<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Layout.php';
Auth::requireView('dashboard.view');

function healthRow(string $label, $value, string $state = 'ok'): string {
    $icon = $state === 'ok' ? 'circle-check' : ($state === 'warn' ? 'triangle-alert' : 'circle-x');
    $iconStyle = $state === 'ok' ? 'var(--success)' : ($state === 'warn' ? 'var(--warning)' : 'var(--danger)');
    return '<tr><td>' . htmlspecialchars($label) . '</td><td>' . $value . '</td><td><i data-lucide="' . $icon . '" style="color:' . $iconStyle . '"></i></td></tr>';
}

$dbOk = false; $dbVersion = '-';
try {
    $dbVersion = Database::fetch('SELECT VERSION() AS v')['v'] ?? '-';
    $dbOk = true;
} catch (Throwable $e) {
}

$lastTick = @file_get_contents(LOGS_DIR . '/last_tick.txt');
$tickState = 'warn';
$tickAge = 'never';
if ($lastTick) {
    $tickAge = number_format((max(0, time() - strtotime($lastTick))) / 60, 1) . ' min ago';
    $tickState = (time() - strtotime($lastTick)) <= 300 ? 'ok' : 'warn';
}

$counts = Database::fetch(
    "SELECT SUM(latra_status = 'pending') AS pending, SUM(latra_status = 'failed') AS failed,
            SUM(latra_status = 'manual') AS manual, SUM(latra_status = 'sent') AS sent,
            SUM(latra_status = 'pending' AND next_retry_at IS NOT NULL AND next_retry_at > NOW()) AS waiting
     FROM message_log"
);
$buckets = Database::fetchAll("SELECT id, bucket_name, is_enabled, run_on_demand, consecutive_failures FROM automation_buckets ORDER BY id");
$runs = Database::fetch("SELECT COUNT(*) AS c, MAX(started_at) AS last FROM automation_runs");
$lastRun = Database::fetch("SELECT bucket_id, status, success_count, failed_count, manual_count, started_at FROM automation_runs ORDER BY id DESC LIMIT 1");

$free = disk_free_space(__DIR__) ?: 0;
$total = disk_total_space(__DIR__) ?: 1;
$diskPct = round((1 - $free / $total) * 100, 1);

Layout::renderHead('System Health');
?>
<div class="page-header"><h1><i data-lucide="heart-pulse"></i> System Health</h1><p class="text-muted">Runtime, connectivity and scheduler status — no operational changes made here.</p></div>

<div class="dashboard-grid">
    <div class="card"><div class="card-header"><h3><i data-lucide="server"></i> Runtime</h3></div><div class="table-responsive"><table class="data-table">
        <thead><tr><th>Check</th><th>Value</th><th></th></tr></thead><tbody>
        <?= healthRow('Environment', '<code>' . APP_ENV . '</code>', APP_ENV === 'production' ? 'ok' : 'warn') ?>
        <?= healthRow('Error display (production)', APP_ENV === 'production' ? '<code>off</code>' : '<code>on</code>', APP_ENV === 'production' ? 'ok' : 'warn') ?>
        <?= healthRow('HTTPS enforcement', FORCE_HTTPS ? '<code>on</code> (redirect http→https)' : '<code>off</code> — enable when served behind TLS', FORCE_HTTPS ? 'ok' : ((($_SERVER['HTTPS'] ?? '') !== '') ? 'ok' : 'warn')) ?>
        <?= healthRow('PHP version', PHP_VERSION, PHP_VERSION_ID >= 80000 ? 'ok' : 'warn') ?>
        <?php
        require_once __DIR__ . '/includes/OpenAIClient.php';
        $ai = new OpenAIClient();
        $aiOn = $ai->isConfigured();
        $aiModel = $ai->model();
        $aiVal = $aiOn ? '<code>' . htmlspecialchars($aiModel) . '</code> · rate ' . (int) envValue('AI_RATE_LIMIT', '20') . '/min' : '<em>not configured</em> (set OPENAI_API_KEY)';
        echo healthRow('AI Assistant service', $aiVal, $aiOn ? 'ok' : 'warn');
        ?>
        <?= healthRow('Outbound TLS verification (Wialon/LATRA)', '<code>VERIFYPEER=1, VERIFYHOST=2</code>', 'ok') ?>
        </tbody></table></div></div>

    <div class="card"><div class="card-header"><h3><i data-lucide="database"></i> Database &amp; Scheduler</h3></div><div class="table-responsive"><table class="data-table">
        <thead><tr><th>Check</th><th>Value</th><th></th></tr></thead><tbody>
        <?= healthRow('Database connection', $dbOk ? '<code>' . htmlspecialchars($dbVersion) . '</code>' : 'FAILED', $dbOk ? 'ok' : 'bad') ?>
        <?= healthRow('Scheduler heartbeat (last_tick.txt)', (string) $tickAge, $tickState) ?>
        <?= healthRow('Message queue', 'pending ' . (int) $counts['pending'] . ' / failed ' . (int) $counts['failed'] . ' / manual ' . (int) $counts['manual'] . ' / sent ' . (int) $counts['sent'], (int) $counts['failed'] === 0 ? 'ok' : 'warn') ?>
        <?= healthRow('Retry backoff waiting', (int) $counts['waiting'] . ' messages', 'ok') ?>
        <?= healthRow('Scheduler runs', (int) $runs['c'] . ' total, last at ' . htmlspecialchars(toDisplay((string) ($runs['last'] ?? '-'))), 'ok') ?>
        <?= healthRow('Last run result', $lastRun ? ('bucket ' . (int) $lastRun['bucket_id'] . ' → ' . htmlspecialchars($lastRun['status']) . ' (sent ' . (int) $lastRun['success_count'] . ', failed ' . (int) $lastRun['failed_count'] . ', manual ' . (int) $lastRun['manual_count'] . ')') : 'none yet', ($lastRun['status'] ?? 'ok') === 'failed' ? 'bad' : 'ok') ?>
        </tbody></table></div></div>
</div>

<div class="card"><div class="card-header"><h3><i data-lucide="layers"></i> Automation Buckets</h3></div><div class="table-responsive"><table class="data-table">
    <thead><tr><th>ID</th><th>Name</th><th>State</th><th>Manual run queued</th><th>Consecutive failures</th><th></th></tr></thead><tbody>
    <?php foreach ($buckets as $b): ?>
        <tr><td><?= (int) $b['id'] ?></td><td><?= htmlspecialchars($b['bucket_name']) ?></td>
        <td><span class="badge <?= $b['is_enabled'] ? 'badge-success' : 'badge-secondary' ?>"><?= $b['is_enabled'] ? 'Active' : 'Paused' ?></span></td>
        <td><?= $b['run_on_demand'] ? 'Yes' : 'No' ?></td>
        <td><?= (int) $b['consecutive_failures'] ?></td>
        <td><?= $b['consecutive_failures'] >= 3 ? '<span class="badge badge-danger">Diagnosis needed</span>' : '' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$buckets): ?><tr><td colspan="6" class="empty-state"><i data-lucide="inbox"></i><p>No buckets configured</p></td></tr><?php endif; ?>
    </tbody></table></div></div>

<div class="card"><div class="card-header"><h3><i data-lucide="hard-drive"></i> Storage</h3></div><div class="table-responsive"><table class="data-table">
    <thead><tr><th>Check</th><th>Value</th><th></th></tr></thead><tbody>
    <?= healthRow('Disk usage', $diskPct . '%', $diskPct > 90 ? 'bad' : ($diskPct > 75 ? 'warn' : 'ok')) ?>
    <?= healthRow('Free space', number_format($free / (1024 ** 3), 1) . ' GB', 'ok') ?>
    </tbody></table></div></div>

<div class="card"><div class="card-header"><h3><i data-lucide="archive"></i> Data Lifecycle</h3></div><div class="table-responsive"><table class="data-table">
    <thead><tr><th>Check</th><th>Value</th><th></th></tr></thead><tbody>
    <?php
    try {
        $bgDepth = BackgroundTasks::depth();
        $activeEmergency = (int) (Database::fetch("SELECT COUNT(*) AS c FROM emergency_events WHERE status = 'active'")['c'] ?? 0);
        $eqDepth = (int) (Database::fetch("SELECT COUNT(*) AS c FROM emergency_queue")['c'] ?? 0);
        $lastRet = Database::fetch("SELECT * FROM retention_log WHERE dry_run = 0 ORDER BY id DESC LIMIT 1");
        $big = Database::fetchAll("SELECT TABLE_NAME, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1048576, 1) AS mb
                                     FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                                    ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC LIMIT 5");
        echo healthRow('Background task queue', 'pending ' . $bgDepth['pending'] . ' / running ' . $bgDepth['running'] . ' / failed ' . $bgDepth['failed'], $bgDepth['failed'] > 0 ? 'warn' : 'ok');
        echo healthRow('Active emergency events', $activeEmergency . ' active', $activeEmergency > 0 ? 'warn' : 'ok');
        echo healthRow('Emergency buffer rows', number_format($eqDepth), $eqDepth > 5000 ? 'warn' : 'ok');
        echo healthRow('Retention windows',
            'hot ' . Schema::configInt('retention_days', 90) . 'd · delivered ' . Schema::configInt('delivered_retention_hours', 24) . 'h · stale ' . Schema::configInt('stale_pending_retention_days', 7) . 'd · emergency ' . Schema::configInt('emergency_retention_days', 7) . 'd · archive ' . Schema::configInt('archive_days', 180) . 'd',
            'ok');
        echo healthRow('Last retention run',
            $lastRet
                ? htmlspecialchars(toDisplay((string) $lastRet['ran_at'])) . ' · ' . number_format((int) $lastRet['rows_deleted']) . ' rows in ' . (int) $lastRet['duration_ms'] . 'ms' . ($lastRet['dry_run'] ? ' <span class="badge badge-secondary">dry-run</span>' : '')
                : 'never',
            $lastRet ? 'ok' : 'warn');
        $sizes = [];
        foreach ($big as $t) {
            $sizes[] = htmlspecialchars($t['TABLE_NAME']) . ' <span class="text-muted">' . $t['mb'] . 'MB</span>';
        }
        echo healthRow('Largest tables', implode(' · ', $sizes), 'ok');
    } catch (Throwable $e) {
        echo healthRow('Data lifecycle', 'unavailable: ' . htmlspecialchars($e->getMessage()), 'warn');
    }
    ?>
    </tbody></table></div></div>

<div class="card"><div class="card-header"><h3><i data-lucide="history"></i> Recent Scheduler Runs</h3></div><div class="table-responsive"><table class="data-table">
    <thead><tr><th>Started</th><th>Bucket</th><th>Trigger</th><th>Status</th><th>Counts</th><th>Duration</th></tr></thead><tbody>
    <?php foreach (Database::fetchAll('SELECT * FROM automation_runs ORDER BY id DESC LIMIT 10') as $run): ?>
        <tr><td><?= htmlspecialchars(toDisplay((string) $run['started_at'])) ?></td><td><?= (int) $run['bucket_id'] ?></td>
        <td><span class="badge badge-info"><?= htmlspecialchars($run['triggered_by']) ?></span></td>
        <td><span class="badge <?= $run['status'] === 'completed' ? 'badge-success' : ($run['status'] === 'completed_failed' ? 'badge-warning' : 'badge-danger') ?>"><?= htmlspecialchars($run['status']) ?></span></td>
        <td><?= (int) $run['success_count'] ?> sent / <?= (int) $run['failed_count'] ?> failed / <?= (int) $run['manual_count'] ?> manual</td>
        <td class="text-muted"><?= (int) $run['duration_seconds'] ?>s</td></tr>
    <?php endforeach; ?>
    <?php if (!Database::fetch('SELECT id FROM automation_runs LIMIT 1')): ?><tr><td colspan="6" class="empty-state"><i data-lucide="inbox"></i><p>No runs yet</p></td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php Layout::renderFoot(); ?>