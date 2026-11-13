<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Application\Ingestion\IngestVehicleMessages;
use Latra\Application\Ingestion\IngestionEffects;
use Latra\Application\Ingestion\IngestionRepository;
use Latra\Infrastructure\Persistence\LegacyIngestionRepository;

final class IngestionUseCaseRepository implements IngestionRepository
{
    public array $messages = [];
    public array $validationErrors = [];
    public array $payloads = [];
    public array $outbox = [];
    public array $state = [];
    public array $calls = [];
    public int $overspeedSamples = 2;
    public bool $failPayloadUpdate = false;
    public bool $isActivityAllowed = true;
    public array $bucketVehicleIds = [7];
    private ?array $transactionSnapshot = null;
    private int $nextId = 1;

    public function loadVehicleState(int $vehicleId): array
    {
        return $this->state;
    }

    public function enabledActivities(int $vehicleId): array
    {
        return [];
    }

    public function speedLimit(array $vehicle): float
    {
        return (float) ($vehicle['speed_limit_kmh'] ?? 80);
    }

    public function resolveParameters(array $parameters, ?string $deviceType): array
    {
        return $parameters;
    }

    public function overspeedConsecutiveSamples(): int
    {
        return $this->overspeedSamples;
    }

    public function beginMessage(): void
    {
        $this->calls[] = 'begin';
        $this->transactionSnapshot = [
            $this->messages,
            $this->validationErrors,
            $this->payloads,
            $this->outbox,
        ];
    }

    public function storeMessage(array $message): bool
    {
        $this->calls[] = 'store';
        foreach ($this->messages as $stored) {
            if ($stored['vehicle_id'] === $message['vehicle_id']
                && $stored['wialon_msg_time'] === $message['wialon_msg_time']) {
                return false;
            }
        }
        $this->messages[] = ['id' => $this->nextId++] + $message;
        return true;
    }

    public function findMessage(int $vehicleId, int $messageTime): ?array
    {
        foreach ($this->messages as $stored) {
            if ($stored['vehicle_id'] === $vehicleId && $stored['wialon_msg_time'] === $messageTime) {
                return $stored;
            }
        }
        return null;
    }

    public function recordValidationErrors(array $message, array $errors): void
    {
        $this->validationErrors[] = [$message, $errors];
    }

    public function updateMessagePayload(array $message, array $payload): void
    {
        if ($this->failPayloadUpdate) {
            throw new RuntimeException('synthetic payload persistence failure');
        }
        $this->payloads[$message['id']] = $payload;
    }

    public function activityAllowed(int $activityId): bool
    {
        return $this->isActivityAllowed;
    }

    public function enabledBucketVehicleIds(): array
    {
        return $this->bucketVehicleIds;
    }

    public function enqueue(array $message, array $payload, ?string $eventId, int $priority): void
    {
        $this->outbox[] = [$message, $payload, $eventId, $priority];
    }

    public function commitMessage(): void
    {
        $this->calls[] = 'commit';
        $this->transactionSnapshot = null;
    }

    public function rollbackMessage(): void
    {
        $this->calls[] = 'rollback';
        if ($this->transactionSnapshot !== null) {
            [
                $this->messages,
                $this->validationErrors,
                $this->payloads,
                $this->outbox,
            ] = $this->transactionSnapshot;
            $this->transactionSnapshot = null;
        }
    }

    public function persistVehicleState(int $vehicleId, array $state): void
    {
        $this->state = $state;
    }

    public function formattedDateTime(): string
    {
        return date('Y-m-d H:i:s');
    }
}

final class IngestionUseCaseEffects implements IngestionEffects
{
    public array $calls = [];
    public array $emergencies = [];
    public array $deviceUpdates = [];
    public array $closedMessages = [];
    public array $tripStatsCalls = [];

    public function mapEmergencyType(int $activityId): ?string
    {
        return $activityId === 4 ? 'overspeed' : null;
    }

    public function ensureActiveEvent(
        int $vehicleId,
        string $eventType,
        ?string $reason,
        ?float $speed,
        int $activityId,
    ): ?array {
        $this->emergencies[] = [$vehicleId, $eventType, $reason, $speed, $activityId];
        return ['id' => 21, 'event_id' => 'synthetic-event'];
    }

    public function bufferEmergencyRecord(array $event, int $vehicleId, array $message): bool
    {
        $this->calls[] = 'buffer';
        return true;
    }

    public function updateDeviceStatus(int $vehicleId, array $parameters, int $commTime): void
    {
        $this->deviceUpdates[] = [$vehicleId, $parameters, $commTime];
    }

    public function buildTripStats(int $vehicleId, int $messageTime): array
    {
        $this->tripStatsCalls[] = [$vehicleId, $messageTime];
        return [];
    }

    public function buildAddonInfo(int $activityId, array $parameters, array $customData): ?array
    {
        return \Latra\Domain\Ingestion\LatraPayloadPolicy::buildAddonInfo($activityId, $parameters, $customData);
    }

    public function closeEndedEvent(int $vehicleId, array $messages): void
    {
        $this->closedMessages[] = [$vehicleId, $messages];
    }
}

function ingestionReport(int $time, float $speed, float $latitude = -6.8): array
{
    return [
        't' => $time,
        'rt' => $time + 1,
        'pos' => ['x' => 39.2, 'y' => $latitude, 'z' => 50, 's' => $speed, 'c' => 90, 'sc' => 12],
        'p' => ['hdop' => 1, 'pwr_ext' => 12.5, 'pwr_int' => 4.1],
    ];
}

$vehicle = [
    'id' => 7,
    'imei' => '123456789012345',
    'vehicle_reg_no' => 'SYNTHETIC-INGESTION',
    'speed_limit_kmh' => 80,
];
$time = time() - 10;
$repository = new IngestionUseCaseRepository();
$effects = new IngestionUseCaseEffects();
$useCase = new IngestVehicleMessages($repository, $effects);
$messages = [ingestionReport($time, 90), ingestionReport($time + 1, 95)];
$result = $useCase->ingest($vehicle, $messages);
if ($result !== ['inserted' => 2, 'rejected' => 0, 'skipped' => 0]
    || array_column($repository->messages, 'activity_id') !== [1, 4]
    || count($repository->outbox) !== 2
    || $repository->outbox[0][2] !== null || $repository->outbox[0][3] !== 0
    || $repository->outbox[1][2] !== 'synthetic-event' || $repository->outbox[1][3] !== 10
    || count($effects->emergencies) !== 1
    || count($effects->deviceUpdates) !== 2
    || $repository->state['overspending_active'] !== 2
    || count($effects->closedMessages) !== 1
    || $repository->calls !== ['begin', 'store', 'commit', 'begin', 'store', 'commit']) {
    throw new RuntimeException('Batch rolling state or per-message ingestion orchestration changed');
}

$replay = $useCase->ingest($vehicle, [$messages[0]]);
if ($replay !== ['inserted' => 0, 'rejected' => 0, 'skipped' => 1]
    || count($repository->messages) !== 2
    || $repository->calls[6] !== 'begin' || $repository->calls[7] !== 'store' || $repository->calls[8] !== 'commit') {
    throw new RuntimeException('Duplicate ingestion no longer commits and counts as skipped');
}

$engineVehicle = $vehicle + ['engine_param' => 'ignition'];
$engineRepository = new IngestionUseCaseRepository();
$engineRepository->state = ['engine_status' => 'off'];
$engineEffects = new IngestionUseCaseEffects();
$engineOn = ingestionReport($time + 2, 20);
$engineOn['p']['ignition'] = 1;
$engineOff = ingestionReport($time + 3, 20);
$engineOff['p']['ignition'] = 0;
$engineResult = (new IngestVehicleMessages($engineRepository, $engineEffects))
    ->ingest($engineVehicle, [$engineOn, $engineOff]);
if ($engineResult !== ['inserted' => 2, 'rejected' => 0, 'skipped' => 0]
    || array_column($engineRepository->messages, 'activity_id') !== [2, 3]
    || $engineEffects->tripStatsCalls !== [[7, $time + 3]]
    || $engineRepository->state['engine_status'] !== 'off') {
    throw new RuntimeException('Rolling engine state or engine-off trip-stat lookup changed');
}

// Vehicle state must come from the newest source time, not the first array element.
$reversedRepository = new IngestionUseCaseRepository();
$reversedRepository->state = ['engine_status' => 'on'];
$reversedEffects = new IngestionUseCaseEffects();
$reversedEngineOn = ingestionReport($time + 2, 20);
$reversedEngineOn['p']['ignition'] = 1;
$reversedEngineOff = ingestionReport($time + 3, 20);
$reversedEngineOff['p']['ignition'] = 0;
(new IngestVehicleMessages($reversedRepository, $reversedEffects))
    ->ingest($engineVehicle, [$reversedEngineOff, $reversedEngineOn]);
if ($reversedRepository->state['engine_status'] !== 'off') {
    throw new RuntimeException('Newest-message vehicle state depended on array order');
}

$ineligibleRepository = new IngestionUseCaseRepository();
$ineligibleRepository->isActivityAllowed = false;
$ineligibleResult = (new IngestVehicleMessages($ineligibleRepository, new IngestionUseCaseEffects()))
    ->ingest($vehicle, [ingestionReport($time + 4, 20)]);
if ($ineligibleResult !== ['inserted' => 1, 'rejected' => 0, 'skipped' => 0]
    || $ineligibleRepository->outbox !== []) {
    throw new RuntimeException('Ineligible activity was not stored without creating delivery intent');
}

$invalidRepository = new IngestionUseCaseRepository();
$invalidEffects = new IngestionUseCaseEffects();
$invalidResult = (new IngestVehicleMessages($invalidRepository, $invalidEffects))
    ->ingest($vehicle, [ingestionReport($time + 5, 20, 95)]);
if ($invalidResult !== ['inserted' => 0, 'rejected' => 1, 'skipped' => 0]
    || count($invalidRepository->validationErrors) !== 1
    || $invalidRepository->outbox !== []
    || $invalidRepository->calls !== ['begin', 'store', 'commit']) {
    throw new RuntimeException('Invalid message persistence and rejection accounting changed');
}

$rollbackRepository = new IngestionUseCaseRepository();
$rollbackRepository->failPayloadUpdate = true;
$rollbackUseCase = new IngestVehicleMessages($rollbackRepository, new IngestionUseCaseEffects());
$failed = false;
try {
    $rollbackUseCase->ingest($vehicle, [ingestionReport($time + 10, 20)]);
} catch (RuntimeException $error) {
    $failed = $error->getMessage() === 'synthetic payload persistence failure';
}
if (!$failed || $rollbackRepository->calls !== ['begin', 'store', 'rollback']
    || $rollbackRepository->messages !== [] || $rollbackRepository->outbox !== []) {
    throw new RuntimeException('Message persistence failure did not roll back and propagate');
}

$databaseInstance = new ReflectionProperty(Database::class, 'instance');
$sensitiveConfigScrubbed = new ReflectionProperty(Database::class, 'sensitiveConfigScrubbed');
$previousDatabase = $databaseInstance->getValue();
$previousScrubState = $sensitiveConfigScrubbed->getValue();
$firstConnection = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$secondConnection = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $databaseInstance->setValue(null, $firstConnection);
    $sensitiveConfigScrubbed->setValue(null, true);
    $legacyRepository = new LegacyIngestionRepository();
    $legacyRepository->beginMessage();
    $legacyRepository->commitMessage();

    $databaseInstance->setValue(null, $secondConnection);
    $legacyRepository->beginMessage();
    if ($firstConnection->inTransaction() || !$secondConnection->inTransaction()) {
        throw new RuntimeException('A later message transaction reused a stale database connection');
    }
    $legacyRepository->rollbackMessage();
    if ($secondConnection->inTransaction()) {
        throw new RuntimeException('Legacy repository did not release its message transaction');
    }
} finally {
    $databaseInstance->setValue(null, $previousDatabase);
    $sensitiveConfigScrubbed->setValue(null, $previousScrubState);
}

echo "PASS ingestion application batch, replay, rejection, side effects, and transaction boundaries\n";
