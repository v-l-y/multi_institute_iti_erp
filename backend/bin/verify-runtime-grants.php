<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;

require dirname(__DIR__) . '/bootstrap/autoload.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
try {
    Environment::load(dirname(__DIR__) . '/.env');
    $config = require dirname(__DIR__) . '/config/database.php';
    $db = (new Database($config))->connection();
    $grants = $db->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    $allowed = ['SELECT','INSERT','UPDATE','DELETE'];
    $actual = [];
    foreach ($grants as $grant) {
        // Never print raw grants: MariaDB may include an authentication hash.
        if (!preg_match('/^GRANT (.*?) ON (.*?) TO /', $grant, $parts)) {
            throw new RuntimeException('Unsupported grant form.');
        }
        if ($parts[1] === 'USAGE' && $parts[2] === '*.*' && !str_contains($grant, 'WITH GRANT OPTION')) {
            continue;
        }
        // Database-level GRANT treats _ and % as wildcards, even in identifiers.
        $databasePattern = str_replace(['\\','_','%','`'], ['\\\\','\\_','\\%','``'], $config['database']);
        $scope = '`' . $databasePattern . '`.*';
        if ($parts[2] !== $scope || str_contains($grant, 'WITH GRANT OPTION')) {
            throw new RuntimeException('Runtime account has privileges outside the application data scope.');
        }
        foreach (explode(', ', $parts[1]) as $privilege) {
            if (!in_array($privilege, $allowed, true)) {
                throw new RuntimeException('Runtime account has administrative privileges.');
            }
            $actual[$privilege] = true;
        }
    }
    if (array_diff($allowed, array_keys($actual)) !== []) {
        throw new RuntimeException('Runtime account lacks required data privileges.');
    }
    echo "Runtime grants passed: application data access only; no DDL or grant authority.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Runtime grants verification failed: ' . $error::class . ". Use a dedicated restricted application identity.\n");
    exit(1);
}
