<?php
declare(strict_types=1);

namespace App\Modules\Ojt;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

/** Institute ownership and source evidence checked again by OjtService. */
final readonly class OjtController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor,string $permission): int
    {
        $actor->requirePermission($permission);
        return $actor->instituteId
            ?? throw new HttpException('FORBIDDEN','Institute identity required.',403);
    }

    private static function id(Request $r,string $name,int $max=PHP_INT_MAX): int
    {
        $v=$r->body[$name]??null;
        if (!is_int($v)||$v<1||$v>$max) {
            throw new HttpException('INVALID_INPUT','Positive integer '.$name.' required.',422);
        }
        return $v;
    }

    private static function str(Request $r,string $name,int $max,bool $optional=false): ?string
    {
        $v=$r->body[$name]??null;
        if ($v===null && $optional) return null;
        if (!is_string($v)||trim($v)===''||strlen(trim($v))>$max) {
            throw new HttpException('INVALID_INPUT','Valid '.$name.' required.',422);
        }
        return trim($v);
    }

    private static function date(Request $r,string $name): string
    {
        $v=self::str($r,$name,10);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$v)
            || $date===false || $date->format('Y-m-d')!==$v) {
            throw new HttpException('INVALID_INPUT','Real YYYY-MM-DD '.$name.' required.',422);
        }
        return $v;
    }

    private static function marks(Request $r,string $name,bool $positive): string
    {
        $value=self::str($r,$name,12);
        if (!preg_match('/^(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?$/D',$value)
            || ($positive && (float)$value<=0)
            || (float)$value>=10000) {
            throw new HttpException('INVALID_INPUT','Valid decimal '.$name.' required.',422);
        }
        return $value;
    }

    private function service(): OjtService
    {
        return new OjtService($this->database->connection());
    }

    public function plan(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'calendar.manage');
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Principal required for OJT planning.',403);
        }
        $enrollment=self::id($r,'enrollment_id');
        $year=self::id($r,'batch_year_id');
        $mode=self::str($r,'mode',20);
        if (!in_array($mode,['ojt','group_project'],true)) {
            throw new HttpException('INVALID_INPUT','Invalid official OJT/project mode.',422);
        }
        $title=self::str($r,'title',255);
        $industry=self::str($r,'industry_name',255,true);
        $registration=self::str($r,'industry_registration_reference',190,true);
        $address=self::str($r,'workplace_address',16000,true);
        $mentor=self::str($r,'industry_mentor',190,true);
        $coordinator=self::id($r,'coordinator_id');
        $starts=self::date($r,'starts_on');
        $ends=self::date($r,'ends_on');
        if ($ends<$starts) throw new HttpException('INVALID_INPUT','OJT end precedes start.',422);
        $minutes=self::id($r,'required_minutes',4294967295);
        $safety=self::str($r,'safety_reference',190);
        $femaleSafety=self::str($r,'female_safety_reference',190,true);
        $id=$this->service()->plan($actor,$institute,$enrollment,$year,
            $mode,$title,$industry,$registration,$address,$mentor,
            $coordinator,$starts,$ends,$minutes,$safety,$femaleSafety);
        return new Response(['success'=>true,'data'=>['ojt_project_id'=>$id,'status'=>'planned']],201);
    }

    public function start(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'calendar.manage');
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Principal required.',403);
        }
        $id=self::id($r,'project_id');
        $review=self::str($r,'review_reference',500);
        $this->service()->start($actor,$institute,$id,$review);
        return new Response(['success'=>true,'data'=>['ojt_project_id'=>$id,'status'=>'ongoing']]);
    }

    public function ownLog(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'ojt.log.own');
        if ($actor->studentId===null || !in_array('student',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Linked learner required.',403);
        }
        $id=self::id($r,'project_id');
        $date=self::date($r,'entry_date');
        $minutes=self::id($r,'attended_minutes',1440);
        $activity=self::str($r,'activity',16000);
        $confirmation=self::str($r,'mentor_confirmation',190);
        $safety=self::str($r,'monitoring_safety_reference',190,true);
        $created=$this->service()->log($actor,$institute,$id,$date,
            $minutes,$activity,$confirmation,$safety);
        return new Response(['success'=>true,'data'=>['logbook_entry_id'=>$created]],201);
    }

    public function evaluateAssigned(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'ojt.evaluate.assigned');
        if ($actor->staffId===null || !in_array('instructor',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Assigned instructor required.',403);
        }
        $id=self::id($r,'project_id');
        $role=self::str($r,'primary_evaluator_role',40);
        if (!in_array($role,['industry_mentor','trade_instructor'],true)) {
            throw new HttpException('INVALID_INPUT','Invalid source-declared primary evaluator role.',422);
        }
        $primaryName=self::str($r,'primary_evaluator_name',190);
        $primary=self::marks($r,'primary_marks',false);
        $primaryMax=self::marks($r,'primary_max_marks',true);
        $examiner=self::str($r,'external_examiner_name',190);
        $external=self::marks($r,'external_marks',false);
        $externalMax=self::marks($r,'external_max_marks',true);
        if ((float)$primary>(float)$primaryMax || (float)$external>(float)$externalMax) {
            throw new HttpException('INVALID_INPUT','Marks cannot exceed reviewed component maxima.',422);
        }
        $grade=self::str($r,'final_grade',4);
        if (!in_array($grade,['A1','A','B','C'],true)) {
            throw new HttpException('INVALID_INPUT','Unsupported source-declared grade.',422);
        }
        $date=self::date($r,'evaluated_on');
        $source=self::id($r,'evaluation_source_id');
        $signed=self::str($r,'signed_evidence_reference',190);
        $sidh=self::str($r,'sidh_submission_reference',190,true);
        $completion=self::str($r,'completion_certificate_reference',190,true);
        $created=$this->service()->evaluate($actor,$institute,$id,$role,
            $primaryName,$primary,$primaryMax,$examiner,$external,
            $externalMax,$grade,$date,$source,$signed,$sidh,$completion);
        return new Response(['success'=>true,'data'=>[
            'ojt_evaluation_id'=>$created,'project_status'=>'completed',
            'government_submission_verified'=>false
        ]],201);
    }
}
