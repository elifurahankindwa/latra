<?php
declare(strict_types=1);

namespace Latra\Application\Delivery;

use Closure;

final class FlushPendingRelayReports
{
    private int $minute = 0;
    private int $attemptsThisMinute = 0;

    private Closure $findReadyUnits;
    private Closure $deliverUnit;
    private Closure $isEligible;
    private Closure $rateLimit;
    private Closure $deliverTransport;

    public function __construct(
        callable $findReadyUnits,
        callable $deliverUnit,
        callable $isEligible,
        callable $rateLimit,
        callable $deliverTransport,
    ) {
        $this->findReadyUnits = Closure::fromCallable($findReadyUnits);
        $this->deliverUnit = Closure::fromCallable($deliverUnit);
        $this->isEligible = Closure::fromCallable($isEligible);
        $this->rateLimit = Closure::fromCallable($rateLimit);
        $this->deliverTransport = Closure::fromCallable($deliverTransport);
    }

    public function flush(int $limit, bool $emergencyOnly, int $seconds): int
    {
        $deadline = microtime(true) + $seconds;
        $processed = 0;

        while ($processed < $limit && microtime(true) < $deadline) {
            $readyUnits = ($this->findReadyUnits)(time());
            if (!$readyUnits) {
                break;
            }

            $advanced = false;
            foreach ($readyUnits as $readyUnit) {
                if ($processed >= $limit || microtime(true) >= $deadline) {
                    break;
                }

                $unit = $readyUnit['unit'];
                $send = function (string $payload, string $deliveryKey): array {
                    $this->resetAttemptWindow();
                    $this->attemptsThisMinute++;
                    return ($this->deliverTransport)($payload, $deliveryKey);
                };
                $canSend = function (string $pendingUnit, array $pendingReport) use ($deadline, $emergencyOnly): bool {
                    if (microtime(true) >= $deadline || !$this->withinRateLimit()) {
                        return false;
                    }

                    $payload = json_decode($pendingReport['payload'] ?? '', true);
                    $activityIds = array_map('intval', array_column($payload['items'] ?? [], 'activity_id'));
                    if (!$activityIds || ($emergencyOnly && !array_intersect($activityIds, [4, 8, 12, 13]))) {
                        return false;
                    }

                    return ($this->isEligible)($pendingUnit, $pendingReport);
                };

                $outcome = ($this->deliverUnit)($unit, time(), $send, $canSend);
                if (!in_array($outcome, ['busy', 'blocked', 'deferred', 'empty'], true)) {
                    $processed++;
                    $advanced = true;
                }
            }

            if (!$advanced) {
                break;
            }
        }

        return $processed;
    }

    private function withinRateLimit(): bool
    {
        $this->resetAttemptWindow();
        return $this->attemptsThisMinute < ($this->rateLimit)();
    }

    private function resetAttemptWindow(): void
    {
        $currentMinute = intdiv(time(), 60);
        if ($this->minute !== $currentMinute) {
            $this->minute = $currentMinute;
            $this->attemptsThisMinute = 0;
        }
    }
}
