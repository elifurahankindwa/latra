<?php
/** Explicit review recovery contract; synthetic SQLite fixture only. */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';
require __DIR__ . '/../includes/DurableRelay.php';

class RecoveryTestPDO extends PDO {
    public function __construct() {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}

function recoveryExpect(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

function recoveryFixture(PDO $db): void {
    $db->exec('CREATE TABLE relay_units (unit TEXT PRIMARY KEY, attempted_time INTEGER NULL)');
    $db->exec('CREATE TABLE relay_identity (
        id INTEGER PRIMARY KEY AUTOINCREMENT, delivery_key TEXT UNIQUE, unit TEXT, source_time INTEGER,
        state TEXT, source_key TEXT NULL)');
    $db->exec('CREATE TABLE relay_pending (
        id INTEGER PRIMARY KEY AUTOINCREMENT, delivery_key TEXT UNIQUE, unit TEXT, source_time INTEGER,
        payload TEXT, status TEXT, attempts INTEGER DEFAULT 0, last_error TEXT NULL)');
    $db->exec('CREATE TABLE relay_activity_identity (
        source_key TEXT, activity_id INTEGER, delivery_key TEXT,
        PRIMARY KEY (source_key, activity_id))');
    $db->exec("INSERT INTO relay_units VALUES ('wialon:123', NULL), ('wialon:123:poll', NULL)");
}

function holdRecoveryRow(PDO $db, int $time, array $raw, array $options = []): array {
    $unit = 'wialon:123:poll';
    $raw['t'] = $time;
    $rawJson = json_encode($raw, JSON_THROW_ON_ERROR);
    $rawHash = hash('sha256', $rawJson);
    $deliveryKey = $options['delivery_key'] ?? hash('sha256', $unit . ':' . $rawHash . ':1');
    $sourceKey = $options['source_key'] ?? null;
    $attempts = $options['attempts'] ?? 0;
    $error = $options['last_error'] ?? 'Mapping or late source requires review';
    $state = $options['state'] ?? 'review';
    $stmt = $db->prepare('INSERT INTO relay_identity (delivery_key, unit, source_time, state, source_key)
        VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$deliveryKey, $unit, $time, $state, $sourceKey]);
    $stmt = $db->prepare('INSERT INTO relay_pending
        (delivery_key, unit, source_time, payload, status, attempts, last_error) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$deliveryKey, $unit, $time, $rawJson, $options['status'] ?? 'review', $attempts, $error]);
    return ['delivery_key' => $deliveryKey, 'source_key' => $sourceKey, 'raw' => $rawJson];
}

$db = new RecoveryTestPDO();
recoveryFixture($db);
$fault = null;
$relay = new DurableRelay($db, 8, static fn(int $attempt): int => 5, 100, 1048576,
    static function (string $stage) use (&$fault): void { if ($fault === $stage) throw new RuntimeException('Injected rollback'); });
$invalidUnitRejected = false;
try {
    $relay->recoverReviews('not-a-wialon-unit', static fn(): array => []);
} catch (InvalidArgumentException $error) {
    $invalidUnitRejected = $error->getMessage() === 'Explicit Wialon vehicle unit required';
}
recoveryExpect($invalidUnitRejected && !$db->inTransaction(),
    'Application recovery boundary must reject invalid unit identities before persistence');
$raw = ['t' => 100, 'p' => ['io_66' => 12.5], 'pos' => ['x' => 39.2, 'y' => -6.8]];
$legacy = holdRecoveryRow($db, 100, $raw);
$bad = holdRecoveryRow($db, 101, ['t' => 101, 'invalid' => true]);
$attempted = holdRecoveryRow($db, 102, $raw + ['unique' => 1], ['attempts' => 1]);
$unclassified = holdRecoveryRow($db, 103, $raw + ['unique' => 2], ['last_error' => 'Attempt budget exhausted']);
$ambiguousRaw = $raw + ['unique' => 3];
$ambiguousRaw['t'] = 104;
$ambiguous = holdRecoveryRow($db, 104, $ambiguousRaw, [
    'delivery_key' => hash('sha256', 'wialon:123:poll:' . hash('sha256', json_encode($ambiguousRaw, JSON_THROW_ON_ERROR)) . ':2'),
]);

$map = static function (array $source, string $unit): array {
    if (!empty($source['invalid'])) throw new RuntimeException('Invalid source remains held');
    return ['payload' => ['items' => [['activity_id' => 1, 'source_time' => $source['t'], 'imei' => '123456789012345']]]];
};
$result = $relay->recoverReviews('wialon:123', $map);
recoveryExpect($result === ['recovered' => 1, 'held' => 1, 'skipped' => 3],
    'Only the valid, uniquely identified, never-attempted generic review should recover: ' . json_encode($result));
$pending = $db->query("SELECT * FROM relay_pending WHERE delivery_key='{$legacy['delivery_key']}'")->fetch(PDO::FETCH_ASSOC);
$sourceKey = hash('sha256', 'wialon:123:' . hash('sha256', $legacy['raw']) . ':1');
$payload = json_decode($pending['payload'], true, 512, JSON_THROW_ON_ERROR);
recoveryExpect($pending['status'] === 'pending' && $pending['last_error'] === null && $payload['items'][0]['MGS_ID'] === '33'
    && $payload['items'][0]['idempotency_key'] === $sourceKey . '-a1', 'Recovered payload lost stable delivery identity');
recoveryExpect($db->query('SELECT source_key FROM relay_identity WHERE delivery_key=' . $db->quote($legacy['delivery_key']))->fetchColumn() === $sourceKey,
    'Legacy source key was not persisted after unambiguous recovery');
recoveryExpect($db->query('SELECT COUNT(*) FROM relay_activity_identity')->fetchColumn() == 1,
    'Recovered activity was not recorded for cross-stream deduplication');
$again = $relay->recoverReviews('wialon:123', $map);
recoveryExpect($again === ['recovered' => 0, 'held' => 1, 'skipped' => 3]
    && $db->query('SELECT COUNT(*) FROM relay_pending')->fetchColumn() == 5,
    'Repeated recovery duplicated or reprocessed durable intent');

$lateRaw = $raw + ['unique' => 4];
$late = holdRecoveryRow($db, 99, $lateRaw);
$db->exec("UPDATE relay_units SET attempted_time=100 WHERE unit='wialon:123:poll'");
$lateResult = $relay->recoverReviews('wialon:123', $map);
recoveryExpect($lateResult === ['recovered' => 0, 'held' => 1, 'skipped' => 4]
    && $db->query('SELECT status FROM relay_pending WHERE delivery_key=' . $db->quote($late['delivery_key']))->fetchColumn() === 'review',
    'Recovery reordered a report before an already attempted source time: ' . json_encode($lateResult));

$retryRaw = $raw + ['unique' => 5];
$retry = holdRecoveryRow($db, 105, $retryRaw, ['source_key' => hash('sha256', 'wialon:123:retry')]);
$before = $db->query('SELECT payload,status,attempts,last_error FROM relay_pending WHERE delivery_key='
    . $db->quote($retry['delivery_key']))->fetch(PDO::FETCH_ASSOC);
$fault = 'recovery-intent';
try {
    $relay->recoverReviews('wialon:123', $map);
    throw new LogicException('Injected recovery failure did not propagate');
} catch (RuntimeException $error) {
    recoveryExpect($error->getMessage() === 'Injected rollback', 'Unexpected recovery failure');
}
$fault = null;
$after = $db->query('SELECT payload,status,attempts,last_error FROM relay_pending WHERE delivery_key='
    . $db->quote($retry['delivery_key']))->fetch(PDO::FETCH_ASSOC);
recoveryExpect($before === $after
    && $db->query('SELECT COUNT(*) FROM relay_activity_identity')->fetchColumn() == 1,
    'Failed recovery partially changed queue state or activity identity');

$attemptedBefore = $db->query('SELECT payload,status,attempts,last_error FROM relay_pending WHERE delivery_key='
    . $db->quote($attempted['delivery_key']))->fetch(PDO::FETCH_ASSOC);
$recovered = $relay->recoverReviews('wialon:123', $map);
$attemptedAfter = $db->query('SELECT payload,status,attempts,last_error FROM relay_pending WHERE delivery_key='
    . $db->quote($attempted['delivery_key']))->fetch(PDO::FETCH_ASSOC);
recoveryExpect($attemptedBefore === $attemptedAfter && $recovered['recovered'] === 1,
    'Attempted payload changed or valid unattempted recovery failed');
echo "PASS explicit review recovery revalidates, deduplicates, rolls back and preserves attempted payloads\n";
