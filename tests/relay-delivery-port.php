<?php
/** Delivery orchestration through an application port, without PDO or infrastructure. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Application\Delivery\DeliverPendingReport;
use Latra\Application\Delivery\RelayDeliveryRepository;
use Latra\Domain\Delivery\DeliveryClaim;
use Latra\Domain\Delivery\DeliveryClaimStatus;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Latra\\Infrastructure\\')) {
        throw new LogicException('Delivery use case loaded infrastructure: ' . $class);
    }
}, true, true);

function deliveryPortExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$repository = new class implements RelayDeliveryRepository {
    public array $calls = [];
    public bool $locked = true;
    public bool $failCleanup = false;
    public array $report = ['id' => 7, 'delivery_key' => 'stable-key', 'payload' => '{"items":[]}',
        'attempts' => 0, 'source_time' => 50];

    public function acquireUnitLock(string $unit): bool
    {
        $this->calls[] = ['lock', $unit];
        return $this->locked;
    }
    public function releaseUnitLock(string $unit): void { $this->calls[] = ['release', $unit]; }
    public function claimPending(string $unit, int $now, int $maximumAttempts, ?callable $canSend = null): DeliveryClaim
    {
        $this->calls[] = ['claim', $unit, $now, $maximumAttempts];
        return $canSend !== null && !$canSend($this->report)
            ? new DeliveryClaim(DeliveryClaimStatus::Deferred)
            : new DeliveryClaim(DeliveryClaimStatus::Ready, $this->report);
    }
    public function completeAccepted(string $unit, array $pendingReport): void
    {
        $this->calls[] = ['accepted', $unit, $pendingReport];
        if ($this->failCleanup) throw new RuntimeException('Synthetic cleanup failure');
    }
    public function scheduleRetry(int $pendingId, string $deliveryKey, string $status, ?int $nextAttempt, string $error): void
    {
        $this->calls[] = ['retry', $pendingId, $deliveryKey, $status, $nextAttempt, $error];
    }
};
$delivery = new DeliverPendingReport($repository, 2, static fn(int $attempt): int => $attempt * 5);
$accept = static function (string $body, string $key) use ($repository): array {
    deliveryPortExpect($body === $repository->report['payload'] && $key === 'stable-key',
        'Sender did not receive the persisted body and stable key');
    $repository->calls[] = ['send'];
    return ['code' => 200, 'data' => ['status' => '1'], 'error' => ''];
};
deliveryPortExpect($delivery->deliver('unit', 100, $accept) === 'sent', 'Acceptance outcome changed');
deliveryPortExpect($repository->calls === [['lock', 'unit'], ['claim', 'unit', 100, 2], ['send'],
    ['accepted', 'unit', $repository->report], ['release', 'unit']], 'Claim/send/finalization ordering changed');

$repository->calls = [];
deliveryPortExpect($delivery->deliver('unit', 100, $accept, static fn(array $report): bool => false) === 'deferred'
    && $repository->calls === [['lock', 'unit'], ['claim', 'unit', 100, 2], ['release', 'unit']],
    'Eligibility deferral called transport or finalization');

$repository->calls = [];
deliveryPortExpect($delivery->deliver('unit', 100, static fn() => throw new RuntimeException('Transport failed')) === 'pending'
    && $repository->calls[2] === ['retry', 7, 'stable-key', 'pending', 105,
        'Unconfirmed acceptance; HTTP 0; RuntimeException: Transport failed']
    && end($repository->calls) === ['release', 'unit'], 'Transport failure did not schedule retry and release ownership');

$repository->calls = [];
$repository->report['attempts'] = 1;
deliveryPortExpect($delivery->deliver('unit', 100, static fn(): array => ['code' => 503]) === 'review'
    && $repository->calls[2] === ['retry', 7, 'stable-key', 'review', null, 'Retained for review; HTTP 503'],
    'Exhausted retry outcome or backoff changed');

$repository->calls = [];
$repository->failCleanup = true;
try {
    $delivery->deliver('unit', 100, $accept);
    throw new LogicException('Expected cleanup failure');
} catch (RuntimeException $error) {
    deliveryPortExpect($error->getMessage() === 'Synthetic cleanup failure'
        && end($repository->calls) === ['release', 'unit'], 'Cleanup failure did not propagate and release ownership');
}
$repository->calls = [];
$repository->locked = false;
deliveryPortExpect($delivery->deliver('unit', 100, $accept) === 'busy'
    && $repository->calls === [['lock', 'unit']], 'Busy ownership reached claim or transport');

echo "PASS delivery application port, eligibility, stable body/key, retry and ownership cleanup\n";
