<?php
declare(strict_types=1);
namespace App\Modules\Admissions\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\AuthContext;
use DateTimeImmutable;
use PDO;

/** Activate/cancel verified local admissions; never creates an official allotment. */
final readonly class AdmissionService
{
    public function __construct(private PDO $db) {}

    private function one(string $sql,array $args): array
    {
        $q=$this->db->prepare($sql);
        $q->execute($args);
        return $q->fetch(PDO::FETCH_ASSOC) ?: throw new HttpException('ADMISSION_REFERENCE','Admission reference is missing.',409);
    }

    private function source(int $id,int $institute,string $on): void
    {
        $source=$this->one('SELECT d.* FROM regulatory_sources s JOIN global_regulatory_documents d ON d.id=s.global_document_id WHERE s.id=? AND s.institute_id=? FOR UPDATE',[$id,$institute]);
        if ($source['status']!=='published' || $source['reviewed_at']===null || $source['issued_on']>$on) {
            throw new HttpException('UNREVIEWED_SOURCE','Applicable reviewed official source required.',409);
        }
    }

    public function activate(AuthContext $actor,int $institute,int $enrollment): void
    {
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'enrollment.manage',function () use ($actor,$institute,$enrollment,$tx) {
            $e=$this->one('SELECT * FROM enrollments WHERE id=? AND institute_id=? FOR UPDATE',[$enrollment,$institute]);
            if ($e['status']==='active') {
                return;
            }
            if ($e['status']!=='pending_verification' || !(int)$e['qualification_check_passed'] || $e['admission_route']==='unverified' || !$e['admission_route_source_id'] || trim($e['admission_route_evidence_reference']??'')==='') {
                throw new HttpException('UNVERIFIED_ADMISSION','Verified qualification, route and evidence required.',409);
            }
            $on=$e['admitted_on'];
            $today=(new DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
            $activationOn=max($on,$today);
            if ($on>$today) {
                throw new HttpException('FUTURE_ADMISSION','Admission date cannot be in the future.',409);
            }
            $this->source((int)$e['admission_route_source_id'],$institute,$on);
            $b=$this->one('SELECT * FROM batches WHERE id=? AND institute_id=? FOR UPDATE',[$e['batch_id'],$institute]);
            $u=$this->one('SELECT * FROM trade_unit_shifts WHERE id=? AND institute_id=? FOR UPDATE',[$b['trade_unit_shift_id'],$institute]);
            $s=$this->one('SELECT * FROM seat_matrix_entries WHERE id=? AND institute_id=? FOR UPDATE',[$b['seat_matrix_entry_id'],$institute]);
            $v=$this->one('SELECT * FROM seat_matrix_versions WHERE id=? AND institute_id=? FOR UPDATE',[$s['version_id'],$institute]);
            $w=$this->one('SELECT * FROM admission_intake_revisions WHERE id=? AND institute_id=? FOR UPDATE',[$e['admission_intake_revision_id'],$institute]);
            $r=$this->one('SELECT g.* FROM admission_rules l JOIN global_admission_rule_versions g ON g.id=l.global_admission_rule_id WHERE l.id=? AND l.institute_id=? FOR UPDATE',[$e['admission_rule_id'],$institute]);
            $a=$this->one("SELECT * FROM institute_affiliation_history WHERE institute_id=? AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC LIMIT 1 FOR UPDATE",[$institute,$on,$on]);
            $c=$this->one('SELECT g.* FROM curriculum_versions l JOIN global_curriculum_versions g ON g.id=l.global_curriculum_id WHERE l.id=? AND l.institute_id=? FOR UPDATE',[$b['curriculum_id'],$institute]);
            $calendar=$this->one('SELECT * FROM cts_training_calendars WHERE id=? FOR UPDATE',[$b['calendar_id']]);
            if ($calendar['status']!=='published') {
                throw new HttpException('ADMISSION_NOT_ALLOWED','Published applicable training calendar required.',409);
            }
            if (!in_array($b['status'],['planned','active'],true) || $u['status']!=='active' || $u['affiliation_status']!=='approved' || $u['valid_from']>$on || ($u['valid_to']!==null && $u['valid_to']<$activationOn) || $a['affiliation_status']!=='affiliated' || $v['status']!=='final' || $w['status']!=='published' || $r['status']!=='published' || $c['status']!=='published' || !(int)$s['admission_allowed'] || !(int)$s['instructor_mapping_satisfied'] || (int)$s['consumed_previous_session']) {
                throw new HttpException('ADMISSION_NOT_ALLOWED','Applicable affiliation, curriculum and final Seat Matrix required.',409);
            }
            if ($on<$w['opens_on'] || $on>$w['closes_on'] || $on<$r['admission_opens_on'] || $on>$r['admission_closes_on'] || $c['effective_from']>$on || ($c['effective_to']!==null && $c['effective_to']<$on)) {
                throw new HttpException('ADMISSION_WINDOW','Admission date is outside the applicable approved window.',409);
            }
            foreach ([$u['source_id'],$v['source_id'],$w['source_id'],$a['source_id']] as $source) {
                $this->source((int)$source,$institute,$on);
            }
            foreach ([$r['source_document_id'],$r['state_source_document_id'],$r['admission_window_document_id'],$c['source_document_id'],$calendar['source_document_id']] as $document) {
                if ($document===null) {
                    continue;
                }
                $d=$this->one('SELECT status,issued_on FROM global_regulatory_documents WHERE id=? FOR UPDATE',[$document]);
                if ($d['status']!=='published' || $d['issued_on']>$on) {
                    throw new HttpException('UNREVIEWED_RULE','Reviewed applicable admission/curriculum source required.',409);
                }
            }
            $student=$this->one('SELECT * FROM students WHERE id=? AND institute_id=? FOR UPDATE',[$e['student_id'],$institute]);
            $qualification=$this->one('SELECT * FROM student_qualifications WHERE id=? AND student_id=? AND institute_id=? FOR UPDATE',[$e['qualification_id'],$e['student_id'],$institute]);
            $routes=json_decode($r['qualification_routes_json'],true,32,JSON_THROW_ON_ERROR);
            $route=$routes[$e['qualification_route_code']]??null;
            if (!is_array($route) || empty($route['level_codes']) || !in_array($qualification['level_code'],$route['level_codes'],true)) {
                throw new HttpException('QUALIFICATION_ROUTE','Qualification must match a configured source-backed route.',409);
            }
            $subjects=json_decode($qualification['subject_results_json'],true,32,JSON_THROW_ON_ERROR);
            foreach ($route['minimum_subject_marks']??[] as $subject=>$minimum) {
                if (!isset($subjects[$subject]) || !is_numeric($subjects[$subject]) || !is_numeric($minimum) || (float)$subjects[$subject]<(float)$minimum) {
                    throw new HttpException('QUALIFICATION_SUBJECT','Required subject marks do not meet the selected route.',409);
                }
            }
            $birth=new DateTimeImmutable($student['date_of_birth']);
            $cutoff=new DateTimeImmutable($r['age_cutoff_date']);
            if ($birth>$cutoff || $birth->diff($cutoff)->y<(int)$r['minimum_age_years']) {
                throw new HttpException('ADMISSION_AGE','Student does not meet the selected official age rule.',409);
            }
            $mapping=$this->one('SELECT m.*,s.nims_verification,s.left_on FROM instructor_mappings m JOIN staff s ON s.id=m.staff_id AND s.institute_id=m.institute_id WHERE m.id=? AND m.institute_id=? FOR UPDATE',[$s['instructor_mapping_id'],$institute]);
            if ($mapping['status']!=='active' || $mapping['nims_verification']!=='verified' || $mapping['valid_from']>$on || ($mapping['valid_to']!==null && $mapping['valid_to']<$on) || ($mapping['left_on']!==null && $mapping['left_on']<=$on)) {
                throw new HttpException('INSTRUCTOR_NOT_VERIFIED','Applicable verified instructor mapping required.',409);
            }
            if ($b['training_mode']!==$u['training_mode']) {
                throw new HttpException('TRAINING_MODE','Batch and affiliated unit training modes differ.',409);
            }
            if ($u['training_mode']==='DST') {
                $m=$this->one("SELECT a.* FROM dst_agreement_units x JOIN dst_agreements a ON a.id=x.agreement_id AND a.institute_id=x.institute_id WHERE x.trade_unit_shift_id=? AND x.institute_id=? AND a.status='verified' AND a.starts_on<=? AND a.ends_on>=? ORDER BY a.ends_on DESC LIMIT 1 FOR UPDATE",[$u['id'],$institute,$on,$activationOn]);
                $this->source((int)$m['source_id'],$institute,$on);
                if ((int)$u['shift_no']===3) {
                    $q=$this->db->prepare("SELECT shift_no,COUNT(*) units FROM trade_unit_shifts WHERE institute_id=? AND trade_id=? AND status='active' AND affiliation_status='approved' AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?) GROUP BY shift_no");
                    $q->execute([$institute,$u['trade_id'],$on,$on]);
                    $counts=array_column($q->fetchAll(PDO::FETCH_ASSOC),'units','shift_no');
                    if ((int)($counts[3]??0)>max((int)($counts[1]??0),(int)($counts[2]??0))) {
                        throw new HttpException('DST_BASE_UNIT_LIMIT','Third-shift DST units exceed affiliated base units.',409);
                    }
                }
            }
            // Completed, transferred and dropped-out admissions continue to consume this intake's seats.
            $q=$this->db->prepare("SELECT COUNT(*) FROM enrollments WHERE batch_id=? AND institute_id=? AND status NOT IN ('pending_verification','cancelled')");
            $q->execute([$b['id'],$institute]);
            $count=(int)$q->fetchColumn();
            if ($count>=min((int)$b['capacity'],(int)$s['seat_capacity'],(int)$u['sanctioned_capacity'])) {
                throw new HttpException('BATCH_FULL','No approved seats remain.',409);
            }
            foreach ([$e['vertical_quota_id'],$e['horizontal_quota_id']] as $quota) {
                if ($quota===null) {
                    continue;
                }
                $quotaRow=$this->one('SELECT * FROM batch_quotas WHERE id=? AND institute_id=? FOR UPDATE',[$quota,$institute]);
                $policy=$this->one('SELECT g.* FROM reservation_policies l JOIN global_reservation_policies g ON g.id=l.global_reservation_policy_id WHERE l.id=? AND l.institute_id=? FOR UPDATE',[$quotaRow['policy_id'],$institute]);
                if ($policy['status']!=='published' || !$e['quota_evidence_reference']) {
                    throw new HttpException('UNVERIFIED_QUOTA','Published applicable quota policy and student evidence required.',409);
                }
                $d=$this->one('SELECT status FROM global_regulatory_documents WHERE id=? FOR UPDATE',[$policy['source_document_id']]);
                if ($d['status']!=='published') {
                    throw new HttpException('UNVERIFIED_QUOTA','Quota source must be reviewed.',409);
                }
                $q=$this->db->prepare("SELECT COUNT(*) FROM enrollments WHERE institute_id=? AND (vertical_quota_id=? OR horizontal_quota_id=?) AND status NOT IN ('pending_verification','cancelled')");
                $q->execute([$institute,$quota,$quota]);
                $used=(int)$q->fetchColumn();
                if ($used>=(int)$quotaRow['seats']) {
                    throw new HttpException('QUOTA_FULL','No approved quota seats remain.',409);
                }
                $q=$this->db->prepare('UPDATE batch_quotas SET occupied_seats=? WHERE id=? AND institute_id=?');
                $q->execute([$used+1,$quota,$institute]);
            }
            $q=$this->db->prepare("UPDATE enrollments SET status='active' WHERE id=? AND institute_id=?");
            $q->execute([$enrollment,$institute]);
            $q=$this->db->prepare('UPDATE batches SET occupied_seats=? WHERE id=? AND institute_id=?');
            $q->execute([$count+1,$b['id'],$institute]);
            $tx->audit($actor,$institute,'enrollment.activate','enrollments',$enrollment,['admission_route'=>$e['admission_route']]);
        });
    }

    public function cancel(AuthContext $actor,int $institute,int $enrollment,string $reason): void
    {
        if (trim($reason)==='' || strlen($reason)>1000) {
            throw new HttpException('CANCELLATION_REASON','Cancellation reason required.',422);
        }
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'enrollment.manage',function () use ($actor,$institute,$enrollment,$reason,$tx) {
            $e=$this->one('SELECT * FROM enrollments WHERE id=? AND institute_id=? FOR UPDATE',[$enrollment,$institute]);
            if ($e['status']==='cancelled') {
                return;
            }
            if (!in_array($e['status'],['pending_verification','active'],true)) {
                throw new HttpException('CANCELLATION_STATE','Historical completed/transfer/dropout records need a separate correction workflow.',409);
            }
            $q=$this->db->prepare("UPDATE enrollments SET status='cancelled' WHERE id=? AND institute_id=?");
            $q->execute([$enrollment,$institute]);
            $q=$this->db->prepare("UPDATE batches SET occupied_seats=(SELECT COUNT(*) FROM enrollments WHERE batch_id=? AND institute_id=? AND status NOT IN ('pending_verification','cancelled')) WHERE id=? AND institute_id=?");
            $q->execute([$e['batch_id'],$institute,$e['batch_id'],$institute]);
            foreach ([$e['vertical_quota_id'],$e['horizontal_quota_id']] as $quota) {
                if ($quota!==null) {
                    $q=$this->db->prepare("UPDATE batch_quotas SET occupied_seats=(SELECT COUNT(*) FROM enrollments WHERE institute_id=? AND (vertical_quota_id=? OR horizontal_quota_id=?) AND status NOT IN ('pending_verification','cancelled')) WHERE id=? AND institute_id=?");
                    $q->execute([$institute,$quota,$quota,$quota,$institute]);
                }
            }
            $tx->audit($actor,$institute,'enrollment.cancel','enrollments',$enrollment,['reason'=>$reason]);
        });
    }
}
