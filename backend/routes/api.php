<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Health\HealthController;
use App\Modules\Auth\Http\AuthController;
use App\Modules\Auth\Http\InstituteUserController;
use App\Core\Database;
use App\Modules\Auth\Http\AuthMiddleware;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Institutes\InstituteRepository;
use App\Modules\Institutes\PlatformInstituteController;
use App\Modules\Regulatory\RegulatoryDocumentController;
use App\Modules\Regulatory\TradeMasterController;
use App\Modules\Regulatory\CurriculumMasterController;
use App\Modules\Regulatory\TrainingCalendarMasterController;
use App\Modules\Reports\ReportingController;
use App\Modules\Assessment\FormativeController;
use App\Modules\Institutes\InstituteContactController;
use App\Modules\Institutes\AffiliationHistoryController;
use App\Modules\Operations\OperationsController;
use App\Modules\Ojt\OjtController;
use App\Modules\Staff\Http\StaffController;
use App\Modules\Staff\Http\InstructorMappingController;
use App\Modules\Students\Http\StudentController;
use App\Modules\Catalog\CatalogController;
use App\Modules\Catalog\ItsuSetupController;
use App\Modules\Fees\Http\FeeController;
use App\Modules\Fees\Http\FeeSetupController;
use App\Modules\Fees\Http\FeeCashController;
use App\Modules\Attendance\AttendanceController;
use App\Modules\Attendance\AttendanceSessionController;
use App\Modules\OfficialRecords\OfficialRecordsController;
use App\Modules\OfficialRecords\OfficialImportController;
use App\Modules\Exams\ExamCycleController;
use App\Modules\Exams\PracticalExamController;
use App\Modules\Exams\PracticalPortalReceiptController;
use App\Modules\Admissions\Http\AdmissionRegistryController;
use App\Modules\Admissions\Http\SeatMatrixSetupController;
use App\Modules\Admissions\Http\IntakeRevisionController;
use App\Modules\Academic\Http\AcademicCalendarController;
use App\Modules\Academic\Http\BatchSetupController;
use App\Modules\Academic\Http\CalendarPlanningController;
use App\Http\Request;
use App\Http\Response;

return static function (Router $router, Database $database): void {
    $router->get('/api/v1/health', new HealthController());
    $auth = new AuthController($database);
    $guard = new AuthMiddleware($database);
    $training=new \App\Modules\Training\TrainingController($database);
    $router->post('/api/v1/training/plans',$guard->wrap([$training,'draft']));
    $router->post('/api/v1/training/plans/delete',$guard->wrap([$training,'delete']));
    $router->post('/api/v1/training/plans/approve',$guard->wrap([$training,'approve'],'training.plan.manage'));
    $router->post('/api/v1/training/progress',$guard->wrap([$training,'record']));
    $router->post('/api/v1/training/progress/void',$guard->wrap([$training,'void'],'training.correct'));
    $router->get('/api/v1/training/plans',$guard->wrap([$training,'plans']));
    $router->get('/api/v1/training/progress',$guard->wrap([$training,'progress']));
    $feeRegisters=new \App\Modules\Fees\Http\FeeRegisterController($database);
    foreach (['heads','structures','structure-items','assessments','demands','refunds','waivers','reversals'] as $kind) {
        $router->get('/api/v1/fees/'.$kind,$guard->wrap(static fn(Request $r,AuthContext $a): Response=>$feeRegisters->read($r,$a,$kind),'fee.view'));
    }
    $documents=new \App\Modules\Documents\DocumentController($database);
    $router->post('/api/v1/documents/upload',$guard->wrap([$documents,'upload'],'document.manage'));
    $router->post('/api/v1/documents/archive',$guard->wrap([$documents,'archive'],'document.manage'));
    $router->get('/api/v1/documents',$guard->wrap([$documents,'index'],'document.view'));
    $router->get('/api/v1/documents/me',$guard->wrap([$documents,'own'],'document.view.own'));
    $router->get('/api/v1/documents/download',$guard->wrap([$documents,'download'],'document.view'));
    $router->get('/api/v1/documents/download/me',$guard->wrap([$documents,'ownDownload'],'document.view.own'));
    $router->post('/api/v1/auth/login', [$auth, 'login']);
    $router->post('/api/v1/auth/forgot-password', [$auth,'forgotPassword']);
    $router->post('/api/v1/auth/reset-password', [$auth,'resetPassword']);
    $router->get('/api/v1/auth/me', $guard->wrap([$auth, 'me'], allowPasswordChange: true));
    $router->post('/api/v1/auth/logout', $guard->wrap([$auth, 'logout'], allowPasswordChange: true));
    $router->post('/api/v1/auth/change-password', $guard->wrap([$auth, 'changePassword'], allowPasswordChange: true));
    $router->get('/api/v1/auth/sessions', $guard->wrap([$auth, 'sessions']));
    $router->post('/api/v1/auth/logout-all', $guard->wrap([$auth, 'logoutAll']));
    $userManagement = new InstituteUserController($database);
    $router->get('/api/v1/auth/users', $guard->wrap([$userManagement, 'index'], 'auth.user.view'));
    $router->post('/api/v1/auth/users/staff', $guard->wrap([$userManagement, 'createStaff']));
    $router->post('/api/v1/auth/users/student', $guard->wrap([$userManagement, 'createStudent'], 'auth.user.manage_student_accounts'));
    $router->post('/api/v1/auth/users/disable', $guard->wrap([$userManagement, 'disable'], 'auth.user.view'));
    $router->post('/api/v1/auth/users/revoke-role', $guard->wrap([$userManagement, 'revokeRole'], 'auth.user.view'));
    $router->post('/api/v1/auth/users/reset-password', $guard->wrap([$userManagement, 'resetPassword'], 'auth.password.reset_user'));
    $globalTrades=new TradeMasterController($database);
    $router->get('/api/v1/platform/trades', $guard->wrap(
        [$globalTrades,'index'], 'platform.regulatory.manage'));
    $router->post('/api/v1/platform/trades', $guard->wrap(
        [$globalTrades,'create'], 'platform.regulatory.manage'));
    $router->post('/api/v1/platform/trades/revisions', $guard->wrap(
        [$globalTrades,'draftRevision'], 'platform.regulatory.manage'));
    $router->post('/api/v1/platform/trades/revisions/activate', $guard->wrap(
        [$globalTrades,'activate'], 'platform.regulatory.manage'));
    $curricula=new CurriculumMasterController($database);
    $router->post('/api/v1/platform/curricula', $guard->wrap(
        [$curricula,'draft'], 'platform.regulatory.manage'));
    $router->post('/api/v1/platform/curricula/years', $guard->wrap(
        [$curricula,'year'], 'platform.regulatory.manage'));
    $router->post('/api/v1/platform/curricula/outcomes', $guard->wrap(
        [$curricula,'outcome'], 'platform.regulatory.manage'));
    $router->post('/api/v1/platform/curricula/publish', $guard->wrap(
        [$curricula,'publish'], 'platform.regulatory.manage'));
    $globalCalendars=new TrainingCalendarMasterController($database);
    $router->post('/api/v1/platform/cts-calendars', $guard->wrap(
        [$globalCalendars,'draft'], 'platform.regulatory.manage'));
    $router->post('/api/v1/platform/cts-calendars/milestones', $guard->wrap(
        [$globalCalendars,'milestone'], 'platform.regulatory.manage'));
    $router->post('/api/v1/platform/cts-calendars/portal-shifts', $guard->wrap(
        [$globalCalendars,'portalShift'], 'platform.regulatory.manage'));
    $router->post('/api/v1/platform/cts-calendars/publish', $guard->wrap(
        [$globalCalendars,'publish'], 'platform.regulatory.manage'));
    $regulatoryDocuments=new RegulatoryDocumentController($database);
    $router->get('/api/v1/platform/regulatory/documents', $guard->wrap(
        [$regulatoryDocuments,'index'], 'platform.regulatory.manage'));
    $router->post('/api/v1/platform/regulatory/documents/draft', $guard->wrap(
        [$regulatoryDocuments,'draft'], 'platform.regulatory.manage'));
    $router->post('/api/v1/platform/regulatory/documents/publish', $guard->wrap(
        [$regulatoryDocuments,'publish'], 'platform.regulatory.manage'));
    $platformInstitute=new PlatformInstituteController($database);
    $router->get('/api/v1/platform/institutes', $guard->wrap(
        [$platformInstitute,'index'], 'platform.institute.view'));
    $router->post('/api/v1/platform/institutes', $guard->wrap(
        [$platformInstitute,'create'], 'platform.institute.create'));
    $router->post('/api/v1/platform/institutes/principal', $guard->wrap(
        [$platformInstitute,'provisionPrincipal'], 'platform.user.provision_principal'));
    $router->get('/api/v1/institute/profile', $guard->wrap(
        static fn(Request $request, AuthContext $context) => new Response(['success' => true, 'data' => (new InstituteRepository($database->connection()))->profile($context)]),
        'institute.profile.view',
    ));
    $router->post('/api/v1/institute/contact', $guard->wrap(
        [new InstituteContactController($database),'update'], 'institute.profile.update'));
    $router->post('/api/v1/institute/affiliation-history', $guard->wrap(
        [new AffiliationHistoryController($database),'record'], 'institute.affiliation.manage'));
    $staff = new StaffController($database);
    $router->get('/api/v1/staff', $guard->wrap([$staff, 'index'], 'staff.view'));
    $router->post('/api/v1/staff', $guard->wrap([$staff, 'create'], 'staff.manage'));
    $router->post('/api/v1/staff/profile', $guard->wrap([$staff, 'updateProfile'], 'staff.manage'));
    $router->post('/api/v1/staff/qualifications', $guard->wrap([$staff, 'recordQualification'], 'staff.manage'));
    $router->post('/api/v1/staff/exit', $guard->wrap([$staff, 'exitEmployment'], 'staff.manage'));
    $instructorReview=new InstructorMappingController($database);
    $router->post('/api/v1/staff/qualifications/review', $guard->wrap(
        [$instructorReview,'verifyQualification'], 'staff.manage'));
    $router->post('/api/v1/instructors/iprn/review', $guard->wrap(
        [$instructorReview,'verifyIpRn'], 'instructor.mapping.manage'));
    $router->post('/api/v1/instructors/mappings', $guard->wrap(
        [$instructorReview,'assign'], 'instructor.mapping.manage'));
    $router->post('/api/v1/instructors/mappings/release', $guard->wrap(
        [$instructorReview,'release'], 'instructor.mapping.manage'));
    $students = new StudentController($database);
    $router->get('/api/v1/students/export',$guard->wrap([$students,'export'],'student.view'));
    $router->post('/api/v1/students/profile/correct', $guard->wrap([$students,'correctProfile'], 'student.manage'));
    $router->post('/api/v1/enrollments/transition', $guard->wrap([$students,'transition'], 'enrollment.manage'));
    $router->get('/api/v1/students', $guard->wrap([$students, 'index'], 'student.view'));
    $router->get('/api/v1/students/me', $guard->wrap([$students, 'own'], 'student.view.own'));
    $router->post('/api/v1/students', $guard->wrap([$students, 'create'], 'student.manage'));
    $router->post('/api/v1/students/qualifications', $guard->wrap([$students, 'createQualification'], 'student.manage'));
    $itsuSetup=new ItsuSetupController($database);
    $router->post('/api/v1/institute/regulatory/sources', $guard->wrap(
        [$itsuSetup,'attachSource'], 'institute.affiliation.manage'));
    $router->post('/api/v1/itsu/trade-units', $guard->wrap(
        [$itsuSetup,'createTradeUnit'], 'tradeunit.manage'));
    $router->post('/api/v1/itsu/shift-revisions', $guard->wrap(
        [$itsuSetup,'createShiftRevision'], 'tradeunit.manage'));
    $catalog = new CatalogController($database);
    $router->get('/api/v1/itsu', $guard->wrap([$catalog, 'itsu'], 'tradeunit.view'));
    $router->get('/api/v1/batches', $guard->wrap([$catalog, 'batches'], 'batch.view'));
    $feeCash = new FeeCashController($database);
    $router->post('/api/v1/fees/cash/transfers', $guard->wrap([$feeCash, 'movement'], 'fee.record'));
    $router->post('/api/v1/fees/cash/close', $guard->wrap([$feeCash, 'close'], 'fee.record'));
    $router->post('/api/v1/fees/cash/close/review', $guard->wrap([$feeCash, 'reviewClosing'], 'fee.refund'));
    $feeSetup = new FeeSetupController($database);
    $router->post('/api/v1/fees/heads', $guard->wrap([$feeSetup, 'head'], 'fee.record'));
    $router->post('/api/v1/fees/structures', $guard->wrap([$feeSetup, 'structure'], 'fee.record'));
    $router->post('/api/v1/fees/structures/items', $guard->wrap([$feeSetup, 'structureItem'], 'fee.record'));
    $router->post('/api/v1/fees/structures/activate', $guard->wrap([$feeSetup, 'activateStructure'], 'fee.record'));
    $router->post('/api/v1/fees/assessments', $guard->wrap([$feeSetup, 'assessment'], 'fee.record'));
    $fees = new FeeController($database);
    $router->get('/api/v1/fees/statements/export',$guard->wrap([$fees,'exportStatement'],'fee.view'));
    $router->get('/api/v1/fees/statements/export/me',$guard->wrap([$fees,'exportOwnStatement'],'fee.view.own'));
    $router->get('/api/v1/fees/receipts/print',$guard->wrap([$fees,'printReceipt'],'fee.view'));
    $router->get('/api/v1/fees/receipts/print/me',$guard->wrap([$fees,'printOwnReceipt'],'fee.view.own'));
    $router->get('/api/v1/fees/statements', $guard->wrap([$fees,'statement'], 'fee.view'));
    $router->get('/api/v1/fees/statements/me', $guard->wrap([$fees,'ownStatement'], 'fee.view.own'));
    $router->get('/api/v1/fees/receipts', $guard->wrap([$fees,'receipt'], 'fee.view'));
    $router->get('/api/v1/fees/receipts/me', $guard->wrap([$fees,'ownReceipt'], 'fee.view.own'));
    $router->get('/api/v1/fees/payments', $guard->wrap([$fees, 'payments'], 'fee.view'));
    $router->get('/api/v1/fees/payments/me', $guard->wrap([$fees, 'ownPayments'], 'fee.view.own'));
    $router->post('/api/v1/fees/assessments/issue', $guard->wrap([$fees, 'issueAssessment'], 'fee.record'));
    $router->post('/api/v1/fees/demands', $guard->wrap([$fees, 'issueDemand'], 'fee.record'));
    $router->post('/api/v1/fees/waivers/request', $guard->wrap([$fees, 'requestWaiver'], 'fee.record'));
    $router->post('/api/v1/fees/waivers/approve', $guard->wrap([$fees, 'approveWaiver'], 'fee.refund'));
    $router->post('/api/v1/fees/payments', $guard->wrap([$fees, 'recordPayment'], 'fee.record'));
    $router->post('/api/v1/fees/allocations', $guard->wrap([$fees, 'allocate'], 'fee.record'));
    $router->post('/api/v1/fees/refunds', $guard->wrap([$fees, 'requestRefund'], 'fee.refund'));
    $router->post('/api/v1/fees/refunds/decide', $guard->wrap([$fees, 'decideRefund'], 'fee.refund'));
    $router->post('/api/v1/fees/payments/reverse', $guard->wrap([$fees, 'reversePayment'], 'fee.refund'));
    $router->post('/api/v1/fees/payments/reversal-request', $guard->wrap([$fees, 'requestReversal'], 'fee.refund'));
    $sessions = new AttendanceSessionController($database);
    $router->post('/api/v1/attendance/sessions', $guard->wrap([$sessions,'create'], 'calendar.manage'));
    $router->post('/api/v1/attendance/sessions/lock', $guard->wrap([$sessions,'lock'], 'attendance.correct'));
    $attendance = new AttendanceController($database);
    $corrections=new \App\Modules\Assessment\AcademicCorrectionController($database);
    $scoreReads=new \App\Modules\Assessment\FormativeReadController($database);
    $router->get('/api/v1/formative/records',$guard->wrap([$scoreReads,'institute'],'formative.template.manage'));
    $router->get('/api/v1/formative/records/me',$guard->wrap([$scoreReads,'own'],'formative.view.own'));
    $router->get('/api/v1/formative/records/assigned',$guard->wrap([$scoreReads,'assigned'],'formative.record.assigned'));
    $router->get('/api/v1/academic/corrections',$guard->wrap([$corrections,'history'],'audit.view'));
    $router->post('/api/v1/attendance/corrections',$guard->wrap([$corrections,'attendance'],'attendance.correct'));
    $router->post('/api/v1/formative/corrections',$guard->wrap([$corrections,'formative'],'formative.template.manage'));
    $router->get('/api/v1/attendance/records', $guard->wrap([$attendance, 'instituteRecords'], 'attendance.view'));
    $router->get('/api/v1/attendance/records/assigned', $guard->wrap([$attendance, 'assignedRecords'], 'attendance.view.assigned'));
    $router->get('/api/v1/attendance/records/me', $guard->wrap([$attendance, 'ownRecords'], 'attendance.view.own'));
    $router->post('/api/v1/attendance/records/assigned', $guard->wrap([$attendance, 'markAssigned'], 'attendance.mark.assigned'));
    $officialImports = new OfficialImportController($database);
    $router->post('/api/v1/exams/results/import', $guard->wrap([$officialImports, 'result'], 'result.import'));
    $router->post('/api/v1/certificates/import', $guard->wrap([$officialImports, 'certificate'], 'certificate.record'));
    $officialRecords = new OfficialRecordsController($database);
    $router->get('/api/v1/exams/results', $guard->wrap([$officialRecords, 'results'], 'result.view'));
    $router->get('/api/v1/exams/results/me', $guard->wrap([$officialRecords, 'ownResults'], 'result.view.own'));
    $router->get('/api/v1/certificates', $guard->wrap([$officialRecords, 'certificates'], 'certificate.view'));
    $router->get('/api/v1/certificates/me', $guard->wrap([$officialRecords, 'ownCertificates'], 'certificate.view.own'));
    $intakeRevisions=new IntakeRevisionController($database);
    $router->post('/api/v1/admission/intake-revisions', $guard->wrap(
        [$intakeRevisions,'draft'], 'admission.intake.manage'));
    $router->post('/api/v1/admission/intake-revisions/publish', $guard->wrap(
        [$intakeRevisions,'publish'], 'admission.intake.manage'));
    $admissionSetup=new SeatMatrixSetupController($database);
    $router->post('/api/v1/admission/intakes', $guard->wrap(
        [$admissionSetup,'intake'], 'batch.manage'));
    $router->post('/api/v1/seat-matrix/versions', $guard->wrap(
        [$admissionSetup,'matrixDraft'], 'seatmatrix.draft'));
    $router->post('/api/v1/seat-matrix/entries', $guard->wrap(
        [$admissionSetup,'matrixEntry'], 'seatmatrix.draft'));
    $router->post('/api/v1/seat-matrix/versions/finalize', $guard->wrap(
        [$admissionSetup,'finalize'], 'seatmatrix.publish'));
    $admissionRegistry = new AdmissionRegistryController($database);
    $router->get('/api/v1/enrollments', $guard->wrap([$admissionRegistry, 'enrollments'], 'enrollment.view'));
    $router->get('/api/v1/seat-matrix/entries', $guard->wrap([$admissionRegistry, 'seatMatrixEntries'], 'seatmatrix.view'));
    $calendarPlanning=new CalendarPlanningController($database);
    $router->post('/api/v1/academic/calendar-entries', $guard->wrap(
        [$calendarPlanning,'entry'], 'calendar.manage'));
    $router->post('/api/v1/academic/calendars/review', $guard->wrap(
        [$calendarPlanning,'review'], 'calendar.manage'));
    $batchSetup=new BatchSetupController($database);
    $router->post('/api/v1/academic/curriculum-links', $guard->wrap(
        [$batchSetup,'linkCurriculum'], 'curriculum.manage'));
    $router->post('/api/v1/academic/curriculum-year-links', $guard->wrap(
        [$batchSetup,'linkCurriculumYear'], 'curriculum.manage'));
    $router->post('/api/v1/admission/rule-links', $guard->wrap(
        [$batchSetup,'linkAdmissionRule'], 'batch.manage'));
    $router->post('/api/v1/batches', $guard->wrap(
        [$batchSetup,'createBatch'], 'batch.manage'));
    $router->post('/api/v1/batches/years', $guard->wrap(
        [$batchSetup,'createBatchYear'], 'batch.manage'));
    $router->get('/api/v1/academic/calendars', $guard->wrap([new AcademicCalendarController($database), 'index'], 'calendar.view'));
    $operations = new OperationsController($database);
    $router->post('/api/v1/enrollments/drafts', $guard->wrap([$operations, 'createDraftEnrollment'], 'enrollment.manage'));
    $router->post('/api/v1/enrollments/review', $guard->wrap([$operations, 'reviewEnrollment'], 'enrollment.manage'));
    $router->post('/api/v1/enrollments/activate', $guard->wrap([$operations, 'activateEnrollment'], 'enrollment.manage'));
    $router->post('/api/v1/enrollments/cancel', $guard->wrap([$operations, 'cancelEnrollment'], 'enrollment.manage'));
    $router->post('/api/v1/enrollments/trainee-upload/prepare', $guard->wrap([$operations, 'prepareTraineeUpload'], 'enrollment.manage'));
    $examSetup = new ExamCycleController($database);
    $practicalExams=new PracticalExamController($database);
    $practicalReceipts=new PracticalPortalReceiptController($database);
    $router->post('/api/v1/exams/practicals/portal-receipts', $guard->wrap(
        [$practicalReceipts,'submitted'], 'exam.portal.submit'));
    $router->post('/api/v1/exams/practicals/portal-receipts/response', $guard->wrap(
        [$practicalReceipts,'acknowledge'], 'exam.portal.submit'));
    $router->post('/api/v1/exams/practicals/sessions', $guard->wrap(
        [$practicalExams,'session'], 'exam.practical.manage'));
    $router->post('/api/v1/exams/practicals/marks/assigned', $guard->wrap(
        [$practicalExams,'assignedMarks'], 'exam.practical.record.assigned'));
    $router->get('/api/v1/exams/cycles/status', $guard->wrap([$examSetup, 'status'], 'exam.eligibility.manage'));
    $router->post('/api/v1/exams/cycles', $guard->wrap([$examSetup, 'draft'], 'exam.eligibility.manage'));
    $router->post('/api/v1/exams/cycles/open', $guard->wrap([$examSetup, 'open'], 'exam.eligibility.manage'));
    $router->post('/api/v1/exams/eligibility/freeze', $guard->wrap([$operations, 'freezeEligibility'], 'exam.eligibility.manage'));
    $ojt=new OjtController($database);
    $router->get('/api/v1/ojt/projects', $guard->wrap(
        [$ojt,'instituteProjects'], 'ojt.view'));
    $router->get('/api/v1/ojt/projects/me', $guard->wrap(
        [$ojt,'ownProjects'], 'ojt.view.own'));
    $router->post('/api/v1/ojt/projects', $guard->wrap(
        [$ojt,'plan'], 'calendar.manage'));
    $router->post('/api/v1/ojt/projects/start', $guard->wrap(
        [$ojt,'start'], 'calendar.manage'));
    $router->post('/api/v1/ojt/logbook/me', $guard->wrap(
        [$ojt,'ownLog'], 'ojt.log.own'));
    $router->post('/api/v1/ojt/evaluations/assigned', $guard->wrap(
        [$ojt,'evaluateAssigned'], 'ojt.evaluate.assigned'));
    $formative=new FormativeController($database);
    $router->post('/api/v1/formative/schemes/configure', $guard->wrap(
        [$formative,'configureScheme'], 'formative.template.manage'));
    $router->post('/api/v1/formative/templates/items', $guard->wrap(
        [$formative,'templateItem'], 'formative.template.manage'));
    $router->post('/api/v1/formative/templates/scores/assigned', $guard->wrap(
        [$formative,'templateScoreAssigned'], 'formative.record.assigned'));
    $router->post('/api/v1/formative/templates/scores/lock', $guard->wrap(
        [$formative,'templateScoreLock'], 'formative.template.manage'));
    $router->post('/api/v1/formative/templates/scores/correct-unlocked', $guard->wrap(
        [$formative,'templateScoreCorrectUnlocked'], 'formative.template.manage'));
    $router->post('/api/v1/formative/records/assigned', $guard->wrap(
        [$formative,'recordAssigned'], 'formative.record.assigned'));
    $router->post('/api/v1/formative/records/lock', $guard->wrap(
        [$formative,'lock'], 'formative.template.manage'));
    $reporting=new ReportingController($database);
    $router->get('/api/v1/reports/summary', $guard->wrap(
        [$reporting,'summary'], 'audit.view'));
    $router->get('/api/v1/audit/events', $guard->wrap(
        [$reporting,'instituteAudit'], 'audit.view'));
    $router->get('/api/v1/platform/audit/events', $guard->wrap(
        [$reporting,'platformAudit'], 'platform.audit.view'));
    $router->get('/api/v1/ready', static function () use ($database): Response {
        try {
            $db = $database->connection();
            $db->query('SELECT 1');
            // Only essential table presence is checked on live requests.
            // TRIGGER metadata requires DDL privileges on some databases and
            // must instead be verified by the privileged, manual schema preflight.
            $requiredTables = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'
                  AND TABLE_NAME IN ('institute','auth_users','fee_payments',
                                     'fee_cash_closings','exam_cycles','official_results',
                                     'private_documents','lesson_plans','training_progress')")->fetchColumn();
            if ($requiredTables !== 9) {
                return Response::error('NOT_READY',
                    'Required application tables are unavailable.', 503);
            }
            return new Response(['success' => true, 'data' => ['status' => 'ready']]);
        } catch (\Throwable) {
            return Response::error('NOT_READY', 'Database or schema unavailable.', 503);
        }
    });
};
