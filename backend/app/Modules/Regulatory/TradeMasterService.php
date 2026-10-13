<?php
declare(strict_types=1);

namespace App\Modules\Regulatory;

use App\Core\Transaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** DGT source-bound Global Trade identity and immutable revision chain. */
final readonly class TradeMasterService
{
    public function __construct(private PDO $db) {}

    private static function allow(AuthContext $actor): void
    {
        $actor->requirePermission('platform.regulatory.manage');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Platform Developer required.',403);
        }
    }

    private function audit(AuthContext $actor,string $action,array $details): void
    {
        $q=$this->db->prepare('INSERT INTO auth_audit_log
            (institute_id,user_id,event_type,event_data_json,occurred_at)
            VALUES (NULL,?,?,?,UTC_TIMESTAMP())');
        $q->execute([$actor->userId,$action,json_encode($details,JSON_THROW_ON_ERROR)]);
    }

    private function activeActor(AuthContext $actor): void
    {
        $q=$this->db->prepare("SELECT id FROM auth_users
            WHERE id=? AND institute_id IS NULL AND status='active' FOR UPDATE");
        $q->execute([$actor->userId]);
        if ($q->fetchColumn()===false) throw new HttpException('FORBIDDEN','Active platform user required.',403);
    }

    private function source(int $id): array
    {
        $q=$this->db->prepare('SELECT id,authority,status,issued_on FROM global_regulatory_documents WHERE id=? FOR UPDATE');
        $q->execute([$id]);
        $source=$q->fetch(PDO::FETCH_ASSOC);
        if (!$source || $source['status']!=='published' || $source['authority']!=='DGT') {
            throw new HttpException('DGT_SOURCE_REQUIRED','Reviewed published DGT source required.',409);
        }
        return $source;
    }

    public function register(AuthContext $actor,string $dgtCode,int $documentId,
        string $version,string $name,string $type,string $effectiveFrom): array
    {
        self::allow($actor);
        try {
            return (new Transaction($this->db))->run(function () use (
                $actor,$dgtCode,$documentId,$version,$name,$type,$effectiveFrom
            ): array {
                $this->activeActor($actor);
                $this->source($documentId);
                $q=$this->db->prepare('SELECT id FROM trades WHERE dgt_code=? FOR UPDATE');
                $q->execute([$dgtCode]);
                if ($q->fetchColumn()!==false) {
                    throw new HttpException('TRADE_EXISTS','Global trade code already registered.',409);
                }
                $this->db->prepare('INSERT INTO trades(dgt_code) VALUES (?)')->execute([$dgtCode]);
                $id=(int)$this->db->lastInsertId();
                $revision=$this->insertRevision($id,$documentId,$version,$name,$type,$effectiveFrom);
                $this->audit($actor,'platform.trade.register',[
                    'trade_id'=>$id,'revision_id'=>$revision,'dgt_code'=>$dgtCode,
                    'source_document_id'=>$documentId
                ]);
                return ['trade_id'=>$id,'revision_id'=>$revision,'status'=>'draft'];
            });
        } catch (PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('TRADE_EXISTS','Trade identity or revision already exists.',409);
            }
            throw $e;
        }
    }

    private function insertRevision(int $trade,int $document,string $version,
        string $name,string $type,string $starts): int
    {
        $q=$this->db->prepare('INSERT INTO trade_master_revisions
            (trade_id,source_document_id,version_code,official_name,trade_type,effective_from,status)
            VALUES (?,?,?,?,?,?,\'draft\')');
        $q->execute([$trade,$document,$version,$name,$type,$starts]);
        return (int)$this->db->lastInsertId();
    }

    public function draftRevision(AuthContext $actor,int $tradeId,int $documentId,
        string $version,string $name,string $type,string $from): int
    {
        self::allow($actor);
        try {
            return (new Transaction($this->db))->run(function () use (
                $actor,$tradeId,$documentId,$version,$name,$type,$from
            ): int {
                $this->activeActor($actor);
                $q=$this->db->prepare('SELECT id FROM trades WHERE id=? FOR UPDATE');
                $q->execute([$tradeId]);
                if ($q->fetchColumn()===false) throw new HttpException('NOT_FOUND','Global trade not found.',404);
                $this->source($documentId);
                $q=$this->db->prepare('SELECT id FROM trade_master_revisions
                    WHERE trade_id=? AND version_code=? FOR UPDATE');
                $q->execute([$tradeId,$version]);
                if ($q->fetchColumn()!==false) {
                    throw new HttpException('TRADE_REVISION_EXISTS','Version code already registered.',409);
                }
                $id=$this->insertRevision($tradeId,$documentId,$version,$name,$type,$from);
                $this->audit($actor,'platform.trade.revision.draft',[
                    'trade_id'=>$tradeId,'revision_id'=>$id,'source_document_id'=>$documentId
                ]);
                return $id;
            });
        } catch (PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('TRADE_REVISION_EXISTS','Trade revision code already exists.',409);
            }
            throw $e;
        }
    }

    public function activate(AuthContext $actor,int $revisionId,string $reviewReference): void
    {
        self::allow($actor);
        (new Transaction($this->db))->run(function () use ($actor,$revisionId,$reviewReference): void {
            $this->activeActor($actor);
            $q=$this->db->prepare('SELECT trade_id FROM trade_master_revisions WHERE id=?');
            $q->execute([$revisionId]);
            $tradeId=$q->fetchColumn();
            if ($tradeId===false) throw new HttpException('NOT_FOUND','Trade revision not found.',404);
            $this->db->prepare('SELECT id FROM trades WHERE id=? FOR UPDATE')->execute([$tradeId]);
            $q=$this->db->prepare('SELECT * FROM trade_master_revisions WHERE id=? FOR UPDATE');
            $q->execute([$revisionId]);
            $revision=$q->fetch(PDO::FETCH_ASSOC);
            if ($revision['status']!=='draft') {
                throw new HttpException('REVISION_STATE','Only draft Trade revisions can be activated.',409);
            }
            $this->source((int)$revision['source_document_id']);
            $q=$this->db->prepare("SELECT id,effective_from FROM trade_master_revisions
                WHERE trade_id=? AND status='active' FOR UPDATE");
            $q->execute([$tradeId]);
            $old=$q->fetch(PDO::FETCH_ASSOC);
            if ($old!==false) {
                if ($revision['effective_from']<=$old['effective_from']) {
                    throw new HttpException('REVISION_DATE','Revision cannot predate current active Trade version.',409);
                }
                $end=(new \DateTimeImmutable($revision['effective_from']))->modify('-1 day')->format('Y-m-d');
                $this->db->prepare("UPDATE trade_master_revisions
                    SET status='superseded',effective_to=? WHERE id=? AND trade_id=?")
                    ->execute([$end,$old['id'],$tradeId]);
            }
            $this->db->prepare("UPDATE trade_master_revisions SET status='active'
                WHERE id=? AND trade_id=? AND status='draft'")->execute([$revisionId,$tradeId]);
            $this->audit($actor,'platform.trade.revision.activate',[
                'trade_id'=>(int)$tradeId,'revision_id'=>$revisionId,
                'superseded_revision_id'=>$old===false?null:(int)$old['id'],
                'review_reference'=>$reviewReference
            ]);
        });
    }

    public function list(AuthContext $actor,int $after,int $size): array
    {
        self::allow($actor);
        $q=$this->db->prepare("SELECT t.id,t.dgt_code,r.id AS revision_id,
            r.version_code,r.official_name,r.trade_type,r.status,r.effective_from,
            r.effective_to,r.source_document_id
            FROM trades t LEFT JOIN trade_master_revisions r
              ON r.trade_id=t.id AND r.status='active'
            WHERE t.id>? ORDER BY t.id LIMIT ?");
        $q->bindValue(1,$after,PDO::PARAM_INT);
        $q->bindValue(2,$size,PDO::PARAM_INT);
        $q->execute();
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        return ['trades'=>$rows,'next_after_id'=>count($rows)===$size?(int)$rows[count($rows)-1]['id']:null];
    }
}
