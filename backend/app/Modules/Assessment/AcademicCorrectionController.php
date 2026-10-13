<?php
declare(strict_types=1);
namespace App\Modules\Assessment;
use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

final readonly class AcademicCorrectionController
{
    public function __construct(private Database $database) {}
    private static function id(Request $r,string $name,bool $zero=false): int
    {
        $value=$r->body[$name]??($zero?0:null);
        if (!is_int($value) || $value<($zero?0:1)) throw new HttpException('INVALID_INPUT','Invalid '.$name.'.',422);
        return $value;
    }
    private static function text(Request $r,string $name,int $max): string
    {
        $v=$r->body[$name]??null;
        if (!is_string($v) || trim($v)==='' || strlen($v)>$max) throw new HttpException('INVALID_INPUT','Invalid '.$name.'.',422);
        return trim($v);
    }
    public function attendance(Request $r,AuthContext $a): Response
    {
        $a->requirePermission('attendance.correct');
        $session=self::id($r,'session_id'); $enrollment=self::id($r,'enrollment_id');
        $minutes=self::id($r,'attended_minutes',true); $status=self::text($r,'status',30);
        $source=self::id($r,'source_id',true); $reason=self::text($r,'reason',1000); $reference=self::text($r,'authority_reference',190); $expected=self::id($r,'expected_correction_id',true);
        $id=(new AcademicCorrectionService($this->database->connection()))->attendance($a,$session,$enrollment,$minutes,$status,$source,$reason,$reference,$expected);
        return new Response(['success'=>true,'data'=>['correction_id'=>$id]],201);
    }
    public function formative(Request $r,AuthContext $a): Response
    {
        $a->requirePermission('formative.template.manage');
        $assessment=array_key_exists('assessment_id',$r->body)?self::id($r,'assessment_id'):null;
        $item=array_key_exists('item_code',$r->body)?self::text($r,'item_code',80):null;
        $enrollment=self::id($r,'enrollment_id'); $year=self::id($r,'batch_year_id');
        $marks=self::text($r,'obtained_marks',12); $source=self::id($r,'source_id',true);
        $reason=self::text($r,'reason',1000); $reference=self::text($r,'authority_reference',190); $expected=self::id($r,'expected_correction_id',true);
        $id=(new AcademicCorrectionService($this->database->connection()))->formative($a,$assessment,$enrollment,$year,$item,$marks,$source,$reason,$reference,$expected);
        return new Response(['success'=>true,'data'=>['correction_id'=>$id]],201);
    }
    public function history(Request $r,AuthContext $a): Response
    {
        $a->requirePermission('audit.view');
        $institute=$a->instituteId??throw new HttpException('FORBIDDEN','Institute identity required.',403);
        $type=$r->query['type']??'attendance';
        $table=match($type){'attendance'=>'attendance_corrections','formative'=>'formative_corrections',default=>throw new HttpException('INVALID_INPUT','Invalid correction type.',422)};
        $after=$r->query['after_id']??0; $size=$r->query['page_size']??50;
        if (filter_var($after,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false || filter_var($size,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]])===false) throw new HttpException('INVALID_INPUT','Invalid pagination.',422);
        $q=$this->database->connection()->prepare("SELECT * FROM $table WHERE institute_id=? AND id>? ORDER BY id LIMIT ?");
        foreach ([$institute,(int)$after,(int)$size] as $i=>$v) $q->bindValue($i+1,$v,\PDO::PARAM_INT);
        $q->execute(); $rows=$q->fetchAll(\PDO::FETCH_ASSOC);
        return new Response(['success'=>true,'data'=>['corrections'=>$rows,'next_after_id'=>count($rows)===(int)$size?(int)$rows[count($rows)-1]['id']:null]]);
    }
}
