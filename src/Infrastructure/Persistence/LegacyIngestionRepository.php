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
        return \Database::fetch('SELECT * FROM vehicle_state WHERE vehicle_id = ?', [$vehicleId]) ?? [];
    }

    public function enabledActivities(int $vehicleId): array
    {
        return \EventDetector::enabledActivities($vehicleId);
    }

    public function speedLimit(array $vehicle): float
    {
        if (!empty($vehicle['speed_limit_kmh'])) {
            return (float) $vehicle['speed_limit_kmh'];
        }
        return \EventDetector::speedLimit((int) $vehicle['id']);
    }

    public function resolveParameters(array $parameters, ?string $deviceType): array
    {
        return \ParamResolver::resolve($parameters, $deviceType);
    }

    public function overspeedConsecutiveSamples(): int
    {
        return \Schema::configInt('overspeed_consecutive', 2);
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
        return \Automation::isActivityAllowed($activityId);
    }

    public function enabledBucketVehicleIds(): array
    {
        return \Automation::enabledBucketVehicleIds();
    }

    public function enqueue(array $message, array $payload, ?string $eventId, int $priority): void
    {
        \Outbox::enqueue($message, $payload, $eventId, $priority);
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
        \EventDetector::persistState($vehicleId, $state);
    }

    public function formattedDateTime(): string
    {
        return date(\DATE_FORMAT);
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
