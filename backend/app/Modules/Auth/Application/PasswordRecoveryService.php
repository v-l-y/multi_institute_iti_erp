<?php
declare(strict_types=1);
namespace App\Modules\Auth\Application;

use App\Core\PrivateFiles;
use App\Core\Transaction;
use App\Http\HttpException;
use App\Modules\Auth\Infrastructure\PasswordHasher;
use PDO;

/** Explicitly enabled local outbox adapter. Does not send SMTP or expose tokens. */
final readonly class PasswordRecoveryService
{
    public function __construct(private PDO $db,private PrivateFiles $outbox) {}
    public function request(string $username,?int $institute): void
    {
        $token=bin2hex(random_bytes(32));
        $object=bin2hex(random_bytes(16)).'.json';
        $created=false;
        try {
            (new Transaction($this->db))->run(function () use ($username,$institute,$token,$object,&$created): void {
                $q=$this->db->prepare("SELECT id,username FROM auth_users WHERE username=? AND tenant_key=? AND status='active' FOR UPDATE");
                $q->execute([$username,$institute??0]);
                $user=$q->fetch(PDO::FETCH_ASSOC);
                if (!$user) return;
                $q=$this->db->prepare('SELECT id FROM auth_password_resets WHERE user_id=? AND issued_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) LIMIT 1');
                $q->execute([$user['id']]);
                if ($q->fetchColumn()) return; // Same generic response; avoid mailbox flooding.
                $this->db->prepare('UPDATE auth_password_resets SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$user['id']]);
                $this->db->prepare('INSERT INTO auth_password_resets (institute_id,user_id,token_hash,issued_at,expires_at) VALUES (?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))')
                    ->execute([$institute,$user['id'],hash('sha256',$token)]);
                $this->outbox->write($object,json_encode(['delivery_mode'=>'local_outbox','user_id'=>(int)$user['id'],
                    'username'=>$user['username'],'institute_id'=>$institute,'reset_token'=>$token,'expires_in'=>1800],JSON_THROW_ON_ERROR));
                $created=true;
                $this->db->prepare("INSERT INTO auth_audit_log (user_id,institute_id,event_type,event_data_json,occurred_at) VALUES (?,?,'password_recovery_requested','{}',UTC_TIMESTAMP())")
                    ->execute([$user['id'],$institute]);
            });
        } catch (\Throwable $error) {
            if ($created) $this->outbox->remove($object);
            throw $error;
        }
    }
    public function reset(string $token,string $password): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new HttpException('INVALID_RESET_TOKEN','Invalid or expired recovery token.',401);
        $password=InstituteUserService::validatePassword($password);
        $hash=PasswordHasher::hash($password);
        (new Transaction($this->db))->run(function () use ($token,$hash): void {
            $q=$this->db->prepare('SELECT user_id FROM auth_password_resets WHERE token_hash=?');
            $q->execute([hash('sha256',$token)]);
            $userId=$q->fetchColumn();
            if (!$userId) throw new HttpException('INVALID_RESET_TOKEN','Invalid or expired recovery token.',401);
            $q=$this->db->prepare("SELECT id,institute_id FROM auth_users WHERE id=? AND status='active' FOR UPDATE");
            $q->execute([$userId]); $user=$q->fetch(PDO::FETCH_ASSOC);
            $q=$this->db->prepare('SELECT id FROM auth_password_resets WHERE token_hash=? AND user_id=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
            $q->execute([hash('sha256',$token),$userId]);
            if (!$user || !$q->fetchColumn()) throw new HttpException('INVALID_RESET_TOKEN','Invalid or expired recovery token.',401);
            $this->db->prepare('UPDATE auth_users SET password_hash=?,must_change_password=0,failed_attempts=0,locked_until=NULL WHERE id=?')->execute([$hash,$userId]);
            $this->db->prepare('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL')->execute([$userId]);
            $this->db->prepare('UPDATE auth_password_resets SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
            $this->db->prepare("INSERT INTO auth_audit_log (user_id,institute_id,event_type,event_data_json,occurred_at) VALUES (?,?,'password_recovered','{}',UTC_TIMESTAMP())")->execute([$userId,$user['institute_id']]);
        });
    }
}
