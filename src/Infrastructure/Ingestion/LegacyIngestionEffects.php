<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Ingestion;

use Latra\Application\Ingestion\IngestionEffects;

final class LegacyIngestionEffects implements IngestionEffects
{
    public function mapEmergencyType(int $activityId): ?string
    {
        return \EmergencyHandler::mapEmergencyType($activityId);
    }

    public function ensureActiveEvent(
        int $vehicleId,
        string $eventType,
        ?string $reason,
        ?float $speed,
        int $activityId,
    ): ?array {
        return \EmergencyHandler::ensureActiveEvent($vehicleId, $eventType, $reason, $speed, $activityId);
    }

    public function bufferEmergencyRecord(array $event, int $vehicleId, array $message): bool
    {
        return \EmergencyHandler::bufferRecord($event, $vehicleId, $message);
    }

    public function updateDeviceStatus(int $vehicleId, array $parameters, int $commTime): void
    {
        \DeviceMonitor::updateFromMessage($vehicleId, $parameters, $commTime);
    }

    public function buildTripStats(int $vehicleId, int $messageTime): array
    {
        return \LatraMapper::buildTripStats($vehicleId, $messageTime);
    }

    public function buildAddonInfo(int $activityId, array $parameters, array $customData): ?array
    {
        return \LatraMapper::buildAddonInfo($activityId, $parameters, $customData);
    }

    public function closeEndedEvent(int $vehicleId, array $messages): void
    {
        \EmergencyHandler::closeEndedEvent($vehicleId, $messages);
    }
}
