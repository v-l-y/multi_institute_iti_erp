<?php
declare(strict_types=1);
namespace App\Modules\Auth\Application;

use App\Http\HttpException;
use App\Http\Request;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Auth\Infrastructure\RecoveryDelivery;
use App\Modules\Auth\Infrastructure\PasswordHasher;
use PDO;
use Throwable;

final readonly class RecoveryEmailService
{
    public function __construct(private PDO $db,private RecoveryDelivery $delivery) {}

    public function status(AuthContext $context): array
    {
        $q=$this->db->prepare('SELECT email,verified_at FROM auth_recovery_emails WHERE user_id=?');
        $q->execute([$context->userId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        return ['verified'=>(bool)($row && $row['verified_at']),
            'email'=>$row&&$row['verified_at']?$row['email']:null];
    }

    public function start(AuthContext $actor,Request $request): void
    {
        $email=$request->body['email']??null;
        $pwd=$request->body['current_password']??null;
        if(!is_string($email)||strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL) ||
           !is_string($pwd)||strlen($pwd)>72||$pwd==='') {
            throw new HttpException('INVALID_INPUT','Valid email and current password required.',422);
        }
        $q=$this->db->prepare('SELECT password_hash FROM auth_users WHERE id=? AND status=\'active\'');
        $q->execute([$actor->userId]);
        $hash=$q->fetchColumn();
        if(!$hash||!password_verify($pwd,$hash)) {
            throw new HttpException('UNAUTHORIZED','Password verification failed.',401);
        }
        $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT email,verified_at FROM auth_recovery_emails WHERE user_id=? FOR UPDATE');
            $q->execute([$actor->userId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if($row && $row['verified_at']){
                throw new HttpException('RECOVERY_EMAIL_EXISTS',
                    'A verified recovery email already exists; contact an authorized administrator to change it.',409);
            }
            $this->db->prepare('INSERT INTO auth_recovery_emails
              (user_id,email,challenge_hash,challenge_expires_at,attempts)
              VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),0)
              ON DUPLICATE KEY UPDATE email=VALUES(email),challenge_hash=VALUES(challenge_hash),
              challenge_expires_at=VALUES(challenge_expires_at),attempts=0,verified_at=NULL')
              ->execute([$actor->userId,$email,PasswordHasher::hash($code)]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $this->delivery->send($email,'ITI ERP recovery email verification',
          "Your ITI ERP recovery email verification code is: ".$code.
          "\nThis code expires in 10 minutes. If you did not request it, ignore this message.");
    }

    public function confirm(AuthContext $actor,Request $request): void
    {
        $code=$request->body['verification_code']??null;
        if(!is_string($code)||!preg_match('/^[0-9]{6}$/D',$code)){
            throw new HttpException('INVALID_INPUT','Six-digit email verification code required.',422);
        }
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT * FROM auth_recovery_emails WHERE user_id=? FOR UPDATE');
            $q->execute([$actor->userId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row || $row['verified_at']!==null || $row['challenge_expires_at']===null ||
               strtotime($row['challenge_expires_at'].' UTC')<time() || (int)$row['attempts']>=5){
                throw new HttpException('INVALID_VERIFICATION','Verification expired.',401);
            }
            if(!password_verify($code,(string)$row['challenge_hash'])){
                $this->db->prepare('UPDATE auth_recovery_emails SET attempts=LEAST(5,attempts+1)
                  WHERE user_id=?')->execute([$actor->userId]);
                $this->db->commit();
                throw new HttpException('INVALID_VERIFICATION','Invalid verification code.',401);
            }
            $this->db->prepare('UPDATE auth_recovery_emails
              SET verified_at=UTC_TIMESTAMP(),challenge_hash=NULL,challenge_expires_at=NULL
              WHERE user_id=?')->execute([$actor->userId]);
            $this->db->prepare('INSERT INTO auth_audit_log
              (user_id,institute_id,event_type,event_data_json,occurred_at)
              VALUES (?,?,\'recovery_email_verified\',\'{}\',UTC_TIMESTAMP())')
              ->execute([$actor->userId,$actor->instituteId]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
