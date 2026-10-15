<?php
require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Layout.php';
Auth::requireLogin();
Auth::requireWrite();

if ($_SERVER['REQUEST_METHOD'] === 'POST') Auth::requireCsrf();

$vehicleId = intval($_GET['vehicle'] ?? 0);

// Get all vehicles for dropdown
$allVehicles = Database::fetchAll("SELECT id, vehicle_reg_no, name FROM vehicles WHERE is_active = 1 ORDER BY vehicle_reg_no");

// Get activity definitions
$activities = Database::fetchAll("SELECT * FROM activity_definitions ORDER BY id");

// Get existing mappings
$whereVehicle = $vehicleId ? "WHERE am.vehicle_id = ?" : "";
$params = $vehicleId ? [$vehicleId] : [];
$mappings = Database::fetchAll(
    "SELECT am.*, v.vehicle_reg_no, ad.activity_name, ad.icon, ad.category, ad.requires_addon, ad.description
    FROM activity_mappings am
    JOIN vehicles v ON am.vehicle_id = v.id
    JOIN activity_definitions ad ON am.activity_id = ad.id
    $whereVehicle
    ORDER BY v.vehicle_reg_no, am.activity_id",
    $params
);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vid = intval($_POST['vehicle_id'] ?? 0);
    $aid = intval($_POST['activity_id'] ?? 0);
    $enabled = intval($_POST['is_enabled'] ?? 0);
    $sensor = trim($_POST['wialon_sensor_name'] ?? '');
    $rule = trim($_POST['mapping_rule'] ?? '');

    if ($vid && $aid) {
        $exists = Database::fetch("SELECT id FROM activity_mappings WHERE vehicle_id = ? AND activity_id = ?", [$vid, $aid]);
        if ($exists) {
            Database::update('activity_mappings', [
                'is_enabled' => $enabled,
                'wialon_sensor_name' => $sensor,
                'mapping_rule' => $rule
            ], "vehicle_id = ? AND activity_id = ?", [$vid, $aid]);
        } else {
            Database::insert('activity_mappings', [
                'vehicle_id' => $vid,
                'activity_id' => $aid,
                'is_enabled' => $enabled,
                'wialon_sensor_name' => $sensor,
                'mapping_rule' => $rule
            ]);
        }
        header("Location: activities?vehicle=$vid&saved=1");
        exit;
    }
}

Layout::renderHead('Activity Mapping');
if ($vehicleId > 0) {
    Layout::assistContext(['vehicle_id' => $vehicleId]);
}
?>

<div class="page-header">
    <h1><i data-lucide="list-checks"></i> Activity Mapping</h1>
    <p class="text-muted">Map Wialon data to LATRA's 24 activity types. Select a vehicle to configure.</p>
</div>

<?php if (isset($_GET['saved'])): ?>
    <?= Layout::alert('success', 'Activity mapping saved successfully') ?>
<?php endif; ?>

<div class="filter-bar">
    <select id="vehicleSelect" class="searchable-select" onchange="location.href='activities?vehicle='+this.value">
        <option value="">All Vehicles</option>
        <?php foreach ($allVehicles as $v): ?>
        <option value="<?= $v['id'] ?>" <?= $vehicleId == $v['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($v['vehicle_reg_no']) ?> - <?= htmlspecialchars($v['name'] ?? '') ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="activity-grid">
    <?php foreach ($activities as $act): 
        $mapping = null;
        foreach ($mappings as $m) {
            if ($m['activity_id'] == $act['id'] && (!$vehicleId || $m['vehicle_id'] == $vehicleId)) {
                $mapping = $m; break;
            }
        }
        $vehiclesForActivity = array_filter($mappings, fn($m) => $m['activity_id'] == $act['id']);
    ?>
    <div class="activity-card <?= $mapping && $mapping['is_enabled'] ? 'enabled' : '' ?>" data-activity="<?= $act['id'] ?>">
        <div class="activity-header">
            <div class="activity-icon"><i data-lucide="<?= Icon::lucide($act['icon']) ?>"></i></div>
            <div class="activity-info">
                <h4><?= $act['id'] ?>. <?= htmlspecialchars($act['activity_name']) ?></h4>
                <span class="activity-category"><?= htmlspecialchars($act['category']) ?></span>
                <?php if ($act['requires_addon']): ?>
                    <span class="badge badge-warning">Requires addon_info</span>
                <?php endif; ?>
            </div>
            <div class="activity-toggle">
                <?php if ($vehicleId && $mapping): ?>
                <label class="toggle-switch">
                    <input type="checkbox" <?= $mapping['is_enabled'] ? 'checked' : '' ?> 
                           onchange="toggleActivity(<?= $vehicleId ?>, <?= $act['id'] ?>, this.checked)">
                    <span class="toggle-slider"></span>
                </label>
                <?php elseif (!$vehicleId): ?>
                    <span class="badge badge-secondary"><?= count($vehiclesForActivity) ?> mapped</span>
                <?php endif; ?>
            </div>
        </div>
        <p class="activity-desc"><?= htmlspecialchars($act['description'] ?? '') ?></p>

        <?php if ($vehicleId): ?>
        <div class="activity-form">
            <form method="POST" class="inline-form">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
                <input type="hidden" name="activity_id" value="<?= $act['id'] ?>">
                <input type="hidden" name="is_enabled" value="<?= $mapping && $mapping['is_enabled'] ? '1' : '0' ?>" id="enabled_<?= $act['id'] ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label>Wialon Sensor/Param</label>
                        <input type="text" name="wialon_sensor_name" value="<?= htmlspecialchars($mapping['wialon_sensor_name'] ?? '') ?>" 
                               placeholder="e.g. ignition, fuel, pwr_ext" class="form-input">
                    </div>
                    <div class="form-group">
                        <label>Mapping Rule (JSON)</label>
                        <input type="text" name="mapping_rule" value="<?= htmlspecialchars($mapping['mapping_rule'] ?? '') ?>" 
                               placeholder='{"threshold": 80}' class="form-input">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary"><i data-lucide="save"></i></button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($act['addon_schema']): ?>
        <div class="addon-schema">
            <small class="text-muted"><strong>addon_info schema:</strong> <?= htmlspecialchars($act['addon_schema']) ?></small>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<script>
function toggleActivity(vehicleId, activityId, enabled) {
    document.getElementById('enabled_' + activityId).value = enabled ? '1' : '0';
    apiJson('toggle_activity', { vehicle_id: vehicleId, activity_id: activityId, enabled: enabled ? 1 : 0 })
        .then(data => {
            const card = document.querySelector('[data-activity="' + activityId + '"]');
            if (data.success !== false) card.classList.toggle('enabled', enabled);
        });
}
</script>

<?php Layout::renderFoot(); ?>
