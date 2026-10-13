<?php
declare(strict_types=1);

namespace App\Modules\Admissions\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Intake and final matrix remain traceable to external published sources. */
final readonly class SeatMatrixSetupService
{
    public function __construct(private PDO $db) {}

    private function row(string $sql,array $params): ?array
    {
        $q=$this->db->prepare($sql);
        $q->execute($params);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function source(int $institute,int $sourceId): array
    {
        $doc=$this->row('SELECT s.id,d.authority,d.status,d.document_type,d.sha256
            FROM regulatory_sources s JOIN global_regulatory_documents d
              ON d.id=s.global_document_id
            WHERE s.id=? AND s.institute_id=? FOR UPDATE',[$sourceId,$institute]);
        if (!$doc || $doc['status']!=='published' || $doc['authority']!=='DGT') {
            throw new HttpException('REGULATORY_SOURCE_REQUIRED','Published DGT source linked to institute required.',409);
        }
        return $doc;
    }

    public function intake(AuthContext $actor,int $institute,int $calendar,
        int $year,string $code,string $sessionPart): int
    {
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'batch.manage',function () use (
                $actor,$institute,$calendar,$year,$code,$sessionPart,$tx
            ): int {
                $record=$this->row('SELECT c.id,c.admission_year,c.status,d.status AS source_status
                    FROM cts_training_calendars c JOIN global_regulatory_documents d
                      ON d.id=c.source_document_id
                    WHERE c.id=? FOR UPDATE',[$calendar]);
                if (!$record || (int)$record['admission_year']!==$year
                    || $record['status']!=='published' || $record['source_status']!=='published') {
                    throw new HttpException('CALENDAR_UNVERIFIED','Published CTS calendar and source for admission year required.',409);
                }
                if ($this->row('SELECT id FROM admission_intakes
                    WHERE institute_id=? AND admission_year=? AND intake_code=? FOR UPDATE',
                    [$institute,$year,$code])) {
                    throw new HttpException('INTAKE_EXISTS','Intake code already registered for year.',409);
                }
                $q=$this->db->prepare('INSERT INTO admission_intakes
                    (institute_id,admission_year,calendar_id,intake_code,session_part)
                    VALUES (?,?,?,?,?)');
                $q->execute([$institute,$year,$calendar,$code,$sessionPart]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'admission.intake.create','admission_intakes',$id,
                    ['calendar_id'=>$calendar,'admission_year'=>$year,'session_part'=>$sessionPart]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('INTAKE_EXISTS','Intake already exists.',409);
            }
            throw $e;
        }
    }

    public function draft(AuthContext $actor,int $institute,int $intake,
        int $sourceId,string $version,string $portalReference,
        ?int $supersedesId): int
    {
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'seatmatrix.draft',function () use (
                $actor,$institute,$intake,$sourceId,$version,$portalReference,$supersedesId,$tx
            ): int {
                $record=$this->row('SELECT id,admission_year FROM admission_intakes
                    WHERE id=? AND institute_id=? FOR UPDATE',[$intake,$institute]);
                if (!$record) throw new HttpException('NOT_FOUND','Admission intake not found.',404);
                $this->source($institute,$sourceId);
                if ($supersedesId!==null) {
                    $prior=$this->row('SELECT id,status FROM seat_matrix_versions
                        WHERE id=? AND intake_id=? AND institute_id=? FOR UPDATE',
                        [$supersedesId,$intake,$institute]);
                    if (!$prior || $prior['status']!=='final') {
                        throw new HttpException('INVALID_PREDECESSOR','Only current finalized matrix for same intake can be superseded.',409);
                    }
                }
                $q=$this->db->prepare('INSERT INTO seat_matrix_versions
                    (institute_id,admission_year,intake_id,version_code,source_id,
                     portal_reference,supersedes_id,status)
                    VALUES (?,?,?,?,?,?,?,\'draft\')');
                $q->execute([$institute,$record['admission_year'],$intake,$version,
                    $sourceId,$portalReference,$supersedesId]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'seatmatrix.version.draft','seat_matrix_versions',$id,
                    ['intake_id'=>$intake,'source_id'=>$sourceId,'supersedes_id'=>$supersedesId]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('SEAT_MATRIX_EXISTS','Version code already exists.',409);
            }
            throw $e;
        }
    }

    public function entry(AuthContext $actor,int $institute,int $version,
        int $itsu,int $capacity,bool $admissionAllowed,string $portalItsu): int
    {
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'seatmatrix.draft',function () use (
                $actor,$institute,$version,$itsu,$capacity,$admissionAllowed,$portalItsu,$tx
            ): int {
                $matrix=$this->row("SELECT v.id,v.status,v.intake_id,
                    i.calendar_id,i.session_part,c.training_starts_on
                    FROM seat_matrix_versions v
                    JOIN admission_intakes i ON i.id=v.intake_id AND i.institute_id=v.institute_id
                    JOIN cts_training_calendars c ON c.id=i.calendar_id
                    WHERE v.id=? AND v.institute_id=? FOR UPDATE",[$version,$institute]);
                if (!$matrix) throw new HttpException('NOT_FOUND','Matrix draft not found.',404);
                if ($matrix['status']!=='draft') {
                    throw new HttpException('MATRIX_FROZEN','Only draft matrices accept entries.',409);
                }
                $shift=$this->row("SELECT s.id,s.shift_no,s.sanctioned_capacity,
                    s.affiliation_status,s.status,s.valid_from,s.valid_to
                    FROM trade_unit_shifts s
                    WHERE s.id=? AND s.institute_id=? FOR UPDATE",[$itsu,$institute]);
                if (!$shift || $shift['status']!=='active' || $shift['affiliation_status']!=='approved'
                    || $matrix['training_starts_on']<$shift['valid_from']
                    || ($shift['valid_to']!==null && $matrix['training_starts_on']>$shift['valid_to'])) {
                    throw new HttpException('ITSU_NOT_ELIGIBLE','Current approved ITSU revision required for calendar.',409);
                }
                if ($capacity>(int)$shift['sanctioned_capacity']) {
                    throw new HttpException('CAPACITY_LIMIT','Matrix capacity exceeds sanctioned ITSU capacity.',409);
                }
                $portal=$this->row('SELECT portal_shift_no FROM cts_portal_shift_mappings
                    WHERE calendar_id=? AND session_part=? AND physical_shift_no=?',
                    [$matrix['calendar_id'],$matrix['session_part'],$shift['shift_no']]);
                if (!$portal) {
                    throw new HttpException('SHIFT_MAPPING_REQUIRED','Official CTS portal-to-physical shift mapping required.',409);
                }
                if ($this->row('SELECT id FROM seat_matrix_entries
                    WHERE version_id=? AND trade_unit_shift_id=? AND institute_id=? FOR UPDATE',
                    [$version,$itsu,$institute])) {
                    throw new HttpException('MATRIX_ENTRY_EXISTS','ITSU already recorded in this matrix.',409);
                }
                $mapping=$this->row("SELECT m.id FROM instructor_mappings m
                    JOIN staff st ON st.id=m.staff_id AND st.institute_id=m.institute_id
                    WHERE m.institute_id=? AND m.trade_unit_shift_id=?
                      AND m.mapping_type='trade' AND m.status='active'
                      AND m.valid_from<=? AND (m.valid_to IS NULL OR m.valid_to>=?)
                      AND st.nims_verification='verified' AND st.left_on IS NULL
                    LIMIT 1 FOR UPDATE",[$institute,$itsu,
                    $matrix['training_starts_on'],$matrix['training_starts_on']]);
                if ($admissionAllowed && !$mapping) {
                    throw new HttpException('INSTRUCTOR_NOT_VERIFIED','Verified active NIMS instructor mapping required.',409);
                }
                $q=$this->db->prepare('INSERT INTO seat_matrix_entries
                    (institute_id,version_id,intake_id,trade_unit_shift_id,calendar_id,
                     session_part,physical_shift_no,portal_shift_no,seat_capacity,
                     admission_allowed,instructor_mapping_id,instructor_mapping_satisfied,
                     portal_itsu_reference)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$institute,$version,$matrix['intake_id'],$itsu,
                    $matrix['calendar_id'],$matrix['session_part'],$shift['shift_no'],
                    $portal['portal_shift_no'],$capacity,$admissionAllowed?1:0,
                    $mapping['id']??null,$mapping?1:0,$portalItsu]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'seatmatrix.entry.draft','seat_matrix_entries',$id,
                    ['version_id'=>$version,'itsu_id'=>$itsu,'capacity'=>$capacity,
                        'admission_allowed'=>$admissionAllowed]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('MATRIX_ENTRY_EXISTS','Duplicate ITSU matrix entry.',409);
            }
            throw $e;
        }
    }

    public function finalize(AuthContext $actor,int $institute,int $version,
        string $reviewReference): void
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_AUTHORITY_REQUIRED','Principal final-seat-matrix review required.',403);
        }
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'seatmatrix.publish',function () use (
            $actor,$institute,$version,$reviewReference,$tx
        ): void {
            $matrix=$this->row('SELECT * FROM seat_matrix_versions
                WHERE id=? AND institute_id=? FOR UPDATE',[$version,$institute]);
            if (!$matrix) throw new HttpException('NOT_FOUND','Matrix not found.',404);
            if ($matrix['status']!=='draft') {
                throw new HttpException('MATRIX_FROZEN','Matrix is not a draft.',409);
            }
            $document=$this->source($institute,(int)$matrix['source_id']);
            $kind=strtolower($document['document_type']);
            if (!str_contains($kind,'final') || !str_contains($kind,'seat')
                || !$document['sha256']) {
                throw new HttpException('FINAL_SOURCE_REQUIRED','Reviewed final seat-matrix source with SHA-256 required.',409);
            }
            $q=$this->db->prepare('SELECT COUNT(*) FROM seat_matrix_entries
                WHERE version_id=? AND institute_id=?');
            $q->execute([$version,$institute]);
            if ((int)$q->fetchColumn()===0) {
                throw new HttpException('MATRIX_EMPTY','Final matrix must contain actual entries.',409);
            }
            if ($matrix['supersedes_id']!==null) {
                $before=$this->row('SELECT id,status FROM seat_matrix_versions
                    WHERE id=? AND intake_id=? AND institute_id=? FOR UPDATE',[
                    $matrix['supersedes_id'],$matrix['intake_id'],$institute
                ]);
                if (!$before || $before['status']!=='final') {
                    throw new HttpException('INVALID_PREDECESSOR','Current final predecessor required.',409);
                }
                $this->db->prepare("UPDATE seat_matrix_versions
                    SET status='superseded' WHERE id=? AND institute_id=? AND status='final'")
                    ->execute([$matrix['supersedes_id'],$institute]);
            } elseif ($this->row("SELECT id FROM seat_matrix_versions
                WHERE intake_id=? AND institute_id=? AND status='final' FOR UPDATE",
                [$matrix['intake_id'],$institute])) {
                throw new HttpException('FINAL_MATRIX_EXISTS','Final matrix already exists; use a referenced revision.',409);
            }
            $this->db->prepare("UPDATE seat_matrix_versions
                SET status='final',published_on=UTC_DATE()
                WHERE id=? AND institute_id=? AND status='draft'")
                ->execute([$version,$institute]);
            $tx->audit($actor,$institute,'seatmatrix.version.finalize','seat_matrix_versions',$version,
                ['source_id'=>$matrix['source_id'],'review_reference'=>$reviewReference,
                 'supersedes_id'=>$matrix['supersedes_id']]);
        });
    }
}
