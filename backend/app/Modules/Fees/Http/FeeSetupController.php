<?php
declare(strict_types=1);

namespace App\Modules\Fees\Http;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Fees\Application\FeeSetupService;
use App\Modules\Fees\Domain\Money;

/** Validated institute fee setup with immutable issued snapshots. */
final readonly class FeeSetupController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor): int
    {
        $actor->requirePermission('fee.record');
        return $actor->instituteId ?? throw new HttpException('FORBIDDEN', 'Institute scope required.', 403);
    }

    private static function id(Request $r, string $key): int
    {
        $value = $r->body[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new HttpException('INVALID_INPUT', 'Positive integer ' . $key . ' required.', 422);
        }
        return $value;
    }

    private static function text(Request $r, string $key, int $max): string
    {
        $value = $r->body[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
            throw new HttpException('INVALID_INPUT', 'Invalid ' . $key . '.', 422);
        }
        return trim($value);
    }

    private static function date(Request $r, string $key): string
    {
        $value = self::text($r, $key, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1
            || !$date || $date->format('Y-m-d') !== $value) {
            throw new HttpException('INVALID_INPUT', 'Real ISO date required for ' . $key . '.', 422);
        }
        return $value;
    }

    private function service(): FeeSetupService
    {
        return new FeeSetupService($this->database->connection());
    }

    public function head(Request $r, AuthContext $actor): Response
    {
        $institute = self::institute($actor);
        $code = self::text($r, 'code', 50);
        $name = self::text($r, 'name', 120);
        $flag = $r->body['requires_enrollment'] ?? null;
        if (!is_bool($flag)) {
            throw new HttpException('INVALID_INPUT', 'Explicit requires_enrollment boolean required.', 422);
        }
        $id = $this->service()->createHead($actor, $institute, $code, $name, $flag);
        return new Response(['success' => true, 'data' => ['fee_head_id' => $id]], 201);
    }

    public function structure(Request $r, AuthContext $actor): Response
    {
        $institute = self::institute($actor);
        $trade = self::id($r, 'trade_id');
        $version = self::id($r, 'version_no');
        $from = self::date($r, 'effective_from');
        $to = array_key_exists('effective_to', $r->body) && $r->body['effective_to'] !== null
            ? self::date($r, 'effective_to') : null;
        if ($to !== null && $to < $from) {
            throw new HttpException('INVALID_INPUT', 'Fee end date precedes start date.', 422);
        }
        $id = $this->service()->draftStructure($actor, $institute, $trade, $version, $from, $to);
        return new Response(['success' => true, 'data' => ['fee_structure_id' => $id, 'status' => 'draft']], 201);
    }

    public function structureItem(Request $r, AuthContext $actor): Response
    {
        $institute = self::institute($actor);
        $structure = self::id($r, 'structure_id');
        $head = self::id($r, 'head_id');
        $amount = self::text($r, 'amount', 18);
        Money::cents($amount);
        $id = $this->service()->setItem($actor, $institute, $structure, $head, $amount);
        return new Response(['success' => true, 'data' => ['fee_structure_item_id' => $id]], 201);
    }

    public function activateStructure(Request $r, AuthContext $actor): Response
    {
        $institute = self::institute($actor);
        $structure = self::id($r, 'structure_id');
        $this->service()->activateStructure($actor, $institute, $structure);
        return new Response(['success' => true, 'data' => ['fee_structure_id' => $structure, 'status' => 'active']]);
    }

    public function assessment(Request $r, AuthContext $actor): Response
    {
        $institute = self::institute($actor);
        $student = self::id($r, 'student_id');
        $enrollment = $r->body['enrollment_id'] ?? null;
        if ($enrollment !== null && (!is_int($enrollment) || $enrollment < 1)) {
            throw new HttpException('INVALID_INPUT', 'Invalid enrollment_id.', 422);
        }
        $structure = self::id($r, 'structure_id');
        $number = self::text($r, 'assessment_no', 100);
        $assessedOn = self::date($r, 'assessed_on');
        $id = $this->service()->draftAssessment(
            $actor, $institute, $student, $enrollment, $structure, $number, $assessedOn
        );
        return new Response(['success' => true, 'data' => ['assessment_id' => $id, 'status' => 'draft']], 201);
    }
}
