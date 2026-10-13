<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Http\Router;
use App\Modules\Health\HealthController;
use App\Http\Request;
use App\Http\HttpException;
use App\Modules\Auth\AuthContext;

$router = new Router();
$router->get('/api/v1/health', new HealthController());
$checks = [
    $router->dispatch('GET', '/api/v1/health')->body['data']['status'] === 'ok',
    $router->dispatch('GET', '/unknown')->status === 404,
    $router->dispatch('POST', '/api/v1/health')->status === 405,
    $router->dispatch('POST', '/api/v1/health')->headers['Allow'] === 'GET',
];
$router->post('/test', static fn(Request $request) => new App\Http\Response(['value' => $request->body['value']]));
$checks[] = $router->dispatch('POST', '/test', new Request(['value' => 17]))->body['value'] === 17;
$application = require dirname(__DIR__) . '/bootstrap/app.php';
$checks[] = $application->dispatch('GET', '/api/v1/health')->status === 200;
$checks[] = $application->dispatch('GET', '/api/v1/auth/login')->status === 405;
$kernel = new App\Http\Kernel();
$checks[] = $kernel->handle(static fn() => $application->dispatch('GET', '/api/v1/auth/me'))->status === 401;
$checks[] = $kernel->handle(static fn() => new App\Http\Response(['bad' => "\xff"]))->status === 500;
$checks[] = $kernel->handle(static fn() => throw new HttpException('TEST_ERROR', 'Safe test error.', 422))->status === 422;
$errorResponse = $kernel->handle(static fn() => throw new RuntimeException('private-database-detail'));
$checks[] = !str_contains(json_encode($errorResponse->body), 'private-database-detail');
$checks[] = $errorResponse->headers['X-Request-ID'] !== $kernel->handle(static fn() => new App\Http\Response([]))->headers['X-Request-ID'];
$environmentFile = tempnam(sys_get_temp_dir(), 'iti-env-test-');
try {
    putenv('ITI_TEST_EXISTING=process-wins');
    file_put_contents($environmentFile, "ITI_TEST_EXISTING=file-value\nITI_TEST_QUOTED='quoted value'\nITI_TEST_LITERAL=trailing'\nITI_TEST_EMPTY=\n");
    App\Core\Environment::load($environmentFile);
    $checks[] = App\Core\Environment::get('ITI_TEST_EXISTING') === 'process-wins';
    $checks[] = App\Core\Environment::get('ITI_TEST_QUOTED') === 'quoted value';
    $checks[] = App\Core\Environment::get('ITI_TEST_LITERAL') === "trailing'";
    $checks[] = App\Core\Environment::get('ITI_TEST_EMPTY', 'default') === '';
    file_put_contents($environmentFile, "invalid-key=value\n");
    try {
        App\Core\Environment::load($environmentFile);
        $checks[] = false;
    } catch (RuntimeException) {
        $checks[] = true;
    }
} finally {
    unlink($environmentFile);
    foreach (['ITI_TEST_EXISTING','ITI_TEST_QUOTED','ITI_TEST_LITERAL','ITI_TEST_EMPTY'] as $environmentKey) {
        putenv($environmentKey);
    }
}
$throttleDirectory = sys_get_temp_dir() . '/iti-throttle-test-' . bin2hex(random_bytes(6));
try {
    $throttle = new App\Modules\Auth\LoginThrottle($throttleDirectory, 2, 60);
    $throttle->check('127.0.0.1');
    $throttle->check('127.0.0.1');
    $checks[] = $kernel->handle(static function() use ($throttle) {
        $throttle->check('127.0.0.1');
        return new App\Http\Response([]);
    })->status === 429;
    $throttle->check('127.0.0.2');
    $checks[] = true;
    $bucket = $throttleDirectory . '/' . hash('sha256', '127.0.0.1') . '.json';
    file_put_contents($bucket, json_encode(['until'=>time()-1,'count'=>2]));
    $throttle->check('127.0.0.1');
    $checks[] = json_decode(file_get_contents($bucket), true)['count'] === 1;
    $storageFailure = new App\Modules\Auth\LoginThrottle($bucket . '/not-a-directory');
    $checks[] = $kernel->handle(static function() use ($storageFailure) {
        $storageFailure->check('127.0.0.1');
        return new App\Http\Response([]);
    })->status === 503;
    // Multiple processes must share one atomic limit, not each allow two attempts.
    $workers = [];
    $workerCode = 'require $argv[1]; try { (new App\\Modules\\Auth\\LoginThrottle($argv[2],2,60))->check("127.0.0.3"); echo "200"; } catch (App\\Http\\HttpException $e) { echo $e->status; }';
    for ($workerIndex=0; $workerIndex<6; $workerIndex++) {
        $worker = proc_open([PHP_BINARY,'-r',$workerCode,dirname(__DIR__) . '/bootstrap/autoload.php',$throttleDirectory], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $workerPipes);
        if (!is_resource($worker)) {
            throw new RuntimeException('Throttle test worker could not start.');
        }
        fclose($workerPipes[0]);
        $workers[] = [$worker,$workerPipes];
    }
    $statuses = [];
    foreach ($workers as [$worker,$workerPipes]) {
        $statuses[] = stream_get_contents($workerPipes[1]);
        $workerError = stream_get_contents($workerPipes[2]);
        fclose($workerPipes[1]);
        fclose($workerPipes[2]);
        if (proc_close($worker) !== 0 || $workerError !== '') {
            throw new RuntimeException('Throttle test worker failed.');
        }
    }
    $checks[] = count(array_filter($statuses, static fn($status) => $status === '200')) === 2;
    $checks[] = count(array_filter($statuses, static fn($status) => $status === '429')) === 4;
} finally {
    foreach (glob($throttleDirectory . '/*.json') ?: [] as $throttleFile) {
        unlink($throttleFile);
    }
    if (is_dir($throttleDirectory)) {
        rmdir($throttleDirectory);
    }
}
$context = new AuthContext(1, 3, 1, 'test', false, ['administrator'], ['staff.view'], 7, null);
$context->requirePermission('staff.view');
$context->requireInstitute(3);
foreach ([static fn() => $context->requirePermission('fee.refund'), static fn() => $context->requireInstitute(4)] as $reject) {
    try {
        $reject();
        $checks[] = false;
    } catch (HttpException $exception) {
        $checks[] = $exception->status === 403;
    }
}
// Reject unauthorised or malformed operational requests before opening a DB connection.
$operations = new App\Modules\Operations\OperationsController(new App\Core\Database([]));
$operator = new AuthContext(1, 3, 1, 'test', false, ['administrator'], ['enrollment.manage', 'exam.eligibility.manage'], 7, null);
$checks[] = $kernel->handle(static fn() => $operations->activateEnrollment(new Request(['enrollment_id' => '1']), $operator))->status === 422;
$checks[] = $kernel->handle(static fn() => $operations->activateEnrollment(new Request(['enrollment_id' => 0]), $operator))->status === 422;
$checks[] = $kernel->handle(static fn() => $operations->cancelEnrollment(new Request(['enrollment_id' => 1, 'reason' => '  ']), $operator))->status === 422;
$checks[] = $kernel->handle(static fn() => $operations->prepareTraineeUpload(new Request(['enrollment_id' => -1]), $operator))->status === 422;
$checks[] = $kernel->handle(static fn() => $operations->freezeEligibility(new Request(['enrollment_id' => 1, 'batch_year_id' => 1, 'cycle_id' => 1, 'evidence_reference' => '']), $operator))->status === 422;
$checks[] = $kernel->handle(static fn() => $operations->activateEnrollment(new Request(['enrollment_id' => 1]), $context))->status === 403;
$platform = new AuthContext(2, null, 2, 'developer', false, ['developer'], ['enrollment.manage'], null, null);
$checks[] = $kernel->handle(static fn() => $operations->activateEnrollment(new Request(['enrollment_id' => 1]), $platform))->status === 403;
$staffController = new App\Modules\Staff\StaffController(new App\Core\Database([]));
$staffWriter = new AuthContext(1, 3, 1, 'test', false, ['administrator'], ['staff.manage', 'staff.view'], 7, null);
$checks[] = $kernel->handle(static fn() => $staffController->create(new Request(['employee_code' => 'STAFF1', 'full_name' => 'Test', 'staff_category' => 'unknown', 'designation' => 'Instructor', 'joined_on' => '2026-01-01']), $staffWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $staffController->create(new Request(['employee_code' => 'STAFF1', 'full_name' => 'Test', 'staff_category' => 'teaching', 'designation' => 'Instructor', 'joined_on' => '2026-02-30']), $staffWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $staffController->create(new Request(['employee_code' => 'STAFF1', 'full_name' => 'Test', 'staff_category' => 'teaching', 'designation' => 'Instructor', 'joined_on' => '2026-01-01']), $context))->status === 403;
$checks[] = $kernel->handle(static fn() => $staffController->index(new Request(query: ['page_size' => '101']), $staffWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $staffController->index(new Request(query: ['after_id' => ['1']]), $staffWriter))->status === 422;
$studentController = new App\Modules\Students\StudentController(new App\Core\Database([]));
$studentWriter = new AuthContext(1, 3, 1, 'test', false, ['administrator'], ['student.manage', 'student.view'], 7, null);
$draft = ['local_student_code'=>'LOCAL-NEW','full_name'=>'Local Test','date_of_birth'=>'2005-01-01','gender'=>'test','address'=>'local','identity_mode'=>'non_aadhaar','identity_evidence_reference'=>'LOCAL-ONLY'];
$checks[] = $kernel->handle(static fn() => $studentController->create(new Request([...$draft, 'identity_mode'=>'invalid']), $studentWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $studentController->create(new Request([...$draft, 'date_of_birth'=>'2005-02-30']), $studentWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $studentController->create(new Request($draft), $context))->status === 403;
$checks[] = $kernel->handle(static fn() => $studentController->index(new Request(query: ['after_id'=>['1']]), $studentWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $studentController->own(new Request(), $studentWriter))->status === 403;
$catalog = new App\Modules\Catalog\CatalogController(new App\Core\Database([]));
$catalogReader = new AuthContext(1, 3, 1, 'test', false, ['instructor'], ['tradeunit.view','batch.view'], 7, null);
$checks[] = $kernel->handle(static fn() => $catalog->itsu(new Request(query: ['page_size' => '101']), $catalogReader))->status === 422;
$checks[] = $kernel->handle(static fn() => $catalog->batches(new Request(query: ['after_id' => ['5']]), $catalogReader))->status === 422;
$checks[] = $kernel->handle(static fn() => $catalog->itsu(new Request(), $context))->status === 403;
$checks[] = $kernel->handle(static fn() => $catalog->batches(new Request(), $platform))->status === 403;
$fees = new App\Modules\Fees\FeeController(new App\Core\Database([]));
$feeWriter = new AuthContext(7, 3, 1, 'cashier', false, ['administrator'], ['fee.record','fee.refund','fee.view'], 7, null);
$feeStudent = new AuthContext(8, 3, 2, 'student', false, ['student'], ['fee.view.own'], null, 21);
$checks[] = $kernel->handle(static fn() => $fees->recordPayment(new Request(['student_id'=>1,'amount'=>10,'payment_mode'=>'cash','payment_no'=>'A','receipt_no'=>'B','idempotency_key'=>'C']), $feeWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->allocate(new Request(['payment_id'=>1,'demand_id'=>1,'amount'=>3.50]), $feeWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->issueAssessment(new Request(['assessment_id'=>'2']), $feeWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->requestRefund(new Request(['allocation_id'=>0]), $feeWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->decideRefund(new Request(['refund_id'=>1,'action'=>'created']), $feeWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->reversePayment(new Request(['payment_id'=>2,'requested_by'=>2,'reversal_no'=>'','reason'=>'x']), $feeWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->recordPayment(new Request([]), $context))->status === 403;
$checks[] = $kernel->handle(static fn() => $fees->payments(new Request(query:['page_size'=>'101']), $feeWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->ownPayments(new Request(query:['after_id'=>['3']]), $feeStudent))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->ownPayments(new Request(), $feeWriter))->status === 403;
$attendance = new App\Modules\Attendance\AttendanceController(new App\Core\Database([]));
$attendanceAdmin = new AuthContext(7, 3, 1, 'test', false, ['administrator'], ['attendance.view'], 7, null);
$attendanceInstructor = new AuthContext(8, 3, 2, 'test-instructor', false, ['instructor'], ['attendance.view.assigned'], 12, null);
$attendanceStudent = new AuthContext(9, 3, 3, 'test-student', false, ['student'], ['attendance.view.own'], null, 5);
$checks[] = $kernel->handle(static fn() => $attendance->instituteRecords(new Request(query:['page_size'=>'101']), $attendanceAdmin))->status === 422;
$checks[] = $kernel->handle(static fn() => $attendance->assignedRecords(new Request(query:['after_session_id'=>'1']), $attendanceInstructor))->status === 422;
$checks[] = $kernel->handle(static fn() => $attendance->ownRecords(new Request(query:['after_session_id'=>'1','after_enrollment_id'=>['2']]), $attendanceStudent))->status === 422;
$checks[] = $kernel->handle(static fn() => $attendance->instituteRecords(new Request(), $attendanceInstructor))->status === 403;
$checks[] = $kernel->handle(static fn() => $attendance->assignedRecords(new Request(), $attendanceStudent))->status === 403;
$checks[] = $kernel->handle(static fn() => $attendance->ownRecords(new Request(), $attendanceAdmin))->status === 403;
$checks[] = $kernel->handle(static fn() => $attendance->ownRecords(new Request(), $platform))->status === 403;
$officialRecords = new App\Modules\OfficialRecords\OfficialRecordsController(new App\Core\Database([]));
$officialStaff = new AuthContext(7, 3, 1, 'principal', false, ['principal'], ['result.view','certificate.view'], 7, null);
$officialStudent = new AuthContext(8, 3, 2, 'student', false, ['student'], ['result.view.own','certificate.view.own'], null, 12);
$checks[] = $kernel->handle(static fn() => $officialRecords->results(new Request(query:['page_size'=>'101']), $officialStaff))->status === 422;
$checks[] = $kernel->handle(static fn() => $officialRecords->ownResults(new Request(query:['after_id'=>['1']]), $officialStudent))->status === 422;
$checks[] = $kernel->handle(static fn() => $officialRecords->certificates(new Request(query:['page_size'=>0]), $officialStaff))->status === 422;
$checks[] = $kernel->handle(static fn() => $officialRecords->ownCertificates(new Request(query:['after_id'=>'-1']), $officialStudent))->status === 422;
$checks[] = $kernel->handle(static fn() => $officialRecords->results(new Request(), $officialStudent))->status === 403;
$checks[] = $kernel->handle(static fn() => $officialRecords->ownCertificates(new Request(), $officialStaff))->status === 403;
$checks[] = $kernel->handle(static fn() => $officialRecords->ownResults(new Request(), $platform))->status === 403;
$attendanceMarker = new AuthContext(8, 3, 2, 'test-instructor', false, ['instructor'], ['attendance.mark.assigned'], 12, null);
$checks[] = $kernel->handle(static fn() => $attendance->markAssigned(
    new Request(['session_id'=>'1','enrollment_id'=>2,'attended_minutes'=>40,'status'=>'present']), $attendanceMarker))->status === 422;
$checks[] = $kernel->handle(static fn() => $attendance->markAssigned(
    new Request(['session_id'=>1,'enrollment_id'=>2,'attended_minutes'=>40.0,'status'=>'present']), $attendanceMarker))->status === 422;
$checks[] = $kernel->handle(static fn() => $attendance->markAssigned(
    new Request(['session_id'=>1,'enrollment_id'=>2,'attended_minutes'=>60,'status'=>'absent']), $attendanceMarker))->status === 422;
$checks[] = $kernel->handle(static fn() => $attendance->markAssigned(
    new Request(['session_id'=>1,'enrollment_id'=>2,'attended_minutes'=>60,'status'=>'unreviewed']), $attendanceMarker))->status === 422;
$checks[] = $kernel->handle(static fn() => $attendance->markAssigned(
    new Request(['session_id'=>1,'enrollment_id'=>2,'attended_minutes'=>60,'status'=>'present']), $attendanceAdmin))->status === 403;
$admissionRegistry = new App\Modules\Admissions\AdmissionRegistryController(new App\Core\Database([]));
$admissionReader = new AuthContext(7,3,1,'administrator',false,['administrator'],['enrollment.view','seatmatrix.view','calendar.view'],7,null);
$checks[] = $kernel->handle(static fn() => $admissionRegistry->enrollments(new Request(query:['page_size'=>'101']), $admissionReader))->status === 422;
$checks[] = $kernel->handle(static fn() => $admissionRegistry->seatMatrixEntries(new Request(query:['after_id'=>['10']]), $admissionReader))->status === 422;
$checks[] = $kernel->handle(static fn() => $admissionRegistry->academicCalendars(new Request(query:['page_size'=>'0']), $admissionReader))->status === 422;
$checks[] = $kernel->handle(static fn() => $admissionRegistry->enrollments(new Request(), $catalogReader))->status === 403;
$checks[] = $kernel->handle(static fn() => $admissionRegistry->seatMatrixEntries(new Request(), $platform))->status === 403;
$draftBody = [
    'student_id'=>11,'batch_id'=>12,'qualification_id'=>13,
    'admission_intake_revision_id'=>14,'admission_number'=>'LOCAL-DRAFT',
    'admitted_on'=>'2026-08-01','qualification_route_code'=>'LOCAL'
];
$checks[] = $kernel->handle(static fn() => $operations->createDraftEnrollment(
    new Request([...$draftBody,'student_id'=>'11']), $operator))->status === 422;
$checks[] = $kernel->handle(static fn() => $operations->createDraftEnrollment(
    new Request([...$draftBody,'admitted_on'=>'2026-02-30']), $operator))->status === 422;
$checks[] = $kernel->handle(static fn() => $operations->createDraftEnrollment(
    new Request([...$draftBody,'admission_number'=>'']), $operator))->status === 422;
$checks[] = $kernel->handle(static fn() => $operations->createDraftEnrollment(
    new Request($draftBody), $context))->status === 403;
$checks[] = $kernel->handle(static fn() => $operations->createDraftEnrollment(
    new Request($draftBody), $platform))->status === 403;
foreach ($checks as $check) {
    if (!$check) {
        throw new RuntimeException('Backend contract failed.');
    }
}
echo 'Backend contracts passed (' . count($checks) . " checks).\n";
