<?php
declare(strict_types=1);

namespace App\Modules\Assessment;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** A mapped instructor records LO points; Principal freezes reviewed marks. */
final readonly class FormativeService
{
    public function __construct(private PDO $db) {}

    private function row(string $sql,array $params): ?array
    {
        $q=$this->db->prepare($sql);
        $q->execute($params);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function record(AuthContext $actor,int $institute,int $enrollment,
        int $batchYear,int $outcome,string $assessedOn,string $maximum,
        string $obtained,string $evidence): int
    {
        $staff=$actor->staffId;
        if ($staff===null || !in_array('instructor',$actor->roles,true)) {
            throw new HttpException('ASSIGNED_INSTRUCTOR_REQUIRED','Linked instructor identity required.',403);
        }
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'formative.record.assigned',function () use (
                $actor,$institute,$enrollment,$batchYear,$outcome,$assessedOn,
                $maximum,$obtained,$evidence,$staff,$tx
            ): int {
                $year=$this->row("SELECT y.id,y.batch_id,y.curriculum_year_id,
                    y.starts_on,y.ends_on,y.formative_method,y.formative_scheme_source_id,
                    b.trade_unit_shift_id
                    FROM batch_years y JOIN batches b
                      ON b.id=y.batch_id AND b.institute_id=y.institute_id
                    WHERE y.id=? AND y.institute_id=? FOR UPDATE",[$batchYear,$institute]);
                if (!$year) throw new HttpException('NOT_FOUND','Batch course year not found.',404);
                if ($year['formative_method']!=='points_ratio'
                    || $year['formative_scheme_source_id']===null) {
                    throw new HttpException('FORMATIVE_SCHEME_REQUIRED',
                        'Only an explicitly source-backed points-ratio formative scheme is currently supported.',409);
                }
                $source=$this->row('SELECT d.status FROM regulatory_sources s
                    JOIN global_regulatory_documents d ON d.id=s.global_document_id
                    WHERE s.id=? AND s.institute_id=? FOR UPDATE',
                    [$year['formative_scheme_source_id'],$institute]);
                if (!$source || $source['status']!=='published') {
                    throw new HttpException('FORMATIVE_SOURCE_NOT_PUBLISHED','Published formative scheme source required.',409);
                }
                if ($assessedOn<$year['starts_on'] || $assessedOn>$year['ends_on']) {
                    throw new HttpException('INVALID_ASSESSMENT_DATE','Assessment must fall inside course year.',422);
                }
                $enrolled=$this->row("SELECT id FROM enrollments WHERE id=?
                    AND institute_id=? AND batch_id=? AND status='active'
                    AND admitted_on<=? FOR UPDATE",
                    [$enrollment,$institute,$year['batch_id'],$assessedOn]);
                if (!$enrolled) {
                    throw new HttpException('ENROLLMENT_NOT_ACTIVE','Active enrollment in matching batch required.',409);
                }
                $lo=$this->row('SELECT id FROM learning_outcomes
                    WHERE id=? AND institute_id=? AND curriculum_year_id=? FOR UPDATE',
                    [$outcome,$institute,$year['curriculum_year_id']]);
                if (!$lo) {
                    throw new HttpException('LEARNING_OUTCOME_MISMATCH','Official course-year learning outcome required.',409);
                }
                $mapping=$this->row("SELECT m.id FROM instructor_mappings m
                    JOIN staff s ON s.id=m.staff_id AND s.institute_id=m.institute_id
                    WHERE m.staff_id=? AND m.institute_id=?
                      AND m.mapping_type='trade' AND m.trade_unit_shift_id=?
                      AND m.status='active' AND m.valid_from<=?
                      AND (m.valid_to IS NULL OR m.valid_to>=?)
                      AND s.nims_verification='verified' AND s.left_on IS NULL
                    LIMIT 1 FOR UPDATE",[$staff,$institute,$year['trade_unit_shift_id'],
                    $assessedOn,$assessedOn]);
                if (!$mapping) {
                    throw new HttpException('INSTRUCTOR_NOT_ASSIGNED','Current NIMS-verified Trade instructor mapping required.',403);
                }
                if ($this->row('SELECT id FROM formative_assessments
                    WHERE enrollment_id=? AND batch_year_id=? AND learning_outcome_id=?
                    AND institute_id=? FOR UPDATE',
                    [$enrollment,$batchYear,$outcome,$institute])) {
                    throw new HttpException('ASSESSMENT_EXISTS','Learning-outcome marks already recorded; correction requires separate approval.',409);
                }
                $q=$this->db->prepare('INSERT INTO formative_assessments
                    (institute_id,enrollment_id,batch_id,batch_year_id,curriculum_year_id,
                     learning_outcome_id,assessed_on,max_marks,obtained_marks,
                     evidence_reference,assessor_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$institute,$enrollment,$year['batch_id'],$batchYear,
                    $year['curriculum_year_id'],$outcome,$assessedOn,$maximum,$obtained,
                    $evidence,$staff]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'formative.outcome.record','formative_assessments',$id,[
                    'enrollment_id'=>$enrollment,'learning_outcome_id'=>$outcome,
                    'max_marks'=>$maximum,'obtained_marks'=>$obtained
                ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('ASSESSMENT_EXISTS','Formative outcome marks already exist.',409);
            }
            throw $e;
        }
    }

    public function lock(AuthContext $actor,int $institute,int $assessmentId,
        string $reviewReference): void
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_AUTHORITY_REQUIRED','Principal formative review required.',403);
        }
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'formative.template.manage',function () use (
            $actor,$institute,$assessmentId,$reviewReference,$tx
        ): void {
            $assessment=$this->row('SELECT id,locked_on FROM formative_assessments
                WHERE id=? AND institute_id=? FOR UPDATE',[$assessmentId,$institute]);
            if (!$assessment) throw new HttpException('NOT_FOUND','Formative assessment not found.',404);
            if ($assessment['locked_on']!==null) {
                throw new HttpException('ASSESSMENT_LOCKED','Formative assessment already frozen.',409);
            }
            $this->db->prepare('UPDATE formative_assessments SET locked_on=UTC_TIMESTAMP()
                WHERE id=? AND institute_id=? AND locked_on IS NULL')
                ->execute([$assessmentId,$institute]);
            $tx->audit($actor,$institute,'formative.outcome.lock','formative_assessments',
                $assessmentId,['review_reference'=>$reviewReference]);
        });
    }
}
