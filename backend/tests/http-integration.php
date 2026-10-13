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
    // All operational mutations require an authenticated, scoped actor.
    $operationalRoutes = [
        '/api/v1/enrollments/activate',
        '/api/v1/enrollments/cancel',
        '/api/v1/enrollments/trainee-upload/prepare',
        '/api/v1/exams/eligibility/freeze',
    ];
    foreach ($operationalRoutes as $route) {
        expectStatus(http('POST', $route, []), 401);
        expectStatus(http('POST', $route, [], $tokens['student']), 403);
    }
    expectStatus(http('POST', '/api/v1/enrollments/activate', ['enrollment_id'=>'1'], $tokens['administrator']), 422);
    expectStatus(http('POST', '/api/v1/enrollments/cancel', ['enrollment_id'=>1, 'reason'=>''], $tokens['administrator']), 422);
    expectStatus(http('POST', '/api/v1/enrollments/trainee-upload/prepare', ['enrollment_id'=>0], $tokens['administrator']), 422);
    expectStatus(http('POST', '/api/v1/exams/eligibility/freeze', ['enrollment_id'=>1, 'batch_year_id'=>1, 'cycle_id'=>1, 'evidence_reference'=>''], $tokens['administrator']), 422);
    expectStatus(http('POST', '/api/v1/enrollments/activate', ['enrollment_id'=>999999], $tokens['administrator']), 409);
    // An institute account can register teaching/non-teaching staff, never provision a login implicitly.
    expectStatus(http('GET', '/api/v1/staff'), 401);
    expectStatus(http('GET', '/api/v1/staff', token: $tokens['student']), 403);
    expectStatus(http('POST', '/api/v1/staff', [], $tokens['student']), 403);
    $newStaff = ['employee_code'=>'LOCAL-NEW-TEACHING','full_name'=>'Local Instructor','staff_category'=>'teaching','designation'=>'Instructor','joined_on'=>'2026-01-02'];
    expectStatus(http('POST', '/api/v1/staff', ['employee_code'=>'LOCAL-BAD','full_name'=>'Bad','staff_category'=>'invalid','designation'=>'Instructor','joined_on'=>'2026-01-02'], $tokens['administrator']), 422);
    $createdStaff = http('POST', '/api/v1/staff', $newStaff, $tokens['administrator']);
    expectStatus($createdStaff, 201);
    $createdId = (int) $createdStaff['body']['data']['staff_id'];
    verify($createdId > 0, 'Staff creation did not return its ID.');
    expectStatus(http('POST', '/api/v1/staff', $newStaff, $tokens['administrator']), 409);
    $staffList = http('GET', '/api/v1/staff?page_size=100', token: $tokens['administrator']);
    expectStatus($staffList, 200);
    verify(count(array_filter($staffList['body']['data']['staff'], static fn($row) => (int)$row['id'] === $createdId)) === 1, 'New staff missing from scoped registry.');
    expectStatus(http('GET', '/api/v1/staff?page_size=abc', token: $tokens['administrator']), 422);
    $otherStaff = http('GET', '/api/v1/staff?page_size=100', token: $tokens['other-admin']);
    expectStatus($otherStaff, 200);
    verify(!in_array('LOCAL-NEW-TEACHING', array_column($otherStaff['body']['data']['staff'], 'employee_code'), true), 'Cross-institute staff leaked.');
    verify((int) $db->query("SELECT COUNT(*) FROM auth_users WHERE institute_id=1")->fetchColumn() === 4, 'Staff creation unexpectedly provisioned a login.');
    expectStatus(http('GET', '/api/v1/students'), 401);
    expectStatus(http('GET', '/api/v1/students', token: $tokens['student']), 403);
    expectStatus(http('POST', '/api/v1/students', [], $tokens['student']), 403);
    expectStatus(http('GET', '/api/v1/students/me', token: $tokens['administrator']), 403);
    $self = http('GET', '/api/v1/students/me', token: $tokens['student']);
    expectStatus($self, 200);
    verify($self['body']['data']['local_student_code'] === 'LOCAL-STUDENT', 'Student own-identity filter failed.');
    $newStudent = ['local_student_code'=>'LOCAL-DRAFT-02','full_name'=>'Local Draft Learner','date_of_birth'=>'2005-01-01','gender'=>'test','address'=>'local','identity_mode'=>'non_aadhaar','identity_evidence_reference'=>'LOCAL-FIXTURE-ONLY'];
    expectStatus(http('POST', '/api/v1/students', [...$newStudent, 'date_of_birth'=>'2005-13-01'], $tokens['administrator']), 422);
    $createdStudent = http('POST', '/api/v1/students', $newStudent, $tokens['administrator']);
    expectStatus($createdStudent, 201);
    $studentId = (int) $createdStudent['body']['data']['student_id'];
    verify($studentId > 0, 'Student creation did not return the local ID.');
    expectStatus(http('POST', '/api/v1/students', $newStudent, $tokens['administrator']), 409);
    $qualUrl='/api/v1/students/qualifications';
    $qualRequest=[
        'student_id'=>$studentId,'level_code'=>'10','board_name'=>'LOCAL',
        'passing_year'=>2024,'certificate_reference'=>'LOCAL-SCHOOL-TEST',
        'subject_results'=>(object)['Mathematics'=>80]
    ];
    expectStatus(http('POST',$qualUrl,[]),401);
    expectStatus(http('POST',$qualUrl,$qualRequest,$tokens['student']),403);
    expectStatus(http('POST',$qualUrl,[...$qualRequest,'passing_year'=>'2024'],$tokens['administrator']),422);
    expectStatus(http('POST',$qualUrl,[...$qualRequest,'subject_results'=>[80]],$tokens['administrator']),422);
    $qualCreated=http('POST',$qualUrl,$qualRequest,$tokens['administrator']);
    expectStatus($qualCreated,201);
    verify((int)$qualCreated['body']['data']['qualification_id']>0,'Local qualification ID missing.');
    expectStatus(http('POST',$qualUrl,$qualRequest,$tokens['administrator']),409);
    expectStatus(http('POST',$qualUrl,$qualRequest,$tokens['other-admin']),404);
    $studentsA = http('GET', '/api/v1/students?page_size=100', token: $tokens['administrator']);
    expectStatus($studentsA, 200);
    verify(in_array('LOCAL-DRAFT-02', array_column($studentsA['body']['data']['students'], 'local_student_code'), true), 'Created local student missing.');
    $studentsB = http('GET', '/api/v1/students?page_size=100', token: $tokens['other-admin']);
    expectStatus($studentsB, 200);
    verify(!in_array('LOCAL-DRAFT-02', array_column($studentsB['body']['data']['students'], 'local_student_code'), true), 'Cross-institute student data leaked.');
    expectStatus(http('GET', '/api/v1/students?page_size=101', token: $tokens['administrator']), 422);
    foreach (['/api/v1/itsu','/api/v1/batches'] as $route) {
        expectStatus(http('GET', $route), 401);
        expectStatus(http('GET', $route, token: $tokens['student']), 403);
        $listed = http('GET', $route . '?page_size=100', token: $tokens['instructor']);
        expectStatus($listed, 200);
        verify(array_key_exists('next_after_id', $listed['body']['data']), 'Pagination metadata missing.');
        expectStatus(http('GET', $route . '?page_size=0', token: $tokens['instructor']), 422);
    }
    // Real HTTP fee payments: exact amounts, replay identities, tenant isolation and own-record reads.
    foreach (['/api/v1/fees/assessments/issue','/api/v1/fees/waivers/approve',
              '/api/v1/fees/payments','/api/v1/fees/allocations','/api/v1/fees/refunds',
              '/api/v1/fees/refunds/decide','/api/v1/fees/payments/reverse'] as $route) {
        expectStatus(http('POST', $route, []), 401);
        expectStatus(http('POST', $route, [], $tokens['student']), 403);
    }
    $paymentBody = [
        'student_id'=>$studentId,'amount'=>'100.50','payment_mode'=>'cash',
        'payment_no'=>'LOCAL-HTTP-PAY-1','receipt_no'=>'LOCAL-HTTP-RCT-1',
        'idempotency_key'=>'local-http-payment-1'
    ];
    expectStatus(http('POST', '/api/v1/fees/payments', [...$paymentBody,'amount'=>100.50], $tokens['administrator']), 422);
    expectStatus(http('POST', '/api/v1/fees/payments', [...$paymentBody,'payment_mode'=>'invalid'], $tokens['administrator']), 422);
    $paymentResponse = http('POST', '/api/v1/fees/payments', $paymentBody, $tokens['administrator']);
    expectStatus($paymentResponse, 201);
    $feePaymentId = (int)$paymentResponse['body']['data']['payment_id'];
    verify($feePaymentId > 0, 'Fee API did not record a payment.');
    $again = http('POST', '/api/v1/fees/payments', $paymentBody, $tokens['administrator']);
    expectStatus($again, 201);
    verify((int)$again['body']['data']['payment_id'] === $feePaymentId, 'Payment idempotent retry inserted a duplicate.');
    expectStatus(http('POST', '/api/v1/fees/payments', [...$paymentBody,'amount'=>'101.00'], $tokens['administrator']), 409);
    expectStatus(http('POST', '/api/v1/fees/payments', [...$paymentBody,'idempotency_key'=>'cross-tenant-attempt'], $tokens['other-admin']), 404);
    $ledger = http('GET', '/api/v1/fees/payments?page_size=100', token:$tokens['administrator']);
    expectStatus($ledger, 200);
    verify(in_array($feePaymentId, array_map('intval', array_column($ledger['body']['data']['payments'], 'id')), true), 'Institute payment missing from ledger.');
    $foreignLedger = http('GET', '/api/v1/fees/payments?page_size=100', token:$tokens['other-admin']);
    expectStatus($foreignLedger, 200);
    verify(!in_array($feePaymentId, array_map('intval', array_column($foreignLedger['body']['data']['payments'], 'id')), true), 'Cross-institute payment exposed.');
    expectStatus(http('GET', '/api/v1/fees/payments', token:$tokens['student']), 403);
    $ownLedger = http('GET', '/api/v1/fees/payments/me', token:$tokens['student']);
    expectStatus($ownLedger, 200);
    verify(count($ownLedger['body']['data']['payments']) === 0, 'Student own fee view leaked another student payment.');
    // An actual payment belonging to the linked student must be visible to
    // that student and not reveal the other locally registered student's rows.
    $linkedId = (int)$db->query("SELECT id FROM students WHERE institute_id=1 AND local_student_code='LOCAL-STUDENT'")->fetchColumn();
    $linkedPayment = http('POST', '/api/v1/fees/payments', [
        'student_id'=>$linkedId,'amount'=>'5.00','payment_mode'=>'cash',
        'payment_no'=>'LOCAL-HTTP-PAY-2','receipt_no'=>'LOCAL-HTTP-RCT-2',
        'idempotency_key'=>'local-http-payment-2'
    ], $tokens['administrator']);
    expectStatus($linkedPayment, 201);
    $linkedPaymentId = (int)$linkedPayment['body']['data']['payment_id'];
    $onlyOwn = http('GET', '/api/v1/fees/payments/me', token:$tokens['student']);
    expectStatus($onlyOwn, 200);
    $ownRows = $onlyOwn['body']['data']['payments'];
    verify(count($ownRows) === 1 && (int)$ownRows[0]['id'] === $linkedPaymentId && (int)$ownRows[0]['student_id'] === $linkedId, 'Linked student payment visibility or isolation failed.');
    verify((int)$db->query("SELECT COUNT(*) FROM fee_payments WHERE institute_id=1 AND idempotency_key='local-http-payment-1'")->fetchColumn()===1, 'Idempotent payment replay duplicated rows.');
    expectStatus(http('GET', '/api/v1/fees/payments/me', token:$tokens['administrator']), 403);
    expectStatus(http('POST', '/api/v1/fees/assessments/issue', ['assessment_id'=>999999], $tokens['administrator']), 404);
    expectStatus(http('POST', '/api/v1/fees/refunds/decide', ['refund_id'=>1,'action'=>'made_up'], $tokens['principal']), 422);
    foreach (['/api/v1/attendance/records',
              '/api/v1/attendance/records/assigned',
              '/api/v1/attendance/records/me'] as $route) {
        expectStatus(http('GET', $route), 401);
    }
    expectStatus(http('GET', '/api/v1/attendance/records', token:$tokens['student']), 403);
    expectStatus(http('GET', '/api/v1/attendance/records/assigned', token:$tokens['student']), 403);
    expectStatus(http('GET', '/api/v1/attendance/records/me', token:$tokens['administrator']), 403);
    $adminAttendance = http('GET', '/api/v1/attendance/records', token:$tokens['administrator']);
    expectStatus($adminAttendance, 200);
    verify(array_key_exists('next_cursor', $adminAttendance['body']['data']), 'Institute attendance cursor missing.');
    $assignedAttendance = http('GET', '/api/v1/attendance/records/assigned', token:$tokens['instructor']);
    expectStatus($assignedAttendance, 200);
    verify(is_array($assignedAttendance['body']['data']['records']), 'Instructor assignment response missing.');
    $ownAttendance = http('GET', '/api/v1/attendance/records/me', token:$tokens['student']);
    expectStatus($ownAttendance, 200);
    verify(count($ownAttendance['body']['data']['records']) === 0, 'Student attendance unexpectedly exposed unrelated data.');
    expectStatus(http('GET', '/api/v1/attendance/records?after_session_id=1', token:$tokens['administrator']), 422);
    expectStatus(http('GET', '/api/v1/attendance/records/me?after_session_id=1&after_enrollment_id=0', token:$tokens['student']), 422);
    expectStatus(http('GET', '/api/v1/attendance/records/assigned?page_size=101', token:$tokens['instructor']), 422);
    foreach (['/api/v1/exams/results', '/api/v1/exams/results/me',
              '/api/v1/certificates', '/api/v1/certificates/me'] as $route) {
        expectStatus(http('GET', $route), 401);
    }
    foreach (['/api/v1/exams/results', '/api/v1/certificates'] as $route) {
        expectStatus(http('GET', $route, token:$tokens['student']), 403);
        $adminOfficial = http('GET', $route, token:$tokens['administrator']);
        expectStatus($adminOfficial, 200);
        verify(array_key_exists('next_after_id', $adminOfficial['body']['data']), 'Official record pagination missing.');
        expectStatus(http('GET', $route.'?page_size=101', token:$tokens['administrator']), 422);
    }
    foreach (['/api/v1/exams/results/me', '/api/v1/certificates/me'] as $route) {
        expectStatus(http('GET', $route, token:$tokens['administrator']), 403);
        $ownOfficial = http('GET', $route, token:$tokens['student']);
        expectStatus($ownOfficial, 200);
        $key = str_contains($route,'results') ? 'results' : 'certificates';
        verify($ownOfficial['body']['data'][$key] === [], 'Nonexistent official record must not be fabricated.');
        expectStatus(http('GET', $route.'?after_id=0', token:$tokens['student']), 422);
    }
    // A teacher may mark only pre-existing, assigned and unlocked sessions.
    $markRoute = '/api/v1/attendance/records/assigned';
    expectStatus(http('POST', $markRoute, []), 401);
    expectStatus(http('POST', $markRoute, [], $tokens['student']), 403);
    expectStatus(http('POST', $markRoute, [], $tokens['administrator']), 403);
    expectStatus(http('POST', $markRoute, [
        'session_id'=>1,'enrollment_id'=>1,'attended_minutes'=>10.5,'status'=>'present'
    ], $tokens['instructor']), 422);
    expectStatus(http('POST', $markRoute, [
        'session_id'=>1,'enrollment_id'=>1,'attended_minutes'=>1,'status'=>'absent'
    ], $tokens['instructor']), 422);
    expectStatus(http('POST', $markRoute, [
        'session_id'=>999999,'enrollment_id'=>999999,'attended_minutes'=>0,'status'=>'absent'
    ], $tokens['instructor']), 404);
    foreach (['/api/v1/enrollments','/api/v1/seat-matrix/entries',
              '/api/v1/academic/calendars'] as $route) {
        expectStatus(http('GET', $route), 401);
        expectStatus(http('GET', $route, token:$tokens['student']), 403);
        $instituteView = http('GET', $route.'?page_size=10', token:$tokens['administrator']);
        expectStatus($instituteView, 200);
        verify(array_key_exists('next_after_id', $instituteView['body']['data']), 'Admissions registry missing its cursor.');
        $otherView = http('GET', $route.'?page_size=10', token:$tokens['other-admin']);
        expectStatus($otherView, 200);
        expectStatus(http('GET', $route.'?page_size=101', token:$tokens['administrator']), 422);
        expectStatus(http('GET', $route.'?after_id[]=1', token:$tokens['administrator']), 422);
    }
    $draftRoute = '/api/v1/enrollments/drafts';
    expectStatus(http('POST', $draftRoute, []), 401);
    expectStatus(http('POST', $draftRoute, [], $tokens['student']), 403);
    $draftRequest = [
        'student_id'=>$studentId,
        'batch_id'=>1,
        'qualification_id'=>1,
        'admission_intake_revision_id'=>1,
        'admission_number'=>'HTTP-LOCAL-DRAFT',
        'admitted_on'=>'2026-08-01',
        'qualification_route_code'=>'LOCAL'
    ];
    expectStatus(http('POST', $draftRoute, [...$draftRequest,'student_id'=>'1'], $tokens['administrator']), 422);
    expectStatus(http('POST', $draftRoute, [...$draftRequest,'admitted_on'=>'2026-02-30'], $tokens['administrator']), 422);
    expectStatus(http('POST', $draftRoute, $draftRequest, $tokens['administrator']), 404);
    expectStatus(http('POST', $draftRoute, $draftRequest, $tokens['other-admin']), 404);
    $reviewUrl='/api/v1/enrollments/review';
    $reviewRequest=['enrollment_id'=>1,'admission_route'=>'institute_direct',
        'admission_route_source_id'=>1,'admission_route_evidence_reference'=>'LOCAL'];
    expectStatus(http('POST',$reviewUrl,[]),401);
    expectStatus(http('POST',$reviewUrl,$reviewRequest,$tokens['student']),403);
    expectStatus(http('POST',$reviewUrl,$reviewRequest,$tokens['administrator']),403);
    expectStatus(http('POST',$reviewUrl,[...$reviewRequest,'admission_route'=>'unverified'],$tokens['principal']),422);
    expectStatus(http('POST',$reviewUrl,$reviewRequest,$tokens['principal']),409);
    // Provisioning is authenticated and bound to existing staff/student IDs.
    $staffProvision='/api/v1/auth/users/staff';
    $studentProvision='/api/v1/auth/users/student';
    $createTeacher=['staff_id'=>$createdId,'role'=>'instructor',
        'username'=>'local-new-instructor','temporary_password'=>'Local-Initial-Password-123'];
    $createLearner=['student_id'=>$studentId,
        'username'=>'local-new-learner','temporary_password'=>'Local-Initial-Password-123'];
    expectStatus(http('GET','/api/v1/auth/users'),401);
    expectStatus(http('GET','/api/v1/auth/users',token:$tokens['student']),403);
    expectStatus(http('GET','/api/v1/auth/users?page_size=101',token:$tokens['principal']),422);
    expectStatus(http('POST',$staffProvision,[]),401);
    expectStatus(http('POST',$staffProvision,$createTeacher,$tokens['student']),403);
    expectStatus(http('POST',$staffProvision,[...$createTeacher,'temporary_password'=>'short'],$tokens['principal']),422);
    expectStatus(http('POST',$staffProvision,[...$createTeacher,'staff_id'=>999999],$tokens['principal']),404);
    expectStatus(http('POST',$staffProvision,$createTeacher,$tokens['other-admin']),404);
    $teacherCreated=http('POST',$staffProvision,$createTeacher,$tokens['administrator']);
    expectStatus($teacherCreated,201);
    $teacherUser=(int)$teacherCreated['body']['data']['user_id'];
    verify($teacherUser>0 && !isset($teacherCreated['body']['data']['temporary_password']),
        'Created teacher account must not disclose credentials.');
    expectStatus(http('POST',$staffProvision,$createTeacher,$tokens['administrator']),409);
    $teacherLogin=http('POST','/api/v1/auth/login',[
        'username'=>$createTeacher['username'],'password'=>$createTeacher['temporary_password'],
        'institute_id'=>1
    ]);
    expectStatus($teacherLogin,200);
    verify($teacherLogin['body']['data']['must_change_password']??$teacherLogin['body']['data']['user']['must_change_password']??true,
        'New account should require first-login password rotation.');
    expectStatus(http('POST',$staffProvision,[
        'staff_id'=>$createdId,'role'=>'administrator','username'=>'local-admin-2',
        'temporary_password'=>'Local-Initial-Password-123'
    ],$tokens['administrator']),403);
    expectStatus(http('POST',$staffProvision,[
        'staff_id'=>$createdId,'role'=>'administrator','username'=>'local-admin-2',
        'temporary_password'=>'Local-Initial-Password-123'
    ],$tokens['principal']),409);
    expectStatus(http('POST',$studentProvision,$createLearner,$tokens['student']),403);
    expectStatus(http('POST',$studentProvision,[...$createLearner,'student_id'=>999999],$tokens['administrator']),404);
    $learnerCreated=http('POST',$studentProvision,$createLearner,$tokens['administrator']);
    expectStatus($learnerCreated,201);
    $learnerUser=(int)$learnerCreated['body']['data']['user_id'];
    expectStatus(http('POST',$studentProvision,$createLearner,$tokens['administrator']),409);
    $userList=http('GET','/api/v1/auth/users?page_size=100',token:$tokens['principal']);
    expectStatus($userList,200);
    verify(in_array($teacherUser,array_map('intval',array_column($userList['body']['data']['users'],'id')),true),
        'Provisioned instructor missing from institute user registry.');
    $foreignUsers=http('GET','/api/v1/auth/users?page_size=100',token:$tokens['other-admin']);
    expectStatus($foreignUsers,200);
    verify(!in_array($teacherUser,array_map('intval',array_column($foreignUsers['body']['data']['users'],'id')),true),
        'Cross-institute user account leaked.');
    $teacherReset='/api/v1/auth/users/reset-password';
    expectStatus(http('POST',$teacherReset,['user_id'=>$teacherUser,'temporary_password'=>'second-local-password-123'],$tokens['student']),403);
    expectStatus(http('POST',$teacherReset,['user_id'=>$teacherUser,'temporary_password'=>'short'],$tokens['administrator']),422);
    expectStatus(http('POST',$teacherReset,['user_id'=>$teacherUser,'temporary_password'=>'Second-Local-Password-123'],$tokens['other-admin']),404);
    expectStatus(http('POST',$teacherReset,['user_id'=>$teacherUser,'temporary_password'=>'Second-Local-Password-123'],$tokens['administrator']),200);
    expectStatus(http('GET','/api/v1/auth/me',token:$teacherLogin['body']['data']['access_token']),401);
    expectStatus(http('POST','/api/v1/auth/login',[
        'username'=>$createTeacher['username'],'password'=>$createTeacher['temporary_password'],'institute_id'=>1
    ]),401);
    expectStatus(http('POST','/api/v1/auth/login',[
        'username'=>$createTeacher['username'],'password'=>'Second-Local-Password-123','institute_id'=>1
    ]),200);
    expectStatus(http('POST','/api/v1/auth/users/disable',['user_id'=>$teacherUser],$tokens['other-admin']),404);
    expectStatus(http('POST','/api/v1/auth/users/disable',['user_id'=>$teacherUser],$tokens['administrator']),200);
    expectStatus(http('POST','/api/v1/auth/login',[
        'username'=>$createTeacher['username'],'password'=>'Second-Local-Password-123','institute_id'=>1
    ]),401);
    expectStatus(http('POST','/api/v1/auth/users/revoke-role',['user_id'=>$learnerUser],$tokens['administrator']),200);
    expectStatus(http('POST','/api/v1/auth/login',[
        'username'=>$createLearner['username'],'password'=>$createLearner['temporary_password'],'institute_id'=>1
    ]),401);
    verify((int)$db->query("SELECT COUNT(*) FROM institute_activity_log
        WHERE action_code IN ('auth.user.provision','auth.user.disable','auth.user.role.revoke','auth.user.password.reset')")->fetchColumn()>=5,
        'Provisioning actions must be audited.');
    $contactUrl='/api/v1/institute/contact';
    expectStatus(http('POST',$contactUrl,['contact_phone'=>'abc'],$tokens['principal']),422);
    expectStatus(http('POST',$contactUrl,['mis_code'=>'NEVER-OVERRIDE'],$tokens['principal']),422);
    expectStatus(http('POST',$contactUrl,['contact_phone'=>'+91-1234567890',
        'contact_email'=>'local@example.test'],$tokens['principal']),200);
    $contactProfile=http('GET','/api/v1/institute/profile',token:$tokens['principal']);
    expectStatus($contactProfile,200);
    verify($contactProfile['body']['data']['contact_email']==='local@example.test',
        'Institute contact edit not visible in profile.');
    $profileUrl='/api/v1/staff/profile';
    expectStatus(http('POST',$profileUrl,['staff_id'=>$createdId,
        'full_name'=>'Updated Instructor','designation'=>'Trade Instructor'],$tokens['student']),403);
    expectStatus(http('POST',$profileUrl,['staff_id'=>$createdId,
        'full_name'=>'Updated Instructor','designation'=>'Trade Instructor'],$tokens['other-admin']),404);
    expectStatus(http('POST',$profileUrl,['staff_id'=>$createdId,
        'full_name'=>'Updated Instructor','designation'=>'Trade Instructor'],$tokens['administrator']),200);
    $qualUrl='/api/v1/staff/qualifications';
    $staffQual=['staff_id'=>$createdId,'qualification_type'=>'ITI',
        'specialization'=>'Electrician','certificate_reference'=>'LOCAL-STAFF-DOCUMENT',
        'experience_months'=>12];
    expectStatus(http('POST',$qualUrl,[...$staffQual,'experience_months'=>'12'],$tokens['administrator']),422);
    expectStatus(http('POST',$qualUrl,$staffQual,$tokens['other-admin']),404);
    $createdStaffQual=http('POST',$qualUrl,$staffQual,$tokens['administrator']);
    expectStatus($createdStaffQual,201);
    $staffQualificationId=(int)$createdStaffQual['body']['data']['qualification_id'];
    verify($staffQualificationId>0 &&
        $db->query("SELECT verified_on FROM staff_qualifications WHERE id=$staffQualificationId")->fetchColumn()===null,
        'Unreviewed staff evidence must not become verified.');
    expectStatus(http('POST',$qualUrl,$staffQual,$tokens['administrator']),409);
    $exitUrl='/api/v1/staff/exit';
    $staffExit=['staff_id'=>$createdId,'left_on'=>'2026-10-01','reference'=>'LOCAL-EXIT'];
    expectStatus(http('POST',$exitUrl,[...$staffExit,'left_on'=>'2026-02-30'],$tokens['administrator']),422);
    expectStatus(http('POST',$exitUrl,$staffExit,$tokens['other-admin']),404);
    expectStatus(http('POST',$exitUrl,$staffExit,$tokens['administrator']),200);
    verify($db->query("SELECT left_on FROM staff WHERE id=$createdId")->fetchColumn()==='2026-10-01',
        'Staff exit date missing.');
    expectStatus(http('POST',$exitUrl,$staffExit,$tokens['administrator']),409);
    verify((int)$db->query("SELECT COUNT(*) FROM institute_activity_log
        WHERE action_code='staff.employment.exit' AND entity_id=$createdId")->fetchColumn()===1,
        'One staff exit should create exactly one audit event.');
    $sessionEndpoint='/api/v1/attendance/sessions';
    $sessionInput=['batch_year_id'=>1,'session_date'=>'2026-09-03',
        'component'=>'trade_theory','instructor_id'=>1];
    expectStatus(http('POST',$sessionEndpoint,[]),401);
    expectStatus(http('POST',$sessionEndpoint,$sessionInput,$tokens['student']),403);
    expectStatus(http('POST',$sessionEndpoint,[...$sessionInput,'instructor_id'=>'1'],$tokens['administrator']),422);
    expectStatus(http('POST',$sessionEndpoint,[...$sessionInput,'session_date'=>'2026-02-30'],$tokens['administrator']),422);
    expectStatus(http('POST',$sessionEndpoint,$sessionInput,$tokens['administrator']),404);
    $lockEndpoint='/api/v1/attendance/sessions/lock';
    expectStatus(http('POST',$lockEndpoint,[]),401);
    expectStatus(http('POST',$lockEndpoint,['session_id'=>1],$tokens['administrator']),403);
    expectStatus(http('POST',$lockEndpoint,['session_id'=>'1'],$tokens['principal']),422);
    expectStatus(http('POST',$lockEndpoint,['session_id'=>999999],$tokens['principal']),404);
    // Platform Developer performs institute onboarding, not regulatory affiliation.
    $platformBase='/api/v1/platform/institutes';
    $newInstitute=['name'=>'LOCAL TEST C','mis_code'=>'LOCAL-C',
        'address'=>'LOCAL ONBOARDING','district_name'=>'LOCAL',
        'management_type'=>'LOCAL'];
    expectStatus(http('POST',$platformBase,$newInstitute),401);
    expectStatus(http('POST',$platformBase,$newInstitute,$tokens['principal']),403);
    expectStatus(http('POST',$platformBase,[...$newInstitute,'mis_code'=>''],$tokens['developer']),422);
    $createdInstitute=http('POST',$platformBase,$newInstitute,$tokens['developer']);
    expectStatus($createdInstitute,201);
    $instituteC=(int)$createdInstitute['body']['data']['institute_id'];
    verify($instituteC>2 && $createdInstitute['body']['data']['affiliation_verified']===false,
        'Institute registration must not represent official affiliation approval.');
    expectStatus(http('POST',$platformBase,$newInstitute,$tokens['developer']),409);
    $instituteList=http('GET',$platformBase.'?page_size=100',token:$tokens['developer']);
    expectStatus($instituteList,200);
    verify(in_array($instituteC,array_map('intval',array_column(
        $instituteList['body']['data']['institutes'],'id')),true),
        'Developer institute directory missing newly onboarded tenant.');
    expectStatus(http('GET',$platformBase,token:$tokens['administrator']),403);
    $principalUrl=$platformBase.'/principal';
    $principalInput=['institute_id'=>$instituteC,'employee_code'=>'PRINCIPAL-C',
        'full_name'=>'Local Principal C','designation'=>'Principal','joined_on'=>'2026-01-01',
        'appointment_reference'=>'LOCAL APPOINTMENT - NOT OFFICIAL',
        'username'=>'local-principal-c','temporary_password'=>'Local-Principal-Password-123'];
    expectStatus(http('POST',$principalUrl,[...$principalInput,'institute_id'=>1],$tokens['administrator']),403);
    expectStatus(http('POST',$principalUrl,[...$principalInput,'joined_on'=>'2026-02-30'],$tokens['developer']),422);
    expectStatus(http('POST',$principalUrl,[...$principalInput,'institute_id'=>999999],$tokens['developer']),404);
    $principalCreated=http('POST',$principalUrl,$principalInput,$tokens['developer']);
    expectStatus($principalCreated,201);
    $principalId=(int)$principalCreated['body']['data']['user_id'];
    verify($principalId>0 && $principalCreated['body']['data']['must_change_password']===true,
        'Principal provisioning must enforce first-login password change.');
    expectStatus(http('POST',$principalUrl,$principalInput,$tokens['developer']),409);
    verify((int)$db->query("SELECT COUNT(*) FROM staff WHERE institute_id=$instituteC
        AND principal_assignment='principal' AND left_on IS NULL")->fetchColumn()===1,
        'Only one current Principal assignment is allowed.');
    $principalLogin=http('POST','/api/v1/auth/login',[
        'username'=>$principalInput['username'],'password'=>$principalInput['temporary_password'],
        'institute_id'=>$instituteC]);
    expectStatus($principalLogin,200);
    $principalToken=$principalLogin['body']['data']['access_token'];
    expectStatus(http('GET','/api/v1/institute/profile',token:$principalToken),403);
    expectStatus(http('POST','/api/v1/auth/change-password',[
        'current_password'=>$principalInput['temporary_password'],
        'new_password'=>'New-Principal-Password-456'],$principalToken),200);
    expectStatus(http('GET','/api/v1/auth/me',token:$principalToken),401);
    verify((int)$db->query("SELECT COUNT(*) FROM auth_audit_log
        WHERE event_type IN ('platform.institute.create','platform.principal.provision')
        AND user_id={$users['developer']['id']}")->fetchColumn()===2,
        'Platform onboarding and Principal provisioning must retain audit events.');
    $regBase='/api/v1/platform/regulatory/documents';
    $regData=['authority'=>'DGT','document_type'=>'CTS_LOCAL',
        'title'=>'LOCAL FIXTURE - NOT A DGT CIRCULAR','reference_no'=>'LOCAL-DOC-123',
        'issued_on'=>'2026-05-08','evidence_reference'=>'LOCAL-FIXTURE-NOT-GOVERNMENT',
        'sha256'=>str_repeat('a',64)];
    expectStatus(http('POST',$regBase.'/draft',$regData),401);
    expectStatus(http('POST',$regBase.'/draft',$regData,$tokens['principal']),403);
    expectStatus(http('POST',$regBase.'/draft',[...$regData,'sha256'=>'abc'],$tokens['developer']),422);
    expectStatus(http('POST',$regBase.'/draft',[...$regData,
        'authority'=>'STATE_UT'],$tokens['developer']),422);
    $docDraft=http('POST',$regBase.'/draft',$regData,$tokens['developer']);
    expectStatus($docDraft,201);
    $docId=(int)$docDraft['body']['data']['document_id'];
    verify($docId>0 && $docDraft['body']['data']['status']==='draft'
        && $docDraft['body']['data']['government_verified']===false,
        'New source record must remain unreviewed.');
    expectStatus(http('POST',$regBase.'/draft',$regData,$tokens['developer']),409);
    expectStatus(http('POST',$regBase.'/publish',[
        'document_id'=>$docId,'review_reference'=>''
    ],$tokens['developer']),422);
    expectStatus(http('POST',$regBase.'/publish',[
        'document_id'=>$docId,'review_reference'=>'LOCAL REVIEW ONLY'
    ],$tokens['principal']),403);
    expectStatus(http('POST',$regBase.'/publish',[
        'document_id'=>$docId,'review_reference'=>'LOCAL REVIEW ONLY'
    ],$tokens['developer']),200);
    expectStatus(http('POST',$regBase.'/publish',[
        'document_id'=>$docId,'review_reference'=>'LOCAL REVIEW ONLY'
    ],$tokens['developer']),409);
    verify($db->query("SELECT status FROM global_regulatory_documents
        WHERE id=$docId")->fetchColumn()==='published',
        'Reviewed source publication must persist.');
    $docList=http('GET',$regBase.'?page_size=100',token:$tokens['developer']);
    expectStatus($docList,200);
    verify(in_array($docId,array_map('intval',array_column(
        $docList['body']['data']['documents'],'id')),true),
        'Regulatory document not visible to platform reviewer.');
    expectStatus(http('GET',$regBase,token:$tokens['administrator']),403);
    $tradeBase='/api/v1/platform/trades';
    $tradeData=['dgt_code'=>'LOCAL-TRADE-001','source_document_id'=>$docId,
        'version_code'=>'LOCAL-2026','official_name'=>'LOCAL TEST TRADE',
        'trade_type'=>'engineering','effective_from'=>'2026-05-09'];
    expectStatus(http('POST',$tradeBase,$tradeData,$tokens['student']),403);
    expectStatus(http('POST',$tradeBase,[...$tradeData,'trade_type'=>'OTHER'],$tokens['developer']),422);
    $tradeDraft=http('POST',$tradeBase,$tradeData,$tokens['developer']);
    expectStatus($tradeDraft,201);
    $tradeId=(int)$tradeDraft['body']['data']['trade_id'];
    $tradeV1=(int)$tradeDraft['body']['data']['revision_id'];
    verify($tradeId>0 && $tradeV1>0 && $tradeDraft['body']['data']['status']==='draft',
        'Global Trade must not auto-activate on creation.');
    expectStatus(http('POST',$tradeBase,$tradeData,$tokens['developer']),409);
    $activateUrl=$tradeBase.'/revisions/activate';
    expectStatus(http('POST',$activateUrl,['revision_id'=>$tradeV1,
        'review_reference'=>'LOCAL REVIEW ONLY'],$tokens['developer']),200);
    expectStatus(http('POST',$activateUrl,['revision_id'=>$tradeV1,
        'review_reference'=>'LOCAL REVIEW ONLY'],$tokens['developer']),409);
    $tradeNext=['trade_id'=>$tradeId,'source_document_id'=>$docId,
        'version_code'=>'LOCAL-2027','official_name'=>'LOCAL TEST REVISED TRADE',
        'trade_type'=>'engineering','effective_from'=>'2026-10-01'];
    $revisionDraft=http('POST',$tradeBase.'/revisions',$tradeNext,$tokens['developer']);
    expectStatus($revisionDraft,201);
    $tradeV2=(int)$revisionDraft['body']['data']['revision_id'];
    expectStatus(http('POST',$activateUrl,['revision_id'=>$tradeV2,
        'review_reference'=>'LOCAL REVISION ONLY'],$tokens['developer']),200);
    verify($db->query("SELECT status FROM trade_master_revisions WHERE id=$tradeV1")->fetchColumn()==='superseded',
        'Old Global Trade revision must retain superseded history.');
    verify($db->query("SELECT effective_to FROM trade_master_revisions WHERE id=$tradeV1")->fetchColumn()==='2026-09-30',
        'Old Global Trade effective period must end at new version boundary.');
    verify($db->query("SELECT status FROM trade_master_revisions WHERE id=$tradeV2")->fetchColumn()==='active',
        'Reviewed Global Trade revision must be active.');
    $tradeList=http('GET',$tradeBase.'?page_size=100',token:$tokens['developer']);
    expectStatus($tradeList,200);
    verify(in_array($tradeId,array_map('intval',array_column(
        $tradeList['body']['data']['trades'],'id')),true),
        'Global Trade register must expose the stable Trade ID.');
    expectStatus(http('GET',$tradeBase,token:$tokens['administrator']),403);
    $sourceUrl='/api/v1/institute/regulatory/sources';
    expectStatus(http('POST',$sourceUrl,['global_document_id'=>$docId],$tokens['administrator']),403);
    expectStatus(http('POST',$sourceUrl,['global_document_id'=>'1'],$tokens['principal']),422);
    $sourceLinked=http('POST',$sourceUrl,[
        'global_document_id'=>$docId,'local_evidence_reference'=>'LOCAL-ITSU-EVIDENCE'
    ],$tokens['principal']);
    expectStatus($sourceLinked,201);
    $regSourceId=(int)$sourceLinked['body']['data']['regulatory_source_id'];
    expectStatus(http('POST',$sourceUrl,['global_document_id'=>$docId],$tokens['principal']),409);
    $tradeUnitUrl='/api/v1/itsu/trade-units';
    $unitData=['global_trade_id'=>$tradeId,'unit_no'=>1];
    expectStatus(http('POST',$tradeUnitUrl,$unitData,$tokens['student']),403);
    $unitCreated=http('POST',$tradeUnitUrl,$unitData,$tokens['administrator']);
    expectStatus($unitCreated,201);
    $tradeUnitId=(int)$unitCreated['body']['data']['trade_unit_id'];
    expectStatus(http('POST',$tradeUnitUrl,$unitData,$tokens['administrator']),409);
    // Isolated local fixture of the reviewed Global Shift master; production
    // data must originate from published documents and formal master workflow.
    $db->prepare("INSERT INTO global_shifts (shift_no,shift_code,display_name,
        revision_code,source_document_id,status,effective_from)
        VALUES (1,'LOCAL-SHIFT','Local Shift','LOCAL-V1',?,'active','2026-05-09')")
        ->execute([$docId]);
    $globalShiftId=(int)$db->lastInsertId();
    $itsuRevUrl='/api/v1/itsu/shift-revisions';
    $itsuRev=['trade_unit_id'=>$tradeUnitId,'global_shift_id'=>$globalShiftId,
        'regulatory_source_id'=>$regSourceId,
        'sanctioned_capacity'=>20,'approval_reference'=>'LOCAL-APPROVAL-ONLY',
        'valid_from'=>'2026-10-01','affiliation_status'=>'approved','training_mode'=>'CTS'];
    expectStatus(http('POST',$itsuRevUrl,[...$itsuRev,
        'sanctioned_capacity'=>'20'],$tokens['administrator']),422);
    expectStatus(http('POST',$itsuRevUrl,$itsuRev,$tokens['other-admin']),404);
    $itsuOne=http('POST',$itsuRevUrl,$itsuRev,$tokens['administrator']);
    expectStatus($itsuOne,201);
    $itsuOneId=(int)$itsuOne['body']['data']['itsu_revision_id'];
    expectStatus(http('POST',$itsuRevUrl,$itsuRev,$tokens['administrator']),409);
    $itsuTwo=http('POST',$itsuRevUrl,[...$itsuRev,
        'valid_from'=>'2026-10-02','sanctioned_capacity'=>24,
        'approval_reference'=>'LOCAL REVISION ONLY'],$tokens['administrator']);
    expectStatus($itsuTwo,201);
    $itsuTwoId=(int)$itsuTwo['body']['data']['itsu_revision_id'];
    verify($db->query("SELECT valid_to FROM trade_unit_shifts WHERE id=$itsuOneId")->fetchColumn()==='2026-10-01',
        'Historic ITSU revision must be closed before newer effective date.');
    verify($db->query("SELECT valid_to FROM trade_unit_shifts WHERE id=$itsuTwoId")->fetchColumn()===null,
        'Current ITSU revision must retain open-ended effective validity.');
    verify((int)$db->query("SELECT COUNT(*) FROM institute_activity_log
        WHERE action_code IN ('itsu.trade_unit.create','itsu.shift.revision','regulatory.source.attach')")->fetchColumn()===4,
        'New institute academic setup operations require auditable records.');
    // Official academic data is simulated only in the isolated disposable
    // test database; no fabricated evidence belongs to an ERP installation.
    $db->prepare("INSERT INTO cts_training_calendars
        (admission_year,session_code,revision_code,source_document_id,
         training_starts_on,training_ends_on,status)
        VALUES (2026,'LOCAL-CTS','LOCAL-V1',?,'2026-10-05','2027-05-30','published')")
        ->execute([$docId]);
    $calendarId=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO cts_portal_shift_mappings
        (calendar_id,session_part,physical_shift_no,portal_shift_no,document_section_reference)
        VALUES (?,'primary',1,1,'LOCAL SHIFT MAPPING')")
        ->execute([$calendarId]);
    $instructorStaff=(int)$db->query("SELECT staff_id FROM auth_staff_links
        WHERE user_id={$users['instructor']['id']} AND institute_id=1")->fetchColumn();
    $db->exec("INSERT INTO instructor_identities(iprn) VALUES ('LOCAL-NOT-NIMS-IPRN')");
    $iprnIdentity=(int)$db->lastInsertId();
    $db->prepare("UPDATE staff SET identity_id=?,nims_verification='verified',
        verification_reference='LOCAL-NOT-NIMS',verified_on='2026-10-01'
        WHERE id=? AND institute_id=1")->execute([$iprnIdentity,$instructorStaff]);
    $db->prepare("INSERT INTO instructor_mappings
        (institute_id,staff_id,mapping_type,identity_id,trade_unit_shift_id,valid_from,
         qualification_source_id,qualification_check_json,qualification_checked_by,nims_reference)
        VALUES (1,?,'trade',?,?,'2026-10-01',?,'{}','LOCAL TEST','LOCAL NOT NIMS')")
        ->execute([$instructorStaff,$iprnIdentity,$itsuTwoId,$regSourceId]);
    $finalDoc=['authority'=>'DGT','document_type'=>'final_cts_seat_matrix',
        'title'=>'LOCAL FINAL MATRIX FIXTURE - NOT OFFICIAL',
        'reference_no'=>'LOCAL-FINAL-SEAT-MATRIX-2026','issued_on'=>'2026-09-01',
        'evidence_reference'=>'LOCAL-NOT-GOVERNMENT','sha256'=>str_repeat('b',64)];
    $finalDraft=http('POST',$regBase.'/draft',$finalDoc,$tokens['developer']);
    expectStatus($finalDraft,201);
    $finalDocId=(int)$finalDraft['body']['data']['document_id'];
    expectStatus(http('POST',$regBase.'/publish',[
        'document_id'=>$finalDocId,'review_reference'=>'LOCAL TEST RECORD'
    ],$tokens['developer']),200);
    $finalSource=http('POST',$sourceUrl,[
        'global_document_id'=>$finalDocId,'local_evidence_reference'=>'LOCAL TEST ONLY'
    ],$tokens['principal']);
    expectStatus($finalSource,201);
    $finalSourceId=(int)$finalSource['body']['data']['regulatory_source_id'];
    $intakeUrl='/api/v1/admission/intakes';
    $intakeInput=['calendar_id'=>$calendarId,'admission_year'=>2026,
        'intake_code'=>'LOCAL-OCTOBER','session_part'=>'primary'];
    expectStatus(http('POST',$intakeUrl,[...$intakeInput,'admission_year'=>'2026'],$tokens['administrator']),422);
    $newIntake=http('POST',$intakeUrl,$intakeInput,$tokens['administrator']);
    expectStatus($newIntake,201);
    $intakeId=(int)$newIntake['body']['data']['intake_id'];
    expectStatus(http('POST',$intakeUrl,$intakeInput,$tokens['administrator']),409);
    $matrixUrl='/api/v1/seat-matrix/versions';
    $matrixData=['intake_id'=>$intakeId,'regulatory_source_id'=>$finalSourceId,
        'version_code'=>'LOCAL-FINAL-V1','portal_reference'=>'LOCAL NOT PORTAL'];
    $draftMatrix=http('POST',$matrixUrl,$matrixData,$tokens['administrator']);
    expectStatus($draftMatrix,201);
    $matrixVersion=(int)$draftMatrix['body']['data']['matrix_version_id'];
    $entryUrl='/api/v1/seat-matrix/entries';
    $entryData=['matrix_version_id'=>$matrixVersion,'itsu_revision_id'=>$itsuTwoId,
        'seat_capacity'=>24,'admission_allowed'=>true,
        'portal_itsu_reference'=>'LOCAL NOT NIMS ITSU'];
    expectStatus(http('POST',$entryUrl,[...$entryData,'seat_capacity'=>25],$tokens['administrator']),409);
    expectStatus(http('POST',$entryUrl,[...$entryData,'admission_allowed'=>1],$tokens['administrator']),422);
    $newEntry=http('POST',$entryUrl,$entryData,$tokens['administrator']);
    expectStatus($newEntry,201);
    $matrixEntryId=(int)$newEntry['body']['data']['matrix_entry_id'];
    expectStatus(http('POST',$entryUrl,$entryData,$tokens['administrator']),409);
    $finalizeUrl='/api/v1/seat-matrix/versions/finalize';
    expectStatus(http('POST',$finalizeUrl,[
        'matrix_version_id'=>$matrixVersion,'review_reference'=>'LOCAL REVIEW'
    ],$tokens['administrator']),403);
    expectStatus(http('POST',$finalizeUrl,[
        'matrix_version_id'=>$matrixVersion,'review_reference'=>'LOCAL REVIEW'
    ],$tokens['principal']),200);
    verify($db->query("SELECT status FROM seat_matrix_versions
        WHERE id=$matrixVersion")->fetchColumn()==='final',
        'Principal review must leave actual local matrix finalized.');
    expectStatus(http('POST',$finalizeUrl,[
        'matrix_version_id'=>$matrixVersion,'review_reference'=>'LOCAL REVIEW'
    ],$tokens['principal']),409);
    expectStatus(http('POST',$entryUrl,$entryData,$tokens['administrator']),409);
    verify((int)$db->query("SELECT instructor_mapping_satisfied FROM seat_matrix_entries
        WHERE id=$matrixEntryId")->fetchColumn()===1,
        'Final matrix eligible entry must be bound to NIMS fixture mapping.');
    $db->prepare("INSERT INTO global_curriculum_versions
      (trade_id,source_document_id,version_code,duration_months,nsqf_level,
       unit_strength,effective_from,status)
      VALUES (?,?,'LOCAL-CURRICULUM',12,4.0,24,'2026-05-09','published')")
      ->execute([$tradeId,$docId]);
    $globalCurriculum=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO global_curriculum_years
      (global_curriculum_id,year_no,trade_practical_minutes,
       trade_theory_minutes,employability_minutes,ojt_project_minutes)
      VALUES (?,1,72000,20000,10000,0)")->execute([$globalCurriculum]);
    $globalYear=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO global_admission_rule_versions
      (trade_id,global_curriculum_id,source_document_id,admission_window_document_id,
       version_code,minimum_age_years,age_cutoff_date,qualification_routes_json,
       admission_opens_on,admission_closes_on,status)
      VALUES (?,?,?,?,'LOCAL-RULE',14,'2026-10-01',?,
       '2026-01-01','2026-12-31','published')")->execute([
       $tradeId,$globalCurriculum,$docId,$docId,
       json_encode(['LOCAL'=>['level_codes'=>['10']]],JSON_THROW_ON_ERROR)]);
    $globalRule=(int)$db->lastInsertId();
    $linkCurriculumUrl='/api/v1/academic/curriculum-links';
    expectStatus(http('POST',$linkCurriculumUrl,[
        'global_curriculum_id'=>$globalCurriculum],$tokens['student']),403);
    $linkedCurriculum=http('POST',$linkCurriculumUrl,[
        'global_curriculum_id'=>$globalCurriculum],$tokens['administrator']);
    expectStatus($linkedCurriculum,201);
    $localCurriculum=(int)$linkedCurriculum['body']['data']['curriculum_id'];
    expectStatus(http('POST',$linkCurriculumUrl,[
        'global_curriculum_id'=>$globalCurriculum],$tokens['administrator']),409);
    $localYearResult=http('POST','/api/v1/academic/curriculum-year-links',[
        'curriculum_id'=>$localCurriculum,'global_curriculum_year_id'=>$globalYear
    ],$tokens['administrator']);
    expectStatus($localYearResult,201);
    $localYear=(int)$localYearResult['body']['data']['curriculum_year_id'];
    $localRuleResult=http('POST','/api/v1/admission/rule-links',[
        'curriculum_id'=>$localCurriculum,'global_admission_rule_id'=>$globalRule
    ],$tokens['administrator']);
    expectStatus($localRuleResult,201);
    $localRule=(int)$localRuleResult['body']['data']['admission_rule_id'];
    $batchUrl='/api/v1/batches';
    $batchInput=['seat_matrix_entry_id'=>$matrixEntryId,
        'curriculum_id'=>$localCurriculum,'admission_rule_id'=>$localRule,
        'batch_code'=>'LOCAL-CTS-BATCH','starts_on'=>'2026-10-05','ends_on'=>'2027-05-30'];
    expectStatus(http('POST',$batchUrl,[...$batchInput,'ends_on'=>'2026-02-30'],$tokens['administrator']),422);
    $newBatch=http('POST',$batchUrl,$batchInput,$tokens['administrator']);
    expectStatus($newBatch,201);
    $batchId=(int)$newBatch['body']['data']['batch_id'];
    verify((int)$db->query("SELECT capacity FROM batches WHERE id=$batchId")->fetchColumn()===24,
        'Batch capacity must be taken from the final matrix, never client input.');
    verify($db->query("SELECT status FROM batches WHERE id=$batchId")->fetchColumn()==='planned',
        'Initial source-linked batch must remain planned.');
    expectStatus(http('POST',$batchUrl,$batchInput,$tokens['administrator']),409);
    $batchYearUrl='/api/v1/batches/years';
    $yearInput=['batch_id'=>$batchId,'curriculum_year_id'=>$localYear,
        'starts_on'=>'2026-10-05','ends_on'=>'2027-05-30',
        'attendance_basis'=>'minutes','attendance_basis_source_id'=>$regSourceId];
    expectStatus(http('POST',$batchYearUrl,[...$yearInput,
        'attendance_basis'=>'unverified'],$tokens['administrator']),422);
    $createdBatchYear=http('POST',$batchYearUrl,$yearInput,$tokens['administrator']);
    expectStatus($createdBatchYear,201);
    $batchYearId=(int)$createdBatchYear['body']['data']['batch_year_id'];
    verify($batchYearId>0 && $db->query("SELECT calendar_status FROM batch_years
        WHERE id=$batchYearId")->fetchColumn()==='draft',
        'New batch year must not auto-certify attendance calendar.');
    expectStatus(http('POST',$batchYearUrl,$yearInput,$tokens['administrator']),409);
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
