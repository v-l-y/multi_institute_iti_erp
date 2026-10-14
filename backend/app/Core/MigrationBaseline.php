<?php
declare(strict_types=1);
namespace App\Core;
use PDO;
use RuntimeException;

/** Explicit baseline only after full DDL comparison with a disposable canonical install. */
final class MigrationBaseline
{
    private static function quote(string $name): string { return '`'.str_replace('`','``',$name).'`'; }
    private static function snapshot(PDO $db): array
    {
        $tables=[];
        foreach($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if ($table==='iti_erp_schema_migrations') continue;
            $row=$db->query('SHOW CREATE TABLE '.self::quote($table))->fetch(PDO::FETCH_NUM);
            $tables[$table]=preg_replace('/ AUTO_INCREMENT=\d+/','',$row[1]);
        }
        $triggers=[];
        foreach($db->query('SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME') as $row) {
            $body=trim($row['ACTION_STATEMENT']);
            // Single-SIGNAL migrations and BEGIN/SIGNAL/END canonical guards
            // implement the same one statement. Ignore that wrapper only.
            if (preg_match('/\ABEGIN\s+(SIGNAL[\s\S]+);\s*END\z/i',$body,$m)) $body=$m[1];
            $body=preg_replace('/\s+/',' ',rtrim($body,';'));
            $triggers[$row['TRIGGER_NAME']]=[$row['EVENT_MANIPULATION'],$row['EVENT_OBJECT_TABLE'],$row['ACTION_TIMING'],$body];
        }
        return ['tables'=>$tables,'triggers'=>$triggers];
    }
    public static function run(PDO $db,string $directory): array
    {
        if ($db->inTransaction()) throw new RuntimeException('Baseline requires its own connection outside a transaction.');
        $schema=(string)$db->query('SELECT DATABASE()')->fetchColumn();
        if ($schema==='') throw new RuntimeException('Selected database required.');
        $lock='iti_erp_migrate_'.substr(hash('sha256',$schema),0,32);
        $q=$db->prepare('SELECT GET_LOCK(?,10)'); $q->execute([$lock]);
        if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Migration lock unavailable.');
        $reference='iti_baseline_'.bin2hex(random_bytes(8)); $created=false;
        try {
            $migrations=MigrationRunner::discover($directory);
            if ($migrations) throw new RuntimeException('Baseline registration is only supported before forward migrations are introduced.');
            $baselineFiles=[MigrationRunner::BASELINE];
            $actual=self::snapshot($db);
            $q=$db->prepare('SELECT DEFAULT_CHARACTER_SET_NAME,DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?'); $q->execute([$schema]); $defaults=$q->fetch(PDO::FETCH_NUM);
            if (!$defaults || !preg_match('/^[a-z0-9_]+$/D',$defaults[0]) || !preg_match('/^[a-z0-9_]+$/D',$defaults[1])) throw new RuntimeException('Cannot verify database defaults.');
            $db->exec('CREATE DATABASE '.self::quote($reference).' CHARACTER SET '.$defaults[0].' COLLATE '.$defaults[1]); $created=true;
            $db->exec('USE '.self::quote($reference)); SchemaInstaller::install($db);
            $expected=self::snapshot($db);
            $db->exec('USE '.self::quote($schema)); $db->exec("SET time_zone='+00:00'");
            if ($actual!==$expected) {
                $differences=[];
                foreach(['tables','triggers'] as $kind) foreach(array_unique([...array_keys($actual[$kind]),...array_keys($expected[$kind])]) as $object) {
                    if (($actual[$kind][$object]??null)!==($expected[$kind][$object]??null)) $differences[]=$kind.'.'.$object;
                }
                throw new RuntimeException('Canonical DDL mismatch; baseline refused: '.implode(', ',$differences));
            }
            // Verify any existing ledger history before writing. Do not pretend
            // mismatched or partially applied history is a fresh installation.
            MigrationRunner::run($db,$directory,false);
            $ledgerExists=(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='iti_erp_schema_migrations'")->fetchColumn()===1;
            $pending=$ledgerExists && (int)$db->query("SELECT COUNT(*) FROM iti_erp_schema_migrations WHERE filename='000_canonical_schema'")->fetchColumn()===1 ? [] : $baselineFiles;
            if (!$pending) return ['baselined'=>[]];
            $db->exec('CREATE TABLE IF NOT EXISTS iti_erp_schema_migrations (filename VARCHAR(255) NOT NULL PRIMARY KEY,checksum_sha256 CHAR(64) NOT NULL,applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $db->beginTransaction();
            $record=$db->prepare('INSERT INTO iti_erp_schema_migrations(filename,checksum_sha256) VALUES (?,?)');
            foreach($pending as $file) $record->execute([$file,MigrationRunner::baselineChecksum()]);
            $db->commit();
            return ['baselined'=>$pending];
        } finally {
            if ($db->inTransaction()) $db->rollBack();
            $db->exec('USE '.self::quote($schema)); $db->exec("SET time_zone='+00:00'");
            if ($created) $db->exec('DROP DATABASE '.self::quote($reference));
            $q=$db->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]);
        }
    }
}
