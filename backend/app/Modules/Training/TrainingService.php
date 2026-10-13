<?php
declare(strict_types=1);
namespace App\Modules\Training;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

/** Internal instructional records; no invented government marks or thresholds. */
final readonly class TrainingService
{
    public function __construct(private PDO $db) {}
    private function row(string $sql,array $args): ?array
    {
        $q=$this->db->prepare($sql); $q->execute($args);
        return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private function institute(AuthContext $actor): int
    {
        return $actor->instituteId??throw new HttpException('FORBIDDEN','Institute identity required.',403);
    }
    private function assigned(int $institute,int $year,int $staff,string $date): void
    {
        $mapping=$this->row("SELECT m.id FROM subject_batch_assignments a
            JOIN instructor_mappings m ON m.id=a.mapping_id AND m.institute_id=a.institute_id
            JOIN staff s ON s.id=m.staff_id AND s.institute_id=m.institute_id
            WHERE a.batch_year_id=? AND a.institute_id=? AND m.staff_id=? AND m.status='active'
              AND m.valid_from<=? AND (m.valid_to IS NULL OR m.valid_to>=?)
              AND s.joined_on<=? AND (s.left_on IS NULL OR s.left_on>=?) FOR UPDATE",
            [$year,$institute,$staff,$date,$date,$date,$date]);
        if (!$mapping) throw new HttpException('INSTRUCTOR_NOT_ASSIGNED','Active course-year instructor assignment required.',403);
    }
    private function input(array $data): void
    {
        foreach (['batch_year_id','learning_outcome_id','instructor_id','planned_minutes'] as $key) {
            if (!isset($data[$key]) || !is_int($data[$key]) || $data[$key]<1) throw new HttpException('INVALID_INPUT','Positive integer '.$key.' required.',422);
        }
        if (!isset($data['start_minute']) || !is_int($data['start_minute']) || $data['start_minute']<0
            || $data['start_minute']+$data['planned_minutes']>1440) throw new HttpException('INVALID_INPUT','Valid daily time slot required.',422);
        foreach (['title'=>190,'instructions'=>16000] as $key=>$limit) {
            if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key])==='' || strlen($data[$key])>$limit) throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
        }
        if (!in_array($data['activity_type']??null,['lesson','demonstration','practical'],true)
            || !in_array($data['component']??null,['trade_practical','trade_theory','employability','ojt_project'],true)) throw new HttpException('INVALID_INPUT','Invalid activity/component.',422);
        $date=$data['session_date']??null;
        $parsed=is_string($date)?\DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
        if (!$parsed || $parsed->format('Y-m-d')!==$date) throw new HttpException('INVALID_INPUT','Real session date required.',422);
    }
    public function plan(AuthContext $actor,array $data,?int $id=null): int
    {
        $institute=$this->institute($actor);
        $permission=in_array('training.plan.manage',$actor->permissions,true)?'training.plan.manage':'training.record.assigned';
        $actor->requirePermission($permission);
        $this->input($data);
        if ($permission==='training.record.assigned' && $actor->staffId!==$data['instructor_id']) throw new HttpException('FORBIDDEN','Instructor may plan only their own assigned training.',403);
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,$permission,function () use ($actor,$data,$id,$institute,$tx): int {
            $before=null;
            if ($id!==null) {
                $before=$this->row('SELECT * FROM lesson_plans WHERE id=? AND institute_id=? FOR UPDATE',[$id,$institute]);
                if (!$before) throw new HttpException('NOT_FOUND','Lesson plan not found.',404);
                if ($before['status']!=='draft') throw new HttpException('PLAN_LOCKED','Approved plan cannot be overwritten.',409);
                if ((int)$before['created_by']!==$actor->userId && !in_array('training.plan.manage',$actor->permissions,true)) throw new HttpException('FORBIDDEN','Draft belongs to another author.',403);
            }
            $year=$this->row('SELECT * FROM batch_years WHERE id=? AND institute_id=? FOR UPDATE',[$data['batch_year_id'],$institute]);
            if (!$year) throw new HttpException('NOT_FOUND','Course year not found.',404);
            $date=$data['session_date'];
            if ($year['calendar_status']!=='reviewed' || $date<$year['starts_on'] || $date>$year['ends_on'] || $date>$year['calendar_reviewed_through']) throw new HttpException('CALENDAR_NOT_REVIEWED','Reviewed course-year calendar coverage required.',409);
            $calendar=$this->row('SELECT scheduled_minutes FROM training_calendar_entries WHERE batch_year_id=? AND institute_id=? AND session_date=? AND component=? FOR UPDATE',[$year['id'],$institute,$date,$data['component']]);
            $lo=$this->row('SELECT id FROM learning_outcomes WHERE id=? AND institute_id=? AND curriculum_year_id=? FOR UPDATE',[$data['learning_outcome_id'],$institute,$year['curriculum_year_id']]);
            if (!$calendar || !$lo) throw new HttpException('CURRICULUM_MISMATCH','Matching calendar entry and curriculum learning outcome required.',409);
            $this->assigned($institute,(int)$year['id'],$data['instructor_id'],$date);
            $used=$this->row('SELECT COALESCE(SUM(planned_minutes),0) AS minutes FROM lesson_plans WHERE institute_id=? AND batch_year_id=? AND session_date=? AND component=? AND id<>?',[$institute,$year['id'],$date,$data['component'],$id??0]);
            if ((int)$used['minutes']+$data['planned_minutes']>(int)$calendar['scheduled_minutes']) throw new HttpException('PLAN_CAPACITY','Lesson minutes exceed the reviewed daily component allocation.',409);
            $overlap=$this->row('SELECT id FROM lesson_plans WHERE institute_id=? AND session_date=? AND id<>? AND (instructor_id=? OR batch_id=?) AND start_minute<? AND start_minute+planned_minutes>? LIMIT 1 FOR UPDATE',[$institute,$date,$id??0,$data['instructor_id'],$year['batch_id'],$data['start_minute']+$data['planned_minutes'],$data['start_minute']]);
            if ($overlap) throw new HttpException('TIMETABLE_CONFLICT','Instructor or batch already has training in this time slot.',409);
            $values=[$year['id'],$year['batch_id'],$year['curriculum_year_id'],$data['learning_outcome_id'],$data['instructor_id'],$date,$data['component'],$data['activity_type'],$data['title'],$data['instructions'],$data['start_minute'],$data['planned_minutes']];
            if ($id===null) {
                $this->db->prepare('INSERT INTO lesson_plans(batch_year_id,batch_id,curriculum_year_id,learning_outcome_id,instructor_id,session_date,component,activity_type,title,instructions,start_minute,planned_minutes,institute_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([...$values,$institute,$actor->userId]);
                $id=(int)$this->db->lastInsertId();
            } else {
                $this->db->prepare('UPDATE lesson_plans SET batch_year_id=?,batch_id=?,curriculum_year_id=?,learning_outcome_id=?,instructor_id=?,session_date=?,component=?,activity_type=?,title=?,instructions=?,start_minute=?,planned_minutes=? WHERE id=? AND institute_id=?')->execute([...$values,$id,$institute]);
            }
            $tx->audit($actor,$institute,'training.plan.draft','lesson_plans',$id,['before'=>$before,'after'=>$data]);
            return $id;
        });
    }
    public function deleteDraft(AuthContext $actor,int $id,string $reason): void
    {
        $institute=$this->institute($actor);
        $permission=in_array('training.plan.manage',$actor->permissions,true)?'training.plan.manage':'training.record.assigned';
        $actor->requirePermission($permission);
        if ($id<1 || trim($reason)==='' || strlen($reason)>1000) throw new HttpException('INVALID_INPUT','Draft ID and deletion reason required.',422);
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,$permission,function () use ($actor,$id,$reason,$institute,$permission,$tx): void {
            $plan=$this->row('SELECT * FROM lesson_plans WHERE id=? AND institute_id=? FOR UPDATE',[$id,$institute]);
            if (!$plan) throw new HttpException('NOT_FOUND','Plan not found.',404);
            if ($plan['status']!=='draft') throw new HttpException('PLAN_LOCKED','Approved plans must be preserved.',409);
            if ($permission==='training.record.assigned' && (int)$plan['created_by']!==$actor->userId) throw new HttpException('FORBIDDEN','Draft belongs to another author.',403);
            $tx->audit($actor,$institute,'training.plan.delete','lesson_plans',$id,['draft'=>$plan,'reason'=>$reason]);
            $this->db->prepare('DELETE FROM lesson_plans WHERE id=? AND institute_id=?')->execute([$id,$institute]);
        });
    }
    public function approve(AuthContext $actor,int $id,string $reference): void
    {
        $actor->requirePermission('training.plan.manage');
        if (!in_array('principal',$actor->roles,true)) throw new HttpException('PRINCIPAL_REQUIRED','Principal approval required.',403);
        if (trim($reference)==='' || strlen($reference)>190) throw new HttpException('INVALID_INPUT','Review reference required.',422);
        $institute=$this->institute($actor); $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'training.plan.manage',function () use ($actor,$id,$reference,$institute,$tx): void {
            $plan=$this->row('SELECT * FROM lesson_plans WHERE id=? AND institute_id=? FOR UPDATE',[$id,$institute]);
            if (!$plan) throw new HttpException('NOT_FOUND','Lesson plan not found.',404);
            if ($plan['status']!=='draft') throw new HttpException('PLAN_LOCKED','Plan already approved.',409);
            if ((int)$plan['created_by']===$actor->userId || (int)$plan['instructor_id']===$actor->staffId) throw new HttpException('INDEPENDENT_REVIEW','Plan author/instructor cannot approve their own plan.',403);
            // Every draft creation/edit is audited in the same transaction. The
            // latest editor is authoritative, not only the original author.
            $lastEdit=$this->row("SELECT actor_user_id FROM institute_activity_log
                WHERE institute_id=? AND action_code='training.plan.draft'
                  AND entity_table='lesson_plans' AND entity_id=?
                ORDER BY id DESC LIMIT 1 FOR UPDATE",[$institute,$id]);
            if (!$lastEdit || (int)$lastEdit['actor_user_id']===$actor->userId) throw new HttpException('INDEPENDENT_REVIEW','An independently authored latest draft is required for approval.',403);
            $this->assigned($institute,(int)$plan['batch_year_id'],(int)$plan['instructor_id'],$plan['session_date']);
            $this->db->prepare("UPDATE lesson_plans SET status='approved',approved_by=?,approved_at=UTC_TIMESTAMP(),review_reference=? WHERE id=? AND institute_id=?")->execute([$actor->userId,$reference,$id,$institute]);
            $tx->audit($actor,$institute,'training.plan.approve','lesson_plans',$id,['reference'=>$reference]);
        });
    }
    /**
     * Void the effective/latest progress entry without erasing its record or
     * its correction chain. The immutable activity log is the void register.
     */
    public function voidProgress(AuthContext $actor,int $progressId,string $reference,string $reason): void
    {
        $actor->requirePermission('training.correct');
        if (!in_array('principal',$actor->roles,true)) throw new HttpException('PRINCIPAL_REQUIRED','Principal void authorization required.',403);
        if ($progressId<1 || trim($reference)==='' || strlen($reference)>190 || trim($reason)==='' || strlen($reason)>1000) {
            throw new HttpException('INVALID_INPUT','Progress ID, evidence reference and correction reason required.',422);
        }
        $institute=$this->institute($actor); $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'training.correct',function () use ($actor,$institute,$progressId,$reference,$reason,$tx): void {
            $progress=$this->row('SELECT * FROM training_progress WHERE id=? AND institute_id=? FOR UPDATE',[$progressId,$institute]);
            if (!$progress) throw new HttpException('NOT_FOUND','Progress record not found.',404);
            if ((int)$progress['recorded_by']===$actor->userId) throw new HttpException('INDEPENDENT_REVIEW','Original progress recorder cannot void their own record.',403);
            if ($this->row('SELECT id FROM training_progress WHERE supersedes_id=? AND institute_id=? FOR UPDATE',[$progressId,$institute])) {
                throw new HttpException('STALE_CORRECTION','Only the latest effective progress may be voided.',409);
            }
            if ($this->row("SELECT id FROM institute_activity_log WHERE institute_id=? AND action_code='training.progress.void' AND entity_table='training_progress' AND entity_id=? FOR UPDATE",[$institute,$progressId])) {
                throw new HttpException('PROGRESS_VOIDED','Progress is already voided.',409);
            }
            $tx->audit($actor,$institute,'training.progress.void','training_progress',$progressId,[
                'reference'=>$reference,'reason'=>$reason,'previous_minutes'=>(int)$progress['attended_minutes'],
                'effective_minutes'=>0,'plan_id'=>(int)$progress['plan_id'],
                'enrollment_id'=>(int)$progress['enrollment_id'],
            ]);
        });
    }

    public function progress(AuthContext $actor,array $data): int
    {
        $parent=$data['supersedes_id']??null;
        $permission=$parent===null?'training.record.assigned':'training.correct';
        $actor->requirePermission($permission); $institute=$this->institute($actor);
        foreach (['plan_id','enrollment_id','attended_minutes'] as $key) if (!isset($data[$key]) || !is_int($data[$key]) || $data[$key]<1) throw new HttpException('INVALID_INPUT','Positive '.$key.' required.',422);
        if ($parent!==null && (!is_int($parent) || $parent<1 || !in_array('principal',$actor->roles,true))) throw new HttpException('PRINCIPAL_REQUIRED','Principal correction required.',403);
        if (!in_array($data['progress_status']??null,['started','practised','completed'],true)) throw new HttpException('INVALID_INPUT','Valid progress status required.',422);
        foreach (['evidence_reference'=>190,'notes'=>16000] as $key=>$limit) if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key])==='' || strlen($data[$key])>$limit) throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
        $reason=$data['correction_reason']??null;
        if ($parent!==null && (!is_string($reason) || trim($reason)==='' || strlen($reason)>1000)) throw new HttpException('INVALID_INPUT','Correction reason required.',422);
        $date=$data['recorded_on']??null; $parsed=is_string($date)?\DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
        if (!$parsed || $parsed->format('Y-m-d')!==$date || $date>(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d')) throw new HttpException('INVALID_INPUT','Real nonfuture recording date required.',422);
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,$permission,function () use ($actor,$data,$date,$parent,$reason,$institute,$tx): int {
            $plan=$this->row('SELECT p.*,y.ends_on FROM lesson_plans p JOIN batch_years y ON y.id=p.batch_year_id AND y.institute_id=p.institute_id WHERE p.id=? AND p.institute_id=? FOR UPDATE',[$data['plan_id'],$institute]);
            if (!$plan) throw new HttpException('NOT_FOUND','Lesson plan not found.',404);
            if ($plan['status']!=='approved' || $date<$plan['session_date'] || $date>$plan['ends_on']) throw new HttpException('PLAN_STATE','Approved plan and applicable recording date required.',409);
            if ($parent===null) {
                if ((int)$plan['instructor_id']!==$actor->staffId) throw new HttpException('FORBIDDEN','Only assigned instructor may record progress.',403);
                $this->assigned($institute,(int)$plan['batch_year_id'],(int)$actor->staffId,$date);
            } else {
                $original=$this->row('SELECT * FROM training_progress WHERE id=? AND plan_id=? AND enrollment_id=? AND institute_id=? FOR UPDATE',[$parent,$plan['id'],$data['enrollment_id'],$institute]);
                if (!$original) throw new HttpException('NOT_FOUND','Original progress not found.',404);
                if ((int)$original['recorded_by']===$actor->userId) throw new HttpException('INDEPENDENT_REVIEW','Original recorder cannot approve their correction.',403);
                if ($this->row('SELECT id FROM training_progress WHERE supersedes_id=? FOR UPDATE',[$parent])) throw new HttpException('STALE_CORRECTION','Record already superseded.',409);
                if ($this->row("SELECT id FROM institute_activity_log WHERE institute_id=? AND action_code='training.progress.void' AND entity_table='training_progress' AND entity_id=? FOR UPDATE",[$institute,$parent])) throw new HttpException('PROGRESS_VOIDED','Cannot correct a voided progress record.',409);
            }
            $enrollment=$this->row('SELECT status,admitted_on FROM enrollments WHERE id=? AND batch_id=? AND institute_id=? FOR UPDATE',[$data['enrollment_id'],$plan['batch_id'],$institute]);
            if (!$enrollment || $enrollment['admitted_on']>$date || ($parent===null && $enrollment['status']!=='active')) throw new HttpException('ENROLLMENT_NOT_ACTIVE','Applicable enrollment required.',409);
            $sum=$this->row('SELECT COALESCE(SUM(p.attended_minutes),0) AS minutes FROM training_progress p WHERE p.plan_id=? AND p.enrollment_id=? AND p.institute_id=? AND p.id<>? AND NOT EXISTS (SELECT 1 FROM training_progress c WHERE c.supersedes_id=p.id) AND NOT EXISTS (SELECT 1 FROM institute_activity_log v WHERE v.institute_id=p.institute_id AND v.action_code='training.progress.void' AND v.entity_table='training_progress' AND v.entity_id=p.id)',[$plan['id'],$data['enrollment_id'],$institute,$parent??0]);
            if ((int)$sum['minutes']+$data['attended_minutes']>(int)$plan['planned_minutes']) throw new HttpException('PROGRESS_LIMIT','Recorded minutes exceed planned training.',409);
            if ($this->row('SELECT id FROM training_progress WHERE plan_id=? AND enrollment_id=? AND evidence_reference=? AND institute_id=?',[$plan['id'],$data['enrollment_id'],$data['evidence_reference'],$institute])) throw new HttpException('PROGRESS_EXISTS','Evidence reference already recorded.',409);
            $this->db->prepare('INSERT INTO training_progress(institute_id,plan_id,batch_year_id,batch_id,enrollment_id,progress_status,attended_minutes,recorded_on,evidence_reference,notes,recorded_by,supersedes_id,correction_reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$institute,$plan['id'],$plan['batch_year_id'],$plan['batch_id'],$data['enrollment_id'],$data['progress_status'],$data['attended_minutes'],$date,$data['evidence_reference'],$data['notes'],$actor->userId,$parent,$reason]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'training.progress.record','training_progress',$id,['supersedes_id'=>$parent,'reason'=>$reason,'plan_id'=>$plan['id'],'enrollment_id'=>$data['enrollment_id']]);
            return $id;
        });
    }
}
