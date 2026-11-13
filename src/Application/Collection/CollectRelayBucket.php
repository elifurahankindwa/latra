<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use Closure;
use Throwable;

final class CollectRelayBucket
{
    private Closure $collect;
    private Closure $createSource;
    private Closure $warn;

    public function __construct(
        private RelayRuntimeRepository $repository,
        callable $collect,
        callable $createSource,
        callable $warn,
    ) {
        $this->collect = Closure::fromCallable($collect);
        $this->createSource = Closure::fromCallable($createSource);
        $this->warn = Closure::fromCallable($warn);
    }

    public function run(array $bucket): array
    {
        $stats = [
            'fetched' => 0,
            'inserted' => 0,
            'queued' => 0,
            'rejected' => 0,
            'pushed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'denied' => 0,
        ];
        $vehicles = $this->repository->activeVehiclesForBucket((int) $bucket['id']);
        $started = microtime(true);

        foreach ($vehicles as $vehicle) {
            if (microtime(true) - $started > max(30, (int) ($bucket['max_run_seconds'] ?? 300))) {
                break;
            }
            if ($stats['queued'] >= max(1, (int) ($bucket['max_items_per_run'] ?? 100))) {
                break;
            }
            try {
                $source = ($this->createSource)($vehicle);
                $result = ($this->collect)(
                    $vehicle,
                    180,
                    100,
                    ':activity:' . (int) $bucket['activity_id'],
                    [(int) $bucket['activity_id']],
                    $source,
                );
                foreach (['fetched', 'inserted', 'queued', 'rejected', 'skipped'] as $key) {
                    $stats[$key] += $result[$key];
                }
            } catch (Throwable $error) {
                $stats['failed']++;
                ($this->warn)('relay.bucket', [
                    'vehicle' => (int) $vehicle['id'],
                    'error' => $error->getMessage(),
                ]);
            }
        }

        return $stats;
    }
}
