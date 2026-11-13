<?php
declare(strict_types=1);

namespace Latra\Application\Delivery;

use Latra\Domain\Delivery\DeliveryClaim;

interface RelayDeliveryRepository
{
    /** Ownership spans the claim, transport and finalization; acquisition must not block. */
    public function acquireUnitLock(string $unit): bool;

    public function releaseUnitLock(string $unit): void;

    /** Commit any reserved attempt before returning; leave no transaction open for transport. */
    public function claimPending(
        string $unit,
        int $now,
        int $maximumAttempts,
        ?callable $canSend = null,
    ): DeliveryClaim;

    /** Atomically finalize identity/progress and remove the accepted pending payload. */
    public function completeAccepted(string $unit, array $pendingReport): void;

    /** Atomically retain retry/review state in both the queue and identity ledger. */
    public function scheduleRetry(
        int $pendingId,
        string $deliveryKey,
        string $status,
        ?int $nextAttempt,
        string $error,
    ): void;
}
