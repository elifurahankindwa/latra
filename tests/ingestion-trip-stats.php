<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}

final class TripStatisticsTestDatabase
{
    public static ?PDO $connection = null;

    public static function getInstance(): PDO
    {
        return self::$connection ?? throw new RuntimeException('Trip-stat test database is not configured');
    }
}
class_alias(TripStatisticsTestDatabase::class, 'Database');

require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/LatraMapper.php';

$database = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
TripStatisticsTestDatabase::$connection = $database;
$database->exec('CREATE TABLE message_log (
    id INTEGER PRIMARY KEY,
    vehicle_id INTEGER NOT NULL,
    activity_id INTEGER NOT NULL,
    wialon_msg_time INTEGER NOT NULL,
    lat REAL NULL,
    lng REAL NULL,
    speed REAL NULL
)');
$insert = $database->prepare('INSERT INTO message_log
    (id, vehicle_id, activity_id, wialon_msg_time, lat, lng, speed)
    VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [1, 7, 1, 900, 0, 0, 10],
    [2, 7, 2, 1000, 0, 0, 0],
    [3, 7, 1, 1030, 0, 1, 30],
    [4, 7, 1, 1040, null, 2, null],
    [5, 7, 1, 1060, 0, 2, 60],
    [6, 8, 2, 1020, -6, 39, 200],
    [7, 7, 2, 1061, -6, 39, 300],
] as $row) {
    $insert->execute($row);
}

$stats = LatraMapper::buildTripStats(7, 1060);
if ($stats !== [
    'distance' => 111.19,
    'duration' => 1.0,
    'avgSpeed' => 30.0,
    'maxSpeed' => 60.0,
]) {
    throw new RuntimeException('Engine-on trip window or trip-stat aggregation changed: ' . json_encode($stats));
}

$fallback = LatraMapper::buildTripStats(9, 200000);
if ($fallback !== ['distance' => 0.0, 'duration' => 1440.0, 'avgSpeed' => 0, 'maxSpeed' => 0.0]) {
    throw new RuntimeException('No-engine-on fallback window or empty trip-stat result changed');
}

$repository = new \Latra\Infrastructure\Persistence\PdoTripStatisticsRepository($database);
$useCase = new \Latra\Application\Ingestion\BuildTripStatistics($repository);
if ($useCase->build(7, 1060) !== $stats) {
    throw new RuntimeException('Trip-stat application use case differs from the compatibility adapter');
}

echo "PASS trip-stat window selection, aggregation, fallback and legacy mapper adapter\n";
