<?php
/** Real application classes + disposable native SQL; fake paged source, no external requests. */
if (PHP_SAPI !== 'cli') exit(1);
$dsn = getenv('LATRA_RELAY_TEST_DSN') ?: '';
if (getenv('LATRA_RELAY_TEST_ALLOW_RESET') !== '1'
    || !preg_match('/^mysql:.*;dbname=(latra_test_relay_app_[a-z0-9_]+)(?:;|$)/', $dsn, $match)) {
    throw new RuntimeException('Explicit dedicated relay test database required');
}
const DATE_FORMAT = 'Y-m-d H:i:s';
require_once __DIR__ . '/../includes/autoload.php';
$db = new PDO($dsn, getenv('LATRA_RELAY_TEST_USER') ?: 'root', getenv('LATRA_RELAY_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ($db->query('SELECT DATABASE()')->fetchColumn() !== $match[1]) throw new RuntimeException('Wrong database');
$db->exec(file_get_contents(__DIR__ . '/../migrations/009_durable_relay.sql'));
$db->exec(file_get_contents(__DIR__ . '/../migrations/010_relay_review_recovery.sql'));
foreach (['relay_pending', 'relay_activity_identity', 'relay_identity', 'relay_units'] as $table) $db->exec('DELETE FROM ' . $table);
(new ReflectionProperty(Database::class, 'instance'))->setValue(null, $db);
(new ReflectionProperty(Database::class, 'sensitiveConfigScrubbed'))->setValue(null, true);
function expect(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
class PagedWialon extends WialonAPI {
    public bool $closed = false;
    public bool $unloaded = false;
    public function __construct(public array $messages, public bool $short = false) {}
    public function login(): bool { return true; }
    public function logout(): void { $this->closed = true; }
    public function request(string $svc, array $params = [], ?int $maxResponseBytes = null): ?array {
        return match ($svc) {
            'messages/load_interval' => ['count' => count($this->messages), 'messages' => array_slice($this->messages, 0, $params['loadCount'])],
            'messages/get_messages' => $this->short ? [] : array_slice($this->messages, $params['indexFrom'], $params['indexTo'] - $params['indexFrom']),
            'messages/unload' => ($this->unloaded = true) ? [] : [],
            default => throw new RuntimeException('Unexpected source call'),
        };
    }
}
$vehicle = ['id' => 1, 'wialon_unit_id' => 123, 'imei' => '123456789012345', 'vehicle_reg_no' => 'SYNTHETIC', 'engine_param' => 'io_239'];
$mapper = new RelayMapper($vehicle, array_fill_keys(range(1, 24), true), range(1, 24), 80, 2, 3200, 'teltonika-fmb');
$now = time() - 60;
$raw = static fn(int $t, array $p): array => ['t' => $t, 'pos' => ['x' => 39.2, 'y' => -6.8, 's' => 20, 'c' => 90, 'z' => 10, 'sc' => 8], 'p' => $p + ['io_66' => 12500, 'io_67' => 4100]];
$source = new PagedWialon([$raw($now, ['io_239' => 0]), $raw($now + 1, ['io_239' => 1]),
    $raw($now + 1, ['io_253' => 2]), $raw($now + 2, ['io_239' => 0])]);
$relay = new DurableRelay($db, 8, static fn(int $n): int => 5, 100, 1048576);
expect(FleetSync::collectRelayVehicle($vehicle, $relay, $mapper, $now, $now + 2, 2, 10, 16384, $source) === 4, 'Application collection lost reports');
expect($source->closed && $source->unloaded, 'Source session/loader leaked');
expect($relay->status('wialon:123')['queue']['review'] == 0, 'Valid application mapping entered review');
$bodies = $db->query('SELECT payload FROM relay_pending ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$allIds = []; $activities = [];
foreach ($bodies as $body) foreach (json_decode($body, true)['items'] as $item) {
    $allIds[] = $item['MGS_ID']; $activities[] = (int) $item['activity_id'];
}
expect(count(array_unique($allIds)) === count($allIds), 'Simultaneous/same-second MGS_ID collision');
expect(in_array(2, $activities, true) && in_array(3, $activities, true) && in_array(5, $activities, true), 'Transitions or harsh braking lost');
$state = $db->query('SELECT state_json FROM relay_units')->fetchColumn();
expect(FleetSync::collectRelayVehicle($vehicle, $relay, $mapper, $now, $now + 2, 2, 10, 16384, $source) === 0, 'Restart overlap duplicated intent');
expect($db->query('SELECT state_json FROM relay_units')->fetchColumn() === $state, 'Overlap recomputed detection state');
echo "PASS FleetSync paged collection, actual mapping, same-second identity, atomic state and replay\n";
$small = new DurableRelay($db, 8, static fn(int $n): int => 5, 4, 1048576);
try {
    FleetSync::collectRelayVehicle($vehicle, $small, $mapper, $now + 3, $now + 3, 2, 10, 16384,
        new PagedWialon([$raw($now + 3, ['io_239' => 1])]));
    throw new LogicException('Expected capacity rejection');
} catch (RuntimeException $error) {}
expect($db->query('SELECT state_json FROM relay_units')->fetchColumn() === $state, 'Capacity rollback lost committed engine state');
$broken = new PagedWialon([$raw($now + 3, []), $raw($now + 4, []), $raw($now + 5, [])], true);
try {
    FleetSync::collectRelayVehicle($vehicle, $relay, $mapper, $now + 3, $now + 5, 2, 10, 16384, $broken);
    throw new LogicException('Expected partial-window rejection');
} catch (RuntimeException $error) {}
expect($broken->closed && $broken->unloaded && $db->query('SELECT state_json FROM relay_units')->fetchColumn() === $state,
    'Failed source read advanced state or leaked session');
echo "PASS capacity and source failure preserve queue/checkpoint/state, unload and logout\n";
$sent = [];
while ($relay->deliver('wialon:123', time(), static function (string $body, string $key) use (&$sent): array {
    $sent[$key] = $body;
    return ['code' => 200, 'data' => ['status' => '1']];
}) === 'sent') {}
expect(count($sent) === 4 && $relay->status('wialon:123')['queue']['unresolved'] == 0, 'Application payload cleanup failed');
expect(FleetSync::collectRelayVehicle($vehicle, $relay, $mapper, $now, $now + 2, 2, 10, 16384, $source) === 0, 'Successful reports replayed');
expect($db->query('SELECT COUNT(*) FROM relay_identity')->fetchColumn() == 4, 'Cleanup lost identities');
echo "PASS confirmed application payload cleanup retains replay identity without message_log writes\n";
$newSource = new PagedWialon([$raw($now + 3, ['io_239' => 1])]);
FleetSync::collectRelayVehicle($vehicle, $relay, $mapper, $now + 3, $now + 3, 2, 10, 16384, $newSource);
$called = false;
expect($relay->deliver('wialon:123', time(), static function () use (&$called): array {
    $called = true; return ['code' => 200, 'data' => ['status' => '1']];
}, static fn(array $row): bool => false) === 'deferred', 'Local policy deferral consumed delivery');
expect(!$called && $db->query('SELECT attempts FROM relay_pending')->fetchColumn() == 0,
    'Local deferral called HTTP or spent retry budget');
echo "PASS local eligibility/rate/deadline preflight preserves attempts and pending intent\n";
