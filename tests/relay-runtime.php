<?php
/** Stage 3: real application coordinator against a dedicated SQL fixture and paged source. */
if (PHP_SAPI !== 'cli') exit(1);
define('DELIVERY_ARCHITECTURE', 'relay');
const DATE_FORMAT = 'Y-m-d H:i:s';
define('LOGS_DIR', sys_get_temp_dir());
$dsn = getenv('LATRA_RELAY_TEST_DSN') ?: '';
if (getenv('LATRA_RELAY_TEST_ALLOW_RESET') !== '1'
    || !preg_match('/^mysql:.*;dbname=(latra_test_relay_app_[a-z0-9_]+)(?:;|$)/', $dsn, $match)) {
    throw new RuntimeException('Explicit dedicated relay test database required');
}
require_once __DIR__ . '/../includes/autoload.php';
$db = new PDO($dsn, getenv('LATRA_RELAY_TEST_USER') ?: 'root', getenv('LATRA_RELAY_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ($db->query('SELECT DATABASE()')->fetchColumn() !== $match[1]) throw new RuntimeException('Wrong database');
(new ReflectionProperty(Database::class, 'instance'))->setValue(null, $db);
(new ReflectionProperty(Database::class, 'sensitiveConfigScrubbed'))->setValue(null, true);
$db->exec(file_get_contents(__DIR__ . '/../migrations/009_durable_relay.sql'));
$db->exec(file_get_contents(__DIR__ . '/../migrations/010_relay_review_recovery.sql'));
$db->exec(file_get_contents(__DIR__ . '/../migrations/011_relay_review_schedule.sql'));
foreach (['relay_pending', 'relay_activity_identity', 'relay_identity', 'relay_units', 'relay_vehicle_settings'] as $table) $db->exec('DELETE FROM ' . $table);
foreach (['config','activity_mappings','automation_buckets','bucket_vehicles','overspeed_config','vehicles','message_log','outbox'] as $table) {
    $db->exec('DROP TABLE IF EXISTS ' . $table);
}
$db->exec('CREATE TABLE config (config_key VARCHAR(100) PRIMARY KEY, config_value TEXT)');
$db->exec('CREATE TABLE activity_mappings (vehicle_id INT, activity_id INT, is_enabled INT)');
$db->exec('CREATE TABLE automation_buckets (id INT PRIMARY KEY, activity_id INT, is_enabled INT)');
$db->exec('CREATE TABLE bucket_vehicles (bucket_id INT, vehicle_id INT)');
$db->exec('CREATE TABLE overspeed_config (vehicle_id INT, is_enabled INT, speed_limit_kmh INT)');
$db->exec('CREATE TABLE vehicles (id INT PRIMARY KEY, wialon_unit_id BIGINT, imei VARCHAR(20), vehicle_reg_no VARCHAR(40), engine_param VARCHAR(40), is_active INT)');
// Any retained-source or old-outbox access in this suite must leave these sentinels unchanged.
$db->exec('CREATE TABLE message_log (id INT PRIMARY KEY, marker VARCHAR(32))');
$db->exec('CREATE TABLE outbox (id INT PRIMARY KEY, marker VARCHAR(32))');
$db->exec("INSERT INTO message_log VALUES (1,'historical-preserved')");
$db->exec("INSERT INTO outbox VALUES (1,'historical-preserved')");
$db->exec("INSERT INTO vehicles VALUES (1,123,'123456789012345','SYNTHETIC','io_239',1),(2,456,NULL,'UNENROLLED','io_239',1)");
$db->exec("INSERT INTO relay_vehicle_settings VALUES (1,'teltonika-fmb',100,1048576,100,1048576,1)");
foreach ([1,2,3,5,6,7,9,10,11] as $id) $db->exec("INSERT INTO activity_mappings VALUES (1,$id,1)");
$db->exec('INSERT INTO automation_buckets VALUES (1,1,1)');
$db->exec('INSERT INTO bucket_vehicles VALUES (1,1)');
function expect(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function fails(callable $action): void {
    try { $action(); } catch (RuntimeException $error) { return; }
    throw new LogicException('Expected failure');
}
class RuntimeSource extends WialonAPI {
    public int $logins = 0;
    public int $logouts = 0;
    public int $unloads = 0;
    public array $loaded = [];
    public array $windows = [];
    public bool $short = false;
    public bool $failLogin = false;
    public function __construct(public array $messages, private PDO $database) {}
    public function login(): bool { $this->logins++; return !$this->failLogin; }
    public function logout(): void { $this->logouts++; }
    public function request(string $svc, array $params = [], ?int $maxResponseBytes = null): ?array {
        expect(!$this->database->inTransaction(), 'Network read occurred while SQL locks were held');
        if ($svc === 'messages/load_interval') {
            $this->windows[] = [$params['timeFrom'], $params['timeTo']];
            $this->loaded = array_values(array_filter($this->messages, static fn(array $m): bool => $m['t'] >= $params['timeFrom'] && $m['t'] <= $params['timeTo']));
            return ['count' => count($this->loaded), 'messages' => array_slice($this->loaded, 0, $params['loadCount'])];
        }
        if ($svc === 'messages/get_messages') return $this->short ? [] : array_slice($this->loaded, $params['indexFrom'], $params['indexTo'] - $params['indexFrom']);
        if ($svc === 'messages/unload') { $this->unloads++; return []; }
        throw new RuntimeException('Unexpected request');
    }
}
$vehicle = Database::fetch('SELECT * FROM vehicles WHERE id=1');
$now = time() - 120;
$report = static fn(int $t, array $params = []): array => ['t' => $t,
    'pos' => ['x' => 39.2, 'y' => -6.8, 'z' => 10, 's' => 20, 'c' => 90, 'sc' => 8],
    'p' => $params + ['io_66' => 12500, 'io_67' => 4100]];
$source = new RuntimeSource([$report($now), $report($now + 1), $report($now + 2)], $db);
$r = RelayRuntime::collect($vehicle, 180, 100, ':poll', [1], $source, $now, $now + 2);
expect($r['fetched'] === 3 && $r['inserted'] === 3 && $r['rejected'] === 0 && $r['skipped'] === 0, 'Per-window collection counts are wrong');
$repeat = RelayRuntime::collect($vehicle, 180, 100, ':poll', [1], $source, $now, $now + 2);
expect($repeat['fetched'] === 3 && $repeat['inserted'] === 0 && $repeat['skipped'] === 3, 'Replay counts or identity are wrong');
expect($source->unloads === 2 && $source->logouts === 2, 'Source cleanup failed');
$otherPath = RelayRuntime::collect($vehicle, 180, 100, ':activity:1', [1], $source, $now, $now + 2);
expect($otherPath['inserted'] === 0 && $otherPath['skipped'] === 3,
    'Portal/poll and bucket paths queued the same source activity twice');
echo "PASS actual coordinator collects complete pages without SQL-held network calls; replay skips exactly\n";
$badVehicle = $vehicle; $badVehicle['imei'] = null;
$bad = RelayRuntime::collect($badVehicle, 180, 100, ':poll', [1], new RuntimeSource([$report($now + 3)], $db), $now + 3, $now + 3);
$again = RelayRuntime::collect($badVehicle, 180, 100, ':poll', [1], new RuntimeSource([$report($now + 4)], $db), $now + 4, $now + 4);
expect($bad['rejected'] === 1 && $again['rejected'] === 1 && $again['inserted'] === 0, 'Held-review totals leaked into per-run counts');
expect($db->query("SELECT COUNT(*) FROM relay_pending WHERE status='review'")->fetchColumn() == 2, 'Invalid reports were lost');
echo "PASS missing identity is durably held; each call reports only its own new review rows\n";
$recovery = RelayRuntime::recoverReviews($vehicle);
expect($recovery === ['recovered' => 2, 'held' => 0, 'skipped' => 0]
    && $db->query("SELECT COUNT(*) FROM relay_pending WHERE status='pending'")->fetchColumn() == 5,
    'Refreshed identity did not recover the two valid held reports');
$replayRecovered = RelayRuntime::collect($vehicle, 180, 100, ':activity:1', [1],
    new RuntimeSource([$report($now + 3), $report($now + 4)], $db), $now + 3, $now + 4);
expect($replayRecovered['inserted'] === 0 && $replayRecovered['skipped'] === 2,
    'Recovered reports lost cross-stream activity identity');
echo "PASS explicit recovery uses refreshed vehicle identity and preserves cross-stream deduplication\n";
$disabled = new RuntimeSource([$report($now + 5)], $db);
$notEnrolled = RelayRuntime::collect(Database::fetch('SELECT * FROM vehicles WHERE id=2'), 180, 100, ':poll', [1], $disabled, $now, $now + 2);
expect($notEnrolled === ['fetched'=>0,'queued'=>0,'inserted'=>0,'rejected'=>0,'skipped'=>0],
    'Unenrolled vehicle did not return the deliberate non-fatal skip result');
expect($disabled->logins === 0, 'Unenrolled vehicle reached Wialon');
$before = $db->query('SELECT SUM(collected_through) FROM relay_units')->fetchColumn();
$short = new RuntimeSource(array_map($report, range($now + 5, $now + 65)), $db); $short->short = true;
fails(static fn() => RelayRuntime::collect($vehicle, 180, 100, ':poll', [1], $short, $now + 5, $now + 65));
expect($short->logouts === 1 && $short->unloads === 1 && $db->query('SELECT SUM(collected_through) FROM relay_units')->fetchColumn() === $before,
    'Partial source advanced progress or leaked session');
$loginFailure = new RuntimeSource([], $db); $loginFailure->failLogin = true;
fails(static fn() => RelayRuntime::collect($vehicle, 180, 100, ':poll', [1], $loginFailure, $now + 5, $now + 5));
expect($loginFailure->logouts === 1, 'Failed login skipped cleanup');
echo "PASS enrollment gate, failed login and partial-page rollback preserve existing progress\n";
$suppressed = RelayRuntime::collect($vehicle, 180, 100, ':activity:5', [5], new RuntimeSource([$report($now)], $db), $now, $now);
expect($suppressed['fetched'] === 1 && $suppressed['inserted'] === 0 && $suppressed['rejected'] === 0 && $suppressed['skipped'] === 1,
    'Ineligible source was fabricated or held as a mapping failure');
// Existing work caps require subdivision, never truncation plus a watermark at the original end.
$small = new RuntimeSource(array_map($report, range($now, $now + 7)), $db);
$prefix = RelayRuntime::collect($vehicle, 180, 2, ':bounded', [1], $small, $now, $now + 7);
expect($prefix['fetched'] === 2 && end($small->windows) === [$now, $now + 1], 'Work cap did not collect an exact bounded prefix');
$progress = Database::fetch("SELECT collected_through FROM relay_units WHERE unit='wialon:123:bounded'");
expect((int) $progress['collected_through'] === $now + 1, 'Subdivision skipped uncollected history');
$second = RelayRuntime::collect($vehicle, 180, 2, ':bounded', [1], $small, null, $now + 7);
expect($second['fetched'] >= 1 && $second['fetched'] <= 2 && $small->windows[count($small->windows) - 1][0] === $now + 2, 'Restart failed to resume the pending prefix');
expect($db->query('SELECT marker FROM message_log')->fetchColumn() === 'historical-preserved'
    && $db->query('SELECT marker FROM outbox')->fetchColumn() === 'historical-preserved', 'Collection changed legacy history/outbox');
echo "PASS independent stream cursors, intentional suppression, bounded subdivision and restart; old history untouched\n";
$eventSource=new RuntimeSource([],$db);$eventTo=$now+30;
RelayRuntime::collect($vehicle,60,100,':events',[5],$eventSource,null,$eventTo);
expect($eventSource->windows[0]===[$eventTo-60,$eventTo], 'Custom event window inherited the ordinary poll minimum');
echo "PASS custom event source window retains its own 60-second minimum\n";
$count=(int)$db->query('SELECT COUNT(*) FROM relay_pending')->fetchColumn();
$db->exec('UPDATE relay_vehicle_settings SET max_pending_records=' . $count . ' WHERE vehicle_id=1');
fails(static fn() => RelayRuntime::collect($vehicle, 180, 100, ':another-stream', [1], new RuntimeSource([$report($now + 99)], $db), $now + 99, $now + 99));
expect((int)$db->query('SELECT COUNT(*) FROM relay_pending')->fetchColumn()===$count,'Independent streams exceeded the vehicle pending budget');
echo "PASS one vehicle capacity budget covers all collection streams\n";
$db->exec('UPDATE relay_vehicle_settings SET max_pending_records=100 WHERE vehicle_id=1');
$invalid=$report($now+11);$invalid['pos']['y']=null;
$bucketSource=new RuntimeSource([$report($now+10),$invalid],$db);
$bucket=RelayRuntime::bucket(['id'=>1,'activity_id'=>1,'max_run_seconds'=>30,'max_items_per_run'=>100],static fn(array $v):WialonAPI=>$bucketSource);
expect($bucket['fetched']===2 && $bucket['inserted']===1 && ($bucket['rejected']??null)===1 && ($bucket['queued']??null)===2,
    'Scheduled bucket hid source/review counts or bypassed coordinator');
$invalidRecovery = RelayRuntime::recoverReviews($vehicle);
expect($invalidRecovery['recovered'] === 0 && $invalidRecovery['held'] === 1
    && $db->query("SELECT COUNT(*) FROM relay_pending WHERE status='review'")->fetchColumn() == 1,
    'Invalid source evidence was promoted to pending');
echo "PASS actual scheduled-bucket collection reports fetched, valid and held intent accurately\n";
