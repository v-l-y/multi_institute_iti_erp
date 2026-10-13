<?php
declare(strict_types=1);
namespace App\Modules\Assessment;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

/** Reviewed overlays; original evidence and existing eligibility snapshots stay immutable. */
final readonly class AcademicCorrectionService
{
    public function __construct(private PDO $db) {}
    private function principal(AuthContext $actor,string $reason,string $reference): int
    {
        if (!in_array('principal',$actor->roles,true)) throw new HttpException('PRINCIPAL_REQUIRED','Principal review required.',403);
        if (trim($reason)==='' || strlen($reason)>1000 || trim($reference)==='' || strlen($reference)>190) throw new HttpException('INVALID_INPUT','Correction reason and authority reference required.',422);
        return $actor->instituteId??throw new HttpException('FORBIDDEN','Institute identity required.',403);
    }
    private function source(int $institute,int $source): void
    {
        if ($source===0) return; // Institute order: actual Principal + authority reference.
        $q=$this->db->prepare("SELECT d.id FROM regulatory_sources s JOIN global_regulatory_documents d ON d.id=s.global_document_id WHERE s.id=? AND s.institute_id=? AND d.status='published' AND d.reviewed_at IS NOT NULL AND d.sha256 IS NOT NULL AND d.issued_on<=UTC_DATE() FOR UPDATE");
        $q->execute([$source,$institute]);
        if (!$q->fetchColumn()) throw new HttpException('REVIEWED_SOURCE_REQUIRED','Published reviewed correction authority required.',409);
    }
    public function attendance(AuthContext $actor,int $session,int $enrollment,int $minutes,string $status,
        int $source,string $reason,string $reference,int $expected=0): int
    {
        $institute=$this->principal($actor,$reason,$reference);
        if ($minutes<0 || !in_array($status,['present','absent','leave','medical_leave','emergency_leave','ojt'],true) || ($status==='absent' && $minutes!==0)) throw new HttpException('INVALID_INPUT','Invalid corrected attendance.',422);
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'attendance.correct',function () use ($actor,$session,$enrollment,$minutes,$status,$source,$reason,$reference,$expected,$institute,$tx): int {
            $q=$this->db->prepare('SELECT a.*,s.scheduled_minutes,s.locked_on FROM attendance_records a JOIN attendance_sessions s ON s.id=a.session_id AND s.institute_id=a.institute_id WHERE a.session_id=? AND a.enrollment_id=? AND a.institute_id=? FOR UPDATE');
            $q->execute([$session,$enrollment,$institute]); $record=$q->fetch(PDO::FETCH_ASSOC);
            if (!$record) throw new HttpException('NOT_FOUND','Attendance record not found.',404);
            if ($record['locked_on']===null || $minutes>(int)$record['scheduled_minutes']) throw new HttpException('CORRECTION_STATE','Locked attendance within scheduled minutes required.',409);
            if ($record['recorded_by']===$actor->username) throw new HttpException('INDEPENDENT_REVIEW','Original recorder cannot approve their correction.',403);
            $this->source($institute,$source);
            $q=$this->db->prepare('SELECT id,after_json FROM attendance_corrections WHERE session_id=? AND enrollment_id=? AND institute_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $q->execute([$session,$enrollment,$institute]); $prior=$q->fetch(PDO::FETCH_ASSOC);
            if ($expected!==($prior?(int)$prior['id']:0)) throw new HttpException('STALE_CORRECTION','Read the latest correction before submitting.',409);
            $before=$prior?json_decode($prior['after_json'],true,32,JSON_THROW_ON_ERROR):['attended_minutes'=>(int)$record['attended_minutes'],'status'=>$record['status']];
            $after=['attended_minutes'=>$minutes,'status'=>$status,'source_id'=>$source>0?$source:null];
            $this->db->prepare('INSERT INTO attendance_corrections (institute_id,session_id,enrollment_id,before_json,after_json,reason,authority_reference,approved_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$institute,$session,$enrollment,json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),$reason,$reference,$actor->username]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'attendance.correction.approve','attendance_corrections',$id,['session_id'=>$session,'enrollment_id'=>$enrollment,'source_id'=>$source]);
            return $id;
        });
    }
    public function formative(AuthContext $actor,?int $assessment,int $enrollment,int $year,?string $item,string $marks,
        int $source,string $reason,string $reference,int $expected=0): int
    {
        $institute=$this->principal($actor,$reason,$reference);
        if (($assessment===null && $item===null) || ($assessment!==null && $item!==null)
            || !preg_match($assessment!==null?'/^(0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?$/D':'/^(0|[1-9][0-9]{0,5})(?:\.[0-9]{1,3})?$/D',$marks)) throw new HttpException('INVALID_INPUT','Valid score identity and nonnegative decimal marks required.',422);
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'formative.template.manage',function () use ($actor,$assessment,$enrollment,$year,$item,$marks,$source,$reason,$reference,$expected,$institute,$tx): int {
            if ($assessment!==null) {
                $q=$this->db->prepare('SELECT * FROM formative_assessments WHERE id=? AND enrollment_id=? AND batch_year_id=? AND institute_id=? FOR UPDATE');
                $q->execute([$assessment,$enrollment,$year,$institute]);
            } else {
                $q=$this->db->prepare('SELECT s.*,i.max_marks FROM formative_template_scores s JOIN formative_template_items i ON i.batch_year_id=s.batch_year_id AND i.item_code=s.item_code AND i.institute_id=s.institute_id WHERE s.enrollment_id=? AND s.batch_year_id=? AND s.item_code=? AND s.institute_id=? FOR UPDATE');
                $q->execute([$enrollment,$year,$item,$institute]);
            }
            $record=$q->fetch(PDO::FETCH_ASSOC);
            if (!$record) throw new HttpException('NOT_FOUND','Score not found.',404);
            if ($record['locked_on']===null || (float)$marks>(float)$record['max_marks']) throw new HttpException('CORRECTION_STATE','Locked score within the original maximum required.',409);
            if ((int)$record['assessor_id']===$actor->staffId) throw new HttpException('INDEPENDENT_REVIEW','Original assessor cannot approve their correction.',403);
            $this->source($institute,$source);
            $q=$this->db->prepare('SELECT id,after_json FROM formative_corrections WHERE institute_id=? AND enrollment_id=? AND batch_year_id=? AND assessment_id<=>? AND item_code<=>? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $q->execute([$institute,$enrollment,$year,$assessment,$item]); $prior=$q->fetch(PDO::FETCH_ASSOC);
            if ($expected!==($prior?(int)$prior['id']:0)) throw new HttpException('STALE_CORRECTION','Read the latest correction before submitting.',409);
            $before=$prior?json_decode($prior['after_json'],true,32,JSON_THROW_ON_ERROR):['obtained_marks'=>$record['obtained_marks']];
            $after=['obtained_marks'=>$marks];
            $this->db->prepare('INSERT INTO formative_corrections (institute_id,enrollment_id,batch_id,batch_year_id,assessment_id,item_code,before_json,after_json,source_id,reason,authority_reference,approved_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$institute,$enrollment,$record['batch_id'],$year,$assessment,$item,json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),$source>0?$source:null,$reason,$reference,$actor->userId]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'formative.correction.approve','formative_corrections',$id,['enrollment_id'=>$enrollment,'batch_year_id'=>$year,'source_id'=>$source]);
            return $id;
        });
    }
}
