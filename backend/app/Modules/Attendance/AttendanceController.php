<?php
declare(strict_types=1);

namespace App\Modules\Attendance;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\AuthContext;
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
