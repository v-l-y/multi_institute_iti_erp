<?php
declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Institute ITSUs only; Global Trade and Global Shift remain authoritative. */
final readonly class ItsuSetupService
{
    public function __construct(private PDO $db) {}

    private function row(string $sql,array $params): ?array
    {
        $q=$this->db->prepare($sql);
        $q->execute($params);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function attachOfficialSource(AuthContext $actor,int $institute,
        int $documentId,?string $localReference): int
    {
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'institute.affiliation.manage',function () use (
            $actor,$institute,$documentId,$localReference,$tx
        ): int {
            $doc=$this->row('SELECT id,status,authority,state_ut_code FROM global_regulatory_documents
                WHERE id=? FOR UPDATE',[$documentId]);
            if (!$doc || $doc['status']!=='published'
                || !in_array($doc['authority'],['DGT','NCVET','STATE_UT','SSDEC'],true)
                || ($doc['state_ut_code']!==null && $doc['state_ut_code']!=='BR')) {
                throw new HttpException('DOCUMENT_NOT_REVIEWED','Reviewed official document for Bihar required.',409);
            }
            if ($this->row('SELECT id FROM regulatory_sources
                WHERE institute_id=? AND global_document_id=? FOR UPDATE',
                [$institute,$documentId])!==null) {
                throw new HttpException('SOURCE_EXISTS','Document already linked to institute.',409);
            }
            $q=$this->db->prepare('INSERT INTO regulatory_sources
                (institute_id,global_document_id,local_evidence_reference)
                VALUES (?,?,?)');
            $q->execute([$institute,$documentId,$localReference]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'regulatory.source.attach','regulatory_sources',$id,
                ['global_document_id'=>$documentId]);
            return $id;
        });
    }

    public function tradeUnit(AuthContext $actor,int $institute,int $tradeId,int $unitNo): int
    {
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'tradeunit.manage',function () use (
            $actor,$institute,$tradeId,$unitNo,$tx
        ): int {
            $trade=$this->row("SELECT t.id FROM trades t JOIN trade_master_revisions r
                ON r.trade_id=t.id AND r.status='active'
                    AND r.effective_from<=UTC_DATE()
                    AND (r.effective_to IS NULL OR r.effective_to>=UTC_DATE())
                JOIN global_regulatory_documents d ON d.id=r.source_document_id AND d.status='published'
                WHERE t.id=? FOR UPDATE",[$tradeId]);
            if ($trade===null) {
                throw new HttpException('GLOBAL_TRADE_NOT_ACTIVE','Active reviewed Global Trade required.',409);
            }
            if ($this->row('SELECT id FROM trade_units
                WHERE institute_id=? AND trade_id=? AND unit_no=? FOR UPDATE',
                [$institute,$tradeId,$unitNo])!==null) {
                throw new HttpException('TRADE_UNIT_EXISTS','Institute Trade Unit already exists.',409);
            }
            $q=$this->db->prepare('INSERT INTO trade_units (institute_id,trade_id,unit_no)
                VALUES (?,?,?)');
            $q->execute([$institute,$tradeId,$unitNo]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'itsu.trade_unit.create','trade_units',$id,
                ['global_trade_id'=>$tradeId,'unit_no'=>$unitNo]);
            return $id;
        });
    }

    public function shiftRevision(AuthContext $actor,int $institute,int $unit,
        int $globalShiftId,int $sourceId,string $reference,int $capacity,
        string $starts,string $affiliationStatus,string $trainingMode): int
    {
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'tradeunit.manage',function () use (
                $actor,$institute,$unit,$globalShiftId,$sourceId,$reference,$capacity,
                $starts,$affiliationStatus,$trainingMode,$tx
            ): int {
                $tradeUnit=$this->row('SELECT id,trade_id FROM trade_units
                    WHERE id=? AND institute_id=? FOR UPDATE',[$unit,$institute]);
                if (!$tradeUnit) {
                    throw new HttpException('NOT_FOUND','Institute Trade Unit not found.',404);
                }
                $shift=$this->row("SELECT id,shift_no FROM global_shifts
                    WHERE id=? AND status='active' AND effective_from<=?
                      AND (effective_to IS NULL OR effective_to>=?) FOR UPDATE",
                    [$globalShiftId,$starts,$starts]);
                if (!$shift) {
                    throw new HttpException('GLOBAL_SHIFT_NOT_ACTIVE','Active Global Shift revision required.',409);
                }
                $source=$this->row('SELECT s.id,d.authority,d.status FROM regulatory_sources s
                    JOIN global_regulatory_documents d ON d.id=s.global_document_id
                    WHERE s.id=? AND s.institute_id=? FOR UPDATE',[$sourceId,$institute]);
                if (!$source || $source['status']!=='published'
                    || $source['authority']!=='DGT') {
                    throw new HttpException('OFFICIAL_SOURCE_REQUIRED','Reviewed source-linked DGT affiliation order required.',409);
                }
                if ((int)$shift['shift_no']===3 && $trainingMode!=='DST') {
                    throw new HttpException('INVALID_SHIFT_MODE','Global Shift III requires DST mode.',422);
                }
                $previous=$this->row('SELECT id,shift_no,valid_from FROM trade_unit_shifts
                    WHERE trade_unit_id=? AND shift_no=? AND institute_id=?
                      AND valid_to IS NULL FOR UPDATE',
                    [$unit,$shift['shift_no'],$institute]);
                if ($previous) {
                    if ($starts<=$previous['valid_from']) {
                        throw new HttpException('REVISION_DATE','New ITSU approval date must follow current version.',409);
                    }
                    $ends=(new \DateTimeImmutable($starts))->modify('-1 day')->format('Y-m-d');
                    $this->db->prepare("UPDATE trade_unit_shifts
                        SET valid_to=?,status='inactive'
                        WHERE id=? AND institute_id=? AND valid_to IS NULL")
                        ->execute([$ends,$previous['id'],$institute]);
                }
                $q=$this->db->prepare('INSERT INTO trade_unit_shifts
                    (institute_id,trade_unit_id,trade_id,global_shift_id,shift_no,
                     status,training_mode,affiliation_status,sanctioned_capacity,
                     valid_from,source_id,approval_reference)
                    VALUES (?,?,?,?,?,\'active\',?,?,?,?,?,?)');
                $q->execute([$institute,$unit,$tradeUnit['trade_id'],$globalShiftId,
                    $shift['shift_no'],$trainingMode,$affiliationStatus,$capacity,
                    $starts,$sourceId,$reference]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'itsu.shift.revision','trade_unit_shifts',$id,[
                    'global_trade_id'=>(int)$tradeUnit['trade_id'],
                    'global_shift_id'=>$globalShiftId,'trade_unit_id'=>$unit,
                    'sanctioned_capacity'=>$capacity,'source_id'=>$sourceId,
                    'superseded_id'=>$previous['id']??null
                ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('ITSU_CONFLICT','Current ITSU mapping or revision already exists.',409);
            }
            throw $e;
        }
    }
}
