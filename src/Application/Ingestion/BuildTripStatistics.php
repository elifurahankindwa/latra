<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

use Latra\Domain\Ingestion\TripStatisticsPolicy;

final class BuildTripStatistics
{
    public function __construct(private TripStatisticsRepository $repository)
    {
    }

    public function build(int $vehicleId, int $engineOffMessageTime): array
    {
        $engineOnTime = $this->repository->latestEngineOnTime($vehicleId, $engineOffMessageTime);
        $fromTime = $engineOnTime ?? $engineOffMessageTime - 86400;
        $messages = $this->repository->messagesBetween($vehicleId, $fromTime, $engineOffMessageTime);

        return TripStatisticsPolicy::calculate($engineOffMessageTime, $fromTime, $messages);
    }
}
