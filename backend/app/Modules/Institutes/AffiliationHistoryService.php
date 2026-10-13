<?php
declare(strict_types=1);

namespace App\Modules\Institutes;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Record a reviewed institute affiliation order without impersonating DGT. */
final readonly class AffiliationHistoryService
{
    public function __construct(private PDO $db) {}

    public function record(AuthContext $actor,int $institute,int $sourceId,
        string $changeType,string $approvedName,string $approvedAddress,
        string $status,string $order,string $effective): int
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_REQUIRED','Principal review required for affiliation history.',403);
        }
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'institute.affiliation.manage',function () use (
                $actor,$institute,$sourceId,$changeType,$approvedName,$approvedAddress,
                $status,$order,$effective,$tx
            ): int {
                $q=$this->db->prepare('SELECT d.status,d.authority,d.sha256
                    FROM regulatory_sources s JOIN global_regulatory_documents d
                      ON d.id=s.global_document_id
                    WHERE s.id=? AND s.institute_id=? FOR UPDATE');
                $q->execute([$sourceId,$institute]);
                $source=$q->fetch(PDO::FETCH_ASSOC);
                if (!$source || $source['status']!=='published'
                    || $source['authority']!=='DGT' || !$source['sha256']) {
                    throw new HttpException('AFFILIATION_SOURCE_REQUIRED',
                        'Institute-linked published DGT affiliation evidence required.',409);
                }
                $q=$this->db->prepare('SELECT id,effective_from,effective_to
                    FROM institute_affiliation_history
                    WHERE institute_id=? ORDER BY effective_from DESC LIMIT 1 FOR UPDATE');
                $q->execute([$institute]);
                $prior=$q->fetch(PDO::FETCH_ASSOC);
                if ($prior) {
                    if ($effective<=$prior['effective_from']) {
                        throw new HttpException('AFFILIATION_DATE',
                            'New order effective date must follow the latest recorded order.',409);
                    }
                    if ($prior['effective_to']!==null
                        && $prior['effective_to'] >= $effective) {
                        throw new HttpException('AFFILIATION_OVERLAP',
                            'Official affiliation periods may not overlap.',409);
                    }
                    if ($prior['effective_to']===null) {
                        $ends=(new \DateTimeImmutable($effective))->modify('-1 day')->format('Y-m-d');
                        $this->db->prepare('UPDATE institute_affiliation_history
                            SET effective_to=? WHERE id=? AND institute_id=?')
                            ->execute([$ends,$prior['id'],$institute]);
                    }
                }
                $q=$this->db->prepare('INSERT INTO institute_affiliation_history
                    (institute_id,source_id,change_type,approved_name,approved_address,
                     affiliation_status,affiliation_order_reference,effective_from)
                    VALUES (?,?,?,?,?,?,?,?)');
                $q->execute([$institute,$sourceId,$changeType,$approvedName,
                    $approvedAddress,$status,$order,$effective]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'institute.affiliation.record',
                    'institute_affiliation_history',$id,[
                        'source_id'=>$sourceId,'status'=>$status,
                        'effective_from'=>$effective,'supersedes_history_id'=>$prior['id']??null
                    ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('AFFILIATION_CONFLICT','Affiliation order already recorded.',409);
            }
            throw $e;
        }
    }
}
