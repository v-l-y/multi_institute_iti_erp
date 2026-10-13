<?php
declare(strict_types=1);
namespace App\Modules\Students\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

final readonly class StudentLifecycleService
{
    public function __construct(private PDO $db) {}

    public function profile(AuthContext $actor,int $institute,int $student,array $changes,string $reason): void
    {
        $columns=['full_name'=>190,'address'=>16000,'mobile'=>20,'email'=>254];
        if (!$changes || trim($reason)==='' || strlen($reason)>1000) throw new HttpException('INVALID_INPUT','Profile changes and correction reason required.',422);
        foreach ($changes as $key=>$value) {
            if (!isset($columns[$key]) || !is_string($value) || trim($value)==='' || strlen($value)>$columns[$key]
                || ($key==='email' && filter_var($value,FILTER_VALIDATE_EMAIL)===false)) {
                throw new HttpException('INVALID_INPUT','Unsupported or invalid profile field.',422);
            }
        }
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'student.manage',function () use ($actor,$institute,$student,$changes,$reason,$tx): void {
            $q=$this->db->prepare('SELECT full_name,address,mobile,email FROM students WHERE id=? AND institute_id=? FOR UPDATE');
            $q->execute([$student,$institute]);
            $before=$q->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new HttpException('NOT_FOUND','Student not found.',404);
            $sets=[];
            foreach ($changes as $key=>$value) $sets[]="$key=?";
            $this->db->prepare('UPDATE students SET '.implode(',',$sets).' WHERE id=? AND institute_id=?')
                ->execute([...array_values($changes),$student,$institute]);
            $tx->audit($actor,$institute,'student.profile.correct','students',$student,
                ['before'=>array_intersect_key($before,$changes),'after'=>$changes,'reason'=>$reason]);
        });
    }

    public function transition(AuthContext $actor,int $institute,int $enrollment,string $target,string $on,
        int $source,string $reference,string $reason,?string $destination=null): void
    {
        if (!in_array('principal',$actor->roles,true)) throw new HttpException('PRINCIPAL_REQUIRED','Principal review required.',403);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$on);
        $today=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        if (!in_array($target,['dropout','transferred','completed'],true) || !$date || $date->format('Y-m-d')!==$on || $on>$today
            || trim($reference)==='' || strlen($reference)>190 || trim($reason)==='' || strlen($reason)>1000
            || ($target==='transferred' && ($destination===null || !preg_match('/^[A-Za-z0-9_-]{1,40}$/D',$destination)))
            || ($target!=='transferred' && $destination!==null)) {
            throw new HttpException('INVALID_INPUT','Valid lifecycle transition, date, reviewed evidence and reason required.',422);
        }
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'enrollment.manage',function () use ($actor,$institute,$enrollment,$target,$on,$source,$reference,$reason,$destination,$tx): void {
            $q=$this->db->prepare('SELECT e.*,b.ends_on FROM enrollments e JOIN batches b ON b.id=e.batch_id AND b.institute_id=e.institute_id WHERE e.id=? AND e.institute_id=? FOR UPDATE');
            $q->execute([$enrollment,$institute]);
            $e=$q->fetch(PDO::FETCH_ASSOC);
            if (!$e) throw new HttpException('NOT_FOUND','Enrollment not found.',404);
            if ($e['status']!=='active' || $on<$e['admitted_on'] || ($target==='completed' && $on<$e['ends_on'])) {
                throw new HttpException('LIFECYCLE_STATE','Only an active enrollment with an applicable date may transition.',409);
            }
            if ($source>0) {
                $q=$this->db->prepare("SELECT d.id FROM regulatory_sources s JOIN global_regulatory_documents d ON d.id=s.global_document_id WHERE s.id=? AND s.institute_id=? AND d.status='published' AND d.reviewed_at IS NOT NULL AND d.sha256 IS NOT NULL AND d.issued_on<=? FOR UPDATE");
                $q->execute([$source,$institute,$on]);
                if (!$q->fetchColumn()) throw new HttpException('REVIEWED_SOURCE_REQUIRED','Published applicable external source required.',409);
            }
            $this->db->prepare('UPDATE enrollments SET status=? WHERE id=? AND institute_id=?')->execute([$target,$enrollment,$institute]);
            if ($target!=='completed') {
                $this->db->prepare('INSERT INTO student_events (institute_id,enrollment_id,event_type,effective_on,reference_no,destination_mis_code,reason,recorded_by) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$institute,$enrollment,$target==='transferred'?'transfer':'dropout',$on,$reference,$destination,$reason,$actor->username]);
            }
            // These historical statuses continue consuming intake seats. Never
            // reopen frozen academic records or infer official exam/certification.
            $tx->audit($actor,$institute,'student.enrollment.'.$target,'enrollments',$enrollment,
                ['before'=>'active','after'=>$target,'effective_on'=>$on,'source_id'=>$source,
                 'reference'=>$reference,'reason'=>$reason,'destination_mis_code'=>$destination]);
        });
    }
}
