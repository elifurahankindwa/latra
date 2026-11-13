<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use Closure;
use Throwable;

final class CollectRelayEventCycle
{
    private Closure $collect;
    private Closure $warn;

    public function __construct(
        private RelayRuntimeRepository $repository,
        callable $collect,
        callable $warn,
    ) {
        $this->collect = Closure::fromCallable($collect);
        $this->warn = Closure::fromCallable($warn);
    }

    public function run(int $window, int $limit): array
    {
        $stats = ['vehicles' => 0, 'fetched' => 0, 'detected' => 0, 'sent' => 0, 'failed' => 0];
        $activities = $this->repository->enabledEventActivities();

        foreach ($this->repository->activeVehiclesForEventCollection() as $vehicle) {
            try {
                $result = ($this->collect)(
                    $vehicle,
                    max(60, $window),
                    max(1, $limit),
                    ':events',
                    $activities,
                );
                $stats['vehicles']++;
                $stats['fetched'] += $result['fetched'];
                $stats['detected'] += $result['inserted'];
            } catch (Throwable $error) {
                $stats['failed']++;
                ($this->warn)('relay.events', [
                    'vehicle' => (int) $vehicle['id'],
                    'error' => $error->getMessage(),
                ]);
            }
        }

        return $stats;
    }
}
