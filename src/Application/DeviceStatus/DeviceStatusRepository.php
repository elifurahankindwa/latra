<?php
declare(strict_types=1);

namespace Latra\Application\DeviceStatus;

interface DeviceStatusRepository
{
    public function findByVehicleId(int $vehicleId): ?array;

    public function insert(int $vehicleId, array $status): void;

    public function update(int $vehicleId, array $status): void;
}
