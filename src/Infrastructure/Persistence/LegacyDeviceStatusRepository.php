<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\DeviceStatus\DeviceStatusRepository;

final class LegacyDeviceStatusRepository implements DeviceStatusRepository
{
    public function findByVehicleId(int $vehicleId): ?array
    {
        return \Database::fetch(
            'SELECT * FROM device_status WHERE vehicle_id = ?',
            [$vehicleId],
        );
    }

    public function insert(int $vehicleId, array $status): void
    {
        \Database::insert('device_status', array_merge(['vehicle_id' => $vehicleId], $status));
    }

    public function update(int $vehicleId, array $status): void
    {
        \Database::update('device_status', $status, 'vehicle_id = ?', [$vehicleId]);
    }
}
