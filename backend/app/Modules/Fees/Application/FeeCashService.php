<?php
declare(strict_types=1);

namespace App\Modules\Fees\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Fees\Domain\Money;
use PDO;
use PDOException;

/** Daily UTC cash book: movements and closure are audited inside institute mutex. */
final readonly class FeeCashService
{
    public function __construct(private PDO $db) {}

    private function sum(string $sql, array $params): int
    {
        $q = $this->db->prepare($sql);
        $q->execute($params);
        return Money::cents((string)($q->fetchColumn() ?: '0'));
    }

    public function movement(
        AuthContext $actor, int $institute, string $type, string $amount,
        string $reference, string $retryKey
    ): int {
        $cents = Money::cents($amount, true);
        $decimal = Money::decimal($cents);
        $hash = hash('sha256', json_encode([$type, $decimal, $reference], JSON_THROW_ON_ERROR));
        return (new InstituteTransaction($this->db))->run($actor, $institute, 'fee.record',
            function () use ($actor, $institute, $type, $decimal, $reference, $retryKey, $hash): int {
                $q = $this->db->prepare('SELECT id,request_fingerprint FROM fee_cash_transfers
                    WHERE institute_id=? AND idempotency_key=? FOR UPDATE');
                $q->execute([$institute, $retryKey]);
                $existing = $q->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    if (!hash_equals((string)$existing['request_fingerprint'], $hash)) {
                        throw new HttpException('RETRY_CONFLICT', 'Cash movement retry key conflict.', 409);
                    }
                    return (int)$existing['id'];
                }
                $q = $this->db->prepare('INSERT INTO fee_cash_transfers
                    (institute_id,occurred_at,transfer_type,amount,reference_no,idempotency_key,request_fingerprint,recorded_by)
                    VALUES (?,UTC_TIMESTAMP(),?,?,?,?,?,?)');
                $q->execute([$institute, $type, $decimal, $reference, $retryKey, $hash, $actor->userId]);
                $id = (int)$this->db->lastInsertId();
                (new InstituteTransaction($this->db))->audit(
                    $actor, $institute, 'fee.cash.movement', 'fee_cash_transfers', $id,
                    ['transfer_type' => $type, 'amount' => $decimal, 'reference_no' => $reference]
                );
                return $id;
            }
        );
    }

    public function close(
        AuthContext $actor, int $institute, string $date,
        string $openingAmount, string $actualAmount, string $openingReference
    ): array {
        $openingCents = Money::cents($openingAmount);
        $actualCents = Money::cents($actualAmount);
        return (new InstituteTransaction($this->db))->run($actor, $institute, 'fee.record',
            function () use ($actor, $institute, $date, $openingCents, $actualCents, $openingReference): array {
                $today = (string)$this->db->query('SELECT UTC_DATE()')->fetchColumn();
                if ($date >= $today) {
                    throw new HttpException('CASH_DAY_OPEN', 'Only fully elapsed UTC days can be closed.', 409);
                }
                $q = $this->db->prepare('SELECT id FROM fee_cash_closings WHERE institute_id=? AND closing_date=? FOR UPDATE');
                $q->execute([$institute, $date]);
                if ($q->fetchColumn() !== false) {
                    throw new HttpException('CASH_ALREADY_CLOSED', 'Cash day already closed.', 409);
                }
                $q = $this->db->prepare('SELECT closing_date,actual_amount FROM fee_cash_closings
                    WHERE institute_id=? AND closing_date<? ORDER BY closing_date DESC LIMIT 1 FOR UPDATE');
                $q->execute([$institute, $date]);
                $previous = $q->fetch(PDO::FETCH_ASSOC);
                if ($previous) {
                    $priorDate = (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))
                        ->modify('-1 day')->format('Y-m-d');
                    if ($previous['closing_date'] !== $priorDate) {
                        throw new HttpException('CASH_PREVIOUS_DAY', 'Close each prior calendar day before closing this date.', 409);
                    }
                    if (Money::cents((string)$previous['actual_amount']) !== $openingCents) {
                        throw new HttpException('CASH_OPENING_MISMATCH', 'Opening cash must equal preceding closing cash.', 409);
                    }
                }
                // Reject an attempted out-of-order backfill after a later day is closed.
                $q = $this->db->prepare('SELECT id FROM fee_cash_closings WHERE institute_id=? AND closing_date>? LIMIT 1 FOR UPDATE');
                $q->execute([$institute, $date]);
                if ($q->fetchColumn() !== false) {
                    throw new HttpException('CASH_CHRONOLOGY', 'Cannot insert a closing before an already closed later day.', 409);
                }
                $from = $date . ' 00:00:00';
                $to = (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))
                    ->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
                $collected = $this->sum("SELECT COALESCE(SUM(amount),0) FROM fee_payments
                    WHERE institute_id=? AND payment_mode='cash' AND paid_on>=? AND paid_on<?",
                    [$institute, $from, $to]);
                $cashRefunds = $this->sum("SELECT COALESCE(SUM(amount),0) FROM fee_refunds
                    WHERE institute_id=? AND refund_mode='cash' AND status='paid'
                      AND paid_at>=? AND paid_at<?",
                    [$institute, $from, $to]);
                $reversedCash = $this->sum("SELECT COALESCE(SUM(p.amount),0)
                    FROM fee_payment_reversals r JOIN fee_payments p
                      ON p.id=r.payment_id AND p.institute_id=r.institute_id
                    WHERE r.institute_id=? AND p.payment_mode='cash'
                      AND r.reversed_at>=? AND r.reversed_at<?",
                    [$institute, $from, $to]);
                $sums = [];
                foreach (['counter_addition', 'bank_deposit', 'cash_withdrawal'] as $type) {
                    $sums[$type] = $this->sum('SELECT COALESCE(SUM(amount),0) FROM fee_cash_transfers
                        WHERE institute_id=? AND transfer_type=? AND occurred_at>=? AND occurred_at<?',
                        [$institute, $type, $from, $to]);
                }
                $refunded = $cashRefunds + $reversedCash;
                $expected = $openingCents + $collected - $refunded
                    + $sums['counter_addition'] - $sums['bank_deposit'] - $sums['cash_withdrawal'];
                if ($expected < 0) {
                    throw new HttpException('CASH_NEGATIVE_EXPECTED', 'Source cash book yields a negative expected balance.', 409);
                }
                $values = array_map([Money::class, 'decimal'], [
                    $openingCents, $collected, $refunded,
                    $sums['counter_addition'], $sums['bank_deposit'], $sums['cash_withdrawal'], $actualCents,
                ]);
                $q = $this->db->prepare('INSERT INTO fee_cash_closings
                    (institute_id,closing_date,opening_amount,collected_amount,refunded_amount,
                     added_amount,deposited_amount,withdrawn_amount,actual_amount,closed_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$institute, $date, ...$values, $actor->userId]);
                $id = (int)$this->db->lastInsertId();
                $difference = $actualCents - $expected;
                (new InstituteTransaction($this->db))->audit(
                    $actor, $institute, 'fee.cash.close', 'fee_cash_closings', $id,
                    [
                        'closing_date' => $date,
                        'opening_reference' => $openingReference,
                        'expected_amount' => Money::decimal($expected),
                        'actual_amount' => Money::decimal($actualCents),
                        'difference_cents' => $difference,
                    ]
                );
                return ['closing_id' => $id, 'expected_amount' => Money::decimal($expected),
                    'actual_amount' => Money::decimal($actualCents), 'difference_cents' => $difference];
            }
        );
    }
}
