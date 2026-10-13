<?php
declare(strict_types=1);

namespace App\Modules\Admissions\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Local institute record of source-backed official intake-window revisions. */
final readonly class IntakeRevisionService
{
    public function __construct(private PDO $db) {}

    private function one(string $sql, array $params): ?array
    {
        $q=$this->db->prepare($sql);
        $q->execute($params);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function reviewedSource(int $institute, int $sourceId): array
    {
        $source=$this->one('SELECT d.id,d.status,d.sha256,d.issued_on
            FROM regulatory_sources r
            JOIN global_regulatory_documents d ON d.id=r.global_document_id
            WHERE r.id=? AND r.institute_id=? FOR UPDATE',[$sourceId,$institute]);
        if (!$source || $source['status']!=='published' || !$source['sha256']) {
            throw new HttpException('INTAKE_SOURCE_REQUIRED',
                'Published institute-linked official admission-window evidence required.',409);
        }
        return $source;
    }

    public function draft(AuthContext $actor, int $institute, int $intakeId,
        int $sourceId, string $code, string $opens, string $closes,
        string $approval, ?int $supersedes): int
    {
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'admission.intake.manage',function () use (
                $actor,$institute,$intakeId,$sourceId,$code,$opens,$closes,$approval,$supersedes,$tx
            ): int {
                $intake=$this->one('SELECT id FROM admission_intakes
                    WHERE id=? AND institute_id=? FOR UPDATE',[$intakeId,$institute]);
                if (!$intake) throw new HttpException('NOT_FOUND','Institute intake not found.',404);
                $this->reviewedSource($institute,$sourceId);
                if ($supersedes!==null) {
                    $old=$this->one('SELECT status FROM admission_intake_revisions
                        WHERE id=? AND intake_id=? AND institute_id=? FOR UPDATE',
                        [$supersedes,$intakeId,$institute]);
                    if (!$old || $old['status']!=='published') {
                        throw new HttpException('INVALID_PREDECESSOR',
                            'Only the current published revision of this intake may be superseded.',409);
                    }
                }
                $q=$this->db->prepare("INSERT INTO admission_intake_revisions
                    (institute_id,intake_id,source_id,revision_code,opens_on,closes_on,
                     approval_reference,supersedes_id,status)
                    VALUES (?,?,?,?,?,?,?,?,'draft')");
                $q->execute([$institute,$intakeId,$sourceId,$code,$opens,$closes,$approval,$supersedes]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'admission.intake.revision.draft',
                    'admission_intake_revisions',$id,[
                        'intake_id'=>$intakeId,'source_id'=>$sourceId,'supersedes_id'=>$supersedes
                    ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('INTAKE_REVISION_EXISTS','Admission intake revision code already exists.',409);
            }
            throw $e;
        }
    }

    public function publish(AuthContext $actor, int $institute, int $id,
        string $reviewReference): void
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_REQUIRED','Principal review is required.',403);
        }
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'admission.intake.manage',function () use (
            $actor,$institute,$id,$reviewReference,$tx
        ): void {
            $revision=$this->one('SELECT * FROM admission_intake_revisions
                WHERE id=? AND institute_id=? FOR UPDATE',[$id,$institute]);
            if (!$revision) throw new HttpException('NOT_FOUND','Intake revision not found.',404);
            if ($revision['status']!=='draft') {
                throw new HttpException('INTAKE_REVISION_FROZEN','Only draft revisions may be published.',409);
            }
            $this->reviewedSource($institute,(int)$revision['source_id']);
            if ($revision['supersedes_id']!==null) {
                $old=$this->one('SELECT id,status FROM admission_intake_revisions
                    WHERE id=? AND intake_id=? AND institute_id=? FOR UPDATE',[
                        $revision['supersedes_id'],$revision['intake_id'],$institute
                    ]);
                if (!$old || $old['status']!=='published') {
                    throw new HttpException('INVALID_PREDECESSOR','Published predecessor required.',409);
                }
                $this->db->prepare("UPDATE admission_intake_revisions
                    SET status='superseded' WHERE id=? AND institute_id=? AND status='published'")
                    ->execute([$old['id'],$institute]);
            } elseif ($this->one("SELECT id FROM admission_intake_revisions
                WHERE intake_id=? AND institute_id=? AND status='published' FOR UPDATE",
                [$revision['intake_id'],$institute])) {
                throw new HttpException('INTAKE_REVISION_EXISTS',
                    'Already published intake revision; provide supersedes_id.',409);
            }
            $this->db->prepare("UPDATE admission_intake_revisions
                SET status='published',published_on=UTC_DATE()
                WHERE id=? AND institute_id=? AND status='draft'")
                ->execute([$id,$institute]);
            $tx->audit($actor,$institute,'admission.intake.revision.publish',
                'admission_intake_revisions',$id,[
                    'intake_id'=>(int)$revision['intake_id'],
                    'source_id'=>(int)$revision['source_id'],
                    'supersedes_id'=>$revision['supersedes_id'],
                    'review_reference'=>$reviewReference
                ]);
        });
    }
}
