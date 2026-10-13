<?php
declare(strict_types=1);
namespace App\Core;

use PDO;

/** Fresh, disposable databases only. Existing application databases require reviewed migrations. */
final class SchemaInstaller
{
    public static function install(PDO $db): void
    {
        $root=dirname(__DIR__,2).'/database/schema/';
        $guards=file_get_contents($root.'integrity_guards.sql');
        if (preg_match_all('/^END\$\$\s*$/m',$guards)!==preg_match_all('/CREATE TRIGGER /',$guards)) {
            throw new \RuntimeException('Integrity module has incomplete trigger delimiters.');
        }
        $db->exec(file_get_contents($root.'iti_erp_schema.sql'));
        $guards=preg_replace('/^DELIMITER\s+.*$/m','',$guards);
        foreach (explode('$$',$guards) as $statement) {
            if (trim($statement)!=='') {
                $db->exec($statement);
            }
        }
    }
}
