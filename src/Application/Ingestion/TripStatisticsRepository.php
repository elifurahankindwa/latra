<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

interface TripStatisticsRepository
{
    public function latestEngineOnTime(int $vehicleId, int $beforeTime): ?int;

    public function messagesBetween(int $vehicleId, int $fromTime, int $toTime): array;
}
