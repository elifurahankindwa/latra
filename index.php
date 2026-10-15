<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Layout.php';
require_once 'includes/DashboardCache.php';
Auth::requireView('dashboard.view');

$now = time();

$now = time();

// -- Dashboard scope (header filters: Area / Status / Activity) ---------
$area    = $_GET['area'] ?? 'all';
$status  = $_GET['status'] ?? 'all';
$activity = max(0, (int) ($_GET['activity'] ?? 0));
if (!in_array($area, ['all', 'dar', 'up'], true))   $area = 'all';
if (!in_array($status, ['all', 'online', 'offline'], true)) $status = 'all';

$cacheKey = 'dash|' . $area . '|' . $status . '|' . (string)$activity;
$cached = DashboardCache::get($cacheKey, 60);
if ($cached !== null) {
    extract($cached, EXTR_SKIP);
} else {

$vParts = [];
if ($area === 'dar')  $vParts[] = 'lat BETWEEN -7.4000 AND -6.4000 AND lng BETWEEN 38.9000 AND 39.7000';
if ($area === 'up')   $vParts[] = '(lat IS NULL OR lng IS NULL OR lat = 0 OR lng = 0 OR NOT (lat BETWEEN -7.4000 AND -6.4000 AND lng BETWEEN 38.9000 AND 39.7000))';
if ($status === 'online')  $vParts[] = 'last_message_time IS NOT NULL AND last_message_time > ' . ($now - 86400);
if ($status === 'offline') $vParts[] = '(last_message_time IS NULL OR last_message_time <= ' . ($now - 86400) . ')';
$vScope = $vParts ? implode(' AND ', $vParts) : '1=1';

// Precompute vehicle scope IDs for heavy queries (avoid repeated IN (SELECT...))
$vidRows = Database::fetchAll("SELECT id FROM vehicles WHERE $vScope");
if (empty($vidRows)) {
    $vidList = '(-1)';
    $vidParams = [];
} else {
    $ids = array_column($vidRows, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $vidList = "($ph)";
    $vidParams = $ids;
}
$scopeIn = static fn(string $and = '') => "$and vehicle_id IN $vidList";

// Append the vehicle/activity scope to a WHERE clause already containing conditions.
$scope = function (string $and, string $actTable = 'activity_id'): string {
    global $vidList, $activity;
    $s = "$and vehicle_id IN $vidList";
    if ($activity > 0) $s .= " AND $actTable = $activity";
    return $s;
};

$actName = '';
if ($activity > 0) {
    $def = Database::fetch('SELECT activity_name FROM activity_definitions WHERE id = ?', [$activity]);
    $actName = $def['activity_name'] ?? '';
}

$qs = function (array $drop = []) use ($area, $status, $activity): string {
    $p = [];
    if ($area !== 'all' && !in_array('area', $drop, true))     $p['area'] = $area;
    if ($status !== 'all' && !in_array('status', $drop, true)) $p['status'] = $status;
    if ($activity > 0 && !in_array('activity', $drop, true))   $p['activity'] = $activity;
    return $p ? 'dashboard?' . http_build_query($p) : 'dashboard';
};

function compactNum(int $n): string {
    if ($n < 1000) return (string) $n;
    $v = $n / 1000;
    return rtrim(rtrim(number_format($v, 1), '0'), '.') . 'k';
}

/** Today / This week / Older split for a table + condition. */
function periodTotals(string $from, string $cond, string $dateCol = 'created_at', int $maxDays = 30, array $params = []): array {
    $q = function(string $extra) use ($from, $cond, $dateCol, $params): int {
        $sql = "SELECT COUNT(*) c FROM $from WHERE $cond $extra";
        // Count placeholders in full SQL and in params
        $phCount = substr_count($sql, '?');
        $pCount = is_array($params) ? count($params) : 0;
        if ($phCount > 0 && $pCount >= $phCount) {
            // if more params passed than placeholders? or just pass first N? better pass exactly what matches
            $execParams = array_slice($params, 0, $phCount);
        } elseif ($phCount > 0 && $pCount > 0 && $pCount < $phCount) {
            $execParams = $params;
        } elseif ($phCount > 0 && $pCount == 0) {
            // placeholders but no params provided - this can happen if we constructed badly; try to not pass
            $execParams = $params;
        } else {
            $execParams = [];
        }
        if ($phCount == 0 || empty($execParams)) {
            $row = Database::fetch($sql);
        } else {
            $row = Database::fetch($sql, $execParams);
        }
        return (int) ($row['c'] ?? 0);
    };
    $week  = $q("AND $dateCol >= NOW() - INTERVAL 7 DAY");
    $today = $q("AND $dateCol >= CURDATE()");
    $last30 = $q("AND $dateCol >= NOW() - INTERVAL $maxDays DAY");
    $older = max(0, $last30 - $week);
    return [$today, $week, $older];
}

// -- Pipeline (message → LATRA processing → sent) -----------------
[$openT, $openW, $openO]   = periodTotals('outbox', "status = 'pending'" . $scope(' AND'), 'created_at', 30, $vidParams);
$awaitUrg                   = (int) (Database::fetch("SELECT COUNT(*) c FROM outbox WHERE status = 'failed' AND created_at >= CURDATE()" . $scope(' AND'), $vidParams)['c'] ?? 0);
[$awaitT, $awaitW, $awaitO] = periodTotals('outbox', "status = 'failed'" . $scope(' AND'), 'created_at', 30, $vidParams);
$readyUrg                   = (int) (Database::fetch("SELECT COUNT(*) c FROM outbox WHERE status = 'failed' AND failure_class = 'transient'" . $scope(' AND'), $vidParams)['c'] ?? 0);
[$readyT, $readyW, $readyO] = periodTotals('message_log', "latra_status = 'pending'" . $scope(' AND'), 'created_at', 30, $vidParams);
$wipUrg                     = (int) (Database::fetch("SELECT COUNT(*) c FROM wialon_events WHERE status = 'pending'" . $scope(' AND'), $vidParams)['c'] ?? 0);
[$wipT, $wipW, $wipO]       = periodTotals('wialon_events', '1 = 1' . $scope(' AND'), 'created_at', 30, $vidParams);
[$doneT, $doneW, $doneO]    = periodTotals('push_log', "response_status = '1'" . $scope(' AND'), 'created_at', 30, $vidParams);

// -- Down equipment ------------------------------------------------
$downToday = (int) (Database::fetch("SELECT COUNT(*) c FROM vehicles WHERE id IN $vidList AND (last_message_time IS NULL OR last_message_time <= " . ($now - 86400) . ")", $vidParams)['c'] ?? 0);
$downWeek  = (int) (Database::fetch("SELECT COUNT(*) c FROM vehicles WHERE id IN $vidList AND (last_message_time IS NULL OR last_message_time <= " . ($now - 7 * 86400) . ")", $vidParams)['c'] ?? 0);

// -- Presence totals (GPS connectivity: recency of last GPS fix) -----
$gpsOnline = $now - 86400;
$gpsWeek   = $now - 7 * 86400;

$presOnline  = (int) (Database::fetch("SELECT COUNT(*) c FROM vehicles WHERE id IN $vidList AND last_message_time IS NOT NULL AND last_message_time > $gpsOnline", $vidParams)['c'] ?? 0);
$presOffline = (int) (Database::fetch("SELECT COUNT(*) c FROM vehicles WHERE id IN $vidList AND (last_message_time IS NULL OR last_message_time <= $gpsOnline)", $vidParams)['c'] ?? 0);

// -- Locations (GPS connectivity, split at 7 days) -------------------
$locUnits  = (int) (Database::fetch("SELECT COUNT(*) c FROM vehicles WHERE lat IS NOT NULL AND lng IS NOT NULL AND lat <> 0 AND id IN $vidList", $vidParams)['c'] ?? 0);
$locOk     = $presOnline;
$locOpen   = (int) (Database::fetch("SELECT COUNT(*) c FROM vehicles WHERE id IN $vidList AND last_message_time IS NOT NULL AND last_message_time <= $gpsOnline AND last_message_time > $gpsWeek", $vidParams)['c'] ?? 0);
$locDown   = $presOffline - $locOpen;

// -- Feed -----------------------------------------------------------
$eventsFeed = Database::fetchAll("SELECT e.id, e.status, e.created_at, e.activity_id, e.speed, e.wialon_msg_time,
        v.vehicle_reg_no, v.name, ad.activity_name
    FROM wialon_events e
    LEFT JOIN vehicles v ON e.vehicle_id = v.id
    LEFT JOIN activity_definitions ad ON e.activity_id = ad.id
    WHERE e.vehicle_id IN $vidList" . ($activity > 0 ? " AND e.activity_id = $activity" : '') . "
    ORDER BY e.created_at DESC LIMIT 8", $vidParams);
$sentFeed   = Database::fetchAll("SELECT pl.id, pl.created_at, pl.response_status, pl.latency_ms, pl.attempt,
        v.vehicle_reg_no, v.name, ad.activity_name
    FROM push_log pl
    LEFT JOIN vehicles v ON pl.vehicle_id = v.id
    LEFT JOIN activity_definitions ad ON pl.activity_id = ad.id
    WHERE pl.response_status = '1' AND pl.vehicle_id IN $vidList" . ($activity > 0 ? " AND pl.activity_id = $activity" : '') . "
    ORDER BY pl.created_at DESC LIMIT 8", $vidParams);
$failFeed   = Database::fetchAll("SELECT pl.id, pl.created_at, pl.response_status, pl.latency_ms, pl.attempt,
        v.vehicle_reg_no, v.name, ad.activity_name
    FROM push_log pl
    LEFT JOIN vehicles v ON pl.vehicle_id = v.id
    LEFT JOIN activity_definitions ad ON pl.activity_id = ad.id
    WHERE pl.response_status IS NOT NULL AND pl.response_status <> '1' AND pl.vehicle_id IN $vidList" . ($activity > 0 ? " AND pl.activity_id = $activity" : '') . "
    ORDER BY pl.created_at DESC LIMIT 8", $vidParams);

$mapUnits = Database::fetchAll("SELECT id, vehicle_reg_no, name, lat, lng, speed, last_message_time, satellite_count, bearing
    FROM vehicles
    WHERE lat IS NOT NULL AND lng IS NOT NULL AND lat <> 0 AND lng <> 0 AND ($vScope)
    ORDER BY last_message_time IS NULL, last_message_time DESC LIMIT 80");

function chipFor(string $status): string {
    $s = strtolower((string) $status);
    if ($s === 'sent' || $s === '1'  || $s === 'ok')      return 'chip-green';
    if ($s === 'failed' || $s === 'false' || $s === '0') return 'chip-red';
    if ($s === 'pending' || $s === '')  return 'chip-orange';
    if ($s === 'rejected') return 'chip-gray';
    return 'chip-blue';
}
function feedChipLabel(string $status): string {
    $s = strtolower((string) $status);
    if ($s === '1' || $s === 'ok')           return 'Sent';
    if ($s === '0' || $s === 'false')        return 'Rejected';
    if ($s === 'failed')                     return 'Failed';
    if ($s === 'rejected')                   return 'Rejected';
    if ($s === 'pending')                    return 'Pending';
    return ucfirst($s ?: 'Open');
}
function hhmm(string $dt): string {
    $t = trim($dt);
    return strlen($t) >= 5 ? substr($t, 11, 5) : '--:--';
}

function feedItem(array $e, bool $isNew, bool $unread, string $time, string $text, string $top, string $sub, string $status, string $meta, bool $blue, string $kind, int $id): string {
    $badge = $isNew ? '<span class="f-tag-new">NEW</span>' : '';
    $chip  = $blue ? 'chip-blue' : chipFor($status);
    $label = $blue ? ucfirst(strtolower($status)) : feedChipLabel($status);
    return '<div class="f-item" data-read="' . ($unread ? '0' : '1') . '" data-preview="' . $kind . '" data-id="' . $id . '" title="Open preview">
        <div class="f-head"><span class="f-text">' . $text . '</span><span class="f-time">' . htmlspecialchars($time) . '</span></div>
        ' . $badge . '
        <div class="f-card">
            <div class="f-top">' . htmlspecialchars($top) . '</div>
            <div class="f-row"><span class="f-thumb"><i data-lucide="zap"></i></span><span class="f-name">' . htmlspecialchars($sub) . '</span><span class="chip ' . $chip . '">' . htmlspecialchars($label) . '</span></div>
            <div class="f-meta">' . $meta . '</div>
        </div>
    </div>';
}

$mkw = function (array $e, string $activityKey) {
    $reg  = $e['vehicle_reg_no'] ?? 'Unknown';
    $act  = $e[$activityKey] ?? ('Activity ' . ($e['activity_id'] ?? ''));
    return '<b>' . htmlspecialchars($reg) . '</b> — <b>' . htmlspecialchars($act) . '</b>';
};

function renderFeedItems(array $rows, string $mode): string {
    $html = '';
    foreach ($rows as $e) {
        $t = hhmm(toDisplay((string) $e['created_at']));
        $reg = $e['vehicle_reg_no'] ?? 'Unknown';
        $act = $e['activity_name'] ?? ('Activity ' . ($e['activity_id'] ?? ''));
        if ($mode === 'events') {
            $html .= feedItem($e, true, true, $t,
                '<b>' . htmlspecialchars($reg) . '</b> triggered <b>' . htmlspecialchars($act) . '</b>',
                'Event time ' . ($e['wialon_msg_time'] ? htmlspecialchars(toDisplay(date('Y-m-d H:i:s', (int) $e['wialon_msg_time']))) : 'unknown'),
                $reg . (($e['name'] ?? '') ? ' · ' . htmlspecialchars($e['name']) : ''),
                $e['status'], '<i data-lucide="sticky-note"></i> speed ' . (float) $e['speed'] . ' km/h', false,
                'event', (int) $e['id']);
        } elseif ($mode === 'sent') {
            $html .= feedItem($e, false, true, $t,
                '<b>' . htmlspecialchars($reg) . '</b> delivered <b>' . htmlspecialchars($act) . '</b> to LATRA',
                'Approved & sent · ' . htmlspecialchars(toDisplay((string) $e['created_at'])),
                $reg, 'Sent',
                '<i data-lucide="sticky-note"></i> ' . $e['latency_ms'] . ' ms <i data-lucide="paperclip"></i> attempt ' . (int) $e['attempt'], true,
                'push', (int) $e['id']);
        } else {
            $html .= feedItem($e, false, true, $t,
                '<b>' . htmlspecialchars($reg) . '</b> failed to send <b>' . htmlspecialchars($act) . '</b>',
                'Push attempt ' . (int) $e['attempt'] . ' · ' . htmlspecialchars(toDisplay((string) $e['created_at'])),
                $reg, 'Failed',
                '<i data-lucide="triangle-alert"></i> http ' . (int) $e['latency_ms'] . ' ms', false,
                'push', (int) $e['id']);
        }
    }
    return $html;
}

$feedEventsHtml = renderFeedItems($eventsFeed, 'events');
$feedSentHtml   = renderFeedItems($sentFeed, 'sent');
$feedFailHtml   = renderFeedItems($failFeed, 'failed');

$emptyFeed = '<div class="feed-empty"><i data-lucide="inbox"></i><span>No activity yet</span></div>';

$pp = function (array $c) {
    return '<div class="pp-trio">
        <div class="pp-metric"><span class="v">' . compactNum($c[0]) . '</span><span class="l">Today</span></div>
        <div class="pp-metric"><span class="v">' . compactNum($c[1]) . '</span><span class="l">This week</span></div>
        <div class="pp-metric"><span class="v">' . compactNum($c[2]) . '</span><span class="l">Older</span></div>
    </div>';
};
$urgent = fn(int $n): string => $n > 0
    ? '<span class="pp-urgent"><span class="bd">' . compactNum($n) . '</span>Urgent</span>'
    : '<span class="pp-urgent"><span class="bd">0</span>Urgent</span>';

$info = '<span class="icon-info" title="Counts from the current message pipeline">i</span>';

Layout::renderHead('Dashboard', true);
?>

<div class="page-header">
    <h1>Dashboard</h1>
    <?php if ($area !== 'all' || $status !== 'all' || $activity > 0): ?>
    <div class="filter-chips">
        <?php $areaLabel = ['dar' => 'Dar es Salaam', 'up' => 'Upcountry'][$area] ?? ''; ?>
        <?php if ($area !== 'all'): ?><span class="filter-chip"><i data-lucide="map-pin"></i> Area: <?= htmlspecialchars($areaLabel) ?><a href="<?= htmlspecialchars($qs(['area'])) ?>" title="Clear area filter">&times;</a></span><?php endif; ?>
        <?php if ($status !== 'all'): ?><span class="filter-chip"><i data-lucide="wifi"></i> Status: <?= htmlspecialchars(ucfirst($status)) ?><a href="<?= htmlspecialchars($qs(['status'])) ?>" title="Clear status filter">&times;</a></span><?php endif; ?>
        <?php if ($activity > 0): ?><span class="filter-chip"><i data-lucide="zap"></i> Activity: <?= htmlspecialchars(($activity . '. ' . $actName)) ?><a href="<?= htmlspecialchars($qs(['activity'])) ?>" title="Clear activity filter">&times;</a></span><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ===================== Pipeline ===================== -->
<div class="section-head">
    <h2>Parts and Service Pipeline</h2><?= $info ?>
    <span class="spacer"></span>
    <?php if (Auth::canWrite()): ?>
    <div class="tb-dd" data-dd>
        <button type="button" class="tb-dd-btn" data-dd-btn aria-label="Quick action"><span>Quick action</span><i data-lucide="chevron-down"></i></button>
        <div class="tb-dd-menu">
            <button type="button" class="tb-dd-opt" onclick="quickPush(1,'Movement/Logging')"><i data-lucide="wrench"></i>&nbsp; Service</button>
            <button type="button" class="tb-dd-opt" onclick="quickPush(4,'Speeding')"><i data-lucide="gauge"></i>&nbsp; Speeding</button>
            <button type="button" class="tb-dd-opt" onclick="quickPush(10,'External Power Disconnected')"><i data-lucide="plug-zap"></i>&nbsp; Power / tamper</button>
            <button type="button" class="tb-dd-opt" onclick="quickPush(8,'Panic Button (Driver)')"><i data-lucide="phone"></i>&nbsp; SOS / emergency</button>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="pipeline-card">
    <a class="pp-col" href="push_log" title="Preview pending messages" data-preview="pipeline" data-col="open" data-pv-icon="inbox" data-pv-title="Open Items">
        <div class="pp-head"><span class="nm">Open Items</span><?= $info ?></div>
        <?= $pp([$openT, $openW, $openO]) ?>
    </a>
    <a class="pp-col" href="push_log" title="Preview failed messages" data-preview="pipeline" data-col="await" data-pv-icon="clock" data-pv-title="Awaiting approval">
        <div class="pp-head"><span class="nm">Awaiting approval</span><?= $info ?><?= $urgent($awaitUrg) ?></div>
        <?= $pp([$awaitT, $awaitW, $awaitO]) ?>
    </a>
    <a class="pp-col" href="automation" title="Preview queued messages" data-preview="pipeline" data-col="ready" data-pv-icon="list-checks" data-pv-title="Ready to be Assigned">
        <div class="pp-head"><span class="nm">Ready to be Assigned</span><?= $info ?><?= $urgent($readyUrg) ?></div>
        <?= $pp([$readyT, $readyW, $readyO]) ?>
    </a>
    <a class="pp-col" href="automation" title="Preview events in progress" data-preview="pipeline" data-col="wip" data-pv-icon="zap" data-pv-title="Work in Progress (WIP)">
        <div class="pp-head"><span class="nm">Work in Progress (WIP)</span><?= $info ?><?= $urgent($wipUrg) ?></div>
        <?= $pp([$wipT, $wipW, $wipO]) ?>
    </a>
    <a class="pp-col" href="push_log" title="Preview completed pushes" data-preview="pipeline" data-col="done" data-pv-icon="circle-check" data-pv-title="Recently completed">
        <div class="pp-head"><span class="nm">Recently completed</span><?= $info ?><?= $urgent(0) ?></div>
        <?= $pp([$doneT, $doneW, $doneO]) ?>
    </a>
</div>

<!-- ===================== Lower grid ===================== -->
<div class="lower-grid">

    <!-- Left: Down equipment + Locations + Map -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><h3><?= compactNum($downToday) ?> Down equipment</h3><?= $info ?></div>
        </div>
        <div class="card-body">
            <div class="status-summary">
                <span class="status-total ok" tabindex="0" role="button" data-preview="loc" data-state="ok" data-pv-icon="plug" data-pv-title="Vehicles · GPS online" title="Preview units with a recent GPS fix">
                    <i data-lucide="plug"></i><b><?= compactNum($presOnline) ?></b> online
                </span>
                <span class="status-total bad" tabindex="0" role="button" data-preview="loc" data-state="down" data-pv-icon="plug-zap" data-pv-title="Vehicles · GPS offline" title="Preview units without a recent GPS fix">
                    <i data-lucide="plug-zap"></i><b><?= compactNum($presOffline) ?></b> offline
                </span>
            </div>

            <div class="loc-focus">
                <div class="down-metrics">
                    <div class="pp-metric" tabindex="0" role="button" data-preview="down" data-span="today" data-pv-icon="truck" data-pv-title="Down equipment · today" title="Preview units down today"><span class="v"><?= compactNum($downToday) ?></span><span class="l">Down today</span></div>
                    <div class="pp-metric" tabindex="0" role="button" data-preview="down" data-span="week" data-pv-icon="truck" data-pv-title="Down equipment · this week" title="Preview units down this week"><span class="v"><?= compactNum($downWeek) ?></span><span class="l">Down this week</span></div>
                </div>
                <?php if (Auth::canView()): ?>
                <div style="display:flex; gap:8px; padding-top:4px;">
                    <a class="btn btn-sm btn-ghost" href="vehicles?export=csv&amp;down=today" title="Export down-equipment list (today)"><i data-lucide="download"></i> CSV today</a>
                    <a class="btn btn-sm btn-ghost" href="vehicles?export=csv&amp;down=week" title="Export down-equipment list (week)"><i data-lucide="download"></i> CSV week</a>
                </div>
                <?php endif; ?>
            </div>

            <div class="loc-block">
                <div class="loc-head"><span class="loc-title">Locations</span><?= $info ?></div>
                <div class="loc-count"><i data-lucide="map-pin"></i><?= compactNum($locUnits) ?> units with locations</div>
                <div class="loc-pills">
                    <span class="loc-pill blue" tabindex="0" role="button" data-preview="loc" data-state="ok" data-pv-icon="map-pin" data-pv-title="Locations · GPS online" title="Preview units with a recent GPS fix"><span class="n"><?= compactNum($locOk) ?></span>No issues</span>
                    <span class="loc-pill orange" tabindex="0" role="button" data-preview="loc" data-state="open" data-pv-icon="map-pin" data-pv-title="Locations · GPS silent 1–7 days" title="Preview units with open issues"><span class="n"><?= compactNum($locOpen) ?></span>Open issues</span>
                    <span class="loc-pill red" tabindex="0" role="button" data-preview="loc" data-state="down" data-pv-icon="map-pin" data-pv-title="Locations · GPS down" title="Preview down units"><span class="n"><?= compactNum($locDown) ?></span>Down</span>
                </div>
            </div>
        </div>

        <div class="map-wrap">
            <div class="map-seg">
                <button class="active" data-layer="map" type="button">Map</button>
                <button data-layer="sat" type="button">Satellite</button>
            </div>
            <div id="fleetMap"></div>
        </div>
    </div>

    <!-- Right: Updates feed -->
    <div class="card update-card">
        <div class="card-header">
            <div class="card-title"><h3>Latest updates</h3><?= $info ?></div>
            <div class="feed-tools">
                <span class="mini-toggle on" data-unread-toggle role="checkbox" tabindex="0" aria-checked="true" title="Show only unread">
                    <span class="s"></span>Only unread
                </span>
                <div class="tb-dd small" data-dd>
                    <button type="button" class="tb-dd-btn" data-dd-btn aria-label="Filter type"><span>All</span><i data-lucide="chevron-down"></i></button>
                    <div class="tb-dd-menu">
                        <button type="button" class="tb-dd-opt" data-unread-set="all">All</button>
                        <button type="button" class="tb-dd-opt" data-unread-set="new">Only new</button>
                    </div>
                </div>
                <button type="button" class="icon-btn" title="Dismiss all" onclick="markFeedRead()"><i data-lucide="check-check"></i></button>
            </div>
        </div>

        <div class="feed-tabs">
            <button class="feed-tab active" data-tab="events" type="button">Events <span class="c">(<?= count($eventsFeed) ?>)</span></button>
            <button class="feed-tab" data-tab="sent" type="button">Sent <span class="c">(<?= count($sentFeed) ?>)</span></button>
            <button class="feed-tab" data-tab="failed" type="button">Failed <span class="c">(<?= count($failFeed) ?>)</span></button>
        </div>

        <div class="feed" data-feed="events"><?= $eventsFeed ? $feedEventsHtml : $emptyFeed ?></div>
        <div class="feed" data-feed="sent" style="display:none"><?= $sentFeed ? $feedSentHtml : $emptyFeed ?></div>
        <div class="feed" data-feed="failed" style="display:none"><?= $failFeed ? $feedFailHtml : $emptyFeed ?></div>
    </div>
</div>

<script>
// ---- Fleet map -------------------------------------------------
// Map is initialized from script.js once units data is published here.
window.__fleetMapUnits = <?= json_encode($mapUnits, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<?php 
    $cachePayload = [
        'openT' => $openT, 'openW' => $openW, 'openO' => $openO,
        'awaitUrg' => $awaitUrg, 'awaitT' => $awaitT, 'awaitW' => $awaitW, 'awaitO' => $awaitO,
        'readyUrg' => $readyUrg, 'readyT' => $readyT, 'readyW' => $readyW, 'readyO' => $readyO,
        'wipUrg' => $wipUrg, 'wipT' => $wipT, 'wipW' => $wipW, 'wipO' => $wipO,
        'doneT' => $doneT, 'doneW' => $doneW, 'doneO' => $doneO,
        'downToday' => $downToday, 'downWeek' => $downWeek,
        'presOnline' => $presOnline, 'presOffline' => $presOffline,
        'locUnits' => $locUnits, 'locOk' => $locOk, 'locOpen' => $locOpen, 'locDown' => $locDown,
        'eventsFeed' => $eventsFeed, 'sentFeed' => $sentFeed, 'failFeed' => $failFeed,
        'mapUnits' => $mapUnits,
        'actName' => $actName,
    ];
    DashboardCache::set($cacheKey, $cachePayload);
}
Layout::renderFoot(); ?>