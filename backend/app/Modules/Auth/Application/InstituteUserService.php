<?php
declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Auth\Infrastructure\PasswordHasher;
use PDO;

/**
 * Institute account lifecycle. Does not provision the platform Developer
 * or Principal identities: those require their own platform authority.
 */
final readonly class InstituteUserService
{
    public function __construct(private PDO $db) {}

    private function one(string $sql, array $parameters): ?array
    {
        $query=$this->db->prepare($sql);
        $query->execute($parameters);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function requireRoleAuthorization(AuthContext $actor,string $role): void
    {
        if ($role==='administrator') {
            $actor->requirePermission('auth.user.grant_administrator');
            if (!in_array('principal',$actor->roles,true)) {
                throw new HttpException('FORBIDDEN','Principal authority required for Administrator accounts.',403);
            }
        } elseif ($role==='instructor') {
            $actor->requirePermission('auth.user.manage_staff_accounts');
        } elseif ($role==='student') {
            $actor->requirePermission('auth.user.manage_student_accounts');
        } else {
            throw new HttpException('INVALID_ROLE','Only Institute Administrator, Instructor and Student may be provisioned here.',422);
        }
    }

    public static function validatePassword(mixed $password): string
    {
        if (!is_string($password) || strlen($password)<12 || strlen($password)>72
            || str_contains($password,"\0")) {
            throw new HttpException('INVALID_PASSWORD','Temporary password must contain 12–72 bytes without NUL.',422);
        }
        return $password;
    }

    public function create(AuthContext $actor,int $institute,string $role,int $identity,
        string $username,string $temporaryPassword): int
    {
        self::requireRoleAuthorization($actor,$role);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,119}$/D',$username)) {
            throw new HttpException('INVALID_USERNAME','Username must be 3–120 ASCII letters, numbers or ._- characters.',422);
        }
        $secret=self::validatePassword($temporaryPassword);
        $hashed=PasswordHasher::hash($secret);
        $tx=new InstituteTransaction($this->db);
        $permission=$role==='administrator'?'auth.user.grant_administrator':
            ($role==='instructor'?'auth.user.manage_staff_accounts':'auth.user.manage_student_accounts');
        return $tx->run($actor,$institute,$permission,function () use (
            $actor,$institute,$role,$identity,$username,$hashed,$tx
        ): int {
            $table=$role==='student'?'students':'staff';
            $subject=$this->one("SELECT * FROM $table WHERE id=? AND institute_id=? FOR UPDATE",
                [$identity,$institute]);
            if ($subject===null) {
                throw new HttpException('NOT_FOUND','Linked institute identity not found.',404);
            }
            if ($role!=='student' && ($subject['left_on']!==null
                || ($role==='instructor' && $subject['staff_category']!=='teaching'))) {
                throw new HttpException('INVALID_STAFF','Current eligible staff identity is required.',409);
            }
            if ($role==='administrator' && !in_array('principal',$actor->roles,true)) {
                throw new HttpException('FORBIDDEN','Principal authority required.',403);
            }
            if ($this->one('SELECT id FROM auth_users WHERE institute_id=? AND username=? FOR UPDATE',[$institute,$username])) {
                throw new HttpException('USERNAME_EXISTS','Username already belongs to an account in this institute.',409);
            }
            $link=$role==='student'?'auth_student_links':'auth_staff_links';
            $key=$role==='student'?'student_id':'staff_id';
            if ($this->one("SELECT user_id FROM $link WHERE institute_id=? AND $key=? FOR UPDATE",[$institute,$identity])) {
                throw new HttpException('IDENTITY_LINKED','Linked identity already has an account.',409);
            }
            if ($role==='administrator') {
                // Institute lock serializes competing Principal requests.
                $current=$this->one("SELECT ar.id FROM auth_user_roles ar
                  WHERE ar.institute_id=? AND ar.role_code='administrator'
                    AND ar.valid_to IS NULL LIMIT 1 FOR UPDATE",[$institute]);
                if ($current!==null) {
                    throw new HttpException('ADMINISTRATOR_EXISTS','Revoke the previous Administrator assignment before assigning a successor.',409);
                }
            }
            $r=$this->one('SELECT id FROM auth_roles WHERE code=? AND scope=\'institute\' LIMIT 1',[$role]);
            if ($r===null) {
                throw new HttpException('ROLE_UNAVAILABLE','Reviewed institute role is not seeded.',409);
            }
            $q=$this->db->prepare('INSERT INTO auth_users
              (institute_id,username,password_hash,must_change_password)
              VALUES (?,?,?,1)');
            $q->execute([$institute,$username,$hashed]);
            $id=(int)$this->db->lastInsertId();
            $q=$this->db->prepare("INSERT INTO $link (institute_id,user_id,$key) VALUES (?,?,?)");
            $q->execute([$institute,$id,$identity]);
            $q=$this->db->prepare('INSERT INTO auth_user_roles
              (institute_id,user_id,role_id,role_code,scope,
               staff_link_user_id,student_link_user_id,granted_by,valid_from)
              VALUES (?,?,?,?,\'institute\',?,?,?,UTC_TIMESTAMP())');
            $q->execute([$institute,$id,$r['id'],$role,
                $role==='student'?null:$id,$role==='student'?$id:null,$actor->userId]);
            $tx->audit($actor,$institute,'auth.user.provision','auth_users',$id,
                ['role'=>$role,'linked_identity_id'=>$identity]);
            return $id;
        });
    }

    /**
     * Disable access without deleting identity, role or security history.
     * Administrator replacement must explicitly revoke the earlier role.
     */
    public function disable(AuthContext $actor,int $institute,int $userId): void
    {
        $actor->requirePermission('auth.user.view');
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'auth.user.view',function () use ($actor,$institute,$userId,$tx): void {
            if ($userId===$actor->userId) {
                throw new HttpException('SELF_CHANGE','Self-disable is not allowed.',409);
            }
            $user=$this->one('SELECT id,status FROM auth_users WHERE id=? AND institute_id=? FOR UPDATE',[$userId,$institute]);
            if ($user===null) throw new HttpException('NOT_FOUND','Institute account not found.',404);
            $role=$this->one('SELECT role_code FROM auth_user_roles WHERE user_id=? AND institute_id=? AND valid_to IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$userId,$institute]);
            if ($role!==null) self::requireRoleAuthorization($actor,$role['role_code']);
            else {
                // An account without a current role may be disabled by Principal only.
                if (!in_array('principal',$actor->roles,true)) {
                    throw new HttpException('FORBIDDEN','Principal authority required.',403);
                }
            }
            $this->db->prepare("UPDATE auth_users SET status='disabled',locked_until=NULL
                WHERE id=? AND institute_id=?")->execute([$userId,$institute]);
            $this->db->prepare('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP()
                WHERE user_id=? AND institute_id=? AND revoked_at IS NULL')->execute([$userId,$institute]);
            $this->db->prepare('UPDATE auth_password_resets SET used_at=UTC_TIMESTAMP()
                WHERE user_id=? AND institute_id=? AND used_at IS NULL')->execute([$userId,$institute]);
            $tx->audit($actor,$institute,'auth.user.disable','auth_users',$userId,[]);
        });
    }

    public function revokeRole(AuthContext $actor,int $institute,int $userId): void
    {
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'auth.user.view',function () use ($actor,$institute,$userId,$tx): void {
            if ($actor->userId===$userId) {
                throw new HttpException('SELF_CHANGE','Self-revocation is prohibited.',409);
            }
            $user=$this->one('SELECT id,status FROM auth_users WHERE id=? AND institute_id=? FOR UPDATE',[$userId,$institute]);
            if ($user===null) throw new HttpException('NOT_FOUND','Institute account not found.',404);
            $role=$this->one('SELECT id,role_code FROM auth_user_roles WHERE user_id=? AND institute_id=? AND valid_to IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$userId,$institute]);
            if ($role===null) throw new HttpException('ROLE_NOT_ACTIVE','No current role assignment exists.',409);
            self::requireRoleAuthorization($actor,$role['role_code']);
            $this->db->prepare('UPDATE auth_user_roles SET
                valid_to=GREATEST(UTC_TIMESTAMP(),DATE_ADD(valid_from,INTERVAL 1 SECOND))
                WHERE id=? AND institute_id=? AND valid_to IS NULL')->execute([$role['id'],$institute]);
            $this->db->prepare("UPDATE auth_users SET status='disabled' WHERE id=? AND institute_id=?")->execute([$userId,$institute]);
            $this->db->prepare('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP()
                WHERE user_id=? AND institute_id=? AND revoked_at IS NULL')->execute([$userId,$institute]);
            $this->db->prepare('UPDATE auth_password_resets SET used_at=UTC_TIMESTAMP()
                WHERE user_id=? AND institute_id=? AND used_at IS NULL')->execute([$userId,$institute]);
            $tx->audit($actor,$institute,'auth.user.role.revoke','auth_user_roles',
                (int)$role['id'],['user_id'=>$userId,'role'=>$role['role_code']]);
        });
    }

    public function resetPassword(AuthContext $actor,int $institute,int $userId,string $temporaryPassword): void
    {
        $actor->requirePermission('auth.password.reset_user');
        $hash=PasswordHasher::hash(self::validatePassword($temporaryPassword));
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'auth.password.reset_user',function () use (
            $actor,$institute,$userId,$hash,$tx
        ): void {
            if ($userId===$actor->userId) {
                throw new HttpException('SELF_CHANGE','Use own change-password workflow.',409);
            }
            $user=$this->one('SELECT id,status FROM auth_users WHERE id=? AND institute_id=? FOR UPDATE',[$userId,$institute]);
            if ($user===null) throw new HttpException('NOT_FOUND','Institute account not found.',404);
            if ($user['status']!=='active') throw new HttpException('ACCOUNT_INACTIVE','Only active institute accounts may be reset.',409);
            $role=$this->one('SELECT role_code FROM auth_user_roles WHERE user_id=? AND institute_id=?
                AND valid_from<=UTC_TIMESTAMP() AND (valid_to IS NULL OR valid_to>UTC_TIMESTAMP())
                ORDER BY id DESC LIMIT 1 FOR UPDATE',[$userId,$institute]);
            if ($role===null) throw new HttpException('ROLE_NOT_ACTIVE','Active institute role required.',409);
            if ($role['role_code']==='administrator') {
                self::requireRoleAuthorization($actor,'administrator');
            } else {
                self::requireRoleAuthorization($actor,$role['role_code']);
            }
            $this->db->prepare('UPDATE auth_users SET password_hash=?,must_change_password=1,
                failed_attempts=0,locked_until=NULL WHERE id=? AND institute_id=?')
                ->execute([$hash,$userId,$institute]);
            $this->db->prepare('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP()
                WHERE user_id=? AND institute_id=? AND revoked_at IS NULL')->execute([$userId,$institute]);
            $this->db->prepare('UPDATE auth_password_resets SET used_at=UTC_TIMESTAMP()
                WHERE user_id=? AND institute_id=? AND used_at IS NULL')->execute([$userId,$institute]);
            $tx->audit($actor,$institute,'auth.user.password.reset','auth_users',$userId,[]);
        });
    }

    public function list(AuthContext $actor,int $institute,int $after,int $size): array
    {
        $actor->requirePermission('auth.user.view');
        $actor->requireInstitute($institute);
        $q=$this->db->prepare("SELECT u.id,u.username,u.status,u.must_change_password,
            u.failed_attempts,u.locked_until,r.role_code,sl.staff_id,st.student_id
            FROM auth_users u
            LEFT JOIN auth_user_roles r ON r.user_id=u.id AND r.institute_id=u.institute_id
              AND r.valid_from<=UTC_TIMESTAMP() AND (r.valid_to IS NULL OR r.valid_to>UTC_TIMESTAMP())
            LEFT JOIN auth_staff_links sl ON sl.user_id=u.id AND sl.institute_id=u.institute_id
            LEFT JOIN auth_student_links st ON st.user_id=u.id AND st.institute_id=u.institute_id
            WHERE u.institute_id=? AND u.id>? ORDER BY u.id LIMIT ?");
        $q->bindValue(1,$institute,PDO::PARAM_INT);
        $q->bindValue(2,$after,PDO::PARAM_INT);
        $q->bindValue(3,$size,PDO::PARAM_INT);
        $q->execute();
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        return ['users'=>$rows,'next_after_id'=>count($rows)===$size?(int)$rows[count($rows)-1]['id']:null];
    }
}
