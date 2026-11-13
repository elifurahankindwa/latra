<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

interface IngestionEffects
{
    public function mapEmergencyType(int $activityId): ?string;

    public function ensureActiveEvent(
        int $vehicleId,
        string $eventType,
        ?string $reason,
        ?float $speed,
        int $activityId,
    ): ?array;

    public function bufferEmergencyRecord(array $event, int $vehicleId, array $message): bool;

    public function updateDeviceStatus(int $vehicleId, array $parameters, int $commTime): void;

    public function buildTripStats(int $vehicleId, int $messageTime): array;

    public function buildAddonInfo(int $activityId, array $parameters, array $customData): ?array;

    public function closeEndedEvent(int $vehicleId, array $messages): void;
}
