<?php
declare(strict_types=1);
/** Offline SQLite contract. No application bootstrap, live dump, secrets or outbound clients. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/CollectionStore.php';
umask(0077);
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function fails(callable $action): void {
    try { $action(); } catch (Throwable $e) { return; }
    throw new RuntimeException('Expected failure did not occur');
}
function pages(array $messages): Closure {
    return static fn($offset, $size) => ['count' => count($messages), 'messages' => array_slice($messages, $offset, $size)];
}
function capture(CollectionStore $store, array $messages, int $size = 2): array {
    return $store->capture('synthetic-wialon', 'unit-1', 100, 200,
        ['mapping_version' => 'raw-only-v1', 'sensors' => []], $size, 20, pages($messages));
}
function replayHash(CollectionStore $store, int $id): string {
    return hash('sha256', json_encode(iterator_to_array($store->replay($id)), JSON_THROW_ON_ERROR));
}

// Terminate during a real transaction; the parent must observe no partial window or raw rows.
if (($argv[1] ?? '') === '--crash-worker') {
    $store = new CollectionStore(new PDO('sqlite:' . $argv[2]));
    $store->capture('synthetic-wialon', 'unit-1', 100, 200, [], 1, 20,
        static function ($offset, $size) use ($argv) {
            if ($offset === 1) {
                file_put_contents($argv[3], 'ready');
                $deadline = microtime(true) + 15;
                while (microtime(true) < $deadline) usleep(10000);
                throw new RuntimeException('Parent did not kill worker');
            }
            return ['count' => 2, 'messages' => [['t' => 100, 'p' => ['battery_low' => 1]]]];
        });
    exit(1);
}

$dir = sys_get_temp_dir() . '/latra-collection-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$path = $dir . '/capture.sqlite';
$pdo = new PDO('sqlite:' . $path);
$store = new CollectionStore($pdo);
try {
    $a = ['t' => 100, 'rt' => 101, 'p' => ['power' => null, 'battery_low' => 1, 'turn' => 1, 'v' => 0.0]];
    $b = ['t' => 100, 'p' => ['voltage' => 12.5]];
    $c = ['t' => 150, 'p' => []];
    $messages = [$a, $a, $b, $c];
    $first = capture($store, $messages);
    check($first['captured'] === 4 && $first['new_messages'] === 4, 'Same-second messages or multiplicity lost');
    $raw = iterator_to_array($store->replay($first['window_id']));
    check(count($raw) === 4 && $raw[0] == $a && $raw[1] == $a && $raw[2] == $b, 'Raw replay changed');
    check($raw[0]['p']['power'] === null && $raw[0]['p']['v'] === 0.0, 'Unknown or zero changed');
    check(!isset($raw[0]['pos']) && $raw[0]['p']['turn'] === 1 && $raw[0]['p']['battery_low'] === 1, 'Signals discarded');
    check(json_decode($store->window($first['window_id'])['context_json'], true)['mapping_version'] === 'raw-only-v1', 'Provenance missing');
    echo "PASS paged capture, same-second distinct/identical reports, missing GPS, nulls, zeroes and simultaneous raw signals\n";

    $same = capture($store, $messages, 3);
    check($same['new_messages'] === 0, 'Page size changed identity');
    $hash = replayHash($store, $first['window_id']);
    check($hash === replayHash($store, $same['window_id']), 'Replay differs across paging');
    // Object key order is irrelevant to deduplication; ordered lists are preserved.
    $reordered = ['p' => ['v' => 0.0, 'turn' => 1, 'battery_low' => 1, 'power' => null], 'rt' => 101, 't' => 100];
    check(capture($store, [$reordered])['new_messages'] === 0, 'Key order changed identity');
    $late = ['t' => 101, 'p' => ['battery' => 4.1]];
    check(capture($store, [$a, $b, $late])['new_messages'] === 1, 'Late reconciliation failed');
    check(replayHash($store, $first['window_id']) === $hash, 'Later capture changed old replay');
    check(replayHash(new CollectionStore(new PDO('sqlite:' . $path)), $first['window_id']) === $hash, 'Reconnect changed replay');
    echo "PASS overlap, late reports, stable identities, immutable window replay and reconnect\n";

    $before = (int) $pdo->query('SELECT COUNT(*) FROM collection_windows')->fetchColumn();
    $beforeMessages = (int) $pdo->query('SELECT COUNT(*) FROM collection_messages')->fetchColumn();
    $badPages = [
        static fn($o, $s) => null,
        static fn($o, $s) => ['count' => 0, 'messages' => [], 'error' => 5],
        static fn($o, $s) => ['count' => 21, 'messages' => []],
        static fn($o, $s) => ['count' => '0', 'messages' => []],
        static fn($o, $s) => ['count' => 1, 'messages' => []],
        static fn($o, $s) => ['count' => 0, 'messages' => [['t' => 100]]],
        static fn($o, $s) => ['count' => 1, 'messages' => [['p' => []]]],
        static fn($o, $s) => ['count' => 1, 'messages' => [['t' => 99]]],
        static function ($o, $s) {
            if ($o) throw new RuntimeException('Synthetic page error');
            return ['count' => 2, 'messages' => [['t' => 199]]];
        },
        static fn($o, $s) => ['count' => $o ? 3 : 2, 'messages' => [['t' => 198]]],
    ];
    foreach ($badPages as $fetch) {
        fails(fn() => $store->capture('synthetic-wialon', 'unit-1', 100, 200, [], 1, 20, $fetch));
        check(!$pdo->inTransaction(), 'Failure leaked transaction');
        check((int) $pdo->query('SELECT COUNT(*) FROM collection_windows')->fetchColumn() === $before, 'Failed window checkpoint persisted');
        check((int) $pdo->query('SELECT COUNT(*) FROM collection_messages')->fetchColumn() === $beforeMessages, 'Partial raw page persisted');
    }
    $empty = capture($store, []);
    check($empty['captured'] === 0 && iterator_to_array($store->replay($empty['window_id'])) === [], 'True empty window failed');
    fails(fn() => $store->window(999999));
    fails(fn() => $store->capture('s', 'u', 100, 99, [], 1, 20, pages([])));
    echo "PASS empty/error distinction, count drift, bounds, invalid timestamps and atomic rollback after page failure\n";

    $foreign = new PDO('sqlite::memory:');
    $foreign->exec('CREATE TABLE outbox (id INTEGER)');
    fails(fn() => new CollectionStore($foreign));
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    check(!in_array('outbox', $tables, true) && !in_array('message_log', $tables, true), 'Delivery tables exist');
    foreach (['Database', 'Outbox', 'Delivery', 'Scheduler', 'WialonAPI', 'LatraAdapter'] as $class) {
        check(!class_exists($class, false), 'Application dependency loaded: ' . $class);
    }
    echo "PASS separate store rejects app database and loads no application/network/delivery classes\n";

    $crashPath = $dir . '/crash.sqlite';
    $ready = $dir . '/ready';
    $worker = proc_open([PHP_BINARY, __FILE__, '--crash-worker', $crashPath, $ready],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . '/worker.log', 'a'], 2 => ['file', $dir . '/worker.log', 'a']], $pipes);
    check(is_resource($worker), 'Worker did not start');
    $deadline = microtime(true) + 10;
    while (!file_exists($ready) && microtime(true) < $deadline && proc_get_status($worker)['running']) usleep(10000);
    $reached = file_exists($ready);
    proc_terminate($worker, 9);
    proc_close($worker);
    check($reached, 'Worker never reached partial-page transaction');
    $afterCrash = new PDO('sqlite:' . $crashPath);
    $restarted = new CollectionStore($afterCrash);
    check((int) $afterCrash->query('SELECT COUNT(*) FROM collection_windows')->fetchColumn() === 0, 'Crash checkpoint persisted');
    check((int) $afterCrash->query('SELECT COUNT(*) FROM collection_messages')->fetchColumn() === 0, 'Crash raw rows persisted');
    check(capture($restarted, $messages)['captured'] === 4, 'Restart failed');
    echo "PASS SIGKILL during partial capture rolls back raw rows/checkpoint and restart succeeds\n";

    $fixture = __DIR__ . '/collection-fixture.json';
    $command = [PHP_BINARY, __DIR__ . '/../bin/collection.php', 'capture-fixture', $dir . '/cli.sqlite', $fixture, '1', '100', '1048576'];
    $run = static function (array $command) use ($dir): array {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . '/cli.out', 'w'],
            2 => ['file', $dir . '/cli.err', 'w']], $pipes);
        check(is_resource($process), 'CLI did not start');
        return [proc_close($process), file_get_contents($dir . '/cli.out'), file_get_contents($dir . '/cli.err')];
    };
    [$exit, $out, $err] = $run($command);
    check($exit === 0 && $err === '', 'CLI fixture capture failed');
    $one = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    [$exit, $out, $err] = $run($command);
    $two = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    check($exit === 0 && $two['new_messages'] === 0 && $two['replayed'] === 2
        && $one['replay_sha256'] === $two['replay_sha256'], 'CLI replay is not repeatable');
    check((fileperms($dir . '/cli.sqlite') & 0077) === 0, 'CLI store is public');
    check(!str_contains($out, 'battery_low') && !str_contains($out, 'synthetic-unit'), 'CLI printed raw values');
    $bad = $command;
    $bad[7] = '1';
    check($run($bad)[0] !== 0, 'Byte bound was ignored');
    $bad = $command;
    $bad[3] = __DIR__ . '/../collection-test-must-not-exist.sqlite';
    check($run($bad)[0] !== 0 && !file_exists($bad[3]), 'CLI allowed a store inside the app checkout');
    $link = $dir . '/link.sqlite';
    symlink($dir . '/cli.sqlite', $link);
    $bad[3] = $link;
    check($run($bad)[0] !== 0, 'CLI followed a store symlink');
    echo "PASS CLI capture/replay, private store, byte bound, no raw output and unsafe-path rejection\n";
} finally {
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir);
}
