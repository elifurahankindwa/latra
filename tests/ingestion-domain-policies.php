<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../includes/autoload.php';

class IngestionPolicyDatabase
{
    private static ?PDO $database = null;

    public static function fetchAll(string $sql, array $parameters = []): array
    {
        return [
            ['is_enabled' => 1, 'device_type' => '*', 'param_key' => 'battery_level', 'canonical_key' => 'battery_pct'],
            ['is_enabled' => 1, 'device_type' => 'FMB920', 'param_key' => 'v_ext', 'canonical_key' => 'pwr_ext'],
            ['is_enabled' => 1, 'device_type' => 'OTHER', 'param_key' => 'wrong', 'canonical_key' => 'pwr_ext'],
        ];
    }

    public static function getInstance(): PDO
    {
        if (self::$database === null) {
            self::$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            self::$database->exec('CREATE TABLE param_aliases (
                is_enabled INTEGER, device_type TEXT, param_key TEXT, canonical_key TEXT
            )');
            $statement = self::$database->prepare('INSERT INTO param_aliases VALUES (?,?,?,?)');
            foreach (self::fetchAll('') as $alias) {
                $statement->execute([
                    $alias['is_enabled'],
                    $alias['device_type'],
                    $alias['param_key'],
                    $alias['canonical_key'],
                ]);
            }
        }
        return self::$database;
    }
}
class_alias(IngestionPolicyDatabase::class, 'Database');

$vehicle = [
    'id' => 42,
    'hw_type' => 'FMB920',
    'imei' => '123456789012345',
    'vehicle_reg_no' => 'SYNTHETIC',
];
$sourceTime = time() - 60;
$source = [
    't' => $sourceTime,
    'rt' => $sourceTime + 1,
    'pos' => ['x' => 39.123456789, 'y' => -6.123456789, 'z' => 0, 's' => 0, 'c' => 0, 'sc' => 0],
    'p' => [
        'battery_level' => 0.5,
        'v_ext' => 0,
        'odometer' => 0,
        'driver_id' => 'driver-7',
        'hdop' => 0,
        'rssi' => 0,
        'lac' => 0,
        'cell_id' => 0,
        'mcc' => 0,
    ],
];
$normalized = Normaliser::fromWialon($vehicle, $source);
if ($normalized['lat'] !== -6.12345679 || $normalized['lng'] !== 39.12345679
    || $normalized['speed'] !== 0.0 || $normalized['d2d3'] !== 2
    || $normalized['battery_pct'] !== 50.0 || $normalized['battery_mv'] !== null
    || $normalized['odometer'] !== 0.0 || $normalized['driver_id'] !== 'driver-7'
    || $normalized['canonical_params']['pwr_ext'] !== 0
    || Normaliser::toMv('12.5') !== 12500.0
    || Normaliser::toPct('150') !== 100.0) {
    throw new RuntimeException('Legacy normalizer adapter changed canonical data or zero handling');
}

$valid = GpsValidator::validate($normalized);
if (!$valid['valid'] || GpsValidator::statusOf($valid) !== 'valid') {
    throw new RuntimeException('Valid normalized message failed domain validation');
}
$invalid = GpsValidator::validate(array_replace($normalized, ['lat' => 91, 'bearing' => 360]));
if ($invalid['valid'] || GpsValidator::statusOf($invalid) !== 'failed'
    || array_column($invalid['errors'], 'field') !== ['lat', 'bearing']) {
    throw new RuntimeException('GPS validation ranges or error ordering changed');
}

$event = EventDetector::detect(['event' => 'battery_low']);
if ($event['activity_id'] !== EventDetector::BATTERY_LOW
    || EventDetector::engineState(['sensor' => 1], 'sensor') !== true
    || EventDetector::flagOn(['status' => 2048], ['status:11']) !== true
    || EventDetector::externalPowerState(['io_66' => 0]) !== false
    || EventDetector::EVENT_MAP !== \Latra\Domain\Ingestion\ActivityDetectionPolicy::EVENT_MAP) {
    throw new RuntimeException('Activity detection policy behavior or compatibility constants changed');
}
$transition = EventDetector::detect(
    ['io_239' => 1],
    ['engine_status' => 'off'],
    ['activity_enabled' => [EventDetector::ENGINE_ON => true], 'engine_param' => 'io_239'],
);
if ($transition['activity_id'] !== EventDetector::ENGINE_ON) {
    throw new RuntimeException('Engine transition decision changed');
}

$unit = \Latra\Domain\Ingestion\LatraPayloadPolicy::mapVehicle([
    'id' => 77,
    'nm' => 'unit-name',
    'uid' => '123456789012345',
    'hw' => 'FMB920',
    'lmsg' => ['t' => 1000, 'pos' => ['x' => 39, 'y' => -6, 'z' => 0], 'p' => ['hdop' => 1.5]],
    'pos' => ['t' => 900, 'x' => 1, 'y' => 1],
    'flds' => [['n' => 'plate', 'v' => ' tz-123 ']],
]);
if ($unit['vehicle_reg_no'] !== 'TZ-123' || $unit['lat'] !== -6.0 || $unit['lng'] !== 39.0
    || $unit['last_message_time'] !== 1000 || $unit['d2d3'] !== 2
    || LatraMapper::mapVehicle([
        'id' => 77,
        'nm' => 'not a plate name',
        'flds' => [],
    ])['vehicle_reg_no'] !== 'W77') {
    throw new RuntimeException('Vehicle payload normalization or registration fallback changed');
}

$payload = LatraMapper::buildPayload(
    $vehicle,
    ['wialon_msg_time' => $sourceTime, 'lat' => -6.1, 'lng' => 39.1, 'speed' => 0, 'mgs_id' => 0],
    16,
    ['fuel_sensor' => 'present'],
    ['fuelLevel' => '125'],
);
if ($payload['items'][0]['latitude'] !== '-6.1' || $payload['items'][0]['horizontal_speed'] !== '0'
    || $payload['items'][0]['MGS_ID'] !== '0' || $payload['items'][0]['addon_info'] !== ['fuel_sensor' => 'present']
    || $payload['items'][0]['fuel_info'] !== ['fuelLevel' => '125']
    || LatraMapper::validatePayload($payload) !== []
    || LatraMapper::buildFuelInfo(['fuel_level' => 12.5])['fuelLevel'] !== '125') {
    throw new RuntimeException('LATRA payload policy changed formatting, activity blocks, or fuel scaling');
}
$missingFuel = $payload;
unset($missingFuel['items'][0]['fuel_info']);
if (!in_array('items[0].fuel_info is required for activity 16', LatraMapper::validatePayload($missingFuel), true)
    || LatraMapper::activityRequiresAddon(16)
    || LatraMapper::getActivityName(24) !== 'iButton Scan (Regular)') {
    throw new RuntimeException('LATRA payload validation or activity metadata changed');
}
$orderedErrors = LatraMapper::validatePayload([
    'items' => [[
        'latitude' => '',
        'longitude' => null,
        'timestamp' => '',
        'activity_id' => 16,
        'altitude' => 'unknown',
    ]],
]);
if ($orderedErrors !== [
    'vehicle_reg_no is required',
    'imei is required',
    'items[0].latitude is required',
    'items[0].longitude is required',
    'items[0].timestamp is required',
    'items[0].altitude must be numeric',
    'items[0].fuel_info is required for activity 16',
]) {
    throw new RuntimeException('LATRA payload validation error order changed');
}

echo "PASS domain normalization, activity detection, GPS and LATRA payload policies, and global adapters\n";
