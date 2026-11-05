<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/migrations.php';
try {
    $ran = migrateTripLedger(db());
    echo $ran ? 'Applied: ' . implode(', ', $ran) . "\n" : "Schema is current; no changes.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed; existing data was not reset. Consult the private server log.\n");
    error_log($e->getMessage());
    exit(1);
}
