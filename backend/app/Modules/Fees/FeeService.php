<?php
declare(strict_types=1);
namespace App\Modules\Fees;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\AuthContext;
use PDO;

/** Audited financial write service. HTTP callers must use FeeController; no direct SQL writes. */
final readonly class FeeService
{
    public function __construct(private PDO $db) {}

    private function row(string $table, int $id, int $institute): array
    {
        // Table names are internal constants, never request values.
        $q = $this->db->prepare("SELECT * FROM $table WHERE id=? AND institute_id=? FOR UPDATE");
        $q->execute([$id, $institute]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: throw new HttpException('NOT_FOUND', 'Fee record not found.', 404);
    }

    private function total(string $sql, array $parameters): int
    {
        $q = $this->db->prepare($sql);
        $q->execute($parameters);
        return Money::cents((string) ($q->fetchColumn() ?: '0'));
    }

    private function assertPaymentLive(int $payment, int $institute): void
    {
        $q = $this->db->prepare('SELECT id FROM fee_payment_reversals WHERE payment_id=? AND institute_id=?');
        $q->execute([$payment, $institute]);
        if ($q->fetchColumn() !== false) {
            throw new HttpException('PAYMENT_REVERSED', 'Payment has been reversed.', 409);
        }
    }

    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function replay(string $table, int $institute, string $key, string $fingerprint): ?int
    {
        if (!preg_match('/^[\x21-\x7e]{1,128}$/D', $key)) {
            throw new HttpException('INVALID_RETRY_KEY', 'Provide an ASCII retry key of 1–128 characters.', 422);
        }
        $q = $this->db->prepare("SELECT id,request_fingerprint FROM $table WHERE institute_id=? AND idempotency_key=? FOR UPDATE");
        $q->execute([$institute, $key]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if (!hash_equals($row['request_fingerprint'], $fingerprint)) {
            throw new HttpException('RETRY_CONFLICT', 'Retry key already belongs to a different request.', 409);
        }
        return (int) $row['id'];
    }

    public function issueAssessment(AuthContext $actor,int $institute,int $assessment): void
    {
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'fee.record',function () use ($actor,$institute,$assessment,$tx) {
            $a=$this->row('student_fee_assessments',$assessment,$institute);
            if ($a['status']==='issued') return;
            if ($a['status']!=='draft') throw new HttpException('ASSESSMENT_STATE','Only a draft assessment can be issued.',409);
            $s=$this->row('fee_structures',(int)$a['structure_id'],$institute);
            if ($s['status']!=='active' || $s['effective_from']>$a['assessed_on'] || ($s['effective_to']!==null && $s['effective_to']<$a['assessed_on'])) throw new HttpException('FEE_STRUCTURE','Applicable active fee structure required.',409);
            $q=$this->db->prepare('SELECT i.*,h.requires_enrollment FROM student_fee_assessment_items i JOIN fee_heads h ON h.id=i.head_id AND h.institute_id=i.institute_id WHERE i.assessment_id=? AND i.institute_id=? FOR UPDATE');
            $q->execute([$assessment,$institute]);
            $items=$q->fetchAll(PDO::FETCH_ASSOC);
            if (!$items || ($a['enrollment_id']===null && in_array(1,array_map('intval',array_column($items,'requires_enrollment')),true))) throw new HttpException('ASSESSMENT_ITEMS','Items required; pre-enrollment charges must explicitly permit registration collection.',409);
            $q=$this->db->prepare('SELECT i.head_id,i.amount,h.code,h.name,h.is_active FROM fee_structure_items i JOIN fee_heads h ON h.id=i.head_id AND h.institute_id=i.institute_id WHERE i.structure_id=? AND i.institute_id=? FOR UPDATE');
            $q->execute([$s['id'],$institute]);
            $configured=array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'head_id');
            if (count($configured)!==count($items)) throw new HttpException('ASSESSMENT_SNAPSHOT','Assessment must snapshot all configured structure items.',409);
            foreach ($items as $item) {
                $head=$configured[$item['head_id']]??null;
                if (!$head || !(int)$head['is_active'] || Money::cents($head['amount'])!==Money::cents($item['amount']) || $head['code']!==$item['head_code'] || $head['name']!==$item['head_name']) throw new HttpException('ASSESSMENT_SNAPSHOT','Assessment snapshot does not match the configured fee structure.',409);
            }
            if ($a['enrollment_id']!==null) {
                $e=$this->row('enrollments',(int)$a['enrollment_id'],$institute);
                $b=$this->row('batches',(int)$e['batch_id'],$institute);
                if ($b['trade_id']!==$s['trade_id'] || in_array($e['status'],['cancelled','dropout','transferred'],true)) throw new HttpException('ASSESSMENT_ENROLLMENT','Applicable student trade enrollment required.',409);
            }
            $q=$this->db->prepare("UPDATE student_fee_assessments SET status='issued' WHERE id=? AND institute_id=?");
            $q->execute([$assessment,$institute]);
            $tx->audit($actor,$institute,'fee.assessment.issue','student_fee_assessments',$assessment,[]);
        });
    }

    public function approveWaiver(AuthContext $actor,int $institute,int $waiver): void
    {
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'fee.refund',function () use ($actor,$institute,$waiver,$tx) {
            $w=$this->row('fee_waivers',$waiver,$institute);
            $a=$this->row('student_fee_assessments',(int)$w['assessment_id'],$institute);
            if ($w['status']!=='pending' || $a['status']!=='issued' || (int)$w['requested_by']===$actor->userId) throw new HttpException('WAIVER_APPROVAL','Independent approval of a pending waiver on an issued assessment required.',409);
            $gross=$this->total('SELECT COALESCE(SUM(amount),0) FROM student_fee_assessment_items WHERE assessment_id=? AND institute_id=?',[$a['id'],$institute]);
            $approved=$this->total("SELECT COALESCE(SUM(amount),0) FROM fee_waivers WHERE assessment_id=? AND institute_id=? AND status='approved'",[$a['id'],$institute]);
            $paid=$this->total('SELECT COALESCE(SUM(x.amount),0) FROM fee_payment_allocations x JOIN fee_demands d ON d.id=x.demand_id AND d.institute_id=x.institute_id WHERE d.assessment_id=? AND d.institute_id=? AND NOT EXISTS (SELECT 1 FROM fee_payment_reversals r WHERE r.payment_id=x.payment_id AND r.institute_id=x.institute_id)',[$a['id'],$institute]);
            $refunded=$this->total("SELECT COALESCE(SUM(r.amount),0) FROM fee_refunds r JOIN fee_demands d ON d.id=r.demand_id AND d.institute_id=r.institute_id WHERE d.assessment_id=? AND d.institute_id=? AND r.status='paid'",[$a['id'],$institute]);
            if ($approved+Money::cents($w['amount'])>$gross || $gross-$approved-Money::cents($w['amount'])<$paid-$refunded) throw new HttpException('WAIVER_LIMIT','Waiver exceeds gross or would create unreconciled overcollection.',409);
            $q=$this->db->prepare("UPDATE fee_waivers SET status='approved',decided_by=?,decided_at=UTC_TIMESTAMP() WHERE id=? AND institute_id=?");
            $q->execute([$actor->userId,$waiver,$institute]);
            $tx->audit($actor,$institute,'fee.waiver.approve','fee_waivers',$waiver,[]);
        });
    }

    public function recordPayment(AuthContext $actor, int $institute, int $student, string $amount, string $mode, string $number, string $receipt, string $key): int
    {
        $cents = Money::cents($amount, true);
        if (!in_array($mode, ['cash','bank','upi','card','cheque','gateway','other'], true) || trim($number)==='' || trim($receipt)==='' || strlen($number)>100 || strlen($receipt)>100) {
            throw new HttpException('INVALID_PAYMENT', 'Payment mode and receipt identifiers are required.', 422);
        }
        $hash = $this->fingerprint([$student,$cents,$mode,$number,$receipt]);
        $tx = new InstituteTransaction($this->db);
        return $tx->run($actor, $institute, 'fee.record', function () use ($actor,$institute,$student,$cents,$mode,$number,$receipt,$key,$hash,$tx) {
            $existing = $this->replay('fee_payments', $institute, $key, $hash);
            if ($existing !== null) {
                return $existing;
            }
            $studentRow = $this->row('students', $student, $institute);
            $q = $this->db->prepare('SELECT name,mis_code FROM institute WHERE id=?');
            $q->execute([$institute]);
            $identity = ['institute'=>$q->fetch(PDO::FETCH_ASSOC),'student_name'=>$studentRow['full_name'],'local_student_code'=>$studentRow['local_student_code']];
            $q = $this->db->prepare('INSERT INTO fee_payments (institute_id,student_id,payment_no,receipt_no,paid_on,amount,payment_mode,receipt_identity_json,idempotency_key,request_fingerprint,received_by) VALUES (?,?,?,?,UTC_TIMESTAMP(),?,?,?,?,?,?)');
            $q->execute([$institute,$student,$number,$receipt,Money::decimal($cents),$mode,json_encode($identity,JSON_THROW_ON_ERROR),$key,$hash,$actor->userId]);
            $id = (int) $this->db->lastInsertId();
            $tx->audit($actor,$institute,'fee.payment.record','fee_payments',$id,['amount'=>Money::decimal($cents)]);
            return $id;
        });
    }

    public function allocate(AuthContext $actor, int $institute, int $payment, int $demand, string $amount): int
    {
        $cents = Money::cents($amount, true);
        $tx = new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'fee.record',function () use ($actor,$institute,$payment,$demand,$cents,$tx) {
            $p = $this->row('fee_payments',$payment,$institute);
            $d = $this->row('fee_demands',$demand,$institute);
            $a = $this->row('student_fee_assessments',(int)$d['assessment_id'],$institute);
            $this->assertPaymentLive($payment,$institute);
            if ($p['student_id']!==$d['student_id'] || $d['status']!=='issued' || $a['status']!=='issued') {
                throw new HttpException('INVALID_ALLOCATION','Issued assessment/demand for the same student required.',409);
            }
            $q = $this->db->prepare('SELECT id,amount FROM fee_payment_allocations WHERE payment_id=? AND demand_id=? AND institute_id=? FOR UPDATE');
            $q->execute([$payment,$demand,$institute]);
            if ($existing=$q->fetch(PDO::FETCH_ASSOC)) {
                if (Money::cents($existing['amount'])!==$cents) {
                    throw new HttpException('ALLOCATION_EXISTS','Existing allocation is immutable.',409);
                }
                return (int)$existing['id'];
            }
            $allocated=$this->total('SELECT COALESCE(SUM(amount),0) FROM fee_payment_allocations WHERE payment_id=? AND institute_id=?',[$payment,$institute]);
            $gross=$this->total('SELECT COALESCE(SUM(amount),0) FROM student_fee_assessment_items WHERE assessment_id=? AND institute_id=?',[$a['id'],$institute]);
            $waived=$this->total("SELECT COALESCE(SUM(amount),0) FROM fee_waivers WHERE assessment_id=? AND institute_id=? AND status='approved'",[$a['id'],$institute]);
            $paid=$this->total('SELECT COALESCE(SUM(x.amount),0) FROM fee_payment_allocations x WHERE x.demand_id=? AND x.institute_id=? AND NOT EXISTS (SELECT 1 FROM fee_payment_reversals r WHERE r.payment_id=x.payment_id AND r.institute_id=x.institute_id)',[$demand,$institute]);
            $refunded=$this->total("SELECT COALESCE(SUM(amount),0) FROM fee_refunds WHERE demand_id=? AND institute_id=? AND status='paid'",[$demand,$institute]);
            if ($allocated+$cents>Money::cents($p['amount']) || $cents>$gross-$waived-$paid+$refunded) {
                throw new HttpException('ALLOCATION_LIMIT','Allocation exceeds payment credit or demand outstanding.',409);
            }
            $q=$this->db->prepare('INSERT INTO fee_payment_allocations (institute_id,student_id,payment_id,demand_id,amount) VALUES (?,?,?,?,?)');
            $q->execute([$institute,$p['student_id'],$payment,$demand,Money::decimal($cents)]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'fee.allocate','fee_payment_allocations',$id,['payment_id'=>$payment,'demand_id'=>$demand,'amount'=>Money::decimal($cents)]);
            return $id;
        });
    }

    public function requestRefund(AuthContext $actor,int $institute,int $allocation,string $amount,string $reason,string $mode,string $number,string $key): int
    {
        $cents=Money::cents($amount,true);
        if (trim($reason)==='' || strlen($reason)>500 || trim($number)==='' || strlen($number)>100 || !in_array($mode,['cash','bank','upi','gateway','other'],true)) {
            throw new HttpException('INVALID_REFUND','Refund reason, mode and number are required.',422);
        }
        $hash=$this->fingerprint([$allocation,$cents,$reason,$mode,$number]);
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'fee.refund',function () use ($actor,$institute,$allocation,$cents,$reason,$mode,$number,$key,$hash,$tx) {
            if (($id=$this->replay('fee_refunds',$institute,$key,$hash))!==null) {
                return $id;
            }
            $a=$this->row('fee_payment_allocations',$allocation,$institute);
            $this->row('fee_payments',(int)$a['payment_id'],$institute);
            $this->assertPaymentLive((int)$a['payment_id'],$institute);
            $reserved=$this->total("SELECT COALESCE(SUM(amount),0) FROM fee_refunds WHERE allocation_id=? AND institute_id=? AND status IN ('pending','approved','paid')",[$allocation,$institute]);
            if ($reserved+$cents>Money::cents($a['amount'])) {
                throw new HttpException('REFUND_LIMIT','Refund exceeds unreserved original allocation.',409);
            }
            $q=$this->db->prepare('INSERT INTO fee_refunds (institute_id,student_id,demand_id,allocation_id,refund_no,amount,reason,refund_mode,idempotency_key,request_fingerprint,requested_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$institute,$a['student_id'],$a['demand_id'],$allocation,$number,Money::decimal($cents),$reason,$mode,$key,$hash,$actor->userId]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'fee.refund.request','fee_refunds',$id,['amount'=>Money::decimal($cents)]);
            return $id;
        });
    }

    public function decideRefund(AuthContext $actor,int $institute,int $refund,string $action,?string $reference=null): void
    {
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'fee.refund',function () use ($actor,$institute,$refund,$action,$reference,$tx) {
            $r=$this->row('fee_refunds',$refund,$institute);
            $a=$this->row('fee_payment_allocations',(int)$r['allocation_id'],$institute);
            $this->assertPaymentLive((int)$a['payment_id'],$institute);
            if ($action==='paid' && $r['status']==='approved') {
                if ($r['refund_mode']!=='cash' && ($reference===null || trim($reference)==='' || strlen($reference)>190)) {
                    throw new HttpException('REFUND_REFERENCE','Non-cash refund requires payment evidence.',422);
                }
                $q=$this->db->prepare("UPDATE fee_refunds SET status='paid',paid_by=?,paid_at=UTC_TIMESTAMP(),reference_no=? WHERE id=? AND institute_id=?");
                $q->execute([$actor->userId,$reference,$refund,$institute]);
            } elseif (in_array($action,['approved','rejected','cancelled'],true) && ($r['status']==='pending' || ($r['status']==='approved' && $action==='cancelled'))) {
                if ($action==='approved' && (int)$r['requested_by']===$actor->userId) {
                    throw new HttpException('INDEPENDENT_APPROVAL','Requester cannot approve their refund.',403);
                }
                $reserved=$this->total("SELECT COALESCE(SUM(amount),0) FROM fee_refunds WHERE allocation_id=? AND institute_id=? AND status IN ('pending','approved','paid')",[$a['id'],$institute]);
                if ($action==='approved' && $reserved>Money::cents($a['amount'])) {
                    throw new HttpException('REFUND_LIMIT','Original allocation has insufficient refundable amount.',409);
                }
                if ($r['status']==='approved') {
                    $q=$this->db->prepare('UPDATE fee_refunds SET status=? WHERE id=? AND institute_id=?');
                    $q->execute([$action,$refund,$institute]);
                } else {
                    $q=$this->db->prepare('UPDATE fee_refunds SET status=?,decided_by=?,decided_at=UTC_TIMESTAMP() WHERE id=? AND institute_id=?');
                    $q->execute([$action,$actor->userId,$refund,$institute]);
                }
            } else {
                throw new HttpException('REFUND_STATE','Refund transition is not permitted.',409);
            }
            $tx->audit($actor,$institute,'fee.refund.'.$action,'fee_refunds',$refund,[]);
        });
    }

    public function reverse(AuthContext $actor,int $institute,int $payment,int $requestedBy,string $number,string $reason): int
    {
        if ($requestedBy===$actor->userId || trim($number)==='' || strlen($number)>100 || trim($reason)==='' || strlen($reason)>500) {
            throw new HttpException('INVALID_REVERSAL','Independent approver, reversal number and reason required.',422);
        }
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'fee.refund',function () use ($actor,$institute,$payment,$requestedBy,$number,$reason,$tx) {
            $p=$this->row('fee_payments',$payment,$institute);
            if ($requestedBy!==(int)$p['received_by']) {
                throw new HttpException('REVERSAL_REQUESTER','Original payment recorder must be the reversal requester.',403);
            }
            $this->assertPaymentLive($payment,$institute);
            $q=$this->db->prepare("SELECT r.id FROM fee_refunds r JOIN fee_payment_allocations a ON a.id=r.allocation_id AND a.institute_id=r.institute_id WHERE a.payment_id=? AND a.institute_id=? AND r.status IN ('pending','approved','paid') LIMIT 1");
            $q->execute([$payment,$institute]);
            if ($q->fetchColumn()!==false) {
                throw new HttpException('REVERSAL_REFUND_CONFLICT','Payment with reserved or paid refunds cannot be reversed.',409);
            }
            $q=$this->db->prepare('INSERT INTO fee_payment_reversals (institute_id,student_id,payment_id,reversal_no,reason,requested_by,approved_by) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$institute,$p['student_id'],$payment,$number,$reason,$requestedBy,$actor->userId]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'fee.payment.reverse','fee_payment_reversals',$id,[]);
            return $id;
        });
    }
}
