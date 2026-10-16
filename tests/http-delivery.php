<?php
/** Exercise the real adapter against an explicitly supplied loopback-only mock server. */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/LatraAdapter.php';

/** Keep every test credential and destination synthetic. */
class Vault {
    public static string $url = '';
    public static function latraUrl(): string { return self::$url; }
    public static function latraUser(): string { return 'synthetic'; }
    public static function latraPass(): string { return 'synthetic'; }
}
$url = getenv('LATRA_TEST_HTTP_URL');
if (!$url || !preg_match('#^http://127\.0\.0\.1:[0-9]+/$#', $url)) {
    throw new RuntimeException('Set LATRA_TEST_HTTP_URL to an explicit http://127.0.0.1:PORT/ mock');
}
$pdo = testDatabase();
fixture($pdo);
Vault::$url = $url;
[$message, $body] = queueMessage(1);
$first = LatraAdapter::submit($body, 'test-1');
$second = LatraAdapter::submit(['items' => [['changed' => true]]], 'test-1');
expect(!empty($first['ok']) && !empty($first['attempted']), 'mock HTTP acceptance failed');
expect(!empty($second['deduplicated']) && empty($second['attempted']), 'accepted HTTP repeated');
expect($first['responseData']['received']['items'][0]['synthetic_id'] === 1, 'wrong body reached destination');
expect($first['responseData']['received']['items'][0]['idempotency_key'] === 'test-1'
    && $first['responseData']['idempotency_key'] === 'test-1', 'idempotency header/body key missing');
LatraAdapter::recordPush($message, 2, $first);
LatraAdapter::recordPush($message, 2, $second);
expect((int) $pdo->query('SELECT COUNT(*) FROM push_log')->fetchColumn() === 1, 'duplicate receipt recorded');

// A real 429 response remains retryable and its stored request body is unchanged.
queueMessage(2);
Vault::$url = $url . '?response=429';
$startedAt = time();
$result = LatraAdapter::submit([], 'test-2');
$row = $pdo->query('SELECT * FROM outbox WHERE id=2')->fetch(PDO::FETCH_ASSOC);
expect(!$result['ok'] && $row['status'] === 'pending' && $row['failure_class'] === 'transient', '429 became terminal');
// A zero-second retry is valid under the original full-jitter formula.
$nextAttempt = strtotime($row['next_attempt_at'].' UTC');
expect((int) $row['tries'] === 1 && $nextAttempt >= $startedAt && $nextAttempt <= time()+30,
    'remote attempt not counted or original jitter changed');

// A local rate cap performs no HTTP request and creates no additional receipt/attempt.
queueMessage(3);
$pdo->exec("INSERT INTO config VALUES ('latra_rate_per_minute','0','test')");
$result = LatraAdapter::submit([], 'test-3');
expect(empty($result['attempted']) && !empty($result['deferred']), 'local limiter attempted transport');
expect((int) $pdo->query('SELECT tries FROM outbox WHERE id=3')->fetchColumn() === 0, 'local limiter spent retry budget');
echo "PASS real mock HTTP acceptance, deduplication, 429 backoff and local rate deferral\n";
