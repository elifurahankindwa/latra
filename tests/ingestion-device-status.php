<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/DeviceMonitor.php';

use Latra\Application\DeviceStatus\DeviceStatusEffects;
use Latra\Application\DeviceStatus\DeviceStatusClock;
use Latra\Application\DeviceStatus\DeviceStatusRepository;
use Latra\Application\DeviceStatus\UpdateDeviceStatus;
use Latra\Domain\Ingestion\DeviceStatusPolicy;

final class DeviceStatusTrace
{
    public array $calls = [];
}

final class DeviceStatusRepositoryStub implements DeviceStatusRepository
{
    public ?array $previous = null;
    public array $saved = [];

    public function __construct(private DeviceStatusTrace $trace)
    {
    }

    public function findByVehicleId(int $vehicleId): ?array
    {
        $this->trace->calls[] = ['find', $vehicleId];
        return $this->previous;
    }

    public function insert(int $vehicleId, array $status): void
    {
        $this->trace->calls[] = ['insert', $vehicleId];
        $this->saved[] = ['insert', $vehicleId, $status];
    }

    public function update(int $vehicleId, array $status): void
    {
        $this->trace->calls[] = ['update', $vehicleId];
        $this->saved[] = ['update', $vehicleId, $status];
    }
}

final class DeviceStatusEffectsStub implements DeviceStatusEffects
{
    public array $calls = [];

    public function __construct(private DeviceStatusTrace $trace)
    {
    }

    public function recordTransition(int $vehicleId, string $type, string $detail): void
    {
        $this->trace->calls[] = ['transition', $type];
        $this->calls[] = ['transition', $vehicleId, $type, $detail];
    }

    public function statusChanged(int $vehicleId, array $status): void
    {
        $this->trace->calls[] = ['changed', $vehicleId];
        $this->calls[] = ['changed', $vehicleId, $status];
    }
}

final class DeviceStatusClockStub implements DeviceStatusClock
{
    public function now(): string
    {
        return 'now';
    }

    public function at(int $timestamp): string
    {
        return 'at-' . $timestamp;
    }
}

$evaluation = DeviceStatusPolicy::evaluate([
    'pwr_ext' => 0.9,
    'pwr_int' => 3.1,
    'battery' => 0.1,
    'io_8' => 1,
], [
    'is_online' => 0,
    'internal_battery_low' => 0,
    'external_power_disconnected' => 0,
    'tampered' => 0,
]);
if ($evaluation !== [
    'internal_battery_low' => 1,
    'internal_battery_mv' => 3100.0,
    'internal_battery_pct' => 10.0,
    'external_power_disconnected' => 1,
    'external_power_mv' => 900.0,
    'tampered' => 1,
    'events' => [
        ['type' => 'BATTERY_LOW', 'detail' => 'Internal battery low (3100 mV)'],
        ['type' => 'POWER_DISCONNECTED', 'detail' => 'External power disconnected'],
        ['type' => 'TAMPERING', 'detail' => 'Device tampering detected'],
    ],
    'changed' => true,
]) {
    throw new RuntimeException('Device low-power/tamper transitions changed: ' . json_encode($evaluation));
}

$unchanged = DeviceStatusPolicy::evaluate([
    'ext_power' => 1.0,
    'battery_charge' => 3.2,
    'battery_pct' => 20,
    'tampering' => 0,
], [
    'is_online' => 1,
    'internal_battery_low' => 0,
    'external_power_disconnected' => 0,
    'tampered' => 0,
]);
if ($unchanged !== [
    'internal_battery_low' => 0,
    'internal_battery_mv' => 3200.0,
    'internal_battery_pct' => 20.0,
    'external_power_disconnected' => 0,
    'external_power_mv' => 1000.0,
    'tampered' => 0,
    'events' => [],
    'changed' => false,
]) {
    throw new RuntimeException('Device thresholds must remain strict and unchanged');
}

$missing = DeviceStatusPolicy::evaluate([], []);
if ($missing['internal_battery_mv'] !== null || $missing['internal_battery_pct'] !== null
    || $missing['external_power_mv'] !== null || $missing['events'] !== []
    || $missing['changed'] !== false) {
    throw new RuntimeException('Missing device signals or initial-status logging behavior changed');
}

$trace = new DeviceStatusTrace();
$repository = new DeviceStatusRepositoryStub($trace);
$effects = new DeviceStatusEffectsStub($trace);
$useCase = new UpdateDeviceStatus($repository, $effects, new DeviceStatusClockStub());
$useCase->execute(42, ['pwr_int' => 3.1], 1_800_000_000);
if (count($repository->saved) !== 1 || $repository->saved[0][0] !== 'insert'
    || $repository->saved[0][1] !== 42
    || $repository->saved[0][2]['last_comm_time'] !== 1_800_000_000
    || $repository->saved[0][2]['online_at'] !== 'now'
    || $repository->saved[0][2]['last_comm_at'] !== 'at-1800000000'
    || $repository->saved[0][2]['status_changed_at'] !== 'now'
    || $effects->calls !== [] || $trace->calls !== [['find', 42], ['insert', 42]]) {
    throw new RuntimeException('Initial device status must insert without transition or status-change effects');
}

$repository->previous = [
    'online_at' => 'previous-online-time',
    'is_online' => 0,
    'internal_battery_low' => 0,
    'external_power_disconnected' => 0,
    'tampered' => 0,
];
$effects->calls = [];
$trace->calls = [];
$useCase->execute(42, ['pwr_int' => 3.1, 'io_8' => 1], 1_800_000_001);
$savedUpdate = $repository->saved[1] ?? null;
if ($savedUpdate === null || $savedUpdate[0] !== 'update'
    || $savedUpdate[2]['online_at'] !== 'previous-online-time'
    || $savedUpdate[2]['is_online'] !== 1
    || $effects->calls !== [
        ['transition', 42, 'BATTERY_LOW', 'Internal battery low (3100 mV)'],
        ['transition', 42, 'TAMPERING', 'Device tampering detected'],
        ['changed', 42, $savedUpdate[2]],
    ]
    || $trace->calls !== [
        ['find', 42],
        ['transition', 'BATTERY_LOW'],
        ['transition', 'TAMPERING'],
        ['update', 42],
        ['changed', 42],
    ]) {
    throw new RuntimeException('Existing status update, transition and change effects must retain their order');
}

$databaseInstance = new ReflectionProperty(Database::class, 'instance');
$sensitiveConfigScrubbed = new ReflectionProperty(Database::class, 'sensitiveConfigScrubbed');
$previousDatabase = $databaseInstance->getValue();
$previousScrubState = $sensitiveConfigScrubbed->getValue();
$sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $databaseInstance->setValue(null, $sqlite);
    $sensitiveConfigScrubbed->setValue(null, true);
    $sqlite->exec(
        'CREATE TABLE device_status (
            vehicle_id INTEGER PRIMARY KEY,
            is_online INTEGER NOT NULL,
            online_at TEXT,
            last_comm_time INTEGER,
            last_comm_at TEXT,
            last_gps_at INTEGER,
            internal_battery_low INTEGER,
            internal_battery_mv REAL,
            internal_battery_pct REAL,
            external_power_disconnected INTEGER,
            external_power_mv REAL,
            tampered INTEGER,
            status_changed_at TEXT
        )',
    );
    $legacyRepository = new \Latra\Infrastructure\Persistence\LegacyDeviceStatusRepository();
    $legacyRepository->insert(7, [
        'is_online' => 1,
        'online_at' => 'now',
        'last_comm_time' => 1_800_000_000,
        'last_comm_at' => 'at-1800000000',
        'last_gps_at' => 1_800_000_000,
        'internal_battery_low' => 0,
        'internal_battery_mv' => null,
        'internal_battery_pct' => 80.0,
        'external_power_disconnected' => 0,
        'external_power_mv' => 12_500.0,
        'tampered' => 0,
        'status_changed_at' => 'now',
    ]);
    $stored = $legacyRepository->findByVehicleId(7);
    if (($stored['internal_battery_pct'] ?? null) != 80.0 || $stored['vehicle_id'] != 7) {
        throw new RuntimeException('Legacy device-status repository insert/read mapping changed');
    }
    $legacyRepository->update(7, [
        'is_online' => 0,
        'online_at' => 'now',
        'last_comm_time' => 1_800_000_001,
        'last_comm_at' => 'at-1800000001',
        'last_gps_at' => 1_800_000_001,
        'internal_battery_low' => 1,
        'internal_battery_mv' => 3100.0,
        'internal_battery_pct' => 10.0,
        'external_power_disconnected' => 1,
        'external_power_mv' => 900.0,
        'tampered' => 1,
        'status_changed_at' => 'later',
    ]);
    $stored = $legacyRepository->findByVehicleId(7);
    if ($stored['is_online'] != 0 || $stored['internal_battery_mv'] != 3100.0
        || $stored['external_power_disconnected'] != 1 || $stored['tampered'] != 1) {
        throw new RuntimeException('Legacy device-status repository update mapping changed');
    }
} finally {
    $databaseInstance->setValue(null, $previousDatabase);
    $sensitiveConfigScrubbed->setValue(null, $previousScrubState);
}

if (DeviceMonitor::BATTERY_LOW_MV !== DeviceStatusPolicy::BATTERY_LOW_MV
    || DeviceMonitor::BATTERY_LOW_PCT !== DeviceStatusPolicy::BATTERY_LOW_PCT
    || DeviceMonitor::EXT_POWER_LOW_MV !== DeviceStatusPolicy::EXT_POWER_LOW_MV) {
    throw new RuntimeException('Existing public device status thresholds changed');
}

$cleared = DeviceStatusPolicy::evaluate([
    'pwr_ext' => 0,
    'pwr_int' => 0,
    'battery' => 100,
], [
    'is_online' => 1,
    'internal_battery_low' => 1,
    'external_power_disconnected' => 1,
    'tampered' => 1,
]);
if ($cleared['internal_battery_low'] !== 0 || $cleared['external_power_disconnected'] !== 0
    || $cleared['tampered'] !== 0 || $cleared['events'] !== [] || !$cleared['changed']) {
    throw new RuntimeException('Signal recovery must clear status without creating activation events');
}

echo "PASS device signal normalization, thresholds, transition events and change detection\n";
