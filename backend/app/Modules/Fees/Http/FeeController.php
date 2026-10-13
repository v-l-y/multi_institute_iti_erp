<?php
declare(strict_types=1);

namespace App\Modules\Fees\Http;

use App\Core\Database;
use App\Modules\Fees\Application\FeeService;
use App\Modules\Fees\Domain\Money;
use App\Modules\Fees\Infrastructure\FeePaymentRepository;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

/**
 * Strict HTTP boundary for the audited FeeService. No regulator-defined
 * fee schedules or amounts are fabricated by this controller.
 */
final readonly class FeeController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor, string $permission): int
    {
        $actor->requirePermission($permission);
        return $actor->instituteId ?? throw new HttpException('FORBIDDEN', 'Institute access denied.', 403);
    }

    private static function id(Request $request, string $key): int
    {
        $value = $request->body[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new HttpException('INVALID_INPUT', $key . ' must be a positive integer.', 422);
        }
        return $value;
    }

    private static function required(Request $request, string $key, int $max): string
    {
        $value = $request->body[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
            throw new HttpException('INVALID_INPUT', $key . ' must be non-empty text of at most ' . $max . ' bytes.', 422);
        }
        return trim($value);
    }

    private static function amount(Request $request): string
    {
        $raw = $request->body['amount'] ?? null;
        if (!is_string($raw)) {
            throw new HttpException('INVALID_AMOUNT', 'Amount must be a decimal string.', 422);
        }
        Money::cents($raw, true);
        return $raw;
    }

    private static function queryInt(Request $request, string $key, int $default, int $max): int
    {
        if (!array_key_exists($key, $request->query)) {
            return $default;
        }
        $raw = $request->query[$key];
        $value = (is_string($raw) || is_int($raw))
            ? filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>$max]])
            : false;
        if ($value === false) {
            throw new HttpException('INVALID_INPUT', 'Invalid ' . $key . '.', 422);
        }
        return $value;
    }

    private function service(): FeeService
    {
        return new FeeService($this->database->connection());
    }

    public function issueAssessment(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'fee.record');
        $id = self::id($request, 'assessment_id');
        $this->service()->issueAssessment($actor, $institute, $id);
        return new Response(['success'=>true,'data'=>['assessment_id'=>$id,'status'=>'issued']]);
    }

    public function issueDemand(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'fee.record');
        $assessment = self::id($request, 'assessment_id');
        $number = self::required($request, 'demand_no', 100);
        $dueOn = self::required($request, 'due_on', 10);
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $dueOn) !== 1) {
            throw new HttpException('INVALID_DUE_DATE', 'A real ISO demand due date is required.', 422);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $dueOn)
            || !$date || $date->format('Y-m-d') !== $dueOn) {
            throw new HttpException('INVALID_DUE_DATE', 'A real ISO demand due date is required.', 422);
        }
        $id = $this->service()->createDemand($actor, $institute, $assessment, $number, $dueOn);
        return new Response(['success' => true, 'data' => ['demand_id' => $id, 'status' => 'issued']], 201);
    }

    public function requestWaiver(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'fee.record');
        $assessment = self::id($request, 'assessment_id');
        $number = self::required($request, 'waiver_no', 100);
        $amount = self::amount($request);
        $reason = self::required($request, 'reason', 500);
        $id = $this->service()->requestWaiver($actor, $institute, $assessment, $number, $amount, $reason);
        return new Response(['success' => true, 'data' => [
            'waiver_id' => $id, 'status' => 'pending',
        ]], 201);
    }

    public function approveWaiver(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'fee.refund');
        $id = self::id($request, 'waiver_id');
        $this->service()->approveWaiver($actor, $institute, $id);
        return new Response(['success'=>true,'data'=>['waiver_id'=>$id,'status'=>'approved']]);
    }

    public function recordPayment(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'fee.record');
        $student = self::id($request, 'student_id');
        $amount = self::amount($request);
        $mode = self::required($request, 'payment_mode', 20);
        $number = self::required($request, 'payment_no', 100);
        $receipt = self::required($request, 'receipt_no', 100);
        $key = self::required($request, 'idempotency_key', 128);
        $id = $this->service()->recordPayment($actor, $institute, $student, $amount, $mode, $number, $receipt, $key);
        return new Response(['success'=>true,'data'=>['payment_id'=>$id]], 201);
    }

    public function allocate(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'fee.record');
        $payment = self::id($request, 'payment_id');
        $demand = self::id($request, 'demand_id');
        $amount = self::amount($request);
        $id = $this->service()->allocate($actor, $institute, $payment, $demand, $amount);
        return new Response(['success'=>true,'data'=>['allocation_id'=>$id]], 201);
    }

    public function requestRefund(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'fee.refund');
        $allocation = self::id($request, 'allocation_id');
        $amount = self::amount($request);
        $reason = self::required($request, 'reason', 500);
        $mode = self::required($request, 'refund_mode', 20);
        $number = self::required($request, 'refund_no', 100);
        $key = self::required($request, 'idempotency_key', 128);
        $id = $this->service()->requestRefund($actor, $institute, $allocation, $amount, $reason, $mode, $number, $key);
        return new Response(['success'=>true,'data'=>['refund_id'=>$id,'status'=>'pending']], 201);
    }

    public function decideRefund(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'fee.refund');
        $id = self::id($request, 'refund_id');
        $action = self::required($request, 'action', 20);
        if (!in_array($action, ['approved','rejected','cancelled','paid'], true)) {
            throw new HttpException('INVALID_INPUT', 'Unknown refund transition.', 422);
        }
        $reference = $request->body['reference_no'] ?? null;
        if ($reference !== null && (!is_string($reference) || strlen(trim($reference)) > 190)) {
            throw new HttpException('INVALID_INPUT', 'Invalid refund reference.', 422);
        }
        $this->service()->decideRefund($actor, $institute, $id, $action, $reference === null ? null : trim($reference));
        return new Response(['success'=>true,'data'=>['refund_id'=>$id,'status'=>$action]]);
    }

    public function requestReversal(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'fee.refund');
        $id = self::id($request, 'payment_id');
        $number = self::required($request, 'reversal_no', 100);
        $reason = self::required($request, 'reason', 500);
        $this->service()->requestReversal($actor, $institute, $id, $number, $reason);
        return new Response(['success'=>true,'data'=>['payment_id'=>$id,'status'=>'pending']], 201);
    }

    public function reversePayment(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'fee.refund');
        $id = self::id($request, 'payment_id');
        $requester = self::id($request, 'requested_by');
        $number = self::required($request, 'reversal_no', 100);
        $reason = self::required($request, 'reason', 500);
        $reversal = $this->service()->reverse($actor, $institute, $id, $requester, $number, $reason);
        return new Response(['success'=>true,'data'=>['reversal_id'=>$reversal]], 201);
    }

    private function listPayments(Request $request, AuthContext $actor, bool $own): Response
    {
        $institute = self::institute($actor, $own ? 'fee.view.own' : 'fee.view');
        if ($own && $actor->studentId === null) {
            throw new HttpException('FORBIDDEN', 'Linked student identity required.', 403);
        }
        $limit = self::queryInt($request, 'page_size', 50, 100);
        $cursor = self::queryInt($request, 'after_id', 0, PHP_INT_MAX);
        $page = (new FeePaymentRepository($this->database->connection()))->page($institute, $cursor, $limit, $own ? $actor->studentId : null);
        return new Response(['success'=>true,'data'=>$page]);
    }

    public function payments(Request $request, AuthContext $actor): Response
    {
        return $this->listPayments($request, $actor, false);
    }

    public function ownPayments(Request $request, AuthContext $actor): Response
    {
        return $this->listPayments($request, $actor, true);
    }
}
