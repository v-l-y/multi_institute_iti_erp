<?php
declare(strict_types=1);

namespace App\Modules\Admissions\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\AuthContext;
use DateTimeImmutable;
use PDO;

/** Principal review verifies local admission evidence, never allocates a seat. */
final readonly class AdmissionReviewService
{
    public function __construct(private PDO $db) {}

    private function one(string $sql, array $args): array
    {
        $s = $this->db->prepare($sql);
        $s->execute($args);
        return $s->fetch(PDO::FETCH_ASSOC)
            ?: throw new HttpException('REVIEW_REFERENCE', 'Required reviewed reference not found.', 409);
    }

    private function publishedDocument(int $id, string $on): void
    {
        $d = $this->one('SELECT status,reviewed_at,issued_on FROM global_regulatory_documents WHERE id=? FOR UPDATE',[$id]);
        if ($d['status']!=='published' || $d['reviewed_at']===null || $d['issued_on']>$on) {
            throw new HttpException('UNREVIEWED_SOURCE','An applicable reviewed official document is required.',409);
        }
    }

    public function verify(AuthContext $actor,int $institute,int $enrollmentId,
        string $route,int $sourceId,string $evidence,?string $quotaEvidence): void
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_REVIEW_REQUIRED','Principal account required.',403);
        }
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'enrollment.manage',function () use (
            $actor,$tx,$institute,$enrollmentId,$route,$sourceId,$evidence,$quotaEvidence
        ): void {
            $e=$this->one('SELECT * FROM enrollments WHERE id=? AND institute_id=? FOR UPDATE',[$enrollmentId,$institute]);
            if ($e['status']!=='pending_verification'
                || (int)$e['qualification_check_passed']!==0
                || $e['admission_route']!=='unverified') {
                throw new HttpException('REVIEW_STATE','Only unverified drafts can be reviewed.',409);
            }
            $q=$this->db->prepare("SELECT actor_user_id FROM institute_activity_log
                WHERE institute_id=? AND action_code='enrollment.draft.create'
                  AND entity_table='enrollments' AND entity_id=?
                ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $q->execute([$institute,$enrollmentId]);
            $createdBy=$q->fetchColumn();
            if ($createdBy!==false && (int)$createdBy===$actor->userId) {
                throw new HttpException('INDEPENDENT_REVIEW','A draft requires a different reviewer.',403);
            }
            $on=$e['admitted_on'];
            $source=$this->one('SELECT global_document_id FROM regulatory_sources WHERE id=? AND institute_id=? FOR UPDATE',[$sourceId,$institute]);
            $this->publishedDocument((int)$source['global_document_id'],$on);
            $r=$this->one('SELECT g.* FROM admission_rules a JOIN global_admission_rule_versions g
                ON g.id=a.global_admission_rule_id WHERE a.id=? AND a.institute_id=? FOR UPDATE',
                [$e['admission_rule_id'],$institute]);
            if ($r['status']!=='published' || $r['admission_opens_on']>$on || $r['admission_closes_on']<$on) {
                throw new HttpException('REVIEW_RULE','Applicable published admission rule required.',409);
            }
            foreach (['source_document_id','state_source_document_id','admission_window_document_id'] as $field) {
                if ($r[$field]!==null) $this->publishedDocument((int)$r[$field],$on);
            }
            $student=$this->one('SELECT date_of_birth FROM students WHERE id=? AND institute_id=? FOR UPDATE',[$e['student_id'],$institute]);
            $qual=$this->one('SELECT level_code,subject_results_json,certificate_reference FROM student_qualifications
                WHERE id=? AND student_id=? AND institute_id=? FOR UPDATE',
                [$e['qualification_id'],$e['student_id'],$institute]);
            $routes=json_decode($r['qualification_routes_json'],true,32,JSON_THROW_ON_ERROR);
            $selected=is_array($routes) ? ($routes[$e['qualification_route_code']]??null) : null;
            if (!is_array($selected) || !isset($selected['level_codes'])
                || !is_array($selected['level_codes'])
                || !in_array($qual['level_code'],$selected['level_codes'],true)
                || trim($qual['certificate_reference'])==='') {
                throw new HttpException('REVIEW_QUALIFICATION','Recorded qualification is not supported by the published route.',409);
            }
            $marks=json_decode($qual['subject_results_json'],true,32,JSON_THROW_ON_ERROR);
            if (!is_array($marks)) throw new HttpException('REVIEW_QUALIFICATION','Invalid qualification results.',409);
            foreach ($selected['minimum_subject_marks']??[] as $subject=>$minimum) {
                if (!isset($marks[$subject]) || !is_numeric($marks[$subject]) || !is_numeric($minimum)
                    || (float)$marks[$subject]<(float)$minimum) {
                    throw new HttpException('REVIEW_QUALIFICATION','Published subject-mark threshold not met.',409);
                }
            }
            $born=new DateTimeImmutable($student['date_of_birth']);
            $cutoff=new DateTimeImmutable($r['age_cutoff_date']);
            if ($born>$cutoff || $born->diff($cutoff)->y<(int)$r['minimum_age_years']) {
                throw new HttpException('REVIEW_AGE','Published age requirement not met.',409);
            }
            $snapshot=json_encode([
                'reviewed'=>true,'rule_id'=>(int)$r['id'],
                'qualification_id'=>(int)$e['qualification_id'],
                'route_code'=>$e['qualification_route_code'],
                'admission_source_id'=>$sourceId
            ],JSON_THROW_ON_ERROR);
            $q=$this->db->prepare("UPDATE enrollments SET qualification_check_json=?,
                qualification_check_passed=1,checked_by=?,checked_on=UTC_DATE(),
                admission_route=?,admission_route_source_id=?,
                admission_route_evidence_reference=?,quota_evidence_reference=?
                WHERE id=? AND institute_id=? AND status='pending_verification'");
            $q->execute([$snapshot,$actor->username,$route,$sourceId,$evidence,$quotaEvidence,$enrollmentId,$institute]);
            $tx->audit($actor,$institute,'enrollment.principal.verify','enrollments',
                $enrollmentId,['route'=>$route,'source_id'=>$sourceId]);
        });
    }
}
