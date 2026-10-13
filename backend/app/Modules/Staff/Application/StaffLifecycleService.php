<?php
declare(strict_types=1);

namespace App\Modules\Staff\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

/** Staff master corrections, sourced qualifications and safe employment exit. */
final readonly class StaffLifecycleService
{
    public function __construct(private PDO $db) {}

    private function staff(int $id,int $institute): array
    {
        $q=$this->db->prepare('SELECT * FROM staff WHERE id=? AND institute_id=? FOR UPDATE');
        $q->execute([$id,$institute]);
        return $q->fetch(PDO::FETCH_ASSOC)
            ?: throw new HttpException('NOT_FOUND','Staff identity not found in this institute.',404);
    }

    public function update(AuthContext $actor,int $institute,int $id,string $name,string $designation): void
    {
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'staff.manage',function () use ($actor,$institute,$id,$name,$designation,$tx): void {
            $old=$this->staff($id,$institute);
            if ($old['left_on']!==null) {
                throw new HttpException('STAFF_INACTIVE','Exited staff records need a separate historical correction.',409);
            }
            $this->db->prepare('UPDATE staff SET full_name=?,designation=? WHERE id=? AND institute_id=?')
                ->execute([$name,$designation,$id,$institute]);
            $tx->audit($actor,$institute,'staff.profile.update','staff',$id,
                ['previous_name'=>$old['full_name'],'previous_designation'=>$old['designation']]);
        });
    }

    public function qualification(AuthContext $actor,int $institute,int $staffId,
        string $kind,string $specialization,string $reference,int $experience): int
    {
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'staff.manage',function () use (
            $actor,$institute,$staffId,$kind,$specialization,$reference,$experience,$tx
        ): int {
            $staff=$this->staff($staffId,$institute);
            if ($staff['left_on']!==null) throw new HttpException('STAFF_INACTIVE','Staff has exited.',409);
            $q=$this->db->prepare('SELECT id FROM staff_qualifications
                WHERE staff_id=? AND institute_id=? AND certificate_reference=? LIMIT 1 FOR UPDATE');
            $q->execute([$staffId,$institute,$reference]);
            if ($q->fetchColumn()!==false) {
                throw new HttpException('QUALIFICATION_EXISTS','Certificate reference already recorded for this staff.',409);
            }
            $q=$this->db->prepare('INSERT INTO staff_qualifications
                (institute_id,staff_id,qualification_type,specialization,certificate_reference,
                 experience_months,verified_by,verified_on) VALUES (?,?,?,?,?,?,NULL,NULL)');
            $q->execute([$institute,$staffId,$kind,$specialization,$reference,$experience]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'staff.qualification.record','staff_qualifications',$id,
                ['staff_id'=>$staffId,'certificate_reference'=>$reference]);
            return $id;
        });
    }

    public function exit(AuthContext $actor,int $institute,int $staffId,
        string $leftOn,string $reason): void
    {
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'staff.manage',function () use (
            $actor,$institute,$staffId,$leftOn,$reason,$tx
        ): void {
            $staff=$this->staff($staffId,$institute);
            if ($staff['left_on']!==null) throw new HttpException('STAFF_INACTIVE','Staff exit is already recorded.',409);
            if ($leftOn<$staff['joined_on']) {
                throw new HttpException('INVALID_EXIT_DATE','Exit cannot precede joining.',422);
            }
            if ($staff['principal_assignment']!=='none') {
                throw new HttpException('PRINCIPAL_HANDOVER_REQUIRED','Official Principal assignment must be transferred separately.',409);
            }
            $q=$this->db->prepare("SELECT id FROM instructor_mappings
                WHERE staff_id=? AND institute_id=? AND status='active' LIMIT 1 FOR UPDATE");
            $q->execute([$staffId,$institute]);
            if ($q->fetchColumn()!==false) {
                throw new HttpException('INSTRUCTOR_MAPPING_ACTIVE','Release reviewed NIMS instructor mapping before staff exit.',409);
            }
            $account=$this->db->prepare('SELECT user_id FROM auth_staff_links
                WHERE staff_id=? AND institute_id=? FOR UPDATE');
            $account->execute([$staffId,$institute]);
            $userId=$account->fetchColumn();
            if ($userId!==false && (int)$userId===$actor->userId) {
                throw new HttpException('SELF_CHANGE','Use a separate authorized account for employment exit.',409);
            }
            if ($userId!==false) {
                $admin=$this->db->prepare("SELECT role_code FROM auth_user_roles WHERE user_id=?
                    AND institute_id=? AND valid_to IS NULL FOR UPDATE");
                $admin->execute([$userId,$institute]);
                foreach ($admin->fetchAll(PDO::FETCH_COLUMN) as $role) {
                    if ($role==='administrator' && !in_array('principal',$actor->roles,true)) {
                        throw new HttpException('PRINCIPAL_AUTHORITY_REQUIRED','Only Principal can exit current Administrator.',403);
                    }
                }
            }
            $this->db->prepare('UPDATE staff SET left_on=? WHERE id=? AND institute_id=?')
                ->execute([$leftOn,$staffId,$institute]);
            if ($userId!==false) {
                $this->db->prepare("UPDATE auth_users SET status='disabled',locked_until=NULL
                    WHERE id=? AND institute_id=?")->execute([$userId,$institute]);
                $this->db->prepare('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP()
                    WHERE user_id=? AND institute_id=? AND revoked_at IS NULL')->execute([$userId,$institute]);
                $this->db->prepare('UPDATE auth_password_resets SET used_at=UTC_TIMESTAMP()
                    WHERE user_id=? AND institute_id=? AND used_at IS NULL')->execute([$userId,$institute]);
            }
            $tx->audit($actor,$institute,'staff.employment.exit','staff',$staffId,
                ['left_on'=>$leftOn,'reference'=>$reason,'linked_account_disabled'=>$userId!==false]);
        });
    }
}
