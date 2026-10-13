<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\SchemaInstaller;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Exams\ExamCycleService;

// This is a manually invoked test. Only a randomly named disposable DB is modified.
$dsn = getenv('TEST_DB_DSN');
if (!$dsn) {
    fwrite(STDERR, "Disposable TEST_DB_DSN required.\n");
    exit(1);
}
$db = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$name = 'iti_exam_cycle_test_' . bin2hex(random_bytes(6));
$checks = 0;
$assert = static function (bool $ok, string $reason) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($reason);
    }
    $checks++;
};
$reject = static function (callable $f, string $expected) use ($assert): void {
    try {
        $f();
    } catch (HttpException $e) {
        $assert($e->errorCode === $expected, 'Expected '.$expected.', got '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$expected);
};
$db->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $db->exec('USE '.$name);
    SchemaInstaller::install($db);
    $db->exec(file_get_contents(dirname(__DIR__).'/database/seeders/rbac_seed.sql'));
    $db->exec(file_get_contents(__DIR__.'/fixtures/foundation.sql'));
    $db->exec("UPDATE global_regulatory_documents
       SET status='published',reviewed_by='LOCAL TEST',reviewed_at=UTC_TIMESTAMP(),
           sha256=REPEAT('a',64) WHERE id=9101");
    $db->exec("INSERT INTO global_regulatory_documents
      (id,authority,document_type,title,reference_no,issued_on,
       evidence_reference,sha256,status,reviewed_by,reviewed_at)
      VALUES (9301,'DGT','AITT_Exam_Schedule','NOT OFFICIAL SCHEDULE',
        'LOCAL-EXAM-SCHEDULE','2026-07-01','LOCAL-FIXTURE-ONLY',REPEAT('b',64),
        'published','LOCAL-REVIEW',UTC_TIMESTAMP())");
    $db->exec('INSERT INTO regulatory_sources (id,institute_id,global_document_id)
        VALUES (9301,9101,9301),(9302,9102,9301)');
    $db->exec("INSERT INTO global_exam_rule_versions
      (id,source_document_id,code,cycle_type,minimum_attendance,minimum_formative,
       principal_relaxation_cap,requires_prn,requires_cbt_fee,requires_practical_fee,
       requires_ojt_for_certificate,practical_upload_hours,effective_from,status)
      VALUES (9301,9101,'LOCAL-EXAM-RULE','mains',0,0,0,0,0,0,0,0,
        '2026-01-01','published')");
    $db->exec("INSERT INTO exam_rule_versions
      (id,institute_id,global_exam_rule_id,cycle_type)
      VALUES (9301,9101,9301,'mains')");
    $db->exec("INSERT INTO auth_users (id,institute_id,username,password_hash)
      VALUES (9302,9101,'local-principal-review','TEST-ONLY')");
    $operator = new AuthContext(9202,9101,1,'operator',false,
      ['administrator'],['exam.eligibility.manage'],9101,null);
    $principal = new AuthContext(9302,9101,2,'principal',false,
      ['principal'],['exam.eligibility.manage'],null,null);
    $foreign = new AuthContext(9204,9102,3,'foreign',false,
      ['principal'],['exam.eligibility.manage'],9102,null);
    $service = new ExamCycleService($db);
    $reject(fn()=>$service->draft($operator,9101,'BAD-SOURCE','mains',9301,9101,
       '2026-09-01','2026-10-01','{}'), 'EXAM_SCHEDULE_SOURCE_TYPE');
    $reject(fn()=>$service->draft($foreign,9101,'FOREIGN','mains',9301,9301,
       '2026-09-01','2026-10-01','{}'), 'FORBIDDEN');
    $id = $service->draft($operator,9101,'LOCAL-VERIFIED-FIXTURE','mains',9301,9301,
       '2026-09-01','2026-10-01','{}');
    $assert($id>0, 'Source-backed draft was not recorded.');
    $assert($db->query('SELECT status FROM exam_cycles WHERE id='.$id)
        ->fetchColumn()==='draft', 'Cycle should begin draft.');
    $reject(fn()=>$service->open($operator,9101,$id,'LOCAL'), 'PRINCIPAL_REQUIRED');
    $service->open($principal,9101,$id,'LOCAL-REVIEW-NOT-OFFICIAL');
    $assert($db->query('SELECT status FROM exam_cycles WHERE id='.$id)
        ->fetchColumn()==='eligibility_open', 'Principal-reviewed cycle should open.');
    $reject(fn()=>$service->open($principal,9101,$id,'DUPLICATE'),
       'EXAM_CYCLE_FROZEN');
    echo "Exam cycle integration passed (".$checks." checks).\n";
} finally {
    // This script drops only the disposable database that it created.
    $db->exec('DROP DATABASE '.$name);
}
