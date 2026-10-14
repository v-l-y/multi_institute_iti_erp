<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;
use App\Core\MigrationRunner;

require dirname(__DIR__) . '/bootstrap/autoload.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$option = $argv[1] ?? '--status';
if (!in_array($option, ['--status', '--apply','--baseline-current'], true) || count($argv) > 2) {
    fwrite(STDERR, "Usage: php bin/migrate.php [--status|--apply|--baseline-current]\n");
    exit(2);
}
try {
    Environment::load(dirname(__DIR__) . '/.env');
    $db = (new Database(require dirname(__DIR__) . '/config/database.php'))->connection();
    if ($option==='--baseline-current') {
        $baseline=\App\Core\MigrationBaseline::run($db,dirname(__DIR__).'/database/migrations');
        echo 'Verified canonical baseline: '.count($baseline['baselined']).' ledger entries recorded.'.PHP_EOL;
        exit(0);
    }
    $result = MigrationRunner::run($db, dirname(__DIR__) . '/database/migrations', $option === '--apply');
    $ledgerExists=(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='iti_erp_schema_migrations'")->fetchColumn()===1;
    $registered=$ledgerExists && (int)$db->query("SELECT COUNT(*) FROM iti_erp_schema_migrations WHERE filename='000_canonical_schema'")->fetchColumn()===1;
    echo $registered ? "Baseline 000 registered.\n" : "Baseline 000 not registered; --baseline-current verifies a canonical install.\n";
    if ($result['applied'] !== []) {
        echo 'Applied: ' . implode(', ', $result['applied']) . PHP_EOL;
    }
    if ($result['pending'] !== []) {
        echo 'Pending (requires --apply): ' . implode(', ', $result['pending']) . PHP_EOL;
    } else {
        echo "No pending migrations.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration stopped: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
