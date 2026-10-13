<?php
declare(strict_types=1);

namespace App\Modules\Institutes;

use App\Core\Transaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Auth\Application\InstituteUserService;
use App\Modules\Auth\Infrastructure\PasswordHasher;
use PDO;
use PDOException;

/** Platform-only institute bootstrap, without fabricating affiliation approvals. */
final readonly class PlatformInstituteService
{
    public function __construct(private PDO $db) {}

    private static function authorize(AuthContext $actor, string $permission): void
    {
        $actor->requirePermission($permission);
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Platform Developer authority required.',403);
        }
    }

    private function platformAudit(AuthContext $actor,string $action,array $details): void
    {
        $q=$this->db->prepare('INSERT INTO auth_audit_log
            (institute_id,user_id,event_type,event_data_json,occurred_at)
            VALUES (NULL,?,?,?,UTC_TIMESTAMP())');
        $q->execute([$actor->userId,$action,json_encode($details,JSON_THROW_ON_ERROR)]);
    }

    private function actorIsActive(AuthContext $actor): void
    {
        $q=$this->db->prepare("SELECT id FROM auth_users
            WHERE id=? AND institute_id IS NULL AND status='active' FOR UPDATE");
        $q->execute([$actor->userId]);
        if ($q->fetchColumn()===false) {
            throw new HttpException('FORBIDDEN','Active platform account required.',403);
        }
    }

    public function create(AuthContext $actor,string $name,string $misCode,string $address,
        ?string $district,?string $management): int
    {
        self::authorize($actor,'platform.institute.create');
        try {
            return (new Transaction($this->db))->run(function () use (
                $actor,$name,$misCode,$address,$district,$management
            ): int {
                $this->actorIsActive($actor);
                $q=$this->db->prepare('SELECT id FROM institute WHERE mis_code=? FOR UPDATE');
                $q->execute([$misCode]);
                if ($q->fetchColumn()!==false) {
                    throw new HttpException('INSTITUTE_EXISTS','Institute MIS identity already registered.',409);
                }
                $q=$this->db->prepare('INSERT INTO institute
                    (name,mis_code,state_ut_code,address,district_name,management_type)
                    VALUES (?,?,\'BR\',?,?,?)');
                $q->execute([$name,$misCode,$address,$district,$management]);
                $id=(int)$this->db->lastInsertId();
                $this->platformAudit($actor,'platform.institute.create',
                    ['institute_id'=>$id,'mis_code'=>$misCode]);
                return $id;
            });
        } catch (PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('INSTITUTE_EXISTS','Institute MIS identity already registered.',409);
            }
            throw $e;
        }
    }

    public function principal(AuthContext $actor,int $institute,string $employeeCode,
        string $name,string $designation,string $joinedOn,string $appointmentReference,
        string $username,string $password): array
    {
        self::authorize($actor,'platform.user.provision_principal');
        $hash=PasswordHasher::hash(InstituteUserService::validatePassword($password));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,119}$/D',$username)) {
            throw new HttpException('INVALID_USERNAME','Valid 3–120 character username required.',422);
        }
        try {
            return (new Transaction($this->db))->run(function () use (
                $actor,$institute,$employeeCode,$name,$designation,$joinedOn,$appointmentReference,$username,$hash
            ): array {
                $this->actorIsActive($actor);
                $q=$this->db->prepare('SELECT id FROM institute WHERE id=? FOR UPDATE');
                $q->execute([$institute]);
                if ($q->fetchColumn()===false) throw new HttpException('NOT_FOUND','Institute not found.',404);
                $q=$this->db->prepare("SELECT id FROM staff WHERE institute_id=?
                    AND principal_assignment IN('principal','acting_principal') AND left_on IS NULL
                    FOR UPDATE");
                $q->execute([$institute]);
                if ($q->fetchColumn()!==false) {
                    throw new HttpException('PRINCIPAL_EXISTS','Existing Principal appointment must be handed over through a reviewed workflow.',409);
                }
                $q=$this->db->prepare("SELECT id FROM auth_user_roles WHERE institute_id=?
                    AND role_code='principal' AND valid_to IS NULL FOR UPDATE");
                $q->execute([$institute]);
                if ($q->fetchColumn()!==false) {
                    throw new HttpException('PRINCIPAL_EXISTS','Active Principal account exists for this institute.',409);
                }
                $q=$this->db->prepare('SELECT id FROM auth_users WHERE institute_id=? AND username=? FOR UPDATE');
                $q->execute([$institute,$username]);
                if ($q->fetchColumn()!==false) throw new HttpException('USERNAME_EXISTS','Institute username exists.',409);
                $q=$this->db->prepare('SELECT id FROM staff WHERE institute_id=? AND employee_code=? FOR UPDATE');
                $q->execute([$institute,$employeeCode]);
                if ($q->fetchColumn()!==false) throw new HttpException('STAFF_CODE_EXISTS','Employee code exists.',409);
                $q=$this->db->prepare('INSERT INTO staff
                    (institute_id,employee_code,full_name,staff_category,designation,principal_assignment,joined_on)
                    VALUES (?,?,?,\'non_teaching\',?,\'principal\',?)');
                $q->execute([$institute,$employeeCode,$name,$designation,$joinedOn]);
                $staffId=(int)$this->db->lastInsertId();
                $q=$this->db->prepare('INSERT INTO auth_users
                    (institute_id,username,password_hash,must_change_password) VALUES (?,?,?,1)');
                $q->execute([$institute,$username,$hash]);
                $userId=(int)$this->db->lastInsertId();
                $q=$this->db->prepare('INSERT INTO auth_staff_links (institute_id,user_id,staff_id) VALUES (?,?,?)');
                $q->execute([$institute,$userId,$staffId]);
                $q=$this->db->prepare("INSERT INTO auth_user_roles
                    (institute_id,user_id,role_id,role_code,scope,staff_link_user_id,granted_by,valid_from)
                    SELECT ?,?,id,'principal','institute',?, ?,UTC_TIMESTAMP()
                    FROM auth_roles WHERE code='principal' AND scope='institute'");
                $q->execute([$institute,$userId,$userId,$actor->userId]);
                if ($q->rowCount()!==1) {
                    throw new HttpException('ROLE_UNAVAILABLE','Principal role has not been seeded.',409);
                }
                $this->platformAudit($actor,'platform.principal.provision',[
                    'institute_id'=>$institute,'user_id'=>$userId,'staff_id'=>$staffId,
                    'appointment_reference'=>$appointmentReference
                ]);
                return ['user_id'=>$userId,'staff_id'=>$staffId,'must_change_password'=>true];
            });
        } catch (PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('PRINCIPAL_CONFLICT','Principal, account or employee identity already registered.',409);
            }
            throw $e;
        }
    }

    public function list(AuthContext $actor,int $after,int $size): array
    {
        self::authorize($actor,'platform.institute.view');
        $q=$this->db->prepare('SELECT id,name,mis_code,state_ut_code,district_name,management_type,
            contact_email,contact_phone,created_at
            FROM institute WHERE id>? ORDER BY id LIMIT ?');
        $q->bindValue(1,$after,PDO::PARAM_INT);
        $q->bindValue(2,$size,PDO::PARAM_INT);
        $q->execute();
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        return ['institutes'=>$rows,
            'next_after_id'=>count($rows)===$size?(int)$rows[count($rows)-1]['id']:null];
    }
}
