<?php
if (PHP_SAPI !== 'cli') exit(1);
define('DELIVERY_ARCHITECTURE', 'relay');
const DATE_FORMAT = 'Y-m-d H:i:s';

class Database {
    public static PDO $pdo;

    public static function getInstance(): PDO {
        return self::$pdo;
    }
}

class RelayRuntime {
    public static array $calls = [];

    public static function queueForVehicle(array $payload, ?int $vehicleId = null): array {
        self::$calls[] = [$payload, $vehicleId];
        return ['success' => true, 'queued' => 1, 'message' => 'Saved to the durable relay.'];
    }
}

class LatraMapper {
    public static function buildPayload(array $vehicle, array $source, int $activityId): array {
        return [
            'vehicle_reg_no' => $vehicle['vehicle_reg_no'],
            'imei' => $vehicle['imei'],
            'items' => [['activity_id' => (string) $activityId]],
        ];
    }
}

require_once __DIR__ . '/../includes/autoload.php';
require __DIR__ . '/../includes/EmergencyHandler.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE emergency_queue (
    id INTEGER PRIMARY KEY,
    event_id TEXT,
    vehicle_id INTEGER,
    wialon_msg_time INTEGER,
    lat REAL,
    lng REAL,
    altitude INTEGER,
    speed REAL,
    bearing REAL,
    satellite_count INTEGER,
    hdop REAL,
    latra_status TEXT,
    latra_response TEXT,
    submitted_at TEXT
)');
$pdo->exec('CREATE TABLE vehicles (id INTEGER PRIMARY KEY, vehicle_reg_no TEXT, imei TEXT)');
$pdo->exec("INSERT INTO emergency_queue
    (id,event_id,vehicle_id,wialon_msg_time,lat,lng,altitude,speed,bearing,satellite_count,hdop,latra_status)
    VALUES (17,'EMG-1-test',1,1800000000,-6.8,39.2,10,20,90,8,1,'pending')");
$pdo->exec("INSERT INTO emergency_queue (id,event_id,vehicle_id,wialon_msg_time,latra_status)
    VALUES (18,'EMG-legacy-test',1,1800000001,'pending')");
$pdo->exec("INSERT INTO vehicles VALUES (1,'SYNTHETIC','123456789012345')");
Database::$pdo = $pdo;

$result = EmergencyHandler::flushPending([
    'event_id' => 'EMG-1-test',
    'activity_id' => 8,
], 1);
$row = $pdo->query('SELECT latra_status, submitted_at FROM emergency_queue WHERE id=17')->fetch(PDO::FETCH_ASSOC);

if (($result['queued'] ?? null) !== 1 || ($result['submitted'] ?? null) !== 0
    || ($row['latra_status'] ?? null) !== 'queued' || $row['submitted_at'] !== null) {
    throw new RuntimeException('Relay handoff must be recorded as queued, not as confirmed delivery');
}

$delegated = EmergencyHandler::pushPayload([
    'vehicle_reg_no' => 'SYNTHETIC',
    'imei' => '123456789012345',
    'items' => [['activity_id' => '8', 'timestamp' => '1800000000000']],
], 1);
if (empty($delegated['ok']) || (RelayRuntime::$calls[1][1] ?? null) !== 1) {
    throw new RuntimeException('Emergency relay submission did not delegate ownership resolution');
}

$legacyUseCase = new \Latra\Application\Emergency\FlushPendingEmergencyReports(
    new \Latra\Infrastructure\Persistence\PdoEmergencyQueueRepository($pdo),
    static fn(): array => ['items' => []],
    static fn(): array => ['ok' => true, 'response' => 'Accepted'],
);
$legacyResult = $legacyUseCase->flush(['event_id' => 'EMG-legacy-test'], 1);
$legacyRow = $pdo->query('SELECT latra_status, submitted_at FROM emergency_queue WHERE id=18')
    ->fetch(PDO::FETCH_ASSOC);
if (($legacyResult['submitted'] ?? null) !== 1 || $legacyRow['latra_status'] !== 'sent'
    || $legacyRow['submitted_at'] === null) {
    throw new RuntimeException('Confirmed legacy submission must remain recorded as sent');
}

echo "PASS emergency use case distinguishes relay handoff from confirmed legacy submission\n";
