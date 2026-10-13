<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use App\Core\SchemaInstaller;
use App\Core\MigrationRunner;
use App\Core\PrivateFiles;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Auth\Application\PasswordRecoveryService;
use App\Modules\Documents\DocumentService;
use App\Modules\Students\Application\StudentLifecycleService;
use App\Modules\Assessment\AcademicCorrectionService;

$dsn=getenv('TEST_DB_DSN');
if (!$dsn) throw new RuntimeException('Disposable test database required.');
$db=new PDO($dsn,getenv('TEST_DB_USERNAME')?:'root',getenv('TEST_DB_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$name='iti_pending_'.bin2hex(random_bytes(6));
$root=sys_get_temp_dir().'/'.$name;
$checks=0;
$check=static function(bool $ok,string $why) use (&$checks): void { if (!$ok) throw new RuntimeException($why); $checks++; };
$reject=static function(callable $work,string $code) use ($check): void { try { $work(); } catch(HttpException $e) { $check($e->errorCode===$code,'Unexpected rejection: '.$e->errorCode); return; } throw new RuntimeException('Missing rejection '.$code); };
$sqlReject=static function(string $sql) use ($db,$check): void { try { $db->exec($sql); } catch(PDOException $e) { $check((int)$e->errorInfo[1]===1644,'Wrong immutable guard rejection.'); return; } throw new RuntimeException('Mutable history accepted.'); };
$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db->exec("USE `$name`"); SchemaInstaller::install($db);
    $db->exec("SET time_zone='+00:00'");
    $db->exec(file_get_contents(dirname(__DIR__).'/database/seeders/rbac_seed.sql'));
    $db->exec(file_get_contents(__DIR__.'/fixtures/foundation.sql'));
    // Reconstruct the populated 97-table baseline without touching an app DB.
    foreach (['lesson_plans_approved_update','lesson_plans_approved_delete','training_progress_immutable_update','training_progress_immutable_delete'] as $trigger) $db->exec("DROP TRIGGER $trigger");
    $db->exec('DROP TABLE training_progress'); $db->exec('DROP TABLE lesson_plans');
    foreach (['private_documents','formative_corrections','attendance_corrections'] as $table) {
        foreach (['update','delete'] as $event) $db->exec("DROP TRIGGER {$table}_immutable_$event");
    }
    $db->exec('DROP TABLE private_documents'); $db->exec('DROP TABLE formative_corrections');
    $migrated=MigrationRunner::run($db,dirname(__DIR__).'/database/migrations',true);
    $check(count($migrated['applied'])===14,'Additive upgrade must apply all reviewed files.');
    $check((int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn()===2,'Migration must preserve populated student records.');
    $check(MigrationRunner::run($db,dirname(__DIR__).'/database/migrations',true)['applied']===[],'Applied migrations must not replay.');
    $check(\App\Core\MigrationBaseline::run($db,dirname(__DIR__).'/database/migrations')['baselined']===[],'Migrated populated schema must match canonical DDL and existing ledger.');
    $db->exec("INSERT INTO staff(id,institute_id,employee_code,full_name,staff_category,designation,joined_on) VALUES (9115,9101,'P','Principal','non_teaching','Principal','2026-01-01')");
    $db->exec("INSERT INTO auth_users(id,institute_id,username,password_hash) VALUES (9215,9101,'principal','TEST')");
    $permissions=['student.manage','enrollment.manage','document.manage','document.view','attendance.correct','formative.template.manage'];
    $principal=new AuthContext(9215,9101,1,'principal',false,['principal'],$permissions,9115,null);
    $administrator=new AuthContext(9202,9101,2,'local-instructor',false,['administrator'],$permissions,9101,null);
    $student=new AuthContext(9203,9101,3,'local-student',false,['student'],['document.view.own'],null,9101);
    $foreign=new AuthContext(9204,9102,4,'local-staff-b',false,['administrator'],['document.view','document.manage'],9102,null);
    $files=new PrivateFiles($root.'/documents');
    $documents=new DocumentService($db,$files);
    $pdf="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    $id=$documents->upload($principal,9101,'evidence.pdf',base64_encode($pdf));
    $check($documents->download($student,$id,true)['bytes']===$pdf,'Own authorized download must preserve bytes.');
    $check(count($documents->list($student,true,0,50)['documents'])===1,'Student document list scope failed.');
    $reject(fn()=>$documents->download($foreign,$id,false),'NOT_FOUND');
    $reject(fn()=>$documents->upload($principal,9102,'wrong.pdf',base64_encode($pdf)),'NOT_FOUND');
    $reject(fn()=>$documents->upload($principal,9101,'../escape.pdf',base64_encode($pdf)),'INVALID_DOCUMENT');
    $reject(fn()=>$documents->upload($principal,9101,'code.php',base64_encode('<?php echo 1;')),'INVALID_DOCUMENT');
    $sqlReject("UPDATE private_documents SET sha256=REPEAT('a',64) WHERE id=$id");
    $key=$db->query("SELECT storage_key FROM private_documents WHERE id=$id")->fetchColumn();
    file_put_contents($root.'/documents/'.$key,'tampered');
    $reject(fn()=>$documents->download($student,$id,true),'DOCUMENT_INTEGRITY');
    file_put_contents($root.'/documents/'.$key,$pdf);
    $documents->archive($principal,$id,'Replaced with reviewed evidence');
    $reject(fn()=>$documents->download($student,$id,true),'NOT_FOUND');
    $check(is_file($root.'/documents/'.$key),'Archival must preserve original evidence.');

    $recovery=new PasswordRecoveryService($db,new PrivateFiles($root.'/outbox'));
    $recovery->request('not-an-account',9101);
    $check(count(glob($root.'/outbox/*.json'))===0,'Unknown accounts must not generate recovery tokens.');
    $recovery->request('local-student',9102);
    $check(count(glob($root.'/outbox/*.json'))===0,'Recovery must be tenant-bound.');
    $recovery->request('local-student',9101);
    $mailFiles=glob($root.'/outbox/*.json');
    $mail=json_decode(file_get_contents($mailFiles[0]),true,32,JSON_THROW_ON_ERROR);
    $token=$mail['reset_token'];
    $check($db->query('SELECT token_hash FROM auth_password_resets LIMIT 1')->fetchColumn()===hash('sha256',$token),'Recovery DB must store only a token digest.');
    $recovery->request('local-student',9101);
    $check(count(glob($root.'/outbox/*.json'))===1,'Cooldown must prevent mailbox flooding.');
    $db->exec("INSERT INTO auth_sessions(user_id,institute_id,session_token_hash,issued_at,expires_at) VALUES(9203,9101,REPEAT('a',64),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))");
    $recovery->reset($token,'Recovered-strong-password-123');
    $check(password_verify('Recovered-strong-password-123',$db->query('SELECT password_hash FROM auth_users WHERE id=9203')->fetchColumn()),'Recovery password hash failed.');
    $check((int)$db->query('SELECT COUNT(*) FROM auth_sessions WHERE user_id=9203 AND revoked_at IS NULL')->fetchColumn()===0,'Recovery must revoke existing sessions.');
    $reject(fn()=>$recovery->reset($token,'Another-strong-password-123'),'INVALID_RESET_TOKEN');

    $corrections=new AcademicCorrectionService($db);
    $db->exec('UPDATE attendance_sessions SET locked_on=UTC_TIMESTAMP() WHERE id=9101');
    $reject(fn()=>$corrections->attendance($administrator,9101,9101,30,'present',0,'Reason','Order'),'PRINCIPAL_REQUIRED');
    $correction=$corrections->attendance($principal,9101,9101,30,'present',0,'Evidence correction','ITI/ORDER/1');
    $check((int)$db->query('SELECT attended_minutes FROM attendance_records WHERE session_id=9101')->fetchColumn()===40,'Locked attendance must remain original.');
    $reject(fn()=>$corrections->attendance($principal,9101,9101,35,'present',0,'Reason','Order'),'STALE_CORRECTION');
    $sqlReject("DELETE FROM attendance_corrections WHERE id=$correction");
    $db->exec("INSERT INTO global_learning_outcomes(id,global_curriculum_year_id,lo_code,outcome_text,assessment_criteria) VALUES(9101,9101,'TEST','TEST','TEST')");
    $db->exec("INSERT INTO learning_outcomes(id,institute_id,curriculum_year_id,global_curriculum_year_id,global_learning_outcome_id,lo_code) VALUES(9101,9101,9101,9101,9101,'TEST')");
    $db->exec("INSERT INTO formative_assessments(id,institute_id,enrollment_id,batch_id,batch_year_id,curriculum_year_id,learning_outcome_id,assessed_on,max_marks,obtained_marks,evidence_reference,assessor_id,locked_on) VALUES(9101,9101,9101,9101,9101,9101,9101,'2026-09-01',100,80,'TEST',9101,UTC_TIMESTAMP())");
    $scoreCorrection=$corrections->formative($principal,9101,9101,9101,null,'90.00',0,'Corrected marks','ITI/ORDER/2');
    $check($db->query('SELECT obtained_marks FROM formative_assessments WHERE id=9101')->fetchColumn()==='80.00','Locked formative score must remain original.');
    $reject(fn()=>$corrections->formative($principal,9101,9101,9101,null,'101',0,'Reason','Order',$scoreCorrection),'CORRECTION_STATE');
    $sqlReject("UPDATE formative_corrections SET reason='tampered' WHERE id=$scoreCorrection");

    $lifecycle=new StudentLifecycleService($db);
    $lifecycle->profile($principal,9101,9101,['full_name'=>'Corrected learner'],'Documented spelling correction');
    $reject(fn()=>$lifecycle->profile($principal,9101,9101,['prn'=>'forged'],'Reason'),'INVALID_INPUT');
    $db->exec("UPDATE enrollments SET status='active' WHERE id=9101");
    $db->exec('UPDATE batches SET occupied_seats=1 WHERE id=9101');
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    $reject(fn()=>$lifecycle->transition($administrator,9101,9101,'dropout',$today,0,'ITI/ORDER','Reason'),'PRINCIPAL_REQUIRED');
    $reject(fn()=>$lifecycle->transition($principal,9101,9101,'completed',$today,0,'ITI/ORDER','Reason'),'LIFECYCLE_STATE');
    $lifecycle->transition($principal,9101,9101,'transferred',$today,0,'ITI/ORDER/3','Approved local transfer','OTHER-ITI');
    $check($db->query('SELECT status FROM enrollments WHERE id=9101')->fetchColumn()==='transferred','Transfer state missing.');
    $check((int)$db->query('SELECT occupied_seats FROM batches WHERE id=9101')->fetchColumn()===1,'Historical transfer must retain consumed intake seat.');
    $check((int)$db->query('SELECT COUNT(*) FROM student_events WHERE enrollment_id=9101')->fetchColumn()===1,'Transfer event history missing.');
    echo "Pending institute workflow integration passed ($checks checks).\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec("DROP DATABASE `$name`");
    foreach (['documents','outbox'] as $folder) {
        foreach (glob($root.'/'.$folder.'/*')?:[] as $path) unlink($path);
        if (is_dir($root.'/'.$folder)) rmdir($root.'/'.$folder);
    }
    if (is_dir($root)) rmdir($root);
}
