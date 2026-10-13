<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;

require dirname(__DIR__) . '/bootstrap/autoload.php';
if (PHP_SAPI!=='cli') {
    http_response_code(404);
    exit(1);
}
// Read-only preflight and SQL export. Never applies changes to the configured database.
try {
    Environment::load(dirname(__DIR__).'/.env');
    $db=(new Database(require dirname(__DIR__).'/config/database.php'))->connection();
    $q=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('dst_agreements','dst_agreement_units','institute_activity_log')");
    if ((int)$q->fetchColumn()!==0) throw new RuntimeException('Foundation tables already exist; do not retry or apply a partial upgrade blindly.');
    if ((int)$db->query('SELECT COUNT(*) FROM exam_eligibility')->fetchColumn()!==0) throw new RuntimeException('Existing exam eligibility needs verified historical threshold backfill before this upgrade. No values will be guessed.');
    if ((int)$db->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn()!==0) throw new RuntimeException('Existing database triggers need a compatibility review before this upgrade.');
    $schema=file_get_contents(dirname(__DIR__).'/database/schema/iti_erp_schema.sql');
    $table=static function (string $name) use ($schema): string {
        if (!preg_match('/CREATE TABLE '.preg_quote($name,'/').'\s*\([\s\S]*?\n\) ENGINE=InnoDB[^;]*;/',$schema,$match)) throw new RuntimeException('Missing canonical table '.$name);
        return $match[0];
    };
    $column=static function (string $name,string $field) use ($table): string {
        if (!preg_match('/^ '.preg_quote($field,'/').' [^\r\n]+/m',$table($name),$match)) throw new RuntimeException('Missing canonical column '.$field);
        return rtrim(trim($match[0]),',');
    };
    $statements=[$table('dst_agreements'),$table('dst_agreement_units'),$table('institute_activity_log')];
    $statements[]='ALTER TABLE students MODIFY COLUMN '.$column('students','is_ews').';';
    $statements[]='ALTER TABLE fee_heads ADD COLUMN '.$column('fee_heads','requires_enrollment').', ADD CHECK(requires_enrollment IN (0,1));';
    $statements[]='ALTER TABLE cts_training_calendars ADD COLUMN '.$column('cts_training_calendars','upload_profile_json').';';
    $statements[]='ALTER TABLE enrollments ADD COLUMN '.$column('enrollments','admission_route').', ADD COLUMN '.$column('enrollments','admission_route_source_id').', ADD COLUMN '.$column('enrollments','admission_route_evidence_reference').', ADD FOREIGN KEY(admission_route_source_id,institute_id) REFERENCES regulatory_sources(id,institute_id), ADD CHECK(admission_route=\'unverified\' OR (admission_route_source_id IS NOT NULL AND admission_route_evidence_reference IS NOT NULL AND CHAR_LENGTH(TRIM(admission_route_evidence_reference))>0));';
    $columns=[];
    foreach (['minimum_attendance_as_applied','minimum_formative_as_applied','relaxation_cap_as_applied'] as $field) $columns[]='ADD COLUMN '.$column('exam_eligibility',$field);
    preg_match_all('/^ CHECK\([^\r\n]+/m',$table('exam_eligibility'),$checks);
    foreach (array_slice($checks[0],-5) as $check) $columns[]='ADD '.rtrim(trim($check),',');
    $statements[]='ALTER TABLE exam_eligibility '.implode(', ',$columns).';';
    // Emit only after all preflight/extraction steps succeed, so a failed exporter produces no partial SQL.
    echo "-- Foundation upgrade: backup first; DDL auto-commits; apply once.\n",implode("\n\n",$statements),"\n\n",file_get_contents(dirname(__DIR__).'/database/schema/integrity_guards.sql');
} catch (Throwable $exception) {
    fwrite(STDERR,'Upgrade export refused: '.($exception instanceof RuntimeException && !$exception instanceof PDOException ? $exception->getMessage() : get_class($exception))."\n");
    exit(1);
}
