<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

interface IngestionRepository
{
    public function loadVehicleState(int $vehicleId): array;

    public function enabledActivities(int $vehicleId): array;

    public function speedLimit(array $vehicle): float;

    public function resolveParameters(array $parameters, ?string $deviceType): array;

    public function overspeedConsecutiveSamples(): int;

    public function beginMessage(): void;

    public function storeMessage(array $message): bool;

    public function findMessage(int $vehicleId, int $messageTime): ?array;

    public function recordValidationErrors(array $message, array $errors): void;

    public function updateMessagePayload(array $message, array $payload): void;

    public function activityAllowed(int $activityId): bool;

    public function enabledBucketVehicleIds(): array;

    public function enqueue(array $message, array $payload, ?string $eventId, int $priority): void;

    public function commitMessage(): void;

    public function rollbackMessage(): void;

    public function persistVehicleState(int $vehicleId, array $state): void;

    public function formattedDateTime(): string;
}
