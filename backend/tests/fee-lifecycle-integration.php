<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\SchemaInstaller;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Fees\Application\FeeSetupService;
use App\Modules\Fees\Application\FeeService;
use App\Modules\Fees\Application\FeeCashService;

// Explicit manual integration runner. Never connects to the app database.
$dsn = getenv('TEST_DB_DSN');
if (!$dsn) { fwrite(STDERR, "Disposable TEST_DB_DSN required.\n"); exit(1); }
$db = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$name = 'iti_fin_lifecycle_' . bin2hex(random_bytes(6));
$count = 0;
$check = static function (bool $ok, string $why) use (&$count): void {
    if (!$ok) throw new RuntimeException($why);
    $count++;
};
$reject = static function (callable $call, string $code) use ($check): void {
    try { $call(); }
    catch (HttpException $error) {
        $check($error->errorCode === $code, 'Wrong error code: '.$error->errorCode);
        return;
    }
    throw new RuntimeException('Missing expected rejection: '.$code);
};
$db->exec("CREATE DATABASE ".$name." CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db->exec("USE ".$name);
    SchemaInstaller::install($db);
    $db->exec("INSERT INTO institute (id,name,mis_code,address) VALUES
        (1,'LOCAL FINANCE','FIN-1','TEST'),(2,'OTHER FINANCE','FIN-2','TEST')");
    $db->exec("INSERT INTO trades (id,dgt_code)
        VALUES (1,'TEST-TRADE'),(2,'FOREIGN-INSTITUTE-TRADE')");
    $db->exec("INSERT INTO trade_units (id,institute_id,trade_id,unit_no)
        VALUES (1,1,1,1),(2,2,2,1)");
    $db->exec("INSERT INTO auth_users (id,institute_id,username,password_hash) VALUES
        (1,1,'cashier','TEST'),(2,1,'approver','TEST'),(3,2,'other','TEST')");
    $db->exec("INSERT INTO students
        (id,institute_id,local_student_code,full_name,date_of_birth,gender,address,identity_mode,identity_evidence_reference)
        VALUES (1,1,'LOCAL1','Local A','2005-01-01','other','TEST','non_aadhaar','TEST'),
               (2,1,'LOCAL2','Local B','2005-01-01','other','TEST','non_aadhaar','TEST')");
    $permissions = ['fee.record','fee.refund'];
    $operator = new AuthContext(1,1,1,'cashier',false,['administrator'],$permissions,null,null);
    $approver = new AuthContext(2,1,2,'approver',false,['principal'],$permissions,null,null);
    $foreign = new AuthContext(3,2,3,'other',false,['administrator'],$permissions,null,null);
    $setup = new FeeSetupService($db);
    $service = new FeeService($db);
    $cash = new FeeCashService($db);
    $head = $setup->createHead($operator,1,'REG','Registration',false);
    $check($head > 0, 'Fee head missing.');
    $reject(fn()=>$setup->createHead($operator,1,'REG','Duplicate',false), 'FEE_IDENTIFIER_EXISTS');
    $reject(fn()=>$setup->draftStructure($operator,1,2,1,'2026-01-01',null),
        'INSTITUTE_TRADE_NOT_CONFIGURED');
    $version = $setup->draftStructure($operator,1,1,1,'2026-01-01',null);
    $item = $setup->setItem($operator,1,$version,$head,'1000.00');
    $check($version > 0 && $item > 0, 'Fee draft incomplete.');
    $setup->activateStructure($operator,1,$version);
    $reject(fn()=>$setup->setItem($operator,1,$version,$head,'10.00'),'FEE_CONFIG_FROZEN');
    $reject(fn()=>$setup->createHead($foreign,1,'TEST','Other',false),'FORBIDDEN');

    $on = gmdate('Y-m-d');
    $assessment = $setup->draftAssessment($operator,1,1,null,$version,'A-1',$on);
    $service->issueAssessment($operator,1,$assessment);
    $check($db->query("SELECT status FROM student_fee_assessments WHERE id=".$assessment)->fetchColumn()==='issued','Fee assessment not issued.');
    $demand = $service->createDemand($operator,1,$assessment,'D-1',gmdate('Y-m-d',time()+86400));
    $check($demand > 0,'Demand missing.');
    $reject(fn()=>$service->createDemand($operator,1,$assessment,'D-2',gmdate('Y-m-d',time()+86400)),'DEMAND_EXISTS');
    $payment = $service->recordPayment($operator,1,1,'1000.00','cash','P-1','R-1','KEY-1');
    $check($service->allocate($operator,1,$payment,$demand,'1000.00') > 0,'Payment allocation failed.');
    $second = $setup->draftAssessment($operator,1,2,null,$version,'A-2',$on);
    $service->issueAssessment($operator,1,$second);
    $waiver = $service->requestWaiver($operator,1,$second,'W-1','100.00','Local concession');
    $reject(fn()=>$service->approveWaiver($operator,1,$waiver),'WAIVER_APPROVAL');
    $service->approveWaiver($approver,1,$waiver);
    $check($db->query("SELECT status FROM fee_waivers WHERE id=".$waiver)->fetchColumn()==='approved','Independent waiver approval failed.');

    $movement = $cash->movement($operator,1,'bank_deposit','10.00','LOCAL-BANK','MOV-KEY');
    $check($cash->movement($operator,1,'bank_deposit','10.00','LOCAL-BANK','MOV-KEY')===$movement,'Cash movement retry mismatch.');
    $reject(fn()=>$cash->movement($operator,1,'bank_deposit','11.00','LOCAL-BANK','MOV-KEY'),'RETRY_CONFLICT');
    $previous = gmdate('Y-m-d',time()-86400);
    $insert = $db->prepare("INSERT INTO fee_cash_transfers
       (institute_id,occurred_at,transfer_type,amount,reference_no,idempotency_key,request_fingerprint,recorded_by)
       VALUES (1,?,'counter_addition',10.00,'LOCAL','BACKDATED-TEST',REPEAT('a',64),1)");
    $insert->execute([$previous.' 12:00:00']);
    $closing = $cash->close($operator,1,$previous,'50.00','60.00','LOCAL-OPENING');
    $check($closing['expected_amount']==='60.00' && $closing['difference_cents']===0,'Cash reconciliation mismatch.');
    $reject(fn()=>$cash->close($operator,1,$previous,'50.00','60.00','RETRY'),'CASH_ALREADY_CLOSED');
    $reject(fn()=>$cash->reviewClosing($operator,1,$closing['closing_id'],'reviewed','SELF'),'PRINCIPAL_REQUIRED');
    $cash->reviewClosing($approver,1,$closing['closing_id'],'reviewed','PRINCIPAL-REVIEW');
    $check((int)$db->query("SELECT COUNT(*) FROM institute_activity_log
        WHERE action_code='fee.cash.close.review' AND entity_id=".(int)$closing['closing_id'])->fetchColumn()===1,
        'Independent closing review audit missing.');
    $reject(fn()=>$cash->reviewClosing($approver,1,$closing['closing_id'],'reviewed','REPEAT'),'CASH_ALREADY_REVIEWED');
    // A different institute with a counted cash discrepancy must not be
    // marked "reviewed": retain the original immutable variance and flag it.
    $db->exec("INSERT INTO auth_users (id,institute_id,username,password_hash)
               VALUES (4,2,'other-principal','TEST')");
    $otherCashier = new AuthContext(3,2,3,'other',false,['administrator'],$permissions,null,null);
    $otherPrincipal = new AuthContext(4,2,4,'other-principal',false,['principal'],$permissions,null,null);
    $difference = $cash->close($otherCashier,2,$previous,'0.00','1.00','TEST-DIFFERENCE');
    $check($difference['difference_cents']===100,'Fixture variance must remain 100 cents.');
    $reject(fn()=>$cash->reviewClosing($otherPrincipal,2,$difference['closing_id'],
        'reviewed','INVALID-APPROVAL'),'CASH_DIFFERENCE_UNRESOLVED');
    $cash->reviewClosing($otherPrincipal,2,$difference['closing_id'],
        'requires_correction','INDEPENDENT-REVIEW-REQUIRED');
    $check((int)$db->query("SELECT COUNT(*) FROM institute_activity_log
        WHERE institute_id=2 AND action_code='fee.cash.close.review'")->fetchColumn()===1,
        'Discrepancy review must be audited without changing cash data.');

    echo "Finance lifecycle integration passed (".$count." checks).\n";
} finally {
    $db->exec("DROP DATABASE ".$name);
}
