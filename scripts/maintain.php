#!/usr/bin/env php
<?php
/**
 * Database retention. Safe to run daily from cron (also invoked by cleanup.sh).
 *
 * All deletes are delegated to Retention::runAll(), which chunks every purge
 * (`DELETE ... LIMIT`) under a wall-clock budget, so this script can never open
 * a giant transaction or hold a long row lock — even on a multi-million row
 * message_log. Steps are independent: one failure never blocks the rest.
 *
 * Rules:
 *   - Streaming data (fetched from Wialon and posted to LATRA) is pruned after
 *     MAINTAIN_STREAM_DAYS (default 14). Constant/reference data is never touched.
 *   - Rows still queued for LATRA delivery are never deleted (the outbox
 *     references them), so retry-queue integrity is preserved.
 *   - Operational history has its own windows (all overridable via env):
 *       MAINTAIN_STREAM_DAYS   message_log(sent)/stale, push_log, wialon_events,
 *                              outbox(sent/rejected), webhook_events, rate_limits (14)
 *       MAINTAIN_REJECTED_DAYS message_log(rejected), validation_errors (30)
 *       MAINTAIN_RUN_DAYS      automation_runs + job_runs (60)
 *       MAINTAIN_AUDIT_DAYS    audit_log (365)
 *       MAINTAIN_AI_DAYS       ai_conversations + ai_chat_log (180)
 *
 * Usage:
 *   php scripts/maintain.php            # apply retention
 *   php scripts/maintain.php --dry-run  # report what would be deleted
 */

require_once __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli' && empty($_GET['key'])) {
    http_response_code(403);
    exit("Forbidden");
}

if (!Database::available()) {
    fwrite(STDERR, "maintain: database unavailable; skipping DB retention.\n");
    exit(0);
}

$dryRun = (PHP_SAPI === 'cli' && in_array('--dry-run', $argv ?? [], true))
    || envValue('MAINTAIN_DRY_RUN', '0') === '1'
    || (!empty($_GET['dry_run']) && $_GET['dry_run'] === '1');

$streamDays = max(1, (int) envValue('MAINTAIN_STREAM_DAYS', '14'));
$runDays    = max(1, (int) envValue('MAINTAIN_RUN_DAYS', '60'));
$auditDays  = max(1, (int) envValue('MAINTAIN_AUDIT_DAYS', '365'));
$aiDays     = max(1, (int) envValue('MAINTAIN_AI_DAYS', '180'));
$rejDays    = max(1, (int) envValue('MAINTAIN_REJECTED_DAYS', '30'));
$valDays    = max(1, (int) envValue('MAINTAIN_VALIDATION_DAYS', (string) $rejDays));

$overrides = [
    // Maintain keeps its historic "stream window" semantics, but now applies it
    // through the chunked engine (and also to non-deliverable rows).
    'delivered_hours'    => $streamDays * 24,
    'push_log_days'      => $streamDays,
    'wialon_events_days' => $streamDays,
    'webhook_days'       => $streamDays,
    'rate_limit_days'    => $streamDays,
    'stale_pending_days' => $streamDays,
    'rejected_days'      => $rejDays,
    'validation_days'    => $valDays,
    'job_run_days'       => $runDays,
    'automation_days'    => $runDays,
    'audit_days'         => $auditDays,
    'ai_days'            => $aiDays,
    'stream_days'        => $streamDays,
    'run_days'           => $runDays,
    'dry_run'            => $dryRun,
];

$summary = Retention::runAll($overrides);

$lines = [];
$total = 0;
foreach ($summary as $step) {
    $name = $step['step'] ?? '?';
    $rows = (int) ($step['rows'] ?? 0);
    $total += $rows;
    $note = ($step['reason'] ?? '') === 'time_budget' ? ' (budget reached — will resume next run)' : '';
    if (isset($step['error'])) {
        $note = ' ERROR: ' . $step['error'];
    }
    $lines[] = sprintf('  %-28s %8d%s', $name, $rows, $note);
}

echo ($dryRun ? "[dry-run] " : '') . 'maintain: ' . $total . ' rows' . PHP_EOL;
echo implode(PHP_EOL, $lines) . PHP_EOL;
