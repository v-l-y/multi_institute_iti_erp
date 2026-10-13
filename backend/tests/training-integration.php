<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use App\Core\SchemaInstaller;
use App\Core\MigrationBaseline;
use App\Core\MigrationRunner;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Training\TrainingService;
use App\Http\HttpException;
use App\Http\Request;

$dsn=getenv('TEST_DB_DSN'); if (!$dsn) throw new RuntimeException('Disposable test database required.');
$db=new PDO($dsn,getenv('TEST_DB_USERNAME')?:'root',getenv('TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$name='iti_training_'.bin2hex(random_bytes(6)); $checks=0;
$check=static function(bool $ok,string $why) use (&$checks): void { if (!$ok) throw new RuntimeException($why); $checks++; };
$reject=static function(callable $call,string $code) use ($check): void { try { $call(); } catch(HttpException $e) { $check($e->errorCode===$code,'Wrong rejection '.$e->errorCode); return; } throw new RuntimeException('Missing '.$code); };
$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db->exec("USE `$name`"); SchemaInstaller::install($db); $db->exec("SET time_zone='+00:00'");
    $directory=dirname(__DIR__).'/database/migrations';
    $check(count(MigrationBaseline::run($db,$directory)['baselined'])===14,'Fresh canonical ledger baseline must record reviewed files.');
    $check(MigrationRunner::run($db,$directory,true)['applied']===[],'Fresh baseline must not replay CREATE statements.');
    $check(MigrationBaseline::run($db,$directory)['baselined']===[],'Baseline repeat must be idempotent.');
    $db->exec('ALTER TABLE students ADD COLUMN baseline_mismatch INT NULL');
    try { MigrationBaseline::run($db,$directory); throw new LogicException('Mismatched DDL accepted.'); } catch(RuntimeException $e) { $check(str_contains($e->getMessage(),'DDL mismatch'),'Baseline must refuse schema drift.'); }
    $db->exec('ALTER TABLE students DROP COLUMN baseline_mismatch');
    $db->exec('DROP TRIGGER training_progress_immutable_delete');
    try { MigrationBaseline::run($db,$directory); throw new LogicException('Missing immutable guard accepted.'); } catch(RuntimeException $e) { $check(str_contains($e->getMessage(),'DDL mismatch'),'Baseline must verify guard definitions, not only columns.'); }
    $db->exec(MigrationRunner::singleStatement(file_get_contents($directory.'/014_training_progress_delete_guard.sql')));
    $db->exec(file_get_contents(dirname(__DIR__).'/database/seeders/rbac_seed.sql'));
    $db->exec(file_get_contents(__DIR__.'/fixtures/foundation.sql'));
    $db->exec("UPDATE enrollments SET status='active' WHERE id=9101");
    $db->exec("INSERT INTO staff(id,institute_id,employee_code,full_name,staff_category,designation,joined_on) VALUES (9115,9101,'P','Local Principal','non_teaching','Principal','2026-01-01')");
    $db->exec("INSERT INTO auth_users(id,institute_id,username,password_hash) VALUES (9215,9101,'principal','TEST')");
    $db->exec("INSERT INTO global_learning_outcomes(id,global_curriculum_year_id,lo_code,outcome_text,assessment_criteria) VALUES (9101,9101,'LOCAL-LO','Fictional instructional outcome','LOCAL TEST ONLY')");
    $db->exec("INSERT INTO learning_outcomes(id,institute_id,curriculum_year_id,global_curriculum_year_id,global_learning_outcome_id,lo_code) VALUES (9101,9101,9101,9101,9101,'LOCAL-LO')");
    $db->exec("UPDATE batch_years SET calendar_status='reviewed',calendar_reviewed_through='2026-09-03' WHERE id=9101");
    $db->exec("INSERT INTO training_calendar_entries(institute_id,batch_year_id,calendar_id,session_date,component,scheduled_minutes) VALUES (9101,9101,9101,'2026-09-01','trade_theory',60),(9101,9101,9101,'2026-09-02','trade_practical',60)");
    $db->exec("INSERT INTO subject_batch_assignments(institute_id,mapping_id,batch_year_id,assigned_minutes_per_week,principal_reference) VALUES (9101,9101,9101,120,'LOCAL')");
    $teacher=new AuthContext(9202,9101,1,'local-instructor',false,['instructor'],['training.record.assigned'],9101,null);
    $principal=new AuthContext(9215,9101,2,'principal',false,['principal'],['training.plan.manage','training.correct','training.view','fee.view'],9115,null);
    $student=new AuthContext(9203,9101,3,'local-student',false,['student'],['training.view.own'],null,9101);
    $foreign=new AuthContext(9204,9102,4,'other',false,['administrator'],['training.view','training.plan.manage','fee.view'],9102,null);
    $service=new TrainingService($db);
    $plan=['batch_year_id'=>9101,'learning_outcome_id'=>9101,'instructor_id'=>9101,'session_date'=>'2026-09-01','component'=>'trade_theory','activity_type'=>'lesson','title'=>'LOCAL safe practice','instructions'=>'Demonstrate the fictional procedure','start_minute'=>540,'planned_minutes'=>30];
    $id=$service->plan($teacher,$plan); $check($id>0,'Lesson plan must persist.');
    $reject(fn()=>$service->plan($student,$plan),'FORBIDDEN');
    $reject(fn()=>$service->plan($foreign,$plan),'NOT_FOUND');
    $service->plan($teacher,[...$plan,'title'=>'Revised LOCAL lesson'],$id);
    $service->plan($principal,[...$plan,'title'=>'Principal-edited LOCAL lesson'],$id);
    $reject(fn()=>$service->approve($principal,$id,'Self-review after editing'),'INDEPENDENT_REVIEW');
    $service->plan($teacher,[...$plan,'title'=>'Re-reviewed LOCAL lesson'],$id);
    $reject(fn()=>$service->plan($teacher,[...$plan,'planned_minutes'=>61]),'PLAN_CAPACITY');
    $reject(fn()=>$service->plan($teacher,[...$plan,'start_minute'=>550]),'TIMETABLE_CONFLICT');
    $reject(fn()=>$service->approve($foreign,$id,'ORDER'),'PRINCIPAL_REQUIRED');
    $service->approve($principal,$id,'LOCAL APPROVAL');
    $reject(fn()=>$service->deleteDraft($teacher,$id,'Do not erase approved history'),'PLAN_LOCKED');
    $principalDraft=$service->plan($principal,[...$plan,'start_minute'=>570,'title'=>'LOCAL principal draft']);
    $reject(fn()=>$service->approve($principal,$principalDraft,'Own approval'),'INDEPENDENT_REVIEW');
    $service->deleteDraft($principal,$principalDraft,'Unused fictional draft');
    $check((int)$db->query("SELECT COUNT(*) FROM lesson_plans WHERE id=$principalDraft")->fetchColumn()===0,'Unused draft deletion must preserve approved plan.');
    $reject(fn()=>$service->plan($teacher,$plan,$id),'PLAN_LOCKED');
    try { $db->exec("UPDATE lesson_plans SET title='tampered' WHERE id=$id"); throw new LogicException('Approved plan mutable.'); } catch(PDOException $e) { $check((int)$e->errorInfo[1]===1644,'Approved plan SQL guard must reject overwrite.'); }
    $progress=['plan_id'=>$id,'enrollment_id'=>9101,'progress_status'=>'practised','attended_minutes'=>20,'recorded_on'=>'2026-09-01','evidence_reference'=>'LOCAL-PROGRESS-1','notes'=>'Fictional trainee practice'];
    $record=$service->progress($teacher,$progress); $check($record>0,'Assigned progress must persist.');
    $reject(fn()=>$service->progress($teacher,[...$progress,'attended_minutes'=>5]),'PROGRESS_EXISTS');
    $reject(fn()=>$service->progress($teacher,$progress),'PROGRESS_LIMIT');
    $reject(fn()=>$service->progress($teacher,[...$progress,'enrollment_id'=>9102]),'ENROLLMENT_NOT_ACTIVE');
    $correction=$service->progress($principal,[...$progress,'attended_minutes'=>25,'supersedes_id'=>$record,'correction_reason'=>'Reviewed local duration correction','evidence_reference'=>'LOCAL-CORRECTION']);
    $check($correction>$record,'Correction must append history.');
    $reject(fn()=>$service->progress($principal,[...$progress,'supersedes_id'=>$record,'correction_reason'=>'stale','evidence_reference'=>'STALE']),'STALE_CORRECTION');
    $reject(fn()=>$service->voidProgress($teacher,$correction,'LOCAL-VOID','Correction'),'FORBIDDEN');
    $reject(fn()=>$service->voidProgress($principal,$record,'LOCAL-VOID','Correction'),'STALE_CORRECTION');
    $service->voidProgress($principal,$correction,'LOCAL-VOID-EVIDENCE','Incorrect training credit, reverse fully');
    $check((int)$db->query("SELECT COUNT(*) FROM institute_activity_log WHERE action_code='training.progress.void' AND entity_id=$correction")===1,
        'Principal void must preserve independently reviewed immutable evidence.');
    $reject(fn()=>$service->voidProgress($principal,$correction,'DUPLICATE','Again'),'PROGRESS_VOIDED');
    $reject(fn()=>$service->progress($principal,[...$progress,'supersedes_id'=>$correction,'correction_reason'=>'Invalid recredit','evidence_reference'=>'STALE-VOID']),'PROGRESS_VOIDED');
    $check((int)$db->query("SELECT attended_minutes FROM training_progress WHERE id=$correction")->fetchColumn()===25,
        'Voided progress history must remain unchanged.');
    try { $db->exec("DELETE FROM training_progress WHERE id=$correction"); throw new LogicException('Mutable progress.'); } catch(PDOException $e) { $check((int)$e->errorInfo[1]===1644,'Progress deletion must be rejected.'); }
    $practical=$service->plan($teacher,[...$plan,'session_date'=>'2026-09-02','component'=>'trade_practical','activity_type'=>'practical','title'=>'LOCAL practical']);
    $service->approve($principal,$practical,'LOCAL PRACTICAL');
    $demonstration=$service->plan($teacher,[...$plan,'session_date'=>'2026-09-02','component'=>'trade_practical','activity_type'=>'demonstration','start_minute'=>570,'title'=>'LOCAL demonstration']);
    $service->approve($principal,$demonstration,'LOCAL DEMONSTRATION');
    preg_match('/host=([^;]+)/',$dsn,$host); preg_match('/port=([^;]+)/',$dsn,$port);
    $database=new App\Core\Database(['host'=>$host[1],'port'=>$port[1]??3306,'database'=>$name,'username'=>getenv('TEST_DB_USERNAME')?:'root','password'=>getenv('TEST_DB_PASSWORD')?:'']);
    $controller=new App\Modules\Training\TrainingController($database);
    $check(count($controller->plans(new Request(),$student)->body['data']['plans'])===3,'Student should see approved plans in their own batch.');
    $check($controller->plans(new Request(),$foreign)->body['data']['plans']===[],'Foreign institute cannot read plans.');
    $check($controller->progress(new Request(),$student)->body['data']['progress']===[],
        'Voided latest progress must contribute zero effective credit in the default register.');
    $history=$controller->progress(new Request(query:['include_history'=>'1']),$student)->body['data']['progress'];
    $check(count($history)===2 && (int)$history[1]['is_voided']===1,
        'Authorized history must retain the corrected and voided progress with its void marker.');
    $reject(fn()=>$controller->void(new Request([
        'progress_id'=>$correction,'evidence_reference'=>'DUPLICATE','correction_reason'=>'Repeated void',
    ]),$principal),'PROGRESS_VOIDED');
    $fees=new App\Modules\Fees\Http\FeeRegisterController($database);
    foreach(['heads','structures','structure-items','assessments','demands','refunds','waivers','reversals'] as $kind) $check($fees->read(new Request(),$principal,$kind)->status===200,'Fee register SQL failed: '.$kind);
    $reject(fn()=>$fees->read(new Request(query:['student_id'=>'9101']),$foreign,'demands'),'NOT_FOUND');
    $reject(fn()=>$fees->read(new Request(),$student,'heads'),'FORBIDDEN');
    $check((int)$db->query('SELECT COUNT(*) FROM institute_activity_log WHERE action_code LIKE \'training.%\'')->fetchColumn()>=8,'Training actions require transactional audit.');
    echo "Training, read-register and baseline integration passed ($checks checks).\n";
} finally { if ($db->inTransaction()) $db->rollBack(); $db->exec("DROP DATABASE `$name`"); }
