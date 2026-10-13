<?php
declare(strict_types=1);

namespace App\Modules\Exams;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

/** Locally records externally sourced practical exam evidence; no portal send. */
final readonly class PracticalExamController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor,string $permission): int
    {
        $actor->requirePermission($permission);
        return $actor->instituteId
            ?? throw new HttpException('FORBIDDEN','Institute scope required.',403);
    }

    private static function id(Request $r,string $key): int
    {
        $v=$r->body[$key]??null;
        if (!is_int($v)||$v<1) {
            throw new HttpException('INVALID_INPUT','Positive '.$key.' ID required.',422);
        }
        return $v;
    }

    private static function text(Request $r,string $key,int $max): string
    {
        $v=$r->body[$key]??null;
        if (!is_string($v)||trim($v)===''||strlen(trim($v))>$max) {
            throw new HttpException('INVALID_INPUT','Valid '.$key.' required.',422);
        }
        return trim($v);
    }

    private static function dateTime(Request $r,string $key): string
    {
        $value=self::text($r,$key,19);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,
            new \DateTimeZone('Asia/Kolkata'));
        if (!$date || $date->format('Y-m-d H:i:s')!==$value
            || $date>(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))) {
            throw new HttpException('INVALID_INPUT',
                'Completed real past/current exam date/time required.',422);
        }
        return $value;
    }

    private static function decimal(Request $r,string $key,bool $positive): array
    {
        $v=self::text($r,$key,8);
        if (!preg_match('/^(?:0|[1-9][0-9]{0,4})(?:\\.[0-9]{1,2})?$/D',$v)) {
            throw new HttpException('INVALID_INPUT','Decimal string '.$key.' required.',422);
        }
        [$whole,$part]=array_pad(explode('.',$v,2),2,'');
        $cents=(int)$whole*100+(int)str_pad($part,2,'0');
        if ($positive && $cents===0) {
            throw new HttpException('INVALID_INPUT','Positive '.$key.' required.',422);
        }
        return [$cents,number_format($cents/100,2,'.','')];
    }

    public function session(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'exam.practical.manage');
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Principal practical session review required.',403);
        }
        $cycle=self::id($r,'cycle_id');
        $year=self::id($r,'batch_year_id');
        $ended=self::dateTime($r,'ended_at');
        $examiner=self::text($r,'examiner_name',190);
        $reference=self::text($r,'examiner_reference',190);
        $authority=self::text($r,'question_paper_authority',20);
        if (!in_array($authority,['DGT','STATE_UT'],true)) {
            throw new HttpException('INVALID_INPUT','Official question paper authority required.',422);
        }
        $paper=self::text($r,'question_paper_reference',190);
        $source=self::id($r,'question_paper_source_id');
        $id=(new PracticalExamService($this->database->connection()))->session(
            $actor,$institute,$cycle,$year,$ended,$examiner,$reference,$authority,$paper,$source);
        return new Response(['success'=>true,'data'=>[
            'practical_session_id'=>$id,'status'=>'locally_recorded',
            'government_submission_verified'=>false
        ]],201);
    }

    public function assignedMarks(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'exam.practical.record.assigned');
        if ($actor->staffId===null || !in_array('instructor',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Assigned instructor required.',403);
        }
        $session=self::id($r,'practical_session_id');
        $eligibility=self::id($r,'eligibility_id');
        $attendance=self::text($r,'attendance_status',20);
        if (!in_array($attendance,['present','absent'],true)) {
            throw new HttpException('INVALID_INPUT','Practical attendance must be present or absent.',422);
        }
        [$maximum,$max]=self::decimal($r,'max_marks',true);
        $input=$r->body['obtained_marks']??null;
        $marks=null;
        if ($attendance==='present') {
            [$score,$marks]=self::decimal($r,'obtained_marks',false);
            if ($score>$maximum) {
                throw new HttpException('INVALID_INPUT','Marks exceed source maximum.',422);
            }
        } elseif ($input!==null) {
            throw new HttpException('INVALID_INPUT','Absent practical must have null obtained marks.',422);
        }
        $source=self::id($r,'marks_source_id');
        $reference=self::text($r,'evidence_reference',500);
        (new PracticalExamService($this->database->connection()))->marks(
            $actor,$institute,$session,$eligibility,$attendance,$max,$marks,$source,$reference);
        return new Response(['success'=>true,'data'=>[
            'practical_session_id'=>$session,'eligibility_id'=>$eligibility,
            'status'=>'locally_recorded','portal_submitted'=>false
        ]],201);
    }
}
