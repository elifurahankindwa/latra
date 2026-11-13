<?php
/** Pure collection identity, ordering and payload decisions; no persistence or bootstrap. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Domain\Collection\RelayCollectionPolicy;

function collectionPolicyExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function collectionPolicyRejects(callable $operation): void
{
    try { $operation(); } catch (RuntimeException | InvalidArgumentException) { return; }
    throw new LogicException('Expected invalid collection input to be rejected');
}

$a = ['t' => 100, 'p' => ['z' => 1, 'a' => 2]];
$b = ['p' => ['a' => 2, 'z' => 1], 't' => 100];
collectionPolicyExpect(RelayCollectionPolicy::canonical($a) === RelayCollectionPolicy::canonical($b),
    'Object key order changed source identity');
collectionPolicyExpect(RelayCollectionPolicy::canonical([2, 1]) === [2, 1], 'List order changed');
collectionPolicyExpect(RelayCollectionPolicy::vehicleUnit('manual:wialon:123:report') === 'wialon:123',
    'Manual stream lost shared vehicle identity');

RelayCollectionPolicy::assertContiguous(['collected_through' => 100], 101);
collectionPolicyRejects(static fn() => RelayCollectionPolicy::assertContiguous(['collected_through' => 100], 102));
RelayCollectionPolicy::assertNotLate(['attempted_time' => 100, 'collected_through' => 99], 100);
collectionPolicyRejects(static fn() => RelayCollectionPolicy::assertNotLate(['attempted_time' => 101, 'collected_through' => null], 100));
collectionPolicyRejects(static fn() => RelayCollectionPolicy::assertNotLate(['attempted_time' => null, 'collected_through' => 100], 100));

$payload = ['items' => [['activity_id' => '1'], ['activity_id' => '5']]];
[$prepared, $activities] = RelayCollectionPolicy::preparePayload($payload, 'source-key', 7, [1]);
collectionPolicyExpect($activities === [5] && $prepared['items'] === [[
    'activity_id' => '5', 'idempotency_key' => 'source-key-a5', 'MGS_ID' => '229',
]], 'Cross-stream suppression or stable item identity changed');
[$empty, $none] = RelayCollectionPolicy::preparePayload($payload, 'source-key', 7, [1, 5]);
collectionPolicyExpect($empty['items'] === [] && $none === [], 'Known activities were queued again');
foreach ([0, 25] as $invalid) {
    collectionPolicyRejects(static fn() => RelayCollectionPolicy::preparePayload(
        ['items' => [['activity_id' => $invalid]]], 'source-key', 7, []));
}
collectionPolicyRejects(static fn() => RelayCollectionPolicy::preparePayload(
    ['items' => [['activity_id' => 5], ['activity_id' => 5]]], 'source-key', 7, [5]));
collectionPolicyRejects(static fn() => RelayCollectionPolicy::preparePayload($payload, 'source-key', PHP_INT_MAX, []));

echo "PASS pure collection ordering, canonical source identity and activity payload policy\n";
