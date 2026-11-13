<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/GpsValidator.php';

use Latra\Infrastructure\Persistence\LegacyIngestionRepository;
use Latra\Infrastructure\Persistence\PdoValidationEvidenceRepository;

$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(
    'CREATE TABLE validation_errors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_log_id INTEGER,
        vehicle_id INTEGER,
        vehicle_reg_no TEXT,
        imei TEXT,
        field TEXT NOT NULL,
        invalid_value TEXT,
        reason TEXT NOT NULL,
        error_code TEXT,
        activity_id INTEGER,
        validation_status TEXT NOT NULL DEFAULT \'failed\',
        gps_timestamp INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )',
);

$repository = new PdoValidationEvidenceRepository($database);

// No errors must not open/write anything, even without a usable connection.
$unconnected = new PdoValidationEvidenceRepository(
    new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]),
);
if ($unconnected->recordErrors(['id' => 1], []) !== 0) {
    throw new RuntimeException('Empty validation evidence reported a write');
}

$message = [
    'id' => 41,
    'vehicle_id' => 7,
    'vehicle_reg_no' => 'SYNTH-7',
    'imei' => '123456789012345',
    'activity_id' => 3,
    'wialon_msg_time' => 1_800_000_123,
];
$errors = [
    ['field' => 'lat', 'value' => 95.5, 'reason' => 'Must be within [-90, 90]', 'code' => 'INVALID_COORDINATES'],
    ['field' => 'speed', 'value' => ['nested' => true], 'reason' => 'Must be numeric', 'code' => 'INVALID_SPEED'],
    ['field' => 'bearing', 'reason' => 'Bearing out of range [0, 360)'],
];
if ($repository->recordErrors($message, $errors) !== 3) {
    throw new RuntimeException('Validation evidence did not report the written row count');
}

$rows = $database->query('SELECT * FROM validation_errors ORDER BY id')->fetchAll();
if (count($rows) !== 3) {
    throw new RuntimeException('Validation evidence row count changed');
}
$first = $rows[0];
if ((int) $first['message_log_id'] !== 41
    || (int) $first['vehicle_id'] !== 7
    || $first['vehicle_reg_no'] !== 'SYNTH-7'
    || $first['imei'] !== '123456789012345'
    || $first['field'] !== 'lat'
    || $first['invalid_value'] !== '95.5'
    || $first['reason'] !== 'Must be within [-90, 90]'
    || $first['error_code'] !== 'INVALID_COORDINATES'
    || (int) $first['activity_id'] !== 3
    || $first['validation_status'] !== 'failed'
    || (int) $first['gps_timestamp'] !== 1_800_000_123) {
    throw new RuntimeException('Validation evidence identity or gps fields changed');
}
if ($rows[1]['invalid_value'] !== '{"nested":true}') {
    throw new RuntimeException('Non-scalar invalid value was not JSON encoded');
}
if ($rows[2]['invalid_value'] !== null || $rows[2]['error_code'] !== null) {
    throw new RuntimeException('Absent invalid value or error code was fabricated');
}

// An unpersisted message has no source id yet; keep the legacy nulls rather than inventing one.
$database->exec('DELETE FROM validation_errors');
$repository->recordErrors(
    ['field' => 'synthetic', 'wialon_msg_time' => 1_800_000_124],
    [['field' => 'lat', 'value' => null, 'reason' => 'Coordinate is required', 'code' => 'INVALID_COORDINATES']],
);
$unstored = $database->query('SELECT * FROM validation_errors')->fetch();
if ($unstored['message_log_id'] !== null || $unstored['vehicle_id'] !== null
    || $unstored['vehicle_reg_no'] !== null || $unstored['imei'] !== null
    || $unstored['activity_id'] !== null
    || (int) $unstored['gps_timestamp'] !== 1_800_000_124) {
    throw new RuntimeException('Unpersisted message evidence changed shape');
}

// The adapter must write validation evidence on the active message transaction connection.
$databaseInstance = new ReflectionProperty(Database::class, 'instance');
$sensitiveConfigScrubbed = new ReflectionProperty(Database::class, 'sensitiveConfigScrubbed');
$previousDatabase = $databaseInstance->getValue();
$previousScrubState = $sensitiveConfigScrubbed->getValue();
try {
    $databaseInstance->setValue(null, $database);
    $sensitiveConfigScrubbed->setValue(null, true);

    $legacy = new LegacyIngestionRepository();
    $database->exec('DELETE FROM validation_errors');
    $legacy->beginMessage();
    $legacy->recordValidationErrors($message, $errors);
    $legacy->rollbackMessage();
    if ($database->inTransaction()) {
        throw new RuntimeException('Legacy validation evidence left its message transaction open');
    }
    if ((int) $database->query('SELECT COUNT(*) FROM validation_errors')->fetchColumn() !== 0) {
        throw new RuntimeException('Validation evidence escaped the rolled-back message transaction');
    }

    $legacy->beginMessage();
    $legacy->recordValidationErrors($message, $errors);
    $legacy->commitMessage();
    if ((int) $database->query('SELECT COUNT(*) FROM validation_errors')->fetchColumn() !== 3) {
        throw new RuntimeException('Committed validation evidence is missing');
    }

    try {
        $legacy->recordValidationErrors($message, []);
    } catch (Throwable $unexpected) {
        throw new RuntimeException('Empty validation evidence outside a transaction must stay a no-op');
    }

    try {
        $legacy->recordValidationErrors($message, $errors);
    } catch (LogicException $expected) {
        $rejected = true;
    }
    if (($rejected ?? false) !== true) {
        throw new RuntimeException('Validation evidence was written without a message transaction');
    }

    // The public compatibility entry point keeps its count contract over the same repository.
    $database->exec('DELETE FROM validation_errors');
    if (GpsValidator::recordErrors($message, $errors) !== 3) {
        throw new RuntimeException('GpsValidator::recordErrors did not report its written count');
    }
    if ((int) $database->query('SELECT COUNT(*) FROM validation_errors')->fetchColumn() !== 3) {
        throw new RuntimeException('GpsValidator::recordErrors did not persist the evidence rows');
    }
    if (GpsValidator::recordErrors($message, []) !== 0) {
        throw new RuntimeException('GpsValidator::recordErrors wrote evidence for an empty error list');
    }
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    $databaseInstance->setValue(null, $previousDatabase);
    $sensitiveConfigScrubbed->setValue(null, $previousScrubState);
}

echo "PASS validation evidence insert shape, null identity, transaction atomicity and compatibility count\n";
