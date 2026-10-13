<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';

use App\Core\SchemaInstaller;
use App\Http\HttpException;
use App\Modules\Admissions\AdmissionService;
use App\Modules\Auth\AuthContext;
use App\Modules\Exams\EligibilityService;
use App\Modules\Fees\FeeService;

$dsn=getenv('TEST_DB_DSN');
if (!$dsn) { fwrite(STDERR,"Disposable TEST_DB_DSN required.\n"); exit(1); }
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$db=new PDO($dsn,getenv('TEST_DB_USERNAME')?:'root',getenv('TEST_DB_PASSWORD')?:'',$options);
$name='iti_foundation_test_'.bin2hex(random_bytes(6));
$checks=0;
function foundationCheck(bool $condition,string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function foundationReject(callable $action,string $code): void {
    try { $action(); } catch (HttpException $e) { foundationCheck($e->errorCode===$code,'Unexpected rejection: '.$e->errorCode); return; }
    throw new RuntimeException('Expected rejection '.$code);
}
function foundationSqlReject(PDO $db,string $sql): void {
    try { $db->exec($sql); } catch (PDOException $e) { foundationCheck((int)$e->errorInfo[1]===1644,'Expected integrity trigger rejection.'); return; }
    throw new RuntimeException('Expected frozen/integrity SQL rejection.');
}
$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db->exec("USE `$name`");
    SchemaInstaller::install($db);
    $db->exec(file_get_contents(dirname(__DIR__).'/database/seeders/rbac_seed.sql'));
    $db->exec(file_get_contents(__DIR__.'/fixtures/foundation.sql'));
    // Exercise SQL identity scopes against an actual populated, relationally
    // valid attendance session without permitting unverified attendance writes.
    preg_match('/host=([^;]+)/', $dsn, $attendanceHost);
    preg_match('/port=([^;]+)/', $dsn, $attendancePort);
    $attendanceDatabase = new App\Core\Database([
        'host'=>$attendanceHost[1] ?? '127.0.0.1',
        'port'=>$attendancePort[1] ?? '3306',
        'database'=>$name,
        'username'=>getenv('TEST_DB_USERNAME') ?: 'root',
        'password'=>getenv('TEST_DB_PASSWORD') ?: '',
    ]);
    $attendanceController = new App\Modules\Attendance\AttendanceController($attendanceDatabase);
    $attendanceOwner = new AuthContext(9203,9101,2,'local-student',false,['student'],['attendance.view.own'],null,9101);
    $attendanceTeacher = new AuthContext(9202,9101,1,'local-instructor',false,['instructor'],['attendance.view.assigned'],9101,null);
    $attendanceWrongStudent = new AuthContext(9203,9101,2,'other-student',false,['student'],['attendance.view.own'],null,9102);
    $attendanceWrongTeacher = new AuthContext(9202,9101,1,'other-staff',false,['instructor'],['attendance.view.assigned'],9102,null);
    $attendanceOtherInstitute = new AuthContext(9204,9102,3,'other-institute',false,['principal'],['attendance.view'],9102,null);
    $firstPage = $attendanceController->ownRecords(new App\Http\Request(query:['page_size'=>'1']), $attendanceOwner)->body['data'];
    foundationCheck(count($firstPage['records'])===1 && (int)$firstPage['records'][0]['enrollment_id']===9101, 'Student own attendance must resolve its enrolled record.');
    foundationCheck($firstPage['next_cursor']!==null, 'Full attendance page needs composite cursor.');
    $next = $attendanceController->ownRecords(new App\Http\Request(query:[
        'page_size'=>'1',
        'after_session_id'=>(string)$firstPage['next_cursor']['after_session_id'],
        'after_enrollment_id'=>(string)$firstPage['next_cursor']['after_enrollment_id'],
    ]), $attendanceOwner)->body['data'];
    foundationCheck($next['records']===[], 'Composite attendance cursor repeated a record.');
    $assigned = $attendanceController->assignedRecords(new App\Http\Request(), $attendanceTeacher)->body['data']['records'];
    foundationCheck(count($assigned)===1 && (int)$assigned[0]['session_id']===9101, 'Assigned instructor must see only its session.');
    foundationCheck($attendanceController->ownRecords(new App\Http\Request(), $attendanceWrongStudent)->body['data']['records']===[], 'Student own read exposed a different linked student.');
    foundationCheck($attendanceController->assignedRecords(new App\Http\Request(), $attendanceWrongTeacher)->body['data']['records']===[], 'Assigned instructor read exposed a different staff session.');
    foundationCheck($attendanceController->instituteRecords(new App\Http\Request(), $attendanceOtherInstitute)->body['data']['records']===[], 'Attendance tenant isolation failed.');
    $permissions=['enrollment.manage','fee.record','fee.refund','exam.eligibility.manage'];
    $actor=new AuthContext(9202,9101,1,'local-instructor',false,['administrator'],$permissions,9101,null);
    $approver=new AuthContext(9203,9101,2,'local-student',false,['administrator'],$permissions,null,9101);
    $admissions=new AdmissionService($db);
    foundationReject(fn()=>$admissions->activate($actor,9101,9101),'UNVERIFIED_ADMISSION');
    foundationCheck($db->query('SELECT is_ews FROM students WHERE id=9101')->fetchColumn()===null,'Unknown EWS must remain unknown.');
    $db->exec("UPDATE enrollments SET admission_route='institute_direct',admission_route_source_id=9101,admission_route_evidence_reference='LOCAL-ROUTE' WHERE id=9101");
    foundationReject(fn()=>$admissions->activate($actor,9101,9101),'UNREVIEWED_SOURCE');
    $db->exec("UPDATE global_regulatory_documents SET status='published',reviewed_by='LOCAL-REVIEWER',reviewed_at=UTC_TIMESTAMP() WHERE id=9101");
    $db->exec("UPDATE global_admission_rule_versions SET qualification_routes_json='{\"LOCAL\":{\"level_codes\":[\"10\"]}}',status='published' WHERE id=9101");
    $db->exec("UPDATE global_curriculum_versions SET status='published' WHERE id=9101");
    $db->exec("UPDATE admission_intake_revisions SET status='published',published_on='2026-01-01' WHERE id=9101");
    $db->exec("UPDATE staff SET nims_verification='verified',verification_reference='LOCAL-NIMS',verified_on='2026-01-01' WHERE id=9101");
    $db->exec("INSERT INTO institute_affiliation_history (institute_id,source_id,change_type,approved_name,approved_address,affiliation_status,affiliation_order_reference,effective_from) VALUES (9101,9101,'LOCAL','LOCAL','LOCAL','affiliated','LOCAL','2026-01-01')");
    foundationReject(fn()=>$admissions->activate($actor,9101,9101),'ADMISSION_NOT_ALLOWED');
    $db->exec("UPDATE seat_matrix_versions SET status='final',published_on='2026-01-01' WHERE id=9101");
    $db->exec("UPDATE cts_training_calendars SET status='published' WHERE id=9101");
    $db->exec("UPDATE trade_unit_shifts SET training_mode='DST' WHERE id=9101");
    $db->exec("UPDATE batches SET training_mode='DST' WHERE id=9101");
    foundationReject(fn()=>$admissions->activate($actor,9101,9101),'ADMISSION_REFERENCE');
    $db->exec("INSERT INTO dst_agreements (id,institute_id,trade_id,source_id,agreement_no,industry_name,industry_registration_reference,industry_address,industry_contact,starts_on,ends_on,signed_evidence_reference,status,verification_reference,verified_on) VALUES (9101,9101,9101,9101,'LOCAL','LOCAL','LOCAL','LOCAL','LOCAL','2026-01-01','2026-07-01','LOCAL','verified','LOCAL','2026-01-01')");
    $db->exec('INSERT INTO dst_agreement_units (institute_id,agreement_id,trade_unit_shift_id,trade_id) VALUES (9101,9101,9101,9101)');
    foundationReject(fn()=>$admissions->activate($actor,9101,9101),'ADMISSION_REFERENCE');
    // Restore regular CTS for the successful admission; the expired DST agreement remains evidence.
    $db->exec("UPDATE trade_unit_shifts SET training_mode='CTS' WHERE id=9101");
    $db->exec("UPDATE batches SET training_mode='CTS' WHERE id=9101");
    $admissions->activate($actor,9101,9101);
    foundationCheck((int)$db->query('SELECT occupied_seats FROM batches WHERE id=9101')->fetchColumn()===1,'Activation must reconcile occupancy.');
    // Create a second local attendance session: the first fixture session is
    // already populated and must not be overwritten by new marking.
    $db->exec("INSERT INTO attendance_sessions
       (id,institute_id,batch_year_id,batch_id,session_date,component,scheduled_minutes,instructor_id)
       VALUES (9111,9101,9101,9101,'2026-09-02','trade_theory',60,9101)");
    $markActor = new AuthContext(9202,9101,4,'local-instructor',false,
        ['instructor'],['attendance.mark.assigned'],9101,null);
    $otherMarker = new AuthContext(9202,9101,4,'local-instructor',false,
        ['instructor'],['attendance.mark.assigned'],9102,null);
    $markInput = new App\Http\Request([
        'session_id'=>9111,'enrollment_id'=>9101,'attended_minutes'=>40,
        'status'=>'present'
    ]);
    foundationReject(fn()=>$attendanceController->markAssigned($markInput,$otherMarker), 'FORBIDDEN');
    foundationReject(fn()=>$attendanceController->markAssigned(new App\Http\Request([
        'session_id'=>9111,'enrollment_id'=>9102,'attended_minutes'=>40,'status'=>'present'
    ]),$markActor),'ENROLLMENT_NOT_ACTIVE');
    foundationReject(fn()=>$attendanceController->markAssigned(new App\Http\Request([
        'session_id'=>9111,'enrollment_id'=>9101,'attended_minutes'=>61,'status'=>'present'
    ]),$markActor),'INVALID_MINUTES');
    $written = $attendanceController->markAssigned($markInput, $markActor);
    foundationCheck($written->status===201, 'Assigned instructor should record verified local attendance on unlocked session.');
    foundationCheck((int)$db->query('SELECT attended_minutes FROM attendance_records WHERE session_id=9111 AND enrollment_id=9101 AND institute_id=9101')->fetchColumn()===40,'Attendance insert not persisted.');
    foundationReject(fn()=>$attendanceController->markAssigned($markInput,$markActor),'ATTENDANCE_EXISTS');
    $db->exec("UPDATE attendance_sessions SET locked_on=UTC_TIMESTAMP() WHERE id=9111");
    foundationReject(fn()=>$attendanceController->markAssigned($markInput,$markActor),'SESSION_LOCKED');
    foundationCheck((int)$db->query("SELECT COUNT(*) FROM institute_activity_log WHERE action_code='attendance.mark' AND entity_id=9111")->fetchColumn()===1,'Attendance marker audit must be exactly once.');
    $admissions->activate($actor,9101,9101);
    foundationCheck((int)$db->query('SELECT occupied_seats FROM batches WHERE id=9101')->fetchColumn()===1,'Activation replay must not count twice.');
    foundationSqlReject($db,"UPDATE global_regulatory_documents SET title='tampered' WHERE id=9101");
    foundationSqlReject($db,'UPDATE seat_matrix_entries SET seat_capacity=99 WHERE id=9101');
    foundationSqlReject($db,'UPDATE attendance_records SET attended_minutes=61 WHERE session_id=9101');
    $db->exec("INSERT INTO global_learning_outcomes (id,global_curriculum_year_id,lo_code,outcome_text,assessment_criteria) VALUES (9101,9101,'LOCAL','LOCAL','LOCAL')");
    $db->exec("INSERT INTO learning_outcomes (id,institute_id,curriculum_year_id,global_curriculum_year_id,global_learning_outcome_id,lo_code) VALUES (9101,9101,9101,9101,9101,'LOCAL')");
    $db->exec("INSERT INTO formative_assessments (institute_id,enrollment_id,batch_id,batch_year_id,curriculum_year_id,learning_outcome_id,assessed_on,max_marks,obtained_marks,evidence_reference,assessor_id,locked_on) VALUES (9101,9101,9101,9101,9101,9101,'2026-09-01',100,80,'LOCAL',9101,UTC_TIMESTAMP())");
    $db->exec("UPDATE batch_years SET calendar_status='reviewed',calendar_reviewed_through='2026-09-01',formative_method='points_ratio',formative_scheme_source_id=9101 WHERE id=9101");
    $db->exec("INSERT INTO training_calendar_entries (institute_id,batch_year_id,calendar_id,session_date,component,scheduled_minutes) VALUES (9101,9101,9101,'2026-09-01','trade_theory',60)");
    $db->exec("INSERT INTO global_exam_rule_versions (id,source_document_id,code,cycle_type,minimum_attendance,minimum_formative,principal_relaxation_cap,requires_prn,requires_cbt_fee,requires_practical_fee,requires_ojt_for_certificate,practical_upload_hours,effective_from,status) VALUES (9101,9101,'LOCAL','mains',60,60,0,0,0,0,0,24,'2026-01-01','published')");
    $db->exec("INSERT INTO exam_rule_versions (id,institute_id,global_exam_rule_id,cycle_type) VALUES (9101,9101,9101,'mains')");
    $db->exec("INSERT INTO exam_cycles (id,institute_id,code,cycle_type,rule_id,schedule_source_id,eligibility_cutoff,exam_starts_on,activity_windows_json,status) VALUES (9101,9101,'LOCAL','mains',9101,9101,'2026-09-01','2026-09-20','{}','eligibility_open')");
    $exam=new EligibilityService($db);
    foundationReject(fn()=>$exam->freeze($actor,9101,9101,9101,9101,'LOCAL'),'ATTENDANCE_NOT_RECONCILED');
    $db->exec('UPDATE attendance_sessions SET locked_on=UTC_TIMESTAMP() WHERE id=9101');
    foundationSqlReject($db,'UPDATE attendance_records SET attended_minutes=0 WHERE session_id=9101');
    $eligibility=$exam->freeze($actor,9101,9101,9101,9101,'LOCAL');
    foundationCheck($db->query("SELECT eligibility_status FROM exam_eligibility WHERE id=$eligibility")->fetchColumn()==='eligible','Eligible status must be computed from the rule and locked evidence.');
    foundationSqlReject($db,"UPDATE exam_eligibility SET effective_attendance=100 WHERE id=$eligibility");
    foundationReject(fn()=>$exam->freeze($actor,9101,9101,9101,9101,'LOCAL'),'ELIGIBILITY_FROZEN');
    $db->exec("INSERT INTO fee_heads (id,institute_id,code,name,requires_enrollment) VALUES (9101,9101,'LOCAL-REG','Local registration',0)");
    $db->exec("INSERT INTO fee_structures (id,institute_id,trade_id,version_no,effective_from,created_by) VALUES (9101,9101,9101,1,'2026-01-01',9202)");
    $db->exec('INSERT INTO fee_structure_items (institute_id,structure_id,head_id,amount) VALUES (9101,9101,9101,1000)');
    $db->exec("UPDATE fee_structures SET status='active',activated_by=9202,activated_at=UTC_TIMESTAMP() WHERE id=9101");
    $db->exec("INSERT INTO student_fee_assessments (id,institute_id,student_id,structure_id,assessment_no,assessed_on,created_by) VALUES (9101,9101,9101,9101,'LOCAL','2026-08-01',9202)");
    $db->exec("INSERT INTO student_fee_assessment_items (institute_id,assessment_id,head_id,head_code,head_name,amount) VALUES (9101,9101,9101,'LOCAL-REG','Local registration',1000)");
    $fees=new FeeService($db);
    $fees->issueAssessment($actor,9101,9101);
    foundationSqlReject($db,'UPDATE student_fee_assessment_items SET amount=2000 WHERE assessment_id=9101');
    $db->exec("INSERT INTO fee_demands (id,institute_id,student_id,assessment_id,demand_no,issued_on,due_on) VALUES (9101,9101,9101,9101,'LOCAL','2026-08-01','2026-09-01')");
    $payment=$fees->recordPayment($actor,9101,9101,'500','cash','P','R','LOCAL-PAY');
    foundationCheck($fees->recordPayment($actor,9101,9101,'500.00','cash','P','R','LOCAL-PAY')===$payment,'Equivalent payment retry must reuse the original receipt.');
    foundationReject(fn()=>$fees->recordPayment($actor,9101,9101,'600','cash','P','R','LOCAL-PAY'),'RETRY_CONFLICT');
    foundationReject(fn()=>$fees->allocate($actor,9101,$payment,9101,'501'),'ALLOCATION_LIMIT');
    $allocation=$fees->allocate($actor,9101,$payment,9101,'500');
    foundationCheck($fees->allocate($actor,9101,$payment,9101,'500')===$allocation,'Allocation retry must not allocate twice.');
    $refund=$fees->requestRefund($actor,9101,$allocation,'100','LOCAL','cash','RF','LOCAL-RF');
    foundationReject(fn()=>$fees->requestRefund($actor,9101,$allocation,'401','LOCAL','cash','RF2','LOCAL-RF2'),'REFUND_LIMIT');
    foundationReject(fn()=>$fees->decideRefund($actor,9101,$refund,'approved'),'INDEPENDENT_APPROVAL');
    foundationReject(fn()=>$fees->reverse($approver,9101,$payment,9202,'RV','LOCAL'),'REVERSAL_REFUND_CONFLICT');
    $fees->decideRefund($approver,9101,$refund,'approved');
    $fees->decideRefund($approver,9101,$refund,'paid');
    foundationReject(fn()=>$fees->decideRefund($approver,9101,$refund,'cancelled'),'REFUND_STATE');
    foundationSqlReject($db,"UPDATE fee_payments SET amount=999 WHERE id=$payment");
    foundationCheck((int)$db->query('SELECT COUNT(*) FROM institute_activity_log')->fetchColumn()>=7,'Financial/admission/exam actions must retain actor audit records.');
    // Prove a second connection cannot enter the institute financial critical section concurrently.
    $other=new PDO($dsn,getenv('TEST_DB_USERNAME')?:'root',getenv('TEST_DB_PASSWORD')?:'',$options);
    $other->exec("USE `$name`");
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');
    $db->beginTransaction();
    $db->query('SELECT id FROM institute WHERE id=9101 FOR UPDATE')->fetchColumn();
    try {
        (new FeeService($other))->recordPayment($actor,9101,9101,'1','cash','WAIT-P','WAIT-R','WAIT');
        throw new RuntimeException('Concurrent financial critical section was not blocked.');
    } catch (PDOException $e) {
        foundationCheck((int)$e->errorInfo[1]===1205,'Expected institute lock timeout.');
        foundationCheck(!$other->inTransaction(),'Failed concurrent operation must roll back.');
    } finally { $db->rollBack(); }
    $admissions->cancel($actor,9101,9101,'LOCAL TEST');
    foundationCheck((int)$db->query('SELECT occupied_seats FROM batches WHERE id=9101')->fetchColumn()===0,'Cancellation must reconcile occupancy.');
    echo "Foundation integrity passed ($checks checks; admission, financial transactions, exam freeze, record guards and lock serialization).\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec("DROP DATABASE `$name`");
}
