<?php
declare(strict_types=1);

namespace App\Modules\Staff\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Local review of NIMS/IPRN and source-backed staff-to-ITSU assignments. */
final readonly class InstructorMappingService
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
            throw new HttpException('PRINCIPAL_REQUIRED','Principal must review instructor evidence.',403);
        }
    }

    private function source(int $institute,int $id): array
    {
        $row=$this->row('SELECT d.authority,d.source_system,d.status,d.sha256
            FROM regulatory_sources s JOIN global_regulatory_documents d
              ON d.id=s.global_document_id
            WHERE s.id=? AND s.institute_id=? FOR UPDATE',[$id,$institute]);
        if (!$row || $row['status']!=='published' || !$row['sha256']) {
            throw new HttpException('REVIEWED_SOURCE_REQUIRED',
                'Published institute-linked evidence with document hash required.',409);
        }
        return $row;
    }

    public function verifyQualification(AuthContext $actor,int $institute,
        int $qualificationId,int $sourceId,string $review): void
    {
        self::principal($actor);
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'staff.manage',function () use (
            $actor,$institute,$qualificationId,$sourceId,$review,$tx
        ): void {
            $this->source($institute,$sourceId);
            $qualification=$this->row('SELECT q.id,q.staff_id,q.verified_on
                FROM staff_qualifications q JOIN staff s
                  ON s.id=q.staff_id AND s.institute_id=q.institute_id
                WHERE q.id=? AND q.institute_id=? AND s.left_on IS NULL FOR UPDATE',
                [$qualificationId,$institute]);
            if (!$qualification) throw new HttpException('NOT_FOUND','Active staff qualification not found.',404);
            if ($qualification['verified_on']!==null) {
                throw new HttpException('QUALIFICATION_ALREADY_REVIEWED','Qualification already reviewed.',409);
            }
            $this->db->prepare('UPDATE staff_qualifications
                SET verified_by=?,verified_on=UTC_DATE()
                WHERE id=? AND institute_id=? AND verified_on IS NULL')
                ->execute([$actor->username,$qualificationId,$institute]);
            $tx->audit($actor,$institute,'staff.qualification.review',
                'staff_qualifications',$qualificationId,[
                    'source_id'=>$sourceId,'review_reference'=>$review,
                    'staff_id'=>(int)$qualification['staff_id']
                ]);
        });
    }

    public function verifyIpRn(AuthContext $actor,int $institute,int $staffId,
        int $sourceId,string $iprn,string $nimsReference,string $verifiedOn): void
    {
        self::principal($actor);
        $tx=new InstituteTransaction($this->db);
        try {
            $tx->run($actor,$institute,'instructor.mapping.manage',function () use (
                $actor,$institute,$staffId,$sourceId,$iprn,$nimsReference,$verifiedOn,$tx
            ): void {
                $source=$this->source($institute,$sourceId);
                if ($source['source_system']!=='NIMS') {
                    throw new HttpException('NIMS_SOURCE_REQUIRED',
                        'Reviewed NIMS source evidence is required for IPRN.',409);
                }
                $staff=$this->row('SELECT * FROM staff WHERE id=? AND institute_id=? FOR UPDATE',
                    [$staffId,$institute]);
                if (!$staff) throw new HttpException('NOT_FOUND','Staff record not found.',404);
                if ($staff['left_on']!==null || $staff['staff_category']!=='teaching'
                    || $verifiedOn<$staff['joined_on']) {
                    throw new HttpException('STAFF_NOT_ELIGIBLE',
                        'Active teaching staff and valid source verification date required.',409);
                }
                if ($staff['nims_verification']==='verified') {
                    throw new HttpException('IPRN_ALREADY_REVIEWED',
                        'Current NIMS identity cannot be silently replaced.',409);
                }
                $identity=$this->row('SELECT id FROM instructor_identities WHERE iprn=? FOR UPDATE',[$iprn]);
                if (!$identity) {
                    $this->db->prepare('INSERT INTO instructor_identities(iprn) VALUES (?)')
                        ->execute([$iprn]);
                    $identityId=(int)$this->db->lastInsertId();
                } else {
                    $identityId=(int)$identity['id'];
                }
                if ($staff['identity_id']!==null && (int)$staff['identity_id']!==$identityId) {
                    throw new HttpException('IPRN_CONFLICT',
                        'Existing instructor identity requires a reviewed correction.',409);
                }
                $other=$this->row('SELECT id FROM staff WHERE identity_id=?
                    AND left_on IS NULL AND id<>? FOR UPDATE',[$identityId,$staffId]);
                if ($other) {
                    throw new HttpException('IPRN_ACTIVE_ELSEWHERE',
                        'Active IPRN identity is already linked to another staff member.',409);
                }
                $this->db->prepare("UPDATE staff SET identity_id=?,nims_verification='verified',
                    verification_reference=?,verified_on=? WHERE id=? AND institute_id=?")
                    ->execute([$identityId,$nimsReference,$verifiedOn,$staffId,$institute]);
                $tx->audit($actor,$institute,'instructor.iprn.review','staff',$staffId,[
                    'identity_id'=>$identityId,'source_id'=>$sourceId,
                    'verification_reference'=>$nimsReference,'verified_on'=>$verifiedOn
                ]);
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('IPRN_CONFLICT','NIMS identity already registered.',409);
            }
            throw $e;
        }
    }

    public function assign(AuthContext $actor,int $institute,int $staffId,
        int $qualificationId,int $sourceId,string $mappingType,?int $itsu,
        ?string $subject,string $validFrom,string $review): int
    {
        self::principal($actor);
        $tx=new InstituteTransaction($this->db);
        try {
            return $tx->run($actor,$institute,'instructor.mapping.manage',function () use (
                $actor,$institute,$staffId,$qualificationId,$sourceId,$mappingType,
                $itsu,$subject,$validFrom,$review,$tx
            ): int {
                $this->source($institute,$sourceId);
                $staff=$this->row('SELECT * FROM staff WHERE id=? AND institute_id=? FOR UPDATE',
                    [$staffId,$institute]);
                if (!$staff || $staff['staff_category']!=='teaching'
                    || $staff['left_on']!==null || $staff['identity_id']===null
                    || $staff['nims_verification']!=='verified'
                    || $staff['verified_on']===null || $staff['verified_on']>$validFrom
                    || $staff['joined_on']>$validFrom) {
                    throw new HttpException('INSTRUCTOR_NOT_VERIFIED',
                        'Active NIMS-reviewed teaching identity required.',409);
                }
                $qualification=$this->row('SELECT id,certificate_reference,verified_on
                    FROM staff_qualifications
                    WHERE id=? AND staff_id=? AND institute_id=? FOR UPDATE',
                    [$qualificationId,$staffId,$institute]);
                if (!$qualification || $qualification['verified_on']===null) {
                    throw new HttpException('QUALIFICATION_NOT_REVIEWED',
                        'Reviewed staff qualification required.',409);
                }
                if ($mappingType==='trade') {
                    $unit=$this->row('SELECT id,status,affiliation_status,valid_from,valid_to
                        FROM trade_unit_shifts WHERE id=? AND institute_id=? FOR UPDATE',
                        [$itsu,$institute]);
                    if (!$unit || $unit['status']!=='active'
                        || $unit['affiliation_status']!=='approved'
                        || $unit['valid_from']>$validFrom
                        || ($unit['valid_to']!==null && $unit['valid_to']<$validFrom)) {
                        throw new HttpException('ITSU_NOT_APPROVED',
                            'Approved ITSU applicable on mapping date required.',409);
                    }
                }
                $old=$this->row("SELECT id FROM instructor_mappings
                    WHERE status='active' AND (staff_id=? OR identity_id=?"
                    .($mappingType==='trade'?" OR trade_unit_shift_id=?":"").')
                    LIMIT 1 FOR UPDATE',
                    $mappingType==='trade'
                        ? [$staffId,$staff['identity_id'],$itsu]
                        : [$staffId,$staff['identity_id']]);
                if ($old) {
                    throw new HttpException('MAPPING_EXISTS',
                        'Active staff, IPRN or ITSU mapping must first be released.',409);
                }
                $evidence=json_encode([
                    'qualification_id'=>$qualificationId,
                    'certificate_reference'=>$qualification['certificate_reference'],
                    'qualification_source_id'=>$sourceId,
                    'review_reference'=>$review
                ],JSON_THROW_ON_ERROR);
                $q=$this->db->prepare("INSERT INTO instructor_mappings
                    (institute_id,staff_id,mapping_type,identity_id,trade_unit_shift_id,
                     subject_code,valid_from,status,qualification_source_id,
                     qualification_check_json,qualification_checked_by,nims_reference)
                    VALUES (?,?,?,?,?,?,?,'active',?,?,?,?)");
                $q->execute([$institute,$staffId,$mappingType,$staff['identity_id'],
                    $itsu,$subject,$validFrom,$sourceId,$evidence,$actor->username,
                    $staff['verification_reference']]);
                $id=(int)$this->db->lastInsertId();
                $tx->audit($actor,$institute,'instructor.mapping.assign',
                    'instructor_mappings',$id,[
                        'staff_id'=>$staffId,'itsu_revision_id'=>$itsu,
                        'mapping_type'=>$mappingType,'qualification_id'=>$qualificationId,
                        'source_id'=>$sourceId,'valid_from'=>$validFrom
                    ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('MAPPING_EXISTS','An active instructor mapping already exists.',409);
            }
            throw $e;
        }
    }

    public function release(AuthContext $actor,int $institute,int $mappingId,
        string $validTo,int $sourceId,string $reference): void
    {
        self::principal($actor);
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'instructor.mapping.manage',function () use (
            $actor,$institute,$mappingId,$validTo,$sourceId,$reference,$tx
        ): void {
            $this->source($institute,$sourceId);
            $row=$this->row('SELECT valid_from,status FROM instructor_mappings
                WHERE id=? AND institute_id=? FOR UPDATE',[$mappingId,$institute]);
            if (!$row) throw new HttpException('NOT_FOUND','Instructor mapping not found.',404);
            if ($row['status']!=='active' || $validTo<$row['valid_from']) {
                throw new HttpException('INVALID_RELEASE',
                    'Only active mapping may be released on or after valid_from.',409);
            }
            $this->db->prepare("UPDATE instructor_mappings
                SET status='released',valid_to=? WHERE id=? AND institute_id=? AND status='active'")
                ->execute([$validTo,$mappingId,$institute]);
            $tx->audit($actor,$institute,'instructor.mapping.release',
                'instructor_mappings',$mappingId,[
                    'valid_to'=>$validTo,'source_id'=>$sourceId,'review_reference'=>$reference
                ]);
        });
    }
}
