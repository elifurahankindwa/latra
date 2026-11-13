<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

interface ScheduledVehicleRepository
{
    public function ordinaryVehicles(): array;
    public function emergencyVehicles(): array;
}
