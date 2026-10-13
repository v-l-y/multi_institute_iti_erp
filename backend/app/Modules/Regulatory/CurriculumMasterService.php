<?php
declare(strict_types=1);

namespace App\Modules\Regulatory;

use App\Core\Transaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Global CTS syllabus rows are recorded only from a reviewed DGT source. */
final readonly class CurriculumMasterService
{
    public function __construct(private PDO $db) {}

    private static function allowed(AuthContext $actor): void
    {
        $actor->requirePermission('platform.regulatory.manage');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Platform regulatory reviewer required.',403);
        }
    }

    private function row(string $sql,array $args): ?array
    {
        $q=$this->db->prepare($sql);
        $q->execute($args);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function actor(AuthContext $actor): void
    {
        $row=$this->row("SELECT id FROM auth_users
            WHERE id=? AND institute_id IS NULL AND status='active' FOR UPDATE",[$actor->userId]);
        if (!$row) throw new HttpException('FORBIDDEN','Active platform account required.',403);
    }

    private function source(int $document): void
    {
        $row=$this->row('SELECT authority,status,sha256 FROM global_regulatory_documents
            WHERE id=? FOR UPDATE',[$document]);
        if (!$row || $row['authority']!=='DGT' || $row['status']!=='published'
            || !$row['sha256']) {
            throw new HttpException('DGT_SYLLABUS_SOURCE',
                'Published reviewed DGT syllabus source with SHA-256 required.',409);
        }
    }

    private function audit(AuthContext $actor,string $event,array $data): void
    {
        $q=$this->db->prepare('INSERT INTO auth_audit_log
            (institute_id,user_id,event_type,event_data_json,occurred_at)
            VALUES (NULL,?,?,?,UTC_TIMESTAMP())');
        $q->execute([$actor->userId,$event,json_encode($data,JSON_THROW_ON_ERROR)]);
    }

    public function draft(AuthContext $actor,int $trade,int $source,string $version,
        int $duration,string $level,int $capacity,string $effectiveFrom,?string $effectiveTo): int
    {
        self::allowed($actor);
        try {
            return (new Transaction($this->db))->run(function () use (
                $actor,$trade,$source,$version,$duration,$level,$capacity,$effectiveFrom,$effectiveTo
            ): int {
                $this->actor($actor);
                $this->source($source);
                $active=$this->row("SELECT id FROM trade_master_revisions
                    WHERE trade_id=? AND status='active'
                      AND effective_from<=?
                      AND (effective_to IS NULL OR effective_to>=?) FOR UPDATE",
                    [$trade,$effectiveFrom,$effectiveFrom]);
                if (!$active) {
                    throw new HttpException('ACTIVE_TRADE_REQUIRED','Applicable active source-backed DGT Trade required.',409);
                }
                $q=$this->db->prepare("INSERT INTO global_curriculum_versions
                    (trade_id,source_document_id,version_code,duration_months,nsqf_level,
                     unit_strength,effective_from,effective_to,status)
                    VALUES (?,?,?,?,?,?,?,?,'draft')");
                $q->execute([$trade,$source,$version,$duration,$level,$capacity,$effectiveFrom,$effectiveTo]);
                $id=(int)$this->db->lastInsertId();
                $this->audit($actor,'platform.curriculum.draft',[
                    'global_curriculum_id'=>$id,'source_document_id'=>$source,'trade_id'=>$trade
                ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('CURRICULUM_EXISTS','Trade syllabus revision already registered.',409);
            }
            throw $e;
        }
    }

    private function draftYear(int $year): array
    {
        $row=$this->row('SELECT y.id,y.global_curriculum_id,c.status
            FROM global_curriculum_years y JOIN global_curriculum_versions c
              ON c.id=y.global_curriculum_id
            WHERE y.id=? FOR UPDATE',[$year]);
        if (!$row) throw new HttpException('NOT_FOUND','Syllabus course year not found.',404);
        if ($row['status']!=='draft') {
            throw new HttpException('CURRICULUM_FROZEN','Only draft syllabus revisions can be populated.',409);
        }
        return $row;
    }

    public function year(AuthContext $actor,int $curriculum,int $yearNo,
        int $practical,int $theory,int $employability,int $ojt,?string $credits): int
    {
        self::allowed($actor);
        try {
            return (new Transaction($this->db))->run(function () use (
                $actor,$curriculum,$yearNo,$practical,$theory,$employability,$ojt,$credits
            ): int {
                $this->actor($actor);
                $base=$this->row('SELECT id,status FROM global_curriculum_versions WHERE id=? FOR UPDATE',[$curriculum]);
                if (!$base) throw new HttpException('NOT_FOUND','Global syllabus not found.',404);
                if ($base['status']!=='draft') {
                    throw new HttpException('CURRICULUM_FROZEN','Published syllabus cannot be amended.',409);
                }
                if ($practical+$theory+$employability+$ojt===0) {
                    throw new HttpException('SYLLABUS_HOURS_REQUIRED','Explicit syllabus component time required.',422);
                }
                $q=$this->db->prepare('INSERT INTO global_curriculum_years
                    (global_curriculum_id,year_no,trade_practical_minutes,trade_theory_minutes,
                     employability_minutes,ojt_project_minutes,credits) VALUES (?,?,?,?,?,?,?)');
                $q->execute([$curriculum,$yearNo,$practical,$theory,$employability,$ojt,$credits]);
                $id=(int)$this->db->lastInsertId();
                $this->audit($actor,'platform.curriculum.year.add',[
                    'global_curriculum_id'=>$curriculum,'global_curriculum_year_id'=>$id
                ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('CURRICULUM_YEAR_EXISTS','Course year already registered.',409);
            }
            throw $e;
        }
    }

    public function outcome(AuthContext $actor,int $year,string $loCode,?string $nos,
        string $description,string $criteria,?string $max): int
    {
        self::allowed($actor);
        try {
            return (new Transaction($this->db))->run(function () use (
                $actor,$year,$loCode,$nos,$description,$criteria,$max
            ): int {
                $this->actor($actor);
                $this->draftYear($year);
                $q=$this->db->prepare('INSERT INTO global_learning_outcomes
                    (global_curriculum_year_id,lo_code,nos_code,outcome_text,
                     assessment_criteria,formative_max_marks) VALUES (?,?,?,?,?,?)');
                $q->execute([$year,$loCode,$nos,$description,$criteria,$max]);
                $id=(int)$this->db->lastInsertId();
                $this->audit($actor,'platform.curriculum.outcome.add',[
                    'global_learning_outcome_id'=>$id,'global_curriculum_year_id'=>$year
                ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('LEARNING_OUTCOME_EXISTS','LO code already exists for course year.',409);
            }
            throw $e;
        }
    }

    public function publish(AuthContext $actor,int $id,string $review): void
    {
        self::allowed($actor);
        (new Transaction($this->db))->run(function () use ($actor,$id,$review): void {
            $this->actor($actor);
            $curriculum=$this->row('SELECT * FROM global_curriculum_versions WHERE id=? FOR UPDATE',[$id]);
            if (!$curriculum) throw new HttpException('NOT_FOUND','Syllabus revision not found.',404);
            if ($curriculum['status']!=='draft') {
                throw new HttpException('CURRICULUM_FROZEN','Only draft syllabus may be published.',409);
            }
            $this->source((int)$curriculum['source_document_id']);
            $q=$this->db->prepare('SELECT y.id,y.year_no,
                (SELECT COUNT(*) FROM global_learning_outcomes g
                 WHERE g.global_curriculum_year_id=y.id) AS lo_count
                FROM global_curriculum_years y
                WHERE y.global_curriculum_id=?
                ORDER BY y.year_no FOR UPDATE');
            $q->execute([$id]);
            $years=$q->fetchAll(PDO::FETCH_ASSOC);
            $required=(int)$curriculum['duration_months']===24?2:1;
            if (count($years)!==$required) {
                throw new HttpException('SYLLABUS_YEAR_INCOMPLETE',
                    'Complete source-declared CTS course years required.',409);
            }
            foreach ($years as $i=>$year) {
                if ((int)$year['year_no']!==$i+1 || (int)$year['lo_count']===0) {
                    throw new HttpException('SYLLABUS_LO_INCOMPLETE',
                        'Every applicable syllabus course year must list learning outcomes.',409);
                }
            }
            $this->db->prepare("UPDATE global_curriculum_versions SET status='published'
                WHERE id=? AND status='draft'")->execute([$id]);
            $this->audit($actor,'platform.curriculum.publish',[
                'global_curriculum_id'=>$id,
                'source_document_id'=>$curriculum['source_document_id'],
                'review_reference'=>$review,'years'=>$required
            ]);
        });
    }
}
