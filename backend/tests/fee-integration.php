<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/autoload.php';

// Only TEST_DB_* credentials are accepted. Never uses the application's database.
$dsn = getenv('TEST_DB_DSN');
if (!$dsn) {
    fwrite(STDERR, "Set TEST_DB_DSN to the disposable test server.\n");
    exit(1);
}
$db = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'iti_fee_test_' . bin2hex(random_bytes(6));
$checks = 0;
function rejectsFeeSql(PDO $db, string $sql, array $codes): void
{
    global $checks;
    try {
        $db->exec($sql);
    } catch (PDOException $exception) {
        if (!in_array((int) ($exception->errorInfo[1] ?? 0), $codes, true)) {
            throw $exception;
        }
        $checks++;
        return;
    }
    throw new RuntimeException('Expected fee SQL rejection.');
}
$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db->exec("USE `$name`");
    App\Core\SchemaInstaller::install($db);
    $db->exec("INSERT INTO institute (id,name,mis_code,address) VALUES (1,'TEST A','TEST-A','Test'),(2,'TEST B','TEST-B','Test')");
    $db->exec("INSERT INTO trades (id,dgt_code) VALUES (1,'LOCAL-TEST')");
    $db->exec("INSERT INTO students (id,institute_id,local_student_code,full_name,date_of_birth,gender,address,identity_mode,identity_evidence_reference) VALUES (1,1,'A','Test A','2005-01-01','other','LOCAL','non_aadhaar','LOCAL'),(2,1,'B','Test B','2005-01-01','other','LOCAL','non_aadhaar','LOCAL'),(3,2,'C','Test C','2005-01-01','other','LOCAL','non_aadhaar','LOCAL')");
    $db->exec("INSERT INTO auth_users (id,institute_id,username,password_hash) VALUES (1,1,'requester','TEST-ONLY'),(2,1,'approver','TEST-ONLY'),(3,2,'foreign-user','TEST-ONLY')");
    $db->exec("INSERT INTO fee_heads (id,institute_id,code,name) VALUES (1,1,'REG','Registration')");
    $db->exec("INSERT INTO fee_structures (id,institute_id,trade_id,version_no,effective_from,created_by) VALUES (1,1,1,1,'2026-01-01',1)");
    $db->exec("INSERT INTO fee_structure_items (institute_id,structure_id,head_id,amount) VALUES (1,1,1,1000)");
    $db->exec("INSERT INTO student_fee_assessments (id,institute_id,student_id,structure_id,assessment_no,assessed_on,created_by,status) VALUES (1,1,1,1,'A','2026-01-01',1,'draft'),(2,1,2,1,'B','2026-01-01',1,'draft')");
    $db->exec("INSERT INTO student_fee_assessment_items (institute_id,assessment_id,head_id,head_code,head_name,amount) VALUES (1,1,1,'REG','Registration',1000)");
    $db->exec("UPDATE student_fee_assessments SET status='issued' WHERE id=1");
    $db->exec("INSERT INTO fee_demands (id,institute_id,student_id,assessment_id,demand_no,issued_on,due_on) VALUES (1,1,1,1,'A','2026-01-01','2026-02-01'),(2,1,2,2,'B','2026-01-01','2026-02-01')");
    $payment = "INSERT INTO fee_payments (id,institute_id,student_id,payment_no,receipt_no,paid_on,amount,payment_mode,idempotency_key,request_fingerprint,received_by) VALUES ";
    $db->exec($payment . "(1,1,1,'P','R',NOW(),500,'cash','PAY-1',REPEAT('a',64),1)");
    rejectsFeeSql($db, $payment . "(2,1,1,'P2','R2',NOW(),100,'cash','PAY-2',REPEAT('b',64),3)", [1452]);
    rejectsFeeSql($db, $payment . "(2,1,1,'P2','R2',NOW(),100,'cash','PAY-1',REPEAT('b',64),1)", [1062]);
    rejectsFeeSql($db, $payment . "(2,1,1,'P2','R2',NOW(),0,'cash','PAY-2',REPEAT('b',64),1)", [3819,4025]);
    rejectsFeeSql($db, "INSERT INTO fee_payment_allocations (institute_id,student_id,payment_id,demand_id,amount) VALUES (1,1,1,2,100)", [1452]);
    rejectsFeeSql($db, "INSERT INTO fee_payment_allocations (institute_id,student_id,payment_id,demand_id,amount) VALUES (2,3,1,1,100)", [1452]);
    $db->exec("INSERT INTO fee_payment_allocations (id,institute_id,student_id,payment_id,demand_id,amount) VALUES (1,1,1,1,1,500)");
    $refund = "INSERT INTO fee_refunds (institute_id,student_id,demand_id,allocation_id,refund_no,amount,reason,status,refund_mode,idempotency_key,request_fingerprint,requested_by,decided_by,decided_at,paid_by,paid_at) VALUES ";
    rejectsFeeSql($db, $refund . "(1,2,2,1,'RF',100,'Test','pending','cash','RF-1',REPEAT('a',64),1,NULL,NULL,NULL,NULL)", [1452]);
    rejectsFeeSql($db, $refund . "(1,1,1,1,'RF',100,'Test','approved','cash','RF-1',REPEAT('a',64),1,1,NOW(),NULL,NULL)", [3819,4025]);
    rejectsFeeSql($db, $refund . "(1,1,1,1,'RF',100,'Test','paid','cash','RF-1',REPEAT('a',64),1,2,NOW(),NULL,NULL)", [3819,4025]);
    $db->exec($refund . "(1,1,1,1,'RF',100,'Test','paid','cash','RF-1',REPEAT('a',64),1,2,NOW(),2,NOW())");
    rejectsFeeSql($db, "INSERT INTO fee_waivers (institute_id,student_id,assessment_id,waiver_no,amount,reason,status,requested_by,decided_by,decided_at) VALUES (1,1,1,'W',100,'Test','approved',1,1,NOW())", [3819,4025]);
    rejectsFeeSql($db, "INSERT INTO fee_payment_reversals (institute_id,student_id,payment_id,reversal_no,reason,requested_by,approved_by) VALUES (1,1,1,'RV','Test',1,1)", [3819,4025]);
    $db->exec("INSERT INTO fee_cash_closings (institute_id,closing_date,opening_amount,collected_amount,refunded_amount,added_amount,deposited_amount,withdrawn_amount,actual_amount,closed_by) VALUES (1,'2026-01-01',100,500,100,50,200,25,325,2)");
    $row = $db->query('SELECT expected_amount,difference_amount FROM fee_cash_closings')->fetch(PDO::FETCH_ASSOC);
    if ($row['expected_amount'] !== '325.00' || $row['difference_amount'] !== '0.00') {
        throw new RuntimeException('Cash reconciliation calculation is incorrect.');
    }
    $checks++;
    echo "Fee SQL integration passed ($checks checks; valid workflow and cash closing inserted).\n";
} finally {
    // Only this randomly named database, created by this invocation, is removed.
    $db->exec("DROP DATABASE `$name`");
}
