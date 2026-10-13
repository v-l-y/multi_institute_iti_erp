<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Explicit, forward-only upgrades of an already installed ERP schema.
 * One SQL statement per numbered file. MySQL DDL is not transactional.
 */
final class MigrationRunner
{
    public static function discover(string $directory): array
    {
        $paths = glob(rtrim($directory, '/') . '/*.sql');
        if ($paths === false) {
            throw new RuntimeException('Cannot enumerate migration directory.');
        }
        sort($paths, SORT_STRING);
        $files = [];
        $numbers = [];
        foreach ($paths as $path) {
            $filename = basename($path);
            if (preg_match('/^([0-9]{3})_[a-z0-9_]+\.sql$/D', $filename, $matches) !== 1) {
                throw new RuntimeException('Invalid migration file name: ' . $filename);
            }
            if (isset($numbers[$matches[1]])) {
                throw new RuntimeException('Duplicate migration number: ' . $matches[1]);
            }
            $numbers[$matches[1]] = true;
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException('Cannot read migration: ' . $filename);
            }
            self::singleStatement($sql);
            $files[$filename] = ['sql' => $sql, 'sha256' => hash('sha256', $sql)];
        }
        // Numbered forward upgrades must start at 001 with no silent gaps.
        $expectedNumber = 1;
        foreach (array_keys($files) as $filename) {
            if ((int)substr($filename, 0, 3) !== $expectedNumber) {
                throw new RuntimeException('Non-contiguous migration sequence; expected '
                    . str_pad((string)$expectedNumber, 3, '0', STR_PAD_LEFT) . ' before ' . $filename);
            }
            $expectedNumber++;
        }
        return $files;
    }

    /** Reject batches or procedure delimiters: a failed DDL must be reviewed individually. */
    public static function singleStatement(string $sql): string
    {
        $withoutLeadingComments = preg_replace('/\A(?:\s+|--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$))*/', '', $sql);
        if ($withoutLeadingComments === null) {
            throw new RuntimeException('Invalid migration SQL.');
        }
        $statement = trim($withoutLeadingComments);
        if ($statement === '' || preg_match('/\bDELIMITER\b/i', $statement) === 1) {
            throw new RuntimeException('Migration must contain one supported SQL statement.');
        }
        if (str_ends_with($statement, ';')) {
            $statement = rtrim(substr($statement, 0, -1));
        }
        // Narrow supported compound statement: one conditional immutable-record
        // trigger. Anchor the whole grammar so no trailing SQL batch is accepted.
        if (preg_match("/\\ACREATE TRIGGER [a-z0-9_]+ BEFORE (?:UPDATE|DELETE) ON [a-z0-9_]+ FOR EACH ROW BEGIN IF OLD\\.status='[a-z_]+' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='[A-Za-z -]+'; END IF; END\\z/D", $statement)===1) {
            return $statement;
        }
        if ($statement === '' || str_contains($statement, ';')) {
            throw new RuntimeException('Each migration file must contain exactly one SQL statement.');
        }
        return $statement;
    }

    public static function run(PDO $db, string $directory, bool $apply): array
    {
        $schema = (string) $db->query('SELECT DATABASE()')->fetchColumn();
        if ($schema === '') {
            throw new RuntimeException('A selected database is required.');
        }
        $table = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='institute'");
        $table->execute();
        if ((int) $table->fetchColumn() !== 1) {
            throw new RuntimeException('Existing canonical schema is required. This runner never installs a fresh database.');
        }
        $lockName = 'iti_erp_migrate_' . substr(hash('sha256', $schema), 0, 32);
        if ($apply) {
            $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
            $lock->execute([$lockName]);
            if ((int) $lock->fetchColumn() !== 1) {
                throw new RuntimeException('Could not acquire migration lock.');
            }
        }

        try {
            $migrations = self::discover($directory);
            if ($apply && $migrations === []) {
                throw new RuntimeException('No reviewed numbered migration files are available. Populate and rehearse migrations before --apply.');
            }
            $ledgerCheck = $db->query("SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='iti_erp_schema_migrations'");
            $ledgerExists = (int) $ledgerCheck->fetchColumn() === 1;
            $applied = [];
            if ($ledgerExists) {
                foreach ($db->query('SELECT filename, checksum_sha256 FROM iti_erp_schema_migrations') as $row) {
                    $applied[$row['filename']] = $row['checksum_sha256'];
                }
            }
            foreach ($applied as $filename => $checksum) {
                if (!isset($migrations[$filename]) || !hash_equals($checksum, $migrations[$filename]['sha256'])) {
                    throw new RuntimeException('Migration checksum/history mismatch: ' . $filename);
                }
            }
            $pending = array_diff(array_keys($migrations), array_keys($applied));
            if (!$apply || $pending === []) {
                return ['applied' => [], 'pending' => array_values($pending)];
            }
            // Create the ledger only for an explicit, validated apply operation.
            if (!$ledgerExists) {
                $db->exec("CREATE TABLE iti_erp_schema_migrations (
                    filename VARCHAR(255) NOT NULL PRIMARY KEY,
                    checksum_sha256 CHAR(64) NOT NULL,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
            $completed = [];
            foreach ($pending as $filename) {
                // DDL may implicitly commit. Do not retry a failed migration automatically.
                $db->exec(self::singleStatement($migrations[$filename]['sql']));
                $record = $db->prepare('INSERT INTO iti_erp_schema_migrations (filename, checksum_sha256) VALUES (?, ?)');
                $record->execute([$filename, $migrations[$filename]['sha256']]);
                $completed[] = $filename;
            }
            return ['applied' => $completed, 'pending' => []];
        } finally {
            if ($apply) {
                $release = $db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            }
        }
    }
}
