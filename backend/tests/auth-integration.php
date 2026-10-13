<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Http\HttpException;
use App\Http\Request;
use App\Modules\Auth\AuthService;

// Use only a disposable database server. A random database is created and removed.
$dsn = getenv('TEST_DB_DSN');
if (!$dsn) {
    fwrite(STDERR, "Set TEST_DB_DSN to a disposable MySQL/MariaDB server DSN.\n");
    exit(1);
}
$db = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$name = 'iti_auth_test_' . bin2hex(random_bytes(6));
$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    if (!$value) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function fails(callable $action, int $status): void
{
    try {
        $action();
    } catch (HttpException $exception) {
        check($exception->status === $status, 'Unexpected error status.');
        return;
    }
    throw new RuntimeException('Expected rejection.');
}
$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db->exec("USE `$name`");
    $db->exec(file_get_contents(dirname(__DIR__) . '/database/schema/iti_erp_schema.sql'));
    $db->exec("SET time_zone='+00:00'");
    $db->exec(file_get_contents(dirname(__DIR__) . '/database/seeders/rbac_seed.sql'));
    $db->exec("INSERT INTO institute (name,mis_code,address) VALUES ('Test A','TEST-A','Test'),('Test B','TEST-B','Test')");
    $db->exec("INSERT INTO staff (institute_id,employee_code,full_name,staff_category,designation,joined_on) VALUES (1,'TEST','Test','non_teaching','Administrator','2026-01-01')");
    $insert = $db->prepare('INSERT INTO auth_users (institute_id,username,password_hash,must_change_password) VALUES (?,?,?,?)');
    $insert->execute([1, 'same-name', password_hash('test-password-one', PASSWORD_DEFAULT), 1]);
    $tenantUser = (int) $db->lastInsertId();
    $insert->execute([2, 'same-name', password_hash('different-password', PASSWORD_DEFAULT), 0]);
    $insert->execute([null, 'platform-user', password_hash('platform-password', PASSWORD_DEFAULT), 0]);
    $platformUser = (int) $db->lastInsertId();
    $db->exec("INSERT INTO auth_staff_links (institute_id,user_id,staff_id) VALUES (1,$tenantUser,1)");
    $db->exec("INSERT INTO auth_user_roles (institute_id,user_id,role_id,role_code,scope,staff_link_user_id,valid_from) SELECT 1,$tenantUser,id,code,scope,$tenantUser,UTC_TIMESTAMP() FROM auth_roles WHERE code='administrator'");
    $db->exec("INSERT INTO auth_user_roles (user_id,role_id,role_code,scope,valid_from) SELECT $platformUser,id,code,scope,UTC_TIMESTAMP() FROM auth_roles WHERE code='developer'");
    $service = new AuthService($db);
    $credentials = new Request(['username' => 'same-name', 'password' => 'test-password-one', 'institute_id' => 1]);
    fails(fn() => $service->login(new Request(['username' => [], 'password' => 'x'])), 422);
    fails(fn() => $service->login(new Request(['username' => 'same-name', 'password' => 'test-password-one', 'institute_id' => 2])), 401);
    fails(fn() => $service->login(new Request(['username' => 'absent', 'password' => 'test-password-one'])), 401);
    $login = $service->login($credentials);
    check(password_get_info($db->query("SELECT password_hash FROM auth_users WHERE id=$tenantUser")->fetchColumn())['algoName'] === 'argon2id', 'Successful legacy login did not upgrade its password hash.');
    $sessionRequest = new Request([], $login['access_token']);
    $context = $service->authenticate($sessionRequest);
    check($context->instituteId === 1 && $context->staffId === 1, 'Tenant identity incorrect.');
    check($db->query('SELECT session_token_hash FROM auth_sessions LIMIT 1')->fetchColumn() === hash('sha256', $login['access_token']), 'Raw token stored.');
    fails(fn() => $context->requireInstitute(2), 403);
    fails(fn() => $context->requirePermission('staff.view'), 403);
    fails(fn() => $service->changePassword($context, new Request(['current_password' => 'wrong', 'new_password' => 'new-test-password'])), 401);
    $service->changePassword($context, new Request(['current_password' => 'test-password-one', 'new_password' => 'new-test-password']));
    fails(fn() => $service->authenticate($sessionRequest), 401);
    $credentials = new Request(['username' => 'same-name', 'password' => 'new-test-password', 'institute_id' => 1]);
    $login = $service->login($credentials);
    $sessionRequest = new Request([], $login['access_token']);
    $context = $service->authenticate($sessionRequest);
    $context->requirePermission('staff.view');
    check(!$context->mustChangePassword, 'Password change flag retained.');
    $db->exec("INSERT INTO auth_user_permissions (institute_id,user_id,permission_id,grant_type) SELECT 1,$tenantUser,id,'deny' FROM auth_permissions WHERE code='staff.view'");
    fails(fn() => $service->authenticate($sessionRequest)->requirePermission('staff.view'), 403);
    $service->logout($context, $sessionRequest);
    fails(fn() => $service->authenticate($sessionRequest), 401);
    $login = $service->login($credentials);
    $db->exec("UPDATE auth_sessions SET issued_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR),expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE revoked_at IS NULL");
    fails(fn() => $service->authenticate(new Request([], $login['access_token'])), 401);
    $db->exec("INSERT INTO auth_role_permissions (role_id,permission_id) SELECT r.id,p.id FROM auth_roles r CROSS JOIN auth_permissions p WHERE r.code='developer' AND p.code='staff.view'");
    $login = $service->login(new Request(['username' => 'platform-user', 'password' => 'platform-password']));
    $platformToken = $login['access_token'];
    check(!in_array('staff.view', $login['user']['permissions'], true), 'Platform gained tenant access.');
    for ($i=0; $i<5; $i++) {
        fails(fn() => $service->login(new Request(['username' => 'same-name', 'password' => 'wrong', 'institute_id' => 1])), 401);
    }
    fails(fn() => $service->login($credentials), 401);
    $db->exec("UPDATE auth_users SET locked_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=$tenantUser");
    $login = $service->login($credentials);
    check(strlen($login['access_token']) === 64, 'Login after lock expiry failed.');
    $db->exec("UPDATE auth_users SET status='disabled' WHERE id=$tenantUser");
    fails(fn() => $service->authenticate(new Request([], $login['access_token'])), 401);
    $db->exec("UPDATE auth_user_roles SET valid_from=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR),valid_to=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE user_id=$platformUser");
    fails(fn() => $service->authenticate(new Request([], $platformToken)), 401);
    check((int) $db->query("SELECT COUNT(*) FROM auth_audit_log WHERE event_type='password_changed'")->fetchColumn() === 1, 'Missing password audit.');

    // Regression cases from the authentication security review.
    $db->exec("UPDATE auth_users SET status='active',failed_attempts=0,locked_until=NULL WHERE id=$tenantUser");
    $login = $service->login($credentials);
    $sessionRequest = new Request([], $login['access_token']);
    $context = $service->authenticate($sessionRequest);
    $malformed = new Request(['username' => 'same-name', 'password' => 'wrong', 'institute_id' => 1], null, 'invalid-ip', str_repeat('a', 499) . "\xc3");
    check($malformed->ip === null && preg_match('//u', $malformed->userAgent) === 1, 'Unsafe request metadata retained.');
    fails(fn() => $service->login($malformed), 401);
    check((int) $db->query("SELECT failed_attempts FROM auth_users WHERE id=$tenantUser")->fetchColumn() === 1, 'Metadata rolled back failure tracking.');
    $unicode = new Request([], null, '127.0.0.1', str_repeat('é', 501));
    check(strlen($unicode->userAgent) === 1000 && preg_match('//u', $unicode->userAgent) === 1, 'Unicode user-agent truncation is unsafe.');
    $db->exec('RENAME TABLE auth_audit_log TO auth_audit_log_unavailable');
    try {
        fails(fn() => $service->login($malformed), 401);
        check((int) $db->query("SELECT failed_attempts FROM auth_users WHERE id=$tenantUser")->fetchColumn() === 2, 'Audit outage rolled back failure tracking.');
    } finally {
        $db->exec('RENAME TABLE auth_audit_log_unavailable TO auth_audit_log');
    }
    for ($attempt=0; $attempt<3; $attempt++) {
        fails(fn() => $service->changePassword($context, new Request(['current_password' => 'wrong', 'new_password' => 'replacement-password'])), 401);
    }
    check((int) $db->query("SELECT failed_attempts FROM auth_users WHERE id=$tenantUser")->fetchColumn() === 5, 'Password-change attempts bypassed shared limit.');
    fails(fn() => $service->authenticate($sessionRequest), 401);
    fails(fn() => $service->changePassword($context, new Request(['current_password' => 'new-test-password', 'new_password' => 'replacement-password'])), 401);
    $db->exec("UPDATE auth_users SET locked_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=$tenantUser");
    fails(fn() => $service->login($malformed), 401);
    check((int) $db->query("SELECT failed_attempts FROM auth_users WHERE id=$tenantUser")->fetchColumn() === 1, 'Expired lock did not reset attempt window.');
    $login = $service->login($credentials);
    $context = $service->authenticate(new Request([], $login['access_token']));
    fails(fn() => $service->changePassword($context, new Request(['current_password' => 'new-test-password', 'new_password' => "long-password\0suffix"])), 422);
    fails(fn() => $service->changePassword($context, new Request(['current_password' => "new-test-password\0suffix", 'new_password' => 'replacement-password'])), 422);
    fails(fn() => $service->login(new Request(['username' => 'same-name', 'password' => "new-test-password\0suffix", 'institute_id' => 1])), 422);
    check(!$db->inTransaction(), 'Rejected password left an open transaction.');
    check(password_get_info($db->query("SELECT password_hash FROM auth_users WHERE id=$tenantUser")->fetchColumn())['algoName'] === 'argon2id', 'Password hash not upgraded to Argon2id.');
    $sessionTokens = [];
    for ($sessionNumber=0; $sessionNumber<7; $sessionNumber++) {
        $sessionTokens[] = $service->login($credentials)['access_token'];
    }
    fails(fn() => $service->authenticate(new Request([], $sessionTokens[0])), 401);
    fails(fn() => $service->authenticate(new Request([], $sessionTokens[1])), 401);
    $latestContext = $service->authenticate(new Request([], $sessionTokens[6]));
    $activeSessions = $service->sessions($latestContext);
    check(count($activeSessions) === 5, 'Active session limit not enforced.');
    foreach ($activeSessions as $activeSession) {
        check(!array_key_exists('session_token_hash', $activeSession) && !array_key_exists('refresh_token_hash', $activeSession) && !array_key_exists('user_id', $activeSession), 'Session secrets exposed.');
    }
    $statement = $db->prepare('UPDATE auth_sessions SET updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 16 MINUTE) WHERE session_token_hash=?');
    $statement->execute([hash('sha256', $sessionTokens[6])]);
    fails(fn() => $service->authenticate(new Request([], $sessionTokens[6])), 401);
    $latestContext = $service->authenticate(new Request([], $sessionTokens[5]));
    fails(fn() => $service->logoutAll($latestContext, new Request(['current_password' => 'wrong'])), 401);
    check(count($service->sessions($latestContext)) === 4, 'Failed reauthentication revoked valid sessions.');
    $service->logoutAll($latestContext, new Request(['current_password' => 'new-test-password']));
    foreach ($sessionTokens as $sessionToken) {
        fails(fn() => $service->authenticate(new Request([], $sessionToken)), 401);
    }
    // Compare complete failed-login paths, including SQL and audit work.
    $db->prepare('INSERT INTO auth_users (username,password_hash,status) VALUES (?,?,?)')->execute(['legacy-timing', password_hash('timing-fixture-only', PASSWORD_BCRYPT, ['cost'=>12]), 'active']);
    $db->prepare('INSERT INTO auth_users (username,password_hash,status) VALUES (?,?,?)')->execute(['modern-timing', App\Modules\Auth\PasswordHasher::hash('timing-fixture-only'), 'disabled']);
    $timings = [];
    foreach (['legacy-timing','modern-timing','absent-timing'] as $timingUsername) {
        $samples = [];
        for ($sample=0; $sample<3; $sample++) {
            $started = hrtime(true);
            fails(fn() => $service->login(new Request(['username'=>$timingUsername,'password'=>'wrong-password'])), 401);
            $samples[] = (hrtime(true)-$started)/1e6;
        }
        sort($samples);
        $timings[$timingUsername] = $samples[1];
        check($samples[0] >= 490, 'Failed login bypassed minimum response duration.');
    }
    check(max($timings)-min($timings) < 100, 'Legacy and unknown-account failed-login timing diverged.');
    echo 'Failed-login timing medians: ' . json_encode($timings) . " ms\n";
    echo "Authentication integration passed ($checks checks).\n";
} finally {
    $db->exec("DROP DATABASE `$name`");
}
