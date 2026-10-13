<?php
declare(strict_types=1);

// Export only. This command never connects to or changes a database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$schema = file_get_contents(dirname(__DIR__) . '/database/schema/iti_erp_schema.sql');
$start = '-- BEGIN ADVANCED FEE MANAGEMENT v1';
$end = '-- END ADVANCED FEE MANAGEMENT v1';
$offset = is_string($schema) ? strpos($schema, $start) : false;
$endOffset = is_string($schema) ? strpos($schema, $end) : false;
if ($offset === false || $endOffset === false || $endOffset <= $offset) {
    fwrite(STDERR, "Fee schema module is missing or incomplete.\n");
    exit(1);
}
echo substr($schema, $offset, $endOffset + strlen($end) - $offset), "\n";
