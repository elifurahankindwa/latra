<?php
declare(strict_types=1);

namespace Latra\Application\Delivery;

use Closure;
use Latra\Domain\Delivery\DeliveryClaimStatus;
use Latra\Domain\Delivery\DeliveryDisposition;
use Latra\Domain\Delivery\DeliveryResponsePolicy;
use RuntimeException;
use Throwable;

final class DeliverPendingReport
{
    private Closure $backoff;
    private ?Closure $fault;

    public function __construct(
        private RelayDeliveryRepository $repository,
        private int $maximumAttempts,
        callable $backoff,
        ?callable $fault = null,
    ) {
        $this->backoff = Closure::fromCallable($backoff);
        $this->fault = $fault === null ? null : Closure::fromCallable($fault);
    }

    public function deliver(string $unit, int $now, callable $send, ?callable $canSend = null): string
    {
        if (!$this->repository->acquireUnitLock($unit)) {
            return 'busy';
        }

        try {
            $claim = $this->repository->claimPending($unit, $now, $this->maximumAttempts, $canSend);
            if ($claim->status !== DeliveryClaimStatus::Ready) {
                return $claim->outcome();
            }

            $pendingReport = $claim->report();
            $this->hit('attempt');
            $this->repository->assertUnitLock($unit);
            try {
                $response = $send($pendingReport['payload'], $pendingReport['delivery_key']);
            } catch (Throwable $error) {
                // Keep the cause: "HTTP 0" alone cannot explain an unresolved retry.
                $response = [
                    'code' => 0,
                    'error' => $error::class . ': ' . $error->getMessage(),
                ];
            }

            $attemptNumber = (int) $pendingReport['attempts'] + 1;
            $disposition = DeliveryResponsePolicy::classify($response, $attemptNumber, $this->maximumAttempts);
            if ($disposition === DeliveryDisposition::Accepted) {
                $this->hit('accepted');
                $this->repository->completeAccepted($unit, $pendingReport);
                return 'sent';
            }

            return $this->scheduleRetry($pendingReport, $response, $attemptNumber, $disposition, $now);
        } finally {
            $this->repository->releaseUnitLock($unit);
        }
    }

    private function scheduleRetry(
        array $pendingReport,
        array $response,
        int $attemptNumber,
        DeliveryDisposition $disposition,
        int $now,
    ): string {
        $review = $disposition === DeliveryDisposition::Review;
        $nextAttempt = null;
        if (!$review) {
            $delay = ($this->backoff)($attemptNumber);
            if (!is_int($delay) || $delay < 0) {
                throw new RuntimeException('Explicit nonnegative backoff required');
            }
            $nextAttempt = $now + $delay;
        }

        $detail = trim((string) ($response['error'] ?? ''));
        $this->repository->scheduleRetry(
            (int) $pendingReport['id'],
            $pendingReport['delivery_key'],
            $review ? 'review' : 'pending',
            $nextAttempt,
            ($review ? 'Retained for review' : 'Unconfirmed acceptance')
                . '; HTTP ' . (int) ($response['code'] ?? 0)
                . ($detail === '' ? '' : '; ' . $detail),
        );
        return $review ? 'review' : 'pending';
    }

    private function hit(string $stage): void
    {
        if ($this->fault !== null) {
            ($this->fault)($stage);
        }
    }
}
