#!/usr/bin/env php
<?php
/**
 * Reclaim disk space on the lifecycle tables after a large retention purge.
 *
 * InnoDB frees deleted pages for reuse but does not return them to the OS, so a
 * table can stay physically large after rows are archived/pruned. OPTIMIZE TABLE
 * rebuilds the tablespace and returns the space. Run this occasionally, not on
 * every retention cycle: it rebuilds the table (heavy I/O, briefly locks writes).
 *
 * Prefer a low-traffic window. On MariaDB 10.4 InnoDB this is effectively an
 * online rebuild but still allocates a second copy of the table — make sure the
 * disk has roughly double the largest table's size free before running.
 *
 * Usage:
 *   php scripts/reclaim.php            # optimize the lifecycle tables
 *   php scripts/reclaim.php message_log emergency_queue
 *   php scripts/reclaim.php --dry-run  # just print sizes
 */

require_once __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli' && empty($_GET['key'])) {
    http_response_code(403);
    exit("Forbidden");
}

if (!Database::available()) {
    fwrite(STDERR, "reclaim: database unavailable.\n");
    exit(1);
}

$defaults = [
    'message_log', 'message_log_archive', 'push_log', 'push_log_archive',
    'outbox', 'validation_errors', 'emergency_queue', 'job_runs',
    'automation_runs', 'wialon_events', 'webhook_events', 'retention_log',
];
$tables = [];
$dryRun = false;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (preg_match('/^[a-z_][a-z0-9_]*$/i', $arg)) {
        $tables[] = $arg;
    }
}
if (!$tables) {
    $tables = $defaults;
}

function tableMb(string $table): float {
    $row = Database::fetch(
        "SELECT ROUND((DATA_LENGTH + INDEX_LENGTH) / 1048576, 2) AS mb
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    );
    return (float) ($row['mb'] ?? 0);
}

$totalBefore = 0.0;
$totalAfter = 0.0;
foreach ($tables as $t) {
    // Only touch tables that actually exist.
    $exists = Database::fetch(
        "SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$t]
    );
    if (!$exists) {
        echo sprintf("  %-24s (missing, skipped)\n", $t);
        continue;
    }
    $b = tableMb($t);
    $totalBefore += $b;
    if ($dryRun) {
        echo sprintf("  %-24s %8.2f MB (dry-run)\n", $t, $b);
        continue;
    }
    $start = microtime(true);
    Database::query("OPTIMIZE TABLE `$t`");
    $a = tableMb($t);
    $totalAfter += $a;
    echo sprintf("  %-24s %8.2f MB -> %8.2f MB (%+.2f) in %.1fs\n", $t, $b, $a, $a - $b, microtime(true) - $start);
}

if ($dryRun) {
    echo sprintf("reclaim: %d table(s), %.2f MB total (dry-run, nothing changed)\n", count($tables), $totalBefore);
} else {
    echo sprintf("reclaim: %d table(s), %.2f MB -> %.2f MB (freed %.2f MB)\n", count($tables), $totalBefore, $totalAfter, $totalBefore - $totalAfter);
}
