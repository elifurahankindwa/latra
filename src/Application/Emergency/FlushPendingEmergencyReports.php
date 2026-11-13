<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

use Closure;
use Latra\Domain\Emergency\EmergencyHandoffStatus;

final class FlushPendingEmergencyReports
{
    private Closure $buildPayload;
    private Closure $submit;

    public function __construct(
        private EmergencyQueueRepository $repository,
        callable $buildPayload,
        callable $submit,
    ) {
        $this->buildPayload = Closure::fromCallable($buildPayload);
        $this->submit = Closure::fromCallable($submit);
    }

    public function flush(array $event, int $vehicleId): array
    {
        $rows = $this->repository->pendingForEvent((string) $event['event_id']);
        $vehicle = $this->repository->vehicleById($vehicleId) ?? [];
        $counts = ['submitted' => 0, 'queued' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $payload = ($this->buildPayload)($event, $row, $vehicle);
            $result = ($this->submit)($payload, $vehicleId);
            $status = EmergencyHandoffStatus::fromResult($result);
            $this->repository->recordHandoff(
                (int) $row['id'],
                $status,
                (string) ($result['response'] ?? ''),
            );
            $counts[$status === EmergencyHandoffStatus::Queued ? 'queued'
                : ($status === EmergencyHandoffStatus::Sent ? 'submitted' : 'failed')]++;
        }

        return $counts;
    }
}
