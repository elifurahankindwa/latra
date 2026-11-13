<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../includes/autoload.php';

final class RuntimeUseCaseRepository implements \Latra\Application\Collection\RelayRuntimeRepository
{
    public function __construct(
        private array $bucketVehicles = [],
        private array $eventVehicles = [],
        private array $eventActivities = [],
    ) {
    }

    public function activeVehiclesForBucket(int $bucketId): array
    {
        return $this->bucketVehicles;
    }

    public function activeVehiclesForEventCollection(): array
    {
        return $this->eventVehicles;
    }

    public function enabledEventActivities(): array
    {
        return $this->eventActivities;
    }
}

$warnings = [];
$collected = [];
$bucket = new \Latra\Application\Collection\CollectRelayBucket(
    new RuntimeUseCaseRepository([['id' => 1], ['id' => 2], ['id' => 3]]),
    static function (array $vehicle, int $window, int $limit, string $stream, array $allowed, ?object $source) use (&$collected): array {
        $collected[] = [$vehicle['id'], $window, $limit, $stream, $allowed, $source];
        if ($vehicle['id'] === 2) {
            throw new RuntimeException('synthetic collection failure');
        }
        return ['fetched' => 2, 'inserted' => 1, 'queued' => 1, 'rejected' => 1, 'skipped' => 0];
    },
    static fn(array $vehicle): object => (object) ['vehicle' => $vehicle['id']],
    static function (string $channel, array $context) use (&$warnings): void {
        $warnings[] = [$channel, $context];
    },
);
$bucketResult = $bucket->run([
    'id' => 8,
    'activity_id' => 5,
    'max_run_seconds' => 300,
    'max_items_per_run' => 10,
]);
if ($bucketResult !== [
    'fetched' => 4,
    'inserted' => 2,
    'queued' => 2,
    'rejected' => 2,
    'pushed' => 0,
    'failed' => 1,
    'skipped' => 0,
    'denied' => 0,
] || count($collected) !== 3 || $warnings[0][0] !== 'relay.bucket'
    || $collected[0][1] !== 180 || $collected[0][2] !== 100 || $collected[0][3] !== ':activity:5'
    || $collected[0][4] !== [5] || $collected[0][5]->vehicle !== 1) {
    throw new RuntimeException('Bucket collection use case changed scheduling or result behavior');
}

$eventCalls = [];
$eventCycle = new \Latra\Application\Collection\CollectRelayEventCycle(
    new RuntimeUseCaseRepository([], [['id' => 11], ['id' => 12]], [3, 5]),
    static function (array $vehicle, int $window, int $limit, string $stream, array $allowed) use (&$eventCalls): array {
        $eventCalls[] = [$vehicle['id'], $window, $limit, $stream, $allowed];
        if ($vehicle['id'] === 12) {
            throw new RuntimeException('synthetic event failure');
        }
        return ['fetched' => 4, 'inserted' => 2];
    },
    static function (string $channel, array $context) use (&$warnings): void {
        $warnings[] = [$channel, $context];
    },
);
$eventResult = $eventCycle->run(10, 0);
if ($eventResult !== ['vehicles' => 1, 'fetched' => 4, 'detected' => 2, 'sent' => 0, 'failed' => 1]
    || $eventCalls !== [[11, 60, 1, ':events', [3, 5]], [12, 60, 1, ':events', [3, 5]]]
    || end($warnings)[0] !== 'relay.events') {
    throw new RuntimeException('Event cycle use case changed window, limit or accounting behavior');
}

$cappedCalls = 0;
$capped = new \Latra\Application\Collection\CollectRelayBucket(
    new RuntimeUseCaseRepository([['id' => 1], ['id' => 2]]),
    static function () use (&$cappedCalls): array {
        $cappedCalls++;
        return ['fetched' => 1, 'inserted' => 1, 'queued' => 1, 'rejected' => 0, 'skipped' => 0];
    },
    static fn(): null => null,
    static function (): void {
    },
);
$cappedResult = $capped->run(['id' => 1, 'activity_id' => 1, 'max_items_per_run' => 1]);
if ($cappedCalls !== 1 || $cappedResult['queued'] !== 1) {
    throw new RuntimeException('Bucket item cap no longer stops later vehicle collection');
}

$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database->exec('CREATE TABLE vehicles (id INTEGER PRIMARY KEY, is_active INTEGER)');
$database->exec('CREATE TABLE bucket_vehicles (bucket_id INTEGER, vehicle_id INTEGER)');
$database->exec('CREATE TABLE relay_vehicle_settings (vehicle_id INTEGER, is_enabled INTEGER)');
$database->exec('CREATE TABLE automation_buckets (id INTEGER, is_enabled INTEGER)');
$database->exec('CREATE TABLE wialon_event_map (activity_id INTEGER, is_enabled INTEGER)');
$database->exec('INSERT INTO vehicles VALUES (1,1),(2,0),(3,1)');
$database->exec('INSERT INTO bucket_vehicles VALUES (9,1),(9,2),(8,3)');
$database->exec('INSERT INTO relay_vehicle_settings VALUES (1,1),(3,1)');
$database->exec('INSERT INTO automation_buckets VALUES (4,1),(5,0)');
$database->exec('INSERT INTO bucket_vehicles VALUES (4,1),(5,3)');
$database->exec('INSERT INTO wialon_event_map VALUES (1,1),(3,1),(3,1),(5,0)');
$runtimeRepository = new \Latra\Infrastructure\Persistence\PdoRelayRuntimeRepository($database);
if (array_column($runtimeRepository->activeVehiclesForBucket(9), 'id') !== [1]
    || array_column($runtimeRepository->activeVehiclesForEventCollection(), 'id') !== [1]
    || $runtimeRepository->enabledEventActivities() !== [3]) {
    throw new RuntimeException('PDO runtime repository changed active/enabled query boundaries');
}

echo "PASS relay bucket and event-cycle application workflows preserve scheduling and reporting contracts\n";
