<?php
/** Destructive only to a deliberately named, explicitly authorized synthetic database. */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';
require __DIR__ . '/../includes/DurableRelay.php';
function check(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function database(): PDO {
    $dsn = getenv('LATRA_RELAY_TEST_DSN') ?: '';
    check(getenv('LATRA_RELAY_TEST_ALLOW_RESET') === '1'
        && preg_match('/^mysql:.*;dbname=(latra_test_relay_app_[a-z0-9_]+)(?:;|$)/', $dsn, $m) === 1,
        'Require explicit reset and latra_test_relay_app_* database');
    $db = new PDO($dsn, getenv('LATRA_RELAY_TEST_USER') ?: 'root', getenv('LATRA_RELAY_TEST_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]);
    check($db->query('SELECT DATABASE()')->fetchColumn() === $m[1], 'Wrong database');
    return $db;
}
function message(int $time, int $value = 1): array {
    return ['t' => $time, 'p' => ['value' => $value, 'zero' => 0, 'unknown' => null]];
}
function mapping(array $message): array {
    // Synthetic identity mapping, not an approved LATRA activity/eligibility mapping.
    if (isset($message['invalid'])) throw new RuntimeException('synthetic bad mapping');
    return ['payload' => ['items' => [['activity_id' => '1', 'source_time' => $message['t'], 'signals' => $message['p']]]], 'state' => ['last_time' => $message['t']]];
}
function capture(DurableRelay $relay, array $messages, string $unit = 'synthetic-A', int $from = 100, int $to = 110): int {
    return $relay->collect($unit, $from, $to, 2, 20, 16384,
        static fn($offset, $limit) => ['count' => count($messages), 'messages' => array_slice($messages, $offset, $limit)], 'mapping');
}
function transport(string $base, string $mode = 'ok'): Closure {
    check(preg_match('#^http://127\.0\.0\.1:[0-9]+/$#', $base) === 1, 'Only explicit loopback destination allowed');
    return static function (string $body, string $key) use ($base, $mode): array {
        $curl = curl_init($base . '?mode=' . rawurlencode($mode));
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Idempotency-Key: ' . $key],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1, CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false]);
        $response = curl_exec($curl);
        return ['code' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'data' => $response === false ? null : json_decode($response, true), 'error' => curl_error($curl)];
    };
}
function countRows(PDO $db, string $table): int { return (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
function mustFail(callable $action): void {
    try { $action(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Expected failure did not occur');
}

// Workers reconnect independently so SIGKILL proves native transaction/lock recovery.
if (($argv[1] ?? '') === '--worker') {
    [, , $kind, $stage, $marker, $url] = $argv;
    $fault = static function (string $at) use ($stage, $marker): void {
        if ($at !== $stage) return;
        file_put_contents($marker, $at);
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) usleep(10000);
        throw new RuntimeException('Parent did not terminate worker');
    };
    $relay = new DurableRelay(database(), 8, static fn(int $attempt): int => 5, $kind === 'capacity' ? 1 : 100, 1048576, $fault);
    if ($kind === 'capacity') capture($relay, [message(100)], 'wialon:99:first');
    elseif ($kind === 'collect') capture($relay, [message(100), message(101)]);
    else $relay->deliver('synthetic-A', 1000, transport($url));
    throw new RuntimeException('Worker missed requested stage');
}

$db = database();
$relay = new DurableRelay($db, 8, static fn(int $attempt): int => 5, 100, 1048576);
$db->exec(file_get_contents(__DIR__ . '/../migrations/009_durable_relay.sql'));
$db->exec(file_get_contents(__DIR__ . '/../migrations/010_relay_review_recovery.sql'));
$db->exec(file_get_contents(__DIR__ . '/../migrations/009_durable_relay.sql'));
$db->exec(file_get_contents(__DIR__ . '/../migrations/010_relay_review_recovery.sql'));
$db->exec('ALTER TABLE relay_identity DROP COLUMN source_key');
$db->exec(file_get_contents(__DIR__ . '/../migrations/010_relay_review_recovery.sql'));
$db->exec(file_get_contents(__DIR__ . '/../migrations/010_relay_review_recovery.sql'));
check((int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='relay_identity' AND COLUMN_NAME='source_key'")->fetchColumn() === 1,
    'Additive review recovery migration was not repeatable');
$private = sys_get_temp_dir() . '/latra-relay-proto-' . bin2hex(random_bytes(6));
mkdir($private, 0700);
$receiver = new PDO('sqlite:' . $private . '/receiver.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$receiver->exec('CREATE TABLE accepted (delivery_key TEXT PRIMARY KEY, payload TEXT, requests INTEGER)');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
check($socket !== false, 'Cannot allocate loopback port');
$url = 'http://' . stream_socket_get_name($socket, false) . '/';
$listen = substr($url, 7, -1);
fclose($socket);
$env = getenv();
$env['RELAY_RECEIVER_DB'] = $private . '/receiver.sqlite';
$pipes = [];
$server = proc_open([PHP_BINARY, '-S', $listen, __DIR__ . '/relay-mock.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $private . '/http.log', 'w'],
     2 => ['file', $private . '/http.log', 'a']], $pipes, null, $env);
check(is_resource($server), 'Cannot start mock');

function resetFixture(): void {
    global $db, $receiver;
    foreach (['relay_pending', 'relay_activity_identity', 'relay_identity', 'relay_units'] as $table) $db->exec('TRUNCATE TABLE ' . $table);
    $receiver->exec('DELETE FROM accepted');
}
function worker(string $kind, string $stage): array {
    global $private, $url;
    $marker = $private . '/marker-' . bin2hex(random_bytes(4));
    $log = $marker . '.log';
    $pipes = [];
    $p = proc_open([PHP_BINARY, __FILE__, '--worker', $kind, $stage, $marker, $url],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
    check(is_resource($p), 'Cannot start worker');
    $deadline = microtime(true) + 8;
    while (!is_file($marker) && microtime(true) < $deadline) {
        if (!proc_get_status($p)['running']) break;
        usleep(10000);
    }
    if (!is_file($marker)) {
        proc_terminate($p, 9); proc_close($p);
        throw new RuntimeException('Worker did not reach ' . $stage . ': ' . file_get_contents($log));
    }
    return [$p, $marker];
}
function killWorker($worker): void {
    check(proc_terminate($worker, 9), 'Cannot terminate worker');
    proc_close($worker);
}

try {
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $s = @stream_socket_client('tcp://' . $listen, $errno, $error, .1);
        if ($s) { fclose($s); $ready = true; break; }
        usleep(20000);
    }
    check($ready, 'Mock failed to start');
    resetFixture();
    // All pages, source order and exact duplicate multiplicity survive without a raw archive.
    $messages = [message(102), message(100), message(101), message(100, 2), message(100)];
    check(capture($relay, $messages) === 5, 'Paging lost reports');
    check(capture($relay, $messages) === 0, 'Overlap duplicated pending reports');
    check((int) $db->query('SELECT COUNT(*) FROM relay_identity WHERE source_key IS NULL')->fetchColumn() === 0,
        'Collection omitted persistent cross-stream source identities');
    check($db->query('SELECT collected_through FROM relay_units')->fetchColumn() == 110, 'Missing collection checkpoint');
    $times = array_map('intval', $db->query('SELECT source_time FROM relay_pending ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    check($times === [100, 100, 100, 101, 102], 'Unordered queue');
    for ($i = 0; $i < 5; $i++) check($relay->deliver('synthetic-A', 1000, transport($url)) === 'sent', 'Delivery failed');
    check(countRows($db, 'relay_pending') === 0 && countRows($db, 'relay_identity') === 5, 'Payload cleanup/identity retention failed');
    check(capture($relay, $messages) === 0 && countRows($receiver, 'accepted') === 5, 'Replay after cleanup resent accepted data');
    $received = json_decode($receiver->query('SELECT payload FROM accepted LIMIT 1')->fetchColumn(), true);
    check($received['items'][0]['signals']['zero'] === 0 && array_key_exists('unknown', $received['items'][0]['signals'])
        && $received['items'][0]['signals']['unknown'] === null, 'Mapping erased zero/unknown');
    echo "PASS complete paging, ordering, same-second multiplicity, overlap, zero/nulls and payload removal\n";

    // Page failures never advance past missing intent; retry performs the complete window.
    foreach (['error', 'drift', 'short', 'bound', 'bytes'] as $case) {
        resetFixture();
        mustFail(static function () use ($relay, $case): void {
            $relay->collect('synthetic-A', 100, 110, 2, $case === 'bound' ? 2 : 20, $case === 'bytes' ? 1 : 16384,
                static function ($offset, $limit) use ($case): array {
                    if ($offset && $case === 'error') throw new RuntimeException('Synthetic source failure');
                    return ['count' => $offset && $case === 'drift' ? 4 : 3,
                        'messages' => $offset && $case === 'short' ? [] : array_slice([message(100), message(101), message(102)], $offset, $limit)];
                }, 'mapping');
        });
        check(countRows($db, 'relay_pending') === 0 && countRows($db, 'relay_identity') === 0, 'Partial window persisted');
        check($db->query('SELECT collected_through FROM relay_units')->fetchColumn() === null, 'Failed window advanced checkpoint');
    }
    resetFixture();
    check(capture($relay, []) === 0, 'Empty complete window failed');
    mustFail(static fn() => capture($relay, [], 'synthetic-A', 112, 120));
    echo "PASS failed/short/drifting/over-bound pages roll back; empty windows and gap rejection\n";

    // Real HTTP outage, throttle, lost acknowledgement and timeout against a loopback receiver.
    foreach (['503', '429', 'lost', 'slow'] as $mode) {
        resetFixture();
        capture($relay, [message(100)]);
        $before = $db->query('SELECT * FROM relay_pending')->fetch();
        check($relay->deliver('synthetic-A', 1000, transport($url, $mode)) === 'pending', 'Unconfirmed delivery became terminal');
        $after = $db->query('SELECT * FROM relay_pending')->fetch();
        check($after['payload'] === $before['payload'] && $after['delivery_key'] === $before['delivery_key']
            && (int) $after['attempts'] === 1 && (int) $after['next_attempt'] === 1005, 'Retry intent changed');
        check($relay->deliver('synthetic-A', 1004, transport($url)) === 'blocked', 'Backoff ignored');
        // Wait for the single-threaded mock to finish its deliberately timed-out request.
        if ($mode === 'slow') usleep(1200000);
        check($relay->deliver('synthetic-A', 1005, transport($url)) === 'sent', 'Recovery failed');
        check(countRows($db, 'relay_pending') === 0 && countRows($receiver, 'accepted') === 1, 'Lost data or duplicate receiver side effect');
    }
    echo "PASS real HTTP 503/429, accepted-but-invalid acknowledgement and timeout recover with stable body/key\n";

    resetFixture();
    capture($relay, [message(100), message(101)]);
    check($relay->deliver('synthetic-A', 1000, transport($url, '400')) === 'review', 'Permanent rejection not retained');
    check($relay->deliver('synthetic-A', 1005, transport($url)) === 'blocked' && countRows($db, 'relay_pending') === 2, 'Rejected head discarded/overtaken');
    check($db->query('SELECT state FROM relay_identity ORDER BY source_time LIMIT 1')->fetchColumn() === 'review', 'Review metadata disagrees with queue');
    capture($relay, [message(100)], 'synthetic-B');
    check($relay->deliver('synthetic-B', 1000, transport($url)) === 'sent', 'Blocked unit stalled another unit');
    resetFixture();
    capture($relay, [message(100) + ['invalid' => true], message(101)]);
    check($relay->deliver('synthetic-A', 1000, transport($url)) === 'blocked', 'Bad mapping sent or skipped');
    resetFixture();
    capture($relay, [message(100)]);
    $limited = new DurableRelay($db, 1, static fn(int $attempt): int => 5, 100, 1048576);
    check($limited->deliver('synthetic-A', 1000, transport($url, '503')) === 'review', 'Explicit retry ceiling ignored');
    check($limited->deliver('synthetic-A', 1010, transport($url)) === 'blocked', 'Exhausted retry repeated');
    echo "PASS permanent rejection, invalid mapping and retry exhaustion stay visible; per-unit blocking\n";

    resetFixture();
    foreach ([[2, 1048576], [100, 32]] as [$records, $bytes]) {
        $small = new DurableRelay($db, 8, static fn(int $attempt): int => 5, $records, $bytes);
        mustFail(static fn() => capture($small, [message(100), message(101), message(102)]));
        check(countRows($db, 'relay_pending') === 0 && countRows($db, 'relay_identity') === 0,
            'Capacity overflow committed partial data');
        check($db->query('SELECT collected_through FROM relay_units')->fetchColumn() === null,
            'Capacity overflow skipped uncollected data');
    }
    $small = new DurableRelay($db, 8, static fn(int $attempt): int => 5, 2, 1048576);
    capture($small, [message(100), message(101)]);
    mustFail(static fn() => capture($small, [message(111)], 'synthetic-A', 111, 120));
    check(countRows($db, 'relay_pending') === 2 && $db->query('SELECT collected_through FROM relay_units')->fetchColumn() == 110,
        'Full queue changed earlier intent/progress');
    check($small->deliver('synthetic-A', 1000, transport($url)) === 'sent', 'Cannot release queue capacity');
    check(capture($small, [message(111)], 'synthetic-A', 111, 120) === 1, 'Collection did not recover after capacity released');
    echo "PASS explicit per-unit record/byte capacity stops collection without advancing checkpoint\n";

    resetFixture();
    capture($relay, [message(100)]);
    check($relay->deliver('synthetic-A', 1000, static function () { throw new RuntimeException('Synthetic transport crash'); }) === 'pending', 'Transport exception must schedule retry');
    check(countRows($db, 'relay_pending') === 1 && !$db->inTransaction(), 'Transport exception lost intent/leaked transaction');
    check($relay->deliver('synthetic-A', 1001, transport($url)) === 'blocked', 'Transport exception ignored retry delay'
        );
    check($relay->deliver('synthetic-A', 1005, transport($url)) === 'sent', 'Transport exception leaked ownership');
    echo "PASS transport exception preserves intent and releases ownership for retry\n";

    resetFixture();
    capture($relay, array_map('message', range(100, 109)));
    for ($tick = 0; $tick < 2; $tick++) {
        check($relay->deliver('synthetic-A', 1000 + $tick * 5, transport($url, '503')) === 'pending', 'Outage lost head');
        $from = 111 + 2 * $tick;
        capture($relay, [message($from), message($from + 1)], 'synthetic-A', $from, $from + 1);
    }
    check(countRows($db, 'relay_pending') === 14, 'Collection did not continue through outage');
    for ($tick = 0; $tick < 14; $tick++) {
        $from = 115 + 2 * $tick;
        capture($relay, [message($from), message($from + 1)], 'synthetic-A', $from, $from + 1);
        for ($sent = 0; $sent < 3; $sent++) {
            check($relay->deliver('synthetic-A', 1020 + $tick, transport($url)) === 'sent', 'Backlog recovery stopped');
        }
    }
    check(countRows($db, 'relay_pending') === 0 && countRows($receiver, 'accepted') === 42,
        'Service exceeding continuing arrivals did not drain backlog');
    echo "PASS outage backlog 14 drains to zero with two arrivals/three deliveries per synthetic tick (42 accepted)\n";

    resetFixture();
    capture($relay, [message(100)]);
    check($relay->deliver('synthetic-A', 1000, transport($url, 'lost-no-dedup')) === 'pending', 'Lost acknowledgement not retained');
    check($relay->deliver('synthetic-A', 1005, transport($url, 'no-dedup')) === 'sent', 'Unkeyed receiver retry failed');
    check(countRows($receiver, 'accepted') === 2, 'Negative receiver experiment did not expose duplicate effects');
    echo "PASS negative control: receiver ignoring keys applies accepted-but-response-lost delivery twice\n";

    foreach (['intent', 'checkpoint'] as $stage) {
        resetFixture();
        [$p] = worker('collect', $stage);
        killWorker($p);
        check(countRows($db, 'relay_pending') === 0 && countRows($db, 'relay_identity') === 0, 'SIGKILL left partial intent');
        check($db->query('SELECT collected_through FROM relay_units')->fetchColumn() === null, 'SIGKILL advanced collection');
        check(capture($relay, [message(100), message(101)]) === 2, 'Capture restart failed');
    }
    echo "PASS SIGKILL during intent/checkpoint rolls back and recollection recovers\n";

    foreach (['attempt', 'accepted', 'cleanup'] as $stage) {
        resetFixture();
        capture($relay, [message(100)]);
        [$p] = worker('deliver', $stage);
        killWorker($p);
        check(countRows($db, 'relay_pending') === 1, 'Crash lost pending payload');
        check($relay->deliver('synthetic-A', 1001, transport($url)) === 'sent', 'Delivery restart failed');
        check(countRows($db, 'relay_pending') === 0 && countRows($receiver, 'accepted') === 1, 'Crash replay duplicated receiver effect');
    }
    echo "PASS SIGKILL before HTTP/after acceptance/during cleanup; restart retains intent and receiver deduplicates\n";

    resetFixture();
    capture($relay, [message(100)]);
    [$p] = worker('deliver', 'attempt');
    try {
        check($relay->deliver('synthetic-A', 1000, transport($url)) === 'busy', 'Two senders owned one unit');
        check(capture($relay, [message(111)], 'synthetic-A', 111, 120) === 1, 'Delivery blocked collection');
        capture($relay, [message(100)], 'synthetic-B');
        check($relay->deliver('synthetic-B', 1000, transport($url)) === 'sent', 'Delivery blocked independent unit');
    } finally { killWorker($p); }
    check($relay->deliver('synthetic-A', 1001, transport($url)) === 'sent', 'Abandoned owner did not release lock');
    capture($relay, [message(99)], 'synthetic-A', 99, 120);
    $late = $db->query('SELECT status FROM relay_pending WHERE source_time=99')->fetchColumn();
    check($late === 'review', 'Late record silently reordered behind prior attempt');
    echo "PASS native cross-process ownership; collection continues during stalled delivery; late data retained for review\n";
    resetFixture();
    [$p] = worker('capacity', 'intent');
    $originalTimeout = (int) $db->query('SELECT @@innodb_lock_wait_timeout')->fetchColumn();
    try {
        $db->exec('SET innodb_lock_wait_timeout=1');
        $one = new DurableRelay($db, 8, static fn(int $attempt): int => 5, 1, 1048576);
        mustFail(static fn() => capture($one, [message(100)], 'wialon:99:second'));
        check(countRows($db, 'relay_pending') === 0, 'Concurrent stream escaped shared capacity ownership');
    } finally {
        killWorker($p);
        $db->exec('SET innodb_lock_wait_timeout=' . $originalTimeout);
    }
    check(capture($one, [message(100)], 'wialon:99:second') === 1, 'Abandoned collector did not release vehicle capacity');
    mustFail(static fn() => capture($one, [message(101)], 'wialon:99:first'));
    check(countRows($db, 'relay_pending') === 1, 'Shared vehicle capacity exceeded after collector restart');
    echo "PASS native concurrent streams serialize vehicle capacity; killed collection releases ownership\n";
    echo "RESULT application relay invariants passed on synthetic native MariaDB and mock HTTP; no live Wialon/LATRA proof\n";
} finally {
    proc_terminate($server); proc_close($server);
    foreach (glob($private . '/*') as $file) unlink($file);
    rmdir($private);
}
