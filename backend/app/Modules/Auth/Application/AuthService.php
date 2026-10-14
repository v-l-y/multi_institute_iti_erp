<?php
declare(strict_types=1);
namespace App\Modules\Auth\Application;

use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Auth\Infrastructure\PasswordHasher;
use App\Modules\Auth\Infrastructure\Totp;
use App\Http\Request;
use PDO;
use Throwable;

final class AuthService
{
    private const DUMMY_HASH = '$argon2id$v=19$m=19456,t=2,p=1$cTYzajVkR25hZ21pT2ZoYQ$Aq8Of2wmXJjchhH2T2gdLxuIK/Z9dh0f/Z2MDB7BWgw';
    public function __construct(private readonly PDO $db) {}

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    private function audit(string $event, ?int $userId, ?int $instituteId, Request $request): void
    {
        $this->query('INSERT INTO auth_audit_log (user_id,institute_id,event_type,event_data_json,ip_address,user_agent,occurred_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())', [$userId, $instituteId, $event, '{}', $request->ip, $request->userAgent]);
    }

    private function unauthorized(): never
    {
        throw new HttpException('UNAUTHORIZED', 'Invalid credentials or session.', 401);
    }

    private function reauthenticate(AuthContext $context, string $password, string $failureEvent, Request $request): void
    {
        $user = $this->query('SELECT *, (locked_until > UTC_TIMESTAMP()) AS temporarily_locked FROM auth_users WHERE id=? FOR UPDATE', [$context->userId])->fetch();
        $session = $this->query('SELECT id FROM auth_sessions WHERE id=? AND user_id=? AND tenant_key=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() AND updated_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) FOR UPDATE', [$context->sessionId, $context->userId, $context->instituteId ?? 0])->fetch();
        if (!$user || !$session || $user['status'] !== 'active' || (bool) $user['temporarily_locked']) {
            $this->unauthorized();
        }
        if (!password_verify($password, $user['password_hash'])) {
            $this->rejectAttempt($user, $failureEvent, $request);
        }
    }

    private function rejectAttempt(?array $user, string $event, Request $request): never
    {
        if ($user && $user['status'] === 'active' && !(bool) $user['temporarily_locked']) {
            // Start a fresh attempt window once a previous temporary lock expires.
            $attempts = min(5, ($user['locked_until'] !== null ? 0 : (int) $user['failed_attempts']) + 1);
            $this->query('UPDATE auth_users SET failed_attempts=?, locked_until=CASE WHEN ? >= 5 THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) ELSE NULL END WHERE id=?', [$attempts, $attempts, $user['id']]);
        }
        // Security counters must survive an audit-storage failure.
        $this->db->commit();
        try {
            $this->audit($event, $user ? (int) $user['id'] : null, $user && $user['institute_id'] !== null ? (int) $user['institute_id'] : null, $request);
        } catch (Throwable) {
            error_log('[iti-erp] Authentication failure audit could not be saved.');
        }
        $this->unauthorized();
    }

    public function login(Request $request): array
    {
        $startedAt = hrtime(true);
        $username = $request->body['username'] ?? null;
        $password = $request->body['password'] ?? null;
        $institute = $request->body['institute_id'] ?? null;
        if (!is_string($username) || trim($username) === '' || strlen($username) > 120 || !is_string($password) || $password === '' || strlen($password) > 72 || str_contains($password, "\0") || ($institute !== null && (!is_int($institute) || $institute < 1))) {
            throw new HttpException('VALIDATION_ERROR', 'Provide username, password and a positive integer institute_id (null for platform).', 422);
        }
        $this->db->beginTransaction();
        try {
            $user = $this->query('SELECT *, (locked_until > UTC_TIMESTAMP()) AS temporarily_locked FROM auth_users WHERE username=? AND tenant_key=? FOR UPDATE', [trim($username), $institute ?? 0])->fetch();
            $valid = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);
            if (!$user || !$valid || $user['status'] !== 'active' || (bool) $user['temporarily_locked']) {
                $this->rejectAttempt($user ?: null, 'login_failed', $request);
            }
            $context = $this->context($user, 0);
            // MFA must be checked before issuing any usable session.
            $mfa=$this->query('SELECT secret_cipher,last_counter,verified_at
                FROM auth_mfa_credentials WHERE user_id=? FOR UPDATE',[$user['id']])->fetch(PDO::FETCH_ASSOC);
            if($mfa && $mfa['verified_at']!==null){
                $code=$request->body['otp_code']??null;
                $counter=is_string($code)
                    ?Totp::matchCounter(Totp::open($mfa['secret_cipher']),$code,(int)$mfa['last_counter']):null;
                if($counter!==null){
                    $this->query('UPDATE auth_mfa_credentials SET last_counter=? WHERE user_id=?',
                        [$counter,$user['id']]);
                }else{
                    $recovery=$request->body['recovery_code']??null;
                    if(!is_string($recovery)||!preg_match('/^[0-9a-f]{12}$/D',$recovery)){
                        $this->rejectAttempt($user,'mfa_failed',$request);
                    }
                    $q=$this->query('DELETE FROM auth_mfa_recovery_codes
                        WHERE user_id=? AND code_hash=?',[$user['id'],hash('sha256',$recovery)]);
                    if($q->rowCount()!==1)$this->rejectAttempt($user,'mfa_failed',$request);
                }
            }
            if (PasswordHasher::needsUpgrade($user['password_hash'])) {
                $this->query('UPDATE auth_users SET password_hash=? WHERE id=?', [PasswordHasher::hash($password), $user['id']]);
            }
            // Account lock serializes logins; keep at most five live sessions.
            $sessions = $this->query('SELECT id FROM auth_sessions WHERE user_id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() AND updated_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) ORDER BY issued_at DESC,id DESC', [$user['id']])->fetchAll(PDO::FETCH_COLUMN);
            foreach (array_slice($sessions, 4) as $sessionId) {
                $this->query('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE id=?', [$sessionId]);
            }
            $token = bin2hex(random_bytes(32));
            $refresh=bin2hex(random_bytes(32));
            $this->query('INSERT INTO auth_sessions
              (user_id,institute_id,session_token_hash,refresh_token_hash,issued_at,expires_at,
               refresh_expires_at,ip_address,user_agent)
              VALUES (?,?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 MINUTE),
              DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY),?,?)',
              [$user['id'],$user['institute_id'],hash('sha256',$token),hash('sha256',$refresh),
                  $request->ip,$request->userAgent]);
            $this->query('UPDATE auth_users SET failed_attempts=0,locked_until=NULL,last_login_at=UTC_TIMESTAMP() WHERE id=?', [$user['id']]);
            $this->audit('login_succeeded', $context->userId, $context->instituteId, $request);
            $this->db->commit();
            return ['access_token' => $token, 'refresh_token'=>$refresh,
                'token_type' => 'Bearer', 'expires_in' => 3600,
                'refresh_expires_in'=>604800,'user' => $context->profile()];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception instanceof HttpException && $exception->errorCode === 'UNAUTHORIZED') {
                // Pad the complete failure path, after releasing database locks.
                // Covers unknown, legacy, locked, disabled and missing-role accounts.
                $remainingMicroseconds = (int) ceil((500_000_000 - (hrtime(true) - $startedAt)) / 1000);
                if ($remainingMicroseconds > 0) {
                    usleep($remainingMicroseconds);
                }
            }
            throw $exception;
        }
    }

    /**
     * Rotate a refresh token atomically. A reused previous token revokes the
     * affected session: simultaneous refreshes intentionally fail closed.
     */
    public function refresh(Request $request): array
    {
        $raw=$request->body['refresh_token']??null;
        if(!is_string($raw)||!preg_match('/^[a-f0-9]{64}$/iD',$raw)){
            $this->unauthorized();
        }
        $hash=hash('sha256',$raw);
        $this->db->beginTransaction();
        try{
            $replay=$this->query('SELECT session_id FROM auth_refresh_replays
                WHERE old_token_hash=? FOR UPDATE',[$hash])->fetchColumn();
            if($replay!==false){
                $this->query('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP()
                  WHERE id=? AND revoked_at IS NULL',[$replay]);
                $this->db->commit();
                $this->unauthorized();
            }
            $session=$this->query("SELECT s.id,s.user_id,s.institute_id,s.refresh_expires_at,
                u.* FROM auth_sessions s JOIN auth_users u ON u.id=s.user_id
                AND u.tenant_key=s.tenant_key WHERE s.refresh_token_hash=?
                AND s.revoked_at IS NULL AND s.refresh_expires_at>UTC_TIMESTAMP()
                AND u.status='active' AND (u.locked_until IS NULL OR u.locked_until<=UTC_TIMESTAMP())
                FOR UPDATE",[$hash])->fetch(PDO::FETCH_ASSOC);
            if(!$session){$this->db->rollBack();$this->unauthorized();}
            // Re-evaluate current roles, institute and identity links.
            $context=$this->context($session,(int)$session['id']);
            $access=bin2hex(random_bytes(32));$refresh=bin2hex(random_bytes(32));
            $this->query('INSERT INTO auth_refresh_replays
              (old_token_hash,session_id,expires_at) VALUES (?,?,?)',
              [$hash,$session['id'],$session['refresh_expires_at']]);
            $this->query('UPDATE auth_sessions SET session_token_hash=?,
              refresh_token_hash=?,expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 MINUTE),
              updated_at=UTC_TIMESTAMP() WHERE id=?',
              [hash('sha256',$access),hash('sha256',$refresh),$session['id']]);
            $this->db->commit();
            return ['access_token'=>$access,'refresh_token'=>$refresh,
              'token_type'=>'Bearer','expires_in'=>3600,'user'=>$context->profile()];
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    public function authenticate(Request $request): AuthContext
    {
        if ($request->token === null) {
            $this->unauthorized();
        }
        $user = $this->query("SELECT u.*, s.id AS session_id FROM auth_sessions s JOIN auth_users u ON u.id=s.user_id AND u.tenant_key=s.tenant_key WHERE s.session_token_hash=? AND s.revoked_at IS NULL AND s.expires_at>UTC_TIMESTAMP() AND s.updated_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) AND u.status='active' AND (u.locked_until IS NULL OR u.locked_until<=UTC_TIMESTAMP())", [hash('sha256', $request->token)])->fetch();
        if (!$user) {
            $this->unauthorized();
        }
        $context = $this->context($user, (int) $user['session_id']);
        $this->query('UPDATE auth_sessions SET updated_at=UTC_TIMESTAMP() WHERE id=? AND revoked_at IS NULL', [$user['session_id']]);
        return $context;
    }

    private function context(array $user, int $sessionId): AuthContext
    {
        $roles = $this->query('SELECT role_id,role_code,scope FROM auth_user_roles WHERE user_id=? AND tenant_key=? AND valid_from<=UTC_TIMESTAMP() AND (valid_to IS NULL OR valid_to>UTC_TIMESTAMP())', [$user['id'], $user['institute_id'] ?? 0])->fetchAll();
        if ($roles === []) {
            $this->unauthorized();
        }
        $platform = $user['institute_id'] === null;
        $staffId = $platform ? false : $this->query('SELECT staff_id FROM auth_staff_links WHERE user_id=? AND institute_id=?', [$user['id'], $user['institute_id']])->fetchColumn();
        $studentId = $platform ? false : $this->query('SELECT student_id FROM auth_student_links WHERE user_id=? AND institute_id=?', [$user['id'], $user['institute_id']])->fetchColumn();
        $permissions = [];
        foreach ($roles as $role) {
            if (($platform && ($role['role_code'] !== 'developer' || $role['scope'] !== 'platform')) || (!$platform && ($role['scope'] !== 'institute' || $role['role_code'] === 'developer')) || (in_array($role['role_code'], ['principal', 'administrator', 'instructor'], true) && $staffId === false) || ($role['role_code'] === 'student' && $studentId === false)) {
                $this->unauthorized();
            }
            foreach ($this->query('SELECT p.code FROM auth_role_permissions rp JOIN auth_permissions p ON p.id=rp.permission_id WHERE rp.role_id=?', [$role['role_id']])->fetchAll(PDO::FETCH_COLUMN) as $code) {
                $permissions[$code] = true;
            }
        }
        if (!$platform) {
            foreach ($this->query('SELECT p.code,up.grant_type FROM auth_user_permissions up JOIN auth_permissions p ON p.id=up.permission_id WHERE up.user_id=? AND up.institute_id=?', [$user['id'], $user['institute_id']])->fetchAll() as $override) {
                $permissions[$override['code']] = $override['grant_type'] === 'grant';
            }
        }
        $codes = array_keys(array_filter($permissions));
        $codes = array_values(array_filter($codes, static fn(string $code): bool => $platform === str_starts_with($code, 'platform.')));
        sort($codes);
        return new AuthContext((int) $user['id'], $platform ? null : (int) $user['institute_id'], $sessionId, $user['username'], (bool) $user['must_change_password'], array_column($roles, 'role_code'), $codes, $staffId === false ? null : (int) $staffId, $studentId === false ? null : (int) $studentId);
    }

    public function logout(AuthContext $context, Request $request): void
    {
        $this->db->beginTransaction();
        try {
            $this->query('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND user_id=? AND revoked_at IS NULL', [$context->sessionId, $context->userId]);
            $this->audit('logout', $context->userId, $context->instituteId, $request);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function changePassword(AuthContext $context, Request $request): void
    {
        $current = $request->body['current_password'] ?? null;
        $next = $request->body['new_password'] ?? null;
        if (!is_string($current) || $current === '' || strlen($current) > 72 || str_contains($current, "\0") || !is_string($next) || strlen($next) < 12 || strlen($next) > 72 || str_contains($next, "\0") || $current === $next) {
            throw new HttpException('VALIDATION_ERROR', 'Use a different password of 12–72 bytes.', 422);
        }
        $this->db->beginTransaction();
        try {
            $this->reauthenticate($context, $current, 'password_change_failed', $request);
            $this->query('UPDATE auth_users SET password_hash=?,must_change_password=0,failed_attempts=0,locked_until=NULL WHERE id=?', [PasswordHasher::hash($next), $context->userId]);
            $this->query('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL', [$context->userId]);
            $this->query('UPDATE auth_password_resets SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL', [$context->userId]);
            $this->audit('password_changed', $context->userId, $context->instituteId, $request);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function sessions(AuthContext $context): array
    {
        return $this->query('SELECT id,issued_at,expires_at,updated_at AS last_active_at,ip_address,user_agent,(id=?) AS is_current FROM auth_sessions WHERE user_id=? AND tenant_key=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() AND updated_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) ORDER BY issued_at DESC,id DESC', [$context->sessionId,$context->userId,$context->instituteId ?? 0])->fetchAll();
    }

    public function logoutAll(AuthContext $context, Request $request): void
    {
        $password = $request->body['current_password'] ?? null;
        if (!is_string($password) || $password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new HttpException('VALIDATION_ERROR', 'Provide your current password.', 422);
        }
        $this->db->beginTransaction();
        try {
            $this->reauthenticate($context, $password, 'logout_all_failed', $request);
            $this->query('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL', [$context->userId]);
            $this->audit('logout_all', $context->userId, $context->instituteId, $request);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
