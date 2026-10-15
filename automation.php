<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Layout.php';
require_once 'includes/Automation.php';
Automation::ensureSchema();
EventEngine::ensureEventSchema();
Auth::requireLogin();
Auth::requireWrite();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    if (isset($_POST['run_bucket'])) {
        $bucketId = (int) $_POST['run_bucket'];
        Database::query('UPDATE automation_buckets SET run_on_demand = 1, next_run_at = NOW() WHERE id = ?', [$bucketId]);
        header('Location: automation?queued=1'); exit;
    }
    if (isset($_POST['delete_bucket'])) {
        Database::delete('automation_buckets', 'id = ?', [(int) $_POST['delete_bucket']]);
        header('Location: automation?deleted=1'); exit;
    }
    if (isset($_POST['toggle_bucket'])) {
        $bucket = Database::fetch("SELECT is_event, is_enabled FROM automation_buckets WHERE id = ?", [(int) $_POST['toggle_bucket']]);
        $engineOn = Schema::config('event_engine_enabled', '1') === '1' && Schema::config('scheduler_enabled', '1') === '1';
        if ($bucket && (int) $bucket['is_event'] === 1 && $engineOn && (int) $bucket['is_enabled'] === 0) {
            // Event buckets can't be enabled while the engine owns events.
            header('Location: automation?engine_owns_events=1'); exit;
        }
        Database::query('UPDATE automation_buckets SET is_enabled = NOT is_enabled WHERE id = ?', [(int) $_POST['toggle_bucket']]);
        header('Location: automation'); exit;
    }
    if (isset($_POST['master_toggle'])) {
        $enable = $_POST['master_toggle'] === 'enable';
        // Master toggle owns movement/interval automation + jobs ONLY.
        // Event buckets are switched off by default so the running engine never
        // double-submits an event; enabling them is only meaningful while the
        // engine is OFF (see event_engine_toggle for the mutual exclusion).
        Database::query('UPDATE automation_buckets SET is_enabled = ? WHERE is_event = 0', [$enable ? 1 : 0]);
        if (!$enable) {
            // Master OFF also turns off the engine and any leftover event buckets.
            Database::query('UPDATE automation_buckets SET is_enabled = 0');
            Database::query(
                "INSERT INTO config (config_key, config_value, description)
                 VALUES ('event_engine_enabled', '0', 'Real-time event engine master switch')
                 ON DUPLICATE KEY UPDATE config_value = '0'"
            );
        }
        Database::query('UPDATE jobs SET is_enabled = ?', [$enable ? 1 : 0]);
        header('Location: automation?master=' . ($enable ? 'on' : 'off')); exit;
    }
    if (isset($_POST['event_engine_toggle'])) {
        $val = $_POST['event_engine_toggle'] === 'enable' ? 1 : 0;
        Database::query(
            "INSERT INTO config (config_key, config_value, description)
             VALUES ('event_engine_enabled', ?, 'Real-time event engine master switch')
             ON DUPLICATE KEY UPDATE config_value = ?",
            [$val, $val]
        );
        if ($val) {
            // Mutual exclusion: when the engine owns events, disable all event buckets.
            Database::query('UPDATE automation_buckets SET is_enabled = 0 WHERE is_event = 1');
        }
        header('Location: automation?engine=' . ($val ? 'on' : 'off')); exit;
    }
    $activityId = (int) ($_POST['activity_id'] ?? 0);
    $vehicleIds = array_values(array_filter(array_map('intval', $_POST['vehicle_ids'] ?? [])));
    $bucketId = (int) ($_POST['bucket_id'] ?? 0);
    if ($activityId < 1 || $activityId > 24 || !$vehicleIds || trim($_POST['bucket_name'] ?? '') === '') {
        header('Location: automation?error=Provide a name, one activity, and at least one vehicle'); exit;
    }
    $data = ['bucket_name' => trim($_POST['bucket_name']), 'activity_id' => $activityId, 'interval_seconds' => 180, 'cooldown_seconds' => 0];
    if ($bucketId) {
        $data['is_event'] = $activityId === 1 ? 0 : 1;
        Database::update('automation_buckets', $data, 'id = ?', [$bucketId]);
        Database::delete('bucket_vehicles', 'bucket_id = ?', [$bucketId]);
    } else {
        $data['next_run_at'] = date(DATE_FORMAT);
        $data['is_event'] = $activityId === 1 ? 0 : 1;
        $bucketId = Database::insert('automation_buckets', $data);
    }
    foreach ($vehicleIds as $vehicleId) Database::insert('bucket_vehicles', ['bucket_id' => $bucketId, 'vehicle_id' => $vehicleId]);
    header('Location: automation?saved=1'); exit;
}
if (isset($_GET['delete']) || isset($_GET['toggle'])) { header('Location: automation'); exit; }

$buckets = Database::fetchAll("SELECT ab.*, ad.activity_name, (SELECT COUNT(*) FROM bucket_vehicles bv WHERE bv.bucket_id = ab.id) vehicle_count, (SELECT COUNT(*) FROM automation_runs r WHERE r.bucket_id = ab.id) run_count FROM automation_buckets ab JOIN activity_definitions ad ON ad.id = ab.activity_id ORDER BY ab.created_at DESC");
$vehicles = Database::fetchAll('SELECT id, vehicle_reg_no FROM vehicles WHERE is_active = 1 ORDER BY vehicle_reg_no');
$activities = Database::fetchAll('SELECT id, activity_name FROM activity_definitions ORDER BY id');
$editBucket = null; $selectedVehicleIds = [];
if (isset($_GET['edit'])) {
    $editBucket = Database::fetch('SELECT * FROM automation_buckets WHERE id = ?', [(int) $_GET['edit']]);
    if ($editBucket) $selectedVehicleIds = array_column(Database::fetchAll('SELECT vehicle_id FROM bucket_vehicles WHERE bucket_id = ?', [$editBucket['id']]), 'vehicle_id');
}

// KPI strip
$totalBuckets = count($buckets);
$activeBuckets = array_sum(array_map(fn ($b) => (int) $b['is_enabled'], $buckets));
$managedVehicles = array_sum(array_column($buckets, 'vehicle_count'));
$queuePending = (int) (Database::fetch("SELECT COUNT(*) AS c FROM message_log WHERE latra_status = 'pending'")['c'] ?? 0);

// Real-time event engine state.
$engineEnabled = Schema::config('event_engine_enabled', '1') === '1' && Schema::config('scheduler_enabled', '1') === '1';
$engineLastRun = json_decode((string) Schema::config('event_engine_last_run', '{}'), true) ?: [];
$eventsToday = (int) (Database::fetch("SELECT COUNT(*) AS c FROM wialon_events WHERE created_at >= (NOW() - INTERVAL 24 HOUR)")['c'] ?? 0);
$eventsSent = (int) (Database::fetch("SELECT COUNT(*) AS c FROM wialon_events WHERE status = 'sent' AND created_at >= (NOW() - INTERVAL 24 HOUR)")['c'] ?? 0);
$recentEvents = Database::fetchAll(
    "SELECT e.id, e.vehicle_id, e.event_type, e.activity_id, e.source, e.status,
            e.wialon_msg_time, e.last_error, e.created_at, v.vehicle_reg_no
     FROM wialon_events e JOIN vehicles v ON v.id = e.vehicle_id
     ORDER BY e.id DESC LIMIT 12"
);

// Latest run per bucket for the cards
$lastRunByBucket = [];
foreach (Database::fetchAll("SELECT bucket_id, status AS run_status, success_count, failed_count, manual_count, total_count, started_at, triggered_by, duration_seconds FROM automation_runs r WHERE id = (SELECT MAX(id) FROM automation_runs WHERE bucket_id = r.bucket_id)") as $lr) {
    $lastRunByBucket[(int) $lr['bucket_id']] = $lr;
}

function humantime(int $seconds): string {
    if ($seconds >= 3600 && $seconds % 3600 === 0) return round($seconds / 3600) . ' hr';
    if ($seconds >= 60 && $seconds % 60 === 0) return round($seconds / 60) . ' min';
    return $seconds . ' sec';
}

function fmtCounts(array $counts): array {
    $total = max(1, (int) $counts['succ'] + (int) $counts['fail'] + (int) $counts['man']);
    return [
        'pct_succ' => round((int) $counts['succ'] / $total * 100, 1),
        'pct_fail' => round((int) $counts['fail'] / $total * 100, 1),
        'pct_man'  => round((int) $counts['man'] / $total * 100, 1),
    ];
}

// Master automation state: movement buckets + jobs (the engine owns event buckets).
$movementBuckets = count(array_filter($buckets, fn ($b) => (int) $b['is_event'] === 0));
$movementActive = count(array_filter($buckets, fn ($b) => (int) $b['is_event'] === 0 && (int) $b['is_enabled'] === 1));
$allJobsEnabled = (int) (Database::fetch("SELECT COUNT(*) AS c FROM jobs WHERE is_enabled = 0")['c'] ?? 0) === 0;
$allEnabled = $movementActive === $movementBuckets && $movementBuckets > 0 && $allJobsEnabled;

Layout::renderHead('Automation Buckets');
?>
<style>
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .kpi { display: flex; align-items: center; gap: 14px; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 18px; box-shadow: var(--shadow); }
    .kpi .kpi-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
    .kpi .kpi-value { font-size: 21px; font-weight: 700; line-height: 1.1; color: var(--text-dark); }
    .kpi .kpi-label { font-size: 12px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .master-toggle-bar { display: flex; align-items: center; gap: 16px; background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 20px; margin-bottom: 20px; box-shadow: var(--shadow); }
    .master-toggle-bar.active { border-left: 4px solid var(--success); }
    .master-toggle-bar.inactive { border-left: 4px solid var(--danger); background: #FEF2F2; }
    .master-toggle-bar .mtb-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
    .master-toggle-bar.active .mtb-icon { background: #D1FAE5; color: #065F46; }
    .master-toggle-bar.inactive .mtb-icon { background: #FEE2E2; color: #991B1B; }
    .master-toggle-bar .mtb-info { flex: 1; }
    .master-toggle-bar .mtb-info h4 { margin: 0; font-size: 14px; font-weight: 700; color: var(--text-dark); }
    .master-toggle-bar .mtb-info p { margin: 2px 0 0; font-size: 12px; color: var(--text); }
    .master-toggle-bar .mtb-actions { display: flex; align-items: center; gap: 12px; }

    .auto-layout { display: grid; grid-template-columns: minmax(320px, 380px) minmax(0, 1fr); gap: 24px; align-items: start; }
    .auto-layout > * { min-width: 0; }
    @media (max-width: 1100px) { .auto-layout { grid-template-columns: 1fr; } }
    .auto-form-card { position: sticky; top: 20px; }
    @media (max-width: 1100px) { .auto-form-card { position: static; } }

    .seg-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .seg-chip { position: relative; cursor: pointer; }
    .seg-chip input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
    .seg-chip span { display: flex; align-items: center; gap: 6px; padding: 9px 8px; font-size: 12px; font-weight: 600; color: var(--text); background: var(--bg); border: 1px solid var(--border); border-radius: 8px; transition: all .15s; user-select: none; }
    .seg-chip input:checked + span { background: #EFF6FF; border-color: #93C5FD; color: var(--primary); box-shadow: 0 0 0 3px rgba(59, 130, 246, .12); }
    .seg-chip:hover span { border-color: #93C5FD; }

    .vehicle-pick { position: relative; }
    .vehicle-pick .pick-search { position: relative; }
    .vehicle-pick .pick-search i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--text); font-size: 12px; }
    .vehicle-pick .pick-search input { width: 100%; padding: 9px 12px 9px 32px; border: 1px solid var(--border); border-radius: 8px; font-size: 13px; outline: none; background: var(--bg); }
    .vehicle-pick .pick-search input:focus { border-color: var(--primary); }
    .vehicle-pick select.vehicle-select { width: 100%; min-height: 120px; padding: 8px; border: 1px solid var(--border); border-radius: 8px; font-size: 13px; background: var(--card); outline: none; }
    .vehicle-pick select.vehicle-select option { padding: 6px 8px; border-radius: 6px; margin: 1px; }
    .pick-toolbar { display: flex; align-items: center; justify-content: space-between; margin: 8px 0 6px; }
    .pick-count { font-size: 12px; font-weight: 600; color: var(--primary); background: #DBEAFE; border-radius: 999px; padding: 2px 10px; }
    .pick-link { font-size: 12px; font-weight: 600; color: var(--primary); cursor: pointer; background: none; border: none; padding: 0; }
    .pick-link:hover { text-decoration: underline; }

    .preset-row { display: flex; gap: 6px; flex-wrap: wrap; }
    .preset-btn { padding: 7px 12px; font-size: 12px; font-weight: 600; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); cursor: pointer; transition: all .15s; }
    .preset-btn:hover { border-color: #93C5FD; color: var(--primary); }
    .preset-btn.active { background: var(--primary); border-color: var(--primary); color: #fff; }

    .bucket-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr)); gap: 16px; }
    .bucket-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); display: flex; flex-direction: column; overflow: hidden; transition: all .2s; }
    .bucket-card.is-enabled { border-left: 4px solid var(--success); }
    .bucket-card:not(.is-enabled) { border-left: 4px solid #CBD5E1; opacity: .82; }
    .bucket-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(15, 23, 42, .08); }
    .bucket-head { display: flex; align-items: flex-start; gap: 12px; padding: 16px 16px 12px; }
    .bucket-icon { width: 40px; height: 40px; border-radius: 10px; background: #EFF6FF; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
    .bucket-title { flex: 1; min-width: 0; }
    .bucket-title h4 { font-size: 14px; font-weight: 600; color: var(--text-dark); margin: 0 0 6px; display: flex; align-items: center; gap: 8px; }
    .bucket-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; background: #F1F5F9; color: var(--text); font-size: 11px; font-weight: 600; margin: 2px 4px 0 0; }
    .bucket-chip i { font-size: 10px; color: var(--text); }
    .bucket-run { padding: 0 16px 12px; }
    .run-line { font-size: 12px; color: var(--text); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
    .dist { display: flex; height: 8px; border-radius: 999px; overflow: hidden; background: #E2E8F0; }
    .dist span { height: 100%; }
    .dist .d-ok { background: var(--success); }
    .dist .d-man { background: var(--warning); }
    .dist .d-fail { background: var(--danger); }
    .dist-legend { display: flex; gap: 14px; margin-top: 7px; font-size: 11px; color: var(--text); }
    .dist-legend b { color: var(--text-dark); }
    .bucket-warn { margin: 0 16px 10px; padding: 8px 12px; border-radius: 8px; font-size: 12px; }
    .bucket-warn.warn-danger { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
    .bucket-warn.warn-info { background: #EFF6FF; color: #1E40AF; border: 1px solid #BFDBFE; }
    .bucket-foot { display: flex; align-items: center; gap: 8px; padding: 12px 16px; border-top: 1px solid var(--border); background: #FAFBFC; margin-top: auto; }
    .bucket-foot .btn { padding: 7px 13px; font-size: 12px; }
    .bucket-foot .spacer { flex: 1; }
    .queued-pulse { position: relative; padding-left: 10px; }
    .queued-pulse::before { content: ""; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 7px; height: 7px; border-radius: 50%; background: var(--warning); animation: pulseDot 1.2s ease-in-out infinite; }
    @keyframes pulseDot { 0%,100% { box-shadow: 0 0 0 0 rgba(245,158,11,.5);} 50% { box-shadow: 0 0 0 5px rgba(245,158,11,0);} }
    .form-help { font-size: 11px; color: var(--text); margin-top: 6px; display: flex; align-items: center; gap: 6px; }
</style>

<div class="page-header">
    <div>
        <h1><i data-lucide="layers"></i> Automation Buckets</h1>
        <p class="text-muted">Each bucket owns one LATRA activity and runs its vehicles as a serialized task.</p>
    </div>
    <a class="btn btn-primary" href="#newBucket" title="Create a new bucket"><i data-lucide="plus"></i> New Bucket</a>
</div>

<?php if (isset($_GET['saved'])): ?><?= Layout::alert('success', 'Bucket saved') ?><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><?= Layout::alert('success', 'Bucket deleted') ?><?php endif; ?>
<?php if (isset($_GET['queued'])): ?><?= Layout::alert('success', 'Run queued — the scheduler will pick it up on its next tick') ?><?php endif; ?>
<?php if (isset($_GET['error'])): ?><?= Layout::alert('error', htmlspecialchars($_GET['error'])) ?><?php endif; ?>
<?php if (isset($_GET['master'])): ?><?= Layout::alert($_GET['master'] === 'on' ? 'success' : 'warning', $_GET['master'] === 'on' ? 'Movement automation ENABLED — movement buckets and jobs are active (events owned by the engine).' : 'All automation DISABLED — buckets, jobs, and the event engine are off. No data will be pushed to LATRA.') ?><?php endif; ?>
<?php if (isset($_GET['engine'])): ?><?= Layout::alert($_GET['engine'] === 'on' ? 'success' : 'warning', $_GET['engine'] === 'on' ? 'Real-time event engine ENABLED.' : 'Real-time event engine DISABLED.') ?><?php endif; ?>
<?php if (isset($_GET['engine_owns_events'])): ?><?= Layout::alert('warning', 'Event buckets are disabled while the real-time engine is ON — it submits events immediately. Turn the engine OFF (or master switch OFF) to fall back on interval buckets for events.') ?><?php endif; ?>

<!-- Master kill switch -->
<div class="master-toggle-bar <?= $allEnabled && $allJobsEnabled ? 'active' : 'inactive' ?>">
    <div class="mtb-icon"><i data-lucide="<?= Icon::fixStripped($allEnabled && $allJobsEnabled ? 'power-off' : 'circle-xmark') ?>"></i></div>
    <div class="mtb-info">
        <h4>Master Automation Switch</h4>
        <p>
            <?php if ($allEnabled && $allJobsEnabled): ?>
                Movement buckets and scheduler jobs are <b>active</b>. Event activities are owned by the real-time engine (see below).
            <?php else: ?>
                Movement automation is <b>paused</b>. <?= $movementBuckets - $movementActive ?> of <?= $movementBuckets ?> movement buckets are off.
            <?php endif; ?>
        </p>
    </div>
    <div class="mtb-actions">
        <form method="POST" id="masterToggleForm" style="display:flex;align-items:center;gap:10px">
            <?= Auth::csrfField() ?>
            <?php if ($allEnabled && $allJobsEnabled): ?>
                <input type="hidden" name="master_toggle" value="disable">
                <span style="font-size:12px;font-weight:600;color:var(--success)">All ON</span>
                <label class="toggle-switch" style="cursor:pointer">
                    <input type="checkbox" checked onchange="confirmMasterToggle(this)" aria-label="Master toggle">
                    <span class="toggle-slider"></span>
                </label>
            <?php else: ?>
                <input type="hidden" name="master_toggle" value="enable">
                <span style="font-size:12px;font-weight:600;color:var(--danger)">All OFF</span>
                <label class="toggle-switch" style="cursor:pointer">
                    <input type="checkbox" onchange="confirmMasterToggle(this)" aria-label="Master toggle">
                    <span class="toggle-slider"></span>
                </label>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- KPI strip -->
<div class="kpi-grid">
    <div class="kpi"><div class="kpi-icon bg-primary"><i data-lucide="layers"></i></div><div><div class="kpi-value"><?= $totalBuckets ?></div><div class="kpi-label">Total buckets</div></div></div>
    <div class="kpi"><div class="kpi-icon bg-success"><i data-lucide="play"></i></div><div><div class="kpi-value"><?= $activeBuckets ?></div><div class="kpi-label">Active</div></div></div>
    <div class="kpi"><div class="kpi-icon bg-info"><i data-lucide="truck"></i></div><div><div class="kpi-value"><?= $managedVehicles ?></div><div class="kpi-label">Vehicles under automation</div></div></div>
    <div class="kpi"><div class="kpi-icon bg-warning"><i data-lucide="mail-open"></i></div><div><div class="kpi-value"><?= $queuePending ?></div><div class="kpi-label">Messages in queue</div></div></div>
    <div class="kpi"><div class="kpi-icon bg-info"><i data-lucide="zap"></i></div><div><div class="kpi-value"><?= $eventsToday ?> <small style="font-size:12px;color:var(--success)">/<?= $eventsSent ?> sent</small></div><div class="kpi-label">Real-time events (24h)</div></div></div>
</div>

<!-- Real-time event engine -->
<div class="master-toggle-bar <?= $engineEnabled ? 'active' : 'inactive' ?>" style="margin-bottom:20px">
    <div class="mtb-icon"><i data-lucide="<?= Icon::fixStripped($engineEnabled ? 'tower-broadcast' : 'tower-broadcast') ?>" style="<?= $engineEnabled ? '' : 'opacity:.45' ?>"></i></div>
    <div class="mtb-info">
        <h4>Real-Time Event Engine</h4>
        <p>
            <?php if ($engineEnabled): ?>
                Detects events from new Wialon telemetry and submits them to LATRA immediately (via the outbox as a retry fallback). Movement stays on bucket intervals.
            <?php else: ?>
                Paused. <?= Schema::config('scheduler_enabled', '1') === '1' ? 'The engine switch is off.' : 'The master scheduler switch is off.' ?>
            <?php endif; ?>
            <?php if ($engineLastRun): ?>
                <br><small class="text-muted">Last cycle: <?= htmlspecialchars($engineLastRun['ts'] ?? '') ?> —
                <?= (int) ($engineLastRun['stats']['vehicles'] ?? 0) ?> vehicles,
                <?= (int) ($engineLastRun['stats']['detected'] ?? 0) ?> events,
                <?= (int) ($engineLastRun['stats']['sent'] ?? 0) ?> sent,
                <?= (int) ($engineLastRun['stats']['failed'] ?? 0) ?> failed,
                <?= (int) ($engineLastRun['stats']['denied'] ?? 0) ?> denied.</small>
            <?php endif; ?>
        </p>
    </div>
    <div class="mtb-actions">
        <form method="POST" id="engineToggleForm" style="display:flex;align-items:center;gap:10px">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="event_engine_toggle" value="<?= $engineEnabled ? 'disable' : 'enable' ?>">
            <span style="font-size:12px;font-weight:600;color:<?= $engineEnabled ? 'var(--success)' : 'var(--danger)' ?>"><?= $engineEnabled ? 'Engine ON' : 'Engine OFF' ?></span>
            <label class="toggle-switch" style="cursor:pointer">
                <input type="checkbox" <?= $engineEnabled ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="Event engine toggle">
                <span class="toggle-slider"></span>
            </label>
        </form>
    </div>
</div>

<div class="auto-layout">
    <!-- Bucket form -->
    <div class="card auto-form-card" id="newBucket">
        <div class="card-header"><div class="card-title"><i data-lucide="<?= Icon::fixStripped($editBucket ? 'pen' : 'plus') ?>"></i><h3><?= $editBucket ? 'Edit Bucket' : 'New Bucket' ?></h3></div></div>
        <div class="card-body">
            <form method="POST" class="form" id="bucketForm">
                <?= Auth::csrfField() ?>
                <?php if ($editBucket): ?><input type="hidden" name="bucket_id" value="<?= $editBucket['id'] ?>"><?php endif; ?>

                <div class="form-group">
                    <label>Bucket name</label>
                    <input class="form-input" name="bucket_name" value="<?= htmlspecialchars($editBucket['bucket_name'] ?? '') ?>" placeholder="Morning movement batch" required>
                </div>

                <div class="form-group">
                    <label>LATRA activity</label>
                    <div class="seg-grid">
                        <?php foreach ($activities as $activity): ?>
                            <label class="seg-chip" title="<?= htmlspecialchars($activity['activity_name']) ?>">
                                <input type="radio" name="activity_id" value="<?= (int) $activity['id'] ?>" <?= (int) ($editBucket['activity_id'] ?? 0) === (int) $activity['id'] ? 'checked' : '' ?> required>
                                <span><?= (int) $activity['id'] ?>. <?= htmlspecialchars(mb_strimwidth($activity['activity_name'], 0, 18, '…')) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$activities): ?><p class="form-help">No activity definitions found — add activities first.</p><?php endif; ?>
                </div>

                <div class="form-group vehicle-pick">
                    <div class="pick-toolbar">
                        <label>Vehicles in bucket</label>
                        <button type="button" class="pick-link" id="selectAllVehicles">Select all active</button>
                    </div>
                    <div class="pick-search"><i data-lucide="search"></i><input type="text" id="vehicleSearch" placeholder="Filter vehicles..." autocomplete="off"></div>
                    <select class="vehicle-select" id="vehicleIds" name="vehicle_ids[]" multiple required>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= (int) $vehicle['id'] ?>" <?= in_array($vehicle['id'], $selectedVehicleIds) ? 'selected' : '' ?>><?= htmlspecialchars($vehicle['vehicle_reg_no']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="pick-toolbar"><span class="pick-count" id="vehicleCount">0 selected</span></div>
                    <?php if (!$vehicles): ?><p class="form-help">No active vehicles found.</p><?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Run every</label>
                    <div class="preset-row" id="intervalPresets">
                        <button type="button" class="preset-btn active" data-sec="180">3 min</button>
                    </div>
                    <div class="form-row" style="margin-top:8px">
                        <div class="form-group"><label>Interval (s)</label><input class="form-input" type="number" id="intervalInput" name="interval_seconds" value="180" min="180" max="180" step="180" readonly required></div>
                        <div class="form-group"><label>Cooldown (s)</label><input class="form-input" type="number" name="cooldown_seconds" value="0" min="0" max="0" readonly></div>
                    </div>
                </div>

                <div class="form-row">
                    <button class="btn btn-primary w-100" type="submit"><i data-lucide="save"></i> <?= $editBucket ? 'Save Changes' : 'Create Bucket' ?></button>
                    <?php if ($editBucket): ?><a class="btn btn-secondary w-100" href="automation">Cancel Edit</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Bucket list -->
    <div>
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i data-lucide="list"></i><h3>Buckets</h3></div>
                <span class="badge badge-info"><?= $totalBuckets ?> total</span>
            </div>
            <div class="card-body">
                <?php if (!$buckets): ?>
                    <div class="empty-state" style="padding:48px 0"><i data-lucide="layers"></i><p>No buckets configured yet</p><small class="text-muted">Create your first bucket to start automating LATRA pushes.</small></div>
                <?php else: ?>
                    <div class="bucket-grid">
                        <?php foreach ($buckets as $bucket):
                            $lr = $lastRunByBucket[(int) $bucket['id']] ?? null;
                            $pcts = $lr ? fmtCounts(['succ' => $lr['success_count'], 'fail' => $lr['failed_count'], 'man' => $lr['manual_count']]) : null;
                            $consec = (int) $bucket['consecutive_failures'];
                            $isEnabled = (int) $bucket['is_enabled'] === 1;
                            ?>
                            <div class="bucket-card <?= $isEnabled ? 'is-enabled' : '' ?>">
                                <div class="bucket-head">
                                    <div class="bucket-icon"><i data-lucide="route"></i></div>
                                    <div class="bucket-title">
                                        <h4><?= htmlspecialchars($bucket['bucket_name']) ?><?php if ((int) $bucket['run_on_demand'] === 1): ?><span class="queued-pulse text-warning" title="A manual run is queued for this bucket"></span><?php endif; ?></h4>
                                        <span class="bucket-chip"><i data-lucide="list-checks"></i> <?= (int) $bucket['activity_id'] ?>. <?= htmlspecialchars($bucket['activity_name']) ?></span>
                                        <?php if ((int) $bucket['is_event'] === 1): ?>
                                            <span class="bucket-chip" style="background:#FEF3C7;color:#92400E" title="Event-driven — owned by the real-time engine when it is ON"><i data-lucide="zap"></i> event</span>
                                        <?php else: ?>
                                            <span class="bucket-chip" style="background:#D1FAE5;color:#065F46" title="Interval-driven movement batch"><i data-lucide="truck"></i> interval</span>
                                        <?php endif; ?>
                                        <span class="bucket-chip"><i data-lucide="truck"></i> <?= (int) $bucket['vehicle_count'] ?> veh.</span>
                                        <span class="bucket-chip"><i data-lucide="rotate-cw"></i> <?= (int) $bucket['run_count'] ?> runs</span>
                                        <span class="bucket-chip"><i data-lucide="clock"></i> every <?= humantime((int) $bucket['interval_seconds']) ?> · +<?= (int) $bucket['cooldown_seconds'] ?>s</span>
                                    </div>
                                    <form method="POST" title="<?= $isEnabled ? 'Pause' : 'Resume' ?> bucket" style="flex-shrink:0;display:flex;align-items:center">
                                        <?= Auth::csrfField() ?>
                                        <input type="hidden" name="toggle_bucket" value="<?= (int) $bucket['id'] ?>">
                                        <label class="toggle-switch" style="cursor:pointer">
                                            <input type="checkbox" <?= $isEnabled ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="Toggle bucket">
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </form>
                                </div>

                                <div class="bucket-run">
                                    <?php if ($lr): ?>
                                        <div class="run-line">
                                            <?php $runStatus = $lr['run_status']; ?>
                                            <span class="status-dot" style="background:<?= $runStatus === 'completed' ? 'var(--success)' : ($runStatus === 'completed_failed' ? 'var(--warning)' : 'var(--danger)') ?>"></span>
                                            <span>Last run <b><?= htmlspecialchars(toDisplay((string) ($lr['started_at'] ?? ''))) ?></b></span>
                                            <span class="badge <?= $runStatus === 'completed' ? 'badge-success' : ($runStatus === 'completed_failed' ? 'badge-warning' : 'badge-danger') ?>"><?= htmlspecialchars($runStatus) ?></span>
                                            <span class="badge badge-secondary"><i data-lucide="zap"></i> <?= htmlspecialchars($lr['triggered_by']) ?></span>
                                        </div>
                                        <div class="dist">
                                            <span class="d-ok" style="width:<?= $pcts['pct_succ'] ?>%"></span>
                                            <span class="d-man" style="width:<?= $pcts['pct_man'] ?>%"></span>
                                            <span class="d-fail" style="width:<?= $pcts['pct_fail'] ?>%"></span>
                                        </div>
                                        <div class="dist-legend">
                                            <span><i data-lucide="circle" style="color:var(--success)"></i> <b><?= (int) $lr['success_count'] ?></b> sent</span>
                                            <span><i data-lucide="circle" style="color:var(--danger)"></i> <b><?= (int) $lr['failed_count'] ?></b> failed</span>
                                            <span><i data-lucide="circle" style="color:var(--muted, #94A3B8)"></i> <b><?= (int) $lr['total_count'] ?></b> total</span>
                                            <span style="margin-left:auto"><i data-lucide="timer"></i> <?= (int) $lr['duration_seconds'] ?>s</span>
                                        </div>
                                    <?php else: ?>
                                        <div class="run-line"><span class="status-dot" style="background:#CBD5E1"></span><span>Never run yet</span></div>
                                        <div class="dist"><span class="d-ok" style="width:0%"></span></div>
                                        <div class="dist-legend"><span><i data-lucide="circle" style="color:#CBD5E1"></i> waiting for schedule</span></div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($consec >= 3): ?>
                                    <div class="bucket-warn warn-danger"><i data-lucide="triangle-alert"></i> <?= $consec ?> consecutive failures — inspect the last run or LATRA connectivity.</div>
                                <?php elseif ($bucket['last_error']): ?>
                                    <div class="bucket-warn warn-info"><i data-lucide="info"></i> <?= htmlspecialchars($bucket['last_error']) ?></div>
                                <?php endif; ?>

                                <div class="bucket-foot">
                                    <form method="POST" style="display:inline;margin:0">
                                        <?= Auth::csrfField() ?>
                                        <input type="hidden" name="run_bucket" value="<?= (int) $bucket['id'] ?>">
                                        <button class="btn btn-primary" type="button" title="Run this bucket now" onclick="runBucket(this)"><i data-lucide="play"></i> Run now</button>
                                    </form>
                                    <a class="btn btn-ghost" href="?edit=<?= (int) $bucket['id'] ?>" title="Edit bucket"><i data-lucide="square-pen"></i> Edit</a>
                                    <span class="spacer"></span>
                                    <form method="POST" style="display:inline;margin:0">
                                        <?= Auth::csrfField() ?>
                                        <input type="hidden" name="delete_bucket" value="<?= (int) $bucket['id'] ?>">
                                        <button class="btn btn-ghost btn-sm text-danger" type="button" title="Delete bucket" onclick="deleteBucket(this)" style="color:var(--danger)"><i data-lucide="trash-2"></i></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Real-time event feed -->
        <div class="card" style="margin-top:20px">
            <div class="card-header">
                <div class="card-title"><i data-lucide="zap"></i><h3>Real-Time Events</h3></div>
                <span class="badge badge-info"><?= count($recentEvents) ?> latest</span>
            </div>
            <div class="card-body">
                <?php if (!$recentEvents): ?>
                    <div class="empty-state" style="padding:36px 0"><i data-lucide="zap"></i><p>No events captured yet</p><small class="text-muted">The event engine records normalized events here once it detects non-movement activities on monitored vehicles.</small></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="data-table">
                            <thead>
                                <tr>
                                    <th>Time</th><th>Vehicle</th><th>Event</th><th>Activity</th>
                                    <th>Source</th><th>Status</th><th>Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentEvents as $ev): ?>
                                    <tr>
                                        <td class="text-muted"><?= htmlspecialchars(toDisplay(date(DATE_FORMAT, (int) $ev['wialon_msg_time']))) ?></td>
                                        <td><?= htmlspecialchars($ev['vehicle_reg_no']) ?></td>
                                        <td><b><?= htmlspecialchars($ev['event_type']) ?></b></td>
                                        <td><span class="badge badge-secondary"><?= (int) $ev['activity_id'] ?></span></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($ev['source']) ?></span></td>
                                        <td>
                                            <?php if ($ev['status'] === 'sent'): ?>
                                                <span class="badge badge-success">sent</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger"><?= htmlspecialchars($ev['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars((string) $ev['last_error']) ?>"><?= htmlspecialchars((string) $ev['last_error']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody></table></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    // Vehicle filter + select-all + counter
    const vehicleSelect = document.getElementById('vehicleIds');
    const vehicleCount = document.getElementById('vehicleCount');
    const vehicleSearch = document.getElementById('vehicleSearch');
    const selectAllVehicles = document.getElementById('selectAllVehicles');

    function refreshVehicleCount() {
        const n = Array.from(vehicleSelect.selectedOptions).length;
        vehicleCount.textContent = n + ' selected' + (n ? ' of ' + vehicleSelect.options.length : '');
    }
    refreshVehicleCount();
    vehicleSelect.addEventListener('change', refreshVehicleCount);

    vehicleSearch.addEventListener('input', () => {
        const q = vehicleSearch.value.trim().toLowerCase();
        Array.from(vehicleSelect.options).forEach(o => {
            o.hidden = q !== '' && o.text.toLowerCase().indexOf(q) === -1;
        });
    });
    selectAllVehicles.addEventListener('click', (e) => {
        e.preventDefault();
        Array.from(vehicleSelect.options).forEach(o => o.selected = true);
        refreshVehicleCount();
    });

    // Interval presets
    const intervalInput = document.getElementById('intervalInput');
    const presets = document.querySelectorAll('#intervalPresets .preset-btn');
    presets.forEach(btn => {
        if (parseInt(intervalInput.value, 10) === parseInt(btn.dataset.sec, 10)) btn.classList.add('active');
        btn.addEventListener('click', () => {
            intervalInput.value = btn.dataset.sec;
            presets.forEach(b => b.classList.toggle('active', b === btn));
        });
    });
    intervalInput.addEventListener('input', () => presets.forEach(b => b.classList.toggle('active', parseInt(b.dataset.sec, 10) === parseInt(intervalInput.value, 10))));

    // Run now
    function runBucket(btn) {
        Swal.fire({
            title: 'Run this bucket now?',
            text: 'A run is queued and the scheduler will pick it up within a minute.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, queue it',
            cancelButtonText: 'Cancel'
        }).then(r => { if (r.isConfirmed) btn.form.submit(); });
    }

    // Delete
    function deleteBucket(btn) {
        Swal.fire({
            title: 'Delete this bucket?',
            text: 'This will also remove all vehicle memberships. This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#d33'
        }).then(r => { if (r.isConfirmed) btn.form.submit(); });
    }

    // Master toggle
    function confirmMasterToggle(checkbox) {
        const enabling = checkbox.checked;
        const action = enabling ? 'ENABLE' : 'DISABLE';
        const desc = enabling
            ? 'This will turn on ALL buckets and scheduler jobs. Data will start flowing to LATRA immediately.'
            : 'This will turn off ALL buckets and scheduler jobs. No data will be pushed to LATRA until you turn it back on.';
        Swal.fire({
            title: action + ' all automations?',
            html: '<p style="margin:0">' + desc + '</p>',
            icon: enabling ? 'question' : 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, ' + action,
            cancelButtonText: 'Cancel',
            confirmButtonColor: enabling ? '#10B981' : '#EF4444'
        }).then(r => {
            if (r.isConfirmed) {
                document.getElementById('masterToggleForm').submit();
            } else {
                checkbox.checked = !checkbox.checked;
            }
        });
    }
</script>

<?php Layout::renderFoot(); ?>