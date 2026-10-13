<?php
declare(strict_types=1);

namespace App\Modules\Exams;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Local recording only; this service does not issue papers or upload marks. */
final readonly class PracticalExamService
{
    public function __construct(private PDO $db) {}

    private function one(string $sql,array $params): ?array
    {
        $q=$this->db->prepare($sql);
        $q->execute($params);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function source(int $institute,int $id,string $authority): void
    {
        $s=$this->one('SELECT d.authority,d.document_type,d.status,d.sha256
            FROM regulatory_sources r JOIN global_regulatory_documents d
              ON d.id=r.global_document_id
            WHERE r.id=? AND r.institute_id=? FOR UPDATE',[$id,$institute]);
        $kind=strtolower((string)($s['document_type']??''));
        if (!$s || $s['authority']!==$authority || $s['status']!=='published'
            || !$s['sha256'] || (!str_contains($kind,'practical')
                && !str_contains($kind,'question_paper'))) {
            throw new HttpException('PRACTICAL_SOURCE_REQUIRED',
                'Applicable published institute-linked official exam evidence is required.',409);
        }
    }

    public function session(AuthContext $actor,int $institute,int $cycleId,
        int $yearId,string $endedAt,string $examiner,string $examinerRef,
        string $paperAuthority,string $paperRef,int $sourceId): int
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_REQUIRED','Principal session review required.',403);
        }
        if ($endedAt>(new \DateTimeImmutable('now',
            new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s')) {
            throw new HttpException('INVALID_INPUT','Future practical sessions cannot be recorded.',422);
        }
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'exam.practical.manage',function () use (
            $actor,$institute,$cycleId,$yearId,$endedAt,$examiner,$examinerRef,
            $paperAuthority,$paperRef,$sourceId,$tx
        ): int {
            $cycle=$this->one('SELECT id,status,exam_starts_on FROM exam_cycles
                WHERE id=? AND institute_id=? FOR UPDATE',[$cycleId,$institute]);
            $year=$this->one('SELECT id FROM batch_years
                WHERE id=? AND institute_id=? FOR UPDATE',[$yearId,$institute]);
            if (!$cycle || !$year || !in_array($cycle['status'],
                ['eligibility_open','eligibility_frozen','scheduled'],true)
                || substr($endedAt,0,10)<$cycle['exam_starts_on']) {
                throw new HttpException('PRACTICAL_CYCLE_NOT_READY',
                    'Applicable institute exam cycle and batch-year required.',409);
            }
            $this->source($institute,$sourceId,$paperAuthority);
            $eligible=$this->one("SELECT id FROM exam_eligibility
                WHERE institute_id=? AND cycle_id=? AND batch_year_id=?
                  AND eligibility_status='eligible' LIMIT 1 FOR UPDATE",
                [$institute,$cycleId,$yearId]);
            if (!$eligible) {
                throw new HttpException('PRACTICAL_ELIGIBILITY_REQUIRED',
                    'Frozen eligible trainee reference required for the exam session.',409);
            }
            $q=$this->db->prepare('INSERT INTO practical_sessions
                (institute_id,cycle_id,batch_year_id,ended_at,examiner_name,
                 examiner_reference,question_paper_authority,question_paper_reference,
                 question_paper_source_id) VALUES (?,?,?,?,?,?,?,?,?)');
            $q->execute([$institute,$cycleId,$yearId,$endedAt,$examiner,
                $examinerRef,$paperAuthority,$paperRef,$sourceId]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'exam.practical.session.record',
                'practical_sessions',$id,[
                    'cycle_id'=>$cycleId,'batch_year_id'=>$yearId,
                    'examiner_reference'=>$examinerRef,'paper_reference'=>$paperRef,
                    'question_paper_source_id'=>$sourceId
                ]);
            return $id;
        });
    }

    public function marks(AuthContext $actor,int $institute,int $sessionId,
        int $eligibilityId,string $attendance,string $max,?string $obtained,
        int $sourceId,string $evidence): void
    {
        if ($actor->staffId===null || !in_array('instructor',$actor->roles,true)) {
            throw new HttpException('INSTRUCTOR_REQUIRED',
                'Assigned instructor account required.',403);
        }
        $tx=new InstituteTransaction($this->db);
        try {
            $tx->run($actor,$institute,'exam.practical.record.assigned',function () use (
                $actor,$institute,$sessionId,$eligibilityId,$attendance,$max,
                $obtained,$sourceId,$evidence,$tx
            ): void {
                $session=$this->one('SELECT p.id,p.cycle_id,p.batch_year_id,p.ended_at,
                    b.trade_unit_shift_id
                    FROM practical_sessions p JOIN batch_years y
                      ON y.id=p.batch_year_id AND y.institute_id=p.institute_id
                    JOIN batches b ON b.id=y.batch_id AND b.institute_id=y.institute_id
                    WHERE p.id=? AND p.institute_id=? FOR UPDATE',[$sessionId,$institute]);
                if (!$session) throw new HttpException('NOT_FOUND','Practical session not found.',404);
                $on=substr($session['ended_at'],0,10);
                $mapping=$this->one("SELECT m.id FROM instructor_mappings m
                    JOIN staff s ON s.id=m.staff_id AND s.institute_id=m.institute_id
                    WHERE m.institute_id=? AND m.staff_id=? AND m.mapping_type='trade'
                      AND m.trade_unit_shift_id=? AND m.status='active'
                      AND m.valid_from<=? AND (m.valid_to IS NULL OR m.valid_to>=?)
                      AND s.nims_verification='verified' AND s.left_on IS NULL
                    LIMIT 1 FOR UPDATE",[$institute,$actor->staffId,
                        $session['trade_unit_shift_id'],$on,$on]);
                if (!$mapping) {
                    throw new HttpException('PRACTICAL_INSTRUCTOR_NOT_ASSIGNED',
                        'Current verified Trade instructor mapping required.',403);
                }
                $eligibility=$this->one("SELECT id FROM exam_eligibility
                    WHERE id=? AND institute_id=? AND cycle_id=? AND batch_year_id=?
                      AND eligibility_status='eligible' FOR UPDATE",[
                        $eligibilityId,$institute,$session['cycle_id'],$session['batch_year_id']
                    ]);
                if (!$eligibility) {
                    throw new HttpException('PRACTICAL_NOT_ELIGIBLE',
                        'Matching frozen eligible trainee required.',409);
                }
                $source=$this->one('SELECT d.status,d.sha256,d.document_type
                    FROM regulatory_sources r JOIN global_regulatory_documents d
                      ON d.id=r.global_document_id
                    WHERE r.id=? AND r.institute_id=? FOR UPDATE',[$sourceId,$institute]);
                if (!$source || $source['status']!=='published' || !$source['sha256']
                    || !str_contains(strtolower((string)$source['document_type']),'practical')) {
                    throw new HttpException('PRACTICAL_MARKS_SOURCE',
                        'Reviewed official practical assessment scheme required.',409);
                }
                $other=$this->one('SELECT max_marks FROM practical_marks
                    WHERE practical_session_id=? AND institute_id=? LIMIT 1 FOR UPDATE',
                    [$sessionId,$institute]);
                if ($other && (int)round((float)$other['max_marks']*100)
                    !==(int)round((float)$max*100)) {
                    throw new HttpException('PRACTICAL_MAX_CONFLICT',
                        'One session must retain its source-declared marks maximum.',409);
                }
                if ($this->one('SELECT eligibility_id FROM practical_marks
                    WHERE practical_session_id=? AND eligibility_id=? AND institute_id=?
                    FOR UPDATE',[$sessionId,$eligibilityId,$institute])) {
                    throw new HttpException('PRACTICAL_MARKS_EXIST',
                        'Existing practical marks cannot be overwritten.',409);
                }
                $this->db->prepare('INSERT INTO practical_marks
                    (institute_id,practical_session_id,eligibility_id,cycle_id,
                     batch_year_id,attendance_status,max_marks,obtained_marks)
                    VALUES (?,?,?,?,?,?,?,?)')->execute([
                        $institute,$sessionId,$eligibilityId,$session['cycle_id'],
                        $session['batch_year_id'],$attendance,$max,$obtained
                    ]);
                $tx->audit($actor,$institute,'exam.practical.marks.record',
                    'practical_marks',$sessionId,[
                        'eligibility_id'=>$eligibilityId,'attendance_status'=>$attendance,
                        'max_marks'=>$max,'obtained_marks'=>$obtained,
                        'marks_source_id'=>$sourceId,'evidence_reference'=>$evidence
                    ]);
            });
        } catch(PDOException $e) {
            if ((int)($e->errorInfo[1]??0)===1062) {
                throw new HttpException('PRACTICAL_MARKS_EXIST',
                    'Practical marks are already recorded.',409);
            }
            throw $e;
        }
    }
}
