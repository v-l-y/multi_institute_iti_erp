<?php
declare(strict_types=1);
namespace App\Modules\Auth\Application;

use App\Core\Transaction;
use App\Http\HttpException;
use App\Modules\Auth\Infrastructure\PasswordHasher;
use App\Modules\Auth\Infrastructure\RecoveryDelivery;
use PDO;

/** Password reset tokens are sent only to an independently verified email. */
final readonly class PasswordRecoveryService
{
    public function __construct(private PDO $db,private RecoveryDelivery $delivery) {}

    public function request(string $username,?int $institute): void
    {
        $token=bin2hex(random_bytes(32));
        $destination=null;
        (new Transaction($this->db))->run(function()use($username,$institute,$token,&$destination): void {
            $q=$this->db->prepare("SELECT u.id,e.email FROM auth_users u
              INNER JOIN auth_recovery_emails e ON e.user_id=u.id AND e.verified_at IS NOT NULL
              WHERE u.username=? AND u.tenant_key=? AND u.status='active' FOR UPDATE");
            $q->execute([$username,$institute??0]);$user=$q->fetch(PDO::FETCH_ASSOC);
            if(!$user)return; // Indistinguishable response for unknown or unverified accounts.
            $q=$this->db->prepare('SELECT id FROM auth_password_resets
              WHERE user_id=? AND issued_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) LIMIT 1');
            $q->execute([$user['id']]);
            if($q->fetchColumn())return;
            $this->db->prepare('UPDATE auth_password_resets SET used_at=UTC_TIMESTAMP()
              WHERE user_id=? AND used_at IS NULL')->execute([$user['id']]);
            $this->db->prepare('INSERT INTO auth_password_resets
              (institute_id,user_id,token_hash,issued_at,expires_at)
              VALUES (?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))')
              ->execute([$institute,$user['id'],hash('sha256',$token)]);
            $this->db->prepare("INSERT INTO auth_audit_log
              (user_id,institute_id,event_type,event_data_json,occurred_at)
              VALUES (?,?,'password_recovery_requested','{}',UTC_TIMESTAMP())")
              ->execute([$user['id'],$institute]);
            $destination=$user['email'];
        });
        if($destination!==null){
            try{
                $this->delivery->send($destination,'ITI ERP password recovery',
                    "Your one-time password reset token is:\n".$token.
                    "\n\nEnter this token in ITI ERP Password Recovery and set a new password.".
                    "\nThe token expires in 30 minutes. Do not share it.");
            }catch(\Throwable $e){
                // Avoid revealing existence of a verified account via SMTP failures.
                error_log('[iti-erp] Password recovery email delivery failed.');
            }
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
