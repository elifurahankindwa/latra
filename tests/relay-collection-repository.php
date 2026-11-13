<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

class CollectionRepositorySqlite extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $query);
        $query = str_replace(' FOR UPDATE', '', $query);
        $query = str_replace('OCTET_LENGTH(payload)', 'LENGTH(payload)', $query);
        return parent::prepare($query, $options);
    }
}

$database = new CollectionRepositorySqlite();
$database->exec('CREATE TABLE relay_units (
    unit TEXT PRIMARY KEY,
    collected_through INTEGER,
    delivered_time INTEGER,
    attempted_time INTEGER,
    state_json TEXT
)');
$database->exec('CREATE TABLE relay_identity (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    delivery_key TEXT UNIQUE,
    unit TEXT,
    source_time INTEGER,
    state TEXT,
    source_key TEXT
)');
$database->exec('CREATE TABLE relay_activity_identity (
    source_key TEXT,
    activity_id INTEGER,
    delivery_key TEXT,
    PRIMARY KEY (source_key, activity_id)
)');
$database->exec('CREATE TABLE relay_pending (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    delivery_key TEXT UNIQUE,
    unit TEXT,
    source_time INTEGER,
    payload TEXT,
    status TEXT,
    last_error TEXT, next_attempt INTEGER DEFAULT 0
)');

$messages = [
    ['t' => 100, 'p' => ['x' => 0]],
    ['t' => 101, 'p' => ['x' => 1]],
];
$fetchPage = static fn(int $offset, int $limit): array => [
    'count' => count($messages),
    'messages' => array_slice($messages, $offset, $limit),
];
$map = static fn(array $message, array $state): array => [
    'payload' => ['items' => [['activity_id' => '1', 'timestamp' => (string) $message['t']]]],
    'state' => ['last_time' => $message['t']],
];
$repository = new \Latra\Application\Collection\PersistRelayWindow(
    new \Latra\Infrastructure\Persistence\PdoRelayCollectionRepository($database), 10, 4096,
);
$first = $repository->collectWindow('wialon:123:poll', 100, 102, 1, 10, 4096, $fetchPage, $map);
if ($first !== ['fetched' => 2, 'queued' => 2, 'inserted' => 2, 'rejected' => 0, 'skipped' => 0]) {
    throw new RuntimeException('Collection repository changed first-window results');
}
$replay = $repository->collectWindow('wialon:123:poll', 100, 102, 1, 10, 4096, $fetchPage, $map);
if ($replay['queued'] !== 0 || $replay['skipped'] !== 2
    || (int) $database->query('SELECT collected_through FROM relay_units WHERE unit="wialon:123:poll"')->fetchColumn() !== 102
    || (int) $database->query('SELECT COUNT(*) FROM relay_pending')->fetchColumn() !== 2) {
    throw new RuntimeException('Replay or checkpoint behavior changed');
}

$faulting = new \Latra\Application\Collection\PersistRelayWindow(
    new \Latra\Infrastructure\Persistence\PdoRelayCollectionRepository($database),
    10,
    4096,
    static function (string $stage): void {
        if ($stage === 'intent') {
            throw new RuntimeException('Synthetic persistence fault');
        }
    },
);
try {
    $faulting->collectWindow(
        'wialon:123:fault',
        103,
        103,
        1,
        10,
        4096,
        static fn(): array => ['count' => 1, 'messages' => [['t' => 103]]],
        static fn(): array => ['payload' => ['items' => [['activity_id' => '2']]], 'state' => []],
    );
    throw new LogicException('Expected transactional fault');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Synthetic persistence fault') {
        throw $error;
    }
}
if ($database->inTransaction()
    || $database->query("SELECT collected_through FROM relay_units WHERE unit='wialon:123:fault'")->fetchColumn() !== null
    || (int) $database->query('SELECT COUNT(*) FROM relay_identity')->fetchColumn() !== 2
    || (int) $database->query('SELECT COUNT(*) FROM relay_pending')->fetchColumn() !== 2) {
    throw new RuntimeException('Collection repository leaked partial state after rollback');
}

$crossStream = $repository->collectWindow('wialon:123:events', 100, 102, 1, 10, 4096, $fetchPage, $map);
if ($crossStream['queued'] !== 0 || $crossStream['skipped'] !== 2
    || (int) $database->query('SELECT COUNT(*) FROM relay_pending')->fetchColumn() !== 2) {
    throw new RuntimeException('Shared source activities were duplicated across streams');
}

// Canonical ordering must feed mapping state chronologically, independently of page order.
$seen = [];
$ordered = $repository->collectWindow('wialon:900:poll', 200, 202, 10, 10, 4096,
    static fn(): array => ['count' => 2, 'messages' => [['t' => 202], ['t' => 200]]],
    static function (array $message, array $state) use (&$seen, $map): array {
        $seen[] = [$message['t'], $state['last_time'] ?? null];
        return $map($message, $state);
    });
if ($seen !== [[200, null], [202, 200]] || $ordered['queued'] !== 2) {
    throw new RuntimeException('Chronological mapping state changed');
}

// A newly discovered old report stays durable for review and does not mutate mapping state.
$late = $repository->collectWindow('wialon:900:poll', 201, 202, 1, 10, 4096,
    static fn(): array => ['count' => 1, 'messages' => [['t' => 201, 'p' => ['late' => true]]]],
    static fn() => throw new LogicException('Late report reached mapper'));
$lateRow = $database->query("SELECT payload,status FROM relay_pending WHERE unit='wialon:900:poll' AND source_time=201")->fetch(PDO::FETCH_ASSOC);
if ($late['rejected'] !== 1 || $lateRow['status'] !== 'review'
    || json_decode($lateRow['payload'], true) !== ['p' => ['late' => true], 't' => 201]
    || $database->query("SELECT state_json FROM relay_units WHERE unit='wialon:900:poll'")->fetchColumn() !== '{"last_time":202}') {
    throw new RuntimeException('Late-source evidence or mapping state was lost');
}

$beforeIdentities = (int) $database->query('SELECT COUNT(*) FROM relay_identity')->fetchColumn();
$beforePending = (int) $database->query('SELECT COUNT(*) FROM relay_pending')->fetchColumn();
$cases = [
    'short-page' => [10, 4096, 4096, static fn(): array => ['count' => 2, 'messages' => [['t' => 300]]], $map],
    'invalid-time' => [10, 4096, 4096, static fn(): array => ['count' => 1, 'messages' => [['t' => 301]]], $map],
    'source-bytes' => [10, 4096, 1, static fn(): array => ['count' => 1, 'messages' => [['t' => 300]]], $map],
    'mapped-bytes' => [10, 4096, 100, static fn(): array => ['count' => 1, 'messages' => [['t' => 300]]],
        static fn(): array => ['payload' => ['items' => [['activity_id' => 1, 'extra' => str_repeat('x', 200)]]]]],
    'record-capacity' => [1, 4096, 4096, static fn(): array => ['count' => 2, 'messages' => [['t' => 300], ['t' => 300, 'p' => ['x' => 1]]]], $map],
    'byte-capacity' => [10, 1, 4096, static fn(): array => ['count' => 1, 'messages' => [['t' => 300]]], $map],
];
foreach ($cases as $name => [$records, $bytes, $windowBytes, $fetch, $mapping]) {
    $bounded = new \Latra\Application\Collection\PersistRelayWindow(
        new \Latra\Infrastructure\Persistence\PdoRelayCollectionRepository($database), $records, $bytes);
    $unit = 'wialon:901:' . $name;
    try {
        $bounded->collectWindow($unit, 300, 300, 10, 10, $windowBytes, $fetch, $mapping);
        throw new LogicException('Expected bound failure: ' . $name);
    } catch (RuntimeException) {
        if ($database->inTransaction()
            || (int) $database->query('SELECT COUNT(*) FROM relay_identity')->fetchColumn() !== $beforeIdentities
            || (int) $database->query('SELECT COUNT(*) FROM relay_pending')->fetchColumn() !== $beforePending
            || $database->query("SELECT collected_through FROM relay_units WHERE unit='$unit'")->fetchColumn() !== null) {
            throw new LogicException('Partial collection survived rollback: ' . $name);
        }
    }
}

// Failure after saving progress must roll back both intent and the checkpoint.
$checkpointFault = new \Latra\Application\Collection\PersistRelayWindow(
    new \Latra\Infrastructure\Persistence\PdoRelayCollectionRepository($database), 10, 4096,
    static function (string $stage): void {
        if ($stage === 'checkpoint') throw new RuntimeException('Synthetic checkpoint failure');
    });
try {
    $checkpointFault->collectWindow('wialon:903:poll', 300, 300, 1, 1, 4096,
        static fn(): array => ['count' => 1, 'messages' => [['t' => 300]]], $map);
    throw new LogicException('Expected checkpoint failure');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Synthetic checkpoint failure' || $database->inTransaction()
        || (int) $database->query('SELECT COUNT(*) FROM relay_identity')->fetchColumn() !== $beforeIdentities
        || (int) $database->query('SELECT COUNT(*) FROM relay_pending')->fetchColumn() !== $beforePending
        || $database->query("SELECT collected_through FROM relay_units WHERE unit='wialon:903:poll'")->fetchColumn() !== null) {
        throw new LogicException('Checkpoint fault left committed intent or progress');
    }
}

// Refusing a nested transaction must leave the caller's transaction intact.
$database->beginTransaction();
try {
    $repository->collectWindow('wialon:902:poll', 300, 300, 1, 1, 4096,
        static fn(): array => ['count' => 0, 'messages' => []], $map);
    throw new LogicException('Expected transaction ownership rejection');
} catch (RuntimeException $error) {
    if (!$database->inTransaction() || $error->getMessage() !== 'Relay collection must own its transaction') {
        throw new LogicException('Collection rolled back a caller-owned transaction');
    }
} finally {
    $database->rollBack();
}

echo "PASS collection transaction, replay, cross-stream dedup, chronological state, review evidence and capacity rollback\n";
