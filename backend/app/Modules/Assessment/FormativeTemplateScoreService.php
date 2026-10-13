<?php
declare(strict_types=1);

namespace App\Modules\Assessment;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Source-weighted CTS formative scores; frozen scores are never overwritten. */
final readonly class FormativeTemplateScoreService
{
    public function __construct(private PDO $db) {}

    private function row(string $sql,array $args): ?array
    {
        $q=$this->db->prepare($sql);
        $q->execute($args);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function thousandths(string $decimal): int
    {
        [$whole,$fraction]=array_pad(explode('.',$decimal,2),2,'');
        return (int)$whole*1000+(int)str_pad($fraction,3,'0');
    }

    private function year(int $institute,int $yearId): array
    {
        $row=$this->row('SELECT y.id,y.batch_id,y.starts_on,y.ends_on,
            y.formative_method,y.formative_scheme_source_id,b.trade_unit_shift_id
            FROM batch_years y JOIN batches b
              ON b.id=y.batch_id AND b.institute_id=y.institute_id
            WHERE y.id=? AND y.institute_id=? FOR UPDATE',[$yearId,$institute]);
        if (!$row || $row['formative_method']!=='template_weighted'
            || $row['formative_scheme_source_id']===null) {
            throw new HttpException('FORMATIVE_SCHEME_REQUIRED',
                'Published source-backed template-weighted method is required.',409);
        }
        $source=$this->row('SELECT d.status,d.sha256
            FROM regulatory_sources r JOIN global_regulatory_documents d
              ON d.id=r.global_document_id
            WHERE r.id=? AND r.institute_id=? FOR UPDATE',
            [$row['formative_scheme_source_id'],$institute]);
        if (!$source || $source['status']!=='published' || !$source['sha256']) {
            throw new HttpException('FORMATIVE_SOURCE_REQUIRED',
                'Reviewed published formative source is required.',409);
        }
        return $row;
    }

    private function item(int $institute,int $yearId,string $code): array
    {
        $item=$this->row('SELECT item_code,max_marks FROM formative_template_items
            WHERE batch_year_id=? AND institute_id=? AND item_code=? FOR UPDATE',
            [$yearId,$institute,$code]);
        if (!$item) throw new HttpException('FORMATIVE_ITEM_NOT_FOUND','Official template item not found.',404);
        $q=$this->db->prepare('SELECT COUNT(*) item_count,COALESCE(SUM(weight_percent),0) weight_total
            FROM formative_template_items WHERE batch_year_id=? AND institute_id=?');
        $q->execute([$yearId,$institute]);
        $sum=$q->fetch(PDO::FETCH_ASSOC);
        if ((int)$sum['item_count']===0
            || (int)round((float)$sum['weight_total']*10000)!==1000000) {
            throw new HttpException('FORMATIVE_WEIGHTS',
                'Complete reviewed template weights must total exactly 100 percent.',409);
        }
        return $item;
    }

    private function enrollment(int $institute,int $enrollment,int $batch,string $on): void
    {
        if (!$this->row("SELECT id FROM enrollments
            WHERE id=? AND institute_id=? AND batch_id=?
              AND status='active' AND admitted_on<=? FOR UPDATE",
            [$enrollment,$institute,$batch,$on])) {
            throw new HttpException('ENROLLMENT_NOT_ACTIVE',
                'Active enrollment in the applicable CTS batch is required.',409);
        }
    }

    private function noFrozenEligibility(int $institute,int $year,int $enrollment): void
    {
        if ($this->row('SELECT id FROM exam_eligibility
            WHERE institute_id=? AND batch_year_id=? AND enrollment_id=?
            LIMIT 1 FOR UPDATE',[$institute,$year,$enrollment])) {
            throw new HttpException('FORMATIVE_ELIGIBILITY_FROZEN',
                'An eligibility snapshot exists; source-backed correction/review is required.',409);
        }
    }

    public function record(AuthContext $actor,int $institute,int $enrollment,
        int $yearId,string $itemCode,string $assessedOn,string $marks,
        string $evidence): void
    {
        if ($actor->staffId===null || !in_array('instructor',$actor->roles,true)) {
            throw new HttpException('ASSIGNED_INSTRUCTOR_REQUIRED','Mapped instructor required.',403);
        }
        $tx=new InstituteTransaction($this->db);
        try {
            $tx->run($actor,$institute,'formative.record.assigned',function () use (
                $actor,$institute,$enrollment,$yearId,$itemCode,$assessedOn,
                $marks,$evidence,$tx
            ): void {
                $year=$this->year($institute,$yearId);
                if ($assessedOn<$year['starts_on'] || $assessedOn>$year['ends_on']
                    || $assessedOn>(new \DateTimeImmutable('now',
                        new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d')) {
                    throw new HttpException('FORMATIVE_DATE',
                        'Actual assessment must be within the applicable course year.',422);
                }
                $this->enrollment($institute,$enrollment,(int)$year['batch_id'],$assessedOn);
                $this->noFrozenEligibility($institute,$yearId,$enrollment);
                $item=$this->item($institute,$yearId,$itemCode);
                if (self::thousandths($marks)>self::thousandths((string)$item['max_marks'])) {
                    throw new HttpException('FORMATIVE_MARKS',
                        'Obtained marks exceed source-authored template maximum.',422);
                }
                $mapping=$this->row("SELECT m.id FROM instructor_mappings m
                    JOIN staff s ON s.id=m.staff_id AND s.institute_id=m.institute_id
                    WHERE m.staff_id=? AND m.institute_id=?
                      AND m.mapping_type='trade' AND m.trade_unit_shift_id=?
                      AND m.status='active' AND m.valid_from<=?
                      AND (m.valid_to IS NULL OR m.valid_to>=?)
                      AND s.nims_verification='verified' AND s.left_on IS NULL
                    LIMIT 1 FOR UPDATE",[$actor->staffId,$institute,
                        $year['trade_unit_shift_id'],$assessedOn,$assessedOn]);
                if (!$mapping) {
                    throw new HttpException('INSTRUCTOR_NOT_ASSIGNED',
                        'Only the currently mapped verified Trade instructor may record scores.',403);
                }
                if ($this->row('SELECT item_code FROM formative_template_scores
                    WHERE enrollment_id=? AND batch_year_id=? AND item_code=?
                      AND institute_id=? FOR UPDATE',
                    [$enrollment,$yearId,$itemCode,$institute])) {
                    throw new HttpException('FORMATIVE_SCORE_EXISTS',
                        'Template score already exists; request a reviewed correction.',409);
                }
                $this->db->prepare('INSERT INTO formative_template_scores
                    (institute_id,enrollment_id,batch_id,batch_year_id,item_code,
                     obtained_marks,assessed_on,assessor_id,evidence_reference)
                    VALUES (?,?,?,?,?,?,?,?,?)')->execute([
                        $institute,$enrollment,$year['batch_id'],$yearId,$itemCode,
                        $marks,$assessedOn,$actor->staffId,$evidence
                    ]);
                $tx->audit($actor,$institute,'formative.template.score.record',
                    'formative_template_scores',$yearId,[
                        'enrollment_id'=>$enrollment,'item_code'=>$itemCode,
                        'obtained_marks'=>$marks,'assessed_on'=>$assessedOn,
                        'evidence_reference'=>$evidence
                    ]);
            });
        } catch(PDOException $e) {
            if ((int)($e->errorInfo[1]??0)===1062) {
                throw new HttpException('FORMATIVE_SCORE_EXISTS',
                    'Template score already exists.',409);
            }
            throw $e;
        }
    }

    public function lock(AuthContext $actor,int $institute,int $enrollment,
        int $yearId,string $code,string $review): void
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_REQUIRED','Principal review required.',403);
        }
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'formative.template.manage',function () use (
            $actor,$institute,$enrollment,$yearId,$code,$review,$tx
        ): void {
            $year=$this->year($institute,$yearId);
            $this->item($institute,$yearId,$code);
            $score=$this->row('SELECT obtained_marks,assessed_on,evidence_reference,locked_on
                FROM formative_template_scores
                WHERE enrollment_id=? AND batch_year_id=? AND item_code=?
                  AND institute_id=? FOR UPDATE',[$enrollment,$yearId,$code,$institute]);
            if (!$score) throw new HttpException('NOT_FOUND','Template score not found.',404);
            if ($score['locked_on']!==null) {
                throw new HttpException('FORMATIVE_LOCKED','Score is already frozen.',409);
            }
            $this->db->prepare('UPDATE formative_template_scores SET locked_on=UTC_TIMESTAMP()
                WHERE enrollment_id=? AND batch_year_id=? AND item_code=?
                  AND institute_id=? AND locked_on IS NULL')->execute([
                    $enrollment,$yearId,$code,$institute
                ]);
            $tx->audit($actor,$institute,'formative.template.score.lock',
                'formative_template_scores',$yearId,[
                    'enrollment_id'=>$enrollment,'item_code'=>$code,
                    'review_reference'=>$review,'source_id'=>$year['formative_scheme_source_id'],
                    'obtained_marks'=>$score['obtained_marks'],
                    'evidence_reference'=>$score['evidence_reference']
                ]);
        });
    }

    /** Only a not-yet-locked entry can be corrected, with full before/after audit. */
    public function correctUnlocked(AuthContext $actor,int $institute,int $enrollment,
        int $yearId,string $code,string $marks,string $assessedOn,
        string $evidence,string $reason,string $review): void
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_REQUIRED','Principal correction review required.',403);
        }
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'formative.template.manage',function () use (
            $actor,$institute,$enrollment,$yearId,$code,$marks,$assessedOn,
            $evidence,$reason,$review,$tx
        ): void {
            $year=$this->year($institute,$yearId);
            if ($assessedOn<$year['starts_on'] || $assessedOn>$year['ends_on']
                || $assessedOn>(new \DateTimeImmutable('now',
                    new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d')) {
                throw new HttpException('FORMATIVE_DATE',
                    'Correction date must represent a completed day within the course year.',422);
            }
            $this->enrollment($institute,$enrollment,(int)$year['batch_id'],$assessedOn);
            $this->noFrozenEligibility($institute,$yearId,$enrollment);
            $item=$this->item($institute,$yearId,$code);
            if (self::thousandths($marks)>self::thousandths((string)$item['max_marks'])) {
                throw new HttpException('FORMATIVE_MARKS','Score exceeds official item maximum.',422);
            }
            $before=$this->row('SELECT obtained_marks,assessed_on,evidence_reference,locked_on
                FROM formative_template_scores
                WHERE enrollment_id=? AND batch_year_id=? AND item_code=?
                  AND institute_id=? FOR UPDATE',[$enrollment,$yearId,$code,$institute]);
            if (!$before) throw new HttpException('NOT_FOUND','Template score not found.',404);
            if ($before['locked_on']!==null) {
                throw new HttpException('FORMATIVE_LOCKED',
                    'Frozen assessment cannot be overwritten; use a reviewed revision.',409);
            }
            $this->db->prepare('UPDATE formative_template_scores
                SET obtained_marks=?,assessed_on=?,evidence_reference=?
                WHERE enrollment_id=? AND batch_year_id=? AND item_code=?
                  AND institute_id=? AND locked_on IS NULL')->execute([
                    $marks,$assessedOn,$evidence,$enrollment,$yearId,$code,$institute
                ]);
            $tx->audit($actor,$institute,'formative.template.score.correct_unlocked',
                'formative_template_scores',$yearId,[
                    'enrollment_id'=>$enrollment,'item_code'=>$code,
                    'before'=>[
                        'obtained_marks'=>$before['obtained_marks'],
                        'assessed_on'=>$before['assessed_on'],
                        'evidence_reference'=>$before['evidence_reference']
                    ],'after'=>[
                        'obtained_marks'=>$marks,
                        'assessed_on'=>$assessedOn,
                        'evidence_reference'=>$evidence
                    ],'reason'=>$reason,'review_reference'=>$review
                ]);
        });
    }
}
