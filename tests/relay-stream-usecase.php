<?php
/** Window selection and bounded-prefix retry without a database or Wialon client. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Application\Collection\CollectRelayStream;

function streamExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$progress = null;
$calls = [];
$failures = 0;
$errorCode = 1001;
$result = ['fetched' => 2, 'queued' => 2];
$collect = new CollectRelayStream(
    static function (string $unit) use (&$progress): ?array {
        streamExpect($unit === 'wialon:123:poll' || $unit === 'wialon:123:events', 'Progress stream changed');
        return $progress;
    },
    static function (int $from, int $to, int $pageSize, int $maximum, int $bytes) use (&$calls, &$failures, &$errorCode, $result): array {
        $calls[] = [$from, $to, $pageSize, $maximum, $bytes];
        if ($failures-- > 0) throw new RuntimeException('Synthetic source bound', $errorCode);
        return $result;
    },
    static fn(): int => 1000,
);
streamExpect($collect->collect('wialon:123', ':poll', 10, 100, 80, 4096) === $result
    && $calls === [[820, 1000, 50, 80, 4096]], 'Initial poll window or caps changed');
$calls = [];
$collect->collect('wialon:123', ':events', 10, 20, 80, 4096);
streamExpect($calls === [[940, 1000, 20, 20, 4096]], 'Initial event window changed');

$progress = ['collected_through' => 900];
$calls = [];
$failures = 2;
$collect->collect('wialon:123', ':poll', 180, 100, 80, 4096);
streamExpect($calls === [[901, 1000, 50, 80, 4096], [901, 950, 50, 80, 4096], [901, 925, 50, 80, 4096]],
    'Over-bound retry skipped source seconds or changed work caps');
$calls = [];
$collect->collect('wialon:123', ':poll', 180, 100, 80, 4096, 500, 600);
streamExpect($calls === [[500, 600, 50, 80, 4096]], 'Explicit backfill bounds were ignored');

$calls = [];
$progress = ['collected_through' => 1000];
streamExpect($collect->collect('wialon:123', ':poll', 180, 100, 80, 4096) === [
    'fetched' => 0, 'queued' => 0, 'inserted' => 0, 'rejected' => 0, 'skipped' => 0,
] && $calls === [], 'Caught-up stream fetched another window');

foreach ([[1001, 700, 700], [42, 700, 800]] as [$errorCode, $from, $to]) {
    $calls = [];
    $failures = 1;
    try {
        $collect->collect('wialon:123', ':poll', 180, 100, 80, 4096, $from, $to);
        throw new LogicException('Expected source failure');
    } catch (RuntimeException $error) {
        streamExpect($error->getCode() === $errorCode && count($calls) === 1,
            'Single-second or unrelated source failure retried or disappeared');
    }
}

echo "PASS stream cursors, initial bounds, capped prefix retries, explicit backfill and failure propagation\n";
