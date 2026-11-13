<?php
/** Application collection must coordinate through its port without loading PDO adapters. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Application\Collection\PersistRelayWindow;
use Latra\Application\Collection\RelayCollectionRepository;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Latra\\Infrastructure\\')) {
        throw new LogicException('Collection loaded infrastructure: ' . $class);
    }
}, true, true);

$repository = new class implements RelayCollectionRepository {
    public array $calls = [];
    public function beginWindow(string $unit, string $vehicleUnit): array
    {
        $this->calls[] = ['begin', $unit, $vehicleUnit];
        return ['collected_through' => null, 'attempted_time' => null, 'state_json' => '{}'];
    }
    public function capacity(string $unit, string $vehicleUnit): array { return ['records' => 0, 'bytes' => 0]; }
    public function hasIdentity(string $key): bool { throw new LogicException('Unexpected identity lookup'); }
    public function createIdentity(string $key, string $unit, int $time, string $sourceKey): int { throw new LogicException('Unexpected identity write'); }
    public function setIdentityState(string $key, string $state): void { throw new LogicException('Unexpected identity state'); }
    public function knownActivities(string $sourceKey): array { throw new LogicException('Unexpected activity lookup'); }
    public function addActivityIdentity(string $sourceKey, int $activity, string $key): void { throw new LogicException('Unexpected activity write'); }
    public function addPending(string $key, string $unit, int $time, string $body, string $status, ?string $error): void { throw new LogicException('Unexpected pending write'); }
    public function saveCheckpoint(string $unit, int $through, array $mappingState): void
    {
        $this->calls[] = ['checkpoint', $unit, $through, $mappingState];
    }
    public function commitWindow(): void { $this->calls[] = ['commit']; }
    public function rollbackWindow(): void { $this->calls[] = ['rollback']; }
};
$collector = new PersistRelayWindow($repository, 10, 4096);
$map = static fn() => throw new LogicException('Empty window reached mapper');
$empty = $collector->collectWindow('wialon:123:poll', 100, 110, 1, 10, 4096,
    static fn(): array => ['count' => 0, 'messages' => []], $map);
if ($empty !== ['fetched' => 0, 'queued' => 0, 'inserted' => 0, 'rejected' => 0, 'skipped' => 0]
    || $repository->calls !== [['begin', 'wialon:123:poll', 'wialon:123'],
        ['checkpoint', 'wialon:123:poll', 110, []], ['commit']]) {
    throw new RuntimeException('Complete empty window did not commit its checkpoint through the port');
}
$repository->calls = [];
try {
    $collector->collectWindow('wialon:123:poll', 100, 110, 1, 10, 4096,
        static fn(): array => ['count' => 1, 'messages' => []], $map);
    throw new LogicException('Expected incomplete-window failure');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Incomplete, changed or over-bound source window'
        || $repository->calls !== [['begin', 'wialon:123:poll', 'wialon:123'], ['rollback']]) {
        throw new LogicException('Incomplete window committed progress or lost rollback');
    }
}

echo "PASS collection application port commits complete empty windows and rolls back incomplete windows\n";
