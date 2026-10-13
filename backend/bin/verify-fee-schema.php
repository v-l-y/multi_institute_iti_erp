<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;

require dirname(__DIR__) . '/bootstrap/autoload.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
try {
    Environment::load(dirname(__DIR__) . '/.env');
    $db = (new Database(require dirname(__DIR__) . '/config/database.php'))->connection();
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema/iti_erp_schema.sql');
    $module = substr($schema, strpos($schema, '-- BEGIN ADVANCED FEE MANAGEMENT v1'));
    preg_match_all('/CREATE TABLE (\w+) \(([\s\S]*?)\n\) ENGINE=InnoDB/', $module, $tables, PREG_SET_ORDER);
    $problems = [];
    foreach ($tables as $table) {
        $name = $table[1];
        $query = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$name]);
        $actualColumns = $query->fetchAll(PDO::FETCH_COLUMN);
        if (!$actualColumns) {
            $problems[] = "$name: missing table";
            continue;
        }
        preg_match_all('/^ (\w+) (?:BIGINT|INT|VARCHAR|CHAR|DATE|DATETIME|DECIMAL|ENUM|LONGTEXT|TIMESTAMP|TINYINT)\b/m', $table[2], $columns);
        foreach (array_diff($columns[1], $actualColumns) as $column) {
            $problems[] = "$name: missing column $column";
        }
        $query = $db->prepare('SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION');
        $query->execute([$name]);
        $groups = [];
        foreach ($query as $row) {
            $key = $row['CONSTRAINT_NAME'];
            $groups[$key]['columns'][] = $row['COLUMN_NAME'];
            $groups[$key]['table'] = $row['REFERENCED_TABLE_NAME'];
            $groups[$key]['references'][] = $row['REFERENCED_COLUMN_NAME'];
        }
        $actual = array_map(fn(array $g): string => implode(',', $g['columns']) . '->' . $g['table'] . '(' . implode(',', $g['references']) . ')', $groups);
        preg_match_all('/FOREIGN KEY\(([^)]+)\) REFERENCES (\w+)\(([^)]+)\)/', $table[2], $keys, PREG_SET_ORDER);
        foreach ($keys as $key) {
            $expected = "$key[1]->$key[2]($key[3])";
            if (!in_array($expected, $actual, true)) {
                $problems[] = "$name: missing foreign key $expected";
            }
        }
    }
    if (count($tables) !== 13) {
        throw new RuntimeException('Canonical fee module is incomplete.');
    }
    if ($problems) {
        echo "Fee schema verification FAILED (read-only):\n", implode("\n", $problems), "\n";
        exit(1);
    }
    echo "Fee schema verification passed: 13 tables, expected columns and composite foreign keys present. No data changed.\n";
    echo "This does not verify CHECK/UNIQUE definitions, existing financial data or transactional business rules.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Verification could not complete: " . get_class($exception) . ". Check database connectivity and schema configuration.\n");
    exit(1);
}
