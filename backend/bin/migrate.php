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
$prefix=preg_match('/^--baseline-through=([0-9]{3})$/D',$option,$matches)?(int)$matches[1]:null;
if ((!in_array($option, ['--status', '--apply','--baseline-current'], true) && $prefix===null) || count($argv) > 2) {
    fwrite(STDERR, "Usage: php bin/migrate.php [--status|--apply|--baseline-current|--baseline-through=008]\n");
    exit(2);
}
try {
    Environment::load(dirname(__DIR__) . '/.env');
    $db = (new Database(require dirname(__DIR__) . '/config/database.php'))->connection();
    if ($option==='--baseline-current' || $prefix!==null) {
        $baseline=\App\Core\MigrationBaseline::run($db,dirname(__DIR__).'/database/migrations',$prefix);
        echo 'Verified canonical baseline: '.count($baseline['baselined']).' ledger entries recorded.'.PHP_EOL;
        exit(0);
    }
    $result = MigrationRunner::run($db, dirname(__DIR__) . '/database/migrations', $option === '--apply');
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
