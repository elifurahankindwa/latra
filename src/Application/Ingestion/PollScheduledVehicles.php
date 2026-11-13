<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

use Closure;
use Throwable;

final class PollScheduledVehicles
{
    private Closure $poll;
    private Closure $warn;
    private Closure $flush;

    public function __construct(
        private ScheduledVehicleRepository $repository,
        callable $poll,
        callable $warn,
        callable $flush,
    ) {
        $this->poll = Closure::fromCallable($poll);
        $this->warn = Closure::fromCallable($warn);
        $this->flush = Closure::fromCallable($flush);
    }

    public function ordinary(int $window): int
    {
        return $this->run($this->repository->ordinaryVehicles(), $window, 80, false);
    }

    public function emergency(): int
    {
        $vehicles = $this->repository->emergencyVehicles();
        if (!$vehicles) {
            return 0;
        }
        return $this->run($vehicles, 5, 20, true) + ($this->flush)(true);
    }

    private function run(array $vehicles, int $window, int $limit, bool $emergency): int
    {
        $items = 0;
        foreach ($vehicles as $vehicle) {
            try {
                $result = ($this->poll)($vehicle, $window, $limit);
                $items += $result['inserted'] + ($emergency ? 0 : $result['rejected']);
            } catch (Throwable $error) {
                $context = ['vehicle' => $vehicle['id']];
                if (!$emergency) {
                    $context['error'] = $error->getMessage();
                }
                ($this->warn)($emergency ? 'emergency.poll_failed' : 'poll.vehicle_failed', $context);
            }
        }
        return $items;
    }
}
