<?php
declare(strict_types=1);

namespace App\Modules\Regulatory;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

/**
 * Published DGT CTS syllabus browser. Regulatory masters stay external authority:
 * this endpoint only exposes source-backed ERP records, never draft or unverifiable
 * government outcomes. Institute scope is read-only.
 */
final readonly class CurriculumReadController
{
    public function __construct(private Database $database) {}

    private static function integer(Request $r, string $key, int $default, int $max): int
    {
        if (!array_key_exists($key,$r->query)) return $default;
        $raw=$r->query[$key];
        $n=(is_string($raw)||is_int($raw))
            ? filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$max]])
            : false;
        if ($n===false) throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
        return (int)$n;
    }

    private function listing(Request $r, AuthContext $actor, string $kind): Response
    {
        if ($actor->instituteId===null) {
            $actor->requirePermission('platform.regulatory.manage');
            if (!in_array('developer',$actor->roles,true)) {
                throw new HttpException('FORBIDDEN','Developer scope required.',403);
            }
        } else {
            $actor->requirePermission('curriculum.view');
        }
        $after=self::integer($r,'after_id',0,PHP_INT_MAX);
        $size=self::integer($r,'page_size',50,100);
        $global=self::integer($r,'global_curriculum_id',0,PHP_INT_MAX);
        $year=self::integer($r,'global_curriculum_year_id',0,PHP_INT_MAX);
        $where="c.status='published' AND d.status='published' AND d.authority='DGT'";
        $args=[];
        $query=match ($kind) {
            'curricula' => "SELECT c.id,c.trade_id,t.dgt_code,c.version_code,c.duration_months,
                c.nsqf_level,c.unit_strength,c.effective_from,c.effective_to,
                c.source_document_id,d.reference_no AS source_reference,c.status
                FROM global_curriculum_versions c
                JOIN trades t ON t.id=c.trade_id
                JOIN global_regulatory_documents d ON d.id=c.source_document_id
                WHERE $where AND c.id>?",
            'years' => "SELECT y.id,y.global_curriculum_id,y.year_no,
                y.trade_practical_minutes,y.trade_theory_minutes,y.employability_minutes,
                y.ojt_project_minutes,y.credits,c.version_code,t.dgt_code
                FROM global_curriculum_years y
                JOIN global_curriculum_versions c ON c.id=y.global_curriculum_id
                JOIN trades t ON t.id=c.trade_id
                JOIN global_regulatory_documents d ON d.id=c.source_document_id
                WHERE $where AND y.id>?",
            'outcomes' => "SELECT o.id,o.global_curriculum_year_id,y.global_curriculum_id,
                y.year_no,o.lo_code,o.nos_code,o.outcome_text,
                o.assessment_criteria,o.formative_max_marks
                FROM global_learning_outcomes o
                JOIN global_curriculum_years y ON y.id=o.global_curriculum_year_id
                JOIN global_curriculum_versions c ON c.id=y.global_curriculum_id
                JOIN global_regulatory_documents d ON d.id=c.source_document_id
                WHERE $where AND o.id>?",
            default => throw new \LogicException('Unsupported syllabus register')
        };
        $args[]=$after;
        if ($global>0 && $kind!=='curricula') { $query.=' AND '.($kind==='years'?'y':'y').'.global_curriculum_id=?'; $args[]=$global; }
        if ($year>0 && $kind==='outcomes') { $query.=' AND o.global_curriculum_year_id=?'; $args[]=$year; }
        $query.=' ORDER BY '.match($kind){'curricula'=>'c.id','years'=>'y.id','outcomes'=>'o.id'}.' ASC LIMIT ?';
        $q=$this->database->connection()->prepare($query);
        $args[]=$size;
        foreach($args as $i=>$value)$q->bindValue($i+1,$value,PDO::PARAM_INT);
        $q->execute();
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $next=count($rows)===$size?(int)$rows[count($rows)-1]['id']:null;
        return new Response(['success'=>true,'data'=>['records'=>$rows,'next_after_id'=>$next]]);
    }

    public function curricula(Request $r,AuthContext $a): Response { return $this->listing($r,$a,'curricula'); }
    public function years(Request $r,AuthContext $a): Response { return $this->listing($r,$a,'years'); }
    public function outcomes(Request $r,AuthContext $a): Response { return $this->listing($r,$a,'outcomes'); }
}
