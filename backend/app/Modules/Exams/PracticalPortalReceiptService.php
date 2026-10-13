<?php
declare(strict_types=1);

namespace App\Modules\Exams;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Audit-only retention of external CTS practical portal receipts; never sends data. */
final readonly class PracticalPortalReceiptService
{
    public function __construct(private PDO $db) {}

    private function row(string $sql,array $args): ?array
    {
        $q=$this->db->prepare($sql);
        $q->execute($args);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function principal(AuthContext $actor): void
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_REQUIRED',
                'Principal must review the external portal response.',403);
        }
    }

    private function source(int $institute,int $id): void
    {
        $doc=$this->row('SELECT d.status,d.sha256,d.source_system
            FROM regulatory_sources r JOIN global_regulatory_documents d
              ON d.id=r.global_document_id
            WHERE r.id=? AND r.institute_id=? FOR UPDATE',[$id,$institute]);
        if (!$doc || $doc['status']!=='published' || !$doc['sha256']
            || !in_array($doc['source_system'],['SIDH','NCVT_MIS'],true)) {
            throw new HttpException('PRACTICAL_PORTAL_SOURCE',
                'Published institute-linked CTS portal source evidence required.',409);
        }
    }

    public function submitted(AuthContext $actor,int $institute,int $sessionId,
        int $attempt,int $sourceId,string $submittedAt,int $expected,
        int $submitted,string $portalRef,string $hash,string $evidence): int
    {
        self::principal($actor);
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'exam.portal.submit',function () use (
                $actor,$institute,$sessionId,$attempt,$sourceId,$submittedAt,
                $expected,$submitted,$portalRef,$hash,$evidence,$tx
            ): int {
                $this->source($institute,$sourceId);
                $session=$this->row('SELECT id,cycle_id,batch_year_id,ended_at
                    FROM practical_sessions WHERE id=? AND institute_id=? FOR UPDATE',
                    [$sessionId,$institute]);
                if (!$session) throw new HttpException('NOT_FOUND','Practical session not found.',404);
                if ($submittedAt<$session['ended_at']) {
                    throw new HttpException('PORTAL_SUBMISSION_DATE',
                        'External submission cannot precede completed practical session.',409);
                }
                $eligible=$this->row("SELECT COUNT(*) AS total FROM exam_eligibility
                    WHERE cycle_id=? AND batch_year_id=? AND institute_id=?
                    AND eligibility_status='eligible'",
                    [$session['cycle_id'],$session['batch_year_id'],$institute]);
                $count=$this->row('SELECT COUNT(*) AS total FROM practical_marks
                    WHERE practical_session_id=? AND institute_id=?',[$sessionId,$institute]);
                if ($expected<1 || (int)$eligible['total']!==$expected
                    || (int)$count['total']!==$submitted || $submitted!==$expected) {
                    throw new HttpException('PORTAL_PRACTICAL_INCOMPLETE',
                        'Every eligible trainee must have a locally recorded practical result before external submission attestation.',409);
                }
                $previous=$this->row("SELECT id,attempt_no,status FROM portal_submissions
                    WHERE submission_type='practical_marks'
                      AND practical_session_id=? AND institute_id=?
                    ORDER BY attempt_no DESC,id DESC LIMIT 1 FOR UPDATE",
                    [$sessionId,$institute]);
                if ((!$previous && $attempt!==1)
                    || ($previous && ($previous['status']!=='rejected'
                        || $attempt!==(int)$previous['attempt_no']+1))) {
                    throw new HttpException('PORTAL_ATTEMPT_CHAIN',
                        'A new attempt requires a previous externally rejected receipt and next attempt number.',409);
                }
                $q=$this->db->prepare("INSERT INTO portal_submissions
                    (institute_id,submission_type,cycle_id,practical_session_id,
                     attempt_no,submitted_at,status,expected_records,submitted_records,
                     portal_reference,payload_sha256,response_evidence_reference)
                     VALUES (?,'practical_marks',?,?,?,?,'submitted',?,?,?,?,?)");
                $q->execute([$institute,$session['cycle_id'],$sessionId,$attempt,
                    $submittedAt,$expected,$submitted,$portalRef,$hash,$evidence]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'exam.portal.practical.submitted_receipt',
                    'portal_submissions',$id,[
                        'practical_session_id'=>$sessionId,'source_id'=>$sourceId,
                        'attempt_no'=>$attempt,'portal_reference'=>$portalRef,
                        'response_evidence_reference'=>$evidence,
                        'payload_sha256'=>$hash,'government_verified'=>false
                    ]);
                return $id;
            });
        } catch(PDOException $e) {
            if ((int)($e->errorInfo[1]??0)===1062) {
                throw new HttpException('PORTAL_ATTEMPT_EXISTS',
                    'Duplicate portal attempt or reference.',409);
            }
            throw $e;
        }
    }

    public function acknowledge(AuthContext $actor,int $institute,int $receiptId,
        string $status,?string $acceptedAt,int $sourceId,
        string $responseEvidence,string $review): void
    {
        self::principal($actor);
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'exam.portal.submit',function () use (
            $actor,$institute,$receiptId,$status,$acceptedAt,$sourceId,
            $responseEvidence,$review,$tx
        ): void {
            $this->source($institute,$sourceId);
            $receipt=$this->row("SELECT * FROM portal_submissions
                WHERE id=? AND institute_id=? AND submission_type='practical_marks'
                FOR UPDATE",[$receiptId,$institute]);
            if (!$receipt) throw new HttpException('NOT_FOUND','Practical portal receipt not found.',404);
            if ($receipt['status']!=='submitted') {
                throw new HttpException('PORTAL_RECEIPT_FROZEN',
                    'Only a pending submitted receipt may record an external response.',409);
            }
            if ($status==='accepted'
                && ($acceptedAt===null || $acceptedAt<$receipt['submitted_at']
                    || (int)$receipt['expected_records']!==(int)$receipt['submitted_records'])) {
                throw new HttpException('PORTAL_ACCEPTANCE_INVALID',
                    'Source-backed accepted receipt must reconcile record count and acceptance time.',409);
            }
            $this->db->prepare('UPDATE portal_submissions SET status=?,
                accepted_at=?,response_evidence_reference=?
                WHERE id=? AND institute_id=? AND status=\'submitted\'')
                ->execute([$status,$acceptedAt,$responseEvidence,$receiptId,$institute]);
            $tx->audit($actor,$institute,'exam.portal.practical.response_record',
                'portal_submissions',$receiptId,[
                    'external_response_status'=>$status,'accepted_at'=>$acceptedAt,
                    'review_reference'=>$review,'source_id'=>$sourceId,
                    'response_evidence_reference'=>$responseEvidence,
                    'government_verified'=>false
                ]);
        });
    }
}
