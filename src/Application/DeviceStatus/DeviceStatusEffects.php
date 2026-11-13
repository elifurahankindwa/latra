<?php
declare(strict_types=1);

namespace Latra\Application\DeviceStatus;

interface DeviceStatusEffects
{
    public function recordTransition(int $vehicleId, string $type, string $detail): void;

    public function statusChanged(int $vehicleId, array $status): void;
}
