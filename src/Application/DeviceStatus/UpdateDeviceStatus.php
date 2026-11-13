<?php
declare(strict_types=1);

namespace Latra\Application\DeviceStatus;

use Latra\Domain\Ingestion\DeviceStatusPolicy;

final class UpdateDeviceStatus
{
    public function __construct(
        private DeviceStatusRepository $repository,
        private DeviceStatusEffects $effects,
        private DeviceStatusClock $clock,
    ) {
    }

    public function execute(int $vehicleId, array $parameters, int $commTime): void
    {
        $previous = $this->repository->findByVehicleId($vehicleId);
        $evaluation = DeviceStatusPolicy::evaluate($parameters, $previous ?? []);
        $status = [
            'is_online' => 1,
            'online_at' => $previous['online_at'] ?? $this->clock->now(),
            'last_comm_time' => $commTime,
            'last_comm_at' => $this->clock->at($commTime),
            'last_gps_at' => $commTime,
            'internal_battery_low' => $evaluation['internal_battery_low'],
            'internal_battery_mv' => $evaluation['internal_battery_mv'],
            'internal_battery_pct' => $evaluation['internal_battery_pct'],
            'external_power_disconnected' => $evaluation['external_power_disconnected'],
            'external_power_mv' => $evaluation['external_power_mv'],
            'tampered' => $evaluation['tampered'],
            'status_changed_at' => $this->clock->now(),
        ];

        foreach ($evaluation['events'] as $event) {
            $this->effects->recordTransition($vehicleId, $event['type'], $event['detail']);
        }

        if ($previous !== null) {
            $this->repository->update($vehicleId, $status);
        } else {
            $this->repository->insert($vehicleId, $status);
        }

        if ($evaluation['changed']) {
            $this->effects->statusChanged($vehicleId, $status);
        }
    }
}
