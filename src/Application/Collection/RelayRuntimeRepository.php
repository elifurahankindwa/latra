<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

interface RelayRuntimeRepository
{
    public function activeVehiclesForBucket(int $bucketId): array;

    public function activeVehiclesForEventCollection(): array;

    public function enabledEventActivities(): array;
}
