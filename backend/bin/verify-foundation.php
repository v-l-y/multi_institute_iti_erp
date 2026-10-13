<?php
declare(strict_types=1);
use App\Core\Database;
use App\Core\Environment;
require dirname(__DIR__).'/bootstrap/autoload.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit(1); }
try {
    Environment::load(dirname(__DIR__).'/.env');
    $db=(new Database(require dirname(__DIR__).'/config/database.php'))->connection();
    $problems=[];
    foreach (['dst_agreements','dst_agreement_units','institute_activity_log'] as $table) {
        $q=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $q->execute([$table]);
        if (!(int)$q->fetchColumn()) $problems[]='Missing table: '.$table;
    }
    foreach (['students'=>['is_ews'],'enrollments'=>['admission_route','admission_route_source_id','admission_route_evidence_reference'],'cts_training_calendars'=>['upload_profile_json'],'fee_heads'=>['requires_enrollment'],'exam_eligibility'=>['minimum_attendance_as_applied','minimum_formative_as_applied','relaxation_cap_as_applied']] as $table=>$columns) {
        foreach ($columns as $column) {
            $q=$db->prepare('SELECT IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $q->execute([$table,$column]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!$row) $problems[]='Missing column: '.$table.'.'.$column;
            elseif ($table==='students' && ($row['IS_NULLABLE']!=='YES' || !in_array($row['COLUMN_DEFAULT'],[null,'NULL'],true))) $problems[]='EWS must permit an unknown NULL value without a false default.';
        }
    }
    $guards=file_get_contents(dirname(__DIR__).'/database/schema/integrity_guards.sql');
    preg_match_all('/CREATE TRIGGER (\w+)/',$guards,$expected);
    $actual=$db->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_diff($expected[1],$actual) as $trigger) $problems[]='Missing trigger: '.$trigger;
    if ($problems) { echo "Foundation verification FAILED (read-only):\n",implode("\n",$problems),"\n"; exit(1); }
    echo 'Foundation objects present: three new tables, updated columns and '.count($expected[1])." integrity triggers. No data changed.\n";
    echo "Presence checks do not certify stored financial data or exact CHECK/trigger definitions. Run the disposable integration suite as well.\n";
} catch (Throwable $e) { fwrite(STDERR,'Read-only verification could not complete: '.get_class($e)."\n"); exit(1); }
