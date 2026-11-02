<?php
/** Run deterministic delivery, cleanup and export regressions without external services. */
require __DIR__ . '/bootstrap.php';
$pdo = testDatabase();
fixture($pdo);
$calls = 0;
$success = static function (array $body, callable $started) use (&$calls): array {
    $started();
    $calls++;
    return ['ok' => true, 'payload' => $body, 'response' => 'accepted', 'responseData' => ['status' => '1'],
        'httpCode' => 200, 'latency' => 1, 'curlError' => '', 'attempted' => true];
};

// Two producers and a later flush must reuse the body and perform only one transport.
[$message, $body, $queueId] = queueMessage(1);
Outbox::enqueue($message, ['items' => [['changed' => true]]]);
expect((int) $pdo->query('SELECT COUNT(*) FROM outbox')->fetchColumn() === 1, 'duplicate enqueue created another row');
$first = Delivery::submit(['items' => [['changed' => true]]], 'test-1', $success);
$second = Delivery::submit($body, 'test-1', $success);
expect($calls === 1 && !empty($second['deduplicated']), 'accepted delivery sent again');
expect($first['payload'] === $body, 'retry body was rebuilt');
expect((int) $pdo->query('SELECT tries FROM outbox')->fetchColumn() === 1, 'wrong real-attempt count');
Automation::recordPush($message, 2, $first, '', 1);
Automation::recordPush($message, 2, $second, '', 1);
expect((int) $pdo->query('SELECT COUNT(*) FROM push_log')->fetchColumn() === 1, 'legacy caller duplicated an atomic receipt');
echo "PASS immutable enqueue and successful-send deduplication\n";

// Candidate selection may inspect many records without claiming or spending any retry count.
fixture($pdo);
for ($i = 1; $i <= 81; $i++) queueMessage($i);
expect(count(Outbox::claimBatch(80)) === 80, 'candidate page size changed');
expect((int) $pdo->query('SELECT SUM(tries) FROM outbox')->fetchColumn() === 0, 'unattempted candidates consumed retries');
expect((int) $pdo->query("SELECT COUNT(*) FROM outbox WHERE status='in_flight'")->fetchColumn() === 0, 'unattempted rows claimed');
Delivery::submit([], 'test-1', $success);
expect((int) $pdo->query('SELECT SUM(tries) FROM outbox')->fetchColumn() === 1, 'one send charged other candidates');
echo "PASS only started transports consume retry budget\n";

// An activity enabled for another vehicle must not authorize this vehicle's queue row.
$pdo->exec('INSERT INTO automation_buckets VALUES (2,3,1)');
$pdo->exec('INSERT INTO bucket_vehicles VALUES (2,2)');
$pdo->exec('UPDATE outbox SET activity_id=3 WHERE id=2');
$ids = array_column(Outbox::claimBatch(200), 'id');
expect(!in_array(2, array_map('intval', $ids), true), 'cross-bucket eligibility leak');
echo "PASS exact vehicle/activity eligibility\n";

// Local throttling leaves work due later without manufacturing a remote attempt.
$before = (int) $pdo->query('SELECT SUM(tries) FROM outbox')->fetchColumn();
$defer = static fn(array $body, callable $started): array => ['ok' => false, 'payload' => $body,
    'response' => 'local throttle', 'responseData' => [], 'httpCode' => 429, 'latency' => 0,
    'curlError' => 'local throttle', 'attempted' => false, 'deferred' => true];
$deferralStarted = time();
Delivery::submit([], 'test-3', $defer);
expect((int) $pdo->query('SELECT SUM(tries) FROM outbox')->fetchColumn() === $before, 'local throttle charged retry');
// Preserve upstream full jitter, which legitimately permits a zero-second delay.
$nextAttempt = strtotime($pdo->query('SELECT next_attempt_at FROM outbox WHERE id=3')->fetchColumn().' UTC');
expect($nextAttempt >= $deferralStarted && $nextAttempt <= time()+30, 'local deferral changed the original backoff range');
expect(Automation::classifyFailure(429, [], '') === 'transient', 'remote 429 treated as permanent');
echo "PASS local deferral and upstream throttling classification\n";

// A poison body is quarantined without making HTTP calls or blocking the next candidate.
$pdo->exec("UPDATE outbox SET payload='{}' WHERE id=4");
$before = $calls;
Delivery::submit([], 'test-4', $success);
expect($calls === $before && $pdo->query('SELECT status FROM outbox WHERE id=4')->fetchColumn() === 'failed', 'poison body not quarantined');
echo "PASS malformed payload quarantine\n";

// A separate connection cannot dispatch a key while another connection owns its lock.
$other = $pdo instanceof LocalTestPDO ? new LocalTestPDO() : testDatabase();
$key = Delivery::lockName('test-5');
$pdo->prepare('SELECT GET_LOCK(?, 0)')->execute([$key]);
useDatabase($other);
$blocked = Delivery::submit($body, 'test-5', $success);
useDatabase($pdo);
$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);
expect(!empty($blocked['deferred']) && $calls === $before, 'concurrent lock owner was bypassed');
expect(strlen($key) <= 64, 'MySQL advisory-lock name exceeds 64 characters');
echo "PASS per-key cross-connection ownership\n";

// Exhausted transport failures stop automatically; an operator retry explicitly resets the budget.
fixture($pdo);
queueMessage(1);
$pdo->exec('UPDATE outbox SET max_tries=1');
$failure = static function (array $body, callable $started): array {
    $started();
    return ['ok' => false, 'payload' => $body, 'response' => 'synthetic timeout', 'responseData' => [],
        'httpCode' => 0, 'latency' => 10000, 'curlError' => 'synthetic timeout', 'attempted' => true];
};
Delivery::submit([], 'test-1', $failure);
expect($pdo->query('SELECT status FROM outbox')->fetchColumn() === 'failed', 'retry budget not enforced');
expect(Outbox::claimBatch(80) === [], 'terminal work automatically selected again');
Outbox::retry(1);
expect(count(Outbox::claimBatch(1)) === 1 && (int) $pdo->query('SELECT tries FROM outbox')->fetchColumn() === 0,
    'explicit operator retry did not restore a usable budget');
echo "PASS per-cycle retry budget and explicit operator retry\n";

// The original automatic 30-minute retry cooldown remains active and does not reset early.
Delivery::submit([], 'test-1', $failure);
Database::query('UPDATE outbox SET updated_at=? WHERE id=1', [gmdate(DATE_FORMAT, time()-1700)]);
expect(Outbox::resetTerminal(1800) === 0, 'automatic retry happened before its configured cooldown');
Database::query('UPDATE outbox SET updated_at=? WHERE id=1', [gmdate(DATE_FORMAT, time()-1900)]);
expect(Outbox::resetTerminal(1800) === 1 && count(Outbox::claimBatch(1)) === 1,
    'original automatic cooldown no longer requeues transient failures');
echo "PASS original automatic retry cooldown\n";

// Transport exceptions release the key lock but preserve an uncertain in-flight attempt for recovery.
$threw = false;
try {
    Delivery::submit([], 'test-1', static function (array $body, callable $started): array {
        $started();
        throw new RuntimeException('simulated process failure');
    });
} catch (RuntimeException $e) { $threw = true; }
expect($threw && $pdo->query('SELECT status FROM outbox')->fetchColumn() === 'in_flight',
    'uncertain attempt was silently discarded');
$stmt = $other->prepare('SELECT GET_LOCK(?, 0)');
$stmt->execute([Delivery::lockName('test-1')]);
expect((int) $stmt->fetchColumn() === 1, 'failed sender leaked the advisory lock');
$other->prepare('SELECT RELEASE_LOCK(?)')->execute([Delivery::lockName('test-1')]);
echo "PASS uncertain attempt preservation and lock release\n";

// Recovery keeps the original 300-second lease and never overrides an active key owner.
fixture($pdo);
queueMessage(1); queueMessage(2); queueMessage(3);
Database::query("UPDATE outbox SET status='in_flight', in_flight_at=? WHERE id IN (1,2)", [gmdate(DATE_FORMAT,time()-400)]);
Database::query("UPDATE outbox SET status='in_flight', in_flight_at=? WHERE id=3", [gmdate(DATE_FORMAT,time()-100)]);
$key = Delivery::lockName('test-1');
$other->prepare('SELECT GET_LOCK(?, 0)')->execute([$key]);
expect(Outbox::reconcileStale(300) === 1, 'recovery changed the lease age or reset a live sender');
expect($pdo->query('SELECT status FROM outbox WHERE id=1')->fetchColumn() === 'in_flight', 'live key owner was overridden');
expect($pdo->query('SELECT status FROM outbox WHERE id=3')->fetchColumn() === 'in_flight', 'unexpired lease was reset');
$other->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);
expect(Outbox::reconcileStale(300) === 1, 'expired unlocked delivery was not recovered');
echo "PASS original stale lease age and active-owner protection\n";

// A receipt failure rolls back the queue outcome instead of recording acceptance without evidence.
fixture($pdo);
queueMessage(1);
$pdo->exec('DROP TABLE push_log');
$failed = false;
try { Delivery::submit([], 'test-1', $success); } catch (PDOException $e) { $failed = true; }
expect($failed && !$pdo->inTransaction(), 'receipt failure did not roll back');
expect($pdo->query('SELECT status FROM outbox')->fetchColumn() === 'in_flight', 'queue accepted without a receipt');
echo "PASS atomic receipt and outcome rollback\n";

// Cleanup preserves pending/failed dependencies, recent history and failed evidence.
fixture($pdo);
$old = '2000-01-01 00:00:00';
for ($i = 1; $i <= 5; $i++) {
    [, , $id] = queueMessage($i);
    Database::query("UPDATE message_log SET latra_status='sent', pushed_at=? WHERE id=?", [$old, $i]);
    Database::query("UPDATE outbox SET status='sent',updated_at=?,created_at=? WHERE id=?", [$old, $old, $id]);
}
$pdo->exec("UPDATE outbox SET status='pending' WHERE id=1");
$pdo->exec("UPDATE outbox SET status='failed' WHERE id=3");
$pdo->exec("INSERT INTO wialon_events VALUES (1,4,'failed','2000-01-01 00:00:00')");
Database::query('UPDATE message_log SET pushed_at=? WHERE id=5', [gmdate(DATE_FORMAT)]);
$pdo->exec("INSERT INTO emergency_queue VALUES (1,'pending','2000-01-01 00:00:00'),(2,'sent','2000-01-01 00:00:00')");
$pdo->exec("INSERT INTO push_log (id,message_log_id,response_status,created_at) VALUES
    (1,1,'1','2000-01-01 00:00:00'),(2,2,'0','2000-01-01 00:00:00'),(3,2,'1','2000-01-01 00:00:00')");
$pdo->exec("INSERT INTO push_log (id,message_log_id,response_status,created_at) VALUES (4,2,'1',CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO automation_runs VALUES (1,'completed','2000-01-01 00:00:00','2000-01-01 00:00:00'),(2,'completed','2000-01-01 00:00:00','2000-01-01 00:00:00')");
$pdo->exec("INSERT INTO automation_run_items VALUES
    (1,1,2,'sent','2000-01-01 00:00:00'),(2,2,1,'pending','2000-01-01 00:00:00')");
for ($i = 0; $i < 8; $i++) Retention::maintain();
$survivors = array_map('intval', $pdo->query('SELECT id FROM message_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
expect($survivors === [1,3,4,5], 'cleanup removed a dependent or recent source');
expect((int) $pdo->query('SELECT COUNT(*) FROM outbox WHERE id=2')->fetchColumn() === 0, 'completed orphan tombstone not pruned');
expect((int) $pdo->query('SELECT COUNT(*) FROM emergency_queue WHERE id=1')->fetchColumn() === 1, 'unresolved emergency lost');
expect((int) $pdo->query('SELECT COUNT(*) FROM push_log WHERE id=1')->fetchColumn() === 1, 'unresolved evidence lost');
expect((int) $pdo->query('SELECT COUNT(*) FROM push_log WHERE id=2')->fetchColumn() === 0, 'resolved failed attempt ignored the original CLI age window');
expect((int) $pdo->query('SELECT COUNT(*) FROM push_log WHERE id=3')->fetchColumn() === 0, 'old successful receipt not pruned');
expect($pdo->query('SELECT message_log_id FROM push_log WHERE id=4')->fetchColumn() === null,
    'retained receipt did not survive source deletion with SET NULL');
expect((int) $pdo->query('SELECT COUNT(*) FROM automation_runs WHERE id=2')->fetchColumn() === 1,
    'parent cascade erased unresolved run items');
expect((int) $pdo->query('SELECT COUNT(*) FROM automation_runs WHERE id=1')->fetchColumn() === 0,
    'completed parent history was not pruned after its bounded child page');
echo "PASS paged cleanup and unresolved dependency preservation\n";

// A missing table must report failure and roll back rather than claiming successful maintenance.
$pdo->exec('DROP TABLE webhook_events');
$failed = false;
try { Retention::maintain(); } catch (PDOException $e) { $failed = true; }
expect($failed && !$pdo->inTransaction(), 'maintenance failure was hidden or transaction leaked');
echo "PASS maintenance failure visibility\n";

// Keyset pages preserve tied timestamps and filtering without buffering the whole history.
fixture($pdo);
$stmt = $pdo->prepare('INSERT INTO push_log (id,vehicle_id,activity_id,response_status,created_at,response_body) VALUES (?,1,2,?,?,?)');
for ($i = 1; $i <= 1250; $i++) $stmt->execute([$i, $i % 2 ? '1' : '0', '2020-01-01 00:00:00', str_repeat('x', 800)]);
for ($i = 1251; $i <= 1850; $i++) $stmt->execute([$i, '1', null, 'synthetic NULL timestamp']);
$ids = [];
foreach (Export::pushLogRows('sent') as $row) $ids[] = (int) $row[0];
expect(count($ids) === 1225 && count(array_unique($ids)) === 1225, 'paged CSV lost or repeated tied rows');
expect($ids[0] === 1249 && $ids[624] === 1 && end($ids) === 1251, 'CSV cursor order changed across pages');
echo "PASS paged export across timestamp ties\n";

// Hour-based purging must keep its own configured window instead of using retention_days.
fixture($pdo);
queueMessage(1);
$old = gmdate(DATE_FORMAT, time()-36*3600);
Database::query("UPDATE message_log SET latra_status='sent',pushed_at=? WHERE id=1", [$old]);
Database::query("UPDATE outbox SET status='sent',updated_at=? WHERE id=1", [$old]);
$pdo->exec("INSERT INTO config VALUES ('delivered_retention_hours','48','test'),('retention_days','90','test')");
expect(Retention::purgeDelivered() === 0, 'hour-based purge ignored the chosen 48-hour window');
$pdo->exec("UPDATE config SET config_value='24' WHERE config_key='delivered_retention_hours'");
expect(Retention::purgeDelivered() === 2, 'original 24-hour purge did not remove completed source/queue');
echo "PASS original hour-based purge setting\n";

// The CLI must continue honoring MAINTAIN_STREAM_DAYS independently of the scheduler.
fixture($pdo);
queueMessage(1);
$old = gmdate(DATE_FORMAT, time()-16*86400);
Database::query("UPDATE message_log SET latra_status='sent',pushed_at=? WHERE id=1", [$old]);
Database::query("UPDATE outbox SET status='sent',created_at=? WHERE id=1", [$old]);
$previous = getenv('MAINTAIN_STREAM_DAYS');
try {
    putenv('MAINTAIN_STREAM_DAYS=20');
    expect(Retention::maintain() === 0, 'CLI ignored the chosen 20-day window');
    putenv('MAINTAIN_STREAM_DAYS=14');
    expect(Retention::maintain() === 2, 'original CLI 14-day retention changed');
} finally { putenv($previous === false ? 'MAINTAIN_STREAM_DAYS' : 'MAINTAIN_STREAM_DAYS='.$previous); }
echo "PASS original CLI retention environment setting\n";

// Daily archival keeps its 90-day setting and copies rows before deleting them.
fixture($pdo);
queueMessage(1); queueMessage(2); queueMessage(3);
$old = gmdate(DATE_FORMAT, time()-100*86400);
$recent = gmdate(DATE_FORMAT, time()-80*86400);
Database::query("UPDATE message_log SET latra_status='sent',created_at=?,pushed_at=? WHERE id=1", [$old,$old]);
Database::query("UPDATE message_log SET latra_status='sent',created_at=?,pushed_at=? WHERE id=2", [$recent,$recent]);
Database::query('UPDATE message_log SET created_at=? WHERE id=3', [$old]);
$pdo->exec("UPDATE outbox SET status='sent' WHERE id IN (1,2)");
$before = $pdo->query('SELECT * FROM message_log WHERE id=1')->fetch(PDO::FETCH_ASSOC);
expect(Retention::archiveMessages(90) === 1, 'archive did not honor the original 90-day cutoff');
expect($pdo->query('SELECT * FROM message_log_archive WHERE id=1')->fetch(PDO::FETCH_ASSOC) === $before,
    'archive did not preserve the complete source row');
expect((int) $pdo->query('SELECT COUNT(*) FROM message_log WHERE id IN (2,3)')->fetchColumn() === 2,
    'archive removed recent or unresolved source data');
echo "PASS original daily archive window and copy-before-delete\n";

// Preserve the authors' per-process cap and its configured numeric value.
require_once __DIR__ . '/../includes/LatraAdapter.php';
fixture($pdo);
$pdo->exec("INSERT INTO config VALUES ('latra_rate_per_minute','2','test')");
$allow = new ReflectionMethod(LatraAdapter::class, 'allow');
expect($allow->invoke(null) && $allow->invoke(null) && !$allow->invoke(null), 'configured process-local cap was exceeded');
expect((int) $pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn() === 0,
    'process-local cap was silently converted to shared DB state');
echo "PASS original process-local destination rate scope\n";

// Local breaker deferrals must not extend their own cooldown forever.
class Vault {
    public static function latraUrl(): string { return 'http://127.0.0.1:1/'; }
}
queueMessage(1);
$openUntil = time() + 30;
(new ReflectionProperty(LatraAdapter::class, 'consecutiveFailures'))->setValue(null, 5);
(new ReflectionProperty(LatraAdapter::class, 'openUntil'))->setValue(null, $openUntil);
$result = LatraAdapter::submit([], 'test-1');
expect(!empty($result['deferred']) && empty($result['attempted']), 'open breaker attempted transport');
expect((new ReflectionProperty(LatraAdapter::class, 'openUntil'))->getValue() === $openUntil,
    'local deferral extended breaker indefinitely');
expect((int) $pdo->query('SELECT tries FROM outbox')->fetchColumn() === 0, 'open breaker spent a retry');
LatraAdapter::recordPush(['id'=>1,'vehicle_id'=>1], 2, $result);
expect((int) $pdo->query('SELECT COUNT(*) FROM push_log')->fetchColumn() === 0, 'local deferral created a receipt');
echo "PASS breaker recovery deadline and local non-attempt evidence\n";

// The actual flush path leaves the rest of a page untouched when transport is deferred.
require_once __DIR__ . '/../includes/Scheduler.php';
class Logger { public static function info(...$args): void {} }
fixture($pdo);
for ($i=1; $i<=80; $i++) queueMessage($i);
$processed = (new ReflectionMethod(Scheduler::class, 'jobFlush'))->invoke(null, false);
expect($processed === 1 && (int) $pdo->query('SELECT SUM(tries) FROM outbox')->fetchColumn() === 0,
    'flush preclaimed or charged an unattempted page');
expect((int) $pdo->query("SELECT COUNT(*) FROM outbox WHERE status='pending'")->fetchColumn() === 80,
    'flush discarded unattempted work');
echo "PASS real flush deferral preserves the entire pending page\n";

echo $pdo instanceof LocalTestPDO
    ? "Local SQLite SQL/state checks passed; MySQL row locks and plans remain unverified.\n"
    : "Disposable MySQL SQL/state/connection-lock checks passed; production load remains unverified.\n";
