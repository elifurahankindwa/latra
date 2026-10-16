<?php
/** Use a disposable SQL fixture; never load application config or external credentials. */
// HTTP requests must never initialize or reset a test database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
const DATE_FORMAT = 'Y-m-d H:i:s';
date_default_timezone_set('UTC');

// Prefer the modern SQLite driver while keeping the tests runnable on PHP 8.2.
if (class_exists('Pdo\\Sqlite')) {
    class SqliteTestDriver extends Pdo\Sqlite {}
} else {
    class SqliteTestDriver extends PDO {}
}

/** Translate only MySQL syntax for local SQLite checks; this does not verify InnoDB locking. */
class LocalTestPDO extends SqliteTestDriver {
    private static array $locks = [];
    public function __construct() {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->exec('PRAGMA foreign_keys = ON');
        $owner = spl_object_id($this);
        $function = method_exists($this, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
        $this->$function('NOW', static fn(): string => gmdate('Y-m-d H:i:s'));
        $this->$function('GET_LOCK', static function (string $key) use ($owner): int {
            if (isset(self::$locks[$key]) && self::$locks[$key] !== $owner) return 0;
            self::$locks[$key] = $owner;
            return 1;
        });
        $this->$function('RELEASE_LOCK', static function (string $key) use ($owner): int {
            if ((self::$locks[$key] ?? null) !== $owner) return 0;
            unset(self::$locks[$key]);
            return 1;
        });
    }
    /** Keep fixture SQL executable while leaving production statements MySQL-native. */
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace(' FOR UPDATE', '', $query);
        $query = str_replace('ON DUPLICATE KEY UPDATE id = id',
            'ON CONFLICT(idempotency_key) DO UPDATE SET id = id', $query);
        $query = str_replace('ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)',
            'ON CONFLICT(config_key) DO UPDATE SET config_value = excluded.config_value', $query);
        $query = str_replace('ON DUPLICATE KEY UPDATE cnt = VALUES(cnt)',
            'ON CONFLICT(rate_key, window_start) DO UPDATE SET cnt = excluded.cnt', $query);
        $query = str_replace('NOW() - INTERVAL ? SECOND', "datetime('now', '-' || ? || ' seconds')", $query);
        // SQLite has no UPDATE LIMIT; preserve the original MySQL retry cap through an ID subquery.
        if (preg_match('/^(UPDATE outbox SET.*?)\s+WHERE\s+(.*?)\s+LIMIT\s+(\d+)\s*$/s', trim($query), $match)) {
            $query = $match[1] . ' WHERE id IN (SELECT id FROM outbox WHERE ' . $match[2] . ' LIMIT ' . $match[3] . ')';
        }
        return parent::prepare($query, $options);
    }
}

/** Inject the disposable PDO without triggering connection-time config scrubbing. */
function useDatabase(PDO $pdo): void {
    (new ReflectionProperty(Database::class, 'instance'))->setValue(null, $pdo);
    (new ReflectionProperty(Database::class, 'sensitiveConfigScrubbed'))->setValue(null, true);
}

/** Refuse to reset any database except an explicitly authorized, named test database. */
function testDatabase(): PDO {
    $dsn = getenv('LATRA_TEST_DSN');
    if (!$dsn) return new LocalTestPDO();
    if (getenv('LATRA_TEST_ALLOW_RESET') !== '1' || !preg_match('/dbname=latra_test_[a-z0-9_]+(?:;|$)/', $dsn)) {
        throw new RuntimeException('Use LATRA_TEST_ALLOW_RESET=1 and a latra_test_* database');
    }
    return new PDO($dsn, getenv('LATRA_TEST_USER') ?: 'root', getenv('LATRA_TEST_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]);
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Outbox.php';
require_once __DIR__ . '/../includes/Delivery.php';
require_once __DIR__ . '/../includes/Retention.php';
require_once __DIR__ . '/../includes/Automation.php';
require_once __DIR__ . '/../includes/Export.php';

/** Keep configuration reads real, but avoid unrelated runtime schema initialization. */
class Schema {
    public static function configInt(string $key, int $default = 0): int {
        $row = Database::fetch('SELECT config_value FROM config WHERE config_key = ?', [$key]);
        return $row ? (int) $row['config_value'] : $default;
    }
}

/** Recreate only the tables used by the delivery/retention contract. */
function fixture(PDO $pdo): void {
    useDatabase($pdo);
    $auto = $pdo instanceof LocalTestPDO ? 'INTEGER PRIMARY KEY' : 'BIGINT PRIMARY KEY AUTO_INCREMENT';
    $tables = [
        'rate_limits' => 'rate_key VARCHAR(64), window_start BIGINT, cnt INTEGER, PRIMARY KEY (rate_key, window_start)',
        'config' => 'config_key VARCHAR(100) PRIMARY KEY, config_value TEXT, description TEXT',
        'message_log' => 'id INTEGER PRIMARY KEY, vehicle_id INTEGER, wialon_msg_time BIGINT, latra_status VARCHAR(20), pushed_at DATETIME, created_at DATETIME',
        'message_log_archive' => 'id INTEGER PRIMARY KEY, vehicle_id INTEGER, wialon_msg_time BIGINT, latra_status VARCHAR(20), pushed_at DATETIME, created_at DATETIME',
        'outbox' => "id $auto, message_id INTEGER NOT NULL, vehicle_id INTEGER, activity_id INTEGER,
            event_id VARCHAR(40), idempotency_key VARCHAR(80) UNIQUE, payload TEXT, status VARCHAR(20),
            tries INTEGER DEFAULT 0, max_tries INTEGER DEFAULT 8, next_attempt_at DATETIME, in_flight_at DATETIME,
            last_error TEXT, failure_class VARCHAR(20), priority INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP",
        'wialon_events' => 'id INTEGER PRIMARY KEY, message_log_id INTEGER, status VARCHAR(20), created_at DATETIME',
        'push_log' => "id $auto, message_log_id INTEGER, vehicle_id INTEGER, activity_id INTEGER,
            http_code INTEGER, response_status VARCHAR(10), latency_ms INTEGER, response_body TEXT, created_at DATETIME,
            payload TEXT, claim_id VARCHAR(64), attempt INTEGER,
            FOREIGN KEY (message_log_id) REFERENCES message_log(id) ON DELETE SET NULL",
        'emergency_queue' => 'id INTEGER PRIMARY KEY, latra_status VARCHAR(20), created_at DATETIME',
        'webhook_events' => 'id INTEGER PRIMARY KEY, status VARCHAR(20), created_at DATETIME',
        'automation_runs' => 'id INTEGER PRIMARY KEY, status VARCHAR(20), started_at DATETIME, finished_at DATETIME',
        'automation_run_items' => 'id INTEGER PRIMARY KEY, run_id INTEGER, message_log_id INTEGER, status VARCHAR(20), completed_at DATETIME,
            FOREIGN KEY (run_id) REFERENCES automation_runs(id) ON DELETE CASCADE,
            FOREIGN KEY (message_log_id) REFERENCES message_log(id) ON DELETE SET NULL',
        'audit_log' => 'id INTEGER PRIMARY KEY, created_at DATETIME',
        'ai_chat_log' => 'id INTEGER PRIMARY KEY, conversation_id VARCHAR(64), created_at DATETIME',
        'ai_conversations' => 'id INTEGER PRIMARY KEY, conversation_id VARCHAR(64), updated_at DATETIME',
        'jobs' => 'job_key VARCHAR(60) PRIMARY KEY, interval_seconds INTEGER, next_run_at DATETIME',
        'job_runs' => 'id INTEGER PRIMARY KEY, status VARCHAR(20), started_at DATETIME, finished_at DATETIME',
        'vehicles' => 'id INTEGER PRIMARY KEY, vehicle_reg_no VARCHAR(40)',
        'activity_definitions' => 'id INTEGER PRIMARY KEY, activity_name VARCHAR(40)',
        'automation_buckets' => 'id INTEGER PRIMARY KEY, activity_id INTEGER, is_enabled INTEGER',
        'bucket_vehicles' => 'bucket_id INTEGER, vehicle_id INTEGER',
    ];
    foreach (array_reverse(array_keys($tables)) as $table) $pdo->exec("DROP TABLE IF EXISTS $table");
    foreach ($tables as $table => $columns) $pdo->exec("CREATE TABLE $table ($columns)");
    $pdo->exec("INSERT INTO jobs VALUES ('purge_delivered',3600,NULL)");
    $pdo->exec("INSERT INTO automation_buckets VALUES (1,2,1)");
    $pdo->exec("INSERT INTO bucket_vehicles VALUES (1,1)");
    $pdo->exec("INSERT INTO vehicles VALUES (1,'SYNTHETIC')");
    $pdo->exec("INSERT INTO activity_definitions VALUES (2,'Synthetic')");
}

/** Make fixture assertions fatal even when PHP assertion optimization is enabled. */
function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

/** Queue a synthetic source and return its stable identity. */
function queueMessage(int $id): array {
    Database::query("INSERT INTO message_log (id,latra_status,created_at) VALUES (?,'pending',?)", [$id, gmdate(DATE_FORMAT)]);
    $message = ['id' => $id, 'vehicle_id' => 1, 'activity_id' => 2, 'idempotency_key' => 'test-' . $id];
    $body = ['items' => [['activity_id' => 2, 'synthetic_id' => $id]]];
    $queueId = Outbox::enqueue($message, $body);
    return [$message, $body, $queueId];
}
