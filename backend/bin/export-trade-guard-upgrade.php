<?php
declare(strict_types=1);

// Read-only export: review and apply to an existing database separately.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$guards = file_get_contents(dirname(__DIR__) . '/database/schema/integrity_guards.sql');
if ($guards === false || !preg_match('/CREATE TRIGGER trade_master_revisions_content_update\b[\s\S]*?END\$\$/', $guards, $match)) {
    fwrite(STDERR, "Canonical trade revision guard not found.\n");
    exit(1);
}
echo "-- Back up first. Review before applying; trigger DDL auto-commits.\n",
    "-- Run with writes paused; do not apply the entire create-only schema.\n",
    "DROP TRIGGER IF EXISTS trade_master_revisions_content_update;\n",
    "DELIMITER $$\n", $match[0], "\nDELIMITER ;\n";
