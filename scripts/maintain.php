#!/usr/bin/env php
<?php
/** Run the bounded CLI maintenance with the original environment-configured age windows. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
try {
    $removed = Retention::maintain();
    echo "maintain: removed $removed completed historical rows; unresolved work preserved.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'maintain: failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
