<?php
/** All ingestion reads and outbox writes stay on the captured transaction PDO. */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/autoload.php';
use Latra\Infrastructure\Persistence\LegacyIngestionRepository;

$pdo = new LocalTestPDO();
$other = new LocalTestPDO();
foreach ([$pdo, $other] as $db) {
    $db->exec('CREATE TABLE automation_buckets (id INTEGER, activity_id INTEGER, is_enabled INTEGER)');
    $db->exec('CREATE TABLE bucket_vehicles (bucket_id INTEGER, vehicle_id INTEGER)');
    $db->exec('CREATE TABLE overspeed_config (vehicle_id INTEGER, speed_limit_kmh NUMERIC, is_enabled INTEGER)');
    $db->exec('CREATE TABLE activity_mappings (vehicle_id INTEGER, activity_id INTEGER, is_enabled INTEGER)');
    $db->exec('CREATE TABLE param_aliases (device_type TEXT, param_key TEXT, canonical_key TEXT, is_enabled INTEGER)');
    $db->exec('CREATE TABLE config (config_key TEXT PRIMARY KEY, config_value TEXT)');
    $db->exec('CREATE TABLE message_log (id INTEGER PRIMARY KEY)');
    $db->exec('CREATE TABLE outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, message_id INTEGER, vehicle_id INTEGER,
        activity_id INTEGER, event_id TEXT, idempotency_key TEXT UNIQUE, payload TEXT, status TEXT,
        next_attempt_at TEXT, priority INTEGER, max_tries INTEGER)');
}
$pdo->exec("INSERT INTO automation_buckets VALUES (1,2,1),(2,3,0)");
$pdo->exec('INSERT INTO bucket_vehicles VALUES (1,7),(2,8)');
$pdo->exec('INSERT INTO overspeed_config VALUES (7,65,1),(NULL,75,1)');
$pdo->exec('INSERT INTO activity_mappings VALUES (7,2,1)');
$pdo->exec("INSERT INTO param_aliases VALUES ('*','ignition','ign',1)");
$pdo->exec("INSERT INTO config VALUES ('outbox_max_tries','5')");
$pdo->exec('INSERT INTO message_log VALUES (11)');
useDatabase($pdo);
$repo = new LegacyIngestionRepository();
$repo->beginMessage();
useDatabase($other); // Ambient connection replacement must not split an existing atomic write.
if (!$repo->activityAllowed(2) || $repo->activityAllowed(3) || $repo->enabledBucketVehicleIds() !== [7]) {
    throw new RuntimeException('Eligibility escaped the captured PDO');
}
if ($repo->speedLimit(['id'=>7]) !== 65.0 || $repo->speedLimit(['id'=>9]) !== 75.0
    || $repo->resolveParameters(['ignition'=>1], null)['ign'] !== 1
    || $repo->enabledActivities(7) !== [2=>true]) {
    throw new RuntimeException('Ingestion policies read a different connection');
}
$message = ['id'=>11,'vehicle_id'=>7,'activity_id'=>2];
$repo->enqueue($message, ['items'=>[['value'=>1]]], 'event-1', 10);
$repo->enqueue($message, ['items'=>[['value'=>99]]], 'event-1', 0);
$row = $pdo->query('SELECT * FROM outbox')->fetch(PDO::FETCH_ASSOC);
if (!$row || $row['idempotency_key'] !== 'msg-11-2-event-1' || (int)$row['priority'] !== 10
    || (int)$row['max_tries'] !== 5 || json_decode($row['payload'], true)['items'][0]['value'] !== 1
    || (int)$other->query('SELECT COUNT(*) FROM outbox')->fetchColumn() !== 0) {
    throw new RuntimeException('Queue identity, immutable body, priority or transaction connection changed');
}
$repo->rollbackMessage();
if ((int)$pdo->query('SELECT COUNT(*) FROM outbox')->fetchColumn() !== 0) {
    throw new RuntimeException('Queue intent escaped ingestion rollback');
}
useDatabase($pdo);
$id = Outbox::enqueue($message, ['items'=>[]], null, 0);
if ($id < 1 || $pdo->inTransaction()) throw new RuntimeException('Standalone enqueue did not commit');
try {
    Outbox::enqueue(['id'=>999,'vehicle_id'=>7,'activity_id'=>2], [], null);
    throw new LogicException('Missing source was queued');
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) throw new RuntimeException('Failed standalone enqueue left transaction open');
}
echo "PASS captured ingestion PDO, outbox identity, immutable retry body and rollback\n";
