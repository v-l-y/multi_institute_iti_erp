<?php
declare(strict_types=1);

namespace App\Modules\Admissions\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\AuthContext;
use PDO;

/**
 * Register a local unverified admission draft only. This neither verifies
 * qualifications nor reserves seats, creates an official allotment, or
 * approves a portal admission. Existing AdmissionService::activate remains
 * the separate source-backed final gate.
 */
final readonly class AdmissionDraftService
{
    public function __construct(private PDO $database) {}

    private function belongs(string $sql, array $parameters, string $reason): array
    {
        $query = $this->database->prepare($sql);
        $query->execute($parameters);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new HttpException('NOT_FOUND', $reason, 404);
        }
        return $row;
    }

    public function create(
        AuthContext $actor,
        int $institute,
        int $studentId,
        int $batchId,
        int $qualificationId,
        int $revisionId,
        string $admissionNumber,
        string $admittedOn,
        string $qualificationRouteCode
    ): int {
        $tx = new InstituteTransaction($this->database);
        return $tx->run($actor, $institute, 'enrollment.manage', function () use (
            $actor, $tx, $institute, $studentId, $batchId, $qualificationId,
            $revisionId, $admissionNumber, $admittedOn, $qualificationRouteCode
        ): int {
            $this->belongs('SELECT id FROM students WHERE id=? AND institute_id=? FOR UPDATE',
                [$studentId, $institute], 'Student is unavailable in this institute.');
            $batch = $this->belongs(
                'SELECT id,intake_id,admission_rule_id,status FROM batches WHERE id=? AND institute_id=? FOR UPDATE',
                [$batchId, $institute], 'Batch is unavailable in this institute.');
            if (!in_array($batch['status'], ['planned','active'], true)) {
                throw new HttpException('BATCH_STATE', 'Draft admissions require a non-closed batch.', 409);
            }
            $this->belongs(
                'SELECT id FROM student_qualifications WHERE id=? AND student_id=? AND institute_id=? FOR UPDATE',
                [$qualificationId, $studentId, $institute],
                'Qualification does not belong to the selected student.');
            $this->belongs(
                'SELECT id FROM admission_intake_revisions WHERE id=? AND intake_id=? AND institute_id=? FOR UPDATE',
                [$revisionId, $batch['intake_id'], $institute],
                'Intake revision does not belong to the selected batch.');

            // Database unique guards are reinforced by explicit, scoped checks.
            // A cancelled admission number cannot be reused; same batch+student
            // cannot be duplicated even after cancellation.
            $duplicate = $this->database->prepare(
                "SELECT id FROM enrollments
                 WHERE institute_id=? AND
                   (admission_number=?
                    OR (student_id=? AND (batch_id=?
                        OR status IN ('pending_verification','active'))))
                 LIMIT 1 FOR UPDATE"
            );
            $duplicate->execute([$institute, $admissionNumber, $studentId, $batchId]);
            if ($duplicate->fetchColumn() !== false) {
                throw new HttpException('ADMISSION_EXISTS', 'An admission number or student enrollment already exists.', 409);
            }

            // The schema requires checked_by/checked_on even for unverified rows.
            // The literal marker and false flag prevent invented verification.
            $insert = $this->database->prepare(
                'INSERT INTO enrollments
                 (institute_id,student_id,batch_id,intake_id,admission_intake_revision_id,
                  admission_number,admitted_on,status,admission_route,admission_rule_id,
                  qualification_id,qualification_route_code,qualification_check_json,
                  qualification_check_passed,checked_by,checked_on)
                 VALUES (?,?,?,?,?, ?,?, ?,?,?,?,?, ?,?,?,UTC_DATE())'
            );
            $insert->execute([
                $institute, $studentId, $batchId, $batch['intake_id'], $revisionId,
                $admissionNumber, $admittedOn, 'pending_verification',
                'unverified', $batch['admission_rule_id'],
                $qualificationId, $qualificationRouteCode,
                '{"reviewed":false}', 0, 'NOT_YET_VERIFIED',
            ]);
            $id = (int)$this->database->lastInsertId();
            $tx->audit($actor, $institute, 'enrollment.draft.create', 'enrollments', $id,
                ['batch_id'=>$batchId,'student_id'=>$studentId,'admission_number'=>$admissionNumber]);
            return $id;
        });
    }
}
