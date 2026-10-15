<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Layout.php';
Auth::requireLogin();
Auth::requireView('vehicles.view');

$page = max(1, intval($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$down   = $_GET['down'] ?? '';
if (!in_array($down, ['', 'today', 'week'], true)) $down = '';
$perPage = ITEMS_PER_PAGE;
$offset = ($page - 1) * $perPage;

// Build query
$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = '(vehicle_reg_no LIKE ? OR name LIKE ? OR imei LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like]);
}

if ($status !== '') {
    $where[] = 'is_active = ?';
    $params[] = $status;
}

if ($down !== '') {
    $threshold = time() - ($down === 'week' ? 604800 : 86400);
    $where[] = '(last_message_time IS NULL OR last_message_time <= ?)';
    $params[] = $threshold;
}

$whereSql = implode(' AND ', $where);

if (($_GET['export'] ?? '') === 'csv') {
    require_once __DIR__ . '/includes/Export.php';
    $all = Database::fetchAll(
        "SELECT v.id, v.vehicle_reg_no, v.name, v.imei, v.is_active, v.last_message_time, v.lat, v.lng, v.updated_at,
            (SELECT COUNT(*) FROM activity_mappings am WHERE am.vehicle_id = v.id AND am.is_enabled = 1) AS mapped_activities,
            (SELECT COUNT(*) FROM message_log ml WHERE ml.vehicle_id = v.id) AS message_count,
            (SELECT COUNT(*) FROM push_log pl WHERE pl.vehicle_id = v.id) AS push_count
        FROM vehicles v WHERE $whereSql ORDER BY v.updated_at DESC",
        $params
    );
    Export::stream('vehicles_export', [
        'ID', 'Registration', 'Name', 'IMEI', 'Active',
        'Last message (UTC)', 'Lat', 'Lng', 'Mapped activities', 'Messages', 'Pushes', 'Updated (UTC)',
    ], array_map(fn($v) => [
        $v['id'], $v['vehicle_reg_no'], $v['name'], $v['imei'],
        $v['is_active'] ? 'Yes' : 'No',
        $v['last_message_time'] ? date('Y-m-d H:i:s', (int) $v['last_message_time']) : '',
        $v['lat'], $v['lng'],
        (int) $v['mapped_activities'], (int) $v['message_count'], (int) $v['push_count'],
        $v['updated_at'],
    ], $all));
}

// Count
$countRow = Database::fetch("SELECT COUNT(*) as c FROM vehicles WHERE $whereSql", $params);
$totalItems = $countRow['c'] ?? 0;
$totalPages = max(1, ceil($totalItems / $perPage));

// Fetch vehicles
$vehicles = Database::fetchAll(
    "SELECT v.*, 
        (SELECT COUNT(*) FROM activity_mappings am WHERE am.vehicle_id = v.id AND am.is_enabled = 1) as mapped_activities,
        (SELECT COUNT(*) FROM message_log ml WHERE ml.vehicle_id = v.id) as message_count,
        (SELECT COUNT(*) FROM push_log pl WHERE pl.vehicle_id = v.id) as push_count
    FROM vehicles v 
    WHERE $whereSql 
    ORDER BY v.updated_at DESC 
    LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// Activity definitions for bulk action dropdown
$activityDefs = Database::fetchAll("SELECT id, activity_name FROM activity_definitions ORDER BY id");

Layout::renderHead('Vehicles');
?>

<div class="page-header">
    <h1><i data-lucide="truck"></i> Vehicles</h1>
    <div class="page-actions">
        <?php if (Auth::canWrite()): ?>
        <button class="btn btn-primary" onclick="syncWialon(event)"><i data-lucide="rotate-cw"></i> Sync from Wialon</button>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars('vehicles?export=csv' . ($search ? '&search=' . urlencode($search) : '') . ($status !== '' ? '&status=' . urlencode($status) : '') . ($down ? '&down=' . urlencode($down) : '')) ?>"><i data-lucide="download"></i> Export CSV</a>
    </div>
</div>

<!-- Filters -->
<div class="filter-bar">
    <div class="search-box">
        <i data-lucide="search"></i>
        <input type="text" id="vehicleSearch" placeholder="Search by reg no, name, IMEI..." value="<?= htmlspecialchars($search) ?>" onkeyup="debounceSearch()">
    </div>
    <select id="statusFilter" onchange="applyFilters()">
        <option value="">All Status</option>
        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Active</option>
        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inactive</option>
    </select>
</div>

<!-- Vehicles Table -->
<div class="card">
    <div class="table-responsive">
        <table class="data-table" id="vehiclesTable">
            <thead>
                <tr>
                    <?php if (Auth::canWrite()): ?>
                    <th class="col-check">
                        <input type="checkbox" class="row-checkbox" id="selectAll" title="Select all">
                    </th>
                    <?php endif; ?>
                    <th>Reg. No</th>
                    <th>Name</th>
                    <th>IMEI</th>
                    <th>Last Position</th>
                    <th>Speed</th>
                    <th>Mapped Activities</th>
                    <th>Messages</th>
                    <th>Pushes</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vehicles as $v): ?>
                <tr data-id="<?= $v['id'] ?>" data-search="<?= htmlspecialchars(strtolower($v['vehicle_reg_no'] . ' ' . $v['name'] . ' ' . $v['imei'])) ?>">
                    <?php if (Auth::canWrite()): ?>
                    <td class="col-check">
                        <input type="checkbox" class="row-checkbox vehicle-check" data-id="<?= $v['id'] ?>" data-reg="<?= htmlspecialchars($v['vehicle_reg_no']) ?>">
                    </td>
                    <?php endif; ?>
                    <td>
                        <div class="vehicle-cell">
                            <span class="vehicle-reg"><?= htmlspecialchars($v['vehicle_reg_no']) ?></span>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($v['name'] ?? '-') ?></td>
                    <td class="font-mono text-muted"><?= htmlspecialchars($v['imei'] ?? '-') ?></td>
                    <td>
                        <?php if ($v['lat'] && $v['lng']): ?>
                            <span class="text-muted"><?= round($v['lat'], 4) ?>, <?= round($v['lng'], 4) ?></span>
                        <?php else: ?>
                            <span class="text-muted">No position</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $v['speed'] !== null ? $v['speed'] . ' km/h' : '<span class="text-muted">-</span>' ?></td>
                    <td>
                        <span class="badge <?= $v['mapped_activities'] > 0 ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $v['mapped_activities'] ?>/24
                        </span>
                    </td>
                    <td><?= number_format($v['message_count']) ?></td>
                    <td><?= number_format($v['push_count']) ?></td>
                    <td>
                        <span class="status-dot <?= $v['is_active'] ? 'active' : 'inactive' ?>"></span>
                        <?= $v['is_active'] ? 'Active' : 'Inactive' ?>
                    </td>
                    <td>
                        <div class="action-group">
                            <a href="vehicle_detail?id=<?= $v['id'] ?>" class="btn-icon-sm" title="View Details"><i data-lucide="eye"></i></a>
                            <?php if (Auth::canWrite()): ?>
                            <a href="activities?vehicle=<?= $v['id'] ?>" class="btn-icon-sm" title="Manage Activities"><i data-lucide="list-checks"></i></a>
                            <button class="btn-icon-sm" onclick="fetchMessages(<?= $v['id'] ?>)" title="Fetch Messages"><i data-lucide="download"></i></button>
                            <button class="btn-icon-sm" onclick="toggleVehicle(<?= $v['id'] ?>, <?= $v['is_active'] ? 0 : 1 ?>)" title="<?= $v['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                <i data-lucide="<?= Icon::fixStripped($v['is_active'] ? 'pause' : 'play') ?>"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($vehicles)): ?>
                <tr><td colspan="<?= Auth::canWrite() ? 11 : 10 ?>" class="empty-state"><i data-lucide="truck"></i><p>No vehicles found<?= Auth::canWrite() ? '. <a href="#" onclick="syncWialon(event)">Sync from Wialon</a>' : '' ?>.</p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= Layout::pagination($page, $totalPages, 'vehicles?search=' . urlencode($search) . '&status=' . $status) ?>
</div>

<!-- Bulk Actions Bar -->
<?php if (Auth::canWrite()): ?>
<div class="bulk-bar" id="bulkBar">
    <div class="bulk-bar-selection">
        <div class="bulk-bar-count"><i data-lucide="check-check"></i><strong id="bulkCount">0</strong> vehicle(s) selected</div>
        <div class="bulk-bar-divider"></div>
        <label class="bulk-bar-field" for="bulkActivity">
            <span class="bulk-bar-label">Task / Activity</span>
            <select id="bulkActivity">
                <option value="">Select an activity</option>
                <?php foreach ($activityDefs as $act): ?>
                <option value="<?= $act['id'] ?>"><?= $act['id'] ?>. <?= htmlspecialchars($act['activity_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="bulk-bar-actions">
        <button class="bulk-bar-btn bulk-bar-btn-secondary" onclick="bulkFetchMessages()" id="btnBulkFetch">
            <i data-lucide="download"></i> Bulk Fetch Messages
        </button>
        <button class="bulk-bar-btn bulk-bar-btn-primary" onclick="bulkPushActivity()" id="btnBulkPush">
            <i data-lucide="send"></i> Bulk Push to LATRA
        </button>
        <button class="bulk-bar-btn bulk-bar-btn-secondary" onclick="clearSelection()" title="Deselect all">
            <i data-lucide="x"></i>
        </button>
    </div>
</div>
<?php endif; ?>

<script>
let searchTimeout;
function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 400);
}

function applyFilters() {
    const search = document.getElementById('vehicleSearch').value;
    const status = document.getElementById('statusFilter').value;
    window.location.href = 'vehicles?search=' + encodeURIComponent(search) + '&status=' + status;
}

// Single-vehicle operations

function fetchMessages(vehicleId) {
    Swal.fire({
        title: 'Fetch Messages',
        text: 'Fetch latest messages from Wialon for this vehicle?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, fetch'
    }).then((result) => {
        if (!result.isConfirmed) return;
        simulatedProgress(
            'Fetching Messages',
            apiJson('fetch_messages', { vehicle_id: vehicleId }),
            { subtitle: 'Downloading from Wialon...', icon: 'download' }
        ).catch(() => {});
    });
}

function toggleVehicle(id, status) {
    apiJson('toggle_vehicle', { id: id, status: status })
        .then(() => location.reload())
        .catch(err => Swal.fire('Error', 'Error: ' + err.message, 'error'));
}

//  Bulk checkbox logic 

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.vehicle-check:checked')).map(cb => cb.dataset.id);
}

function updateBulkBar() {
    const ids = getSelectedIds();
    const bar = document.getElementById('bulkBar');
    const count = document.getElementById('bulkCount');
    count.textContent = ids.length;
    bar.classList.toggle('visible', ids.length > 0);

    // Highlight selected rows
    document.querySelectorAll('#vehiclesTable tbody tr').forEach(row => {
        const cb = row.querySelector('.vehicle-check');
        row.classList.toggle('row-selected', cb && cb.checked);
    });
}

function clearSelection() {
    document.querySelectorAll('.vehicle-check').forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateBulkBar();
}

document.getElementById('selectAll').addEventListener('change', function() {
    document.querySelectorAll('.vehicle-check').forEach(cb => {
        // Only toggle visible rows
        const row = cb.closest('tr');
        if (!row || row.style.display !== 'none') cb.checked = this.checked;
    });
    updateBulkBar();
});

document.querySelectorAll('.vehicle-check').forEach(cb => {
    cb.addEventListener('change', () => {
        const all = document.querySelectorAll('.vehicle-check');
        const allChecked = Array.from(all).every(c => c.checked);
        document.getElementById('selectAll').checked = allChecked;
        updateBulkBar();
    });
});

// Bulk operations

function bulkFetchMessages() {
    const ids = getSelectedIds();
    if (!ids.length) { Swal.fire('No Selection', 'Please select at least one vehicle.', 'warning'); return; }

    Swal.fire({
        title: 'Bulk Fetch Messages',
        text: `Fetch messages from Wialon for ${ids.length} vehicle(s)?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, fetch all'
    }).then(r => {
        if (!r.isConfirmed) return;
        const url = sseUrl('bulk_fetch_messages', { vehicle_ids: ids.join(',') });
        sseProgress('Bulk Fetching Messages', url, {
            subtitle: `Processing ${ids.length} vehicle(s) from Wialon...`,
            icon: 'download',
            onDone: (data) => {
                setTimeout(() => location.reload(), 1800);
            }
        });
    });
}

function bulkPushActivity() {
    const ids = getSelectedIds();
    const aid = document.getElementById('bulkActivity').value;

    if (!ids.length) { Swal.fire('No Selection', 'Please select at least one vehicle.', 'warning'); return; }
    if (!aid) { Swal.fire('No Activity', 'Please select an activity from the dropdown.', 'warning'); return; }

    const activityText = document.getElementById('bulkActivity').options[document.getElementById('bulkActivity').selectedIndex].text;

    Swal.fire({
        title: 'Bulk Push to LATRA',
        html: `Push <strong>${activityText}</strong> to LATRA for <strong>${ids.length}</strong> vehicle(s)?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, push all'
    }).then(r => {
        if (!r.isConfirmed) return;
        const url = sseUrl('bulk_push_activity', { vehicle_ids: ids.join(','), activity_id: aid });
        sseProgress('Bulk Push to LATRA', url, {
            subtitle: `Sending ${activityText} for ${ids.length} vehicle(s)...`,
            icon: 'send',
            onDone: () => {
                setTimeout(() => location.reload(), 1800);
            }
        });
    });
}
</script>

<?php Layout::renderFoot(); ?>
