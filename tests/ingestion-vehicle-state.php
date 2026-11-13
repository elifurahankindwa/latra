<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/EventDetector.php';

use Latra\Infrastructure\Persistence\LegacyIngestionRepository;
use Latra\Infrastructure\Persistence\PdoVehicleStateRepository;

$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(
    'CREATE TABLE vehicle_state (
        vehicle_id INTEGER PRIMARY KEY,
        engine_status TEXT,
        last_speed_kmh NUMERIC,
        last_lat NUMERIC,
        last_lng NUMERIC,
        last_event_activity_id INTEGER,
        last_event_at TEXT,
        overspending_active INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT
    )',
);

$repository = new PdoVehicleStateRepository($database);
if ($repository->find(7) !== []) {
    throw new RuntimeException('Missing vehicle state did not read as an empty array');
}

$repository->save(7, [
    'engine_status' => 'on',
    'last_speed_kmh' => 44.5,
    'last_event_activity_id' => 3,
    'last_event_at' => '2026-11-13 08:00:00',
    'overspending_active' => 1,
]);
$state = $repository->find(7);
if ($state === [] || (int) $state['vehicle_id'] !== 7 || $state['engine_status'] !== 'on'
    || (float) $state['last_speed_kmh'] !== 44.5 || (int) $state['last_event_activity_id'] !== 3
    || (int) $state['overspending_active'] !== 1) {
    throw new RuntimeException('Inserted vehicle state could not be read back');
}

// A partial write must update only the supplied columns and leave the rest of the row intact.
$repository->save(7, ['engine_status' => 'off', 'overspending_active' => 0]);
$state = $repository->find(7);
if ($state['engine_status'] !== 'off' || (int) $state['overspending_active'] !== 0
    || (float) $state['last_speed_kmh'] !== 44.5
    || $state['last_event_at'] !== '2026-11-13 08:00:00') {
    throw new RuntimeException('Vehicle state update dropped or overwrote unrelated columns');
}
$count = (int) $database->query('SELECT COUNT(*) FROM vehicle_state')->fetchColumn();
if ($count !== 1) {
    throw new RuntimeException('Vehicle state update created an additional row');
}

$databaseInstance = new ReflectionProperty(Database::class, 'instance');
$sensitiveConfigScrubbed = new ReflectionProperty(Database::class, 'sensitiveConfigScrubbed');
$previousDatabase = $databaseInstance->getValue();
$previousScrubState = $sensitiveConfigScrubbed->getValue();
try {
    $databaseInstance->setValue(null, $database);
    $sensitiveConfigScrubbed->setValue(null, true);

    $legacy = new LegacyIngestionRepository();
    if ((int) $legacy->loadVehicleState(7)['vehicle_id'] !== 7) {
        throw new RuntimeException('Legacy adapter did not read the existing vehicle state');
    }

    $legacy->beginMessage();
    $legacy->persistVehicleState(8, ['engine_status' => 'on', 'overspending_active' => 2]);
    if ($legacy->loadVehicleState(8) === []) {
        throw new RuntimeException('Legacy adapter state write did not join the message transaction');
    }
    $legacy->rollbackMessage();
    if ($database->inTransaction() || $legacy->loadVehicleState(8) !== []) {
        throw new RuntimeException('Vehicle state write escaped the rolled-back message transaction');
    }

    $legacy->persistVehicleState(9, ['engine_status' => 'off']);
    if ((int) $legacy->loadVehicleState(9)['vehicle_id'] !== 9) {
        throw new RuntimeException('Legacy adapter did not persist state outside a transaction');
    }

    // The public compatibility entry point keeps its insert/update contract.
    EventDetector::persistState(9, ['engine_status' => 'on']);
    $database->exec('UPDATE vehicle_state SET engine_status = \'off\' WHERE vehicle_id = 9');
    EventDetector::persistState(9, ['engine_status' => 'on']);
    if ($legacy->loadVehicleState(9)['engine_status'] !== 'on'
        || (int) $database->query('SELECT COUNT(*) FROM vehicle_state WHERE vehicle_id = 9')->fetchColumn() !== 1) {
        throw new RuntimeException('EventDetector::persistState did not preserve the update path');
    }
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    $databaseInstance->setValue(null, $previousDatabase);
    $sensitiveConfigScrubbed->setValue(null, $previousScrubState);
}

echo "PASS vehicle state read, insert, partial update, transaction scope and compatibility delegate\n";
