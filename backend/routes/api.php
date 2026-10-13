<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Health\HealthController;
use App\Modules\Auth\AuthController;
use App\Core\Database;
use App\Modules\Auth\AuthMiddleware;
use App\Modules\Auth\AuthContext;
use App\Modules\Institutes\InstituteRepository;
use App\Modules\Operations\OperationsController;
use App\Modules\Staff\StaffController;
use App\Modules\Students\StudentController;
use App\Modules\Catalog\CatalogController;
use App\Modules\Fees\FeeController;
use App\Modules\Attendance\AttendanceController;
use App\Modules\OfficialRecords\OfficialRecordsController;
use App\Modules\Admissions\AdmissionRegistryController;
use App\Http\Request;
use App\Http\Response;

return static function (Router $router, Database $database): void {
    $router->get('/api/v1/health', new HealthController());
    $auth = new AuthController($database);
    $guard = new AuthMiddleware($database);
    $router->post('/api/v1/auth/login', [$auth, 'login']);
    $router->get('/api/v1/auth/me', $guard->wrap([$auth, 'me'], allowPasswordChange: true));
    $router->post('/api/v1/auth/logout', $guard->wrap([$auth, 'logout'], allowPasswordChange: true));
    $router->post('/api/v1/auth/change-password', $guard->wrap([$auth, 'changePassword'], allowPasswordChange: true));
    $router->get('/api/v1/auth/sessions', $guard->wrap([$auth, 'sessions']));
    $router->post('/api/v1/auth/logout-all', $guard->wrap([$auth, 'logoutAll']));
    $router->get('/api/v1/institute/profile', $guard->wrap(
        static fn(Request $request, AuthContext $context) => new Response(['success' => true, 'data' => (new InstituteRepository($database->connection()))->profile($context)]),
        'institute.profile.view',
    ));
    $staff = new StaffController($database);
    $router->get('/api/v1/staff', $guard->wrap([$staff, 'index'], 'staff.view'));
    $router->post('/api/v1/staff', $guard->wrap([$staff, 'create'], 'staff.manage'));
    $students = new StudentController($database);
    $router->get('/api/v1/students', $guard->wrap([$students, 'index'], 'student.view'));
    $router->get('/api/v1/students/me', $guard->wrap([$students, 'own'], 'student.view.own'));
    $router->post('/api/v1/students', $guard->wrap([$students, 'create'], 'student.manage'));
    $catalog = new CatalogController($database);
    $router->get('/api/v1/itsu', $guard->wrap([$catalog, 'itsu'], 'tradeunit.view'));
    $router->get('/api/v1/batches', $guard->wrap([$catalog, 'batches'], 'batch.view'));
    $fees = new FeeController($database);
    $router->get('/api/v1/fees/payments', $guard->wrap([$fees, 'payments'], 'fee.view'));
    $router->get('/api/v1/fees/payments/me', $guard->wrap([$fees, 'ownPayments'], 'fee.view.own'));
    $router->post('/api/v1/fees/assessments/issue', $guard->wrap([$fees, 'issueAssessment'], 'fee.record'));
    $router->post('/api/v1/fees/waivers/approve', $guard->wrap([$fees, 'approveWaiver'], 'fee.refund'));
    $router->post('/api/v1/fees/payments', $guard->wrap([$fees, 'recordPayment'], 'fee.record'));
    $router->post('/api/v1/fees/allocations', $guard->wrap([$fees, 'allocate'], 'fee.record'));
    $router->post('/api/v1/fees/refunds', $guard->wrap([$fees, 'requestRefund'], 'fee.refund'));
    $router->post('/api/v1/fees/refunds/decide', $guard->wrap([$fees, 'decideRefund'], 'fee.refund'));
    $router->post('/api/v1/fees/payments/reverse', $guard->wrap([$fees, 'reversePayment'], 'fee.refund'));
    $attendance = new AttendanceController($database);
    $router->get('/api/v1/attendance/records', $guard->wrap([$attendance, 'instituteRecords'], 'attendance.view'));
    $router->get('/api/v1/attendance/records/assigned', $guard->wrap([$attendance, 'assignedRecords'], 'attendance.view.assigned'));
    $router->get('/api/v1/attendance/records/me', $guard->wrap([$attendance, 'ownRecords'], 'attendance.view.own'));
    $router->post('/api/v1/attendance/records/assigned', $guard->wrap([$attendance, 'markAssigned'], 'attendance.mark.assigned'));
    $officialRecords = new OfficialRecordsController($database);
    $router->get('/api/v1/exams/results', $guard->wrap([$officialRecords, 'results'], 'result.view'));
    $router->get('/api/v1/exams/results/me', $guard->wrap([$officialRecords, 'ownResults'], 'result.view.own'));
    $router->get('/api/v1/certificates', $guard->wrap([$officialRecords, 'certificates'], 'certificate.view'));
    $router->get('/api/v1/certificates/me', $guard->wrap([$officialRecords, 'ownCertificates'], 'certificate.view.own'));
    $admissionRegistry = new AdmissionRegistryController($database);
    $router->get('/api/v1/enrollments', $guard->wrap([$admissionRegistry, 'enrollments'], 'enrollment.view'));
    $router->get('/api/v1/seat-matrix/entries', $guard->wrap([$admissionRegistry, 'seatMatrixEntries'], 'seatmatrix.view'));
    $router->get('/api/v1/academic/calendars', $guard->wrap([$admissionRegistry, 'academicCalendars'], 'calendar.view'));
    $operations = new OperationsController($database);
    $router->post('/api/v1/enrollments/activate', $guard->wrap([$operations, 'activateEnrollment'], 'enrollment.manage'));
    $router->post('/api/v1/enrollments/cancel', $guard->wrap([$operations, 'cancelEnrollment'], 'enrollment.manage'));
    $router->post('/api/v1/enrollments/trainee-upload/prepare', $guard->wrap([$operations, 'prepareTraineeUpload'], 'enrollment.manage'));
    $router->post('/api/v1/exams/eligibility/freeze', $guard->wrap([$operations, 'freezeEligibility'], 'exam.eligibility.manage'));
    $router->get('/api/v1/ready', static function () use ($database): Response {
        try {
            $database->connection()->query('SELECT 1');
            return new Response(['success' => true, 'data' => ['status' => 'ready']]);
        } catch (\Throwable) {
            return Response::error('NOT_READY', 'Database unavailable.', 503);
        }
    });
};
