<?php
/** Flush policy checks with synthetic queue and sender callbacks; no database or network. */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Application\Delivery\FlushPendingRelayReports;

function flushExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$rateBatches = [[['unit' => 'unit-e'], ['unit' => 'unit-f']]];
$rateSent = [];
$rateService = new FlushPendingRelayReports(
    static function () use (&$rateBatches): array {
        return array_shift($rateBatches) ?? [];
    },
    static function (string $unit, int $now, callable $send, callable $canSend) use (&$rateSent): string {
        $pending = ['payload' => '{"items":[{"activity_id":4}]}', 'delivery_key' => $unit];
        if (!$canSend($unit, $pending)) return 'deferred';
        $send($pending['payload'], $pending['delivery_key']);
        $rateSent[] = $unit;
        return 'sent';
    },
    static fn(): bool => true,
    static fn(): int => 1,
    static fn(string $payload, string $key): array => ['code' => 200, 'data' => ['status' => '1'], 'key' => $key],
);
flushExpect($rateService->flush(10, false, 120) === 1 && count($rateSent) === 1,
    'Per-minute transport budget should stop further sends');

// The attempt budget is per flush run, not process-global state shared between instances.
$rateBatches = [[['unit' => 'unit-e']]];
$rateSent = [];
$rateSecond = new FlushPendingRelayReports(
    static function () use (&$rateBatches): array {
        return array_shift($rateBatches) ?? [];
    },
    static function (string $unit, int $now, callable $send, callable $canSend) use (&$rateSent): string {
        $pending = ['payload' => '{"items":[{"activity_id":4}]}', 'delivery_key' => $unit];
        if (!$canSend($unit, $pending)) return 'deferred';
        $send($pending['payload'], $pending['delivery_key']);
        $rateSent[] = $unit;
        return 'sent';
    },
    static fn(): bool => true,
    static fn(): int => 1,
    static fn(string $payload, string $key): array => ['code' => 200, 'data' => ['status' => '1'], 'key' => $key],
);
flushExpect($rateSecond->flush(10, false, 120) === 1 && count($rateSent) === 1,
    'A fresh flush instance inherited another instance\'s attempt budget');

$pendingBatches = [
    [
        ['unit' => 'unit-a'],
        ['unit' => 'unit-b'],
    ],
];
$sent = [];
$service = new FlushPendingRelayReports(
    static function () use (&$pendingBatches): array {
        return array_shift($pendingBatches) ?? [];
    },
    static function (string $unit, int $now, callable $send, callable $canSend) use (&$sent): string {
        $pending = ['payload' => '{"items":[{"activity_id":1}]}', 'delivery_key' => $unit];
        if (!$canSend($unit, $pending)) return 'deferred';
        $send($pending['payload'], $pending['delivery_key']);
        $sent[] = [$now, $unit];
        return 'sent';
    },
    static fn(): bool => true,
    static fn(): int => 300,
    static fn(string $payload, string $key): array => ['code' => 200, 'data' => ['status' => '1'], 'key' => $key],
);

$processed = $service->flush(10, false, 120);
flushExpect($processed === 2, 'Flush count should include both completed reports');
flushExpect(count($sent) === 2 && $sent[0][1] === 'unit-a' && $sent[1][1] === 'unit-b',
    'Flush did not invoke send callbacks for eligible reports');

$boundedBatches = [[['unit' => 'unit-bounded-a'], ['unit' => 'unit-bounded-b']]];
$boundedSent = [];
$boundedService = new FlushPendingRelayReports(
    static function () use (&$boundedBatches): array {
        return array_shift($boundedBatches) ?? [];
    },
    static function (string $unit, int $now, callable $send, callable $canSend) use (&$boundedSent): string {
        $pending = ['payload' => '{"items":[{"activity_id":1}]}', 'delivery_key' => $unit];
        if (!$canSend($unit, $pending)) return 'deferred';
        $send($pending['payload'], $pending['delivery_key']);
        $boundedSent[] = $unit;
        return 'sent';
    },
    static fn(): bool => true,
    static fn(): int => 300,
    static fn(string $payload, string $key): array => ['code' => 200, 'data' => ['status' => '1'], 'key' => $key],
);
flushExpect($boundedService->flush(1, false, 120) === 1 && count($boundedSent) === 1,
    'Flush exceeded its configured work cap');

$expiredBatches = [[['unit' => 'unit-expired']]];
$expiredDelivered = false;
$expiredService = new FlushPendingRelayReports(
    static function () use (&$expiredBatches): array {
        usleep(1_100_000);
        return array_shift($expiredBatches) ?? [];
    },
    static function (string $unit, int $now, callable $send, callable $canSend) use (&$expiredDelivered): string {
        $expiredDelivered = true;
        return 'sent';
    },
    static fn(): bool => true,
    static fn(): int => 300,
    static fn(string $payload, string $key): array => [],
);
flushExpect($expiredService->flush(10, false, 1) === 0 && !$expiredDelivered,
    'Expired flush deadline dispatched another report');

$emergencyBatches = [[['unit' => 'unit-c'], ['unit' => 'unit-d']]];
$emergencySent = [];
$emergencyService = new FlushPendingRelayReports(
    static function () use (&$emergencyBatches): array {
        return array_shift($emergencyBatches) ?? [];
    },
    static function (string $unit, int $now, callable $send, callable $canSend) use (&$emergencySent): string {
        $pending = ['payload' => json_encode(['items' => [['activity_id' => $unit === 'unit-c' ? 1 : 12]]]), 'delivery_key' => $unit];
        if (!$canSend($unit, $pending)) return 'deferred';
        $send($pending['payload'], $pending['delivery_key']);
        $emergencySent[] = $unit;
        return 'sent';
    },
    static fn(): bool => true,
    static fn(): int => 300,
    static fn(string $payload, string $key): array => ['code' => 200, 'data' => ['status' => '1'], 'key' => $key],
);
$emergencyProcessed = $emergencyService->flush(10, true, 120);
flushExpect($emergencyProcessed === 1 && $emergencySent === ['unit-d'],
    'Emergency flush must exclude non-emergency payloads');

$notEligibleBatches = [[['unit' => 'unit-g']]];
$notEligibleService = new FlushPendingRelayReports(
    static function () use (&$notEligibleBatches): array {
        return array_shift($notEligibleBatches) ?? [];
    },
    static function (string $unit, int $now, callable $send, callable $canSend): string {
        return $canSend($unit, ['payload' => '{"items":[{"activity_id":1}]}'])
            ? throw new LogicException('Ineligible unit passed preflight')
            : 'deferred';
    },
    static fn(): bool => false,
    static fn(): int => 300,
    static fn(string $payload, string $key): array => ['code' => 200, 'data' => ['status' => '1'], 'key' => $key],
);
flushExpect($notEligibleService->flush(10, false, 120) === 0,
    'Queue without sendable progress should stop without counting work');

echo "PASS flush service preserves limits, emergency filtering, eligibility and per-minute send budget\n";
