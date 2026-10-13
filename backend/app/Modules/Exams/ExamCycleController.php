<?php
declare(strict_types=1);

namespace App\Modules\Exams;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

/** No local exam rule is invented: every cycle references published authority records. */
final readonly class ExamCycleController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor): int
    {
        $actor->requirePermission('exam.eligibility.manage');
        return $actor->instituteId ?? throw new HttpException('FORBIDDEN', 'Institute scope required.', 403);
    }

    private static function text(Request $request, string $key, int $max): string
    {
        $value = $request->body[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
            throw new HttpException('INVALID_INPUT', 'Invalid ' . $key . '.', 422);
        }
        return trim($value);
    }

    private static function id(Request $request, string $key): int
    {
        $value = $request->body[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new HttpException('INVALID_INPUT', 'Positive integer ' . $key . ' required.', 422);
        }
        return $value;
    }

    private static function date(Request $request, string $key): string
    {
        $value = self::text($request, $key, 10);
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1) {
            throw new HttpException('INVALID_INPUT', 'Real ISO date required for ' . $key . '.', 422);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1
            || !$date || $date->format('Y-m-d') !== $value) {
            throw new HttpException('INVALID_INPUT', 'Real ISO date required for ' . $key . '.', 422);
        }
        return $value;
    }

    /** Review counts only: never equate snapshot count with official eligibility approval. */
    public function status(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor);
        $raw = $request->query['cycle_id'] ?? null;
        $cycleId = is_string($raw) || is_int($raw)
            ? filter_var($raw, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]])
            : false;
        if ($cycleId === false) {
            throw new HttpException('INVALID_INPUT', 'Positive cycle_id query parameter required.', 422);
        }
        $db = $this->database->connection();
        $q = $db->prepare('SELECT id,code,cycle_type,status,rule_id,schedule_source_id,
                          eligibility_cutoff,exam_starts_on
                          FROM exam_cycles WHERE id=? AND institute_id=?');
        $q->execute([$cycleId, $institute]);
        $cycle = $q->fetch(\PDO::FETCH_ASSOC);
        if (!$cycle) {
            throw new HttpException('NOT_FOUND', 'Institute exam cycle not found.', 404);
        }
        $q = $db->prepare("SELECT
            COUNT(*) AS total,
            COALESCE(SUM(eligibility_status='eligible'),0) AS eligible,
            COALESCE(SUM(eligibility_status='ineligible'),0) AS ineligible,
            COALESCE(SUM(eligibility_status='withheld'),0) AS withheld
            FROM exam_eligibility WHERE cycle_id=? AND institute_id=?");
        $q->execute([$cycleId, $institute]);
        $counts = $q->fetch(\PDO::FETCH_ASSOC);
        $q = $db->prepare('SELECT COUNT(*) FROM official_results WHERE cycle_id=? AND institute_id=?');
        $q->execute([$cycleId, $institute]);
        return new Response(['success' => true, 'data' => [
            'cycle' => $cycle,
            'eligibility_snapshots' => [
                'total' => (int)$counts['total'],
                'eligible' => (int)$counts['eligible'],
                'ineligible' => (int)$counts['ineligible'],
                'withheld' => (int)$counts['withheld'],
            ],
            'imported_result_revisions' => (int)$q->fetchColumn(),
            'review_status' => 'manual_completeness_and_official_source_review_required',
        ]]);
    }

    public function draft(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor);
        $code = self::text($request, 'code', 80);
        $type = self::text($request, 'cycle_type', 20);
        if (!in_array($type, ['mains', 'supplementary', 'leftover'], true)) {
            throw new HttpException('INVALID_INPUT', 'Unsupported exam cycle type.', 422);
        }
        $rule = self::id($request, 'rule_id');
        $source = self::id($request, 'schedule_source_id');
        $cutoff = self::date($request, 'eligibility_cutoff');
        $starts = self::date($request, 'exam_starts_on');
        if ($starts < $cutoff) {
            throw new HttpException('INVALID_INPUT', 'Exam cannot start before eligibility cutoff.', 422);
        }
        $windows = $request->body['activity_windows'] ?? null;
        if ($windows instanceof \stdClass) {
            $windows = (array)$windows;
        }
        if (!is_array($windows) || $windows === [] || array_is_list($windows)) {
            throw new HttpException('INVALID_INPUT', 'Nonempty official activity-windows object required.', 422);
        }
        $encoded = json_encode($windows, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 16384) {
            throw new HttpException('INVALID_INPUT', 'Activity windows exceed size limit.', 422);
        }
        $id = (new ExamCycleService($this->database->connection()))->draft(
            $actor, $institute, $code, $type, $rule, $source, $cutoff, $starts, $encoded
        );
        return new Response(['success' => true, 'data' => ['cycle_id' => $id, 'status' => 'draft']], 201);
    }

    public function open(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor);
        if (!in_array('principal', $actor->roles, true)) {
            throw new HttpException('PRINCIPAL_REQUIRED', 'Principal review required.', 403);
        }
        $id = self::id($request, 'cycle_id');
        $review = self::text($request, 'review_reference', 190);
        (new ExamCycleService($this->database->connection()))->open($actor, $institute, $id, $review);
        return new Response(['success' => true, 'data' => ['cycle_id' => $id, 'status' => 'eligibility_open']]);
    }
}
