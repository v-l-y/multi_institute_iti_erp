<?php
declare(strict_types=1);

namespace App\Modules\Regulatory;

use App\Core\Transaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/**
 * Platform register of externally sourced documents; publication is a
 * recorded human attestation, never an auto-verification of government data.
 */
final readonly class RegulatoryDocumentService
{
    public function __construct(private PDO $db) {}

    private static function authorize(AuthContext $actor): void
    {
        $actor->requirePermission('platform.regulatory.manage');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Platform Developer required.',403);
        }
    }

    private function audit(AuthContext $actor,string $event,array $data): void
    {
        $q=$this->db->prepare('INSERT INTO auth_audit_log
            (institute_id,user_id,event_type,event_data_json,occurred_at)
            VALUES (NULL,?,?,?,UTC_TIMESTAMP())');
        $q->execute([$actor->userId,$event,json_encode($data,JSON_THROW_ON_ERROR)]);
    }

    public function draft(AuthContext $actor,array $data): int
    {
        self::authorize($actor);
        try {
            return (new Transaction($this->db))->run(function () use ($actor,$data): int {
                $q=$this->db->prepare("SELECT id FROM auth_users
                    WHERE id=? AND institute_id IS NULL AND status='active' FOR UPDATE");
                $q->execute([$actor->userId]);
                if ($q->fetchColumn()===false) {
                    throw new HttpException('FORBIDDEN','Active platform user required.',403);
                }
                $q=$this->db->prepare('SELECT id FROM global_regulatory_documents
                    WHERE authority=? AND reference_no=? AND issued_on=? FOR UPDATE');
                $q->execute([$data['authority'],$data['reference_no'],$data['issued_on']]);
                if ($q->fetchColumn()!==false) {
                    throw new HttpException('DOCUMENT_EXISTS','Regulatory document reference already registered.',409);
                }
                if ($data['supersedes_id']!==null) {
                    $q=$this->db->prepare('SELECT id,status FROM global_regulatory_documents
                        WHERE id=? AND authority=? FOR UPDATE');
                    $q->execute([$data['supersedes_id'],$data['authority']]);
                    if ($q->fetch(PDO::FETCH_ASSOC)===false) {
                        throw new HttpException('INVALID_REVISION','Superseded document must exist under the same authority.',409);
                    }
                }
                $q=$this->db->prepare('INSERT INTO global_regulatory_documents
                    (authority,source_system,state_ut_code,document_type,title,
                     reference_no,issued_on,source_url,evidence_reference,
                     sha256,supersedes_id,status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,\'draft\')');
                $q->execute([
                    $data['authority'],$data['source_system'],$data['state_ut_code'],
                    $data['document_type'],$data['title'],$data['reference_no'],
                    $data['issued_on'],$data['source_url'],$data['evidence_reference'],
                    $data['sha256'],$data['supersedes_id']
                ]);
                $id=(int)$this->db->lastInsertId();
                $this->audit($actor,'platform.regulatory.document.draft',[
                    'document_id'=>$id,'authority'=>$data['authority'],
                    'reference_no'=>$data['reference_no'],'issued_on'=>$data['issued_on'],
                    'sha256'=>$data['sha256']
                ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('DOCUMENT_EXISTS','Regulatory document reference already registered.',409);
            }
            throw $e;
        }
    }

    public function publish(AuthContext $actor,int $id,string $reviewReference): void
    {
        self::authorize($actor);
        (new Transaction($this->db))->run(function () use ($actor,$id,$reviewReference): void {
            $q=$this->db->prepare("SELECT id FROM auth_users
                WHERE id=? AND institute_id IS NULL AND status='active' FOR UPDATE");
            $q->execute([$actor->userId]);
            if ($q->fetchColumn()===false) throw new HttpException('FORBIDDEN','Active platform user required.',403);
            $q=$this->db->prepare('SELECT * FROM global_regulatory_documents WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            $doc=$q->fetch(PDO::FETCH_ASSOC);
            if ($doc===false) throw new HttpException('NOT_FOUND','Regulatory document not found.',404);
            if ($doc['status']!=='draft') {
                throw new HttpException('DOCUMENT_STATE','Only a draft can be reviewed and published.',409);
            }
            if (!$doc['sha256'] || (!$doc['source_url'] && !$doc['evidence_reference'])) {
                throw new HttpException('SOURCE_INCOMPLETE','Source reference and verified evidence hash are required.',409);
            }
            if ($doc['supersedes_id']!==null) {
                $q=$this->db->prepare('SELECT status FROM global_regulatory_documents WHERE id=? FOR UPDATE');
                $q->execute([$doc['supersedes_id']]);
                if ($q->fetchColumn()!=='published') {
                    throw new HttpException('SOURCE_PREDECESSOR','Predecessor must be a published document.',409);
                }
            }
            // Explicitly reviewed publication; no government portal is contacted.
            $q=$this->db->prepare("UPDATE global_regulatory_documents
                SET status='published',reviewed_by=?,reviewed_at=UTC_TIMESTAMP()
                WHERE id=? AND status='draft'");
            $q->execute([$actor->username,$id]);
            $this->audit($actor,'platform.regulatory.document.publish',[
                'document_id'=>$id,'review_reference'=>$reviewReference,
                'sha256'=>$doc['sha256'],'supersedes_id'=>$doc['supersedes_id']
            ]);
        });
    }

    public function list(AuthContext $actor,int $after,int $size): array
    {
        self::authorize($actor);
        $q=$this->db->prepare('SELECT id,authority,source_system,state_ut_code,document_type,
            title,reference_no,issued_on,source_url,evidence_reference,sha256,
            supersedes_id,status,reviewed_by,reviewed_at
            FROM global_regulatory_documents WHERE id>? ORDER BY id LIMIT ?');
        $q->bindValue(1,$after,PDO::PARAM_INT);
        $q->bindValue(2,$size,PDO::PARAM_INT);
        $q->execute();
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        return ['documents'=>$rows,
            'next_after_id'=>count($rows)===$size?(int)$rows[count($rows)-1]['id']:null];
    }
}
