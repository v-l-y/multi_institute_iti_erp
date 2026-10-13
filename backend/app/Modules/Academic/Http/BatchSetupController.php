<?php
declare(strict_types=1);

namespace App\Modules\Academic\Http;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Academic\Application\BatchSetupService;

final readonly class BatchSetupController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor,string $permission): int
    {
        $actor->requirePermission($permission);
        return $actor->instituteId ?? throw new HttpException('FORBIDDEN','Institute scope required.',403);
    }
    private static function id(Request $request,string $field): int
    {
        $value=$request->body[$field]??null;
        if (!is_int($value)||$value<1) throw new HttpException('INVALID_INPUT','Positive integer '.$field.' required.',422);
        return $value;
    }
    private static function text(Request $request,string $key,int $max): string
    {
        $v=$request->body[$key]??null;
        if (!is_string($v)||trim($v)===''||strlen(trim($v))>$max) {
            throw new HttpException('INVALID_INPUT',$key.' must be nonempty text.',422);
        }
        return trim($v);
    }
    private static function date(Request $request,string $key): string
    {
        $raw=self::text($request,$key,10);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$raw);
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$raw)
            || $date===false || $date->format('Y-m-d')!==$raw) {
            throw new HttpException('INVALID_INPUT','Real YYYY-MM-DD '.$key.' required.',422);
        }
        return $raw;
    }
    private function service(): BatchSetupService
    {
        return new BatchSetupService($this->database->connection());
    }
    public function linkCurriculum(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'curriculum.manage');
        $global=self::id($request,'global_curriculum_id');
        $id=$this->service()->curriculum($actor,$institute,$global);
        return new Response(['success'=>true,'data'=>['curriculum_id'=>$id]],201);
    }
    public function linkCurriculumYear(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'curriculum.manage');
        $curriculum=self::id($request,'curriculum_id');
        $globalYear=self::id($request,'global_curriculum_year_id');
        $id=$this->service()->curriculumYear($actor,$institute,$curriculum,$globalYear);
        return new Response(['success'=>true,'data'=>['curriculum_year_id'=>$id]],201);
    }
    public function linkAdmissionRule(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'batch.manage');
        $curriculum=self::id($request,'curriculum_id');
        $globalRule=self::id($request,'global_admission_rule_id');
        $id=$this->service()->admissionRule($actor,$institute,$curriculum,$globalRule);
        return new Response(['success'=>true,'data'=>['admission_rule_id'=>$id]],201);
    }
    public function createBatch(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'batch.manage');
        $entry=self::id($request,'seat_matrix_entry_id');
        $curriculum=self::id($request,'curriculum_id');
        $rule=self::id($request,'admission_rule_id');
        $code=self::text($request,'batch_code',80);
        $starts=self::date($request,'starts_on');
        $ends=self::date($request,'ends_on');
        if ($ends<$starts) throw new HttpException('INVALID_INPUT','Batch end cannot precede start.',422);
        $id=$this->service()->batch($actor,$institute,$entry,$curriculum,$rule,
            $code,$starts,$ends);
        return new Response(['success'=>true,'data'=>[
            'batch_id'=>$id,'status'=>'planned','capacity_source'=>'final_seat_matrix'
        ]],201);
    }
    public function createBatchYear(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'batch.manage');
        $batch=self::id($request,'batch_id');
        $year=self::id($request,'curriculum_year_id');
        $starts=self::date($request,'starts_on');
        $ends=self::date($request,'ends_on');
        if ($ends<$starts) throw new HttpException('INVALID_INPUT','Course year dates invalid.',422);
        $basis=self::text($request,'attendance_basis',20);
        if (!in_array($basis,['minutes','working_days'],true)) {
            throw new HttpException('INVALID_INPUT','Attendance basis must be source-backed.',422);
        }
        $source=self::id($request,'attendance_basis_source_id');
        $id=$this->service()->batchYear($actor,$institute,$batch,$year,
            $starts,$ends,$basis,$source);
        return new Response(['success'=>true,'data'=>[
            'batch_year_id'=>$id,'calendar_status'=>'draft'
        ]],201);
    }
}
