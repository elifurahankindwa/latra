<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use Closure;
use RuntimeException;

final class CollectRelayStream
{
    private Closure $progress;
    private Closure $collectWindow;
    private Closure $clock;

    public function __construct(callable $progress, callable $collectWindow, callable $clock)
    {
        $this->progress = Closure::fromCallable($progress);
        $this->collectWindow = Closure::fromCallable($collectWindow);
        $this->clock = Closure::fromCallable($clock);
    }

    public function collect(
        string $unit,
        string $stream,
        int $window,
        int $limit,
        int $maxRecords,
        int $maxBytes,
        ?int $explicitFrom = null,
        ?int $explicitTo = null,
    ): array {
        $progress = ($this->progress)($unit . $stream);
        $to = $explicitTo ?? ($this->clock)();
        $initialWindow = $stream === ':events' ? max(60, $window) : max(180, $window);
        $from = $explicitFrom ?? ($progress && $progress['collected_through'] !== null
            ? (int) $progress['collected_through'] + 1 : $to - $initialWindow);
        if ($from > $to) return ['fetched' => 0, 'queued' => 0, 'inserted' => 0, 'rejected' => 0, 'skipped' => 0];
        $maximum = min($limit, $maxRecords);
        // Shrink only the endpoint, preserving the contiguous cursor and the caller's work cap.
        while (true) {
            try {
                return ($this->collectWindow)($from, $to, min(50, $maximum), $maximum, $maxBytes);
            } catch (RuntimeException $error) {
                if ($error->getCode() !== 1001 || $from === $to) throw $error;
                $to = $from + intdiv($to - $from, 2);
            }
        }
    }
}
