<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Layout.php';
Auth::requireView('integration.view');

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE;
$offset = ($page - 1) * $perPage;
$statusFilter = $_GET['status'] ?? '';
$statusConditions = [
    'sent' => "pl.response_status = '1'",
    'pending' => 'pl.response_status IS NULL',
    'failed' => "pl.response_status IS NOT NULL AND pl.response_status <> '1'"
];
$statusWhere = $statusConditions[$statusFilter] ?? '';
$whereSql = $statusWhere ? ' WHERE ' . $statusWhere : '';

if (($_GET['export'] ?? '') === 'csv') {
    require_once __DIR__ . '/includes/Export.php';
    // Long exports must not hold the authenticated user's session lock.
    session_write_close();
    // The iterable keeps CSV memory bounded as delivery history grows.
    Export::stream('push_log_export', [
        'ID', 'Vehicle', 'Activity', 'HTTP', 'Status', 'Latency (ms)', 'Time (UTC)', 'Payload',
    ], Export::pushLogRows($statusFilter));
}

$total = Database::fetch("SELECT COUNT(*) as c FROM push_log pl$whereSql")['c'] ?? 0;
$totalPages = max(1, ceil($total / $perPage));

$logs = Database::fetchAll(
    "SELECT pl.*, v.vehicle_reg_no, ad.activity_name 
    FROM push_log pl 
    LEFT JOIN vehicles v ON pl.vehicle_id = v.id 
    LEFT JOIN activity_definitions ad ON pl.activity_id = ad.id 
    $whereSql
    ORDER BY pl.created_at DESC LIMIT ? OFFSET ?",
    [$perPage, $offset]
);

$activeVehicles = Database::fetchAll("SELECT id, vehicle_reg_no FROM vehicles WHERE is_active = 1");
$allActivities = Database::fetchAll("SELECT id, activity_name FROM activity_definitions ORDER BY id");

Layout::renderHead('Push Log');
?>

<div class="page-header" style="display:flex; justify-content:space-between; align-items:center;">
    <h1><i data-lucide="send"></i> Push Log</h1>
    <?php if (Auth::canWrite()): ?>
    <button class="btn btn-primary" onclick="openManualPush()"><i data-lucide="upload"></i> Push to LATRA</button>
    <?php endif; ?>
    <a class="btn btn-secondary" href="<?= htmlspecialchars('push_log.php?export=csv' . ($statusFilter ? '&status=' . urlencode($statusFilter) : '')) ?>"><i data-lucide="download"></i> Export CSV</a>
</div>

<form method="GET" class="filter-bar">
    <label for="statusFilter"><strong>Status</strong></label>
    <select id="statusFilter" name="status" class="form-input" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent</option>
        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
        <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
    </select>
    <?php if ($statusFilter): ?><a class="btn btn-secondary btn-sm" href="push_log.php">Clear</a><?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Vehicle</th>
                    <th>Activity</th>
                    <th>HTTP</th>
                    <th>Status</th>
                    <th>Latency</th>
                    <th>Time</th>
                    <th>Payload</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td>#<?= $log['id'] ?></td>
                    <td><?= htmlspecialchars($log['vehicle_reg_no'] ?? 'N/A') ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($log['activity_name'] ?? $log['activity_id']) ?></span></td>
                    <td><code><?= $log['http_code'] ?? '-' ?></code></td>
                    <td>
                        <?php if ($log['response_status'] === '1'): ?>
                            <span class="badge badge-success"><i data-lucide="check"></i> OK</span>
                        <?php elseif ($log['response_status'] === null): ?>
                            <span class="badge badge-warning">Pending</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Failed</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $log['latency_ms'] ? $log['latency_ms'] . 'ms' : '-' ?></td>
                    <td class="text-muted"><?= $log['created_at'] ?></td>
                    <td>
                        <button class="btn-icon-sm" onclick="viewJson(<?= $log['id'] ?>, 'payload')" title="View Payload"><i data-lucide="code"></i></button>
                        <button class="btn-icon-sm" onclick="viewJson(<?= $log['id'] ?>, 'response')" title="View Response"><i data-lucide="reply"></i></button>
                        <?php if ($log['response_status'] !== '1'): ?>
                            <?php if (Auth::canWrite()): ?>
                            <button class="btn-icon-sm text-primary" onclick="retryPush(<?= $log['id'] ?>)" title="Retry Push"><i data-lucide="rotate-cw"></i></button>
                            <button class="btn-icon-sm text-warning" onclick="editPush(<?= $log['id'] ?>, <?= (int)($log['vehicle_id'] ?? 0) ?>, <?= (int)($log['activity_id'] ?? 0) ?>)" title="Edit Payload"><i data-lucide="square-pen"></i></button>
                            <button class="btn-icon-sm text-danger" onclick="deletePush(<?= $log['id'] ?>)" title="Delete Log"><i data-lucide="trash-2"></i></button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                <tr><td colspan="8" class="empty-state"><i data-lucide="inbox"></i><p>No push history</p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= Layout::pagination($page, $totalPages, $statusFilter ? 'push_log.php?status=' . urlencode($statusFilter) : 'push_log.php?') ?>
</div>

<style>
.modal { background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); padding: 20px; max-width: 800px; width: 100%; margin: 2rem auto; display: flex; flex-direction: column; max-height: 90vh; }
.modal-overlay { background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); position: fixed; inset: 0; display: flex; justify-content: center; align-items: flex-start; z-index: 1000; overflow-y: auto; }
.modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }
.modal-header h3 { margin: 0; font-size: 1.25rem; color: #333; }
.json-view { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 8px; overflow-x: auto; font-family: 'Consolas', monospace; font-size: 13px; max-height: 50vh; }
.copy-btn { position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.1); border: none; color: #fff; padding: 5px 10px; border-radius: 4px; cursor: pointer; transition: 0.2s; }
.copy-btn:hover { background: rgba(255,255,255,0.2); }
.json-container { position: relative; }
.select2-container { width: 100% !important; }
.select2-selection { height: 42px !important; border: 1px solid #ddd !important; border-radius: 8px !important; display: flex !important; align-items: center; }
.form-group label { font-weight: 500; margin-bottom: 8px; display: block; color: #555; }
.radio-group { display: flex; gap: 20px; margin-bottom: 15px; }
.radio-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500; color: #444; }
</style>

<script>
function viewJson(id, type) {
    fetch('api.php?action=get_' + type + '&log_id=' + id)
        .then(r => r.json())
        .then(data => {
            const strData = JSON.stringify(data, null, 2);
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.innerHTML = `
                <div class="modal">
                    <div class="modal-header">
                        <h3><i data-lucide="code"></i> ${type.toUpperCase()}</h3>
                        <button onclick="this.closest('.modal-overlay').remove()" class="btn-icon"><i data-lucide="x"></i></button>
                    </div>
                    <div class="modal-body">
                        <div class="json-container">
                            <button class="copy-btn" onclick="navigator.clipboard.writeText(this.nextElementSibling.innerText); Swal.fire({toast:true, position:'top-end', icon:'success', title:'Copied!', showConfirmButton:false, timer:1500});"><i data-lucide="copy"></i> Copy</button>
                            <pre class="json-view">${strData}</pre>
                        </div>
                    </div>
                </div>`;
            document.body.appendChild(modal);
            refreshIcons();
        });
}

function copyToClipboard(elementId) {
    const el = document.getElementById(elementId);
    const text = el.value || el.textContent;
    navigator.clipboard.writeText(text).then(() => {
        Swal.fire({toast:true, position:'top-end', icon:'success', title:'Copied to clipboard!', showConfirmButton:false, timer:1500});
    });
}
</script>

<div id="manualPushModal" class="modal-overlay" style="display:none;">
    <div class="modal">
        <div class="modal-header">
            <h3><i data-lucide="upload"></i> Manual Push to LATRA</h3>
            <button onclick="document.getElementById('manualPushModal').style.display='none'" class="btn-icon"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group radio-group">
                <label class="radio-label">
                    <input type="radio" name="mp_mode" value="single" checked onchange="markPayloadNeedsUpdate()"> Single Event
                </label>
                <label class="radio-label">
                    <input type="radio" name="mp_mode" value="bulk" onchange="markPayloadNeedsUpdate()"> Bulk (Last 10 Events)
                </label>
            </div>
            <div class="form-group">
                <label>Select Vehicle</label>
                <select id="mp_vehicle_id" class="form-control select2">
                    <?php foreach ($activeVehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['vehicle_reg_no']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Select Activity</label>
                <select id="mp_activity_id" class="form-control select2">
                    <?php foreach ($allActivities as $a): ?>
                        <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['activity_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="mp_preview_container" style="display:none;">
                <label style="display:flex; justify-content:space-between; align-items:center;">
                    Payload Preview
                    <span class="payload-actions">
                        <button type="button" class="btn btn-sm btn-secondary" id="mp_update_btn" onclick="previewPayload()"><i data-lucide="rotate-cw"></i> Update</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="copyToClipboard('mp_payload_display')"><i data-lucide="copy"></i> Copy</button>
                    </span>
                </label>
                <div class="json-container">
                    <pre id="mp_payload_display" class="json-view" style="margin-top:0;" contenteditable="true" spellcheck="false" title="You can edit this JSON directly before pushing"></pre>
                </div>
                <div id="mp_meta_note" class="form-help" style="display:none;"></div>
            </div>
            <div class="form-group" style="text-align:right; margin-top:20px;">
                <button class="btn btn-primary btn-lg" onclick="pushCustomPayload()" id="mp_push_btn" style="display:none; width:100%;"><i data-lucide="send"></i> Push Data to LATRA</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2({
        dropdownParent: $('#manualPushModal')
    });

    $('#mp_vehicle_id, #mp_activity_id').on('change', function() {
        markPayloadNeedsUpdate();
    });
});

function openManualPush() {
    document.getElementById('manualPushModal').style.display = 'flex';
    document.getElementById('mp_preview_container').style.display = 'none';
    document.getElementById('mp_push_btn').style.display = 'none';
    setTimeout(() => previewPayload(), 100);
}

function markPayloadNeedsUpdate() {
    const preview = document.getElementById('mp_preview_container');
    const updateBtn = document.getElementById('mp_update_btn');
    const pushBtn = document.getElementById('mp_push_btn');

    if (preview.style.display === 'none') {
        return;
    }

    updateBtn.classList.add('btn-warning');
    updateBtn.innerHTML = '<i data-lucide="rotate-cw"></i> Update JSON';
    refreshIcons();    pushBtn.disabled = true;
}

function previewPayload() {
    const vid = document.getElementById('mp_vehicle_id').value;
    const aid = document.getElementById('mp_activity_id').value;
    const mode = document.querySelector('input[name="mp_mode"]:checked').value;
    const updateBtn = document.getElementById('mp_update_btn');
    const pushBtn = document.getElementById('mp_push_btn');
    
    if(!vid || !aid) return;

    updateBtn.disabled = true;
    updateBtn.classList.remove('btn-warning');
    updateBtn.innerHTML = '<i data-lucide="loader-circle"></i> Updating...';
    refreshIcons();
    fetch(`api.php?action=generate_payload&vehicle_id=${vid}&activity_id=${aid}&mode=${mode}`)
        .then(r => r.json())
        .then(data => {
            if (data.payload) {
                document.getElementById('mp_preview_container').style.display = 'block';
                document.getElementById('mp_payload_display').textContent = JSON.stringify(data.payload, null, 2);
                pushBtn.style.display = 'inline-block';
                pushBtn.disabled = false;

                const note = document.getElementById('mp_meta_note');
                if (data.meta && data.meta.source_time) {
                    const t = new Date(data.meta.source_time * 1000).toLocaleString();
                    const src = data.meta.source === 'live' ? 'Live Wialon' : (data.meta.source === 'stored' ? 'stored message' : 'vehicle record');
                    const fresh = data.meta.is_recent ? 'Up to date' : '<b style="color:var(--danger)">Stale — no fresher data available</b>';
                    note.style.display = 'flex';
                    note.innerHTML = `<i data-lucide="info"></i> Source: ${src} (${t}) — ${fresh}`;
                    refreshIcons();
                } else {
                    note.style.display = 'none';
                }
            } else {
                Swal.fire('Error', 'Error generating payload: ' + (data.error || 'Unknown error'), 'error');
            }
        })
        .catch(() => {
            Swal.fire('Error', 'Unable to update payload preview.', 'error');
        })
        .finally(() => {
            updateBtn.disabled = false;
            updateBtn.innerHTML = '<i data-lucide="rotate-cw"></i> Update';
    refreshIcons();        });
}

function pushCustomPayload() {
    const vid = document.getElementById('mp_vehicle_id').value;
    const aid = document.getElementById('mp_activity_id').value;
    const payload = document.getElementById('mp_payload_display').textContent;
    
    Swal.fire({
        title: 'Are you sure?',
        text: "You are about to push this payload to LATRA.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, push it!'
    }).then((result) => {
        if (result.isConfirmed) {
            const btn = document.getElementById('mp_push_btn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader-circle"></i> Pushing...';
    refreshIcons();            
            const fd = new FormData();
            fd.append('action', 'push_custom_payload');
            fd.append('vehicle_id', vid);
            fd.append('activity_id', aid);
            fd.append('payload', payload);
            fd.append('_csrf', getCsrfToken());
            
            simulatedProgress(
                'Pushing to LATRA',
                fetch('api.php', { method: 'POST', body: fd }).then(r => r.json()),
                { subtitle: 'Waiting for LATRA response...', icon: 'send' }
            ).then(data => {
                if (data.success) location.reload();
            }).catch(() => {})
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    refreshIcons();
                });
        }
    });
}

function retryPush(id) {
    Swal.fire({
        title: 'Retry Push?',
        text: "This resends the exact same payload. If LATRA rejected the format, use Edit and Update first.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, retry'
    }).then((result) => {
        if (result.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'retry_push');
            fd.append('id', id);
            fd.append('_csrf', getCsrfToken());
            
            simulatedProgress(
                'Retrying LATRA Push',
                fetch('api.php', { method: 'POST', body: fd }).then(r => r.json()),
                { subtitle: 'Waiting for LATRA response...', icon: 'rotate-cw' }
            ).then(() => setTimeout(() => location.reload(), 1200)).catch(() => {});
        }
    });
}

function deletePush(id) {
    Swal.fire({
        title: 'Delete Log?',
        text: "This will permanently remove this push log entry.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it'
    }).then((result) => {
        if (result.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'delete_push');
            fd.append('id', id);
            fd.append('_csrf', getCsrfToken());
            
            fetch('api.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Deleted!', 'Log entry has been deleted.', 'success').then(() => location.reload());
                    }
                });
        }
    });
}

function setSelectValue(selectId, value) {
    const select = document.getElementById(selectId);
    if (!select || !value) return;

    select.value = String(value);
    if (window.jQuery) {
        $('#' + selectId).trigger('change.select2');
    }
}

function editPush(id, vehicleId, activityId) {
    fetch('api.php?action=get_payload&log_id=' + id)
        .then(r => r.json())
        .then(data => {
            const payload = data.payload || data;
            if (payload && !payload.error) {
                // Open manual push modal
                document.getElementById('manualPushModal').style.display = 'flex';
                
                // Set radio to single (to avoid auto-fetching bulk)
                document.querySelector('input[name="mp_mode"][value="single"]').checked = true;
                setSelectValue('mp_vehicle_id', vehicleId);
                setSelectValue('mp_activity_id', activityId || payload.items?.[0]?.activity_id);
                
                // Show preview container and set payload
                document.getElementById('mp_preview_container').style.display = 'block';
                let parsed;
                try {
                    parsed = typeof payload === 'string' ? JSON.parse(payload) : payload;
                } catch(e) { parsed = payload; }
                
                document.getElementById('mp_payload_display').textContent = JSON.stringify(parsed, null, 2);
                const pushBtn = document.getElementById('mp_push_btn');
                const updateBtn = document.getElementById('mp_update_btn');
                pushBtn.style.display = 'inline-block';
                pushBtn.disabled = true;
                updateBtn.classList.add('btn-warning');
                updateBtn.innerHTML = '<i data-lucide="rotate-cw"></i> Update JSON';
    refreshIcons();                
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'info',
                    title: 'Payload loaded. Click Update JSON to rebuild it from LATRA format before pushing.',
                    showConfirmButton: false,
                    timer: 4000
                });
            } else {
                Swal.fire('Error', data.error || 'Unable to load payload for editing.', 'error');
            }
        })
        .catch(() => {
            Swal.fire('Error', 'Unable to load payload for editing.', 'error');
        });
}
</script>

<?php Layout::renderFoot(); ?>
