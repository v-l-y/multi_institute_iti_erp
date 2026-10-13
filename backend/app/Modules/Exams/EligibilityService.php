<?php
declare(strict_types=1);
namespace App\Modules\Exams;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

/** Freeze a locally calculated eligibility snapshot from reviewed source-backed records. */
final readonly class EligibilityService
{
    public function __construct(private PDO $db) {}

    private function rows(string $sql,array $args): array
    {
        $q=$this->db->prepare($sql);
        $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function one(string $sql,array $args): array
    {
        return $this->rows($sql,$args)[0] ?? throw new HttpException('ELIGIBILITY_REFERENCE','Required eligibility reference is missing.',409);
    }

    public function freeze(AuthContext $actor,int $institute,int $enrollment,int $year,int $cycle,string $evidence): int
    {
        if (trim($evidence)==='' || strlen($evidence)>190) {
            throw new HttpException('ELIGIBILITY_EVIDENCE','Eligibility evidence reference required.',422);
        }
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'exam.eligibility.manage',function () use ($actor,$institute,$enrollment,$year,$cycle,$evidence,$tx) {
            $existing=$this->rows('SELECT id FROM exam_eligibility WHERE enrollment_id=? AND batch_year_id=? AND cycle_id=? AND institute_id=? FOR UPDATE',[$enrollment,$year,$cycle,$institute]);
            if ($existing) {
                throw new HttpException('ELIGIBILITY_FROZEN','Eligibility already frozen; use an audited correction workflow.',409);
            }
            $e=$this->one('SELECT e.*,s.prn,s.prn_verified_on FROM enrollments e JOIN students s ON s.id=e.student_id AND s.institute_id=e.institute_id WHERE e.id=? AND e.institute_id=? FOR UPDATE',[$enrollment,$institute]);
            $y=$this->one('SELECT * FROM batch_years WHERE id=? AND institute_id=? FOR UPDATE',[$year,$institute]);
            $c=$this->one('SELECT * FROM exam_cycles WHERE id=? AND institute_id=? FOR UPDATE',[$cycle,$institute]);
            $r=$this->one('SELECT g.* FROM exam_rule_versions l JOIN global_exam_rule_versions g ON g.id=l.global_exam_rule_id WHERE l.id=? AND l.institute_id=? FOR UPDATE',[$c['rule_id'],$institute]);
            $cutoff=$c['eligibility_cutoff'];
            $attendanceThrough=min($cutoff,$y['ends_on']);
            $today=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
            if ($e['batch_id']!==$y['batch_id'] || !in_array($e['status'],['active','completed'],true) || $c['status']!=='eligibility_open' || $cutoff>$today || $attendanceThrough<$y['starts_on'] || $y['calendar_status']!=='reviewed' || $y['calendar_reviewed_through']===null || $y['calendar_reviewed_through']<$attendanceThrough || $r['status']!=='published' || $r['effective_from']>$c['exam_starts_on'] || ($r['effective_to']!==null && $r['effective_to']<$c['exam_starts_on'])) {
                throw new HttpException('ELIGIBILITY_NOT_READY','Applicable reviewed calendar, rule and enrollment required.',409);
            }
            $doc=$this->one('SELECT status FROM global_regulatory_documents WHERE id=? FOR UPDATE',[$r['source_document_id']]);
            if ($doc['status']!=='published') {
                throw new HttpException('EXAM_RULE_SOURCE','Exam rule source is not reviewed.',409);
            }
            $scheduled=0;
            $attended=0;
            $calendar=$this->rows('SELECT * FROM training_calendar_entries WHERE batch_year_id=? AND institute_id=? AND session_date BETWEEN ? AND ? ORDER BY session_date,component FOR UPDATE',[$year,$institute,$y['starts_on'],$attendanceThrough]);
            if (!$calendar) {
                throw new HttpException('ATTENDANCE_DENOMINATOR','Reviewed training calendar has no applicable entries.',409);
            }
            if ($y['attendance_basis']==='minutes') {
                foreach ($calendar as $entry) {
                    $s=$this->one("SELECT s.*,COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(c.after_json,'$.attended_minutes')) AS UNSIGNED),a.attended_minutes) AS attended_minutes FROM attendance_sessions s JOIN attendance_records a ON a.session_id=s.id AND a.institute_id=s.institute_id AND a.enrollment_id=? LEFT JOIN attendance_corrections c ON c.id=(SELECT MAX(cx.id) FROM attendance_corrections cx WHERE cx.session_id=a.session_id AND cx.enrollment_id=a.enrollment_id AND cx.institute_id=a.institute_id) WHERE s.batch_year_id=? AND s.institute_id=? AND s.session_date=? AND s.component=? FOR UPDATE",[$enrollment,$year,$institute,$entry['session_date'],$entry['component']]);
                    if ($s['locked_on']===null || (int)$s['scheduled_minutes']!==(int)$entry['scheduled_minutes'] || (int)$s['attended_minutes']>(int)$s['scheduled_minutes']) {
                        throw new HttpException('ATTENDANCE_NOT_RECONCILED','Attendance must match and be locked against the reviewed calendar.',409);
                    }
                    $scheduled+=(int)$s['scheduled_minutes'];
                    $attended+=(int)$s['attended_minutes'];
                }
            } else {
                if ($y['working_day_method']!=='certified_day_register') {
                    throw new HttpException('WORKING_DAYS_UNCONFIGURED','Certified working-day method required.',409);
                }
                foreach (array_unique(array_column($calendar,'session_date')) as $date) {
                    $day=$this->one('SELECT * FROM working_day_attendance WHERE enrollment_id=? AND batch_year_id=? AND institute_id=? AND session_date=? AND is_current=1 FOR UPDATE',[$enrollment,$year,$institute,$date]);
                    if ($day['locked_on']===null) {
                        throw new HttpException('WORKING_DAY_NOT_LOCKED','Certified day register must be locked.',409);
                    }
                    $scheduled++;
                    $attended+=(int)$day['counted_present'];
                }
            }
            $raw=round(100*$attended/$scheduled,2);
            $formative=0.0;
            if ($y['formative_method']==='points_ratio') {
                $items=$this->rows('SELECT id FROM learning_outcomes WHERE curriculum_year_id=? AND institute_id=? FOR UPDATE',[$y['curriculum_year_id'],$institute]);
                $official=$this->one('SELECT COUNT(*) total FROM global_learning_outcomes g JOIN curriculum_years l ON l.global_curriculum_year_id=g.global_curriculum_year_id WHERE l.id=? AND l.institute_id=?',[$y['curriculum_year_id'],$institute]);
                if (count($items)!==(int)$official['total']) {
                    throw new HttpException('FORMATIVE_INCOMPLETE','Local learning outcomes must cover the selected official curriculum year.',409);
                }
                $scores=$this->rows("SELECT a.*,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.after_json,'$.obtained_marks')),a.obtained_marks) AS obtained_marks FROM formative_assessments a LEFT JOIN formative_corrections c ON c.id=(SELECT MAX(cx.id) FROM formative_corrections cx WHERE cx.assessment_id=a.id AND cx.institute_id=a.institute_id) WHERE a.enrollment_id=? AND a.batch_year_id=? AND a.institute_id=? AND a.assessed_on<=? FOR UPDATE",[$enrollment,$year,$institute,$cutoff]);
                if (!$items || count($scores)!==count($items) || in_array(null,array_column($scores,'locked_on'),true)) {
                    throw new HttpException('FORMATIVE_INCOMPLETE','Complete locked learning-outcome scores required.',409);
                }
                $formative=round(100*array_sum(array_column($scores,'obtained_marks'))/array_sum(array_column($scores,'max_marks')),2);
            } elseif ($y['formative_method']==='template_weighted') {
                $items=$this->rows("SELECT i.*,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.after_json,'$.obtained_marks')),s.obtained_marks) AS obtained_marks,s.locked_on,s.assessed_on FROM formative_template_items i LEFT JOIN formative_template_scores s ON s.batch_year_id=i.batch_year_id AND s.item_code=i.item_code AND s.institute_id=i.institute_id AND s.enrollment_id=? LEFT JOIN formative_corrections c ON c.id=(SELECT MAX(cx.id) FROM formative_corrections cx WHERE cx.enrollment_id=s.enrollment_id AND cx.batch_year_id=s.batch_year_id AND cx.item_code=s.item_code AND cx.institute_id=s.institute_id) WHERE i.batch_year_id=? AND i.institute_id=? FOR UPDATE",[$enrollment,$year,$institute]);
                if (!$items || abs(array_sum(array_column($items,'weight_percent'))-100)>0.0001) {
                    throw new HttpException('FORMATIVE_WEIGHTS','Reviewed template weights must total 100.',409);
                }
                foreach ($items as $item) {
                    if ($item['obtained_marks']===null || $item['locked_on']===null || $item['assessed_on']>$cutoff || (float)$item['obtained_marks']>(float)$item['max_marks']) {
                        throw new HttpException('FORMATIVE_INCOMPLETE','Complete valid locked template scores required.',409);
                    }
                    $formative+=(float)$item['obtained_marks']/(float)$item['max_marks']*(float)$item['weight_percent'];
                }
                $formative=round($formative,2);
            } else {
                throw new HttpException('FORMATIVE_UNCONFIGURED','Official formative method must be configured.',409);
            }
            if (!$y['formative_scheme_source_id']) {
                throw new HttpException('FORMATIVE_SOURCE','Reviewed formative source required.',409);
            }
            foreach ([$y['formative_scheme_source_id'],$y['attendance_basis_source_id'],$c['schedule_source_id']] as $source) {
                $d=$this->one('SELECT d.status FROM regulatory_sources s JOIN global_regulatory_documents d ON d.id=s.global_document_id WHERE s.id=? AND s.institute_id=? FOR UPDATE',[$source,$institute]);
                if ($d['status']!=='published') {
                    throw new HttpException('UNREVIEWED_SOURCE','Published applicable academic source required.',409);
                }
            }
            $relax=$this->rows('SELECT * FROM attendance_relaxations WHERE enrollment_id=? AND batch_year_id=? AND cycle_id=? AND institute_id=? FOR UPDATE',[$enrollment,$year,$cycle,$institute]);
            $points=$relax?(float)$relax[0]['points']:0;
            if ($points>(float)$r['principal_relaxation_cap']) {
                throw new HttpException('RELAXATION_CAP','Relaxation exceeds applicable official cap.',409);
            }
            if ($relax) {
                $d=$this->one('SELECT d.* FROM regulatory_sources s JOIN global_regulatory_documents d ON d.id=s.global_document_id WHERE s.id=? AND s.institute_id=? FOR UPDATE',[$relax[0]['authority_source_id'],$institute]);
                if ($d['status']!=='published' || (int)$d['id']!==(int)$r['relaxation_source_document_id']) {
                    throw new HttpException('RELAXATION_SOURCE','Relaxation must cite the selected official relaxation authority.',409);
                }
            }
            $effective=min(100,round($raw+$points,2));
            $reasons=[];
            if ($effective<(float)$r['minimum_attendance']) $reasons[]='attendance';
            if ($formative<(float)$r['minimum_formative']) $reasons[]='formative';
            if ((int)$r['requires_prn'] && (!$e['prn'] || !$e['prn_verified_on'])) $reasons[]='prn';
            $fee=$this->rows('SELECT * FROM exam_fee_clearances WHERE enrollment_id=? AND cycle_id=? AND institute_id=? FOR UPDATE',[$enrollment,$cycle,$institute]);
            if ((int)$r['requires_cbt_fee'] && (!$fee || !(int)$fee[0]['cbt_cleared'])) $reasons[]='cbt_fee';
            if ((int)$r['requires_practical_fee'] && (!$fee || !(int)$fee[0]['practical_cleared'])) $reasons[]='practical_fee';
            $status=$reasons?'ineligible':'eligible';
            $q=$this->db->prepare('INSERT INTO exam_eligibility (institute_id,enrollment_id,batch_id,batch_year_id,cycle_id,scheduled_units,attended_units,attendance_basis,raw_attendance,effective_attendance,relaxation_id,formative_percent,formative_complete,minimum_attendance_as_applied,minimum_formative_as_applied,relaxation_cap_as_applied,eligibility_status,reason,rule_snapshot_json,evidence_reference,frozen_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$institute,$enrollment,$e['batch_id'],$year,$cycle,$scheduled,$attended,$y['attendance_basis'],$raw,$effective,$relax?$relax[0]['id']:null,$formative,1,$r['minimum_attendance'],$r['minimum_formative'],$r['principal_relaxation_cap'],$status,$reasons?implode(',',$reasons):null,json_encode($r,JSON_THROW_ON_ERROR),$evidence,(string)$actor->userId]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'exam.eligibility.freeze','exam_eligibility',$id,['status'=>$status,'rule_id'=>$r['id']]);
            return $id;
        });
    }
}
