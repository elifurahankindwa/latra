<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Layout.php';
Auth::requireView('vehicles.view');

$vehicleId = intval($_GET['id'] ?? 0);
if (!$vehicleId) {
    header('Location: vehicles');
    exit;
}

$vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vehicleId]);
if (!$vehicle) {
    header('Location: vehicles');
    exit;
}

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE;
$offset = ($page - 1) * $perPage;

$totalMessages = Database::fetch("SELECT COUNT(*) as c FROM message_log WHERE vehicle_id = ?", [$vehicleId])['c'] ?? 0;
$totalPages = max(1, ceil($totalMessages / $perPage));

// Recent messages for this vehicle
$messages = Database::fetchAll(
    "SELECT m.*, ad.activity_name
     FROM message_log m
     LEFT JOIN activity_definitions ad ON m.activity_id = ad.id
     WHERE m.vehicle_id = ? ORDER BY m.wialon_msg_time DESC LIMIT ? OFFSET ?",
    [$vehicleId, $perPage, $offset]
);

foreach ($messages as &$msg) {
    $msg['time_local'] = $msg['wialon_msg_time'] ? toDisplay(date(DATE_FORMAT, (int) $msg['wialon_msg_time'])) : null;
}
unset($msg);

// Trips & distance rollup (last 7/30/90 days or all time)
$tripDays = $_GET['tripdays'] ?? '30';
if (!in_array($tripDays, ['7', '30', '90', 'all'], true)) $tripDays = '30';
$tripSince = $tripDays === 'all' ? 0 : time() - (int) $tripDays * 86400;

$segKm = static function (array $pts): float {
    $sum = 0.0;
    for ($i = 1, $n = count($pts); $i < $n; $i++) {
        [$la1, $lo1] = $pts[$i - 1];
        [$la2, $lo2] = $pts[$i];
        $dlat = deg2rad($la2 - $la1);
        $dlng = deg2rad($lo2 - $lo1);
        $a = sin($dlat / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dlng / 2) ** 2;
        $sum += 6371.0088 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
    return $sum;
};
$fmtDur = static function (int $s): string {
    if ($s >= 86400)  return sprintf('%dd %dh', (int) floor($s / 86400), (int) floor(($s % 86400) / 3600));
    if ($s >= 3600)   return sprintf('%dh %02dm', (int) floor($s / 3600), (int) floor(($s % 3600) / 60));
    return sprintf('%dm %02ds', (int) floor($s / 60), $s % 60);
};

$tripRows = Database::fetchAll(
    "SELECT wialon_msg_time, activity_id, lat, lng FROM message_log
     WHERE vehicle_id = ? AND wialon_msg_time >= ?
     ORDER BY wialon_msg_time ASC",
    [$vehicleId, $tripSince]
);

$tripHasPos = static function (array $m): bool {
    return isset($m['lat'], $m['lng']) && is_numeric($m['lat']) && is_numeric($m['lng'])
        && (float) $m['lat'] != 0.0 && (float) $m['lng'] != 0.0;
};

$hasIgnition = false;
foreach ($tripRows as $mr) {
    if (in_array((int) $mr['activity_id'], [2, 3], true)) { $hasIgnition = true; break; }
}

$trips = [];
if ($tripRows) {
    if ($hasIgnition) {
        // Engine ON (2) ... Engine OFF (3) segments
        $start = null;
        $pts   = [];
        foreach ($tripRows as $mr) {
            $act = (int) $mr['activity_id'];
            $t   = (int) $mr['wialon_msg_time'];
            if ($act === 2) {
                $start = $t;
                $pts   = $tripHasPos($mr) ? [[(float) $mr['lat'], (float) $mr['lng']]] : [];
            } elseif ($act === 3 && $start !== null) {
                if ($tripHasPos($mr)) $pts[] = [(float) $mr['lat'], (float) $mr['lng']];
                if ($t > $start) {
                    $trips[] = ['start' => $start, 'end' => $t, 'pts' => count($pts), 'km' => $segKm($pts)];
                }
                $start = null;
                $pts   = [];
            } elseif ($start !== null && $tripHasPos($mr)) {
                $pts[] = [(float) $mr['lat'], (float) $mr['lng']];
            }
        }
    } else {
        // No ignition data: segment consecutive messages, gap > 30 min = new trip
        $GAP   = 1800;
        $start = null;
        $prev  = null;
        $pts   = [];
        foreach ($tripRows as $mr) {
            $t = (int) $mr['wialon_msg_time'];
            if ($start !== null && $prev !== null && $t - $prev > $GAP) {
                $dist = $segKm($pts);
                $dur  = $prev - $start;
                if (count($pts) >= 2 && ($dist >= 0.05 || $dur >= 120)) {
                    $trips[] = ['start' => $start, 'end' => $prev, 'pts' => count($pts), 'km' => $dist];
                }
                $start = null;
                $pts   = [];
            }
            if ($start === null) { $start = $t; $pts = []; }
            if ($tripHasPos($mr)) $pts[] = [(float) $mr['lat'], (float) $mr['lng']];
            $prev = $t;
        }
        if ($start !== null && $prev !== null) {
            $dist = $segKm($pts);
            $dur  = $prev - $start;
            if (count($pts) >= 2 && ($dist >= 0.05 || $dur >= 120)) {
                $trips[] = ['start' => $start, 'end' => $prev, 'pts' => count($pts), 'km' => $dist];
            }
        }
    }
}

$tripCount = count($trips);
$tripKm    = array_sum(array_column($trips, 'km'));
$tripSecs  = array_sum(array_map(static fn($t) => $t['end'] - $t['start'], $trips));
$tripAvg   = $tripCount > 0 ? $tripKm / $tripCount : 0.0;
$recentTrips = array_slice(array_reverse($trips), 0, 25);

// Activity mappings
$mappings = Database::fetchAll(
    "SELECT am.*, ad.activity_name, ad.icon, ad.category, ad.requires_addon 
    FROM activity_mappings am 
    JOIN activity_definitions ad ON am.activity_id = ad.id 
    WHERE am.vehicle_id = ?",
    [$vehicleId]
);

// Push log
$pushes = Database::fetchAll(
    "SELECT pl.*, ad.activity_name FROM push_log pl 
    LEFT JOIN activity_definitions ad ON pl.activity_id = ad.id 
    WHERE pl.vehicle_id = ? ORDER BY pl.created_at DESC LIMIT 20",
    [$vehicleId]
);

Layout::renderHead($vehicle['vehicle_reg_no'], true);
Layout::assistContext(['vehicle_id' => $vehicleId, 'vehicle_reg_no' => $vehicle['vehicle_reg_no']]);
?>

<div class="page-header">
    <a href="vehicles" class="back-link"><i data-lucide="arrow-left"></i> Back to Vehicles</a>
    <h1><i data-lucide="truck"></i> <?= htmlspecialchars($vehicle['vehicle_reg_no']) ?></h1>
    <div class="page-actions">
        <?php if (Auth::canWrite()): ?>
        <button class="btn btn-secondary" onclick="syncSingleVehicle(<?= $vehicleId ?>)"><i data-lucide="rotate-cw"></i> Sync</button>
        <button class="btn btn-primary" onclick="fetchMessages(<?= $vehicleId ?>)"><i data-lucide="download"></i> Fetch Messages</button>
        <a href="activities?vehicle=<?= $vehicleId ?>" class="btn btn-ghost"><i data-lucide="list-checks"></i> Map Activities</a>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <!-- Vehicle Info Card -->
    <div class="detail-card">
        <h3><i data-lucide="info"></i> Vehicle Information</h3>
        <div class="detail-list">
            <div class="detail-item"><span class="detail-label">Registration</span><span class="detail-value"><?= htmlspecialchars($vehicle['vehicle_reg_no']) ?></span></div>
            <div class="detail-item"><span class="detail-label">Name</span><span class="detail-value"><?= htmlspecialchars($vehicle['name'] ?? '-') ?></span></div>
            <div class="detail-item"><span class="detail-label">IMEI</span><span class="detail-value font-mono"><?= htmlspecialchars($vehicle['imei'] ?? '-') ?></span></div>
            <div class="detail-item"><span class="detail-label">Hardware</span><span class="detail-value"><?= htmlspecialchars($vehicle['hw_type'] ?? '-') ?></span></div>
            <div class="detail-item"><span class="detail-label">Phone</span><span class="detail-value"><?= htmlspecialchars($vehicle['phone'] ?? '-') ?></span></div>
            <div class="detail-item"><span class="detail-label">Wialon ID</span><span class="detail-value font-mono"><?= htmlspecialchars((string) $vehicle['wialon_unit_id']) ?></span></div>
            <div class="detail-item"><span class="detail-label">Status</span><span class="detail-value"><span class="status-dot <?= $vehicle['is_active'] ? 'active' : 'inactive' ?>"></span> <?= $vehicle['is_active'] ? 'Active' : 'Inactive' ?></span></div>
            <div class="detail-item"><span class="detail-label">Last Sync</span><span class="detail-value"><?= $vehicle['last_sync_at'] && $vehicle['last_sync_at'] !== '0000-00-00 00:00:00' ? htmlspecialchars(toDisplay((string) $vehicle['last_sync_at'])) : 'Never' ?></span></div>
        </div>
    </div>

    <!-- Position Card -->
    <div class="detail-card">
        <h3><i data-lucide="crosshair"></i> Last Known Position</h3>
        <div class="detail-list">
            <div class="detail-item"><span class="detail-label">Latitude</span><span class="detail-value"><?= $vehicle['lat'] !== null ? round($vehicle['lat'], 8) : '<span class="text-muted">null</span>' ?></span></div>
            <div class="detail-item"><span class="detail-label">Longitude</span><span class="detail-value"><?= $vehicle['lng'] !== null ? round($vehicle['lng'], 8) : '<span class="text-muted">null</span>' ?></span></div>
            <div class="detail-item"><span class="detail-label">Altitude</span><span class="detail-value"><?= $vehicle['altitude'] !== null ? $vehicle['altitude'] . ' m' : '<span class="text-muted">null</span>' ?></span></div>
            <div class="detail-item"><span class="detail-label">Speed</span><span class="detail-value"><?= $vehicle['speed'] !== null ? $vehicle['speed'] . ' km/h' : '<span class="text-muted">null</span>' ?></span></div>
            <div class="detail-item"><span class="detail-label">Bearing</span><span class="detail-value"><?= $vehicle['bearing'] !== null ? $vehicle['bearing'] . '°' : '<span class="text-muted">null</span>' ?></span></div>
            <div class="detail-item"><span class="detail-label">Satellites</span><span class="detail-value"><?= $vehicle['satellite_count'] !== null ? $vehicle['satellite_count'] : '<span class="text-muted">null</span>' ?></span></div>
            <div class="detail-item"><span class="detail-label">HDOP</span><span class="detail-value"><?= $vehicle['hdop'] !== null ? $vehicle['hdop'] : '<span class="text-muted">null</span>' ?></span></div>
            <div class="detail-item"><span class="detail-label">2D/3D</span><span class="detail-value"><?= $vehicle['d2d3'] !== null ? $vehicle['d2d3'] . 'D' : '<span class="text-muted">null</span>' ?></span></div>
        </div>
    </div>

    <!-- Network Card -->
    <div class="detail-card">
        <h3><i data-lucide="radio-tower"></i> Network Data</h3>
        <div class="detail-list">
            <div class="detail-item"><span class="detail-label">RSSI</span><span class="detail-value"><?= $vehicle['rssi'] !== null ? $vehicle['rssi'] : '<span class="text-muted">null</span>' ?></span></div>
            <div class="detail-item"><span class="detail-label">LAC</span><span class="detail-value"><?= $vehicle['lac'] !== null ? $vehicle['lac'] : '<span class="text-muted">null</span>' ?></span></div>
            <div class="detail-item"><span class="detail-label">Cell ID</span><span class="detail-value"><?= $vehicle['cell_id'] !== null ? $vehicle['cell_id'] : '<span class="text-muted">null</span>' ?></span></div>
            <div class="detail-item"><span class="detail-label">MCC</span><span class="detail-value"><?= $vehicle['mcc'] !== null ? $vehicle['mcc'] : '<span class="text-muted">null</span>' ?></span></div>
            <div class="detail-item"><span class="detail-label">MGS ID</span><span class="detail-value"><?= $vehicle['mgs_id'] !== null ? $vehicle['mgs_id'] : '<span class="text-muted">null</span>' ?></span></div>
        </div>
    </div>

    <!-- Activity Mappings -->
    <div class="detail-card">
        <h3><i data-lucide="list-checks"></i> Activity Mappings</h3>
        <div class="activity-mini-list">
            <?php foreach ($mappings as $m): ?>
            <div class="activity-mini-item <?= $m['is_enabled'] ? 'enabled' : 'disabled' ?>">
                <i data-lucide="<?= Icon::lucide($m['icon'] ?? 'circle') ?>"></i>
                <span><?= htmlspecialchars($m['activity_name']) ?></span>
                <span class="badge <?= $m['is_enabled'] ? 'badge-success' : 'badge-secondary' ?>"><?= $m['is_enabled'] ? 'ON' : 'OFF' ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($mappings)): ?>
            <p class="text-muted">No activities mapped yet. <a href="activities?vehicle=<?= $vehicleId ?>">Configure now</a></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Map -->
<div class="card mt-2">
    <div class="card-header">
        <div class="card-title"><i data-lucide="map"></i> Location History</div>
        <div>
            <button class="btn btn-sm btn-ghost" onclick="showRoute()"><i data-lucide="route"></i> Route</button>
            <button class="btn btn-sm btn-ghost" onclick="showPoints(true)"><i data-lucide="map-pin"></i> Points</button>
        </div>
    </div>
    <div class="map-wrap map-wrap-lg">
        <div class="map-seg">
            <button class="active" data-layer="map" type="button">Map</button>
            <button data-layer="sat" type="button">Satellite</button>
        </div>
        <div id="vehicleMap" class="map-container" style="height: 520px;"></div>
    </div>
</div>

<!-- Trips & Distance -->
<div class="card mt-2">
    <div class="card-header">
        <div class="card-title"><i data-lucide="route"></i> Trips &amp; Distance
            <?php if (!$hasIgnition): ?><span class="text-muted" title="No Engine ON/OFF events for this vehicle; trips are inferred from movement segments with a 30-minute silence gap.">· movement-inferred</span><?php endif; ?>
        </div>
        <form method="GET" style="display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="id" value="<?= $vehicleId ?>">
            <label for="tripdays" class="text-muted" style="font-size:12px;">Range</label>
            <select id="tripdays" name="tripdays" class="form-input form-input-sm" onchange="this.form.submit()">
                <option value="7" <?= $tripDays === '7' ? 'selected' : '' ?>>Last 7 days</option>
                <option value="30" <?= $tripDays === '30' ? 'selected' : '' ?>>Last 30 days</option>
                <option value="90" <?= $tripDays === '90' ? 'selected' : '' ?>>Last 90 days</option>
                <option value="all" <?= $tripDays === 'all' ? 'selected' : '' ?>>All time</option>
            </select>
        </form>
    </div>
    <div class="card-body">
        <div class="pv-details">
            <div class="pv-cell"><span class="v"><?= $tripCount ?></span><span class="l">Trips</span></div>
            <div class="pv-cell"><span class="v"><?= number_format($tripKm, 1) ?></span><span class="l">Distance (km)</span></div>
            <div class="pv-cell"><span class="v"><?= $fmtDur($tripSecs) ?></span><span class="l">Runtime</span></div>
            <div class="pv-cell"><span class="v"><?= $tripCount ? number_format($tripAvg, 1) : '—' ?></span><span class="l">Avg km / trip</span></div>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Duration</th>
                        <th>Distance (km)</th>
                        <th>Points</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recentTrips): $i = 0; foreach ($recentTrips as $t): $i++; ?>
                    <tr>
                        <td class="text-muted"><?= $i ?></td>
                        <td><?= htmlspecialchars(toDisplay(date(DATE_FORMAT, $t['start']))) ?></td>
                        <td><?= htmlspecialchars(toDisplay(date(DATE_FORMAT, $t['end']))) ?></td>
                        <td><?= $fmtDur($t['end'] - $t['start']) ?></td>
                        <td><?= number_format($t['km'], 1) ?></td>
                        <td><?= $t['pts'] ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="6" class="empty-state"><i data-lucide="route"></i><p>No trips in this period</p></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Messages Table -->
<div class="card mt-2">
    <div class="card-header">
        <div class="card-title"><i data-lucide="message-square"></i> Recent Messages</div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Lat</th>
                    <th>Lng</th>
                    <th>Speed</th>
                    <th>Activity</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($messages as $msg): ?>
                <tr>
                    <td><?= $msg['time_local'] ? htmlspecialchars($msg['time_local']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $msg['lat'] !== null ? round($msg['lat'], 6) : '<span class="text-muted">null</span>' ?></td>
                    <td><?= $msg['lng'] !== null ? round($msg['lng'], 6) : '<span class="text-muted">null</span>' ?></td>
                    <td><?= $msg['speed'] !== null ? $msg['speed'] . ' km/h' : '<span class="text-muted">null</span>' ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($msg['activity_name'] ?: (string) $msg['activity_id']) ?></span></td>
                    <td><span class="badge badge-<?= $msg['latra_status'] === 'pending' ? 'warning' : ($msg['latra_status'] === 'sent' ? 'success' : 'secondary') ?>"><?= $msg['latra_status'] ?></span></td>
                    <td>
                        <?php if (Auth::canWrite()): ?>
                        <button class="btn-icon-sm" onclick="pushToLatra(<?= $msg['id'] ?>)"><i data-lucide="send"></i></button>
                        <button class="btn-icon-sm" onclick="editAndPost(<?= $msg['id'] ?>)" title="Edit payload and post to LATRA"><i data-lucide="square-pen"></i></button>
                        <?php endif; ?>
                        <button class="btn-icon-sm" onclick="viewPayload(<?= $msg['id'] ?>)"><i data-lucide="code"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($messages)): ?>
                <tr><td colspan="7" class="empty-state"><i data-lucide="inbox"></i><p>No messages yet</p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= Layout::pagination($page, $totalPages, "vehicle_detail?id=$vehicleId") ?>
</div>

<script>
const vehicleData = <?= json_encode($vehicle, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const messageData = <?= json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

const map = L.map('vehicleMap', { zoomControl: false }).setView([vehicleData.lat || -6.7924, vehicleData.lng || 39.2083], 14);
L.control.zoom({ position: 'topright' }).addTo(map);
const osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);

const satLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    attribution: '&copy; Esri World Imagery'
});

// Map / Satellite toggle (mirrors the dashboard control)
document.querySelectorAll('.map-seg button[data-layer]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.map-seg button[data-layer]').forEach(function (b) {
            b.classList.toggle('active', b === btn);
        });
        const sat = btn.getAttribute('data-layer') === 'sat';
        if (sat) {
            if (map.hasLayer(osmLayer)) map.removeLayer(osmLayer);
            if (!map.hasLayer(satLayer)) satLayer.addTo(map);
        } else {
            if (map.hasLayer(satLayer)) map.removeLayer(satLayer);
            if (!map.hasLayer(osmLayer)) osmLayer.addTo(map);
        }
    });
});

const pointLayer = L.layerGroup().addTo(map);
const routeLayer = L.layerGroup().addTo(map);
const currentPosition = getCoordinate(vehicleData.lat, vehicleData.lng);
let currentMarker = null;

function getCoordinate(lat, lng) {
    const latitude = Number(lat);
    const longitude = Number(lng);
    return Number.isFinite(latitude) && Number.isFinite(longitude) &&
        latitude >= -90 && latitude <= 90 && longitude >= -180 && longitude <= 180
        ? [latitude, longitude]
        : null;
}

function addCurrentMarker() {
    if (!currentPosition) return;

    currentMarker = L.marker(currentPosition, {
        title: 'Current vehicle position',
        zIndexOffset: 1000
    });
    bindGeoNameTooltip(currentMarker, currentPosition[0], currentPosition[1], `<?= htmlspecialchars($vehicle['vehicle_reg_no']) ?>`);
    currentMarker.bindPopup(`
        <strong><?= htmlspecialchars($vehicle['vehicle_reg_no']) ?></strong><br>
        Current vehicle position
    `).addTo(map);
}

function showPoints(fitToPoints = false) {
    pointLayer.clearLayers();
    routeLayer.clearLayers();
    if (currentMarker) currentMarker.setZIndexOffset(1000);

    const coordinates = [];

    messageData.forEach((msg, i) => {
        const coordinate = getCoordinate(msg.lat, msg.lng);
        if (coordinate) {
            const color = i === 0 ? '#EF4444' : '#3B82F6';
            const marker = L.circleMarker(coordinate, {
                radius: i === 0 ? 8 : 5,
                fillColor: color,
                color: '#fff',
                weight: 2,
                opacity: 1,
                fillOpacity: 0.8
            }).addTo(map);
            const tooltipPrefix = `<?= htmlspecialchars($vehicle['vehicle_reg_no']) ?> — ${msg.speed || 0} km/h`;
            bindGeoNameTooltip(marker, coordinate[0], coordinate[1], tooltipPrefix);
            marker.bindPopup(`
                <strong><?= htmlspecialchars($vehicle['vehicle_reg_no']) ?></strong><br>
                Speed: ${msg.speed || 0} km/h<br>
                Time: ${msg.time_local || '—'}<br>
                Activity: ${msg.activity_name || msg.activity_id}
            `);
            marker.addTo(pointLayer);
            coordinates.push(coordinate);
        }
    });

    if (currentPosition) coordinates.push(currentPosition);
    if (fitToPoints && coordinates.length > 0) {
        map.fitBounds(L.latLngBounds(coordinates).pad(0.1));
    } else if (currentPosition) {
        map.setView(currentPosition, 16);
    }
}

function showRoute() {
    pointLayer.clearLayers();
    routeLayer.clearLayers();
    if (currentMarker) currentMarker.setZIndexOffset(1000);

    const points = messageData
        .map(msg => getCoordinate(msg.lat, msg.lng))
        .filter(coordinate => coordinate !== null);
    if (points.length > 1) {
        L.polyline(points, { color: '#3B82F6', weight: 3, opacity: 0.7 }).addTo(routeLayer);
    }

    if (points.length > 0) {
        L.marker(points[points.length - 1], { icon: L.divIcon({ className: 'start-marker', html: '<i data-lucide="play"></i>' }) }).addTo(routeLayer);
        L.marker(points[0], { icon: L.divIcon({ className: 'end-marker', html: '<i data-lucide="flag"></i>' }) }).addTo(routeLayer);
        refreshIcons();
        const routeBounds = L.latLngBounds(points);
        if (currentPosition) routeBounds.extend(currentPosition);
        map.fitBounds(routeBounds.pad(0.1));
    } else if (currentPosition) {
        map.setView(currentPosition, 14);
    }
}

addCurrentMarker();
showPoints();

function fetchMessages(id) {
    simulatedProgress(
        'Fetching Messages',
        apiJson('fetch_messages', { vehicle_id: id }),
        { subtitle: 'Downloading from Wialon...', icon: 'download' }
    ).then(() => setTimeout(() => location.reload(), 1200)).catch(() => {});
}

function syncSingleVehicle(id) {
    simulatedProgress(
        'Syncing Vehicle',
        apiJson('sync_single_vehicle', { id: id }),
        { subtitle: 'Updating vehicle data from Wialon...', icon: 'rotate-cw' }
    ).then(() => setTimeout(() => location.reload(), 1200)).catch(() => {});
}

function pushToLatra(msgId) {
    Swal.fire({
        title: 'Push to LATRA?',
        text: 'Push this message to LATRA?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, push it'
    }).then((result) => {
        if (!result.isConfirmed) return;
        simulatedProgress(
            'Pushing to LATRA',
            apiJson('push_single', { message_id: msgId }),
            { subtitle: 'Waiting for LATRA response...', icon: 'send' }
        ).catch(() => {});
    });
}

function editAndPost(msgId) {
    fetch('api.php?action=get_payload&message_id=' + msgId)
        .then(r => r.json())
        .then(data => {
            if (data.error) throw new Error(data.error);

            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.innerHTML = `
                <div class="modal modal-wide">
                    <div class="modal-header"><h3><i data-lucide="square-pen"></i> Edit LATRA Payload</h3><button onclick="this.closest('.modal-overlay').remove()" class="btn-icon"><i data-lucide="x"></i></button></div>
                    <div class="modal-body">
                        <textarea class="payload-editor" spellcheck="false"></textarea>
                        <div class="modal-actions"><button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button><button class="btn btn-primary post-payload-button"><i data-lucide="send"></i> POST to LATRA</button></div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            refreshIcons();

            const editor = modal.querySelector('.payload-editor');
            const submit = modal.querySelector('.post-payload-button');
            editor.value = JSON.stringify(data.payload, null, 2);
            submit.addEventListener('click', () => postEditedPayload(modal, editor, submit, msgId));
        })
        .catch(err => Swal.fire('Error', 'Unable to load payload: ' + err.message, 'error'));
}

function postEditedPayload(modal, editor, submit, msgId) {
    let payload;
    try {
        payload = JSON.parse(editor.value);
    } catch (error) {
        Swal.fire('Error', 'Payload must be valid JSON.', 'error');
        return;
    }

    submit.disabled = true;
    submit.innerHTML = '<i data-lucide="loader-circle"></i> Posting...';
    refreshIcons();    const body = new URLSearchParams({ message_id: msgId, payload: JSON.stringify(payload), _csrf: getCsrfToken() });

    simulatedProgress(
        'Posting to LATRA',
        fetch('api.php?action=post_to_latra', { method: 'POST', body }).then(r => r.json()),
        { subtitle: 'Waiting for LATRA response...', icon: 'send' }
    ).then(data => {
        if (data.success) {
            modal.remove();
            setTimeout(() => location.reload(), 1200);
        }
    }).catch(() => {}).finally(() => {
        submit.disabled = false;
        submit.innerHTML = '<i data-lucide="send"></i> POST to LATRA';
    refreshIcons();    });
}

function viewPayload(msgId) {
    fetch('api.php?action=get_payload&message_id=' + msgId)
        .then(r => r.json())
        .then(data => {
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.innerHTML = `
                <div class="modal">
                    <div class="modal-header"><h3><i data-lucide="code"></i> LATRA Payload</h3><button onclick="this.closest('.modal-overlay').remove()" class="btn-icon"><i data-lucide="x"></i></button></div>
                    <div class="modal-body"><pre class="json-view">${JSON.stringify(data.payload, null, 2)}</pre></div>
                </div>
            `;
            document.body.appendChild(modal);
            refreshIcons();
        });
}
</script>

<?php Layout::renderFoot(); ?>
