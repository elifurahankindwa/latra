<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Layout.php';
require_once 'includes/Schema.php';
Auth::requireView('audit.view');
Schema::ensure();

// ---------------------------------------------------------------------------
// TAB PERMISSIONS — the portal exposes a curated set of compliance views.
// Internal admin/operator users hold every permission; LATRA roles are
// restricted server-side by Auth::hasPermission(). The UI only hides buttons.
// ---------------------------------------------------------------------------
$tabPerms = [
    'dashboard'   => 'dashboard.view',
    'live'        => 'live.view',
    'devices'     => 'devices.view',
    'gps'         => 'gps.view',
    'integration' => 'integration.view',
    'audit'       => 'audit.view',
    'reports'     => 'reports.view',
];
$tab = $_GET['tab'] ?? 'dashboard';
if (!isset($tabPerms[$tab]) || !Auth::hasPermission($tabPerms[$tab])) {
    $tab = 'dashboard';
}

$cfgInt = fn (string $k, int $d): int => Schema::configInt($k, $d);
$offlineSec   = $cfgInt('offline_seconds', 900);
$maxDelaySec  = $cfgInt('gps_max_delay_seconds', 300);
$minFreqSec   = $cfgInt('gps_min_frequency_seconds', 120);
$pollSec      = $cfgInt('poll_interval_seconds', 180);
$emergencySec = $cfgInt('emergency_interval_seconds', 1);
$defaultSpeed = (float) $cfgInt('default_speed_limit_kmh', 80);

// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------
function esc($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES); }

function laState(int $ageSec, int $ok, int $warn): string {
    if ($ageSec <= $ok) return 'ok';
    if ($ageSec <= $warn) return 'warning';
    return 'danger';
}

function laBadge(string $state, string $icon = ''): string {
    static $map = [
        'ok'        => ['success', 'circle-check'],
        'valid'     => ['success', 'check'],
        'online'    => ['success', 'wifi'],
        'healthy'   => ['success', 'heart-pulse'],
        'sent'      => ['success', 'send'],
        'running'   => ['success', 'play'],
        'current'   => ['success', 'loader-circle'],
        'end'       => ['success', 'flag'],
        'warning'   => ['warning', 'triangle-alert'],
        'pending'   => ['warning', 'hourglass'],
        'delayed'   => ['warning', 'clock'],
        'stale'     => ['warning', 'history'],
        'danger'    => ['danger', 'circle-x'],
        'offline'   => ['danger', 'wifi'],
        'failed'    => ['danger', 'x'],
        'rejected'  => ['danger', 'ban'],
        'invalid'   => ['danger', 'flag'],
        'active'    => ['danger', 'bell'],
        'secondary' => ['secondary', 'minus'],
        'unknown'   => ['secondary', 'circle-help'],
    ];
    [$cls, $defIcon] = $map[$state] ?? ['secondary', 'circle'];
    $ic = $icon !== '' ? $icon : $defIcon;
    return '<span class="badge badge-' . $cls . '"><i data-lucide="' . Icon::fixStripped($ic) . '"></i> ' . htmlspecialchars(ucfirst($state)) . '</span>';
}

// Human age helper.
function laAge(int $sec): string {
    if ($sec < 60) return $sec . 's';
    if ($sec < 3600) return (int) floor($sec / 60) . 'm';
    if ($sec < 86400) return (int) floor($sec / 3600) . 'h';
    return (int) floor($sec / 86400) . 'd';
}

// Generic pagination that preserves the current tab + filters.
function laPager(int $total, int $page, int $perPage, string $param): string {
    $totalPages = (int) max(1, ceil($total / $perPage));
    if ($totalPages <= 1) return '';
    $q = $_GET;
    foreach (['page', 'dpage', 'gpage', 'epage', 'mpage', 'ppage', 'apage', 'lpage'] as $k) unset($q[$k]);
    $u = fn (array $extra) => 'latra_audit?' . http_build_query(array_merge($q, $extra));
    $html = '<div class="pagination">';
    if ($page > 1) $html .= '<a href="' . esc($u([$param => $page - 1])) . '" class="page-link"><i data-lucide="chevron-left"></i></a>';
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    if ($start > 1) $html .= '<span class="page-ellipsis">…</span>';
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $page ? ' active' : '';
        $html .= '<a href="' . esc($u([$param => $i])) . '" class="page-link' . $active . '">' . $i . '</a>';
    }
    if ($end < $totalPages) $html .= '<span class="page-ellipsis">…</span>';
    if ($page < $totalPages) $html .= '<a href="' . esc($u([$param => $page + 1])) . '" class="page-link"><i data-lucide="chevron-right"></i></a>';
    return $html . '</div>';
}

// Convert a date (YYYY-MM-DD) to inclusive epoch boundaries (UTC-based math).
function laDayRange(string $from, string $to): array {
    $s = $from !== '' ? strtotime($from . ' 00:00:00') : strtotime('-7 days');
    $e = $to !== '' ? strtotime($to . ' 23:59:59') : time();
    if ($s === false) $s = strtotime('-7 days');
    if ($e === false) $e = time();
    if ($s > $e) [$s, $e] = [$e, $s];
    return [$s, $e];
}

// ---------------------------------------------------------------------------
// CSV REPORT EXPORT (streamed before any HTML output).
// ---------------------------------------------------------------------------
if ($tab === 'reports' && ($_GET['export'] ?? '') === 'csv') {
    if (!Auth::hasPermission('reports.view')) {
        Auth::requirePermission('reports.view');
    }
    $report = $_GET['report'] ?? '';
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    [$s, $e] = laDayRange($from, $to);

    $build = fn () => match ($report) {
        'gps' => latraReportGps($s, $e),
        'devices' => latraReportDevices(),
        'integration' => latraReportIntegration($s, $e),
        'compliance' => latraReportCompliance(),
        default => [[], []],
    };
    [$cols, $rows] = $build();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="latra_' . $report . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $cols, ',', '"', '\\');
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) $line[] = $r[$c] ?? '';
        fputcsv($out, $line, ',', '"', '\\');
    }
    fclose($out);
    Auth::audit('report.export', $report, 'LATRA CSV export');
    exit;
}

// ---------------------------------------------------------------------------
// REPORT QUERY BUILDERS (shared by preview + CSV export)
// ---------------------------------------------------------------------------
function latraReportGps(int $s, int $e): array {
    $cols = ['Vehicle', 'IMEI', 'GPS time', 'Lat', 'Lng', 'Speed km/h', 'Bearing', 'Sats', 'Activity', 'Status', 'Validation', 'Delivery delay s'];
    $rows = Database::fetchAll(
        "SELECT v.vehicle_reg_no, v.imei, m.wialon_msg_time, m.lat, m.lng, m.speed, m.bearing,
                m.satellite_count, m.activity_id, m.latra_status, COALESCE(m.validation_status,'ok') AS validation_status,
                (UNIX_TIMESTAMP(m.created_at) - m.wialon_msg_time) AS delay
         FROM message_log m JOIN vehicles v ON v.id = m.vehicle_id
         WHERE m.wialon_msg_time >= ? AND m.wialon_msg_time <= ?
         ORDER BY m.wialon_msg_time DESC LIMIT 20000",
        [$s, $e]
    );
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'Vehicle' => (string) $r['vehicle_reg_no'],
            'IMEI' => (string) $r['imei'],
            'GPS time' => date('Y-m-d H:i:s', (int) $r['wialon_msg_time']),
            'Lat' => $r['lat'] !== null ? round((float) $r['lat'], 6) : '',
            'Lng' => $r['lng'] !== null ? round((float) $r['lng'], 6) : '',
            'Speed km/h' => $r['speed'] !== null ? round((float) $r['speed'], 1) : '',
            'Bearing' => (string) ((float) ($r['bearing'] ?? 0)),
            'Sats' => (string) ((int) ($r['satellite_count'] ?? 0)),
            'Activity' => (string) ((int) ($r['activity_id'] ?? 0)),
            'Status' => (string) $r['latra_status'],
            'Validation' => (string) $r['validation_status'],
            'Delivery delay s' => (string) ((int) ($r['delay'] ?? 0)),
        ];
    }
    return [$cols, $data];
}

function latraReportDevices(): array {
    $cols = ['Vehicle', 'IMEI', 'HW', 'Online', 'Battery %', 'Battery mV', 'Power mV', 'Power OK', 'Tampered', 'Last comm', 'Last GPS'];
    $rows = Database::fetchAll(
        "SELECT v.vehicle_reg_no, v.imei, v.hw_type, d.is_online, d.internal_battery_pct, d.internal_battery_mv,
                d.external_power_mv, d.external_power_disconnected, d.tampered, d.last_comm_at, d.last_gps_at
         FROM vehicles v LEFT JOIN device_status d ON d.vehicle_id = v.id
         ORDER BY v.vehicle_reg_no"
    );
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'Vehicle' => (string) $r['vehicle_reg_no'],
            'IMEI' => (string) $r['imei'],
            'HW' => (string) $r['hw_type'],
            'Online' => $r['is_online'] ? 'yes' : 'no',
            'Battery %' => $r['internal_battery_pct'] !== null ? round((float) $r['internal_battery_pct'], 1) : '',
            'Battery mV' => (string) ((int) ($r['internal_battery_mv'] ?? 0) ?: ''),
            'Power mV' => (string) ((int) ($r['external_power_mv'] ?? 0) ?: ''),
            'Power OK' => $r['external_power_disconnected'] ? 'no' : 'yes',
            'Tampered' => $r['tampered'] ? 'yes' : 'no',
            'Last comm' => (string) $r['last_comm_at'],
            'Last GPS' => (string) $r['last_gps_at'],
        ];
    }
    return [$cols, $data];
}

function latraReportIntegration(int $s, int $e): array {
    $cols = ['Time', 'Vehicle', 'Activity', 'Payload bytes', 'Response', 'HTTP', 'Latency ms', 'Attempt'];
    $rows = Database::fetchAll(
        "SELECT p.created_at, v.vehicle_reg_no, p.activity_id, LENGTH(p.payload) AS plen,
                p.response_status, p.http_code, p.latency_ms, p.attempt
         FROM push_log p LEFT JOIN vehicles v ON v.id = p.vehicle_id
         WHERE UNIX_TIMESTAMP(p.created_at) >= ? AND UNIX_TIMESTAMP(p.created_at) <= ?
         ORDER BY p.id DESC LIMIT 20000",
        [$s, $e]
    );
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'Time' => (string) $r['created_at'],
            'Vehicle' => (string) $r['vehicle_reg_no'],
            'Activity' => (string) ((int) ($r['activity_id'] ?? 0)),
            'Payload bytes' => (string) ((int) ($r['plen'] ?? 0)),
            'Response' => (string) $r['response_status'],
            'HTTP' => (string) $r['http_code'],
            'Latency ms' => (string) ((int) ($r['latency_ms'] ?? 0)),
            'Attempt' => (string) ((int) ($r['attempt'] ?? 1)),
        ];
    }
    return [$cols, $data];
}

function latraReportCompliance(): array {
    $cols = ['Vehicle', 'IMEI', 'Last GPS', 'GPS age s', 'GPS state', 'Device', 'Device state', 'Msgs 24h', 'Delayed', 'Submission success', 'Emergencies'];
    $rows = Database::fetchAll(
        "SELECT v.id, v.vehicle_reg_no, v.imei, v.last_message_time, d.is_online, d.last_comm_at
         FROM vehicles v LEFT JOIN device_status d ON d.vehicle_id = v.id
         ORDER BY v.vehicle_reg_no"
    );
    $data = [];
    foreach ($rows as $r) {
        $vid = (int) $r['id'];
        $msgs = Database::fetch(
            "SELECT COUNT(*) AS c, SUM((UNIX_TIMESTAMP(created_at) - wialon_msg_time) > ?) AS delayed_cnt
             FROM message_log WHERE vehicle_id = ? AND wialon_msg_time >= ?",
            [$GLOBALS['maxDelaySec'] ?? 300, $vid, time() - 86400]
        );
        $sub = Database::fetch(
            "SELECT COUNT(*) AS c, SUM(response_status = '1') AS okc
             FROM push_log WHERE vehicle_id = ? AND UNIX_TIMESTAMP(created_at) >= ?",
            [$vid, time() - 86400]
        );
        $emg = Database::fetch("SELECT COUNT(*) AS c FROM emergency_events WHERE vehicle_id = ?", [$vid]);
        $now = time();
        $gpsAge = $r['last_message_time'] ? max(0, $now - (int) $r['last_message_time']) : null;
        $data[] = [
            'Vehicle' => (string) $r['vehicle_reg_no'],
            'IMEI' => (string) $r['imei'],
            'Last GPS' => $r['last_message_time'] ? date('Y-m-d H:i:s', (int) $r['last_message_time']) : '',
            'GPS age s' => $gpsAge !== null ? (string) $gpsAge : '',
            'GPS state' => $gpsAge === null ? 'none' : ($gpsAge <= ($GLOBALS['offlineSec'] ?? 900) ? 'ok' : 'offline'),
            'Device' => (string) ($r['is_online'] ?: 0),
            'Device state' => $r['is_online'] ? 'online' : 'offline',
            'Msgs 24h' => (string) ((int) ($msgs['c'] ?? 0)),
            'Delayed' => (string) ((int) ($msgs['delayed_cnt'] ?? 0)),
            'Submission success' => ($sub && (int) ($sub['c'] ?? 0) > 0) ? round(100 * ((int) ($sub['okc'] ?? 0) / (int) $sub['c']), 1) . '%' : 'no data',
            'Emergencies' => (string) ((int) ($emg['c'] ?? 0)),
        ];
    }
    return [$cols, $data];
}

// ---------------------------------------------------------------------------
// TAB NAV
// ---------------------------------------------------------------------------
$tabOrder = ['dashboard', 'live', 'devices', 'gps', 'integration', 'audit', 'reports'];
$tabIcons = [
    'dashboard' => 'gauge',
    'live' => 'map-pinned',
    'devices' => 'truck',
    'gps' => 'satellite',
    'integration' => 'workflow',
    'audit' => 'list-checks',
    'reports' => 'file-spreadsheet',
];
$tabLabels = [
    'dashboard' => 'Dashboard',
    'live' => 'Live monitoring',
    'devices' => 'Vehicles & devices',
    'gps' => 'GPS & events',
    'integration' => 'Integration',
    'audit' => 'Audit logs',
    'reports' => 'Reports',
];

// ===========================================================================
// DASHBOARD QUERIES
// ===========================================================================
$now = time();
// Chain-status inputs shared by Dashboard and Integration tabs.
$wialon = Database::fetch("SELECT MAX(UNIX_TIMESTAMP(last_sync_at)) AS ts FROM vehicles")['ts'] ?? null;
$wialonAge = $wialon ? max(0, $now - (int) $wialon) : null;
$latra = Database::fetch("SELECT MAX(UNIX_TIMESTAMP(created_at)) AS ts FROM push_log")['ts'] ?? null;
$latraAge = $latra ? max(0, $now - (int) $latra) : null;
$gpsIn = Database::fetch("SELECT MAX(wialon_msg_time) AS ts FROM message_log")['ts'] ?? null;
$gpsInTime = $gpsIn ? (int) $gpsIn : null;
$gpsInAge = $gpsInTime !== null ? max(0, $now - $gpsInTime) : null;
$evLast = json_decode((string) Schema::config('event_engine_last_run', '{}'), true);
$evTs = is_array($evLast) && isset($evLast['ts']) ? strtotime((string) $evLast['ts']) : null;
$evAge = $evTs ? max(0, $now - $evTs) : null;

if ($tab === 'dashboard') {
    // --- KPIs ---
    $kpi = Database::fetch(
        "SELECT SUM(latra_status = 'pending') AS pending, SUM(latra_status = 'failed') AS failed,
                SUM(latra_status = 'rejected') AS rejected, SUM(latra_status = 'sent') AS sent,
                SUM(validation_status = 'failed') AS invalid FROM message_log"
    );
    $emergencyActive = (int) (Database::fetch("SELECT COUNT(*) AS c FROM emergency_events WHERE status = 'active'")['c'] ?? 0);
    $deviceSummary = null;
    if (Database::fetch("SELECT 1 FROM device_status LIMIT 1")) {
        $deviceSummary = Database::fetch(
            "SELECT SUM(is_online) AS online, SUM(internal_battery_low) AS bat_low,
                    SUM(external_power_disconnected) AS pwr_off, SUM(tampered) AS tampered, COUNT(*) AS total
             FROM device_status"
        );
    }
    $vehTotal = (int) (Database::fetch("SELECT COUNT(*) AS c FROM vehicles")['c'] ?? 0);
    $vehWithPos = (int) (Database::fetch("SELECT COUNT(*) AS c FROM vehicles WHERE lat IS NOT NULL AND lng IS NOT NULL AND lat <> 0 AND lng <> 0")['c'] ?? 0);

    // --- GPS data health (last 24h) ---
    $gpsH = Database::fetch(
        "SELECT COUNT(*) AS total,
                SUM(lat IS NULL OR lng IS NULL OR lat = 0 OR lng = 0) AS missing,
                SUM(COALESCE(validation_status,'ok') = 'failed') AS invalid,
                SUM((UNIX_TIMESTAMP(created_at) - wialon_msg_time) > ?) AS delayed_cnt
         FROM message_log WHERE wialon_msg_time >= ?",
        [$maxDelaySec, $now - 86400]
    );

    // --- Integration today ---
    $dayStart = (int) strtotime(date('Y-m-d 00:00:00'));
    $integ = Database::fetch(
        "SELECT COUNT(*) AS received,
                SUM(data_processed_at IS NOT NULL) AS processed,
                SUM(latra_status = 'sent') AS sent,
                SUM(latra_status = 'failed') AS failed,
                SUM(latra_status = 'pending') AS pending
         FROM message_log WHERE wialon_msg_time >= ?",
        [$dayStart]
    );
    $pushToday = Database::fetch(
        "SELECT COUNT(*) AS attempts, SUM(response_status = '1') AS okc, ROUND(AVG(latency_ms)) AS avg_ms
         FROM push_log WHERE UNIX_TIMESTAMP(created_at) >= ?",
        [$dayStart]
    );

    // --- Recent activity ---
    $recent = Database::fetchAll(
        "SELECT 'gps' AS kind, v.vehicle_reg_no, m.wialon_msg_time AS ts, m.latra_status, COALESCE(m.validation_status,'ok') AS val,
                CONCAT(m.lat, ', ', m.lng) AS coords
         FROM message_log m JOIN vehicles v ON v.id = m.vehicle_id
         ORDER BY m.id DESC LIMIT 10"
    );
    $recentPush = Database::fetchAll(
        "SELECT 'push' AS kind, v.vehicle_reg_no, UNIX_TIMESTAMP(p.created_at) AS ts, COALESCE(p.response_status,'') AS latra_status,
                '' AS val, CONCAT(p.http_code, ' · ', p.latency_ms, 'ms') AS coords
         FROM push_log p LEFT JOIN vehicles v ON v.id = p.vehicle_id
         ORDER BY p.id DESC LIMIT 10"
    );
    $recent = array_merge($recent, $recentPush);
    usort($recent, fn ($a, $b) => (int) $b['ts'] <=> (int) $a['ts']);
    $recent = array_slice($recent, 0, 12);
}

// ===========================================================================
// LIVE MONITORING QUERIES
// ===========================================================================
if ($tab === 'live') {
    $liveSearch = trim((string) ($_GET['search'] ?? ''));
    $liveStatus = $_GET['status'] ?? 'all';
    if (!in_array($liveStatus, ['all', 'online', 'offline', 'moving', 'stale'], true)) $liveStatus = 'all';
    $liveMoveKmh = 5;

    $w = [];
    $p = [];
    if ($liveSearch !== '') {
        $w[] = '(v.vehicle_reg_no LIKE ? OR v.imei LIKE ? OR v.name LIKE ?)';
        array_push($p, "%$liveSearch%", "%$liveSearch%", "%$liveSearch%");
    }
    // Online scope is GPS-age based (a fix received inside the offline threshold).
    if ($liveStatus === 'online') $w[] = 'v.last_message_time >= ?';
    if ($liveStatus === 'offline') $w[] = '(v.last_message_time IS NULL OR v.last_message_time < ?)';
    if ($liveStatus === 'moving') { $w[] = 'v.last_message_time >= ?'; $w[] = 'v.speed >= ?'; }
    if ($liveStatus === 'stale') $w[] = 'v.last_message_time IS NOT NULL AND v.last_message_time < ?';
    if (in_array($liveStatus, ['online', 'offline', 'moving', 'stale'], true)) $p[] = $now - (int) $offlineSec;
    if ($liveStatus === 'moving') $p[] = $liveMoveKmh;

    $wSql = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
    $liveTotal = (int) (Database::fetch("SELECT COUNT(*) AS c FROM vehicles v $wSql", $p)['c'] ?? 0);

    $liveStats = Database::fetch(
        "SELECT COUNT(*) AS total,
                SUM(last_message_time >= ?) AS online_now,
                SUM(speed >= ? AND last_message_time >= ?) AS moving_now,
                SUM(last_message_time IS NULL OR last_message_time < ?) AS stale_now
         FROM vehicles v $wSql",
        $w ? array_merge($p, [$now - (int) $offlineSec, $liveMoveKmh, $now - (int) $offlineSec, $now - (int) $offlineSec]) : [$now - (int) $offlineSec, $liveMoveKmh, $now - (int) $offlineSec, $now - (int) $offlineSec]
    );
    $liveStats = [
        'total' => $liveTotal,
        'online' => (int) ($liveStats['online_now'] ?? 0),
        'moving' => (int) ($liveStats['moving_now'] ?? 0),
        'stale' => (int) ($liveStats['stale_now'] ?? 0),
    ];
    $liveEmg = (int) (Database::fetch("SELECT COUNT(*) AS c FROM emergency_events WHERE status = 'active'")['c'] ?? 0);

    $livePer = 20;
    $livePage = max(1, (int) ($_GET['lpage'] ?? 1));
    $liveRows = Database::fetchAll(
        "SELECT v.id, v.vehicle_reg_no, v.name, v.imei, v.hw_type, v.lat, v.lng, v.speed, v.satellite_count,
                v.last_message_time,
                (d.is_online = 1) AS dev_online
         FROM vehicles v LEFT JOIN device_status d ON d.vehicle_id = v.id
         $wSql ORDER BY v.last_message_time IS NULL, v.last_message_time DESC LIMIT ? OFFSET ?",
        array_merge($p, [$livePer, ($livePage - 1) * $livePer])
    );
    $mapSql = "SELECT id, vehicle_reg_no, name, lat, lng, speed, satellite_count, bearing, last_message_time
         FROM vehicles WHERE lat IS NOT NULL AND lng IS NOT NULL AND lat <> 0 AND lng <> 0";
    $mapParams = [];
    if ($liveSearch !== '') {
        $mapSql .= ' AND (vehicle_reg_no LIKE ? OR imei LIKE ? OR name LIKE ?)';
        $mapParams = ["%$liveSearch%", "%$liveSearch%", "%$liveSearch%"];
    }
    $mapSql .= ' ORDER BY last_message_time IS NULL, last_message_time DESC LIMIT 80';
    $mapUnits = [];
    foreach (Database::fetchAll($mapSql, $mapParams) as $u) {
        $age = $u['last_message_time'] ? max(0, $now - (int) $u['last_message_time']) : null;
        $u['color'] = $age === null
            ? '#9AA0A6'
            : ($age <= (int) $offlineSec ? '#2F9E57' : ($age <= (int) ($offlineSec * 2) ? '#F59E0B' : '#EF4444'));
        $u['moving'] = $u['speed'] !== null && $u['speed'] >= $liveMoveKmh && $age !== null && $age <= (int) $offlineSec ? 1 : 0;
        $mapUnits[] = $u;
    }
}

// ===========================================================================
// VEHICLES & DEVICES QUERIES
// ===========================================================================
if ($tab === 'devices') {
    $dSearch = trim((string) ($_GET['dsearch'] ?? ''));
    $dStatus = $_GET['dstatus'] ?? 'all';
    if (!in_array($dStatus, ['all', 'online', 'offline'], true)) $dStatus = 'all';

    $w = [];
    $p = [];
    if ($dSearch !== '') { $w[] = '(v.vehicle_reg_no LIKE ? OR v.imei LIKE ?)'; $p[] = "%$dSearch%"; $p[] = "%$dSearch%"; }
    if ($dStatus === 'online') $w[] = 'd.is_online = 1';
    if ($dStatus === 'offline') $w[] = '(d.is_online = 0 OR d.vehicle_id IS NULL)';
    $wSql = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
    $dTotal = (int) (Database::fetch("SELECT COUNT(*) AS c FROM vehicles v LEFT JOIN device_status d ON d.vehicle_id = v.id $wSql", $p)['c'] ?? 0);
    $dPer = 12;
    $dPage = max(1, (int) ($_GET['dpage'] ?? 1));
    $devices = Database::fetchAll(
        "SELECT v.id, v.vehicle_reg_no, v.name, v.imei, v.hw_type, v.speed_limit_kmh, v.driver_id, v.last_message_time,
                d.is_online, d.last_comm_at, d.internal_battery_pct, d.internal_battery_mv,
                d.internal_battery_low, d.external_power_disconnected, d.external_power_mv, d.tampered
         FROM vehicles v LEFT JOIN device_status d ON d.vehicle_id = v.id
         $wSql ORDER BY d.is_online DESC, v.vehicle_reg_no LIMIT ? OFFSET ?",
        array_merge($p, [$dPer, ($dPage - 1) * $dPer])
    );
}

// ===========================================================================
// GPS & EVENTS QUERIES
// ===========================================================================
if ($tab === 'gps') {
    $gVehicle = (int) ($_GET['gvehicle'] ?? 0);
    $gState   = $_GET['gstate'] ?? 'all';
    if (!in_array($gState, ['all', 'delayed', 'invalid', 'sent', 'failed', 'pending'], true)) $gState = 'all';
    [$gFrom, $gTo] = laDayRange(trim((string) ($_GET['gfrom'] ?? '')), trim((string) ($_GET['gto'] ?? '')));

    $w = ['m.wialon_msg_time >= ?', 'm.wialon_msg_time <= ?'];
    $p = [$gFrom, $gTo];
    if ($gVehicle > 0) { $w[] = 'm.vehicle_id = ?'; $p[] = $gVehicle; }
    if ($gState === 'delayed') { $w[] = '(UNIX_TIMESTAMP(m.created_at) - m.wialon_msg_time) > ?'; $p[] = $maxDelaySec; }
    if ($gState === 'invalid') $w[] = 'COALESCE(m.validation_status,\'ok\') = \'failed\'';
    if (in_array($gState, ['sent', 'failed', 'pending'], true)) { $w[] = 'm.latra_status = ?'; $p[] = $gState; }
    $wSql = 'WHERE ' . implode(' AND ', $w);
    $gTotal = (int) (Database::fetch("SELECT COUNT(*) AS c FROM message_log m $wSql", $p)['c'] ?? 0);
    $gPer = 20;
    $gPage = max(1, (int) ($_GET['gpage'] ?? 1));
    $gRecords = Database::fetchAll(
        "SELECT m.*, v.vehicle_reg_no, v.imei, UNIX_TIMESTAMP(m.created_at) AS created_ts FROM message_log m
         JOIN vehicles v ON v.id = m.vehicle_id $wSql
         ORDER BY m.wialon_msg_time DESC LIMIT ? OFFSET ?",
        array_merge($p, [$gPer, ($gPage - 1) * $gPer])
    );
    $gQuality = Database::fetch(
        "SELECT COUNT(*) AS total,
                SUM(lat IS NULL OR lng IS NULL OR lat = 0 OR lng = 0) AS missing,
                SUM(COALESCE(validation_status,'ok') = 'failed') AS invalid,
                SUM((UNIX_TIMESTAMP(created_at) - wialon_msg_time) > ?) AS delayed_cnt,
                ROUND(AVG(satellite_count), 1) AS avg_sats,
                ROUND(AVG(speed)) AS avg_speed
         FROM message_log WHERE wialon_msg_time >= ?",
        [$maxDelaySec, $now - 86400]
    );

    // Events
    $evPer = 10;
    $evPage = max(1, (int) ($_GET['epage'] ?? 1));
    $evTotal = (int) (Database::fetch("SELECT COUNT(*) AS c FROM wialon_events")['c'] ?? 0);
    $events = Database::fetchAll(
        "SELECT e.*, v.vehicle_reg_no FROM wialon_events e LEFT JOIN vehicles v ON v.id = e.vehicle_id
         ORDER BY e.wialon_msg_time DESC LIMIT ? OFFSET ?",
        [$evPer, ($evPage - 1) * $evPer]
    );
    $emPer = 10;
    $emPage = max(1, (int) ($_GET['mpage'] ?? 1));
    $emTotal = (int) (Database::fetch("SELECT COUNT(*) AS c FROM emergency_events")['c'] ?? 0);
    $emEvents = Database::fetchAll(
        "SELECT e.*, v.vehicle_reg_no FROM emergency_events e LEFT JOIN vehicles v ON v.id = e.vehicle_id
         ORDER BY e.started_at DESC LIMIT ? OFFSET ?",
        [$emPer, ($emPage - 1) * $emPer]
    );
}

// ===========================================================================
// INTEGRATION QUERIES
// ===========================================================================
if ($tab === 'integration') {
    $pSearch = trim((string) ($_GET['psearch'] ?? ''));
    $pResult = $_GET['presult'] ?? 'all';
    if (!in_array($pResult, ['all', 'ok', 'fail'], true)) $pResult = 'all';

    $w = [];
    $p = [];
    if ($pSearch !== '') { $w[] = 'v.vehicle_reg_no LIKE ?'; $p[] = "%$pSearch%"; }
    if ($pResult === 'ok') $w[] = 'p.response_status = \'1\'';
    if ($pResult === 'fail') $w[] = '(p.response_status IS NULL OR p.response_status <> \'1\')';
    $wSql = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
    $pTotal = (int) (Database::fetch("SELECT COUNT(*) AS c FROM push_log p LEFT JOIN vehicles v ON v.id = p.vehicle_id $wSql", $p)['c'] ?? 0);
    $pPer = 20;
    $pPage = max(1, (int) ($_GET['ppage'] ?? 1));
    $pushRows = Database::fetchAll(
        "SELECT p.*, v.vehicle_reg_no FROM push_log p LEFT JOIN vehicles v ON v.id = p.vehicle_id
         $wSql ORDER BY p.id DESC LIMIT ? OFFSET ?",
        array_merge($p, [$pPer, ($pPage - 1) * $pPer])
    );

    $dayStart = (int) strtotime(date('Y-m-d 00:00:00'));
    $integ = Database::fetch(
        "SELECT COUNT(*) AS received,
                SUM(data_processed_at IS NOT NULL) AS processed,
                SUM(latra_status = 'sent') AS sent,
                SUM(latra_status = 'failed') AS failed,
                SUM(latra_status = 'pending') AS pending
         FROM message_log WHERE wialon_msg_time >= ?",
        [$dayStart]
    );
    $jobRows = Database::fetchAll(
        "SELECT job_key, label, interval_seconds, is_enabled, last_run_at, next_run_at, last_status, last_error, run_count
         FROM jobs ORDER BY is_enabled DESC, job_key"
    );
    $latra = Database::fetch("SELECT MAX(UNIX_TIMESTAMP(created_at)) AS ts FROM push_log")['ts'] ?? null;
}

// ===========================================================================
// AUDIT QUERIES
// ===========================================================================
if ($tab === 'audit') {
    $aUser = trim((string) ($_GET['auser'] ?? ''));
    $aAction = trim((string) ($_GET['aaction'] ?? ''));
    [$aFrom, $aTo] = laDayRange(trim((string) ($_GET['afrom'] ?? '')), trim((string) ($_GET['ato'] ?? '')));
    $w = ['UNIX_TIMESTAMP(created_at) >= ?', 'UNIX_TIMESTAMP(created_at) <= ?'];
    $p = [$aFrom, $aTo];
    if ($aUser !== '') { $w[] = '(username LIKE ? OR ip LIKE ?)'; $p[] = "%$aUser%"; $p[] = "%$aUser%"; }
    if ($aAction !== '') { $w[] = 'action LIKE ?'; $p[] = '%' . $aAction . '%'; }
    $wSql = 'WHERE ' . implode(' AND ', $w);
    $aTotal = (int) (Database::fetch("SELECT COUNT(*) AS c FROM audit_log $wSql", $p)['c'] ?? 0);
    $aPer = 20;
    $aPage = max(1, (int) ($_GET['apage'] ?? 1));
    $aRows = Database::fetchAll(
        "SELECT * FROM audit_log $wSql ORDER BY id DESC LIMIT ? OFFSET ?",
        array_merge($p, [$aPer, ($aPage - 1) * $aPer])
    );
    $aUsers = Database::fetchAll("SELECT DISTINCT username FROM audit_log WHERE username IS NOT NULL AND username <> '' ORDER BY username");
    $aActs = Database::fetchAll("SELECT action, COUNT(*) AS c FROM audit_log GROUP BY action ORDER BY c DESC LIMIT 30");
}

// ===========================================================================
// REPORTS QUERIES (preview)
// ===========================================================================
if ($tab === 'reports') {
    $report = $_GET['report'] ?? 'gps';
    if (!in_array($report, ['gps', 'devices', 'integration', 'compliance'], true)) $report = 'gps';
    [$rFrom, $rTo] = laDayRange(trim((string) ($_GET['from'] ?? '')), trim((string) ($_GET['to'] ?? '')));
    if ($report === 'gps')       [$rCols, $rRows] = latraReportGps($rFrom, $rTo);
    elseif ($report === 'devices') [$rCols, $rRows] = latraReportDevices();
    elseif ($report === 'integration') [$rCols, $rRows] = latraReportIntegration($rFrom, $rTo);
    else                                                     [$rCols, $rRows] = latraReportCompliance();
    $rRows = array_slice($rRows, 0, 50);
}

// ===========================================================================
// RENDER
// ===========================================================================
$pageTitle = 'LATRA Compliance';
Layout::renderHead($pageTitle, $tab === 'live');
?>
<div class="page-header">
    <h1><i data-lucide="clipboard-check"></i> LATRA Access &amp; Compliance</h1>
    <p class="text-muted">Evidence monitoring for GPS data quality, device health, LATRA submissions and user activity.</p>
</div>

<div class="feed-tabs la-tabs">
    <?php foreach ($tabOrder as $t): if (!Auth::hasPermission($tabPerms[$t])) continue; ?>
        <a class="feed-tab<?= $tab === $t ? ' active' : '' ?>" href="latra_audit?tab=<?= $t ?>"><i data-lucide="<?= Icon::fixStripped($tabIcons[$t]) ?>"></i> <?= $tabLabels[$t] ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'dashboard'): ?>
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i data-lucide="truck"></i></div>
        <div class="stat-info"><span class="stat-value"><?= $vehTotal ?></span><span class="stat-label">Vehicles · <?= $vehWithPos ?> with position</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-success"><i data-lucide="wifi"></i></div>
        <div class="stat-info"><span class="stat-value"><?= (int) ($deviceSummary['online'] ?? 0) ?>/<?= (int) ($deviceSummary['total'] ?? 0) ?></span><span class="stat-label">Devices online</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-danger"><i data-lucide="triangle-alert"></i></div>
        <div class="stat-info"><span class="stat-value"><?= (int) ($kpi['invalid'] ?? 0) ?></span><span class="stat-label">Rejected by validation</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-warning"><i data-lucide="inbox"></i></div>
        <div class="stat-info"><span class="stat-value"><?= (int) ($kpi['pending'] ?? 0) ?></span><span class="stat-label">Pending · <?= (int) ($kpi['failed'] ?? 0) ?> failed · <?= (int) ($kpi['sent'] ?? 0) ?> sent</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-danger"><i data-lucide="bell"></i></div>
        <div class="stat-info"><span class="stat-value"><?= (int) $emergencyActive ?></span><span class="stat-label">Active emergencies</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i data-lucide="send"></i></div>
        <div class="stat-info"><span class="stat-value"><?= (int) ($integ['sent'] ?? 0) ?></span><span class="stat-label">Submitted today · <?= (int) ($integ['received'] ?? 0) ?> received</span></div>
    </div>
</div>

<div class="dashboard-row">
    <div class="dashboard-col-8">
        <div class="card">
            <div class="card-header"><h3><i data-lucide="shield"></i> GPS data health · last 24h</h3></div>
            <div class="table-responsive"><table class="data-table">
                <tbody>
                <tr><th>Records received</th><td><strong><?= (int) ($gpsH['total'] ?? 0) ?></strong> GPS fixes stored in the last 24 hours</td><td><?= laBadge((int) ($gpsH['total'] ?? 0) > 0 ? 'ok' : 'warning', 'database') ?></td></tr>
                <tr><th>Missing / invalid coordinates</th><td><?= (int) ($gpsH['missing'] ?? 0) ?> records with null or zero lat/lng (<?= $gpsH['total'] ? round(100 * (int) $gpsH['missing'] / (int) $gpsH['total'], 1) : 0 ?>%)</td><td><?= laBadge((int) ($gpsH['missing'] ?? 0) === 0 ? 'ok' : 'warning', 'map-pin') ?></td></tr>
                <tr><th>Validation failures</th><td><?= (int) ($gpsH['invalid'] ?? 0) ?> records failed validation checks</td><td><?= laBadge((int) ($gpsH['invalid'] ?? 0) === 0 ? 'ok' : 'invalid') ?></td></tr>
                <tr><th>Delayed delivery</th><td><?= (int) ($gpsH['delayed_cnt'] ?? 0) ?> records took more than <?= $maxDelaySec ?>s from GPS fix to server</td><td><?= laBadge((int) ($gpsH['delayed_cnt'] ?? 0) === 0 ? 'ok' : 'delayed') ?></td></tr>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="dashboard-col-4">
        <div class="card">
            <div class="card-header"><h3><i data-lucide="workflow"></i> Integration · today</h3></div>
            <div class="table-responsive"><table class="data-table">
                <tbody>
                <tr><th>Received</th><td><?= (int) ($integ['received'] ?? 0) ?></td></tr>
                <tr><th>Processed</th><td><?= (int) ($integ['processed'] ?? 0) ?></td></tr>
                <tr><th>Submitted to LATRA</th><td><?= (int) ($integ['sent'] ?? 0) ?></td></tr>
                <tr><th>Failed</th><td><?= (int) ($integ['failed'] ?? 0) ?></td></tr>
                <tr><th>Pending</th><td><?= (int) ($integ['pending'] ?? 0) ?></td></tr>
                <tr><th>Push attempts / success</th><td><?= (int) ($pushToday['attempts'] ?? 0) ?> / <?= (int) ($pushToday['okc'] ?? 0) ?><?= $pushToday['avg_ms'] ? ' · avg ' . (int) $pushToday['avg_ms'] . 'ms' : '' ?></td></tr>
                </tbody>
            </table></div>
        </div>
    </div>
</div>


<div class="card mt-2">
    <div class="card-header"><h3><i data-lucide="rotate-cw"></i> Recent activity</h3></div>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>When</th><th>Vehicle</th><th>Kind</th><th>Status</th><th>Validation</th><th>Detail</th></tr></thead><tbody>
        <?php foreach ($recent as $r): ?>
            <tr>
                <td class="text-muted"><?= date('Y-m-d H:i:s', (int) $r['ts']) ?></td>
                <td><?= esc($r['vehicle_reg_no'] ?? '-') ?></td>
                <td><span class="badge badge-info"><i data-lucide="<?= Icon::fixStripped($r['kind'] === 'push' ? 'paper-plane' : 'satellite') ?>"></i> <?= $r['kind'] === 'push' ? 'LATRA push' : 'GPS record' ?></span></td>
                <td><?= $r['kind'] === 'push' ? (($r['latra_status'] === '1') ? laBadge('sent', 'check') : laBadge('failed', 'times')) : laBadge((string) $r['latra_status']) ?></td>
                <td><?= $r['val'] !== '' ? esc($r['val']) : '-' ?></td>
                <td class="text-muted"><?= esc($r['coords'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="6" class="empty-state"><i data-lucide="inbox"></i><p>No activity yet</p></td></tr><?php endif; ?>
        </tbody></table></div>
</div>

<?php elseif ($tab === 'live'): ?>
<div class="filter-bar">
    <form method="get">
        <input type="hidden" name="tab" value="live">
        <label><strong>Search</strong></label>
        <input type="text" name="search" class="form-input" placeholder="Reg no / IMEI / name" value="<?= esc($liveSearch) ?>">
        <label><strong>Status</strong></label>
        <select name="status" class="form-input">
            <?php foreach (['all' => 'All', 'online' => 'Online (GPS within threshold)', 'moving' => 'Moving', 'stale' => 'Stale / offline', 'offline' => 'No position fix'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $liveStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Apply</button>
        <a href="latra_audit?tab=live" class="btn btn-secondary"><i data-lucide="rotate-cw"></i> Reset</a>
        <button type="button" class="btn btn-secondary" onclick="location.reload()" title="Refresh now"><i data-lucide="rotate-cw"></i></button>
        <label class="live-autorefresh" style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;">
            <input type="checkbox" id="liveAutoRef"> <strong>Auto-refresh 30s</strong>
        </label>
    </form>
</div>

<div class="stats-row lf-stats">
    <div class="stat-card">
        <div class="stat-icon bg-success"><i data-lucide="wifi"></i></div>
        <div class="stat-info"><span class="stat-value"><?= (int) $liveStats['online'] ?> / <?= (int) $liveStats['total'] ?></span><span class="stat-label">Online now (GPS within <?= $offlineSec ?>s)</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-primary"><i data-lucide="play"></i></div>
        <div class="stat-info"><span class="stat-value"><?= (int) $liveStats['moving'] ?></span><span class="stat-label">Moving now (>= 5 km/h)</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-warning"><i data-lucide="hourglass"></i></div>
        <div class="stat-info"><span class="stat-value"><?= (int) $liveStats['stale'] ?></span><span class="stat-label">Stale / no recent fix</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-danger"><i data-lucide="bell"></i></div>
        <div class="stat-info"><span class="stat-value"><?= (int) $liveEmg ?></span><span class="stat-label">Active emergencies</span></div>
    </div>
</div>

<div class="dashboard-row lf-row">
    <div class="dashboard-col-7">
        <div class="card">
            <div class="card-header"><h3><i data-lucide="map-pinned"></i> Fleet locations <span class="badge badge-info"><?= count($mapUnits) ?> on map</span></h3>
                <span class="text-muted">
                    <span class="lf-legend"><span class="status-dot ok"></span>Online</span>
                    <span class="lf-legend"><span class="status-dot warn"></span>Warning</span>
                    <span class="lf-legend"><span class="status-dot bad"></span>Stale</span>
                    <span class="lf-legend"><span class="status-dot none"></span>No fix</span>
                </span>
            </div>
            <div class="map-wrap-lg">
                <div class="map-seg">
                    <button class="active" data-layer="map" type="button">Map</button>
                    <button data-layer="sat" type="button">Satellite</button>
                </div>
                <div id="fleetMap"></div>
            </div>
        </div>
    </div>
    <div class="dashboard-col-5">
        <div class="card">
            <div class="card-header"><h3><i data-lucide="truck"></i> Live vehicle feed <span class="badge badge-info"><?= $liveTotal ?></span></h3>
                <span class="text-muted">Ordered by most recent GPS fix</span></div>
            <div class="table-responsive lf-feed-body"><table class="data-table">
                <thead><tr><th>Status</th><th>Vehicle</th><th>Speed</th><th>GPS age</th><th>Sats</th></tr></thead><tbody>
                <?php foreach ($liveRows as $v): $age = $v['last_message_time'] ? max(0, $now - (int) $v['last_message_time']) : null; ?>
                    <tr>
                        <td>
                            <?php if ($age === null): ?>
                                <span class="status-dot none" title="No position fix"></span><span class="text-muted">No fix</span>
                            <?php elseif ($age <= $offlineSec): ?>
                                <span class="status-dot ok" title="Online"></span><span class="badge badge-success"><i data-lucide="wifi"></i> Online</span>
                            <?php else: ?>
                                <span class="status-dot bad" title="Stale"></span><span class="badge badge-danger"><i data-lucide="wifi"></i> Stale</span>
                            <?php endif; ?>
                        </td>
                        <td class="v-cell">
                            <a href="vehicle_detail?id=<?= (int) $v['id'] ?>"><?= esc($v['vehicle_reg_no']) ?></a>
                            <br><small class="text-muted"><?= esc($v['name'] ?? '') ?></small>
                        </td>
                        <td>
                            <?php if ($v['speed'] !== null && $v['speed'] >= 5 && $age !== null && $age <= $offlineSec): ?>
                                <strong><?= round((float) $v['speed'], 1) ?> <small>km/h</small></strong> <span class="badge badge-primary"><i data-lucide="play"></i> Moving</span>
                            <?php else: ?>
                                <span class="text-muted"><?= $v['speed'] !== null ? round((float) $v['speed'], 1) . ' km/h' : '-' ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($v['last_message_time']): ?>
                                <?= laBadge($age <= $offlineSec ? 'ok' : ($age <= ($offlineSec * 2) ? 'warning' : 'danger'), 'clock') ?> <span class="text-muted"><?= laAge($age) ?> · <?= date('H:i:s', (int) $v['last_message_time']) ?></span>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td><?= (int) ($v['satellite_count'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$liveRows): ?><tr><td colspan="5" class="empty-state"><i data-lucide="inbox"></i><p>No vehicles found</p></td></tr><?php endif; ?>
                </tbody></table></div>
            <?= laPager($liveTotal, $livePage, $livePer, 'lpage') ?>
        </div>
    </div>
</div>
<script>window.__fleetMapUnits = <?= json_encode($mapUnits, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script>
(function () {
    const cb = document.getElementById('liveAutoRef');
    if (!cb) return;
    cb.checked = sessionStorage.getItem('latra_live_autoref') === '1';
    const tick = function () {
        if (!cb.checked) return;
        const n = parseInt(sessionStorage.getItem('latra_live_countdown') || '30', 10);
        const m = (n <= 0) ? 30 : n;
        sessionStorage.setItem('latra_live_countdown', String(m - 1));
        if (m <= 1) { location.reload(); return; }
        setTimeout(tick, 1000);
    };
    cb.addEventListener('change', function () {
        sessionStorage.setItem('latra_live_autoref', cb.checked ? '1' : '0');
        if (cb.checked) {
            sessionStorage.setItem('latra_live_countdown', '30');
            tick();
        }
    });
    if (cb.checked) tick();
})();
</script>

<?php elseif ($tab === 'devices'): ?>
<div class="filter-bar">
    <form method="get">
        <input type="hidden" name="tab" value="devices">
        <label><strong>Search</strong></label>
        <input type="text" name="dsearch" class="form-input" placeholder="Reg no / IMEI" value="<?= esc($dSearch) ?>">
        <label><strong>Status</strong></label>
        <select name="dstatus" class="form-input">
            <?php foreach (['all' => 'All', 'online' => 'Online', 'offline' => 'Offline'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $dStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Apply</button>
        <a href="latra_audit?tab=devices" class="btn btn-secondary"><i data-lucide="rotate-cw"></i> Reset</a>
    </form>
</div>

<div class="card mt-2">
    <div class="card-header"><h3><i data-lucide="truck"></i> Vehicles &amp; devices <span class="badge badge-info"><?= $dTotal ?> vehicles</span></h3>
        <span class="text-muted">Read-only status. Interrogation returns the latest stored device snapshot and is audited.</span>
    </div>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>Vehicle</th><th>Device</th><th>Status</th><th>Battery</th><th>Power</th><th>Tamper</th><th>Last comm</th><th>Speed limit</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($devices as $d): $vid = (int) $d['id']; ?>
            <tr>
                <td><a href="vehicle_detail?id=<?= $vid ?>"><?= esc($d['vehicle_reg_no']) ?></a><br><small class="text-muted"><?= esc($d['name'] ?? '') ?></small></td>
                <td><span class="text-muted"><?= esc($d['imei'] ?? '-') ?></span><br><small><?= esc($d['hw_type'] ?? '') ?></small></td>
                <td><?php if ($d['is_online'] !== null): echo laBadge($d['is_online'] ? 'online' : 'offline', 'wifi'); else: echo laBadge('unknown', 'question'); endif; ?></td>
                <td>
                    <?php if ($d['internal_battery_pct'] !== null): ?>
                        <span class="badge badge-<?= $d['internal_battery_low'] ? 'danger' : 'success' ?>"><i data-lucide="battery-medium"></i> <?= round((float) $d['internal_battery_pct']) ?>%</span>
                    <?php else: ?><span class="text-muted">n/a</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($d['external_power_disconnected'] == 1): ?><span class="badge badge-danger"><i data-lucide="plug-zap"></i> Disconnected</span>
                    <?php elseif ($d['external_power_mv'] !== null): ?><span class="badge badge-success"><i data-lucide="plug"></i> <?= round((float) $d['external_power_mv']) ?> mV</span>
                    <?php else: ?><span class="text-muted">n/a</span><?php endif; ?>
                </td>
                <td><?= $d['tampered'] ? '<span class="badge badge-danger"><i data-lucide="shield-check"></i> Tampered</span>' : 'No' ?></td>
                <td class="text-muted"><?= esc($d['last_comm_at'] ?? '-') ?></td>
                <td><?= $d['speed_limit_kmh'] !== null ? round((float) $d['speed_limit_kmh'], 1) . ' km/h' : esc((string) round($defaultSpeed, 1)) . ' km/h (default)' ?></td>
                <td>
                    <a href="vehicle_detail?id=<?= $vid ?>" class="btn btn-sm btn-secondary" title="View detail &amp; history"><i data-lucide="eye"></i> View</a>
                    <?php if (Auth::hasPermission('device.interrogate')): ?>
                        <button type="button" class="btn btn-sm btn-primary" onclick="interrogateDevice(<?= $vid ?>, '<?= esc($d['vehicle_reg_no']) ?>')" title="Read latest stored device state (audited)"><i data-lucide="satellite-dish"></i> Interrogate</button>
                    <?php endif; ?>
                    <?php if (Auth::hasPermission('device.parameters.view')): ?>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="showDeviceParams(this)" data-vid="<?= $vid ?>" data-params="<?= esc(json_encode([
                            'reg' => (string) $d['vehicle_reg_no'],
                            'imei' => (string) ($d['imei'] ?? ''),
                            'hw' => (string) ($d['hw_type'] ?? ''),
                            'battery' => $d['internal_battery_pct'] !== null ? round((float) $d['internal_battery_pct'], 1) . '%' : '',
                            'power' => $d['external_power_disconnected'] == 1 ? 'Disconnected' : ($d['external_power_mv'] !== null ? round((float) $d['external_power_mv']) . ' mV' : ''),
                            'lastComm' => (string) ($d['last_comm_at'] ?? ''),
                            'tamper' => (bool) $d['tampered'],
                            'driverId' => (string) ($d['driver_id'] ?? ''),
                        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>" title="Device parameters (read-only)"><i data-lucide="sliders-horizontal"></i> Params</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$devices): ?><tr><td colspan="9" class="empty-state"><i data-lucide="inbox"></i><p>No devices found</p></td></tr><?php endif; ?>
        </tbody></table></div>
    <?= laPager($dTotal, $dPage, $dPer, 'dpage') ?>
</div>

<script>
function laEsc(v) {
    return String(v === null || v === undefined ? '-' : v)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function laRow(th, td) {
    return '<tr><th style="padding:5px 9px;text-align:left;color:#64748B;font-weight:600;white-space:nowrap;vertical-align:top">' + laEsc(th) + '</th><td style="padding:5px 9px">' + td + '</td></tr>';
}
function laNice(ts) {
    if (!ts) return '-';
    const d = new Date(ts * 1000);
    return d.toLocaleString() + ' (' + laAgeSec(Math.floor(Date.now() / 1000) - ts) + ' ago)';
}
function laAgeSec(sec) {
    if (sec < 0) sec = 0;
    if (sec < 60) { return sec + 's'; }
    if (sec < 3600) { return Math.floor(sec / 60) + 'm'; }
    if (sec < 86400) { return Math.floor(sec / 3600) + 'h'; }
    return Math.floor(sec / 86400) + 'd';
}

function interrogateDevice(id, label) {
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;
    Swal.fire({
        title: 'Interrogate ' + label,
        text: 'This returns the latest stored device state (read-backed) and records an audit entry. No live device command is issued.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Interrogate',
        cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        preConfirm: function () {
            const fd = new FormData();
            fd.append('_csrf', csrf);
            fd.append('vehicle_id', id);
            return fetch('api.php?action=latra_interrogate', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) throw new Error(data.error || 'Interrogation failed.');
                    return data;
                });
        }
    }).then(function (result) {
        if (!result.isConfirmed || !result.value) return;
        const s = result.value.snapshot || {};
        const v = s.vehicle || {};
        const de = s.device || {};
        const m = s.latest_message || {};
        const rows = [
            laRow('Vehicle', '<strong>' + laEsc(v.vehicle_reg_no || label) + '</strong><br><small style="color:#64748B">' + laEsc(v.name || '') + '</small>'),
            laRow('IMEI', laEsc(v.imei)),
            laRow('Device', laEsc(v.hw_type)),
            laRow('Phone', laEsc(v.phone)),
            laRow('Active', v.is_active ? '<span style="color:#15803D">Yes</span>' : '<span style="color:#B91C1C">No</span>'),
            laRow('Data age', s.stale ? '<b style="color:#B45309">STALE</b> (past offline threshold)' : '<b style="color:#15803D">CURRENT</b>'),
            laRow('Device online', de.is_online ? '<span style="color:#15803D">Online</span>' : '<span style="color:#B91C1C">Offline</span>'),
            laRow('Last device comm', laEsc(de.last_comm_at)),
            laRow('Device status', laEsc(de.last_status)),
            laRow('Battery',
                de.internal_battery_pct !== null && de.internal_battery_pct !== undefined
                    ? laEsc(de.internal_battery_pct) + '%' + (de.internal_battery_mv ? ' / ' + laEsc(de.internal_battery_mv) + ' mV' : '')
                        + (de.internal_battery_low ? ' <span style="color:#B45309">(low)</span>' : '')
                    : '-'),
            laRow('Power', de.external_power_disconnected
                ? '<span style="color:#B91C1C">Disconnected</span>'
                : (de.external_power_mv ? laEsc(de.external_power_mv) + ' mV' : '-')),
            laRow('Tampered', de.tampered ? '<span style="color:#B91C1C">Yes</span>' : 'No'),
            laRow('Last GPS fix', m.wialon_msg_time ? laNice(m.wialon_msg_time) : '-'),
            laRow('Position', m.lat !== null && m.lat !== undefined ? laEsc(m.lat) + ', ' + laEsc(m.lng) + (m.altitude ? ' · ' + laEsc(m.altitude) + ' m' : '') : '-'),
            laRow('Speed', m.speed !== null && m.speed !== undefined ? laEsc(m.speed) + ' km/h' : '-'),
            laRow('Bearing / Sats', (m.bearing !== null && m.bearing !== undefined ? laEsc(m.bearing) + '°' : '-') + ' · ' + (m.satellite_count !== null && m.satellite_count !== undefined ? laEsc(m.satellite_count) + ' sats' : '-')),
            laRow('Activity', m.activity_id !== null && m.activity_id !== undefined ? laEsc(m.activity_id) : '-'),
            laRow('Validation', laEsc(m.validation_status || '-')),
            laRow('LATRA status', m.latra_status !== null && m.latra_status !== undefined && m.latra_status !== '' ? '<span style="color:#15803D">' + laEsc(m.latra_status) + '</span>' + (m.latra_response ? '<br><small style="color:#64748B">' + laEsc(String(m.latra_response).substring(0, 80)) + '</small>' : '') : '-'),
            laRow('Odometer', (v.odometer !== null && v.odometer !== undefined) || (m.odometer !== null && m.odometer !== undefined) ? laEsc((v.odometer || m.odometer)) + ' km' : '-'),
            laRow('Speed limit', v.speed_limit_kmh !== null && v.speed_limit_kmh !== undefined ? laEsc(v.speed_limit_kmh) + ' km/h' : '-'),
            laRow('Last fleet sync', laEsc(v.last_sync_at)),
        ].join('');
        Swal.fire({
            title: 'Interrogation result · ' + laEsc(label),
            html: '<div style="text-align:left;font-size:13px;max-height:480px;overflow:auto"><table style="width:100%;border-collapse:collapse"><tbody>' + rows + '</tbody></table></div>',
            width: 560
        });
    }).catch(function (err) {
        Swal.fire('Error', err.message || 'Unable to reach server.', 'error');
    });
}

function showDeviceParams(btn) {
    let p = {};
    try { p = JSON.parse(btn.dataset.params || '{}'); } catch (e) { p = {}; }
    const rows = [
        ['Vehicle', p.reg], ['IMEI', p.imei], ['Device (HW)', p.hw], ['Driver id', p.driverId],
        ['Battery', p.battery || '-'], ['Power', p.power || '-'],
        ['Last comm', p.lastComm || '-'], ['Tampered', p.tamper ? 'Yes' : 'No'],
        ['GPS reporting interval', '<?= (int) $pollSec ?>s (platform)'],
        ['Emergency interval', '<?= (int) $emergencySec ?>s (platform)'],
        ['Offline threshold', '<?= (int) $offlineSec ?>s (platform)'],
        ['Compliance GPS delay max', '<?= (int) $maxDelaySec ?>s (platform)'],
    ].map(function (x) {
        return laRow(x[0], laEsc(x[1]));
    }).join('');
    Swal.fire({
        title: 'Device parameters · read-only',
        html: '<div style="text-align:left;font-size:13px;max-height:420px;overflow:auto"><table style="width:100%;border-collapse:collapse"><tbody>' + rows + '</tbody></table>'
            + '<p style="margin-top:8px;font-style:italic;color:#B45309">Parameter changes are a platform (admin) action and are not issued from this portal.</p></div>',
        width: 560
    });
}
</script>

<?php elseif ($tab === 'gps'): ?>
<?php $vehicleOpts = Database::fetchAll('SELECT id, vehicle_reg_no FROM vehicles ORDER BY vehicle_reg_no'); ?>
<div class="filter-bar">
    <form method="get">
        <input type="hidden" name="tab" value="gps">
        <label><strong>Vehicle</strong></label>
        <select name="gvehicle" class="form-input">
            <option value="0">All vehicles</option>
            <?php foreach ($vehicleOpts as $vo): ?>
                <option value="<?= (int) $vo['id'] ?>" <?= $gVehicle === (int) $vo['id'] ? 'selected' : '' ?>><?= esc($vo['vehicle_reg_no']) ?></option>
            <?php endforeach; ?>
        </select>
        <label><strong>State</strong></label>
        <select name="gstate" class="form-input">
            <?php foreach (['all' => 'All', 'delayed' => 'Delayed delivery', 'invalid' => 'Validation failed', 'sent' => 'Submitted', 'failed' => 'Failed', 'pending' => 'Pending'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $gState === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="gfrom" class="form-input" title="From" value="<?= esc($_GET['gfrom'] ?? '') ?>">
        <input type="date" name="gto" class="form-input" title="To" value="<?= esc($_GET['gto'] ?? '') ?>">
        <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Apply</button>
        <a href="latra_audit?tab=gps" class="btn btn-secondary"><i data-lucide="rotate-cw"></i> Reset</a>
    </form>
</div>

<div class="dashboard-row mt-2">
    <div class="dashboard-col-3"><div class="stat-card"><div class="stat-icon bg-primary"><i data-lucide="database"></i></div><div class="stat-info"><span class="stat-value"><?= (int) ($gQuality['total'] ?? 0) ?></span><span class="stat-label">Records 24h</span></div></div></div>
    <div class="dashboard-col-3"><div class="stat-card"><div class="stat-icon bg-danger"><i data-lucide="map-pin"></i></div><div class="stat-info"><span class="stat-value"><?= (int) ($gQuality['missing'] ?? 0) ?></span><span class="stat-label">Missing coords</span></div></div></div>
    <div class="dashboard-col-3"><div class="stat-card"><div class="stat-icon bg-danger"><i data-lucide="flag"></i></div><div class="stat-info"><span class="stat-value"><?= (int) ($gQuality['invalid'] ?? 0) ?></span><span class="stat-label">Invalid</span></div></div></div>
    <div class="dashboard-col-3"><div class="stat-card"><div class="stat-icon bg-warning"><i data-lucide="clock"></i></div><div class="stat-info"><span class="stat-value"><?= (int) ($gQuality['delayed_cnt'] ?? 0) ?></span><span class="stat-label">Delayed (&gt;<?= $maxDelaySec ?>s)</span></div></div></div>
</div>

<div class="card mt-2">
    <div class="card-header"><h3><i data-lucide="satellite"></i> GPS records <span class="badge badge-info"><?= $gTotal ?></span></h3><span class="text-muted">avg sats <?= ($gQuality['avg_sats'] ?: 0) ?> · avg speed <?= ($gQuality['avg_speed'] ?: 0) ?> km/h</span></div>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>ID</th><th>Vehicle</th><th>IMEI</th><th>GPS time</th><th>Coords</th><th>Speed</th><th>Sats</th><th>Act</th><th>Delivery delay</th><th>LATRA status</th><th>Validation</th></tr></thead><tbody>
        <?php foreach ($gRecords as $r): ?>
            <tr>
                <td><?= (int) $r['id'] ?></td>
                <td><?= esc($r['vehicle_reg_no'] ?? '-') ?></td>
                <td class="text-muted"><?= esc($r['imei'] ?? '-') ?></td>
                <td><?= $r['wialon_msg_time'] ? date('Y-m-d H:i:s', (int) $r['wialon_msg_time']) : '-' ?></td>
                <td><?= $r['lat'] !== null ? round((float) $r['lat'], 4) . ', ' . round((float) $r['lng'], 4) : '-' ?></td>
                <td><?= $r['speed'] !== null ? round((float) $r['speed'], 1) : '-' ?></td>
                <td><?= (int) ($r['satellite_count'] ?? 0) ?></td>
                <td><?= (int) $r['activity_id'] ?></td>
                <td><?php $rDelay = $r['created_ts'] ? max(0, (int) $r['created_ts'] - (int) $r['wialon_msg_time']) : null; echo $rDelay !== null ? $rDelay . 's' : '-'; ?></td>
                <td><span class="badge badge-<?= $r['latra_status'] === 'sent' ? 'success' : ($r['latra_status'] === 'rejected' ? 'danger' : ($r['latra_status'] === 'failed' ? 'warning' : 'secondary')) ?>"><i data-lucide="<?= Icon::fixStripped($r['latra_status'] === 'sent' ? 'check' : ($r['latra_status'] === 'rejected' ? 'ban' : ($r['latra_status'] === 'failed' ? 'xmark' : 'clock'))) ?>"></i> <?= esc($r['latra_status']) ?></span></td>
                <td><span class="badge badge-<?= ($r['validation_status'] ?? 'ok') === 'failed' ? 'danger' : 'success' ?>"><i data-lucide="<?= Icon::fixStripped(($r['validation_status'] ?? 'ok') === 'failed' ? 'flag' : 'check') ?>"></i> <?= esc($r['validation_status'] ?? 'ok') ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$gRecords): ?><tr><td colspan="11" class="empty-state"><i data-lucide="inbox"></i><p>No GPS records in range</p></td></tr><?php endif; ?>
        </tbody></table></div>
    <?= laPager($gTotal, $gPage, $gPer, 'gpage') ?>
</div>

<div class="dashboard-row mt-2">
    <div class="dashboard-col-6">
        <div class="card"><div class="card-header"><h3><i data-lucide="bell"></i> Wialon events</h3></div>
            <div class="table-responsive"><table class="data-table">
                <thead><tr><th>Event</th><th>Vehicle</th><th>Type</th><th>Status</th><th>GPS time</th><th>Speed</th></tr></thead><tbody>
                <?php foreach ($events as $e): ?>
                    <tr>
                        <td class="text-muted"><?= esc($e['event_id']) ?></td>
                        <td><?= esc($e['vehicle_reg_no'] ?? '-') ?></td>
                        <td><?= esc($e['event_type']) ?></td>
                        <td><?= laBadge((string) $e['status']) ?></td>
                        <td><?= $e['wialon_msg_time'] ? date('Y-m-d H:i:s', (int) $e['wialon_msg_time']) : '-' ?></td>
                        <td><?= $e['speed'] !== null ? round((float) $e['speed'], 1) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$events): ?><tr><td colspan="6" class="empty-state"><i data-lucide="inbox"></i><p>No Wialon events</p></td></tr><?php endif; ?>
                </tbody></table></div>
            <?= laPager($evTotal, $evPage, $evPer, 'epage') ?>
        </div>
    </div>
    <div class="dashboard-col-6">
        <div class="card"><div class="card-header"><h3><i data-lucide="package"></i> Emergency events</h3></div>
            <div class="table-responsive"><table class="data-table">
                <thead><tr><th>Event</th><th>Vehicle</th><th>Type</th><th>Status</th><th>Started</th><th>Max speed</th></tr></thead><tbody>
                <?php foreach ($emEvents as $e): ?>
                    <tr>
                        <td class="text-muted"><?= esc($e['event_id']) ?></td>
                        <td><?= esc($e['vehicle_reg_no'] ?? '-') ?></td>
                        <td><?= esc($e['event_type']) ?></td>
                        <td><span class="badge badge-<?= $e['status'] === 'active' ? 'danger' : 'secondary' ?>"><i data-lucide="<?= Icon::fixStripped($e['status'] === 'active' ? 'bell' : 'flag-checkered') ?>"></i> <?= esc($e['status']) ?></span></td>
                        <td><?= esc(toDisplay((string) ($e['started_at'] ?? ''))) ?></td>
                        <td><?= $e['max_speed_kmh'] !== null ? round((float) $e['max_speed_kmh'], 1) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$emEvents): ?><tr><td colspan="6" class="empty-state"><i data-lucide="inbox"></i><p>No emergency events</p></td></tr><?php endif; ?>
                </tbody></table></div>
            <?= laPager($emTotal, $emPage, $emPer, 'mpage') ?>
        </div>
    </div>
</div>

<?php elseif ($tab === 'integration'): ?>
<div class="dashboard-row">
    <div class="dashboard-col-4">
        <div class="card"><div class="card-header"><h3><i data-lucide="workflow"></i> Chain status</h3></div>
            <div class="table-responsive"><table class="data-table">
                <tbody>
                <tr><th>Wialon fleet sync</th><td><?= $wialon ? laAge($wialonAge) . ' ago' : 'never' ?></td><td><?= laBadge($wialonAge === null ? 'unknown' : laState($wialonAge, 3600, 86400)) ?></td></tr>
                <tr><th>GPS data receiving</th><td><?= $gpsInAge !== null ? laAge($gpsInAge) . ' ago' : 'none' ?></td><td><?= laBadge($gpsInAge === null ? 'unknown' : laState($gpsInAge, (int) $pollSec, (int) $offlineSec)) ?></td></tr>
                <tr><th>Event engine</th><td><?= $evAge !== null ? laAge($evAge) . ' ago' : 'never' ?></td><td><?= laBadge($evTs === null ? 'unknown' : laState($evAge ?? 999999, 300, 1800)) ?></td></tr>
                <tr><th>LATRA API reachable</th><td><?= $latraAge !== null ? 'last ' . laAge($latraAge) . ' ago' : 'never' ?></td><td><?= laBadge($latraAge === null ? 'unknown' : laState($latraAge, 300, 3600)) ?></td></tr>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="dashboard-col-8">
        <div class="card"><div class="card-header"><h3><i data-lucide="activity"></i> Integration metrics · today</h3></div>
            <div class="stats-row">
                <div class="stat-card"><div class="stat-icon bg-primary"><i data-lucide="inbox"></i></div><div class="stat-info"><span class="stat-value"><?= (int) ($integ['received'] ?? 0) ?></span><span class="stat-label">Received</span></div></div>
                <div class="stat-card"><div class="stat-icon bg-primary"><i data-lucide="settings"></i></div><div class="stat-info"><span class="stat-value"><?= (int) ($integ['processed'] ?? 0) ?></span><span class="stat-label">Processed</span></div></div>
                <div class="stat-card"><div class="stat-icon bg-success"><i data-lucide="send"></i></div><div class="stat-info"><span class="stat-value"><?= (int) ($integ['sent'] ?? 0) ?></span><span class="stat-label">Submitted</span></div></div>
                <div class="stat-card"><div class="stat-icon bg-danger"><i data-lucide="x"></i></div><div class="stat-info"><span class="stat-value"><?= (int) ($integ['failed'] ?? 0) ?></span><span class="stat-label">Failed</span></div></div>
            </div>
        </div>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header"><h3><i data-lucide="send"></i> LATRA submission log <span class="badge badge-info"><?= $pTotal ?></span></h3></div>
    <div class="card-body">
        <form method="get" class="filter-bar" style="box-shadow:none">
            <input type="hidden" name="tab" value="integration">
            <input type="text" name="psearch" class="form-input" placeholder="Reg no" value="<?= esc($pSearch) ?>">
            <select name="presult" class="form-input">
                <?php foreach (['all' => 'All results', 'ok' => 'Successful (1)', 'fail' => 'Failed / other'] as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $pResult === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Apply</button>
            <a href="latra_audit?tab=integration" class="btn btn-secondary"><i data-lucide="rotate-cw"></i> Reset</a>
        </form>
    </div>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>When</th><th>Vehicle</th><th>HTTP</th><th>Response</th><th>Latency</th></tr></thead><tbody>
        <?php foreach ($pushRows as $p): ?>
            <tr>
                <td class="text-muted"><?= esc($p['created_at']) ?></td>
                <td><?= esc($p['vehicle_reg_no'] ?? '-') ?></td>
                <td><?= esc((string) $p['http_code']) ?></td>
                <td><span class="badge badge-<?= ($p['response_status'] ?? '') === '1' ? 'success' : 'warning' ?>"><i data-lucide="<?= Icon::fixStripped(($p['response_status'] ?? '') === '1' ? 'check' : 'clock') ?>"></i> <?= esc((string) $p['response_status']) ?></span></td>
                <td class="text-muted"><?= $p['latency_ms'] !== null ? (int) $p['latency_ms'] . ' ms' : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pushRows): ?><tr><td colspan="5" class="empty-state"><i data-lucide="inbox"></i><p>No submission log entries</p></td></tr><?php endif; ?>
        </tbody></table></div>
    <?= laPager($pTotal, $pPage, $pPer, 'ppage') ?>
</div>

<?php if (Auth::hasPermission('integration.view')): ?>
<div class="card mt-2">
    <div class="card-header"><h3><i data-lucide="clipboard-list"></i> Scheduler monitoring</h3></div>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>Job</th><th>Interval</th><th>Enabled</th><th>Last run</th><th>Next run</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($jobRows as $j): ?>
            <tr>
                <td><strong><?= esc($j['label']) ?></strong><br><small class="text-muted"><?= esc($j['job_key']) ?></small></td>
                <td><?= laAge((int) $j['interval_seconds']) ?></td>
                <td><?= $j['is_enabled'] ? laBadge('online', 'check') : laBadge('offline', 'pause') ?></td>
                <td class="text-muted"><?= esc((string) $j['last_run_at']) ?></td>
                <td class="text-muted"><?= esc((string) $j['next_run_at']) ?></td>
                <td><?= $j['last_status'] ? laBadge((string) $j['last_status']) : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$jobRows): ?><tr><td colspan="6" class="empty-state"><i data-lucide="inbox"></i><p>No scheduled jobs registered</p></td></tr><?php endif; ?>
        </tbody></table></div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'audit'): ?>
<div class="filter-bar">
    <form method="get">
        <input type="hidden" name="tab" value="audit">
        <label><strong>User / IP</strong></label>
        <input type="text" name="auser" class="form-input" placeholder="username or ip" value="<?= esc($aUser) ?>">
        <label><strong>Action</strong></label>
        <input list="aActionList" name="aaction" class="form-input" placeholder="action" value="<?= esc($aAction) ?>">
        <datalist id="aActionList"><?php foreach ($aActs as $ac): ?><option value="<?= esc($ac['action']) ?>"><?= esc($ac['action']) ?> (<?= (int) $ac['c'] ?>)</option><?php endforeach; ?></datalist>
        <input type="date" name="afrom" class="form-input" title="From" value="<?= esc($_GET['afrom'] ?? '') ?>">
        <input type="date" name="ato" class="form-input" title="To" value="<?= esc($_GET['ato'] ?? '') ?>">
        <button type="submit" class="btn btn-primary"><i data-lucide="filter"></i> Apply</button>
        <a href="latra_audit?tab=audit" class="btn btn-secondary"><i data-lucide="rotate-cw"></i> Reset</a>
    </form>
</div>

<div class="card mt-2">
    <div class="card-header"><h3><i data-lucide="list-checks"></i> Audit log <span class="badge badge-info"><?= $aTotal ?></span></h3>
        <span class="text-muted">Time · User · Action · Resource · Result — with IP and session request id when available.</span>
    </div>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Target</th><th>Detail</th><th>IP</th></tr></thead><tbody>
        <?php foreach ($aRows as $a): ?>
            <tr>
                <td class="text-muted"><?= esc(toDisplay((string) $a['created_at'])) ?></td>
                <td><?= esc($a['username'] ?? 'system') ?></td>
                <td><span class="badge badge-info"><i data-lucide="ellipsis"></i> <?= esc($a['action']) ?></span></td>
                <td><?= esc($a['target']) ?></td>
                <td class="text-muted" title="<?= esc($a['detail']) ?>"><?= strlen((string) $a['detail']) > 60 ? esc(substr((string) $a['detail'], 0, 60)) . '…' : esc((string) $a['detail']) ?></td>
                <td class="text-muted"><?= esc($a['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$aRows): ?><tr><td colspan="6" class="empty-state"><i data-lucide="inbox"></i><p>No audit entries in range</p></td></tr><?php endif; ?>
        </tbody></table></div>
    <?= laPager($aTotal, $aPage, $aPer, 'apage') ?>
</div>

<?php elseif ($tab === 'reports'): ?>
<div class="filter-bar">
    <form method="get">
        <input type="hidden" name="tab" value="reports">
        <label><strong>Report</strong></label>
        <select name="report" class="form-input">
            <?php foreach ([
                'gps' => 'GPS data report',
                'devices' => 'Device health report',
                'integration' => 'LATRA integration report',
                'compliance' => 'Compliance status report',
            ] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $report === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (in_array($report, ['gps', 'integration'], true)): ?>
            <input type="date" name="from" class="form-input" title="From" value="<?= esc($_GET['from'] ?? '') ?>">
            <input type="date" name="to" class="form-input" title="To" value="<?= esc($_GET['to'] ?? '') ?>">
        <?php endif; ?>
        <button type="submit" class="btn btn-primary"><i data-lucide="eye"></i> Preview</button>
        <button type="button" class="btn btn-success" onclick="window.location='latra_audit?tab=reports&export=csv&report=<?= esc($report) ?>&from=<?= esc($_GET['from'] ?? '') ?>&to=<?= esc($_GET['to'] ?? '') ?>'"><i data-lucide="file-spreadsheet"></i> Export CSV</button>
        <a href="latra_audit?tab=reports" class="btn btn-secondary"><i data-lucide="rotate-cw"></i> Reset</a>
    </form>
</div>

<div class="card mt-2">
    <div class="card-header"><h3><i data-lucide="table"></i> Report preview <span class="badge badge-info"><?= count($rRows) ?> shown (max 50)</span></h3>
        <span class="text-muted">CSV export streams the full result set.</span>
    </div>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><?php foreach ($rCols as $c): ?><th><?= esc($c) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($rRows as $r): ?>
            <tr><?php foreach ($rCols as $c): ?><td><?= esc($r[$c] ?? '') ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$rRows): ?><tr><td colspan="<?= count($rCols) ?>" class="empty-state"><i data-lucide="inbox"></i><p>No rows for this report</p></td></tr><?php endif; ?>
        </tbody></table></div>
</div>
<?php endif; ?>

<?php Layout::renderFoot(); ?>