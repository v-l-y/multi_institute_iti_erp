<?php
declare(strict_types=1);

namespace App\Modules\Academic\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Institute links and batch setup only from published regulatory masters. */
final readonly class BatchSetupService
{
    public function __construct(private PDO $db) {}

    private function row(string $sql,array $arguments): ?array
    {
        $q=$this->db->prepare($sql);
        $q->execute($arguments);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function curriculum(AuthContext $actor,int $institute,int $global): int
    {
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'curriculum.manage',function () use ($actor,$institute,$global,$tx): int {
            $source=$this->row("SELECT g.id,g.trade_id FROM global_curriculum_versions g
                JOIN global_regulatory_documents d ON d.id=g.source_document_id
                WHERE g.id=? AND g.status='published' AND d.status='published' FOR UPDATE",[$global]);
            if (!$source) throw new HttpException('CURRICULUM_NOT_PUBLISHED','Published global CTS curriculum required.',409);
            if ($this->row('SELECT id FROM curriculum_versions
                WHERE global_curriculum_id=? AND institute_id=? FOR UPDATE',[$global,$institute])) {
                throw new HttpException('CURRICULUM_LINK_EXISTS','Curriculum already linked to institute.',409);
            }
            $q=$this->db->prepare('INSERT INTO curriculum_versions
                (institute_id,trade_id,global_curriculum_id) VALUES (?,?,?)');
            $q->execute([$institute,$source['trade_id'],$global]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'curriculum.link','curriculum_versions',$id,
                ['global_curriculum_id'=>$global]);
            return $id;
        });
    }

    public function curriculumYear(AuthContext $actor,int $institute,int $localCurriculum,
        int $globalYear): int
    {
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'curriculum.manage',function () use (
            $actor,$institute,$localCurriculum,$globalYear,$tx
        ): int {
            $r=$this->row("SELECT l.global_curriculum_id,y.year_no,y.id AS global_year_id
                FROM curriculum_versions l
                JOIN global_curriculum_versions g ON g.id=l.global_curriculum_id AND g.status='published'
                JOIN global_curriculum_years y ON y.global_curriculum_id=g.id AND y.id=?
                WHERE l.id=? AND l.institute_id=? FOR UPDATE",
                [$globalYear,$localCurriculum,$institute]);
            if (!$r) throw new HttpException('CURRICULUM_YEAR_NOT_FOUND','Global CTS course year not linked to local curriculum.',409);
            if ($this->row('SELECT id FROM curriculum_years
                WHERE curriculum_id=? AND year_no=? AND institute_id=? FOR UPDATE',
                [$localCurriculum,$r['year_no'],$institute])) {
                throw new HttpException('CURRICULUM_YEAR_EXISTS','Curriculum year already mapped.',409);
            }
            $q=$this->db->prepare('INSERT INTO curriculum_years
                (institute_id,curriculum_id,global_curriculum_id,global_curriculum_year_id,year_no)
                VALUES (?,?,?,?,?)');
            $q->execute([$institute,$localCurriculum,$r['global_curriculum_id'],$globalYear,$r['year_no']]);
            $id=(int)$this->db->lastInsertId();
            // A course-year link must carry the exact official global LOs.
            // Otherwise the formative completeness gate has no local LO rows.
            $globalOutcomes=$this->db->prepare('SELECT id,lo_code
                FROM global_learning_outcomes WHERE global_curriculum_year_id=?
                ORDER BY id FOR UPDATE');
            $globalOutcomes->execute([$globalYear]);
            $outcomes=$globalOutcomes->fetchAll(PDO::FETCH_ASSOC);
            if (!$outcomes) {
                throw new HttpException('CURRICULUM_OUTCOMES_MISSING',
                    'Published CTS course year must contain official learning outcomes.',409);
            }
            $localOutcome=$this->db->prepare('INSERT INTO learning_outcomes
                (institute_id,curriculum_year_id,global_curriculum_year_id,
                 global_learning_outcome_id,lo_code)
                VALUES (?,?,?,?,?)');
            foreach ($outcomes as $outcome) {
                $localOutcome->execute([$institute,$id,$globalYear,
                    $outcome['id'],$outcome['lo_code']]);
            }
            $tx->audit($actor,$institute,'curriculum.year.link','curriculum_years',$id,
                ['global_curriculum_year_id'=>$globalYear,
                 'learning_outcomes_linked'=>count($outcomes)]);
            return $id;
        });
    }

    public function admissionRule(AuthContext $actor,int $institute,int $curriculum,
        int $globalRule): int
    {
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'batch.manage',function () use (
            $actor,$institute,$curriculum,$globalRule,$tx
        ): int {
            $rule=$this->row("SELECT l.trade_id,l.global_curriculum_id
                FROM curriculum_versions l
                JOIN global_admission_rule_versions r
                  ON r.global_curriculum_id=l.global_curriculum_id AND r.trade_id=l.trade_id
                  AND r.id=? AND r.status='published'
                JOIN global_regulatory_documents d ON d.id=r.source_document_id AND d.status='published'
                JOIN global_regulatory_documents w ON w.id=r.admission_window_document_id AND w.status='published'
                WHERE l.id=? AND l.institute_id=? FOR UPDATE",[$globalRule,$curriculum,$institute]);
            if (!$rule) throw new HttpException('ADMISSION_RULE_NOT_PUBLISHED',
                'Published Global admission rule and source-backed institute curriculum required.',409);
            if ($this->row('SELECT id FROM admission_rules
                WHERE global_admission_rule_id=? AND institute_id=? FOR UPDATE',
                [$globalRule,$institute])) {
                throw new HttpException('ADMISSION_RULE_EXISTS','Admission rule already mapped.',409);
            }
            $q=$this->db->prepare('INSERT INTO admission_rules
                (institute_id,trade_id,curriculum_id,global_curriculum_id,global_admission_rule_id)
                VALUES (?,?,?,?,?)');
            $q->execute([$institute,$rule['trade_id'],$curriculum,
                $rule['global_curriculum_id'],$globalRule]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'admission.rule.link','admission_rules',$id,
                ['global_rule_id'=>$globalRule,'curriculum_id'=>$curriculum]);
            return $id;
        });
    }

    public function batch(AuthContext $actor,int $institute,int $entry,int $curriculum,
        int $rule,string $code,string $starts,string $ends): int
    {
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'batch.manage',function () use (
                $actor,$institute,$entry,$curriculum,$rule,$code,$starts,$ends,$tx
            ): int {
                $matrix=$this->row("SELECT e.id,e.intake_id,e.trade_unit_shift_id,
                    e.seat_capacity,e.admission_allowed,e.instructor_mapping_satisfied,
                    i.admission_year,i.calendar_id,c.training_starts_on,c.training_ends_on,
                    c.status AS calendar_status,v.status AS matrix_status
                    FROM seat_matrix_entries e
                    JOIN seat_matrix_versions v ON v.id=e.version_id
                        AND v.institute_id=e.institute_id
                    JOIN admission_intakes i ON i.id=e.intake_id
                        AND i.institute_id=e.institute_id
                    JOIN cts_training_calendars c ON c.id=i.calendar_id
                    WHERE e.id=? AND e.institute_id=? FOR UPDATE",[$entry,$institute]);
                if (!$matrix || $matrix['matrix_status']!=='final'
                    || $matrix['calendar_status']!=='published'
                    || !(int)$matrix['admission_allowed']
                    || !(int)$matrix['instructor_mapping_satisfied']) {
                    throw new HttpException('FINAL_MATRIX_REQUIRED','Final allowed seat-matrix entry and published CTS calendar required.',409);
                }
                if ($starts<$matrix['training_starts_on'] || $ends>$matrix['training_ends_on']) {
                    throw new HttpException('BATCH_DATES','Batch dates must fit the published CTS calendar.',422);
                }
                $itsu=$this->row("SELECT trade_id,training_mode,affiliation_status,status,
                    valid_from,valid_to,source_id,sanctioned_capacity,approval_reference
                    FROM trade_unit_shifts WHERE id=? AND institute_id=? FOR UPDATE",
                    [$matrix['trade_unit_shift_id'],$institute]);
                if (!$itsu || $itsu['status']!=='active' || $itsu['affiliation_status']!=='approved'
                    || $itsu['valid_from']>$starts
                    || ($itsu['valid_to']!==null && $itsu['valid_to']<$starts)
                    || (int)$matrix['seat_capacity']>(int)$itsu['sanctioned_capacity']) {
                    throw new HttpException('ITSU_NOT_APPROVED','Effective approved ITSU required.',409);
                }
                $source=$this->row("SELECT l.id,l.trade_id,g.id AS global_curriculum_id
                    FROM curriculum_versions l JOIN global_curriculum_versions g
                      ON g.id=l.global_curriculum_id AND g.status='published'
                    JOIN global_regulatory_documents d ON d.id=g.source_document_id
                      AND d.status='published'
                    WHERE l.id=? AND l.institute_id=? FOR UPDATE",[$curriculum,$institute]);
                if (!$source || (int)$source['trade_id']!==(int)$itsu['trade_id']) {
                    throw new HttpException('CURRICULUM_MISMATCH','Published CTS Trade curriculum must match ITSU.',409);
                }
                $admissionRule=$this->row("SELECT a.id,r.status
                    FROM admission_rules a JOIN global_admission_rule_versions r
                      ON r.id=a.global_admission_rule_id
                    WHERE a.id=? AND a.curriculum_id=? AND a.trade_id=?
                      AND a.institute_id=? FOR UPDATE",
                    [$rule,$curriculum,$itsu['trade_id'],$institute]);
                if (!$admissionRule || $admissionRule['status']!=='published') {
                    throw new HttpException('ADMISSION_RULE_MISMATCH','Current published admission rule required.',409);
                }
                if ($this->row('SELECT id FROM batches
                    WHERE seat_matrix_entry_id=? AND institute_id=? AND status<>\'cancelled\' FOR UPDATE',
                    [$entry,$institute])) {
                    throw new HttpException('BATCH_EXISTS','Batch already mapped to matrix entry.',409);
                }
                $snapshot=json_encode([
                    'itsu_revision_id'=>$matrix['trade_unit_shift_id'],
                    'source_id'=>$itsu['source_id'],'approval_reference'=>$itsu['approval_reference'],
                    'sanctioned_capacity'=>$itsu['sanctioned_capacity']
                ],JSON_THROW_ON_ERROR);
                $q=$this->db->prepare('INSERT INTO batches
                    (institute_id,batch_code,trade_unit_shift_id,trade_id,curriculum_id,
                     seat_matrix_entry_id,admission_rule_id,admission_year,intake_id,
                     calendar_id,starts_on,ends_on,training_mode,itsu_approval_snapshot_json,
                     capacity,status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'planned\')');
                $q->execute([$institute,$code,$matrix['trade_unit_shift_id'],
                    $itsu['trade_id'],$curriculum,$entry,$rule,
                    $matrix['admission_year'],$matrix['intake_id'],
                    $matrix['calendar_id'],$starts,$ends,$itsu['training_mode'],
                    $snapshot,$matrix['seat_capacity']]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'batch.create','batches',$id,[
                    'matrix_entry_id'=>$entry,'capacity'=>(int)$matrix['seat_capacity'],
                    'curriculum_id'=>$curriculum,'admission_rule_id'=>$rule]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('BATCH_EXISTS','Batch identity already exists.',409);
            }
            throw $e;
        }
    }

    public function batchYear(AuthContext $actor,int $institute,int $batch,
        int $curriculumYear,string $starts,string $ends,string $basis,int $basisSource): int
    {
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'batch.manage',function () use (
            $actor,$institute,$batch,$curriculumYear,$starts,$ends,$basis,$basisSource,$tx
        ): int {
            $b=$this->row('SELECT id,curriculum_id,calendar_id,starts_on,ends_on,status
                FROM batches WHERE id=? AND institute_id=? FOR UPDATE',[$batch,$institute]);
            if (!$b || !in_array($b['status'],['planned','active'],true)) {
                throw new HttpException('NOT_FOUND','Active/planned institute batch required.',404);
            }
            if ($starts<$b['starts_on'] || $ends>$b['ends_on']) {
                throw new HttpException('BATCH_YEAR_DATES','Course-year dates must fit its CTS batch.',422);
            }
            $year=$this->row('SELECT id FROM curriculum_years
                WHERE id=? AND curriculum_id=? AND institute_id=? FOR UPDATE',
                [$curriculumYear,$b['curriculum_id'],$institute]);
            if (!$year) throw new HttpException('CURRICULUM_YEAR_NOT_FOUND','Matching CTS course year required.',409);
            $source=$this->row('SELECT s.id,d.status FROM regulatory_sources s
                JOIN global_regulatory_documents d ON d.id=s.global_document_id
                WHERE s.id=? AND s.institute_id=? FOR UPDATE',[$basisSource,$institute]);
            if (!$source || $source['status']!=='published') {
                throw new HttpException('ATTENDANCE_BASIS_SOURCE','Published attendance basis source required.',409);
            }
            if ($this->row('SELECT id FROM batch_years
                WHERE batch_id=? AND curriculum_year_id=? AND institute_id=? FOR UPDATE',
                [$batch,$curriculumYear,$institute])) {
                throw new HttpException('BATCH_YEAR_EXISTS','CTS course year already configured.',409);
            }
            $q=$this->db->prepare('INSERT INTO batch_years
                (institute_id,batch_id,curriculum_id,curriculum_year_id,calendar_id,
                 starts_on,ends_on,attendance_basis,attendance_basis_source_id)
                VALUES (?,?,?,?,?,?,?,?,?)');
            $q->execute([$institute,$batch,$b['curriculum_id'],$curriculumYear,$b['calendar_id'],
                $starts,$ends,$basis,$basisSource]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'batch.course_year.create','batch_years',$id,[
                'batch_id'=>$batch,'curriculum_year_id'=>$curriculumYear,
                'attendance_basis'=>$basis,'source_id'=>$basisSource
            ]);
            return $id;
        });
    }
}
