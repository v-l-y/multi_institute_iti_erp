<?php
declare(strict_types=1);
namespace App\Modules\Auth\Application;

use App\Http\HttpException;
use App\Http\Request;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Auth\Infrastructure\Totp;
use PDO;
use Throwable;

final readonly class MfaService
{
    public function __construct(private PDO $db) {}

    private function credential(AuthContext $actor, Request $request): void
    {
        $pwd=$request->body['current_password']??null;
        if (!is_string($pwd) || $pwd==='' || strlen($pwd)>72) {
            throw new HttpException('INVALID_INPUT','Current password required.',422);
        }
        $q=$this->db->prepare('SELECT password_hash FROM auth_users WHERE id=? AND status=\'active\' FOR UPDATE');
        $q->execute([$actor->userId]);
        $hash=$q->fetchColumn();
        if (!$hash || !password_verify($pwd,$hash)) {
            throw new HttpException('UNAUTHORIZED','Password verification failed.',401);
        }
    }

    public function status(AuthContext $actor): array
    {
        $q=$this->db->prepare('SELECT verified_at,pending_at FROM auth_mfa_credentials WHERE user_id=?');
        $q->execute([$actor->userId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $q=$this->db->prepare('SELECT COUNT(*) FROM auth_mfa_recovery_codes WHERE user_id=?');
        $q->execute([$actor->userId]);
        return ['enabled'=>(bool)($row && $row['verified_at']),
            'pending'=>(bool)($row && $row['pending_at'] && !$row['verified_at']),
            'recovery_codes_remaining'=>(int)$q->fetchColumn()];
    }

    public function enroll(AuthContext $actor,Request $request): array
    {
        $this->db->beginTransaction();
        try {
            $this->credential($actor,$request);
            $q=$this->db->prepare('SELECT verified_at FROM auth_mfa_credentials WHERE user_id=? FOR UPDATE');
            $q->execute([$actor->userId]);
            if ($q->fetchColumn()) {
                throw new HttpException('MFA_ALREADY_ENABLED','Disable the current authenticator before re-enrollment.',409);
            }
            $secret=random_bytes(20);
            $cipher=Totp::seal($secret);
            $q=$this->db->prepare("INSERT INTO auth_mfa_credentials(user_id,pending_cipher,pending_at)
              VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE
              pending_cipher=VALUES(pending_cipher),pending_at=UTC_TIMESTAMP()");
            $q->execute([$actor->userId,$cipher]);
            $this->db->commit();
            $encoded=Totp::base32($secret);
            $label=rawurlencode('ITI ERP:'.$actor->username);
            return [
                'secret'=>$encoded,
                'otpauth_uri'=>'otpauth://totp/'.$label.'?secret='.$encoded.
                    '&issuer='.rawurlencode('ITI ERP').'&algorithm=SHA1&digits=6&period=30',
                'expires_in'=>600
            ];
        } catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function confirm(AuthContext $actor,Request $request): array
    {
        $code=$request->body['otp_code']??null;
        if(!is_string($code)||!preg_match('/^[0-9]{6}$/D',$code)) {
            throw new HttpException('INVALID_INPUT','Six-digit authenticator code required.',422);
        }
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT * FROM auth_mfa_credentials WHERE user_id=? FOR UPDATE');
            $q->execute([$actor->userId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row || $row['verified_at']!==null || !$row['pending_cipher'] ||
                !$row['pending_at'] ||
                strtotime($row['pending_at'].' UTC') < time()-600) {
                throw new HttpException('MFA_ENROLLMENT_EXPIRED','Restart authenticator enrollment.',409);
            }
            $counter=Totp::matchCounter(Totp::open($row['pending_cipher']),$code);
            if($counter===null)throw new HttpException('INVALID_MFA','Invalid authenticator code.',401);
            $this->db->prepare('UPDATE auth_mfa_credentials
              SET secret_cipher=pending_cipher,pending_cipher=NULL,pending_at=NULL,
                  verified_at=UTC_TIMESTAMP(),last_counter=? WHERE user_id=?')
              ->execute([$counter,$actor->userId]);
            $codes=[];
            for($i=0;$i<8;$i++){
                $recovery=bin2hex(random_bytes(6));
                $this->db->prepare('INSERT INTO auth_mfa_recovery_codes(user_id,code_hash) VALUES (?,?)')
                  ->execute([$actor->userId,hash('sha256',$recovery)]);
                $codes[]=$recovery;
            }
            // A session created before MFA activation must not remain usable.
            $this->db->prepare('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP()
              WHERE user_id=? AND id<>? AND revoked_at IS NULL')
              ->execute([$actor->userId,$actor->sessionId]);
            $this->db->prepare('INSERT INTO auth_audit_log
              (user_id,institute_id,event_type,event_data_json,occurred_at)
              VALUES (?,?,\'mfa_enabled\',\'{}\',UTC_TIMESTAMP())')
              ->execute([$actor->userId,$actor->instituteId]);
            $this->db->commit();
            return ['enabled'=>true,'recovery_codes'=>$codes];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function disable(AuthContext $actor,Request $request): void
    {
        $code=$request->body['otp_code']??null;
        $recovery=$request->body['recovery_code']??null;
        $this->db->beginTransaction();
        try{
            $this->credential($actor,$request);
            $q=$this->db->prepare('SELECT * FROM auth_mfa_credentials WHERE user_id=? FOR UPDATE');
            $q->execute([$actor->userId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row || !$row['verified_at'])throw new HttpException('MFA_NOT_ENABLED','MFA not enabled.',409);
            $matched=is_string($code)?Totp::matchCounter(Totp::open($row['secret_cipher']),$code,(int)$row['last_counter']):null;
            if($matched===null){
                if(!is_string($recovery)||!preg_match('/^[0-9a-f]{12}$/D',$recovery)) {
                    throw new HttpException('INVALID_MFA','Authenticator or recovery code required.',401);
                }
                $q=$this->db->prepare('DELETE FROM auth_mfa_recovery_codes WHERE user_id=? AND code_hash=?');
                $q->execute([$actor->userId,hash('sha256',$recovery)]);
                if($q->rowCount()!==1)throw new HttpException('INVALID_MFA','Invalid MFA code.',401);
            }
            $this->db->prepare('DELETE FROM auth_mfa_recovery_codes WHERE user_id=?')->execute([$actor->userId]);
            $this->db->prepare('DELETE FROM auth_mfa_credentials WHERE user_id=?')->execute([$actor->userId]);
            $this->db->prepare('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP()
              WHERE user_id=? AND id<>? AND revoked_at IS NULL')->execute([$actor->userId,$actor->sessionId]);
            $this->db->prepare('INSERT INTO auth_audit_log
              (user_id,institute_id,event_type,event_data_json,occurred_at)
              VALUES (?,?,\'mfa_disabled\',\'{}\',UTC_TIMESTAMP())')
              ->execute([$actor->userId,$actor->instituteId]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
