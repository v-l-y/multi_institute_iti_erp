<?php
declare(strict_types=1);
namespace App\Modules\Fees\Infrastructure;

use App\Core\Transaction;
use App\Http\HttpException;
use PDO;

/** Snapshot reads of the assessment ledger; never combines legacy transactions. */
final readonly class FeeStatementRepository
{
    public function __construct(private PDO $db) {}

    private function query(string $sql, array $args): \PDOStatement
    {
        $q=$this->db->prepare($sql);
        foreach ($args as $i=>$value) $q->bindValue($i+1,$value,PDO::PARAM_INT);
        $q->execute();
        return $q;
    }

    public function statement(int $institute,int $student,int $after,int $size): array
    {
        if ($this->db->inTransaction()) throw new \LogicException('Nested report transactions are not supported.');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return (new Transaction($this->db))->run(function () use ($institute,$student,$after,$size): array {
            $identity=$this->query('SELECT id,local_student_code,full_name FROM students WHERE id=? AND institute_id=?',[$student,$institute])->fetch(PDO::FETCH_ASSOC);
            if (!$identity) throw new HttpException('NOT_FOUND','Student not found.',404);
            $base="SELECT d.id,d.demand_no,d.due_on,d.assessment_id,
                (SELECT COALESCE(SUM(i.amount),0) FROM student_fee_assessment_items i WHERE i.assessment_id=d.assessment_id AND i.institute_id=d.institute_id) AS gross,
                (SELECT COALESCE(SUM(w.amount),0) FROM fee_waivers w WHERE w.assessment_id=d.assessment_id AND w.institute_id=d.institute_id AND w.status='approved') AS waived,
                (SELECT COALESCE(SUM(x.amount),0) FROM fee_payment_allocations x WHERE x.demand_id=d.id AND x.institute_id=d.institute_id AND NOT EXISTS (SELECT 1 FROM fee_payment_reversals r WHERE r.payment_id=x.payment_id AND r.institute_id=x.institute_id)) AS allocated,
                (SELECT COALESCE(SUM(r.amount),0) FROM fee_refunds r WHERE r.demand_id=d.id AND r.institute_id=d.institute_id AND r.status='paid') AS refunded
                FROM fee_demands d JOIN student_fee_assessments a ON a.id=d.assessment_id AND a.institute_id=d.institute_id
                WHERE d.institute_id=? AND d.student_id=? AND d.status='issued' AND a.status='issued'";
            $creditSql='SELECT COALESCE(SUM(p.amount-(SELECT COALESCE(SUM(x.amount),0) FROM fee_payment_allocations x WHERE x.payment_id=p.id AND x.institute_id=p.institute_id)),0)
                FROM fee_payments p WHERE p.institute_id=? AND p.student_id=? AND NOT EXISTS
                (SELECT 1 FROM fee_payment_reversals r WHERE r.payment_id=p.id AND r.institute_id=p.institute_id)';
            $totals=$this->query("SELECT COALESCE(SUM(gross),0) AS gross,COALESCE(SUM(waived),0) AS waived,
                COALESCE(SUM(gross-waived),0) AS net_due,
                COALESCE(SUM(allocated-refunded),0) AS effective_paid,
                COALESCE(SUM(gross-waived-allocated+refunded),0) AS outstanding,
                ($creditSql) AS unallocated_credit
                FROM ($base) AS balances",[$institute,$student,$institute,$student])->fetch(PDO::FETCH_ASSOC);
            $rows=$this->query("SELECT b.*,gross-waived AS net_due,allocated-refunded AS effective_paid,
                gross-waived-allocated+refunded AS outstanding FROM ($base AND d.id>?) AS b ORDER BY id LIMIT ?",
                [$institute,$student,$after,$size])->fetchAll(PDO::FETCH_ASSOC);
            return ['ledger'=>'assessment','student'=>$identity,'totals'=>$totals,
                'demands'=>$rows,'next_after_id'=>count($rows)===$size?(int)$rows[count($rows)-1]['id']:null];
        });
    }

    public function receipt(int $institute,int $payment,?int $student): array
    {
        $sql='SELECT p.id,p.student_id,p.payment_no,p.receipt_no,p.paid_on,p.amount,p.payment_mode,
            p.reference_no,p.receipt_identity_json,r.reversal_no,r.reversed_at
            FROM fee_payments p LEFT JOIN fee_payment_reversals r ON r.payment_id=p.id AND r.institute_id=p.institute_id
            WHERE p.id=? AND p.institute_id=?';
        $args=[$payment,$institute];
        if ($student!==null) { $sql.=' AND p.student_id=?'; $args[]=$student; }
        $row=$this->query($sql,$args)->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new HttpException('NOT_FOUND','Receipt not found.',404);
        $row['identity_snapshot']=$row['receipt_identity_json']===null?null:json_decode($row['receipt_identity_json'],true,32,JSON_THROW_ON_ERROR);
        unset($row['receipt_identity_json']);
        $row['status']=$row['reversal_no']===null?'recorded':'reversed';
        return $row;
    }
}
