<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

use Latra\Domain\Emergency\EmergencyHandoffStatus;

interface EmergencyQueueRepository
{
    public function pendingForEvent(string $eventId): array;

    public function vehicleById(int $vehicleId): ?array;

    public function recordHandoff(int $queueId, EmergencyHandoffStatus $status, string $response): void;
}
