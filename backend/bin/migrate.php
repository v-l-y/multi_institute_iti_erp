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
if (!in_array($option, ['--status', '--apply'], true) || count($argv) > 2) {
    fwrite(STDERR, "Usage: php bin/migrate.php [--status|--apply]\n");
    exit(2);
}
try {
    Environment::load(dirname(__DIR__) . '/.env');
    $db = (new Database(require dirname(__DIR__) . '/config/database.php'))->connection();
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
