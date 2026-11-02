<?php
/** Native MySQL/MariaDB crash recovery; uses only a dedicated synthetic database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!getenv('LATRA_TEST_DSN')) {
    echo "SKIP ingestion recovery requires a disposable native MySQL or MariaDB database\n";
    exit;
}
if (getenv('LATRA_TEST_ALLOW_RESET') !== '1'
    || !preg_match('/^mysql:.*(?:^|;)dbname=latra_test_ingestion(?:;|$)/', getenv('LATRA_TEST_DSN'))) {
    throw new RuntimeException('Use LATRA_TEST_ALLOW_RESET=1 and the latra_test_ingestion database');
}
const DATE_FORMAT = 'Y-m-d H:i:s';
define('LOGS_DIR', sys_get_temp_dir());
date_default_timezone_set('UTC');
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/../includes/' . $class . '.php';
    if (is_file($file)) require_once $file;
});

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function ingestionDatabase(): PDO {
    $pdo = new PDO(getenv('LATRA_TEST_DSN'), getenv('LATRA_TEST_USER') ?: 'root',
        getenv('LATRA_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET time_zone = '+00:00'");
    expect($pdo->query('SELECT DATABASE()')->fetchColumn() === 'latra_test_ingestion', 'Wrong test database');
    return $pdo;
}
function useIngestionDatabase(PDO $pdo): void {
    (new ReflectionProperty(Database::class, 'instance'))->setValue(null, $pdo);
    (new ReflectionProperty(Database::class, 'sensitiveConfigScrubbed'))->setValue(null, true);
}
function report(int $time, bool $panic = false): array {
    return ['t' => $time, 'rt' => $time,
        'pos' => ['x' => 39.2, 'y' => -6.8, 'z' => 50, 's' => 30, 'c' => 90, 'sc' => 12],
        'p' => ['hdop' => 1, 'pwr_ext' => 12.5, 'pwr_int' => 4.1] + ($panic ? ['event' => 'panic'] : [])];
}

/** Pause or fail after real SQL, without adding fault hooks to application code. */
class IngestionProbeStatement extends PDOStatement {
    private static int $sourceWrites = 0;
    protected function __construct(private string $stage, private string $mode, private string $checkpoint) {}
    public function execute(?array $params = null): bool {
        $result = parent::execute($params);
        $sql = $this->queryString;
        $matched = match ($this->stage) {
            'source' => str_starts_with($sql, 'INSERT INTO `message_log`'),
            'second_source' => str_starts_with($sql, 'INSERT INTO `message_log`') && ++self::$sourceWrites === 2,
            'payload' => str_starts_with($sql, 'UPDATE `message_log` SET `latra_payload`'),
            'outbox' => str_starts_with($sql, 'INSERT INTO outbox'),
            default => false,
        };
        if ($matched) {
            if ($this->mode === 'exception') throw new RuntimeException('synthetic interruption: ' . $this->stage);
            file_put_contents($this->checkpoint, $this->stage, LOCK_EX);
            // The parent kills this process. The deadline also bounds abandoned workers.
            $deadline = microtime(true) + 15;
            while (microtime(true) < $deadline) usleep(10000);
            throw new RuntimeException('Crash-test parent did not terminate worker');
        }
        return $result;
    }
}

// Workers use fresh connections; killing one cannot accidentally retain the parent's PDO socket.
if (($argv[1] ?? '') === '--worker') {
    [$script, $worker, $stage, $mode, $checkpoint, $time, $panic] = $argv;
    $pdo = ingestionDatabase();
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [IngestionProbeStatement::class, [$stage, $mode, $checkpoint]]);
    useIngestionDatabase($pdo);
    $vehicle = Database::fetch('SELECT * FROM vehicles WHERE id = 1');
    try {
        $messages = [report((int) $time, $panic === '1')];
        if ($stage === 'second_source') $messages[] = report((int) $time + 1);
        IngestPipeline::ingestVehicle($vehicle, $messages);
        throw new RuntimeException('Requested interruption was not reached');
    } catch (RuntimeException $e) {
        if ($mode !== 'exception' || $e->getMessage() !== 'synthetic interruption: ' . $stage) throw $e;
        expect(!$pdo->inTransaction(), 'Ingestion leaked its transaction after failure');
        echo "PASS propagated exception and released transaction\n";
        exit;
    }
}

$pdo = ingestionDatabase();
useIngestionDatabase($pdo);
// Import the real schema only into the explicitly authorized database, never its named production DB.
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec('SET default_storage_engine = InnoDB');
$sql = file_get_contents(__DIR__ . '/../database.sql');
$sql = preg_replace('/^(?:CREATE DATABASE[^;]*;|USE\s+latra_wialon;)\s*$/mi', '', $sql);
$pdo->exec($sql);
Schema::ensure();
EventEngine::ensureEventSchema();
expect($pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME IN ('message_log','outbox') AND ENGINE='InnoDB'")->fetchColumn() == 2,
    'Recovery tests require transactional source and outbox tables');

function resetIngestion(PDO $pdo): array {
    $pdo->exec('DELETE FROM outbox');
    $pdo->exec('DELETE FROM webhook_events');
    $pdo->exec('DELETE FROM vehicles');
    $pdo->exec('DELETE FROM automation_buckets');
    $pdo->exec("INSERT INTO vehicles (id,wialon_unit_id,vehicle_reg_no,imei,speed_limit_kmh)
        VALUES (1,1,'SYNTHETIC-RECOVERY','123456789012345',80)");
    $pdo->exec("INSERT INTO automation_buckets (id,bucket_name,activity_id,is_enabled)
        VALUES (1,'Synthetic movement',1,1),(2,'Synthetic panic',8,1)");
    $pdo->exec('INSERT INTO bucket_vehicles (bucket_id,vehicle_id) VALUES (1,1),(2,1)');
    useIngestionDatabase($pdo);
    return Database::fetch('SELECT * FROM vehicles WHERE id = 1');
}
function counts(PDO $pdo): array {
    return [(int) $pdo->query('SELECT COUNT(*) FROM message_log')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM outbox')->fetchColumn()];
}
function interrupt(string $stage, string $mode, int $time, bool $panic): void {
    $checkpoint = tempnam(sys_get_temp_dir(), 'latra-ingest-checkpoint-');
    unlink($checkpoint);
    $log = tempnam(sys_get_temp_dir(), 'latra-ingest-worker-');
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', $stage, $mode, $checkpoint,
        (string) $time, $panic ? '1' : '0'], [0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
    expect(is_resource($process), 'Could not start isolated ingestion worker');
    try {
        if ($mode === 'kill') {
            $deadline = microtime(true) + 10;
            while (!is_file($checkpoint) && microtime(true) < $deadline) {
                $status = proc_get_status($process);
                expect($status['running'], 'Worker exited before checkpoint: ' . file_get_contents($log));
                usleep(10000);
            }
            expect(is_file($checkpoint), 'Worker did not reach SQL checkpoint: ' . $stage);
            expect(proc_terminate($process, 9), 'Could not kill ingestion worker');
        }
        $exit = proc_close($process);
        $process = null;
        expect($mode === 'kill' ? $exit !== 0 : $exit === 0, 'Unexpected worker result: ' . file_get_contents($log));
    } finally {
        if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
        @unlink($checkpoint);
        @unlink($log);
    }
}

$time = time() - 60;
$vehicle = resetIngestion($pdo);
$result = IngestPipeline::ingestVehicle($vehicle, [report($time)]);
expect($result === ['inserted' => 1, 'rejected' => 0, 'skipped' => 0] && counts($pdo) === [1, 1],
    'Healthy ingestion did not persist source and delivery intent');
$body = $pdo->query('SELECT payload FROM outbox')->fetchColumn();
IngestPipeline::ingestVehicle($vehicle, [report($time)]);
expect(counts($pdo) === [1, 1] && $pdo->query('SELECT payload FROM outbox')->fetchColumn() === $body,
    'Replay changed immutable delivery intent or duplicated a report');
echo "PASS normal ingestion, replay and immutable payload\n";

// Reproduce each interruption with a thrown exception and an actual SIGKILL.
foreach ([false, true] as $panic) {
    foreach (['exception', 'kill'] as $mode) {
        foreach (['source', 'payload', 'outbox'] as $stage) {
            $vehicle = resetIngestion($pdo);
            interrupt($stage, $mode, $time, $panic);
            expect(counts($pdo) === [0, 0], "Partial ingestion survived $mode at $stage (panic=" . (int) $panic . ')');
            $result = IngestPipeline::ingestVehicle($vehicle, [report($time, $panic)]);
            expect($result['inserted'] === 1 && counts($pdo) === [1, 1], 'Restart failed to persist complete delivery intent');
            $row = $pdo->query('SELECT * FROM outbox')->fetch();
            expect($row['status'] === 'pending' && (int) $row['tries'] === 0 && (int) $row['max_tries'] === 8,
                'Recovery changed pending status or retry defaults');
            expect((int) $row['priority'] === ($panic ? 10 : 0), 'Recovery changed emergency priority');
            $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            if ($panic) expect($payload['items'][0]['emergency_event_id'] === $row['event_id'], 'Emergency identity changed');
            IngestPipeline::ingestVehicle($vehicle, [report($time, $panic)]);
            expect(counts($pdo) === [1, 1], 'Post-recovery replay duplicated source or intent');
            echo 'PASS ' . ($panic ? 'panic' : 'movement') . " $mode after $stage, restart and replay\n";
        }
    }
}

// A later failed message must not roll back a previously committed message in the batch.
foreach (['exception', 'kill'] as $mode) {
    $vehicle = resetIngestion($pdo);
    interrupt('second_source', $mode, $time, false);
    expect(counts($pdo) === [1, 1], 'Later failure removed complete work or retained a partial source');
    $result = IngestPipeline::ingestVehicle($vehicle, [report($time), report($time + 1)]);
    expect($result === ['inserted' => 1, 'rejected' => 0, 'skipped' => 1] && counts($pdo) === [2, 2],
        'Partial batch replay lost or duplicated a message');
    echo "PASS partial batch $mode preserves complete work and replays the rest\n";
}

// Eligibility and rejection are intentionally unchanged: stored does not always mean queueable.
foreach (['disabled', 'unassigned', 'invalid'] as $case) {
    $vehicle = resetIngestion($pdo);
    $message = report($time);
    if ($case === 'disabled') $pdo->exec('UPDATE automation_buckets SET is_enabled=0');
    if ($case === 'unassigned') $pdo->exec('DELETE FROM bucket_vehicles');
    if ($case === 'invalid') $message['pos']['y'] = 95;
    $result = IngestPipeline::ingestVehicle($vehicle, [$message]);
    expect(counts($pdo) === [1, 0], 'Ineligible report was queued: ' . $case);
    if ($case === 'invalid') {
        expect($result['rejected'] === 1, 'Invalid report was not rejected');
        expect((int) $pdo->query('SELECT COUNT(*) FROM validation_errors')->fetchColumn() > 0, 'Validation evidence missing');
    }
    expect(!$pdo->inTransaction(), 'Nonqueued path leaked transaction');
    echo "PASS preserved $case report behavior\n";
}

// Enqueue must leave an existing transaction under its caller's control.
$vehicle = resetIngestion($pdo);
IngestPipeline::ingestVehicle($vehicle, [report($time)]);
$row = $pdo->query('SELECT * FROM message_log')->fetch();
$pdo->exec('DELETE FROM outbox');
$pdo->beginTransaction();
Outbox::enqueue($row, ['items' => [['synthetic' => true]]]);
expect($pdo->inTransaction(), 'Enqueue committed caller-owned transaction');
$pdo->rollBack();
expect(counts($pdo) === [1, 0], 'Caller rollback did not remove its queue entry');
echo "PASS caller-owned outbox transaction rollback\n";

// Custom retry settings still govern freshly queued messages.
$vehicle = resetIngestion($pdo);
$pdo->exec("UPDATE config SET config_value='3' WHERE config_key='outbox_max_tries'");
IngestPipeline::ingestVehicle($vehicle, [report($time)]);
expect((int) $pdo->query('SELECT max_tries FROM outbox')->fetchColumn() === 3, 'Custom retry setting was replaced');
$pdo->exec("UPDATE config SET config_value='8' WHERE config_key='outbox_max_tries'");
echo "PASS custom retry configuration preserved\n";

// A disconnected transaction must fail instead of silently continuing on a fresh connection.
$pdo->exec("INSERT INTO config (config_key,config_value) VALUES ('synthetic_atomicity','before')");
$lost = ingestionDatabase();
useIngestionDatabase($lost);
$thread = (int) $lost->query('SELECT CONNECTION_ID()')->fetchColumn();
$lost->beginTransaction();
$lost->exec("UPDATE config SET config_value='uncommitted' WHERE config_key='synthetic_atomicity'");
$pdo->exec('KILL ' . $thread);
$failed = false;
try {
    Database::query("UPDATE config SET config_value='wrong-connection' WHERE config_key='synthetic_atomicity'");
} catch (PDOException $e) {
    $failed = true;
}
expect($failed, 'Lost transaction did not propagate the connection failure');
expect((new ReflectionProperty(Database::class, 'instance'))->getValue() === $lost,
    'Database helper replaced an in-transaction connection');
useIngestionDatabase($pdo);
expect($pdo->query("SELECT config_value FROM config WHERE config_key='synthetic_atomicity'")->fetchColumn() === 'before',
    'Disconnected transaction persisted partial work');
echo "PASS lost transaction fails without reconnecting or committing partial work\n";
