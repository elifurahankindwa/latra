<?php
/** Delivery application and persistence regression checks; SQLite only, no network. */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Application\Delivery\DeliverPendingReport;
use Latra\Infrastructure\Persistence\PdoRelayDeliveryRepository;

trait RelayDeliveryTestPdoBehavior
{
    private static array $locks = [];

    private function registerSqliteFunctions(callable $registerFunction): void
    {
        $owner = spl_object_id($this);
        $registerFunction('GET_LOCK', static function (string $key) use ($owner): int {
            if (isset(self::$locks[$key]) && self::$locks[$key] !== $owner) return 0;
            self::$locks[$key] = $owner;
            return 1;
        });
        $registerFunction('RELEASE_LOCK', static function (string $key) use ($owner): int {
            if ((self::$locks[$key] ?? null) !== $owner) return 0;
            unset(self::$locks[$key]);
            return 1;
        });
        $registerFunction('GREATEST', static fn(int ...$values): int => max($values));
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}

if (class_exists('Pdo\\Sqlite')) {
    final class RelayDeliveryTestPdo extends Pdo\Sqlite
    {
        use RelayDeliveryTestPdoBehavior;

        public function __construct(string $dsn = 'sqlite::memory:')
        {
            parent::__construct($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->registerSqliteFunctions(fn(string $name, callable $function) => $this->createFunction($name, $function));
        }
    }
} else {
    final class RelayDeliveryTestPdo extends PDO
    {
        use RelayDeliveryTestPdoBehavior;

        public function __construct(string $dsn = 'sqlite::memory:')
        {
            parent::__construct($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->registerSqliteFunctions(
                fn(string $name, callable $function) => $this->sqliteCreateFunction($name, $function)
            );
        }
    }
}

function deliveryServiceExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function addPendingReport(PDO $database, string $unit, string $key, int $sourceTime, int $nextAttempt = 0, int $attempts = 0): void
{
    $database->prepare('INSERT OR IGNORE INTO relay_units (unit) VALUES (?)')->execute([$unit]);
    $database->prepare('INSERT INTO relay_identity (delivery_key,state) VALUES (?,?)')->execute([$key, 'pending']);
    $database->prepare('INSERT INTO relay_pending
        (delivery_key,unit,source_time,payload,status,attempts,next_attempt,last_error)
        VALUES (?,?,?,?,?,?,?,NULL)')->execute([$key, $unit, $sourceTime, '{"items":[]}', 'pending', $attempts, $nextAttempt]);
}

$databasePath = tempnam(sys_get_temp_dir(), 'latra-relay-delivery-');
if ($databasePath === false) throw new RuntimeException('Could not create SQLite fixture path');
register_shutdown_function(static function () use ($databasePath): void {
    if (is_file($databasePath)) unlink($databasePath);
});
$database = new RelayDeliveryTestPdo('sqlite:' . $databasePath);
$database->exec('CREATE TABLE relay_units (
    unit TEXT PRIMARY KEY, attempted_time INTEGER NULL, delivered_time INTEGER NULL)');
$database->exec('CREATE TABLE relay_identity (delivery_key TEXT PRIMARY KEY, state TEXT NOT NULL)');
$database->exec('CREATE TABLE relay_pending (
    id INTEGER PRIMARY KEY AUTOINCREMENT, delivery_key TEXT UNIQUE, unit TEXT, source_time INTEGER,
    payload TEXT, status TEXT, attempts INTEGER, next_attempt INTEGER, last_error TEXT NULL)');

$backoff = static fn(int $attempt): int => 5;
$repository = new PdoRelayDeliveryRepository($database);
$delivery = new DeliverPendingReport($repository, 2, $backoff);

deliveryServiceExpect($delivery->deliver('empty-unit', 100, static fn() => [] ) === 'empty', 'Empty queue result changed');

addPendingReport($database, 'unit-facade', 'report-facade', 5);
$facade = new DurableRelay($database, 2, $backoff, 10, 1024);
deliveryServiceExpect($facade->deliver('unit-facade', 50,
    static fn(): array => ['code' => 200, 'data' => ['status' => '1'], 'error' => '']) === 'sent',
    'DurableRelay compatibility facade did not delegate to the delivery use case');

addPendingReport($database, 'unit-a', 'report-success', 10);
$sent = [];
$success = $delivery->deliver('unit-a', 100, static function (string $payload, string $key) use ($database, &$sent): array {
    deliveryServiceExpect(!$database->inTransaction(), 'Network sender ran inside a database transaction');
    $sent = [$payload, $key];
    return ['code' => 200, 'data' => ['status' => '1'], 'error' => ''];
});
deliveryServiceExpect($success === 'sent' && $sent[1] === 'report-success', 'Accepted report was not sent with its stable key');
deliveryServiceExpect((int) $database->query('SELECT COUNT(*) FROM relay_pending')->fetchColumn() === 0, 'Accepted payload was not deleted');
deliveryServiceExpect($database->query("SELECT state FROM relay_identity WHERE delivery_key='report-success'")->fetchColumn() === 'delivered',
    'Accepted identity state was not committed');
deliveryServiceExpect((int) $database->query("SELECT delivered_time FROM relay_units WHERE unit='unit-a'")->fetchColumn() === 10,
    'Accepted delivery watermark was not advanced');

addPendingReport($database, 'unit-b', 'report-retry', 20);
$deferred = $delivery->deliver('unit-b', 200, static fn() => throw new LogicException('Deferred report called sender'),
    static fn(array $report): bool => false);
deliveryServiceExpect($deferred === 'deferred'
    && (int) $database->query("SELECT attempts FROM relay_pending WHERE delivery_key='report-retry'")->fetchColumn() === 0,
    'Eligibility deferral consumed an attempt');
$retry = $delivery->deliver('unit-b', 200, static fn(): array => ['code' => 503, 'data' => null, 'error' => '']);
deliveryServiceExpect($retry === 'pending'
    && (int) $database->query("SELECT attempts FROM relay_pending WHERE delivery_key='report-retry'")->fetchColumn() === 1
    && (int) $database->query("SELECT next_attempt FROM relay_pending WHERE delivery_key='report-retry'")->fetchColumn() === 205,
    'Retry state or backoff changed');
deliveryServiceExpect($delivery->deliver('unit-b', 204, static fn() => throw new LogicException('Backoff was ignored')) === 'blocked',
    'Retry backoff was not enforced');
$sameReportSent = false;
deliveryServiceExpect($delivery->deliver('unit-b', 205, static function (string $payload, string $key) use (&$sameReportSent): array {
    $sameReportSent = $key === 'report-retry' && $payload === '{"items":[]}';
    return ['code' => 200, 'data' => ['status' => '1'], 'error' => ''];
}) === 'sent' && $sameReportSent, 'Retry did not reuse the original payload and key');

addPendingReport($database, 'unit-c', 'report-review', 30);
deliveryServiceExpect($delivery->deliver('unit-c', 300, static fn(): array => ['code' => 400, 'data' => null, 'error' => '']) === 'review',
    'Permanent rejection was not retained for review');
deliveryServiceExpect($database->query("SELECT state FROM relay_identity WHERE delivery_key='report-review'")->fetchColumn() === 'review',
    'Review queue and identity states diverged');
deliveryServiceExpect($database->query("SELECT next_attempt FROM relay_pending WHERE delivery_key='report-review'")->fetchColumn() === null,
    'Review row was scheduled for automatic retry');

addPendingReport($database, 'unit-transport', 'report-transport', 32);
deliveryServiceExpect($delivery->deliver('unit-transport', 320,
    static fn() => throw new LogicException('socket closed')) === 'pending',
    'Transport exception was not treated as a retry');
$transportError = (string) $database->query(
    "SELECT last_error FROM relay_pending WHERE delivery_key='report-transport'")->fetchColumn();
deliveryServiceExpect(str_contains($transportError, 'LogicException') && str_contains($transportError, 'socket closed'),
    'Transport cause was not retained in last_error');

addPendingReport($database, 'unit-exhausted', 'report-exhausted', 35, 0, 1);
$oneAttemptOnly = new DeliverPendingReport($repository, 1, $backoff);
deliveryServiceExpect($oneAttemptOnly->deliver('unit-exhausted', 350, static fn() => throw new LogicException('Exhausted report called sender'),
    static fn(array $report): bool => false) === 'deferred', 'Eligibility check no longer precedes retry exhaustion');
deliveryServiceExpect($oneAttemptOnly->deliver('unit-exhausted', 350, static fn() => throw new LogicException('Exhausted report called sender')) === 'review'
    && $database->query("SELECT state FROM relay_identity WHERE delivery_key='report-exhausted'")->fetchColumn() === 'review',
    'Exhausted attempt budget was not moved to review');

addPendingReport($database, 'unit-d', 'report-lock', 40);
$lockOwner = new PdoRelayDeliveryRepository(new RelayDeliveryTestPdo('sqlite:' . $databasePath));
deliveryServiceExpect($lockOwner->acquireUnitLock('unit-d'), 'Could not acquire test delivery lock');
try {
    deliveryServiceExpect($delivery->deliver('unit-d', 400, static fn() => throw new LogicException('Busy queue called sender')) === 'busy',
        'Concurrent delivery owner was not rejected');
} finally {
    $lockOwner->releaseUnitLock('unit-d');
}

addPendingReport($database, 'unit-e', 'report-rollback', 50);
$faultingDelivery = new DeliverPendingReport(
    new PdoRelayDeliveryRepository($database, static function (string $stage): void {
        if ($stage === 'cleanup') throw new RuntimeException('Synthetic cleanup failure');
    }),
    2,
    $backoff,
);
try {
    $faultingDelivery->deliver('unit-e', 500, static fn(): array => ['code' => 200, 'data' => ['status' => '1'], 'error' => '']);
    throw new LogicException('Expected acceptance-cleanup failure');
} catch (RuntimeException $error) {
    deliveryServiceExpect($error->getMessage() === 'Synthetic cleanup failure', 'Unexpected cleanup error');
}
deliveryServiceExpect((int) $database->query("SELECT COUNT(*) FROM relay_pending WHERE delivery_key='report-rollback'")->fetchColumn() === 1
    && $database->query("SELECT state FROM relay_identity WHERE delivery_key='report-rollback'")->fetchColumn() === 'pending'
    && $database->query("SELECT delivered_time FROM relay_units WHERE unit='unit-e'")->fetchColumn() === null,
    'Acceptance cleanup failure did not roll back atomically');

echo "PASS delivery use case, transactional repository, retry policy and cleanup recovery\n";
