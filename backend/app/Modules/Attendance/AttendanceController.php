<?php
declare(strict_types=1);

namespace App\Modules\Attendance;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

/**
 * Read-only scoped attendance. Recording and correction require reviewed
 * academic/session rules and are not bypassed by these endpoints.
 */
final readonly class AttendanceController
{
    public function __construct(private Database $database) {}

    private static function positiveQuery(Request $request, string $key, int $default, int $max): int
    {
        if (!array_key_exists($key, $request->query)) {
            return $default;
        }
        $raw = $request->query[$key];
        if (!is_string($raw) && !is_int($raw)) {
            throw new HttpException('INVALID_INPUT', 'Invalid ' . $key . '.', 422);
        }
        $number = filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>$max]]);
        if ($number === false) {
            throw new HttpException('INVALID_INPUT', 'Invalid ' . $key . '.', 422);
        }
        return $number;
    }

    private static function cursor(Request $request): array
    {
        $sessionProvided = array_key_exists('after_session_id', $request->query);
        $enrollmentProvided = array_key_exists('after_enrollment_id', $request->query);
        if ($sessionProvided !== $enrollmentProvided) {
            throw new HttpException('INVALID_CURSOR', 'Both cursor IDs are required.', 422);
        }
        $size = self::positiveQuery($request, 'page_size', 50, 100);
        $session = self::positiveQuery($request, 'after_session_id', 0, PHP_INT_MAX);
        $enrollment = self::positiveQuery($request, 'after_enrollment_id', 0, PHP_INT_MAX);
        return [$size, $session, $enrollment];
    }

    private function records(Request $request, AuthContext $actor, string $scope): Response
    {
        $permission = match ($scope) {
            'institute' => 'attendance.view',
            'assigned' => 'attendance.view.assigned',
            'own' => 'attendance.view.own',
        };
        $actor->requirePermission($permission);
        $institute = $actor->instituteId ?? throw new HttpException('FORBIDDEN', 'Institute access denied.', 403);
        if ($scope === 'assigned' && $actor->staffId === null) {
            throw new HttpException('FORBIDDEN', 'Linked staff identity required.', 403);
        }
        if ($scope === 'own' && $actor->studentId === null) {
            throw new HttpException('FORBIDDEN', 'Linked student identity required.', 403);
        }
        [$size, $afterSession, $afterEnrollment] = self::cursor($request);

        $sql = 'SELECT r.session_id, r.enrollment_id, r.batch_id, r.attended_minutes, r.status,
                       s.batch_year_id, s.session_date, s.component, s.scheduled_minutes,
                       s.locked_on
                FROM attendance_records r
                JOIN attendance_sessions s ON s.id=r.session_id
                    AND s.batch_id=r.batch_id AND s.institute_id=r.institute_id';
        if ($scope === 'own') {
            $sql .= ' JOIN enrollments e ON e.id=r.enrollment_id
                      AND e.batch_id=r.batch_id AND e.institute_id=r.institute_id';
        }
        $sql .= ' WHERE r.institute_id=?
                  AND (r.session_id>? OR (r.session_id=? AND r.enrollment_id>?))';
        if ($scope === 'assigned') {
            $sql .= ' AND s.instructor_id=?';
        } elseif ($scope === 'own') {
            $sql .= ' AND e.student_id=?';
        }
        $sql .= ' ORDER BY r.session_id ASC, r.enrollment_id ASC LIMIT ?';
        $statement = $this->database->connection()->prepare($sql);
        $params = [$institute, $afterSession, $afterSession, $afterEnrollment];
        if ($scope === 'assigned') {
            $params[] = $actor->staffId;
        } elseif ($scope === 'own') {
            $params[] = $actor->studentId;
        }
        $params[] = $size;
        foreach ($params as $i => $value) {
            $statement->bindValue($i + 1, $value, PDO::PARAM_INT);
        }
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $last = count($rows) === $size ? $rows[count($rows)-1] : null;
        return new Response(['success'=>true, 'data'=>[
            'records'=>$rows,
            'next_cursor'=>$last === null ? null : [
                'after_session_id'=>(int)$last['session_id'],
                'after_enrollment_id'=>(int)$last['enrollment_id'],
            ],
        ]]);
    }

    private static function requiredId(Request $request, string $key): int
    {
        $value = $request->body[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new HttpException('INVALID_INPUT', $key . ' must be a positive integer.', 422);
        }
        return $value;
    }

    /**
     * Record one entry, once, on a pre-existing unlocked session assigned to
     * the authenticated instructor. Corrections are a distinct reviewed flow.
     */
    public function markAssigned(Request $request, AuthContext $actor): Response
    {
        $actor->requirePermission('attendance.mark.assigned');
        $institute = $actor->instituteId
            ?? throw new HttpException('FORBIDDEN', 'Institute access denied.', 403);
        if ($actor->staffId === null) {
            throw new HttpException('FORBIDDEN', 'Linked instructor staff identity required.', 403);
        }
        $sessionId = self::requiredId($request, 'session_id');
        $enrollmentId = self::requiredId($request, 'enrollment_id');
        $minutes = $request->body['attended_minutes'] ?? null;
        if (!is_int($minutes) || $minutes < 0 || $minutes > 65535) {
            throw new HttpException('INVALID_INPUT', 'Attended minutes must be a non-negative integer.', 422);
        }
        $status = $request->body['status'] ?? null;
        if (!is_string($status) || !in_array($status,
            ['present','absent','leave','medical_leave','emergency_leave','ojt'], true)) {
            throw new HttpException('INVALID_INPUT', 'Invalid attendance status.', 422);
        }
        if ($status === 'absent' && $minutes !== 0) {
            throw new HttpException('INVALID_INPUT', 'Absent records cannot have attended minutes.', 422);
        }
        $evidence = $request->body['leave_evidence_reference'] ?? null;
        if ($evidence !== null && (!is_string($evidence) || trim($evidence) === ''
            || strlen(trim($evidence)) > 190)) {
            throw new HttpException('INVALID_INPUT', 'Invalid leave evidence reference.', 422);
        }
        $evidence = $evidence === null ? null : trim($evidence);

        $db = $this->database->connection();
        $transaction = new \App\Core\InstituteTransaction($db);
        $transaction->run($actor, $institute, 'attendance.mark.assigned',
            function () use ($db, $transaction, $actor, $institute, $sessionId,
                $enrollmentId, $minutes, $status, $evidence): void {
                $statement = $db->prepare(
                    'SELECT id,batch_id,session_date,scheduled_minutes,instructor_id,locked_on
                     FROM attendance_sessions WHERE id=? AND institute_id=? FOR UPDATE'
                );
                $statement->execute([$sessionId, $institute]);
                $session = $statement->fetch(PDO::FETCH_ASSOC);
                if ($session === false) {
                    throw new HttpException('NOT_FOUND', 'Attendance session not found.', 404);
                }
                if ((int)$session['instructor_id'] !== $actor->staffId) {
                    throw new HttpException('FORBIDDEN', 'Session is not assigned to this instructor.', 403);
                }
                if ($session['locked_on'] !== null) {
                    throw new HttpException('SESSION_LOCKED', 'Locked attendance cannot be changed.', 409);
                }
                if ($minutes > (int)$session['scheduled_minutes']) {
                    throw new HttpException('INVALID_MINUTES', 'Attendance exceeds scheduled minutes.', 422);
                }
                $statement = $db->prepare(
                    "SELECT id FROM enrollments
                     WHERE id=? AND institute_id=? AND batch_id=?
                       AND status='active' AND admitted_on<=? FOR UPDATE"
                );
                $statement->execute([$enrollmentId, $institute, $session['batch_id'], $session['session_date']]);
                if ($statement->fetchColumn() === false) {
                    throw new HttpException('ENROLLMENT_NOT_ACTIVE', 'Active enrolled learner in the session batch required.', 409);
                }
                $statement = $db->prepare(
                    'SELECT enrollment_id FROM attendance_records
                     WHERE session_id=? AND enrollment_id=? AND institute_id=? FOR UPDATE'
                );
                $statement->execute([$sessionId, $enrollmentId, $institute]);
                if ($statement->fetchColumn() !== false) {
                    throw new HttpException('ATTENDANCE_EXISTS', 'Attendance is already recorded; use an audited correction.', 409);
                }
                $statement = $db->prepare(
                    'INSERT INTO attendance_records
                      (institute_id,session_id,batch_id,enrollment_id,attended_minutes,status,leave_evidence_reference,recorded_by)
                     VALUES (?,?,?,?,?,?,?,?)'
                );
                $statement->execute([
                    $institute, $sessionId, $session['batch_id'], $enrollmentId,
                    $minutes, $status, $evidence, $actor->username,
                ]);
                $transaction->audit($actor, $institute, 'attendance.mark', 'attendance_records',
                    $sessionId, ['enrollment_id'=>$enrollmentId,'status'=>$status,'minutes'=>$minutes]);
            }
        );
        return new Response(['success'=>true,'data'=>[
            'session_id'=>$sessionId,'enrollment_id'=>$enrollmentId,'status'=>$status,
        ]], 201);
    }

    public function instituteRecords(Request $request, AuthContext $actor): Response
    {
        return $this->records($request, $actor, 'institute');
    }

    public function assignedRecords(Request $request, AuthContext $actor): Response
    {
        return $this->records($request, $actor, 'assigned');
    }

    public function ownRecords(Request $request, AuthContext $actor): Response
    {
        return $this->records($request, $actor, 'own');
    }
}
