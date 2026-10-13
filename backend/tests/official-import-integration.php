<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\SchemaInstaller;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\OfficialRecords\OfficialImportService;

// Explicit/manual integration test; creates and drops ONLY its own TEST_DB_* database.
$dsn = getenv('TEST_DB_DSN');
if (!$dsn) { fwrite(STDERR, "Disposable TEST_DB_DSN required.\n"); exit(1); }
$db = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$name = 'iti_official_test_' . bin2hex(random_bytes(6));
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) throw new RuntimeException($label);
    ++$count;
};
$reject = static function (callable $action, string $code) use ($check): void {
    try { $action(); }
    catch (HttpException $e) {
        $check($e->errorCode === $code, 'Wrong error: '.$e->errorCode);
        return;
    }
    throw new RuntimeException('Missing rejection: '.$code);
};

$db->exec("CREATE DATABASE ".$name." CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db->exec("USE ".$name);
    SchemaInstaller::install($db);
    $db->exec(file_get_contents(dirname(__DIR__).'/database/seeders/rbac_seed.sql'));
    $db->exec(file_get_contents(__DIR__.'/fixtures/foundation.sql'));

    // Synthetic published DGT evidence, explicitly *not* a real publication.
    $db->exec("UPDATE global_regulatory_documents
      SET document_type='official_result',status='published',
          reviewed_by='LOCAL TEST',reviewed_at=UTC_TIMESTAMP(),
          sha256=REPEAT('a',64) WHERE id=9101");
    // Different published, linked and typed source for certificate records.
    $db->exec("INSERT INTO global_regulatory_documents
      (id,authority,document_type,title,reference_no,issued_on,
       evidence_reference,sha256,status,reviewed_by,reviewed_at)
      VALUES (9201,'DGT','official_certificate','LOCAL CERTIFICATE FIXTURE',
              'LOCAL-CERT-TEST','2026-10-02','NOT-OFFICIAL',
              REPEAT('b',64),'published','LOCAL TEST',UTC_TIMESTAMP())");
    $db->exec("INSERT INTO regulatory_sources (id,institute_id,global_document_id)
      VALUES (9201,9101,9201)");
    $db->exec("INSERT INTO global_exam_rule_versions
      (id,source_document_id,code,cycle_type,minimum_attendance,minimum_formative,
       principal_relaxation_cap,requires_prn,requires_cbt_fee,requires_practical_fee,
       requires_ojt_for_certificate,practical_upload_hours,effective_from,status)
      VALUES (9101,9101,'LOCAL-TEST-RULE','mains',0,0,0,0,0,0,0,0,'2026-01-01','published')");
    $db->exec("INSERT INTO exam_rule_versions (id,institute_id,global_exam_rule_id,cycle_type)
      VALUES (9101,9101,9101,'mains')");
    $db->exec("INSERT INTO exam_cycles
      (id,institute_id,code,cycle_type,rule_id,schedule_source_id,eligibility_cutoff,exam_starts_on,activity_windows_json)
      VALUES (9101,9101,'LOCAL-TEST-CYCLE','mains',9101,9101,'2026-09-01','2026-10-01','{}')");

    $importer = new AuthContext(9202,9101,1,'local-importer',false,['principal'],
      ['result.import','certificate.record'],null,null);
    $other = new AuthContext(9204,9102,1,'other-importer',false,['principal'],
      ['result.import','certificate.record'],null,null);
    $service = new OfficialImportService($db);
    $resultData = [
      'regulatory_source_id'=>9101,
      'source_system'=>'DGT','source_reference'=>'LOCAL-ONLY-TEST',
      'evidence_reference'=>'NOT-OFFICIAL-FIXTURE',
      'enrollment_id'=>9101,'batch_year_id'=>9101,'cycle_id'=>9101,
      'result_status'=>'pass','component_marks'=>['test_theory'=>80],
      'ojt_grade'=>null,'revision_no'=>1,'supersedes_result_id'=>null,
      'declared_at'=>'2026-10-01 10:00:00',
    ];
    $reject(fn()=>$service->result($importer,9101,$resultData),'EXAM_CYCLE_UNOPENED');
    $db->exec("UPDATE exam_cycles SET status='eligibility_open' WHERE id=9101");
    $resultId = $service->result($importer,9101,$resultData);
    $check($resultId>0, 'Official result local import missing.');
    $check((int)$db->query('SELECT enrollment_id FROM official_results WHERE id='.$resultId)
      ->fetchColumn()===9101, 'Result enrolled identity changed.');
    $reject(fn()=>$service->result($other,9101,$resultData),'FORBIDDEN');
    $reject(fn()=>$service->result($importer,9101,[...$resultData,'revision_no'=>2]),'OFFICIAL_REVISION');

    $certData = [
      'regulatory_source_id'=>9201,'source_system'=>'DGT',
      'source_reference'=>'LOCAL-ONLY-TEST','evidence_reference'=>'NOT-OFFICIAL-FIXTURE',
      'enrollment_id'=>9101,'official_result_id'=>$resultId,'document_type'=>'NTC',
      'certificate_number'=>'TEST-NTC-1','revision_no'=>1,'supersedes_certificate_id'=>null,
      'status'=>'valid','apaar_id_as_issued'=>null,'credits_as_issued'=>null,
      'ojt_grade_as_issued'=>null,'verification_reference'=>'LOCAL-TEST',
      'issued_on'=>'2026-10-02',
    ];
    $reject(fn()=>$service->result($importer,9101,[...$resultData,
      'regulatory_source_id'=>9201]),'OFFICIAL_DOCUMENT_TYPE');
    $reject(fn()=>$service->certificate($importer,9101,[...$certData,
      'regulatory_source_id'=>9101]),'OFFICIAL_DOCUMENT_TYPE');
    $certificateId = $service->certificate($importer,9101,$certData);
    $check($certificateId>0,'Certificate local import missing.');
    $reject(fn()=>$service->certificate($other,9101,$certData),'FORBIDDEN');
    // Marksheets from two different exam attempts are independent records,
    // not revision 1 and revision 2 of one certificate series.
    $firstSheet = $service->certificate($importer,9101,[
      ...$certData,
      'document_type'=>'marksheet','certificate_number'=>'TEST-MARKSHEET-ATTEMPT-1',
    ]);
    $check($firstSheet>0, 'First attempt marksheet missing.');
    $db->exec("INSERT INTO exam_cycles
      (id,institute_id,code,cycle_type,rule_id,schedule_source_id,
       eligibility_cutoff,exam_starts_on,activity_windows_json,status)
      VALUES (9202,9101,'LOCAL-RETAKE-CYCLE','mains',9101,9101,
              '2026-09-01','2026-10-03','{}','eligibility_open')");
    $secondAttemptResult = $service->result($importer,9101,[
      ...$resultData,
      'cycle_id'=>9202,'source_reference'=>'SECOND-ATTEMPT-LOCAL-TEST',
    ]);
    $secondSheet = $service->certificate($importer,9101,[
      ...$certData,
      'official_result_id'=>$secondAttemptResult,
      'document_type'=>'marksheet',
      'certificate_number'=>'TEST-MARKSHEET-ATTEMPT-2',
      'revision_no'=>1,
      'supersedes_certificate_id'=>null,
    ]);
    $check($secondSheet>$firstSheet,
      'An independent exam attempt must support its own first marksheet revision.');
    $reject(fn()=>$service->certificate($importer,9101,[
      ...$certData,
      'official_result_id'=>$secondAttemptResult,
      'document_type'=>'marksheet',
      'certificate_number'=>'TEST-MARKSHEET-DUP',
      'revision_no'=>1,
      'supersedes_certificate_id'=>null,
    ]),'OFFICIAL_REVISION');


    $revision = $service->result($importer,9101,[...$resultData,
      'revision_no'=>2,'supersedes_result_id'=>$resultId,'result_status'=>'fail']);
    $check($revision>$resultId,'Historical correction not appended.');
    // A previously valid NTC can only be newly imported from the current
    // result revision, not from a superseded historical pass.
    $reject(fn()=>$service->certificate($importer,9101,[
        ...$certData,
        'certificate_number'=>'TEST-NTC-REV2',
        'revision_no'=>2,
        'supersedes_certificate_id'=>$certificateId,
    ]),'OFFICIAL_RESULT_SUPERSEDED');
    $reject(fn()=>$service->result($importer,9101,[...$resultData,
      'revision_no'=>2,'supersedes_result_id'=>$resultId]),'OFFICIAL_REVISION');
    echo "Official local import integration passed (".$count." checks).\n";
} finally {
    $db->exec("DROP DATABASE ".$name);
}
