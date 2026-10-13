<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;

require dirname(__DIR__) . '/bootstrap/autoload.php';
if (PHP_SAPI !== 'cli' || count($argv) !== 1) {
    fwrite(STDERR, "Usage: php bin/schema-preflight.php\n");
    exit(2);
}

try {
    Environment::load(dirname(__DIR__) . '/.env');
    $db = (new Database(require dirname(__DIR__) . '/config/database.php'))->connection();
    $ddl = file_get_contents(dirname(__DIR__) . '/database/schema/iti_erp_schema.sql');
    $guards = file_get_contents(dirname(__DIR__) . '/database/schema/integrity_guards.sql');
    if ($ddl === false || $guards === false) {
        throw new RuntimeException('Canonical SQL files unreadable.');
    }
    preg_match_all('/CREATE TABLE\s+([a-z0-9_]+)\s*\(/i', $ddl, $tables);
    preg_match_all('/CREATE TRIGGER\s+([a-z0-9_]+)\s+/i', $guards, $triggers);
    $expectedTables = array_values(array_unique($tables[1]));
    $expectedTriggers = array_values(array_unique($triggers[1]));
    if ($expectedTables === [] || $expectedTriggers === []) {
        throw new RuntimeException('Could not parse canonical tables/guards.');
    }
    $presentTables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $presentTriggers = $db->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    $report = [
        'database' => (string)$db->query('SELECT DATABASE()')->fetchColumn(),
        'canonical_table_count' => count($expectedTables),
        'installed_table_count' => count($presentTables),
        'missing_tables' => array_values(array_diff($expectedTables, $presentTables)),
        'extra_tables' => array_values(array_diff($presentTables, $expectedTables)),
        'canonical_trigger_count' => count($expectedTriggers),
        'installed_trigger_count' => count($presentTriggers),
        'missing_triggers' => array_values(array_diff($expectedTriggers, $presentTriggers)),
        'extra_triggers' => array_values(array_diff($presentTriggers, $expectedTriggers)),
        'migration_ledger_present' => in_array('iti_erp_schema_migrations', $presentTables, true),
        'upgrade_safe_to_apply_without_review' => false,
    ];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    // Presence checks are not proof of exact DDL, proper grants or provenance.
    exit($report['missing_tables'] === [] && $report['missing_triggers'] === [] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Schema preflight failed: ' . $exception::class . PHP_EOL);
    exit(1);
}
