<?php
declare(strict_types=1);

namespace App\Modules\Fees\Http;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Fees\Application\FeeCashService;
use App\Modules\Fees\Domain\Money;

/** Recorded cash transactions and end-of-day source reconciliation. */
final readonly class FeeCashController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor): int
    {
        $actor->requirePermission('fee.record');
        return $actor->instituteId ?? throw new HttpException('FORBIDDEN', 'Institute scope required.', 403);
    }

    private static function text(Request $r, string $key, int $max): string
    {
        $value = $r->body[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
            throw new HttpException('INVALID_INPUT', 'Invalid ' . $key . '.', 422);
        }
        return trim($value);
    }

    private static function amount(Request $r, string $key, bool $positive): string
    {
        $amount = self::text($r, $key, 18);
        Money::cents($amount, $positive);
        return $amount;
    }

    public function movement(Request $r, AuthContext $actor): Response
    {
        $institute = self::institute($actor);
        $type = self::text($r, 'transfer_type', 32);
        if (!in_array($type, ['bank_deposit', 'cash_withdrawal', 'counter_addition'], true)) {
            throw new HttpException('INVALID_INPUT', 'Unsupported cash transfer type.', 422);
        }
        $amount = self::amount($r, 'amount', true);
        $reference = self::text($r, 'reference_no', 190);
        $key = self::text($r, 'idempotency_key', 128);
        if (preg_match('/^[\x21-\x7e]{1,128}$/D', $key) !== 1) {
            throw new HttpException('INVALID_INPUT', 'ASCII retry key required.', 422);
        }
        $id = (new FeeCashService($this->database->connection()))->movement(
            $actor, $institute, $type, $amount, $reference, $key
        );
        return new Response(['success' => true, 'data' => ['cash_transfer_id' => $id]], 201);
    }

    public function reviewClosing(Request $r, AuthContext $actor): Response
    {
        $actor->requirePermission('fee.refund');
        $institute = $actor->instituteId
            ?? throw new HttpException('FORBIDDEN', 'Institute scope required.', 403);
        if (!in_array('principal', $actor->roles, true)) {
            throw new HttpException('PRINCIPAL_REQUIRED', 'Principal review required.', 403);
        }
        $id = $r->body['closing_id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new HttpException('INVALID_INPUT', 'Positive closing_id required.', 422);
        }
        $decision = self::text($r, 'decision', 40);
        if (!in_array($decision, ['reviewed', 'requires_correction'], true)) {
            throw new HttpException('INVALID_INPUT', 'Unknown closing review decision.', 422);
        }
        $reference = self::text($r, 'evidence_reference', 190);
        (new FeeCashService($this->database->connection()))->reviewClosing(
            $actor, $institute, $id, $decision, $reference
        );
        return new Response(['success' => true, 'data' => [
            'closing_id' => $id, 'review_decision' => $decision,
            'closing_records_modified' => false,
        ]]);
    }

    public function close(Request $r, AuthContext $actor): Response
    {
        $institute = self::institute($actor);
        $date = self::text($r, 'closing_date', 10);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1
            || !$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new HttpException('INVALID_INPUT', 'Real closing date required.', 422);
        }
        $opening = self::amount($r, 'opening_amount', false);
        $actual = self::amount($r, 'actual_amount', false);
        $reference = self::text($r, 'opening_reference', 190);
        $summary = (new FeeCashService($this->database->connection()))->close(
            $actor, $institute, $date, $opening, $actual, $reference
        );
        return new Response(['success' => true, 'data' => $summary], 201);
    }
}
