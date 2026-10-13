<?php
declare(strict_types=1);
namespace App\Modules\Assessment;
use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

final readonly class FormativeReadController
{
    public function __construct(private Database $database) {}
    private static function integer(array $q,string $key,int $default=0,int $max=PHP_INT_MAX): int
    {
        if (!array_key_exists($key,$q)) return $default;
        $v=$q[$key];
        if ((!is_string($v) && !is_int($v)) || filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$max]])===false) throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
        return (int)$v;
    }
    private function read(Request $request,AuthContext $actor,string $scope): Response
    {
        $actor->requirePermission(match($scope){'own'=>'formative.view.own','assigned'=>'formative.record.assigned','institute'=>'formative.template.manage'});
        $institute=$actor->instituteId??throw new HttpException('FORBIDDEN','Institute identity required.',403);
        if (($scope==='own' && $actor->studentId===null) || ($scope==='assigned' && $actor->staffId===null)) throw new HttpException('FORBIDDEN','Linked identity required.',403);
        $type=$request->query['type']??'points';
        if (!in_array($type,['points','template'],true)) throw new HttpException('INVALID_INPUT','Invalid score type.',422);
        $size=self::integer($request->query,'page_size',50,100);
        $sql=$type==='points'?
            "SELECT a.id,a.enrollment_id,a.batch_year_id,a.learning_outcome_id,a.max_marks,a.obtained_marks,a.assessed_on,a.locked_on,c.id AS correction_id,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.after_json,'$.obtained_marks')),a.obtained_marks) AS effective_obtained_marks FROM formative_assessments a JOIN enrollments e ON e.id=a.enrollment_id AND e.institute_id=a.institute_id LEFT JOIN formative_corrections c ON c.id=(SELECT MAX(cx.id) FROM formative_corrections cx WHERE cx.assessment_id=a.id AND cx.institute_id=a.institute_id) WHERE a.institute_id=? AND a.id>?":
            "SELECT a.enrollment_id,a.batch_year_id,a.item_code,i.max_marks,a.obtained_marks,a.assessed_on,a.locked_on,c.id AS correction_id,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.after_json,'$.obtained_marks')),a.obtained_marks) AS effective_obtained_marks FROM formative_template_scores a JOIN formative_template_items i ON i.batch_year_id=a.batch_year_id AND i.item_code=a.item_code AND i.institute_id=a.institute_id JOIN enrollments e ON e.id=a.enrollment_id AND e.institute_id=a.institute_id LEFT JOIN formative_corrections c ON c.id=(SELECT MAX(cx.id) FROM formative_corrections cx WHERE cx.enrollment_id=a.enrollment_id AND cx.batch_year_id=a.batch_year_id AND cx.item_code=a.item_code AND cx.institute_id=a.institute_id) WHERE a.institute_id=? AND (a.enrollment_id,a.batch_year_id,a.item_code)>(?,?,?)";
        if ($type==='points') $args=[$institute,self::integer($request->query,'after_id')];
        else {
            $provided=array_intersect(['after_enrollment_id','after_batch_year_id','after_item_code'],array_keys($request->query));
            if ($provided && count($provided)!==3) throw new HttpException('INVALID_CURSOR','All three template cursor fields required.',422);
            $item=$request->query['after_item_code']??'';
            if (!is_string($item) || strlen($item)>80 || ($provided && $item==='')) throw new HttpException('INVALID_CURSOR','Invalid item cursor.',422);
            $args=[$institute,self::integer($request->query,'after_enrollment_id'),self::integer($request->query,'after_batch_year_id'),$item];
        }
        if ($scope==='own') { $sql.=' AND e.student_id=?'; $args[]=$actor->studentId; }
        if ($scope==='assigned') { $sql.=' AND a.assessor_id=?'; $args[]=$actor->staffId; }
        $sql.=$type==='points'?' ORDER BY a.id LIMIT ?':' ORDER BY a.enrollment_id,a.batch_year_id,a.item_code LIMIT ?'; $args[]=$size;
        $q=$this->database->connection()->prepare($sql);
        foreach ($args as $i=>$v) $q->bindValue($i+1,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
        $q->execute(); $rows=$q->fetchAll(PDO::FETCH_ASSOC); $last=count($rows)===$size?$rows[count($rows)-1]:null;
        $cursor=$last===null?null:($type==='points'?['after_id'=>(int)$last['id']]:['after_enrollment_id'=>(int)$last['enrollment_id'],'after_batch_year_id'=>(int)$last['batch_year_id'],'after_item_code'=>$last['item_code']]);
        return new Response(['success'=>true,'data'=>['records'=>$rows,'next_cursor'=>$cursor]]);
    }
    public function own(Request $r,AuthContext $a): Response { return $this->read($r,$a,'own'); }
    public function assigned(Request $r,AuthContext $a): Response { return $this->read($r,$a,'assigned'); }
    public function institute(Request $r,AuthContext $a): Response { return $this->read($r,$a,'institute'); }
}
