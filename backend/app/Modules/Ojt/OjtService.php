<?php
declare(strict_types=1);

namespace App\Modules\Ojt;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Source-backed CTS OJT/group project, learner logbook and instructor evaluation. */
final readonly class OjtService
{
    public function __construct(private PDO $db) {}

    private function row(string $sql,array $params): ?array
    {
        $q=$this->db->prepare($sql);
        $q->execute($params);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function principal(AuthContext $actor): void
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_REQUIRED','Principal approval is required.',403);
        }
    }

    private function project(int $institute,int $id): array
    {
        return $this->row('SELECT p.*,e.student_id,e.status AS enrollment_status,
            b.trade_unit_shift_id,y.curriculum_year_id
            FROM ojt_projects p
            JOIN enrollments e ON e.id=p.enrollment_id AND e.institute_id=p.institute_id
            JOIN batches b ON b.id=p.batch_id AND b.institute_id=p.institute_id
            JOIN batch_years y ON y.id=p.batch_year_id AND y.institute_id=p.institute_id
            WHERE p.id=? AND p.institute_id=? FOR UPDATE',[$id,$institute])
            ?? throw new HttpException('NOT_FOUND','OJT/project record not found.',404);
    }

    private function assigned(int $institute,int $staff,int $itsu,string $on): void
    {
        $q=$this->db->prepare("SELECT m.id FROM instructor_mappings m
            JOIN staff s ON s.id=m.staff_id AND s.institute_id=m.institute_id
            WHERE m.staff_id=? AND m.institute_id=? AND m.trade_unit_shift_id=?
              AND m.mapping_type='trade' AND m.status='active'
              AND m.valid_from<=? AND (m.valid_to IS NULL OR m.valid_to>=?)
              AND s.staff_category='teaching' AND s.nims_verification='verified'
              AND s.left_on IS NULL LIMIT 1 FOR UPDATE");
        $q->execute([$staff,$institute,$itsu,$on,$on]);
        if ($q->fetchColumn()===false) {
            throw new HttpException('OJT_INSTRUCTOR_NOT_ASSIGNED',
                'Active NIMS-verified matching Trade instructor required.',403);
        }
    }

    public function plan(AuthContext $actor,int $institute,int $enrollment,int $year,
        string $mode,string $title,?string $industry,?string $registration,
        ?string $address,?string $mentor,int $coordinator,string $starts,
        string $ends,int $minutes,string $safety,?string $femaleSafety): int
    {
        self::principal($actor);
        try {
            $tx=new InstituteTransaction($this->db);
            return $tx->run($actor,$institute,'calendar.manage',function () use (
                $actor,$tx,$institute,$enrollment,$year,$mode,$title,$industry,
                $registration,$address,$mentor,$coordinator,$starts,$ends,$minutes,
                $safety,$femaleSafety
            ): int {
                $e=$this->row('SELECT id,batch_id,status,admitted_on
                    FROM enrollments WHERE id=? AND institute_id=? FOR UPDATE',
                    [$enrollment,$institute]);
                if (!$e || $e['status']!=='active' || $e['admitted_on']>$starts) {
                    throw new HttpException('ENROLLMENT_NOT_ACTIVE',
                        'Active admitted trainee required at OJT start.',409);
                }
                $y=$this->row('SELECT y.id,y.batch_id,y.starts_on,y.ends_on,
                    g.ojt_project_minutes,b.trade_unit_shift_id
                    FROM batch_years y
                    JOIN curriculum_years cy ON cy.id=y.curriculum_year_id
                      AND cy.institute_id=y.institute_id
                    JOIN global_curriculum_years g ON g.id=cy.global_curriculum_year_id
                    JOIN batches b ON b.id=y.batch_id AND b.institute_id=y.institute_id
                    WHERE y.id=? AND y.institute_id=? FOR UPDATE',[$year,$institute]);
                if (!$y || (int)$e['batch_id']!==(int)$y['batch_id']
                    || $starts<$y['starts_on'] || $ends>$y['ends_on']
                    || (int)$y['ojt_project_minutes']===0
                    || $minutes!==(int)$y['ojt_project_minutes']) {
                    throw new HttpException('OJT_SYLLABUS_MISMATCH',
                        'Matching trainee/course year and official syllabus OJT minutes required.',409);
                }
                $this->assigned($institute,$coordinator,(int)$y['trade_unit_shift_id'],$starts);
                if ($mode==='ojt' && ($industry===null || $mentor===null)) {
                    throw new HttpException('OJT_INDUSTRY_REQUIRED',
                        'Industry and mentor evidence required for OJT.',422);
                }
                $q=$this->db->prepare("INSERT INTO ojt_projects
                    (institute_id,enrollment_id,batch_id,batch_year_id,mode,title,
                     industry_name,industry_registration_reference,workplace_address,
                     industry_mentor,coordinator_id,starts_on,ends_on,required_minutes,
                     safety_reference,female_safety_reference,status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'planned')");
                $q->execute([$institute,$enrollment,$e['batch_id'],$year,$mode,$title,
                    $industry,$registration,$address,$mentor,$coordinator,
                    $starts,$ends,$minutes,$safety,$femaleSafety]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'ojt.project.plan','ojt_projects',$id,[
                    'enrollment_id'=>$enrollment,'batch_year_id'=>$year,
                    'mode'=>$mode,'syllabus_minutes'=>$minutes
                ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('OJT_PROJECT_EXISTS','OJT/project already registered for this trainee/course year.',409);
            }
            throw $e;
        }
    }

    public function start(AuthContext $actor,int $institute,int $projectId,
        string $review): void
    {
        self::principal($actor);
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'calendar.manage',function () use (
            $actor,$institute,$projectId,$review,$tx
        ): void {
            $p=$this->project($institute,$projectId);
            $today=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
            if ($p['status']!=='planned' || $p['enrollment_status']!=='active'
                || $p['starts_on']>$today || $p['ends_on']<$today) {
                throw new HttpException('OJT_NOT_READY',
                    'Planned project, active admission and applicable dates required.',409);
            }
            $this->assigned($institute,(int)$p['coordinator_id'],(int)$p['trade_unit_shift_id'],$today);
            $this->db->prepare("UPDATE ojt_projects SET status='ongoing'
                WHERE id=? AND institute_id=? AND status='planned'")
                ->execute([$projectId,$institute]);
            $tx->audit($actor,$institute,'ojt.project.start','ojt_projects',$projectId,
                ['review_reference'=>$review]);
        });
    }

    public function log(AuthContext $actor,int $institute,int $projectId,
        string $date,int $minutes,string $activity,string $mentorReference,
        ?string $safetyReference): int
    {
        if ($actor->studentId===null || !in_array('student',$actor->roles,true)) {
            throw new HttpException('STUDENT_REQUIRED','Linked student account required.',403);
        }
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'ojt.log.own',function () use (
                $actor,$institute,$projectId,$date,$minutes,$activity,$mentorReference,$safetyReference,$tx
            ): int {
                $p=$this->project($institute,$projectId);
                $today=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
                if ((int)$p['student_id']!==$actor->studentId) {
                    throw new HttpException('NOT_FOUND','Own OJT project not found.',404);
                }
                if ($p['status']!=='ongoing' || $p['enrollment_status']!=='active'
                    || $date<$p['starts_on'] || $date>$p['ends_on'] || $date>$today) {
                    throw new HttpException('OJT_LOG_NOT_READY',
                        'OJT entry must be for an active project on a completed applicable day.',409);
                }
                $q=$this->db->prepare('SELECT COALESCE(SUM(attended_minutes),0)
                    FROM ojt_logbook WHERE project_id=? AND institute_id=?');
                $q->execute([$projectId,$institute]);
                if ((int)$q->fetchColumn()+$minutes>(int)$p['required_minutes']) {
                    throw new HttpException('OJT_HOURS_EXCEEDED',
                        'Attended time cannot exceed this source-backed project requirement.',409);
                }
                $q=$this->db->prepare('INSERT INTO ojt_logbook
                    (institute_id,project_id,entry_date,attended_minutes,activity,
                     mentor_confirmation,monitoring_safety_reference)
                    VALUES (?,?,?,?,?,?,?)');
                $q->execute([$institute,$projectId,$date,$minutes,$activity,
                    $mentorReference,$safetyReference]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'ojt.logbook.record','ojt_logbook',$id,[
                    'project_id'=>$projectId,'entry_date'=>$date,'attended_minutes'=>$minutes
                ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('OJT_ENTRY_EXISTS','Entry already exists for this project/date.',409);
            }
            throw $e;
        }
    }

    public function list(AuthContext $actor,int $institute,int $after,int $size,bool $own): array
    {
        $actor->requireInstitute($institute);
        $actor->requirePermission($own?'ojt.view.own':'ojt.view');
        if ($own && $actor->studentId===null) {
            throw new HttpException('FORBIDDEN','Student identity required.',403);
        }
        $sql='SELECT p.id,p.enrollment_id,p.batch_year_id,p.mode,p.title,
            p.industry_name,p.coordinator_id,p.starts_on,p.ends_on,
            p.required_minutes,p.status,p.completion_certificate_reference
            FROM ojt_projects p';
        if ($own) {
            $sql.=' JOIN enrollments e ON e.id=p.enrollment_id
                AND e.institute_id=p.institute_id';
        }
        $sql.=' WHERE p.institute_id=? AND p.id>?';
        if ($own) $sql.=' AND e.student_id=?';
        $sql.=' ORDER BY p.id LIMIT ?';
        $q=$this->db->prepare($sql);
        $q->bindValue(1,$institute,PDO::PARAM_INT);
        $q->bindValue(2,$after,PDO::PARAM_INT);
        if ($own) $q->bindValue(3,$actor->studentId,PDO::PARAM_INT);
        $q->bindValue($own?4:3,$size,PDO::PARAM_INT);
        $q->execute();
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        return ['projects'=>$rows,
            'next_after_id'=>count($rows)===$size?(int)$rows[count($rows)-1]['id']:null];
    }

    public function evaluate(AuthContext $actor,int $institute,int $projectId,
        string $role,string $primaryName,string $primary,string $primaryMax,
        string $examiner,string $external,string $externalMax,string $grade,
        string $evaluatedOn,int $sourceId,string $signedEvidence,?string $sidhReference,
        ?string $completion): int
    {
        if ($actor->staffId===null || !in_array('instructor',$actor->roles,true)) {
            throw new HttpException('INSTRUCTOR_REQUIRED','Assigned instructor required.',403);
        }
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'ojt.evaluate.assigned',function () use (
                $actor,$tx,$institute,$projectId,$role,$primaryName,$primary,$primaryMax,
                $examiner,$external,$externalMax,$grade,$evaluatedOn,$sourceId,
                $signedEvidence,$sidhReference,$completion
            ): int {
                $p=$this->project($institute,$projectId);
                if ((int)$p['coordinator_id']!==$actor->staffId) {
                    throw new HttpException('FORBIDDEN','Only assigned OJT coordinator may submit evaluation.',403);
                }
                $today=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
                if ($p['status']!=='ongoing' || $evaluatedOn<$p['starts_on']
                    || $evaluatedOn>$today) {
                    throw new HttpException('OJT_EVALUATION_NOT_READY',
                        'Current OJT and actual evaluation date required.',409);
                }
                $this->assigned($institute,$actor->staffId,(int)$p['trade_unit_shift_id'],$evaluatedOn);
                $source=$this->row('SELECT d.status,d.sha256
                    FROM regulatory_sources s JOIN global_regulatory_documents d
                      ON d.id=s.global_document_id
                    WHERE s.id=? AND s.institute_id=? FOR UPDATE',[$sourceId,$institute]);
                if (!$source || $source['status']!=='published' || !$source['sha256']) {
                    throw new HttpException('OJT_EVALUATION_SOURCE',
                        'Reviewed, published official OJT assessment evidence required.',409);
                }
                $q=$this->db->prepare('SELECT COALESCE(SUM(attended_minutes),0)
                    FROM ojt_logbook WHERE project_id=? AND institute_id=?');
                $q->execute([$projectId,$institute]);
                if ((int)$q->fetchColumn()<(int)$p['required_minutes']) {
                    throw new HttpException('OJT_LOGBOOK_INCOMPLETE',
                        'Complete documented OJT/project minutes required.',409);
                }
                if ($p['mode']==='ojt' && $completion===null) {
                    throw new HttpException('OJT_COMPLETION_EVIDENCE',
                        'Industry completion certificate reference required.',409);
                }
                if ($this->row('SELECT id FROM ojt_evaluations WHERE project_id=? AND institute_id=? FOR UPDATE',
                    [$projectId,$institute])) {
                    throw new HttpException('OJT_ALREADY_EVALUATED','Existing assessment is immutable.',409);
                }
                $q=$this->db->prepare('INSERT INTO ojt_evaluations
                    (institute_id,project_id,primary_evaluator_role,primary_evaluator_name,
                     primary_marks,external_examiner_name,external_marks,
                     primary_max_marks,external_max_marks,final_grade,evaluated_on,
                     evaluation_source_id,signed_evidence_reference,sidh_submission_reference)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$institute,$projectId,$role,$primaryName,$primary,
                    $examiner,$external,$primaryMax,$externalMax,$grade,
                    $evaluatedOn,$sourceId,$signedEvidence,$sidhReference]);
                $id=(int)$this->db->lastInsertId();
                $this->db->prepare("UPDATE ojt_projects SET status='completed',
                    completion_certificate_reference=? WHERE id=? AND institute_id=? AND status='ongoing'")
                    ->execute([$completion,$projectId,$institute]);
                $tx->audit($actor,$institute,'ojt.evaluation.record',
                    'ojt_evaluations',$id,[
                        'project_id'=>$projectId,'evaluation_source_id'=>$sourceId,
                        'evidence_reference'=>$signedEvidence,'grade_recorded'=>$grade
                    ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('OJT_ALREADY_EVALUATED','OJT evaluation already exists.',409);
            }
            throw $e;
        }
    }
}
