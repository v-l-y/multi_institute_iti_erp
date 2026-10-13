<?php
declare(strict_types=1);

namespace App\Modules\Operations;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Admissions\AdmissionService;
use App\Modules\Admissions\AdmissionDraftService;
use App\Modules\Admissions\TraineeUploadService;
use App\Modules\Auth\AuthContext;
use App\Modules\Exams\EligibilityService;

/**
 * Narrow HTTP adapters for existing audited, institute-scoped services.
 * Does not create official allotments, submit portal data, or issue certificates.
 */
final readonly class OperationsController
{
    public function __construct(private Database $database) {}

    private function institute(AuthContext $actor, string $permission): int
    {
        $actor->requirePermission($permission);
        return $actor->instituteId
            ?? throw new HttpException('FORBIDDEN', 'Institute access denied.', 403);
    }

    private static function id(Request $request, string $key): int
    {
        $id = $request->body[$key] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new HttpException('INVALID_INPUT', $key . ' must be a positive integer.', 422);
        }
        return $id;
    }

    private static function text(Request $request, string $key, int $maximum): string
    {
        $value = $request->body[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > $maximum) {
            throw new HttpException('INVALID_INPUT', $key . ' must be non-empty text of at most ' . $maximum . ' bytes.', 422);
        }
        return trim($value);
    }

    public function createDraftEnrollment(Request $request, AuthContext $actor): Response
    {
        $institute = $this->institute($actor, 'enrollment.manage');
        $student = self::id($request, 'student_id');
        $batch = self::id($request, 'batch_id');
        $qualification = self::id($request, 'qualification_id');
        $revision = self::id($request, 'admission_intake_revision_id');
        $number = self::text($request, 'admission_number', 80);
        $date = self::text($request, 'admitted_on', 10);
        $route = self::text($request, 'qualification_route_code', 80);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $date)
            || $parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new HttpException('INVALID_INPUT', 'admitted_on must be a real YYYY-MM-DD date.', 422);
        }
        $id = (new AdmissionDraftService($this->database->connection()))->create(
            $actor, $institute, $student, $batch, $qualification, $revision, $number, $date, $route
        );
        return new Response(['success'=>true,'data'=>[
            'enrollment_id'=>$id,'status'=>'pending_verification','qualification_check_passed'=>false,
        ]], 201);
    }

    public function activateEnrollment(Request $request, AuthContext $actor): Response
    {
        $institute = $this->institute($actor, 'enrollment.manage');
        $enrollment = self::id($request, 'enrollment_id');
        (new AdmissionService($this->database->connection()))->activate($actor, $institute, $enrollment);
        return new Response(['success' => true, 'data' => ['enrollment_id' => $enrollment, 'status' => 'active']]);
    }

    public function cancelEnrollment(Request $request, AuthContext $actor): Response
    {
        $institute = $this->institute($actor, 'enrollment.manage');
        $enrollment = self::id($request, 'enrollment_id');
        $reason = self::text($request, 'reason', 1000);
        (new AdmissionService($this->database->connection()))->cancel($actor, $institute, $enrollment, $reason);
        return new Response(['success' => true, 'data' => ['enrollment_id' => $enrollment, 'status' => 'cancelled']]);
    }

    public function prepareTraineeUpload(Request $request, AuthContext $actor): Response
    {
        $institute = $this->institute($actor, 'enrollment.manage');
        $enrollment = self::id($request, 'enrollment_id');
        $payload = (new TraineeUploadService($this->database->connection()))->prepare($actor, $institute, $enrollment);
        return new Response(['success' => true, 'data' => $payload]);
    }

    public function freezeEligibility(Request $request, AuthContext $actor): Response
    {
        $institute = $this->institute($actor, 'exam.eligibility.manage');
        $enrollment = self::id($request, 'enrollment_id');
        $year = self::id($request, 'batch_year_id');
        $cycle = self::id($request, 'cycle_id');
        $evidence = self::text($request, 'evidence_reference', 190);
        $id = (new EligibilityService($this->database->connection()))->freeze($actor, $institute, $enrollment, $year, $cycle, $evidence);
        return new Response(['success' => true, 'data' => ['eligibility_id' => $id]], 201);
    }
}
