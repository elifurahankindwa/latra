<?php
/**
 * Execute the real legacy fetch dispatcher against an out-of-order batch and assert that the
 * persisted vehicle state comes from the newest source message, not the first array element.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = sys_get_temp_dir() . '/latra-api-legacy-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
mkdir($root . '/includes', 0700);
copy(__DIR__ . '/../api.php', $root . '/api.php');
$sourcePath = realpath(__DIR__ . '/../src');

$autoload = str_replace('__SOURCE__', var_export($sourcePath, true), <<<'PHP'
<?php
spl_autoload_register(static function (string $class): void {
    $prefix = 'Latra\\';
    if (str_starts_with($class, $prefix)) {
        $file = __SOURCE__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
    if (str_contains($class, '\\')) {
        return;
    }
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
PHP);

$stubs = [
    'Database' => <<<'PHP'
<?php
class Database {
    public static function fetch(string $sql, array $params = []): ?array {
        if (str_contains($sql, 'vehicle_state')) {
            return ['vehicle_id' => 1, 'engine_status' => 'off', 'overspending_active' => 0];
        }
        if (str_contains($sql, 'vehicles')) {
            return ['id' => 1, 'vehicle_reg_no' => 'SYNTHETIC', 'imei' => '123456789012345',
                'wialon_unit_id' => 123, 'is_active' => 1, 'engine_param' => 'ign'];
        }
        return null;
    }
    public static function insert(...$args): never { throw new RuntimeException('Unexpected insert'); }
    public static function update(...$args): never { throw new RuntimeException('Unexpected update'); }
    public static function query(...$args): never { throw new RuntimeException('Unexpected query'); }
}
PHP,
    'IngestPipeline' => <<<'PHP'
<?php
class IngestPipeline {
    public static function importVehicle(array $vehicle, array $messages): array {
        $repository = new class implements \Latra\Application\Ingestion\IngestionRepository {
            public array $messages = [];
            public function loadVehicleState(int $id): array { return ['engine_status'=>'off']; }
            public function enabledActivities(int $id): array { return []; }
            public function speedLimit(array $v): float { return 80.0; }
            public function resolveParameters(array $p, ?string $type): array { return $p; }
            public function overspeedConsecutiveSamples(): int { return 2; }
            public function beginMessage(): void {}
            public function storeMessage(array $message): bool { $this->messages[$message['wialon_msg_time']]=$message; return true; }
            public function findMessage(int $v, int $t): ?array { return ['id'=>$t]+$this->messages[$t]; }
            public function recordValidationErrors(array $m, array $e): void {}
            public function updateMessagePayload(array $m, array $p): void {}
            public function activityAllowed(int $id): bool { throw new LogicException('Import checked queue eligibility'); }
            public function enabledBucketVehicleIds(): array { throw new LogicException('Import checked queue eligibility'); }
            public function enqueue(array $m, array $p, ?string $id, int $priority): void { throw new LogicException('Import queued dispatch'); }
            public function commitMessage(): void {}
            public function rollbackMessage(): void {}
            public function persistVehicleState(int $id, array $state): void { EventDetector::persistState($id,$state); }
            public function formattedDateTime(): string { return date(DATE_FORMAT); }
        };
        return (new \Latra\Application\Ingestion\IngestVehicleMessages($repository,
            new \Latra\Infrastructure\Ingestion\LegacyIngestionEffects()))->ingest($vehicle,$messages, false);
    }
}
PHP,
    'WialonAPI' => <<<'PHP'
<?php
class WialonAPI {
    public function login(): bool { return true; }
    public function logout(): void {}
    public function getMessages(int $unit, int $from, int $to, int $limit): array {
        if ($unit !== 123 || $limit !== 100) {
            throw new RuntimeException('Unexpected Wialon request');
        }
        return [
            ['t' => time() - 20, 'pos' => ['s' => 11, 'y' => -6.80, 'x' => 39.20], 'p' => ['ign' => 0]],
            ['t' => time() - 10, 'pos' => ['s' => 57, 'y' => -6.70, 'x' => 39.30], 'p' => ['ign' => 1]],
        ];
    }
}
PHP,
    'LatraMapper' => <<<'PHP'
<?php
class LatraMapper {
    public static function buildAddonInfo(int $activityId, array $params, array $custom = []): ?array { return []; }
    public static function buildFuelInfo(array $params): array { return []; }
}
PHP,
    'Auth' => <<<'PHP'
<?php
class Auth {
    public static function audit(...$args): void {}
    public static function check(): bool { return true; }
    public static function rateLimit(...$args): bool { return true; }
    public static function clientKey(): string { return 'synthetic'; }
    public static function verifyCsrf($value): bool { return $value === 'synthetic-csrf'; }
    public static function requireCsrf(): void {}
    public static function requireWrite(): void {}
}
PHP,
    'Automation' => <<<'PHP'
<?php
class Automation {
    public static function storeMessage(array $message): bool { return true; }
}
PHP,
    'GpsValidator' => <<<'PHP'
<?php
require_once __DIR__ . '/autoload.php';
class GpsValidator {
    public static function validate(array $message): array { return ['valid' => true, 'errors' => []]; }
    public static function statusOf(array $result): string { return 'valid'; }
    public static function recordErrors(array $message, array $errors): int { return 0; }
}
PHP,
    'EventDetector' => <<<'PHP'
<?php
class EventDetector {
    public const ENGINE_OFF = 3;
    public static function enabledActivities(int $vehicleId): array { return []; }
    public static function speedLimit(int $vehicleId): float { return 80.0; }
    public static function detect(array $params, array $state, array $options): array {
        return ['activity_id' => 2, 'reason' => 'movement'];
    }
    public static function engineState(array $params, ?string $engineParam): ?bool {
        if (!array_key_exists('ign', $params)) { return null; }
        return (int) $params['ign'] === 1;
    }
    public static function persistState(int $vehicleId, array $state): void {
        $path = getenv('API_STATE_OUT');
        if ($path === false || $path === '') { throw new RuntimeException('API_STATE_OUT missing'); }
        file_put_contents($path, json_encode(['vehicle_id' => $vehicleId] + $state));
    }
}
PHP,
    'EmergencyHandler' => <<<'PHP'
<?php
class EmergencyHandler {
    public static function mapEmergencyType(int $activityId): ?string { return null; }
    public static function ensureActiveEvent(...$args): ?array { return null; }
    public static function bufferRecord(...$args): bool { return false; }
    public static function closeEndedEvent(...$args): void {}
}
PHP,
    'DeviceMonitor' => <<<'PHP'
<?php
class DeviceMonitor {
    public static function updateFromMessage(...$args): void {}
}
PHP,
    'DataCleaner' => <<<'PHP'
<?php
PHP,
    'RelayRuntime' => <<<'PHP'
<?php
class RelayRuntime {
    public static function enabled(): bool { return false; }
}
PHP,
];

foreach ($stubs + ['autoload' => $autoload] as $name => $code) {
    file_put_contents($root . '/includes/' . $name . '.php', $code);
}

file_put_contents($root . '/config.php', <<<'PHP'
<?php
require_once __DIR__ . '/includes/autoload.php';
define('DELIVERY_ARCHITECTURE', 'legacy');
define('DATE_FORMAT', 'Y-m-d H:i:s');
date_default_timezone_set('UTC');
PHP);

function legacyRequest(string $root, array $environment = []): array {
    $statePath = $root . '/state.json';
    @unlink($statePath);
    $environment = $environment + ['API_STATE_OUT' => $statePath];
    $code = '$_SERVER["REQUEST_METHOD"]="POST";'
        . '$_GET=[];$_POST=' . var_export([
            'action' => 'fetch_messages',
            'vehicle_id' => '1',
            '_csrf' => 'synthetic-csrf',
        ], true) . ';$_REQUEST=array_merge($_GET,$_POST);require "api.php";';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-r', $code],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        array_merge(getenv(), $environment),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot execute API fixture');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('API fixture failed: ' . $error);
    }
    $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;

    return ['response' => json_decode($output, true, 512, JSON_THROW_ON_ERROR), 'state' => $state];
}

try {
    $result = legacyRequest($root);
    if (($result['response']['success'] ?? false) !== true) {
        throw new RuntimeException('Legacy fetch did not succeed: ' . json_encode($result['response']));
    }
    $state = $result['state'];
    if (!is_array($state)) {
        throw new RuntimeException('Legacy fetch did not persist vehicle state');
    }
    if ((int) $state['vehicle_id'] !== 1
        || (float) $state['last_speed_kmh'] !== 57.0
        || $state['engine_status'] !== 'on'
        || (float) $state['last_lat'] !== -6.7
        || (float) $state['last_lng'] !== 39.3) {
        throw new RuntimeException('Persisted state did not come from the newest source message: '
            . json_encode($state));
    }

    $denied = legacyRequest($root, ['API_FIXTURE_DENY_UNUSED' => '1']);
    if (($denied['response']['success'] ?? null) !== true) {
        throw new RuntimeException('Repeated legacy fetch was not idempotent at the fixture level');
    }

    echo "PASS legacy fetch persists the newest source message state regardless of array order\n";
} finally {
    foreach (glob($root . '/includes/*') as $path) {
        unlink($path);
    }
    @unlink($root . '/state.json');
    rmdir($root . '/includes');
    unlink($root . '/config.php');
    unlink($root . '/api.php');
    rmdir($root);
}
