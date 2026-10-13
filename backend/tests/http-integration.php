<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\Transaction;

$dsn = getenv('TEST_DB_DSN');
if (!$dsn || PHP_VERSION_ID < 80500) {
    throw new RuntimeException('Requires PHP 8.5 and TEST_DB_DSN for an isolated database server.');
}
$db = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$name = 'iti_http_test_' . bin2hex(random_bytes(6));
$checks = 0;
$server = null;
$log = tempnam(sys_get_temp_dir(), 'iti-http-');
function verify(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function http(string $method, string $path, ?array $body = null, ?string $token = null, ?string $raw = null, string $contentType = 'application/json'): array
{
    $headers = ['Content-Type: ' . $contentType];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 5]];
    if ($raw !== null || $body !== null) {
        $options['http']['content'] = $raw ?? ($body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR));
    }
    $text = @file_get_contents('http://127.0.0.1:18099' . $path, false, stream_context_create($options));
    if ($text === false) {
        throw new RuntimeException('Test HTTP server unavailable.');
    }
    $responseHeaders = http_get_last_response_headers();
    preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches);
    return ['status' => (int) $matches[1], 'body' => json_decode($text, true, 32, JSON_THROW_ON_ERROR), 'headers' => implode("\n", $responseHeaders)];
}
function expectStatus(array $response, int $status): void
{
    verify($response['status'] === $status, 'Expected HTTP ' . $status . ', got ' . $response['status']);
}
function bootstrapDeveloper(string $input, array $environment): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/create-developer.php'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Bootstrap test process could not start.');
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['status'=>proc_close($process),'output'=>$output,'error'=>$error];
}
$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db->exec("USE `$name`");
    App\Core\SchemaInstaller::install($db);
    $db->exec("SET time_zone='+00:00'");
    $db->exec(file_get_contents(dirname(__DIR__) . '/database/seeders/rbac_seed.sql'));
    preg_match('/host=([^;]+)/', $dsn, $host);
    preg_match('/port=([^;]+)/', $dsn, $port);
    $environment = array_merge(getenv(), ['DB_HOST' => $host[1] ?? '127.0.0.1', 'DB_PORT' => $port[1] ?? '3306', 'DB_DATABASE' => $name, 'DB_USERNAME' => getenv('TEST_DB_USERNAME') ?: 'root', 'DB_PASSWORD' => getenv('TEST_DB_PASSWORD') ?: '']);
    $grantsBefore = $db->query('SELECT COUNT(*) FROM auth_role_permissions')->fetchColumn();
    $bootstrapInput = json_encode(['username'=>'bootstrap-test','password'=>'bootstrap-local-test-password']);
    $bootstrap = bootstrapDeveloper($bootstrapInput, $environment);
    verify($bootstrap['status'] === 0, 'First developer bootstrap failed.');
    $bootstrapUser = $db->query("SELECT * FROM auth_users WHERE username='bootstrap-test'")->fetch();
    verify((int) $bootstrapUser['must_change_password'] === 1 && $bootstrapUser['institute_id'] === null, 'Bootstrap identity is not restricted correctly.');
    verify(password_verify('bootstrap-local-test-password', $bootstrapUser['password_hash']), 'Bootstrap password hash invalid.');
    verify(password_get_info($bootstrapUser['password_hash'])['algoName'] === 'argon2id', 'Bootstrap did not use Argon2id.');
    verify(!str_contains($bootstrap['output'] . $bootstrap['error'], 'bootstrap-local-test-password'), 'Bootstrap printed credentials.');
    verify(bootstrapDeveloper($bootstrapInput, $environment)['status'] === 1, 'Repeat bootstrap was accepted.');
    verify((int) $db->query('SELECT COUNT(*) FROM auth_users')->fetchColumn() === 1, 'Repeat bootstrap created another account.');
    verify($db->query('SELECT COUNT(*) FROM auth_role_permissions')->fetchColumn() === $grantsBefore, 'Bootstrap changed permission grants.');
    verify(bootstrapDeveloper(json_encode(['username'=>'bad','password'=>"long-password\0suffix"]), $environment)['status'] === 1, 'Bootstrap accepted a NUL password.');
    verify(bootstrapDeveloper('{', $environment)['status'] === 1, 'Bootstrap accepted malformed JSON.');
    $db->exec('DELETE FROM auth_audit_log; DELETE FROM auth_user_roles; DELETE FROM auth_users');
    $db->exec("INSERT INTO institute (name,mis_code,address) VALUES ('LOCAL TEST A','LOCAL-A','Test'),('LOCAL TEST B','LOCAL-B','Test')");
    $users = [];
    foreach (['developer', 'principal', 'administrator', 'instructor', 'student', 'other-admin'] as $roleName) {
        $role = $roleName === 'other-admin' ? 'administrator' : $roleName;
        $institute = $role === 'developer' ? null : ($roleName === 'other-admin' ? 2 : 1);
        $username = $roleName === 'other-admin' ? 'administrator' : $roleName;
        $statement = $db->prepare('INSERT INTO auth_users (institute_id,username,password_hash,must_change_password) VALUES (?,?,?,?)');
        $statement->execute([$institute, $username, password_hash('http-test-password', PASSWORD_DEFAULT), $role === 'principal' ? 1 : 0]);
        $id = (int) $db->lastInsertId();
        $staffLink = $studentLink = null;
        if (in_array($role, ['principal', 'administrator', 'instructor'], true)) {
            $statement = $db->prepare('INSERT INTO staff (institute_id,employee_code,full_name,staff_category,designation,principal_assignment,joined_on) VALUES (?,?,?,?,?,?,?)');
            $statement->execute([$institute, $roleName, 'Local test staff', $role === 'instructor' ? 'teaching' : 'non_teaching', $role, $role === 'principal' ? 'principal' : 'none', '2026-01-01']);
            $staff = $db->lastInsertId();
            $db->prepare('INSERT INTO auth_staff_links (institute_id,user_id,staff_id) VALUES (?,?,?)')->execute([$institute,$id,$staff]);
            $staffLink = $id;
        } elseif ($role === 'student') {
            $db->exec("INSERT INTO students (institute_id,local_student_code,full_name,date_of_birth,gender,address,identity_mode,identity_evidence_reference) VALUES (1,'LOCAL-STUDENT','Local test student','2005-01-01','test','Test','non_aadhaar','LOCAL-TEST-ONLY')");
            $student = $db->lastInsertId();
            $db->prepare('INSERT INTO auth_student_links (institute_id,user_id,student_id) VALUES (?,?,?)')->execute([$institute,$id,$student]);
            $studentLink = $id;
        }
        $statement = $db->prepare('INSERT INTO auth_user_roles (institute_id,user_id,role_id,role_code,scope,staff_link_user_id,student_link_user_id,valid_from) SELECT ?,?,id,code,scope,?,?,UTC_TIMESTAMP() FROM auth_roles WHERE code=?');
        $statement->execute([$institute,$id,$staffLink,$studentLink,$role]);
        $users[$roleName] = ['id' => $id, 'credentials' => ['username' => $username, 'password' => 'http-test-password', 'institute_id' => $institute]];
    }
    $root = dirname(__DIR__);
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:18099', '-t', $root . '/public', $root . '/public/index.php'], [0 => ['pipe','r'], 1 => ['file',$log,'a'], 2 => ['file',$log,'a']], $pipes, $root, $environment);
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start isolated PHP server.');
    }
    fclose($pipes[0]);
    for ($attempt=0; $attempt<30; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 18099);
        if ($socket !== false) {
            fclose($socket);
            break;
        }
        usleep(100000);
    }
    $health = http('GET', '/api/v1/health');
    expectStatus($health, 200);
    verify(preg_match('/X-Request-ID: [a-f0-9]{32}/i', $health['headers']) === 1, 'Request identifier missing.');
    verify(str_contains(strtolower($health['headers']), 'cache-control: no-store'), 'Sensitive response caching enabled.');
    verify(str_contains(strtolower($health['headers']), 'referrer-policy: no-referrer'), 'Referrer data exposure protection missing.');
    verify(str_contains(strtolower($health['headers']), 'x-frame-options: deny'), 'Frame embedding protection missing.');
    expectStatus(http('GET', '/api/v1/ready'), 200);
    expectStatus(http('GET', '/api/v1/auth/me'), 401);
    expectStatus(http('GET', '/api/v1/institute/profile'), 401);
    expectStatus(http('GET', '/unknown'), 404);
    expectStatus(http('GET', '/api/v1/auth/login'), 405);
    expectStatus(http('POST', '/api/v1/auth/login', raw: '{'), 400);
    expectStatus(http('POST', '/api/v1/auth/login', raw: '[]'), 400);
    expectStatus(http('POST', '/api/v1/auth/login', raw: '{}', contentType: 'text/plain'), 415);
    expectStatus(http('POST', '/api/v1/auth/login', raw: str_repeat('a',16385)), 413);
    $tokens = [];
    foreach ($users as $role => $user) {
        $login = http('POST', '/api/v1/auth/login', $user['credentials']);
        expectStatus($login, 200);
        $token = $tokens[$role] = $login['body']['data']['access_token'];
        $me = http('GET', '/api/v1/auth/me', token: $token);
        expectStatus($me, 200);
        verify(!isset($me['body']['data']['password_hash']), 'Password hash exposed.');
        verify(in_array($role === 'other-admin' ? 'administrator' : $role, $me['body']['data']['roles'], true), 'Incorrect role context.');
        if ($role === 'principal') {
            expectStatus(http('GET', '/api/v1/institute/profile', token: $token), 403);
            expectStatus(http('GET', '/api/v1/auth/sessions', token: $token), 403);
            expectStatus(http('POST', '/api/v1/auth/logout-all', ['current_password'=>'http-test-password'], $token), 403);
            expectStatus(http('POST', '/api/v1/auth/change-password', ['current_password'=>'http-test-password','new_password'=>'rotated-test-password'], $token), 200);
            expectStatus(http('GET', '/api/v1/auth/me', token: $token), 401);
            $users[$role]['credentials']['password'] = 'rotated-test-password';
            $login = http('POST', '/api/v1/auth/login', $users[$role]['credentials']);
            expectStatus($login, 200);
            $token = $tokens[$role] = $login['body']['data']['access_token'];
        }
        $profile = http('GET', '/api/v1/institute/profile?institute_id=2', token: $token);
        $allowed = in_array($role, ['principal','administrator','instructor','other-admin'], true);
        expectStatus($profile, $allowed ? 200 : 403);
        if ($allowed) {
            verify((int) $profile['body']['data']['id'] === ($role === 'other-admin' ? 2 : 1), 'Client input changed institute scope.');
        }
    }
    $admin = $users['administrator']['id'];
    expectStatus(http('GET', '/api/v1/auth/me?access_token=' . $tokens['administrator']), 401);
    expectStatus(http('GET', '/api/v1/auth/me', token: 'malformed-token'), 401);
    expectStatus(http('GET', '/api/v1/auth/me', token: str_repeat('f',63)), 401);
    expectStatus(http('POST', '/api/v1/auth/logout-all', ['current_password'=>'wrong-password'], $tokens['administrator']), 401);
    expectStatus(http('GET', '/api/v1/auth/me', token: $tokens['administrator']), 200);
    $db->exec("UPDATE auth_users SET failed_attempts=0 WHERE id=$admin");
    $sessions = http('GET', '/api/v1/auth/sessions', token: $tokens['administrator']);
    expectStatus($sessions, 200);
    verify(count($sessions['body']['data']) === 1, 'Another user sessions exposed.');
    verify(!isset($sessions['body']['data'][0]['session_token_hash']), 'Session hash exposed over HTTP.');
    $extraLogin = http('POST', '/api/v1/auth/login', $users['other-admin']['credentials']);
    expectStatus($extraLogin, 200);
    expectStatus(http('POST', '/api/v1/auth/logout-all', ['current_password'=>'http-test-password'], $tokens['other-admin']), 200);
    expectStatus(http('GET', '/api/v1/auth/me', token: $tokens['other-admin']), 401);
    expectStatus(http('GET', '/api/v1/auth/me', token: $extraLogin['body']['data']['access_token']), 401);
    expectStatus(http('GET', '/api/v1/auth/me', token: $tokens['administrator']), 200);
    $db->exec("INSERT INTO auth_user_permissions (institute_id,user_id,permission_id,grant_type) SELECT 1,$admin,id,'deny' FROM auth_permissions WHERE code='institute.profile.view'");
    expectStatus(http('GET', '/api/v1/institute/profile', token: $tokens['administrator']), 403);
    expectStatus(http('GET', '/api/v1/auth/me', token: str_repeat('f',64)), 401);
    expectStatus(http('POST', '/api/v1/auth/change-password', ['current_password'=>'http-test-password','new_password'=>"long-password\0suffix"], $tokens['administrator']), 422);
    expectStatus(http('POST', '/api/v1/auth/logout', [], $tokens['student']), 200);
    expectStatus(http('GET', '/api/v1/auth/me', token: $tokens['student']), 401);
    for ($attempt=0; $attempt<5; $attempt++) {
        expectStatus(http('POST', '/api/v1/auth/login', [...$users['administrator']['credentials'], 'password'=>'incorrect']), 401);
    }
    expectStatus(http('GET', '/api/v1/auth/me', token: $tokens['administrator']), 401);
    expectStatus(http('POST', '/api/v1/auth/login', $users['administrator']['credentials']), 401);
    $rateLimitReached = false;
    for ($attempt=0; $attempt<31; $attempt++) {
        $response = http('POST', '/api/v1/auth/login', ['username'=>'missing-account','password'=>'invalid-password']);
        if ($response['status'] === 429) {
            $rateLimitReached = true;
            break;
        }
        verify($response['status'] === 401, 'Unexpected login throttle response.');
    }
    verify($rateLimitReached, 'HTTP login rate limit not enforced.');
    $transaction = new Transaction($db);
    try {
        $transaction->run(static function(PDO $connection): void {
            $connection->exec("UPDATE institute SET name='SHOULD ROLLBACK' WHERE id=1");
            throw new RuntimeException('Test rollback');
        });
    } catch (RuntimeException) {}
    verify($db->query('SELECT name FROM institute WHERE id=1')->fetchColumn() === 'LOCAL TEST A', 'Transaction did not roll back.');
    verify($transaction->run(static fn(PDO $connection) => $connection->query('SELECT 1')->fetchColumn()) == 1, 'Transaction result lost.');
    $transaction->run(static fn(PDO $connection) => $connection->exec("UPDATE institute SET contact_phone='LOCAL-TEST' WHERE id=1"));
    verify($db->query('SELECT contact_phone FROM institute WHERE id=1')->fetchColumn() === 'LOCAL-TEST', 'Successful transaction did not commit.');
    $db->beginTransaction();
    try {
        $called = false;
        try {
            $transaction->run(static function() use (&$called): void { $called = true; });
            verify(false, 'Nested transaction accepted.');
        } catch (LogicException) {
            verify(!$called && $db->inTransaction(), 'Nested transaction altered the outer transaction.');
        }
    } finally {
        $db->rollBack();
    }
    // Restart only the isolated HTTP server with an unreachable database endpoint.
    proc_terminate($server);
    proc_close($server);
    $server = null;
    $outageEnvironment = [...$environment, 'DB_HOST'=>'127.0.0.1', 'DB_PORT'=>'1'];
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:18099', '-t', $root . '/public', $root . '/public/index.php'], [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']], $pipes, $root, $outageEnvironment);
    if (!is_resource($server)) {
        throw new RuntimeException('Outage test server could not start.');
    }
    fclose($pipes[0]);
    for ($attempt=0; $attempt<30; $attempt++) {
        $socket = @fsockopen('127.0.0.1',18099);
        if ($socket !== false) { fclose($socket); break; }
        usleep(100000);
    }
    expectStatus(http('GET','/api/v1/ready'),503);
    expectStatus(http('GET','/api/v1/health'),200);
    expectStatus(http('GET','/api/v1/auth/me'),401);
    echo "HTTP foundation passed ($checks checks, five roles and tenant boundaries).\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $db->exec("DROP DATABASE `$name`");
    unlink($log);
}
