<?php
declare(strict_types=1);
namespace App\Modules\Training;
use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

final readonly class TrainingController
{
    public function __construct(private Database $database) {}
    private static function number(array $data,string $key,int $default=0,int $max=PHP_INT_MAX): int
    {
        $value=$data[$key]??$default;
        if ((!is_int($value) && !is_string($value)) || filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>$max]])===false) throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
        return (int)$value;
    }
    public function draft(Request $r,AuthContext $actor): Response
    {
        $id=self::number($r->body,'plan_id');
        $id=(new TrainingService($this->database->connection()))->plan($actor,$r->body,$id?:null);
        return new Response(['success'=>true,'data'=>['plan_id'=>$id,'status'=>'draft']],201);
    }
    public function delete(Request $r,AuthContext $actor): Response
    {
        $id=self::number($r->body,'plan_id'); $reason=$r->body['reason']??null;
        if ($id<1 || !is_string($reason)) throw new HttpException('INVALID_INPUT','Plan ID and reason required.',422);
        (new TrainingService($this->database->connection()))->deleteDraft($actor,$id,$reason);
        return new Response(['success'=>true,'data'=>['plan_id'=>$id,'status'=>'draft_deleted']]);
    }
    public function approve(Request $r,AuthContext $actor): Response
    {
        $id=self::number($r->body,'plan_id'); $reference=$r->body['review_reference']??null;
        if ($id<1 || !is_string($reference)) throw new HttpException('INVALID_INPUT','Plan ID and review reference required.',422);
        (new TrainingService($this->database->connection()))->approve($actor,$id,$reference);
        return new Response(['success'=>true,'data'=>['plan_id'=>$id,'status'=>'approved']]);
    }
    public function record(Request $r,AuthContext $actor): Response
    {
        $id=(new TrainingService($this->database->connection()))->progress($actor,$r->body);
        return new Response(['success'=>true,'data'=>['progress_id'=>$id]],201);
    }
    private function read(Request $r,AuthContext $actor,bool $progress): Response
    {
        $institute=$actor->instituteId??throw new HttpException('FORBIDDEN','Institute identity required.',403);
        $scope=in_array('training.view',$actor->permissions,true)?'institute':(in_array('training.record.assigned',$actor->permissions,true)?'assigned':'own');
        $actor->requirePermission(match($scope){'institute'=>'training.view','assigned'=>'training.record.assigned','own'=>'training.view.own'});
        $after=self::number($r->query,'after_id'); $size=self::number($r->query,'page_size',50,100);
        if ($size<1) throw new HttpException('INVALID_INPUT','Positive page size required.',422);
        $db=$this->database->connection(); $args=[$institute,$after];
        $sql=$progress?'SELECT p.*,l.title,l.activity_type,l.learning_outcome_id FROM training_progress p JOIN lesson_plans l ON l.id=p.plan_id AND l.institute_id=p.institute_id WHERE p.institute_id=? AND p.id>?':'SELECT p.* FROM lesson_plans p WHERE p.institute_id=? AND p.id>?';
        $year=self::number($r->query,'batch_year_id');
        if ($year>0) { $sql.=' AND p.batch_year_id=?'; $args[]=$year; }
        if ($scope==='assigned') {
            if ($actor->staffId===null) throw new HttpException('FORBIDDEN','Staff identity required.',403);
            $sql.=$progress?' AND l.instructor_id=?':' AND p.instructor_id=?'; $args[]=$actor->staffId;
        } elseif ($scope==='own') {
            if ($actor->studentId===null) throw new HttpException('FORBIDDEN','Student identity required.',403);
            $sql.=$progress?' AND EXISTS (SELECT 1 FROM enrollments e WHERE e.id=p.enrollment_id AND e.student_id=? AND e.institute_id=p.institute_id)':' AND p.status=\'approved\' AND EXISTS (SELECT 1 FROM enrollments e WHERE e.batch_id=p.batch_id AND e.student_id=? AND e.institute_id=p.institute_id)';
            $args[]=$actor->studentId;
        }
        if ($progress && self::number($r->query,'include_history',0,1)===0) $sql.=' AND NOT EXISTS (SELECT 1 FROM training_progress c WHERE c.supersedes_id=p.id)';
        $sql.=' ORDER BY p.id LIMIT ?'; $args[]=$size;
        $q=$db->prepare($sql); foreach($args as $i=>$v) $q->bindValue($i+1,$v,PDO::PARAM_INT);
        $q->execute(); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        return new Response(['success'=>true,'data'=>[$progress?'progress':'plans'=>$rows,'next_after_id'=>count($rows)===$size?(int)$rows[count($rows)-1]['id']:null]]);
    }
    public function plans(Request $r,AuthContext $a): Response { return $this->read($r,$a,false); }
    public function progress(Request $r,AuthContext $a): Response { return $this->read($r,$a,true); }
}
