<?php
declare(strict_types=1);
namespace App\Modules\Fees\Http;
use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

/** Bounded read registers for existing fee workflows, without storage internals. */
final readonly class FeeRegisterController
{
    public function __construct(private Database $database) {}
    public function read(Request $r,AuthContext $actor,string $kind): Response
    {
        $actor->requirePermission('fee.view');
        $institute=$actor->instituteId??throw new HttpException('FORBIDDEN','Institute identity required.',403);
        [$table,$fields]=match($kind){
            'heads'=>['fee_heads','id,code,name,requires_enrollment,is_active'],
            'structures'=>['fee_structures','id,trade_id,version_no,effective_from,effective_to,status'],
            'structure-items'=>['fee_structure_items','id,structure_id,head_id,amount'],
            'assessments'=>['student_fee_assessments','id,student_id,enrollment_id,structure_id,assessment_no,assessed_on,status'],
            'demands'=>['fee_demands','id,student_id,assessment_id,demand_no,issued_on,due_on,status'],
            'refunds'=>['fee_refunds','id,student_id,allocation_id,demand_id,amount,status,refund_no'],
            'waivers'=>['fee_waivers','id,student_id,assessment_id,waiver_no,amount,status,reason'],
            'reversals'=>['fee_payment_reversals','id,payment_id,reversal_no,reason,requested_by,approved_by'],
        };
        $values=[];
        foreach(['after_id'=>[0,PHP_INT_MAX],'page_size'=>[50,100],'student_id'=>[0,PHP_INT_MAX],'structure_id'=>[0,PHP_INT_MAX]] as $key=>[$default,$max]) {
            $value=$r->query[$key]??$default;
            if ((!is_int($value)&&!is_string($value)) || filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>$max]])===false) throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
            $values[$key]=(int)$value;
        }
        if ($values['page_size']<1) throw new HttpException('INVALID_INPUT','Positive page size required.',422);
        $sql="SELECT $fields FROM $table WHERE institute_id=? AND id>?"; $args=[$institute,$values['after_id']];
        if ($values['student_id']>0) {
            if (!in_array($kind,['assessments','demands','refunds','waivers'],true)) throw new HttpException('INVALID_INPUT','Student filter unsupported for this register.',422);
            $q=$this->database->connection()->prepare('SELECT id FROM students WHERE id=? AND institute_id=?'); $q->execute([$values['student_id'],$institute]);
            if (!$q->fetchColumn()) throw new HttpException('NOT_FOUND','Student not found.',404);
            $sql.=' AND student_id=?'; $args[]=$values['student_id'];
        }
        if ($values['structure_id']>0) {
            if ($kind!=='structure-items') throw new HttpException('INVALID_INPUT','Structure filter unsupported for this register.',422);
            $sql.=' AND structure_id=?'; $args[]=$values['structure_id'];
        }
        $sql.=' ORDER BY id LIMIT ?'; $args[]=$values['page_size'];
        $q=$this->database->connection()->prepare($sql); foreach($args as $i=>$v) $q->bindValue($i+1,$v,PDO::PARAM_INT);
        $q->execute(); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        return new Response(['success'=>true,'data'=>['records'=>$rows,'next_after_id'=>count($rows)===$values['page_size']?(int)$rows[count($rows)-1]['id']:null]]);
    }
}
