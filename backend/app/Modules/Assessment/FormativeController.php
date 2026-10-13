<?php
declare(strict_types=1);

namespace App\Modules\Assessment;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

/** Formative points in real CTS learning outcomes, not invented templates. */
final readonly class FormativeController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor,string $permission): int
    {
        $actor->requirePermission($permission);
        return $actor->instituteId ?? throw new HttpException('FORBIDDEN','Institute scope required.',403);
    }

    private static function id(Request $request,string $name): int
    {
        $v=$request->body[$name]??null;
        if (!is_int($v)||$v<1) {
            throw new HttpException('INVALID_INPUT','Positive integer '.$name.' required.',422);
        }
        return $v;
    }

    private static function text(Request $request,string $name,int $limit): string
    {
        $value=$request->body[$name]??null;
        if (!is_string($value)||trim($value)===''||strlen(trim($value))>$limit) {
            throw new HttpException('INVALID_INPUT','Invalid '.$name.' text.',422);
        }
        return trim($value);
    }

    private static function marks(Request $request,string $name): array
    {
        $input=self::text($request,$name,8);
        if (!preg_match('/^(?:0|[1-9][0-9]{0,4})(?:\\.[0-9]{1,2})?$/D',$input)) {
            throw new HttpException('INVALID_INPUT',$name.' must be a decimal number with at most two places.',422);
        }
        $parts=explode('.',$input,2);
        $cents=(int)$parts[0]*100+(int)str_pad($parts[1]??'0',2,'0');
        return [$cents,number_format($cents/100,2,'.','')];
    }

    private static function date(Request $request,string $name): string
    {
        $date=self::text($request,$name,10);
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$date)
            || $parsed===false || $parsed->format('Y-m-d')!==$date) {
            throw new HttpException('INVALID_INPUT','Real assessment date required.',422);
        }
        return $date;
    }

    private static function officialDecimal(Request $r,string $field,int $whole,int $scale): string
    {
        $value=self::text($r,$field,$whole+$scale+1);
        if (!preg_match('/^(?:0|[1-9][0-9]{0,'.($whole-1).'})'
            .'(?:\\.[0-9]{1,'.$scale.'})?$/D',$value)
            || (float)$value<=0) {
            throw new HttpException('INVALID_INPUT',
                'Source-declared positive decimal required for '.$field.'.',422);
        }
        return $value;
    }

    public function configureScheme(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'formative.template.manage');
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Principal scheme review required.',403);
        }
        $year=self::id($r,'batch_year_id');
        $source=self::id($r,'regulatory_source_id');
        $method=self::text($r,'formative_method',32);
        if (!in_array($method,['points_ratio','template_weighted'],true)) {
            throw new HttpException('INVALID_INPUT','Only explicit source-declared formative methods are supported.',422);
        }
        $reference=self::text($r,'review_reference',500);
        (new FormativeSchemeService($this->database->connection()))->configure(
            $actor,$institute,$year,$source,$method,$reference);
        return new Response(['success'=>true,'data'=>[
            'batch_year_id'=>$year,'formative_method'=>$method,'reviewed'=>true
        ]]);
    }

    public function templateItem(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'formative.template.manage');
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Principal template review required.',403);
        }
        $year=self::id($r,'batch_year_id');
        $code=self::text($r,'item_code',80);
        $description=self::text($r,'description',500);
        $max=self::officialDecimal($r,'max_marks',6,3);
        $weight=self::officialDecimal($r,'weight_percent',3,4);
        if ((float)$weight>100) {
            throw new HttpException('INVALID_INPUT','Source-declared weight cannot exceed 100%.',422);
        }
        $reference=self::text($r,'review_reference',500);
        (new FormativeSchemeService($this->database->connection()))->item(
            $actor,$institute,$year,$code,$description,$max,$weight,$reference);
        return new Response(['success'=>true,'data'=>[
            'batch_year_id'=>$year,'item_code'=>$code,'weight_percent'=>$weight
        ]],201);
    }

    public function recordAssigned(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'formative.record.assigned');
        $enrollment=self::id($request,'enrollment_id');
        $year=self::id($request,'batch_year_id');
        $outcome=self::id($request,'learning_outcome_id');
        $date=self::date($request,'assessed_on');
        [$max,$maximum]=self::marks($request,'max_marks');
        [$obtainedCents,$obtained]=self::marks($request,'obtained_marks');
        if ($max===0 || $obtainedCents>$max) {
            throw new HttpException('INVALID_MARKS','Marks must be nonnegative and not exceed a positive maximum.',422);
        }
        $evidence=self::text($request,'evidence_reference',190);
        $id=(new FormativeService($this->database->connection()))->record($actor,$institute,
            $enrollment,$year,$outcome,$date,$maximum,$obtained,$evidence);
        return new Response(['success'=>true,'data'=>[
            'formative_assessment_id'=>$id,'locked'=>false
        ]],201);
    }

    public function lock(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'formative.template.manage');
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Principal review required.',403);
        }
        $id=self::id($request,'formative_assessment_id');
        $reference=self::text($request,'review_reference',500);
        (new FormativeService($this->database->connection()))
            ->lock($actor,$institute,$id,$reference);
        return new Response(['success'=>true,'data'=>[
            'formative_assessment_id'=>$id,'locked'=>true
        ]]);
    }
}
