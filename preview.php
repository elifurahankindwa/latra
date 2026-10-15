<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Layout.php';

if (!Auth::check()) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
if (!Auth::rateLimit('preview:' . Auth::clientKey(), 180, 20)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Too many requests']);
    exit;
}

$kind = trim($_GET['k'] ?? '');
$id   = (int) ($_GET['id'] ?? 0);
$col  = trim($_GET['col'] ?? '');
$span = trim($_GET['span'] ?? 'today');
$state= trim($_GET['state'] ?? 'ok');

// -- Dashboard scope (mirrors index.php header filters) -----------------
$area    = $_GET['area'] ?? 'all';
$status  = $_GET['status'] ?? 'all';
$pvtAct  = max(0, (int) ($_GET['activity'] ?? 0));
if (!in_array($area, ['all', 'dar', 'up'], true))     $area = 'all';
if (!in_array($status, ['all', 'online', 'offline'], true)) $status = 'all';

$pvParts = [];
if ($area === 'dar')  $pvParts[] = 'lat BETWEEN -7.4000 AND -6.4000 AND lng BETWEEN 38.9000 AND 39.7000';
if ($area === 'up')   $pvParts[] = '(lat IS NULL OR lng IS NULL OR lat = 0 OR lng = 0 OR NOT (lat BETWEEN -7.4000 AND -6.4000 AND lng BETWEEN 38.9000 AND 39.7000))';
if ($status === 'online')  $pvParts[] = 'last_message_time IS NOT NULL AND last_message_time > ' . (time() - 86400);
if ($status === 'offline') $pvParts[] = '(last_message_time IS NULL OR last_message_time <= ' . (time() - 86400) . ')';
$vScope = $pvParts ? implode(' AND ', $pvParts) : '1=1';

$pvScopeOf = function (string $alias, bool $hasAct = true) use ($vScope, $pvtAct): string {
    $s = "$alias.vehicle_id IN (SELECT id FROM vehicles WHERE $vScope)";
    if ($hasAct && $pvtAct > 0) $s .= " AND $alias.activity_id = $pvtAct";
    return $s;
};

function pvNum(int $n): string {
    if ($n < 1000) return (string) $n;
    $v = $n / 1000;
    return rtrim(rtrim(number_format($v, 1), '0'), '.') . 'k';
}

function pvCell(int $v, string $l): string {
    return '<div class="pv-cell"><span class="v">' . pvNum($v) . '</span><span class="l">' . pvEsc($l) . '</span></div>';
}

function pvTotals(string $table, string $alias, string $cond, bool $hasAct, Closure $scopeOf): array {
    $sc = $scopeOf($alias, $hasAct);
    $g  = function (string $extra) use ($table, $alias, $cond, $sc): int {
        return (int) (Database::fetch("SELECT COUNT(*) c FROM $table $alias WHERE $cond AND $sc $extra")['c'] ?? 0);
    };
    $week  = $g("AND $alias.created_at >= NOW() - INTERVAL 7 DAY");
    $today = $g("AND $alias.created_at >= CURDATE()");
    $older = max(0, $g('') - $week);
    return [$today, $week, $older];
}

function pvJson(array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pvErr(string $msg): void {
    pvJson(['ok' => false, 'error' => $msg]);
}

function pvChip(string $v): string {
    $s = strtolower((string) $v);
    if ($s === 'sent' || $s === '1' || $s === 'ok')      return 'chip-green';
    if ($s === 'failed' || $s === '0')                   return 'chip-red';
    if ($s === 'pending')                                return 'chip-orange';
    if ($s === 'rejected')                               return 'chip-gray';
    return 'chip-blue';
}

function pvEsc(string $v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function pvHuman(string $dt): string {
    $dt = trim((string) $dt);
    return $dt ? toDisplay($dt) : '';
}

function pvItem(string $title, string $sub, string $meta, string $chip, string $chipLabel): string {
    return '<div class="pv-item"><div class="pv-line"><span class="pv-title">' . $title . '</span>'
        . ($chip ? '<span class="chip ' . $chip . '">' . pvEsc($chipLabel) . '</span>' : '')
        . '</div><div class="pv-sub">' . $sub . '</div><div class="pv-meta">' . $meta . '</div></div>';
}

function pvDetailRows(array $rows): string {
    $h = '<div class="pv-detail">';
    foreach ($rows as [$k, $v]) {
        $h .= '<div class="pv-row"><div class="pv-k">' . pvEsc($k) . '</div><div class="pv-v">' . $v . '</div></div>';
    }
    return $h . '</div>';
}

$footer = static function (array $links): array {
    $out = [];
    foreach ($links as $l) {
        $out[] = ['label' => $l[0], 'href' => $l[1], 'icon' => $l[2] ?? 'arrow-right'];
    }
    return $out;
};

switch ($kind) {
    // ---- Pipeline columns -------------------------------------------------
    case 'pipeline':
        $cols = [
            'open'  => ['title' => 'Open Items',               'page' => 'push_log',      'chip' => 'Pending'],
            'await' => ['title' => 'Awaiting approval',        'page' => 'push_log',      'chip' => 'Failed'],
            'ready' => ['title' => 'Ready to be Assigned',     'page' => 'automation',    'chip' => 'Pending'],
            'wip'   => ['title' => 'Work in Progress (WIP)',   'page' => 'automation',    'chip' => ''],
            'done'  => ['title' => 'Recently completed',       'page' => 'push_log',      'chip' => 'Sent'],
        ];
        $spec = $cols[$col] ?? null;
        if (!$spec) pvErr('Unknown pipeline column');

        $stage = [
            'open'  => ['outbox',         'o', "o.status = 'pending'"],
            'await' => ['outbox',         'o', "o.status = 'failed'"],
            'ready' => ['message_log',    'm', "m.latra_status = 'pending'"],
            'wip'   => ['wialon_events',  'e', '1 = 1'],
            'done'  => ['push_log',       'p', "p.response_status = '1'"],
        ][$col];
        [$sTable, $sAlias, $sCond] = $stage;
        $totals = pvTotals($sTable, $sAlias, $sCond, true, $pvScopeOf);

        $urgent = 0;
        if ($col === 'await') {
            $urgent = (int) (Database::fetch("SELECT COUNT(*) c FROM outbox o WHERE o.status = 'failed' AND o.created_at >= CURDATE() AND " . $pvScopeOf('o'))['c'] ?? 0);
        } elseif ($col === 'ready') {
            $urgent = (int) (Database::fetch("SELECT COUNT(*) c FROM outbox o WHERE o.status = 'failed' AND o.failure_class = 'transient' AND " . $pvScopeOf('o'))['c'] ?? 0);
        } elseif ($col === 'wip') {
            $urgent = (int) (Database::fetch("SELECT COUNT(*) c FROM wialon_events e WHERE e.status = 'pending' AND " . $pvScopeOf('e'))['c'] ?? 0);
        }

        $details = '<div class="pv-details">' . pvCell($totals[0], 'Today') . pvCell($totals[1], 'This week')
            . pvCell($totals[2], 'Older') . pvCell($urgent, 'Urgent') . '</div>';

        $items = '';
        if ($col === 'open' || $col === 'await') {
            $status = $col === 'open' ? 'pending' : 'failed';
            $rows = Database::fetchAll(
                "SELECT o.*, v.vehicle_reg_no, v.name
                 FROM outbox o LEFT JOIN vehicles v ON o.vehicle_id = v.id
                 WHERE o.status = ? AND " . $pvScopeOf('o') . " ORDER BY o.created_at DESC LIMIT 25", [$status]);
            foreach ($rows as $r) {
                $title = '<b>' . pvEsc($r['vehicle_reg_no'] ?: '—') . '</b> <span class="pv-dim">' . pvEsc($r['name'] ?: '') . '</span>';
                $meta  = '<i data-lucide="clock"></i> ' . pvEsc(pvHuman($r['created_at']))
                    . ' <i data-lucide="rotate-cw"></i> tries ' . (int) $r['tries'] . '/' . (int) $r['max_tries']
                    . ($r['last_error'] ? ' &#124; ' . pvEsc(mb_strimwidth($r['last_error'], 0, 60, '…')) : '');
                $items .= pvItem($title, $spec['chip'] . ' · message ' . pvEsc($r['message_id'] ?: $r['id']), $meta,
                    pvChip($status), $status);
            }
        } elseif ($col === 'ready') {
            $rows = Database::fetchAll(
                "SELECT m.*, v.vehicle_reg_no, v.name, a.activity_name
                 FROM message_log m LEFT JOIN vehicles v ON m.vehicle_id = v.id
                 LEFT JOIN activity_definitions a ON m.activity_id = a.id
                 WHERE m.latra_status = 'pending' AND " . $pvScopeOf('m') . "
                 ORDER BY m.created_at DESC LIMIT 25");
            foreach ($rows as $r) {
                $title = '<b>' . pvEsc($r['vehicle_reg_no'] ?: '—') . '</b> <span class="pv-dim">' . pvEsc($r['name'] ?: '') . '</span>';
                $meta  = '<i data-lucide="clock"></i> ' . pvEsc(pvHuman($r['created_at']))
                    . ' <i data-lucide="route"></i> ' . pvEsc(mb_strimwidth($r['activity_name'] ?? ('Activity ' . $r['activity_id']), 0, 46, '…'));
                $items .= pvItem($title, 'Queued for assignment · ' . ($r['wialon_msg_time'] ? 'msg ' . pvEsc(toDisplay(date('Y-m-d H:i:s', (int) $r['wialon_msg_time']))) : 'ref ' . pvEsc($r['id'])), $meta,
                    pvChip('pending'), 'Pending');
            }
        } elseif ($col === 'wip') {
            $rows = Database::fetchAll(
                "SELECT e.*, v.vehicle_reg_no, v.name, a.activity_name
                 FROM wialon_events e LEFT JOIN vehicles v ON e.vehicle_id = v.id
                 LEFT JOIN activity_definitions a ON e.activity_id = a.id
                 WHERE " . $pvScopeOf('e') . "
                 ORDER BY e.created_at DESC LIMIT 25");
            foreach ($rows as $r) {
                $title = '<b>' . pvEsc($r['vehicle_reg_no'] ?: '—') . '</b> <span class="pv-dim">' . pvEsc($r['name'] ?: '') . '</span>';
                $meta  = '<i data-lucide="clock"></i> ' . pvEsc(pvHuman($r['created_at']))
                    . ' <i data-lucide="zap"></i> ' . pvEsc($r['event_type'] ?: $r['source'])
                    . ' <i data-lucide="rotate-cw"></i> ' . (int) $r['attempts'] . ' attempt(s)';
                $items .= pvItem($title, pvEsc($r['activity_name'] ?? ('Activity ' . $r['activity_id'])), $meta,
                    pvChip($r['status']), (string) ($r['status'] ?: 'open'));
            }
        } else { // done
            $rows = Database::fetchAll(
                "SELECT p.*, v.vehicle_reg_no, v.name, a.activity_name
                 FROM push_log p LEFT JOIN vehicles v ON p.vehicle_id = v.id
                 LEFT JOIN activity_definitions a ON p.activity_id = a.id
                 WHERE p.response_status = '1' AND " . $pvScopeOf('p') . " ORDER BY p.created_at DESC LIMIT 25");
            foreach ($rows as $r) {
                $title = '<b>' . pvEsc($r['vehicle_reg_no'] ?: '—') . '</b> <span class="pv-dim">' . pvEsc($r['name'] ?: '') . '</span>';
                $meta  = '<i data-lucide="clock"></i> ' . pvEsc(pvHuman($r['created_at']))
                    . ' <i data-lucide="signal"></i> http ' . (int) $r['http_code'] . ' &#124; ' . (int) $r['latency_ms'] . ' ms';
                $items .= pvItem($title, pvEsc($r['activity_name'] ?? ('Activity ' . $r['activity_id'])), $meta,
                    pvChip('1'), 'Sent');
            }
        }

        $foot = $footer([['Open full ' . $spec['title'] . ' page…', $spec['page'], 'arrow-up-right-from-square']]);
        $scoped = ($area !== 'all' || $status !== 'all' || $pvtAct > 0);
        pvJson(['ok' => true, 'icon' => 'table-cells-large', 'title' => $spec['title'],
            'subtitle' => ($scoped ? 'Dashboard scope applied · ' : '') . 'Today / This week / Older totals and up to 25 latest records',
            'content' => $details . ($items ? '<div class="pv-list">' . $items . '</div>'
                : '<div class="pv-empty">No records for this stage right now.</div>'), 'footer' => $foot]);

    // ---- Down equipment ----------------------------------------------------
    case 'down':
        $span = $span === 'week' ? 'week' : 'today';
        $now  = time();
        $cut  = $now - ($span === 'week' ? 7 * 86400 : 86400);
        $rows = Database::fetchAll(
            "SELECT vehicle_reg_no, name, imei, last_message_time, lat, lng
             FROM vehicles WHERE (last_message_time IS NULL OR last_message_time <= ?) AND ($vScope)
             ORDER BY last_message_time IS NULL, last_message_time ASC LIMIT 30", [$cut]);
        if (!$rows) {
            pvJson(['ok' => true, 'icon' => 'truck-arrow-right', 'title' => 'Down equipment',
                'subtitle' => ucfirst($span) . ' · no units offline', 'content' => '',
                'footer' => $footer([['Open vehicles…', 'vehicles', 'truck']])]);
        }
        $items = '';
        foreach ($rows as $r) {
            $last = $r['last_message_time']
                ? pvEsc(pvHuman(date('Y-m-d H:i:s', (int) $r['last_message_time']))) : 'never';
            $title = '<b>' . pvEsc($r['vehicle_reg_no'] ?: '—') . '</b> <span class="pv-dim">' . pvEsc($r['name'] ?: '') . '</span>';
            $meta  = '<i data-lucide="clock"></i> last GPS fix ' . pvEsc($last)
                . ($r['imei'] ? ' <i data-lucide="radio-tower"></i> ' . pvEsc($r['imei']) : '');
            $items .= pvItem($title, $r['lat'] ? 'Has last known position' : 'No position recorded', $meta,
                'chip-red', 'Down');
        }
        pvJson(['ok' => true, 'icon' => 'truck-arrow-right', 'title' => 'Down equipment',
            'subtitle' => ucfirst($span) . ' · ' . count($rows) . ' unit(s) without a recent GPS fix',
            'content' => '<div class="pv-list">' . $items . '</div>',
            'footer' => $footer([['Open vehicles…', 'vehicles', 'truck']])]);

    // ---- Locations ---------------------------------------------------------
    case 'loc':
        $states = [
            'ok'   => ['No issues',  'chip-green',  'GPS fix in the last 24h'],
            'open' => ['Open issues', 'chip-orange', 'No GPS fix for 1–7 days · still within grace'],
            'down' => ['Down',       'chip-red',    'No GPS fix for over 7 days · offline'],
        ];
        $s = $states[$state] ?? null;
        if (!$s) pvErr('Unknown state');
        [$label, $clip, $hint] = $s;

        $gpsNow   = time();
        $gpsOnline = $gpsNow - 86400;
        $gpsWeek   = $gpsNow - 7 * 86400;
        if ($state === 'ok') {
            $where = 'v.last_message_time IS NOT NULL AND v.last_message_time > ' . $gpsOnline;
        } elseif ($state === 'open') {
            $where = "(v.last_message_time IS NOT NULL AND v.last_message_time <= $gpsOnline AND v.last_message_time > $gpsWeek)";
        } else {
            $where = "(v.last_message_time IS NULL OR v.last_message_time <= $gpsWeek)";
        }
        $rows = Database::fetchAll(
            "SELECT v.vehicle_reg_no, v.name, v.imei, v.last_message_time
             FROM vehicles v
             WHERE $where AND ($vScope) ORDER BY v.last_message_time IS NULL, v.last_message_time DESC LIMIT 30");
        $items = '';
        foreach ($rows as $r) {
            $title = '<b>' . pvEsc($r['vehicle_reg_no'] ?: '—') . '</b> <span class="pv-dim">' . pvEsc($r['name'] ?: '') . '</span>';
            $meta  = '<i data-lucide="clock"></i> last GPS fix ' . pvEsc($r['last_message_time'] ? pvHuman(date(DATE_FORMAT, (int) $r['last_message_time'])) : 'never')
                . ($r['imei'] ? ' <i data-lucide="radio-tower"></i> ' . pvEsc($r['imei']) : '');
            $items .= pvItem($title, $hint, $meta, $clip, $label);
        }
        $hintText = html_entity_decode($hint, ENT_QUOTES, 'UTF-8');
        pvJson(['ok' => true, 'icon' => 'map-pin', 'title' => 'Locations · ' . $label,
            'subtitle' => count($rows) . ' unit(s) · ' . $hintText,
            'content' => '<div class="pv-list">' . $items . '</div>',
            'footer' => $footer([['Open vehicles…', 'vehicles', 'truck']])]);

    // ---- Single records ----------------------------------------------------
    case 'event':
        $e = Database::fetch(
            "SELECT e.*, v.vehicle_reg_no, v.name, a.activity_name
             FROM wialon_events e LEFT JOIN vehicles v ON e.vehicle_id = v.id
             LEFT JOIN activity_definitions a ON e.activity_id = a.id
             WHERE e.id = ?", [$id]);
        if (!$e) pvErr('Event not found');
        $rows = [
            ['Event', '#' . (int) $e['id'] . ' · type ' . pvEsc($e['event_type'] ?: '—')],
            ['Vehicle', '<b>' . pvEsc($e['vehicle_reg_no'] ?: '—') . '</b>' . ($e['name'] ? ' <span class="pv-dim">' . pvEsc($e['name']) . '</span>' : '')],
            ['Activity', pvEsc($e['activity_name'] ?? ('Activity ' . $e['activity_id']))],
            ['Status', '<span class="chip ' . pvChip((string) $e['status']) . '">' . pvEsc((string) ($e['status'] ?: 'open')) . '</span>'],
            ['Message time', pvEsc($e['wialon_msg_time'] ? toDisplay(date('Y-m-d H:i:s', (int) $e['wialon_msg_time'])) : '—')],
            ['Triggered at', pvEsc(pvHuman($e['created_at']))],
            ['Attempts', (int) $e['attempts'] . ($e['last_error'] ? ' &#124; <span class="pv-dim">' . pvEsc(mb_strimwidth($e['last_error'], 0, 90, '…')) . '</span>' : '')],
            ['Telemetry', 'speed ' . (float) $e['speed'] . ' km/h · sat ' . (int) $e['satellite_count'] . ($e['lat'] ? ' · ' . round((float) $e['lat'], 5) . ', ' . round((float) $e['lng'], 5) : '')],
            ['Source', pvEsc($e['source'] ?: '—')],
        ];
        if ($e['sensor_values']) $rows[] = ['Sensor values', '<pre class="pv-pre">' . pvEsc(mb_strimwidth($e['sensor_values'], 0, 600, '…')) . '</pre>'];
        pvJson(['ok' => true, 'icon' => 'bolt', 'title' => 'Event preview',
            'subtitle' => 'Recorded by the Wialon integration',
            'content' => pvDetailRows($rows),
            'footer' => $footer([['Open Automation…', 'automation', 'bolt']])]);

    case 'push':
        $p = Database::fetch(
            "SELECT p.*, v.vehicle_reg_no, v.name, a.activity_name
             FROM push_log p LEFT JOIN vehicles v ON p.vehicle_id = v.id
             LEFT JOIN activity_definitions a ON p.activity_id = a.id
             WHERE p.id = ?", [$id]);
        if (!$p) pvErr('Push record not found');
        $ok = (string) $p['response_status'] === '1';
        $rows = [
            ['Push', '#' . (int) $p['id'] . ' · attempt ' . (int) $p['attempt']],
            ['Vehicle', '<b>' . pvEsc($p['vehicle_reg_no'] ?: '—') . '</b>' . ($p['name'] ? ' <span class="pv-dim">' . pvEsc($p['name']) . '</span>' : '')],
            ['Activity', pvEsc($p['activity_name'] ?? ('Activity ' . $p['activity_id']))],
            ['Result', '<span class="chip ' . pvChip($ok ? '1' : '0') . '">' . ($ok ? 'Sent' : 'Rejected') . '</span>'],
            ['HTTP', (int) $p['http_code'] . ' · ' . (int) $p['latency_ms'] . ' ms'],
            ['Sent at', pvEsc(pvHuman($p['created_at']))],
            ['Response', $p['response_body'] ? '<pre class="pv-pre">' . pvEsc(mb_strimwidth($p['response_body'], 0, 500, '…')) . '</pre>' : '<span class="pv-dim">none</span>'],
        ];
        if ($p['payload']) $rows[] = ['Payload', '<pre class="pv-pre">' . pvEsc(mb_strimwidth($p['payload'], 0, 1500, '…')) . '</pre>'];
        pvJson(['ok' => true, 'icon' => 'paper-plane', 'title' => 'Push preview',
            'subtitle' => 'LATRA push log entry',
            'content' => pvDetailRows($rows),
            'footer' => $footer([['Open Push log…', 'push_log', 'paper-plane']])]);

    // ---- Unit scope (used by quick push actions) --------------------------
    case 'scope':
        $scope = ($_GET['scope'] ?? 'online') === 'all' ? 'all' : 'online';
        $nowTs = time() - 86400;
        $ids = Database::fetchAll($scope === 'all'
            ? "SELECT id FROM vehicles ORDER BY id"
            : "SELECT id FROM vehicles WHERE last_message_time IS NOT NULL AND last_message_time > $nowTs ORDER BY id");
        $list = array_column($ids, 'id');
        pvJson(['ok' => true, 'ids' => $list, 'count' => count($list)]);

    default:
        pvErr('Unknown preview kind');
}