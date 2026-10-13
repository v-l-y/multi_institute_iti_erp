<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Http\Router;
use App\Modules\Health\HealthController;
use App\Http\Request;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;

$router = new Router();
$router->get('/api/v1/health', new HealthController());
$checks = [
    $router->dispatch('GET', '/api/v1/health')->body['data']['status'] === 'ok',
    $router->dispatch('GET', '/unknown')->status === 404,
    $router->dispatch('POST', '/api/v1/health')->status === 405,
    $router->dispatch('POST', '/api/v1/health')->headers['Allow'] === 'GET',
];
$duplicateRouteRejected = false;
try {
    $router->get('/api/v1/health', static fn() => new App\Http\Response(['success' => false]));
} catch (LogicException $exception) {
    $duplicateRouteRejected = $exception->getMessage() === 'Duplicate route registration: GET /api/v1/health.';
}
$checks[] = $duplicateRouteRejected;
$checks[] = $router->dispatch('GET', '/api/v1/health')->body['data']['status'] === 'ok';
// Verify PSR-4 module classes and the required architectural directories.
$checks[] = class_exists(\App\Modules\Admissions\Application\AdmissionDraftService::class);
$checks[] = class_exists(\App\Modules\Admissions\Application\AdmissionReviewService::class);
$checks[] = class_exists(\App\Modules\Admissions\Application\AdmissionService::class);
$checks[] = class_exists(\App\Modules\Admissions\Application\TraineeUploadService::class);
$checks[] = class_exists(\App\Modules\Admissions\Http\AdmissionRegistryController::class);
$checks[] = class_exists(\App\Modules\Admissions\Domain\TraineeUploadValidator::class);
$checks[] = is_dir(dirname(__DIR__) . '/app/Modules/Admissions/Http');
$checks[] = is_dir(dirname(__DIR__) . '/app/Modules/Admissions/Application');
$checks[] = is_dir(dirname(__DIR__) . '/app/Modules/Admissions/Domain');
$checks[] = is_dir(dirname(__DIR__) . '/app/Modules/Admissions/Infrastructure');
$checks[] = is_dir(dirname(__DIR__) . '/app/Modules/Academic');
$checks[] = is_dir(dirname(__DIR__) . '/database/migrations');
// Every requested layer must contain an autoloadable working class, not only a README.
foreach ([
    \App\Modules\Admissions\Infrastructure\AdmissionRegistryRepository::class,
    \App\Modules\Academic\Http\AcademicCalendarController::class,
    \App\Modules\Academic\Application\AcademicCalendarService::class,
    \App\Modules\Academic\Domain\CalendarPage::class,
    \App\Modules\Academic\Infrastructure\AcademicCalendarRepository::class,
    \App\Modules\Auth\Http\AuthController::class,
    \App\Modules\Auth\Http\AuthMiddleware::class,
    \App\Modules\Auth\Application\AuthService::class,
    \App\Modules\Auth\Domain\AuthContext::class,
    \App\Modules\Auth\Infrastructure\LoginThrottle::class,
    \App\Modules\Auth\Infrastructure\PasswordHasher::class,
    \App\Modules\Staff\Http\StaffController::class,
    \App\Modules\Staff\Application\StaffService::class,
    \App\Modules\Staff\Domain\StaffRecord::class,
    \App\Modules\Staff\Infrastructure\StaffRepository::class,
    \App\Modules\Students\Http\StudentController::class,
    \App\Modules\Students\Application\StudentRegistrationService::class,
    \App\Modules\Students\Application\StudentQualificationService::class,
    \App\Modules\Students\Domain\StudentIdentity::class,
    \App\Modules\Students\Infrastructure\StudentRepository::class,
    \App\Modules\Fees\Http\FeeController::class,
    \App\Modules\Fees\Application\FeeService::class,
    \App\Modules\Fees\Domain\Money::class,
    \App\Modules\Fees\Infrastructure\FeePaymentRepository::class,
    \App\Core\MigrationRunner::class,
] as $layerClass) {
    $checks[] = class_exists($layerClass);
}
foreach (['Admissions','Academic','Auth','Staff','Students','Fees'] as $module) {
    foreach (['Http','Application','Domain','Infrastructure'] as $layer) {
        $checks[] = is_dir(dirname(__DIR__) . '/app/Modules/' . $module . '/' . $layer);
    }
}
$checks[] = \App\Modules\Staff\Domain\StaffRecord::validCategoryAndJoiningDate('teaching', '2026-08-01');
$checks[] = !\App\Modules\Staff\Domain\StaffRecord::validCategoryAndJoiningDate('teaching', '2026-02-30');
$checks[] = \App\Modules\Students\Domain\StudentIdentity::validIdentityModeAndBirthDate('non_aadhaar', '2006-01-18');
$checks[] = !\App\Modules\Students\Domain\StudentIdentity::validIdentityModeAndBirthDate('aadhaar', '2006-02-30');
$checks[] = \App\Modules\Fees\Domain\Money::cents('100.01') === 10001;
$checks[] = \App\Modules\Academic\Domain\CalendarPage::integer(['page_size'=>'3'], 'page_size', 50, 100) === 3;
$checks[] = \App\Core\MigrationRunner::singleStatement('ALTER TABLE sample ADD COLUMN value INT;') === 'ALTER TABLE sample ADD COLUMN value INT';
$checks[] = \App\Core\MigrationRunner::discover(dirname(__DIR__) . '/database/migrations') === [];
$checks[] = is_file(dirname(__DIR__) . '/bin/migrate.php');
try {
    \App\Core\MigrationRunner::singleStatement('ALTER TABLE sample ADD COLUMN a INT; DROP TABLE sample;');
    $checks[] = false;
} catch (RuntimeException) {
    $checks[] = true;
}
foreach ([
    'Auth/AuthContext', 'Auth/AuthController', 'Auth/AuthMiddleware',
    'Auth/AuthService', 'Auth/LoginThrottle', 'Auth/PasswordHasher',
    'Staff/StaffController', 'Students/StudentController',
    'Students/StudentQualificationService', 'Fees/FeeController',
    'Fees/FeeService', 'Fees/Money',
] as $obsoleteAlias) {
    $checks[] = !is_file(dirname(__DIR__) . '/app/Modules/' . $obsoleteAlias . '.php');
}

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
    $throttle = new App\Modules\Auth\Infrastructure\LoginThrottle($throttleDirectory, 2, 60);
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
    $storageFailure = new App\Modules\Auth\Infrastructure\LoginThrottle($bucket . '/not-a-directory');
    $checks[] = $kernel->handle(static function() use ($storageFailure) {
        $storageFailure->check('127.0.0.1');
        return new App\Http\Response([]);
    })->status === 503;
    // Multiple processes must share one atomic limit, not each allow two attempts.
    $workers = [];
    $workerCode = 'require $argv[1]; try { (new App\\Modules\\Auth\\Infrastructure\\LoginThrottle($argv[2],2,60))->check("127.0.0.3"); echo "200"; } catch (App\\Http\\HttpException $e) { echo $e->status; }';
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
$staffController = new App\Modules\Staff\Http\StaffController(new App\Core\Database([]));
$staffWriter = new AuthContext(1, 3, 1, 'test', false, ['administrator'], ['staff.manage', 'staff.view'], 7, null);
$checks[] = $kernel->handle(static fn() => $staffController->create(new Request(['employee_code' => 'STAFF1', 'full_name' => 'Test', 'staff_category' => 'unknown', 'designation' => 'Instructor', 'joined_on' => '2026-01-01']), $staffWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $staffController->create(new Request(['employee_code' => 'STAFF1', 'full_name' => 'Test', 'staff_category' => 'teaching', 'designation' => 'Instructor', 'joined_on' => '2026-02-30']), $staffWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $staffController->create(new Request(['employee_code' => 'STAFF1', 'full_name' => 'Test', 'staff_category' => 'teaching', 'designation' => 'Instructor', 'joined_on' => '2026-01-01']), $context))->status === 403;
$checks[] = $kernel->handle(static fn() => $staffController->index(new Request(query: ['page_size' => '101']), $staffWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $staffController->index(new Request(query: ['after_id' => ['1']]), $staffWriter))->status === 422;
$studentController = new App\Modules\Students\Http\StudentController(new App\Core\Database([]));
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
$fees = new App\Modules\Fees\Http\FeeController(new App\Core\Database([]));
$feeWriter = new AuthContext(7, 3, 1, 'cashier', false, ['administrator'], ['fee.record','fee.refund','fee.view'], 7, null);
$feeStudent = new AuthContext(8, 3, 2, 'student', false, ['student'], ['fee.view.own'], null, 21);
$checks[] = $kernel->handle(static fn() => $fees->recordPayment(new Request(['student_id'=>1,'amount'=>10,'payment_mode'=>'cash','payment_no'=>'A','receipt_no'=>'B','idempotency_key'=>'C']), $feeWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->allocate(new Request(['payment_id'=>1,'demand_id'=>1,'amount'=>3.50]), $feeWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->issueAssessment(new Request(['assessment_id'=>'2']), $feeWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->issueDemand(new Request(['assessment_id'=>'1','demand_no'=>'D1','due_on'=>'2026-10-15']), $feeWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->issueDemand(new Request(['assessment_id'=>1,'demand_no'=>'D1','due_on'=>'2026-02-30']), $feeWriter))->status === 422;
$checks[] = $kernel->handle(static fn() => $fees->issueDemand(new Request(['assessment_id'=>1,'demand_no'=>'D1','due_on'=>'2026-10-15']), $context))->status === 403;
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
$admissionRegistry = new App\Modules\Admissions\Http\AdmissionRegistryController(new App\Core\Database([]));
$admissionReader = new AuthContext(7,3,1,'administrator',false,['administrator'],['enrollment.view','seatmatrix.view','calendar.view'],7,null);
$checks[] = $kernel->handle(static fn() => $admissionRegistry->enrollments(new Request(query:['page_size'=>'101']), $admissionReader))->status === 422;
$checks[] = $kernel->handle(static fn() => $admissionRegistry->seatMatrixEntries(new Request(query:['after_id'=>['10']]), $admissionReader))->status === 422;
$checks[] = $kernel->handle(static fn() => (new App\Modules\Academic\Http\AcademicCalendarController(new App\Core\Database([])))->index(new Request(query:['page_size'=>'0']), $admissionReader))->status === 422;
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
$reviewer = new AuthContext(8,3,4,'principal',false,['principal'],['enrollment.manage'],8,null);
$reviewBody = ['enrollment_id'=>1,'admission_route'=>'institute_direct',
    'admission_route_source_id'=>1,'admission_route_evidence_reference'=>'LOCAL'];
$checks[] = $kernel->handle(static fn()=>$operations->reviewEnrollment(
    new Request($reviewBody),$operator))->status===403;
$checks[] = $kernel->handle(static fn()=>$operations->reviewEnrollment(
    new Request([...$reviewBody,'admission_route'=>'unverified']),$reviewer))->status===422;
$checks[] = $kernel->handle(static fn()=>$operations->reviewEnrollment(
    new Request([...$reviewBody,'admission_route_source_id'=>'1']),$reviewer))->status===422;
$checks[] = $kernel->handle(static fn()=>$operations->reviewEnrollment(
    new Request([...$reviewBody,'quota_evidence_reference'=>[]]),$reviewer))->status===422;
$qualificationData=[
    'student_id'=>1,'level_code'=>'10','board_name'=>'LOCAL',
    'passing_year'=>2024,'certificate_reference'=>'LOCAL SCHOOL',
    'subject_results'=>(object)['Mathematics'=>80]
];
$checks[]=$kernel->handle(static fn()=>$studentController->createQualification(
    new Request([...$qualificationData,'student_id'=>'1']),$studentWriter))->status===422;
$checks[]=$kernel->handle(static fn()=>$studentController->createQualification(
    new Request([...$qualificationData,'subject_results'=>[80]]),$studentWriter))->status===422;
$checks[]=$kernel->handle(static fn()=>$studentController->createQualification(
    new Request([...$qualificationData,'subject_results'=>(object)['Math'=>-1]]),$studentWriter))->status===422;
$checks[]=$kernel->handle(static fn()=>$studentController->createQualification(
    new Request($qualificationData),$context))->status===403;
$accountController=new App\Modules\Auth\Http\InstituteUserController(new App\Core\Database([]));
$accountPrincipal=new AuthContext(8,3,1,'principal',false,['principal'],[
    'auth.user.view','auth.user.manage_staff_accounts','auth.user.manage_student_accounts',
    'auth.user.grant_administrator','auth.password.reset_user',
],8,null);
$accountStudent=new AuthContext(9,3,1,'student',false,['student'],[],null,9);
$checks[]=$kernel->handle(static fn()=>$accountController->createStaff(
    new Request(['role'=>'developer','staff_id'=>1,'username'=>'validuser','temporary_password'=>'long-temp-password']),$accountPrincipal))->status===422;
$checks[]=$kernel->handle(static fn()=>$accountController->createStaff(
    new Request(['role'=>'instructor','staff_id'=>1,'username'=>'validuser','temporary_password'=>'short']),$accountPrincipal))->status===422;
$checks[]=$kernel->handle(static fn()=>$accountController->createStudent(
    new Request(['student_id'=>'2','username'=>'validuser','temporary_password'=>'long-temp-password']),$accountPrincipal))->status===422;
$checks[]=$kernel->handle(static fn()=>$accountController->createStudent(
    new Request(['student_id'=>2,'username'=>'validuser','temporary_password'=>'long-temp-password']),$accountStudent))->status===403;
$checks[]=$kernel->handle(static fn()=>$accountController->disable(
    new Request(['user_id'=>'2']),$accountPrincipal))->status===422;
$checks[]=$kernel->handle(static fn()=>$accountController->revokeRole(
    new Request(['user_id'=>0]),$accountPrincipal))->status===422;
$checks[]=$kernel->handle(static fn()=>$accountController->resetPassword(
    new Request(['user_id'=>1,'temporary_password'=>'x']),$accountPrincipal))->status===422;
$checks[]=$kernel->handle(static fn()=>$accountController->index(
    new Request(query:['page_size'=>'101']),$accountPrincipal))->status===422;
$checks[]=$kernel->handle(static fn()=>$accountController->index(
    new Request(),$accountStudent))->status===403;
$contact=new App\Modules\Institutes\InstituteContactController(new App\Core\Database([]));
$contactActor=new AuthContext(5,3,1,'principal',false,['principal'],['institute.profile.update'],5,null);
$checks[]=$kernel->handle(static fn()=>$contact->update(new Request([]),$contactActor))->status===422;
$checks[]=$kernel->handle(static fn()=>$contact->update(new Request(['contact_email'=>'not-email']),$contactActor))->status===422;
$checks[]=$kernel->handle(static fn()=>$contact->update(new Request(['contact_phone'=>'a']),$contactActor))->status===422;
$checks[]=$kernel->handle(static fn()=>$contact->update(new Request(['contact_phone'=>'+91-1234567890']),$context))->status===403;
$checks[]=$kernel->handle(static fn()=>$staffController->updateProfile(new Request(['staff_id'=>'1','full_name'=>'Name','designation'=>'D']),$staffWriter))->status===422;
$checks[]=$kernel->handle(static fn()=>$staffController->recordQualification(new Request([
    'staff_id'=>1,'qualification_type'=>'ITI','specialization'=>'Electrician',
    'certificate_reference'=>'TEST','experience_months'=>'12']),$staffWriter))->status===422;
$checks[]=$kernel->handle(static fn()=>$staffController->exitEmployment(new Request([
    'staff_id'=>1,'left_on'=>'2026-02-30','reference'=>'EXIT']),$staffWriter))->status===422;
$checks[]=$kernel->handle(static fn()=>$staffController->exitEmployment(new Request([
    'staff_id'=>1,'left_on'=>'2026-09-01','reference'=>'EXIT']),$context))->status===403;
$sessionController=new App\Modules\Attendance\AttendanceSessionController(new App\Core\Database([]));
$calendarWriter=new AuthContext(3,3,1,'administrator',false,['administrator'],['calendar.manage'],3,null);
$principalLocker=new AuthContext(4,3,1,'principal',false,['principal'],['attendance.correct'],4,null);
$sessionBody=['batch_year_id'=>1,'session_date'=>'2026-09-03','component'=>'trade_theory','instructor_id'=>2];
$checks[]=$kernel->handle(static fn()=>$sessionController->create(new Request([
    ...$sessionBody,'instructor_id'=>'2']),$calendarWriter))->status===422;
$checks[]=$kernel->handle(static fn()=>$sessionController->create(new Request([
    ...$sessionBody,'component'=>'unknown']),$calendarWriter))->status===422;
$checks[]=$kernel->handle(static fn()=>$sessionController->create(new Request([
    ...$sessionBody,'session_date'=>'2026-02-30']),$calendarWriter))->status===422;
$checks[]=$kernel->handle(static fn()=>$sessionController->create(new Request($sessionBody),$attendanceStudent))->status===403;
$checks[]=$kernel->handle(static fn()=>$sessionController->lock(new Request(['session_id'=>'1']),$principalLocker))->status===422;
$checks[]=$kernel->handle(static fn()=>$sessionController->lock(new Request(['session_id'=>1]),$calendarWriter))->status===403;
// Show the exact failing input contract instead of throwing an unlabeled
// failure at the very end of the suite. Only this test file uses this helper.
function backendExpectStatus(\App\Http\Response $response, int $expected, int $line): bool
{
    if ($response->status === $expected) {
        return true;
    }
    $error = $response->body['error']['code'] ?? 'NO_ERROR_CODE';
    fwrite(STDERR, "Backend contract input at line $line: expected HTTP "
        . $expected . ", got HTTP " . $response->status . " (" . $error . ")\n");
    return false;
}

$platformController=new App\Modules\Institutes\PlatformInstituteController(new App\Core\Database([]));
$developerActor=new AuthContext(1,null,1,'developer',false,['developer'],[
    'platform.institute.create','platform.institute.update','platform.institute.view',
    'platform.user.provision_principal','platform.regulatory.manage'],null,null);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$platformController->create(new Request([
    'name'=>'A','mis_code'=>'X','address'=>'Test']),$accountStudent)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$platformController->create(new Request([
    'name'=>'A','mis_code'=>'','address'=>'Test']),$developerActor)),422, __LINE__);
// Directory updates must not grant the independent authority to provision a Principal.
$directoryOnlyDeveloper=new AuthContext(2,null,1,'developer',false,['developer'],
    ['platform.institute.update'],null,null);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$platformController->provisionPrincipal(new Request([
    'institute_id'=>1,'employee_code'=>'P','full_name'=>'Principal',
    'designation'=>'Principal','joined_on'=>'2026-01-01',
    'appointment_reference'=>'TEST','username'=>'principal01',
    'temporary_password'=>'123456789012']),$directoryOnlyDeveloper)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$platformController->provisionPrincipal(new Request([
    'institute_id'=>'1','employee_code'=>'P','full_name'=>'Principal',
    'designation'=>'Principal','joined_on'=>'2026-01-01',
    'appointment_reference'=>'TEST','username'=>'principal01',
    'temporary_password'=>'123456789012']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$platformController->provisionPrincipal(new Request([
    'institute_id'=>1,'employee_code'=>'P','full_name'=>'Principal',
    'designation'=>'Principal','joined_on'=>'2026-02-30',
    'appointment_reference'=>'TEST','username'=>'principal01',
    'temporary_password'=>'123456789012']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$platformController->index(
    new Request(query:['page_size'=>'101']),$developerActor)),422, __LINE__);
$regulatoryController=new App\Modules\Regulatory\RegulatoryDocumentController(new App\Core\Database([]));
$regulatoryBody=[
    'authority'=>'DGT','document_type'=>'CTS','title'=>'Local source',
    'reference_no'=>'LOCAL-001','issued_on'=>'2026-05-08',
    'evidence_reference'=>'LOCAL-FIXTURE','sha256'=>str_repeat('a',64)
];
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$regulatoryController->draft(
    new Request([...$regulatoryBody,'sha256'=>'not-a-hash']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$regulatoryController->draft(
    new Request([...$regulatoryBody,'authority'=>'UNKNOWN']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$regulatoryController->draft(
    new Request([...$regulatoryBody,'authority'=>'STATE_UT']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$regulatoryController->draft(
    new Request($regulatoryBody),$accountStudent)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$regulatoryController->publish(
    new Request(['document_id'=>'1','review_reference'=>'LOCAL']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$regulatoryController->index(
    new Request(query:['page_size'=>'101']),$developerActor)),422, __LINE__);
$tradeController=new App\Modules\Regulatory\TradeMasterController(new App\Core\Database([]));
$tradeInput=['dgt_code'=>'LOCAL-TRADE','source_document_id'=>1,'version_code'=>'LOCAL-V1',
    'official_name'=>'LOCAL ONLY','trade_type'=>'engineering','effective_from'=>'2026-05-08'];
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$tradeController->create(
    new Request([...$tradeInput,'trade_type'=>'other']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$tradeController->create(
    new Request([...$tradeInput,'effective_from'=>'2026-02-30']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$tradeController->create(
    new Request($tradeInput),$accountStudent)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$tradeController->draftRevision(
    new Request([...$tradeInput,'trade_id'=>'1']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$tradeController->activate(
    new Request(['revision_id'=>'1','review_reference'=>'LOCAL']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$tradeController->index(
    new Request(query:['page_size'=>'101']),$developerActor)),422, __LINE__);
$calendarMasterController=new App\Modules\Regulatory\TrainingCalendarMasterController(new App\Core\Database([]));
$calendarDraft=['admission_year'=>2026,'session_code'=>'LOCAL','revision_code'=>'V1',
    'source_document_id'=>1,'training_starts_on'=>'2026-08-01',
    'training_ends_on'=>'2027-05-01'];
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$calendarMasterController->draft(
    new Request([...$calendarDraft,'training_ends_on'=>'2026-01-01']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$calendarMasterController->draft(
    new Request($calendarDraft),$accountStudent)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$calendarMasterController->milestone(
    new Request(['calendar_id'=>1,'milestone_code'=>'CLOSE',
        'milestone_date'=>'2026-02-30','description'=>'LOCAL',
        'document_section_reference'=>'LOCAL']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$calendarMasterController->portalShift(
    new Request(['calendar_id'=>1,'session_part'=>'unsupported',
        'physical_shift_no'=>1,'portal_shift_no'=>1,
        'document_section_reference'=>'LOCAL']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$calendarMasterController->publish(
    new Request(['calendar_id'=>'1','review_reference'=>'LOCAL']),$developerActor)),422, __LINE__);
$curriculumMasterController=new App\Modules\Regulatory\CurriculumMasterController(new App\Core\Database([]));
$curriculumDraft=['global_trade_id'=>1,'source_document_id'=>1,'version_code'=>'LOCAL',
    'duration_months'=>12,'nsqf_level'=>'4.0','unit_strength'=>20,'effective_from'=>'2026-01-01'];
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$curriculumMasterController->draft(
    new Request([...$curriculumDraft,'duration_months'=>13]),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$curriculumMasterController->draft(
    new Request([...$curriculumDraft,'nsqf_level'=>4.0]),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$curriculumMasterController->draft(
    new Request($curriculumDraft),$accountStudent)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$curriculumMasterController->year(
    new Request(['global_curriculum_id'=>1,'year_no'=>3,
        'trade_practical_minutes'=>0,'trade_theory_minutes'=>0,
        'employability_minutes'=>0,'ojt_project_minutes'=>0]),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$curriculumMasterController->outcome(
    new Request(['global_curriculum_year_id'=>1,'lo_code'=>'LO1','outcome_text'=>'LOCAL',
        'assessment_criteria'=>'LOCAL','formative_max_marks'=>'0']),$developerActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$curriculumMasterController->publish(
    new Request(['global_curriculum_id'=>'1','review_reference'=>'LOCAL']),$developerActor)),422, __LINE__);
$itsuController=new App\Modules\Catalog\ItsuSetupController(new App\Core\Database([]));
$itsuWriter=new AuthContext(1,3,1,'administrator',false,['administrator'],
    ['tradeunit.manage','institute.affiliation.manage'],7,null);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$itsuController->attachSource(
    new Request(['global_document_id'=>'1']),$itsuWriter)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$itsuController->createTradeUnit(
    new Request(['global_trade_id'=>1,'unit_no'=>0]),$itsuWriter)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$itsuController->createTradeUnit(
    new Request(['global_trade_id'=>1,'unit_no'=>1]),$accountStudent)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$itsuController->createShiftRevision(
    new Request(['trade_unit_id'=>1,'global_shift_id'=>1,
        'regulatory_source_id'=>1,'sanctioned_capacity'=>'20',
        'approval_reference'=>'LOCAL','valid_from'=>'2026-10-01',
        'affiliation_status'=>'approved','training_mode'=>'CTS']),$itsuWriter)),422, __LINE__);
$instructorReviewController=new App\Modules\Staff\Http\InstructorMappingController(new App\Core\Database([]));
$instructorPrincipal=new AuthContext(4,3,1,'principal',false,['principal'],
    ['staff.manage','instructor.mapping.manage'],4,null);
$instructorAdministrator=new AuthContext(5,3,1,'administrator',false,['administrator'],
    ['staff.manage','instructor.mapping.manage'],5,null);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$instructorReviewController->verifyQualification(
    new Request(['qualification_id'=>'1','regulatory_source_id'=>1,'review_reference'=>'LOCAL']),$instructorPrincipal)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$instructorReviewController->verifyQualification(
    new Request(['qualification_id'=>1,'regulatory_source_id'=>1,'review_reference'=>'LOCAL']),$instructorAdministrator)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$instructorReviewController->verifyIpRn(
    new Request(['staff_id'=>1,'regulatory_source_id'=>1,'iprn'=>'TEST',
        'verification_reference'=>'LOCAL','verified_on'=>'2026-02-30']),$instructorPrincipal)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$instructorReviewController->assign(
    new Request(['staff_id'=>1,'qualification_id'=>1,'qualification_source_id'=>1,
        'mapping_type'=>'unknown','valid_from'=>'2026-01-01','review_reference'=>'LOCAL']),$instructorPrincipal)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$instructorReviewController->assign(
    new Request(['staff_id'=>1,'qualification_id'=>1,'qualification_source_id'=>1,
        'mapping_type'=>'subject','subject_code'=>'INVALID',
        'valid_from'=>'2026-01-01','review_reference'=>'LOCAL']),$instructorPrincipal)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$instructorReviewController->assign(
    new Request(['staff_id'=>1,'qualification_id'=>1,'qualification_source_id'=>1,
        'mapping_type'=>'subject','subject_code'=>'ES',
        'valid_from'=>'2026-01-01','review_reference'=>'LOCAL']),$instructorAdministrator)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$instructorReviewController->release(
    new Request(['mapping_id'=>'1','valid_to'=>'2026-01-01',
        'regulatory_source_id'=>1,'review_reference'=>'LOCAL']),$instructorPrincipal)),422, __LINE__);
$affiliationController=new App\Modules\Institutes\AffiliationHistoryController(new App\Core\Database([]));
$affiliationActor=new AuthContext(1,3,1,'principal',false,['principal'],['institute.affiliation.manage'],7,null);
$affiliationAdmin=new AuthContext(2,3,1,'administrator',false,['administrator'],['institute.affiliation.manage'],2,null);
$affiliationBody=['regulatory_source_id'=>1,'change_type'=>'source_order',
    'approved_name'=>'LOCAL ITI','approved_address'=>'LOCAL',
    'affiliation_status'=>'affiliated','affiliation_order_reference'=>'LOCAL','effective_from'=>'2026-01-01'];
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$affiliationController->record(
    new Request([...$affiliationBody,'affiliation_status'=>'invalid']),$affiliationActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$affiliationController->record(
    new Request([...$affiliationBody,'effective_from'=>'2026-02-30']),$affiliationActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$affiliationController->record(
    new Request($affiliationBody),$affiliationAdmin)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$affiliationController->record(
    new Request($affiliationBody),$accountStudent)),403, __LINE__);
$intakeRevisionController=new App\Modules\Admissions\Http\IntakeRevisionController(new App\Core\Database([]));
$intakeRevisionWriter=new AuthContext(1,3,1,'administrator',false,['administrator'],
    ['admission.intake.manage'],7,null);
$intakeRevisionPrincipal=new AuthContext(2,3,1,'principal',false,['principal'],
    ['admission.intake.manage'],2,null);
$intakeRevisionInput=['intake_id'=>1,'regulatory_source_id'=>1,'revision_code'=>'LOCAL',
    'opens_on'=>'2026-07-01','closes_on'=>'2026-08-01','approval_reference'=>'LOCAL'];
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$intakeRevisionController->draft(
    new Request([...$intakeRevisionInput,'intake_id'=>'1']),$intakeRevisionWriter)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$intakeRevisionController->draft(
    new Request([...$intakeRevisionInput,'opens_on'=>'2026-02-30']),$intakeRevisionWriter)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$intakeRevisionController->draft(
    new Request([...$intakeRevisionInput,'closes_on'=>'2026-06-30']),$intakeRevisionWriter)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$intakeRevisionController->draft(
    new Request($intakeRevisionInput),$accountStudent)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$intakeRevisionController->publish(
    new Request(['intake_revision_id'=>1,'review_reference'=>'LOCAL']),$intakeRevisionWriter)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$intakeRevisionController->publish(
    new Request(['intake_revision_id'=>'1','review_reference'=>'LOCAL']),$intakeRevisionPrincipal)),422, __LINE__);
$matrixController=new App\Modules\Admissions\Http\SeatMatrixSetupController(new App\Core\Database([]));
$matrixActor=new AuthContext(1,3,1,'admin',false,['administrator'],[
    'batch.manage','seatmatrix.draft'],1,null);
$matrixPrincipal=new AuthContext(2,3,1,'principal',false,['principal'],[
    'seatmatrix.publish'],2,null);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$matrixController->intake(
    new Request(['calendar_id'=>'1','admission_year'=>2026,
        'intake_code'=>'X','session_part'=>'primary']),$matrixActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$matrixController->intake(
    new Request(['calendar_id'=>1,'admission_year'=>2026,
        'intake_code'=>'X','session_part'=>'invalid']),$matrixActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$matrixController->matrixDraft(
    new Request(['intake_id'=>1,'regulatory_source_id'=>1,
        'version_code'=>'V1','portal_reference'=>'X','supersedes_id'=>'1']),$matrixActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$matrixController->matrixEntry(
    new Request(['matrix_version_id'=>1,'itsu_revision_id'=>1,'seat_capacity'=>20,
        'admission_allowed'=>1,'portal_itsu_reference'=>'X']),$matrixActor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$matrixController->finalize(
    new Request(['matrix_version_id'=>1,'review_reference'=>'TEST']),$matrixActor)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$matrixController->finalize(
    new Request(['matrix_version_id'=>'1','review_reference'=>'TEST']),$matrixPrincipal)),422, __LINE__);
$batchSetup=new App\Modules\Academic\Http\BatchSetupController(new App\Core\Database([]));
$batchWriter=new AuthContext(1,3,1,'admin',false,['administrator'],[
    'batch.manage','curriculum.manage'],1,null);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$batchSetup->linkCurriculum(
    new Request(['global_curriculum_id'=>'1']),$batchWriter)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$batchSetup->linkCurriculumYear(
    new Request(['curriculum_id'=>1,'global_curriculum_year_id'=>'1']),$batchWriter)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$batchSetup->linkAdmissionRule(
    new Request(['curriculum_id'=>1,'global_admission_rule_id'=>0]),$batchWriter)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$batchSetup->createBatch(
    new Request(['seat_matrix_entry_id'=>1,'curriculum_id'=>1,'admission_rule_id'=>1,
        'batch_code'=>'X','starts_on'=>'2026-02-30','ends_on'=>'2027-05-30']),$batchWriter)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$batchSetup->createBatchYear(
    new Request(['batch_id'=>1,'curriculum_year_id'=>1,'starts_on'=>'2026-10-01',
        'ends_on'=>'2027-05-30','attendance_basis'=>'none','attendance_basis_source_id'=>1]),$batchWriter)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$batchSetup->createBatch(
    new Request(['seat_matrix_entry_id'=>1,'curriculum_id'=>1,'admission_rule_id'=>1,
        'batch_code'=>'X','starts_on'=>'2026-10-01','ends_on'=>'2027-05-30']),$accountStudent)),403, __LINE__);
$planning=new App\Modules\Academic\Http\CalendarPlanningController(new App\Core\Database([]));
$calendarManager=new AuthContext(1,3,1,'administrator',false,['administrator'],['calendar.manage'],1,null);
$calendarReviewer=new AuthContext(2,3,1,'principal',false,['principal'],['calendar.manage'],2,null);
$calendarInput=['batch_year_id'=>1,'session_date'=>'2026-10-05',
    'component'=>'trade_theory','scheduled_minutes'=>60];
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$planning->entry(
    new Request([...$calendarInput,'scheduled_minutes'=>0]),$calendarManager)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$planning->entry(
    new Request([...$calendarInput,'session_date'=>'2026-02-30']),$calendarManager)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$planning->entry(
    new Request([...$calendarInput,'component'=>'invalid']),$calendarManager)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$planning->review(
    new Request(['batch_year_id'=>1,'reviewed_through'=>'2026-10-05',
        'review_evidence_reference'=>'LOCAL']),$calendarManager)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$planning->review(
    new Request(['batch_year_id'=>'1','reviewed_through'=>'2026-10-05',
        'review_evidence_reference'=>'LOCAL']),$calendarReviewer)),422, __LINE__);
$reporting=new App\Modules\Reports\ReportingController(new App\Core\Database([]));
$auditor=new AuthContext(1,3,1,'principal',false,['principal'],['audit.view'],1,null);
$platformAuditor=new AuthContext(2,null,2,'developer',false,['developer'],['platform.audit.view'],null,null);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$reporting->instituteAudit(
    new Request(query:['page_size'=>'101']),$auditor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$reporting->instituteAudit(
    new Request(query:['action_code'=>[]]),$auditor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$reporting->instituteAudit(
    new Request(),$accountStudent)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$reporting->platformAudit(
    new Request(query:['after_id'=>'bad']),$platformAuditor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$reporting->platformAudit(
    new Request(),$auditor)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$reporting->summary(
    new Request(),$accountStudent)),403, __LINE__);
$formative=new App\Modules\Assessment\FormativeController(new App\Core\Database([]));
$assignedAssessor=new AuthContext(3,3,1,'instructor',false,['instructor'],
    ['formative.record.assigned'],3,null);
$formativeReviewer=new AuthContext(4,3,1,'principal',false,['principal'],
    ['formative.template.manage'],4,null);
$formativeInput=['enrollment_id'=>1,'batch_year_id'=>1,'learning_outcome_id'=>1,
    'assessed_on'=>'2026-09-01','max_marks'=>'100.00',
    'obtained_marks'=>'72.50','evidence_reference'=>'LOCAL'];
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$formative->recordAssigned(
    new Request([...$formativeInput,'obtained_marks'=>'101.00']),$assignedAssessor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$formative->recordAssigned(
    new Request([...$formativeInput,'max_marks'=>'0']),$assignedAssessor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$formative->recordAssigned(
    new Request([...$formativeInput,'max_marks'=>100]),$assignedAssessor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$formative->recordAssigned(
    new Request([...$formativeInput,'assessed_on'=>'2026-02-30']),$assignedAssessor)),422, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$formative->recordAssigned(
    new Request($formativeInput),$accountStudent)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$formative->lock(
    new Request(['formative_assessment_id'=>1,'review_reference'=>'LOCAL']),$assignedAssessor)),403, __LINE__);
$checks[]=backendExpectStatus($kernel->handle(static fn()=>$formative->lock(
    new Request(['formative_assessment_id'=>'1','review_reference'=>'LOCAL']),$formativeReviewer)),422, __LINE__);
foreach ($checks as $check) {
    if (!$check) {
        throw new RuntimeException('Backend contract failed.');
    }
}
echo 'Backend contracts passed (' . count($checks) . " checks).\n";
