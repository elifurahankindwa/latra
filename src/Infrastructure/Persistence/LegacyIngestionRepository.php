<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Ingestion\IngestionRepository;
use LogicException;
use PDO;

final class LegacyIngestionRepository implements IngestionRepository
{
    private ?PDO $messageTransaction = null;

    public function loadVehicleState(int $vehicleId): array
    {
        return (new PdoVehicleStateRepository($this->activeConnection()))->find($vehicleId);
    }

    public function enabledActivities(int $vehicleId): array
    {
        return $this->settings()->enabledActivities($vehicleId);
    }

    public function speedLimit(array $vehicle): float
    {
        if (!empty($vehicle['speed_limit_kmh'])) {
            return (float) $vehicle['speed_limit_kmh'];
        }
        return $this->settings()->speedLimit((int) $vehicle['id']);
    }

    public function resolveParameters(array $parameters, ?string $deviceType): array
    {
        return \Latra\Domain\Ingestion\ParameterResolutionPolicy::resolve($parameters, $deviceType,
            (new PdoParameterAliasRepository($this->activeConnection()))->enabledAliases());
    }

    public function overspeedConsecutiveSamples(): int
    {
        return $this->settings()->configInt('overspeed_consecutive', 2);
    }

    public function beginMessage(): void
    {
        $database = \Database::getInstance();
        $database->beginTransaction();
        $this->messageTransaction = $database;
    }

    public function storeMessage(array $message): bool
    {
        if (defined('DELIVERY_ARCHITECTURE') && DELIVERY_ARCHITECTURE === 'relay') {
            throw new \RuntimeException('Retained-source writes are disabled in relay mode');
        }
        return $this->messageRepository()->storeMessage($message);
    }

    public function findMessage(int $vehicleId, int $messageTime): ?array
    {
        return $this->messageRepository()->findMessage($vehicleId, $messageTime);
    }

    public function recordValidationErrors(array $message, array $errors): void
    {
        if ($errors === []) {
            return;
        }

        (new PdoValidationEvidenceRepository($this->transactionConnection()))
            ->recordErrors($message, $errors);
    }

    public function updateMessagePayload(array $message, array $payload): void
    {
        $this->messageRepository()->updatePayload($message, $payload);
    }

    public function activityAllowed(int $activityId): bool
    {
        return $this->settings()->activityAllowed($activityId);
    }

    public function enabledBucketVehicleIds(): array
    {
        return $this->settings()->enabledBucketVehicleIds();
    }

    public function enqueue(array $message, array $payload, ?string $eventId, int $priority): void
    {
        (new \Latra\Application\Ingestion\QueueOutboxMessage(new PdoOutboxWriter($this->transactionConnection())))
            ->enqueue($message, $payload, $eventId, $priority, $this->formattedDateTime(),
                $this->settings()->configInt('outbox_max_tries', 8));
    }

    public function commitMessage(): void
    {
        $this->transactionConnection()->commit();
        $this->messageTransaction = null;
    }

    public function rollbackMessage(): void
    {
        $database = $this->messageTransaction;
        $this->messageTransaction = null;
        if ($database !== null && $database->inTransaction()) {
            $database->rollBack();
        }
    }

    public function persistVehicleState(int $vehicleId, array $state): void
    {
        (new PdoVehicleStateRepository($this->activeConnection()))->save($vehicleId, $state);
    }

    public function formattedDateTime(): string
    {
        return date(\DATE_FORMAT);
    }

    private function settings(): PdoIngestionSettingsRepository
    {
        return new PdoIngestionSettingsRepository($this->activeConnection());
    }

    private function activeConnection(): PDO
    {
        return $this->messageTransaction ?? \Database::getInstance();
    }

    private function transactionConnection(): PDO
    {
        return $this->messageTransaction
            ?? throw new LogicException('No ingestion message transaction is active');
    }

    private function messageRepository(): PdoIngestionMessageRepository
    {
        return new PdoIngestionMessageRepository($this->transactionConnection());
    }
}
