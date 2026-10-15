<?php
/**
 * Layout helper.
 */
class Layout {

    /** Extra context injected into the AI assistant (page-specific data). */
    private static array $assistExtra = [];

    /** Merge additional AI-assistant page context (vehicle etc). */
    public static function assistContext(array $extra): void {
        self::$assistExtra = array_merge(self::$assistExtra, $extra);
    }

    public static function renderHead(string $title = '', bool $includeMap = false): void {
        $pageTitle = $title ? $title . ' | ' . APP_NAME : APP_NAME;
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/lucide@1.42.0/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
    <?php if ($includeMap): ?>
    <script>
        // Reverse geocoding for map pins (loaded before page scripts use it).
        const GeoName = (function () {
            const cache = {};
            let tail = Promise.resolve();
            function reverse(lat, lng) {
                const key = Number(lat).toFixed(5) + ',' + Number(lng).toFixed(5);
                if (cache[key] !== undefined) return Promise.resolve(cache[key]);
                const job = tail.then(() =>
                    fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=16&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng), { headers: { 'Accept-Language': 'en' } })
                    .then(r => r.json())
                    .then(d => cache[key] = d && (d.display_name || d.name || d.address && d.address.road) || '')
                    .catch(() => cache[key] = '')
                    .then(name => new Promise(res => setTimeout(() => res(name), 1100)))
                );
                tail = job.then(() => {}, () => {});
                return job;
            }
            return { reverse };
        })();
        function bindGeoNameTooltip(marker, lat, lng, prefix, options) {
            const opts = Object.assign({ direction: 'top', offset: [0, -10] }, options || {});
            marker.bindTooltip(prefix || '', opts);
            marker.on('mouseover', function () {
                GeoName.reverse(lat, lng).then(function (name) {
                    if (name) marker.setTooltipContent(prefix ? prefix + ' — ' + name : name);
                });
            });
        }
    </script>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.all.min.js"></script>
    <?= class_exists('Auth') ? '<meta name="csrf-token" content="' . Auth::csrfToken() . '">' : '' ?>
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/style.css?v=<?= APP_VERSION ?>">
    <link rel="icon" type="image/x-icon" href="<?= ASSETS_URL ?>/logo/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ASSETS_URL ?>/logo/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= ASSETS_URL ?>/logo/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ASSETS_URL ?>/logo/apple-touch-icon.png">
    <link rel="manifest" href="<?= ASSETS_URL ?>/logo/site.webmanifest">
    <meta name="theme-color" content="#E8920C">
</head>
<body>
    <div class="app-wrapper">
        <?= self::renderSidebar() ?>
        <div class="app-main">
            <?= self::renderTopbar() ?>
            <div class="page-scroll">
                <div class="page-container">
<?php
    }

    public static function renderFoot(): void {
        ?>
                </div>
            </div>
        </div>
    </div>
    <?php self::renderAssistant() ?>
    <script src="<?= ASSETS_URL ?>/script.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
<?php
    }

    /** AI assistant injection: per-page context + stylesheet + script. */
    public static function renderAssistant(): void {
        $page = basename($_SERVER['PHP_SELF'] ?? '', '.php');
        $extra = self::$assistExtra;
        $extra2 = ['page' => $page];
        foreach (['vehicle_id' => 'vehicle_id', 'vehicle_reg_no' => 'vehicle_reg_no'] as $k => $v) {
            $extra2[$k] = (string) ($extra[$v] ?? ($extra[$k] ?? ''));
        }
        $suggestions = self::assistantSuggestions($page);
        $cfg = [
            'page' => $extra2['page'],
            'vehicle_id' => (int) ($extra2['vehicle_id'] ?? 0),
            'vehicle_reg_no' => $extra2['vehicle_reg_no'] ?? '',
            'suggestions' => $suggestions,
        ];
        ?>
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/ai-assistant.css?v=<?= APP_VERSION ?>">
    <script>window.LATRA_ASSISTANT = <?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="<?= ASSETS_URL ?>/ai-assistant.js?v=<?= APP_VERSION ?>"></script>
<?php
    }

    /** Quick-action suggestions shown for the current page. */
    private static function assistantSuggestions(string $page): array {
        $map = [
            'index' => ["What's happening today?", 'Show failed LATRA submissions', 'Check system health', 'Summarize today\'s activity'],
            'vehicles' => ['How many vehicles are currently active?', 'Which vehicles have not sent GPS data recently?', 'Show me recent Engine ON events', 'Check GPS health'],
            'vehicle_detail' => ['Explain this vehicle\'s status', 'Show recent events', 'Check LATRA submissions', 'Why is this vehicle not compliant?'],
            'activities' => ['Explain vehicle events', 'Show recent events', 'What does each activity mean?'],
            'automation' => ['Check automation health', 'Show failed automation runs', 'Why are jobs stuck?', 'Recent automation errors'],
            'push_log' => ['Show failed submissions', 'Check outbox status', 'Why did this push fail?', 'Summarize today\'s submissions'],
            'run_logs' => ['Check scheduler health', 'Show failed scheduler runs', 'When did the last run finish?', 'Is the worker alive?'],
            'latra_audit' => ['Show failed submissions', 'Why are submissions failing?', 'Summarize today\'s submissions', 'Check LATRA API status'],
            'health' => ['Check system health', 'Are Wialon requests working?', 'Are LATRA requests working?', 'What needs attention?'],
            'settings' => ['Check scheduler status', 'Is the worker alive?', 'Check system health'],
        ];
        return $map[$page] ?? ["What's happening today?", 'Show failed LATRA submissions', 'Check system health'];
    }

    private static function renderSidebar(): string {
        $current = basename($_SERVER['PHP_SELF'], '.php');
        $menu = [
            ['index', 'Dashboard', 'table-cells-large'],
            ['vehicles', 'Vehicles', 'truck'],
            ['activities', 'Activities', 'list-check'],
            ['automation', 'Automation', 'bolt'],
            ['push_log', 'Push log', 'paper-plane'],
            ['run_logs', 'Run logs', 'clock-rotate-left'],
            ['latra_audit', 'LATRA portal', 'clipboard-check'],
            ['health', 'System health', 'heart-pulse'],
            ['settings', 'Settings', 'sliders'],
            ['users', 'Users', 'users'],
        ];
        // Role-scoped menu. LATRA-facing roles are restricted to the compliance
        // portal + system health; the internal viewer keeps read-only pages.
        $role = class_exists('Auth') && method_exists('Auth', 'role') ? Auth::role() : '';
        $latraOnly = ['latra_audit', 'health'];
        if ($role === 'admin') {
            $visible = null; // all pages, including Users
        } elseif ($role === 'operator') {
            $visible = ['index', 'vehicles', 'activities', 'automation', 'push_log', 'run_logs', 'latra_audit', 'health', 'settings'];
        } elseif ($role === 'viewer') {
            $visible = ['index', 'vehicles', 'push_log', 'latra_audit', 'health'];
        } else {
            $visible = $latraOnly;
        }

        $items = '';
        foreach ($menu as $item) {
            if ($visible !== null && !in_array($item[0], $visible, true)) {
                continue;
            }
            $active = $current === $item[0] ? ' active' : '';
            // index.php is published at the canonical /dashboard URL.
            $slug = $item[0] === 'index' ? 'dashboard' : $item[0];
            $items .= '<a href="' . $slug . '" class="dock-item' . $active . '"'
                . ' data-tip="' . htmlspecialchars($item[1]) . '"'
                . ' aria-label="' . htmlspecialchars($item[1]) . '">'
                . '<span class="dock-ico"><i data-lucide="' . Icon::fixStripped($item[2]) . '"></i></span>'
                . '</a>';
        }

        return '<aside class="sidemenu" id="sideMenu">'
            . '<div class="dock">'
            . '<nav class="dock-nav" aria-label="Primary navigation">' . $items . '</nav>'
            . '<div class="dock-foot"><span class="dock-ver">v' . APP_VERSION . '</span></div>'
            . '</div>'
            . '</aside>';
    }

    private static function renderTopbar(): string {
        $user = class_exists('Auth') && method_exists('Auth', 'user') ? Auth::user() : ['display_name' => ''];
        $name = trim((string) ($user['display_name'] ?? ''));
        $initials = '';
        foreach (preg_split('/\s+/', $name, 2) as $i => $part) {
            if ($i === 0 && $part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            if ($i === 1 && $part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        $initials = $initials !== '' ? $initials : 'OP';
        $logout = class_exists('Auth') && method_exists('Auth', 'csrfToken')
            ? '<a class="tb-dd-opt" href="login?logout=1&amp;_csrf=' . Auth::csrfToken() . '"><i data-lucide="log-out"></i>&nbsp; Sign out</a>'
            : '';

        $area   = $_GET['area'] ?? 'all';
        $status = $_GET['status'] ?? 'all';
        $actId  = max(0, (int) ($_GET['activity'] ?? 0));
        if (!in_array($area, ['all', 'dar', 'up'], true)) $area = 'all';
        if (!in_array($status, ['all', 'online', 'offline'], true)) $status = 'all';

        $canWrite = class_exists('Auth') && method_exists('Auth', 'canWrite') ? Auth::canWrite() : true;

        // Internal admin actions (sync, automation, settings, profile) are hidden from LATRA roles.
        $adminRole = class_exists('Auth') && method_exists('Auth', 'role') ? Auth::role() : '';
        $adminChips = $canWrite
            ? '<a href="#" class="tb-dd-opt" onclick="syncWialon(event)"><i data-lucide="rotate-cw"></i>&nbsp; Sync vehicles</a>'
            . '<a href="automation" class="tb-dd-opt"><i data-lucide="zap"></i>&nbsp; Run automation</a>'
            . '<a href="settings" class="tb-dd-opt"><i data-lucide="settings"></i>&nbsp; Settings</a><div class="tb-dd-sep"></div>'
            . '<a href="settings" class="tb-dd-opt"><i data-lucide="user-cog"></i>&nbsp; Profile</a>'
            . ($adminRole === 'admin' ? '<a href="users" class="tb-dd-opt"><i data-lucide="users"></i>&nbsp; Users</a>' : '')
            : '<span class="tb-dd-opt" style="color:var(--text-muted)">' . htmlspecialchars((string) (class_exists('Auth') ? Auth::role() : '')) . '</span><div class="tb-dd-sep"></div>';

        $areaLabel   = ['all' => 'All areas', 'dar' => 'Dar es Salaam', 'up' => 'Upcountry'][$area];
        $statusLabel = ['all' => 'All status', 'online' => 'Online', 'offline' => 'Offline'][$status];
        $actLabel    = 'All activities';
        if ($actId > 0) {
            $def = class_exists('Database') ? Database::fetch('SELECT activity_name FROM activity_definitions WHERE id = ?', [$actId]) : null;
            $actLabel = $def ? ($actId . '. ' . $def['activity_name']) : ('Activity #' . $actId);
        }
        $q = function (string $key, string $val) use ($area, $status, $actId): string {
            $p = [];
            if ($key !== 'area' && $area !== 'all')     $p['area'] = $area;
            if ($key !== 'status' && $status !== 'all') $p['status'] = $status;
            if ($key !== 'activity' && $actId > 0)      $p['activity'] = $actId;
            if ($key === 'area' && $val !== 'all')      $p['area'] = $val;
            if ($key === 'status' && $val !== 'all')    $p['status'] = $val;
            if ($key === 'activity' && $val !== 'all')  $p['activity'] = (int) $val;
            return $p ? 'dashboard?' . http_build_query($p) : 'dashboard';
        };
        $activityOpts = '';
        if (class_exists('Database')) {
            foreach (Database::fetchAll('SELECT id, activity_name FROM activity_definitions ORDER BY id') as $a) {
                $activityOpts .= '<a class="tb-dd-opt" href="' . htmlspecialchars($q('activity', (string) $a['id'])) . '">' . (int) $a['id'] . '. ' . htmlspecialchars($a['activity_name']) . '</a>';
            }
        }

        return '<header class="topbar">'
            . '<div class="brand"><i data-lucide="map-pin"></i><span>LATRA</span></div>'
            . '<div class="tb-right">'
            . '<div class="tb-dd" data-dd><button type="button" class="tb-dd-btn" data-dd-btn aria-label="Area filter"><span class="tm">' . htmlspecialchars($areaLabel) . '</span><i data-lucide="chevron-down"></i></button>'
            . '<div class="tb-dd-menu"><a href="' . htmlspecialchars($q('area', 'all')) . '" class="tb-dd-opt">All areas</a><a href="' . htmlspecialchars($q('area', 'dar')) . '" class="tb-dd-opt">Dar es Salaam</a><a href="' . htmlspecialchars($q('area', 'up')) . '" class="tb-dd-opt">Upcountry</a></div></div>'
            . '<div class="tb-dd" data-dd><button type="button" class="tb-dd-btn" data-dd-btn aria-label="Status filter"><span class="tm">' . htmlspecialchars($statusLabel) . '</span><i data-lucide="chevron-down"></i></button>'
            . '<div class="tb-dd-menu"><a href="' . htmlspecialchars($q('status', 'all')) . '" class="tb-dd-opt">All status</a><a href="' . htmlspecialchars($q('status', 'online')) . '" class="tb-dd-opt">Online</a><a href="' . htmlspecialchars($q('status', 'offline')) . '" class="tb-dd-opt">Offline</a></div></div>'
            . '<div class="tb-dd" data-dd><button type="button" class="tb-dd-btn" data-dd-btn aria-label="Activity filter"><span class="tm">' . htmlspecialchars($actLabel) . '</span><i data-lucide="chevron-down"></i></button>'
            . '<div class="tb-dd-menu maxh"><a href="' . htmlspecialchars($q('activity', 'all')) . '" class="tb-dd-opt">All activities</a>' . $activityOpts . '</div></div>'
            . '<div class="tb-user"><span class="tb-user-name">' . htmlspecialchars($name ?: 'Operator') . '</span>'
            . '<div class="tb-dd" data-dd><button type="button" class="tb-avatar" data-dd-btn aria-label="Account">' . htmlspecialchars($initials) . '</button>'
            . '<div class="tb-dd-menu"><span class="tb-dd-opt" style="font-weight:600;cursor:default">' . htmlspecialchars($name ?: 'Operator') . '</span><div class="tb-dd-sep"></div>'
            . $adminChips . $logout . '</div></div>'
            . '</div>'
            . '</div>'
            . '</header>';
    }

    public static function alert(string $type, string $message): string {
        $swalType = in_array($type, ['success', 'error', 'warning', 'info']) ? $type : 'info';
        return "<script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: '{$swalType}',
                    title: '" . addslashes(htmlspecialchars($message)) . "',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true
                });
            });
        </script>";
    }

    public static function card(string $title, string $content, string $icon = '', string $extra = ''): string {
        $iconHtml = $icon ? '<i data-lucide="' . Icon::fixStripped($icon) . '"></i>' : '';
        return '<div class="card"><div class="card-header"><div class="card-title">' . $iconHtml . '<h3>' . htmlspecialchars($title) . '</h3></div>' . $extra . '</div><div class="card-body">' . $content . '</div></div>';
    }

    public static function pagination(int $currentPage, int $totalPages, string $baseUrl): string {
        if ($totalPages <= 1) return '';

        $html = '<div class="pagination">';

        // Previous
        if ($currentPage > 1) {
            $html .= '<a href="' . $baseUrl . '&page=' . ($currentPage - 1) . '" class="page-link"><i data-lucide="chevron-left"></i></a>';
        }

        // Pages
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);

        if ($start > 1) $html .= '<span class="page-ellipsis">...</span>';

        for ($i = $start; $i <= $end; $i++) {
            $active = $i === $currentPage ? ' active' : '';
            $html .= '<a href="' . $baseUrl . '&page=' . $i . '" class="page-link' . $active . '">' . $i . '</a>';
        }

        if ($end < $totalPages) $html .= '<span class="page-ellipsis">...</span>';

        // Next
        if ($currentPage < $totalPages) {
            $html .= '<a href="' . $baseUrl . '&page=' . ($currentPage + 1) . '" class="page-link"><i data-lucide="chevron-right"></i></a>';
        }

        $html .= '</div>';
        return $html;
    }
}