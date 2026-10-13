<?php
declare(strict_types=1);

namespace App\Modules\Regulatory;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

final readonly class CurriculumMasterController
{
    public function __construct(private Database $database) {}

    private static function platform(AuthContext $actor): void
    {
        $actor->requirePermission('platform.regulatory.manage');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Platform Developer required.',403);
        }
    }

    private static function id(Request $request,string $key): int
    {
        $v=$request->body[$key]??null;
        if (!is_int($v)||$v<1) throw new HttpException('INVALID_INPUT','Positive integer '.$key.' required.',422);
        return $v;
    }

    private static function count(Request $request,string $key,int $max): int
    {
        $v=$request->body[$key]??null;
        if (!is_int($v)||$v<0||$v>$max) throw new HttpException('INVALID_INPUT','Invalid '.$key.' quantity.',422);
        return $v;
    }

    private static function text(Request $request,string $key,int $max): string
    {
        $v=$request->body[$key]??null;
        if (!is_string($v)||trim($v)===''||strlen(trim($v))>$max) {
            throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
        }
        return trim($v);
    }

    private static function optionalText(Request $request,string $key,int $max): ?string
    {
        $v=$request->body[$key]??null;
        if ($v===null) return null;
        if (!is_string($v)||strlen(trim($v))>$max) {
            throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
        }
        return trim($v)===''?null:trim($v);
    }

    private static function date(Request $request,string $key): string
    {
        $v=self::text($request,$key,10);
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$v)
            || !$d || $d->format('Y-m-d')!==$v) {
            throw new HttpException('INVALID_INPUT','Real '.$key.' required.',422);
        }
        return $v;
    }

    private static function decimal(Request $request,string $key,int $before,int $places,bool $required): ?string
    {
        $v=$request->body[$key]??null;
        if ($v===null && !$required) return null;
        if (!is_string($v) || !preg_match('/^(?:0|[1-9][0-9]{0,'.($before-1).'})'
            .'(?:\\.[0-9]{1,'.$places.'})?$/D',$v)) {
            throw new HttpException('INVALID_INPUT','Decimal string '.$key.' required.',422);
        }
        return $v;
    }

    private function service(): CurriculumMasterService
    {
        return new CurriculumMasterService($this->database->connection());
    }

    public function draft(Request $r,AuthContext $actor): Response
    {
        self::platform($actor);
        $trade=self::id($r,'global_trade_id');
        $source=self::id($r,'source_document_id');
        $version=self::text($r,'version_code',80);
        $duration=self::id($r,'duration_months');
        if (!in_array($duration,[6,12,24],true)) {
            throw new HttpException('INVALID_INPUT','Supported source-declared duration required.',422);
        }
        $level=self::decimal($r,'nsqf_level',1,1,true);
        if ((float)$level<1 || (float)$level>8) {
            throw new HttpException('INVALID_INPUT','NSQF level outside schema bounds.',422);
        }
        $strength=self::id($r,'unit_strength');
        if ($strength>65535) throw new HttpException('INVALID_INPUT','unit_strength is too large.',422);
        $from=self::date($r,'effective_from');
        $to=$r->body['effective_to']??null;
        if ($to!==null) {
            $to=self::date($r,'effective_to');
            if ($to<$from) throw new HttpException('INVALID_INPUT','Invalid effective date range.',422);
        }
        $id=$this->service()->draft($actor,$trade,$source,$version,$duration,
            $level,$strength,$from,$to);
        return new Response(['success'=>true,'data'=>['global_curriculum_id'=>$id,'status'=>'draft']],201);
    }

    public function year(Request $r,AuthContext $actor): Response
    {
        self::platform($actor);
        $id=self::id($r,'global_curriculum_id');
        $year=self::id($r,'year_no');
        if ($year>2) throw new HttpException('INVALID_INPUT','Invalid CTS year number.',422);
        $practical=self::count($r,'trade_practical_minutes',4294967295);
        $theory=self::count($r,'trade_theory_minutes',4294967295);
        $employ=self::count($r,'employability_minutes',4294967295);
        $ojt=self::count($r,'ojt_project_minutes',4294967295);
        $credits=self::decimal($r,'credits',5,2,false);
        $created=$this->service()->year($actor,$id,$year,$practical,$theory,$employ,$ojt,$credits);
        return new Response(['success'=>true,'data'=>['global_curriculum_year_id'=>$created]],201);
    }

    public function outcome(Request $r,AuthContext $actor): Response
    {
        self::platform($actor);
        $year=self::id($r,'global_curriculum_year_id');
        $code=self::text($r,'lo_code',80);
        $nos=self::optionalText($r,'nos_code',80);
        $outcome=self::text($r,'outcome_text',16000);
        $criteria=self::text($r,'assessment_criteria',16000);
        $max=self::decimal($r,'formative_max_marks',5,2,false);
        if ($max!==null && (float)$max<=0) {
            throw new HttpException('INVALID_INPUT','formative_max_marks must be positive.',422);
        }
        $id=$this->service()->outcome($actor,$year,$code,$nos,$outcome,$criteria,$max);
        return new Response(['success'=>true,'data'=>['global_learning_outcome_id'=>$id]],201);
    }

    public function publish(Request $r,AuthContext $actor): Response
    {
        self::platform($actor);
        $id=self::id($r,'global_curriculum_id');
        $reference=self::text($r,'review_reference',500);
        $this->service()->publish($actor,$id,$reference);
        return new Response(['success'=>true,'data'=>[
            'global_curriculum_id'=>$id,'status'=>'published',
            'government_verified'=>false
        ]]);
    }
}
