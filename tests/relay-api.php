<?php
/** Execute the real API dispatcher in a credential-free fixture; legacy source clients must never run. */
if (PHP_SAPI !== 'cli') exit(1);
$root = sys_get_temp_dir() . '/latra-relay-api-' . bin2hex(random_bytes(6));
mkdir($root, 0700); mkdir($root . '/includes', 0700);
copy(__DIR__ . '/../api.php', $root . '/api.php');
foreach (['Database','WialonAPI','LatraMapper','Auth','Automation','GpsValidator','EventDetector','EmergencyHandler','DeviceMonitor','DataCleaner'] as $class) {
    file_put_contents($root . '/includes/' . $class . '.php', '<?php');
}
$config = <<<'PHP'
<?php
define('DELIVERY_ARCHITECTURE', 'relay');
define('LATRA_API_URL', 'http://127.0.0.1:1/');
class Auth {
    public static function audit(...$args): void {}
    public static function check(): bool { return getenv('API_DENY_AUTH') !== '1'; }
    public static function rateLimit(...$args): bool { return true; }
    public static function clientKey(): string { return 'synthetic'; }
    public static function verifyCsrf($value): bool { return $value === 'synthetic-csrf'; }
    public static function requireCsrf(): void {
        if (!self::verifyCsrf($_POST['_csrf'] ?? '')) { echo json_encode(['success'=>false,'error'=>'Invalid CSRF token']); exit; }
    }
    public static function requireWrite(): void {
        if (getenv('API_DENY_WRITE') === '1') { echo json_encode(['success'=>false,'error'=>'Forbidden']); exit; }
    }
}
class Database {
    public static function fetch(string $sql, array $params=[]): ?array {
        if (str_contains($sql, 'push_log')) {
            $payload = ['vehicle_reg_no'=>'SYNTHETIC','imei'=>'123456789012345',
                'items'=>[['activity_id'=>'2','timestamp'=>(string)(time()*1000)]]];
            return ['id'=>7,'vehicle_id'=>1,'activity_id'=>2,'message_log_id'=>5,
                'payload'=>json_encode($payload)];
        }
        if (str_contains($sql, 'message_log')) {
            return ['id'=>5,'msg_id'=>5,'vehicle_id'=>1,'activity_id'=>2,'vehicle_reg_no'=>'SYNTHETIC',
                'imei'=>'123456789012345','name'=>'Synthetic vehicle','wialon_msg_time'=>time(),
                'addon_info'=>'[]','fuel_info'=>'[]','raw_data'=>'{}'];
        }
        $id = (int) ($params[0] ?? 0);
        return $id === 1 ? ['id'=>1,'vehicle_reg_no'=>'SYNTHETIC','imei'=>'123456789012345',
            'wialon_unit_id'=>123,'is_active'=>1] : null;
    }
    public static function insert(...$args): never { throw new RuntimeException('Legacy delivery receipt write bypassed relay'); }
    public static function update(...$args): never { throw new RuntimeException('Legacy message update bypassed relay'); }
}
class LatraMapper {
    public static function buildPayload(array $vehicle, array $source, int $activityId, array $addonInfo=[], array $fuelInfo=[]): array {
        return ['vehicle_reg_no'=>$vehicle['vehicle_reg_no'],'imei'=>$vehicle['imei'],
            'items'=>[['activity_id'=>(string)$activityId,'timestamp'=>(string)(((int)($source['wialon_msg_time'] ?? time()))*1000)]]];
    }
    public static function validatePayload(array $payload): array { return []; }
    public static function buildAddonInfo(...$args): array { return []; }
    public static function buildFuelInfo(...$args): array { return []; }
    public static function buildTripStats(...$args): array { return []; }
}
class WialonAPI { public function __construct() { throw new RuntimeException('Legacy source bypass'); } }
class EventDetector { public const ENGINE_OFF = 3; }
class RelayRuntime {
    public static function enabled(): bool { return true; }
    public static function queueForVehicle(array $payload, ?int $vehicleId = null): array {
        $vehicle = ['id' => $vehicleId ?? 1, 'vehicle_reg_no' => $payload['vehicle_reg_no'] ?? 'SYNTHETIC', 'imei' => $payload['imei'] ?? '123456789012345'];
        if (($vehicle['id'] ?? null) !== 1 || empty($payload['items'])) {
            throw new RuntimeException('Invalid fixture relay queue call');
        }
        return self::queuePayload($vehicle, $payload);
    }
    public static function queuePayload(array $vehicle, array $payload): array {
        if (($vehicle['id'] ?? null) !== 1 || empty($payload['items'])) {
            throw new RuntimeException('Invalid fixture relay queue call');
        }
        return ['success'=>true,'queued'=>1,'message'=>'Saved to the durable relay.'];
    }
    public static function poll(array $vehicle, int $window, int $limit): array {
        if ($vehicle['id'] !== 1 || $window !== 86400 || $limit !== 100) throw new RuntimeException('Portal source window/work policy changed');
        return ['fetched'=>3,'inserted'=>2,'rejected'=>1,'skipped'=>0,'queued'=>3];
    }
}
PHP;
file_put_contents($root . '/config.php', $config);
function request(string $root, string $action, bool $sse = false, array $environment = [], array $parameters = []): string {
    $csrf = $environment['CSRF'] ?? 'synthetic-csrf';
    $query = array_merge(['action' => $action, 'vehicle_ids' => '1,999', 'activity_id' => '2', '_csrf' => $csrf], $sse ? $parameters : []);
    $post = array_merge(['action' => $action, 'vehicle_id' => '1', 'message_id' => '5', 'activity_id' => '2',
        'id' => '7', 'payload' => json_encode(['vehicle_reg_no'=>'SYNTHETIC','imei'=>'123456789012345',
            'items'=>[['activity_id'=>'2','timestamp'=>(string)(time()*1000)]]]), '_csrf' => $csrf], $sse ? [] : $parameters);
    $code = '$_SERVER["REQUEST_METHOD"]=' . var_export($sse ? 'GET' : 'POST', true) . ';'
        . '$_GET=' . var_export($sse ? $query : [], true) . ';$_POST=' . var_export($post, true) . ';'
        . '$_REQUEST=array_merge($_GET,$_POST);require "api.php";';
    $pipes=[];
    $process=proc_open([PHP_BINARY, '-r', $code], [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root, array_merge(getenv(), $environment));
    if (!is_resource($process)) throw new RuntimeException('Cannot execute API fixture');
    $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $status=proc_close($process);
    if ($status !== 0) throw new RuntimeException('API fixture failed: ' . $error);
    return $out;
}
function expect(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
try {
    $single = json_decode(request($root, 'fetch_messages'), true, 512, JSON_THROW_ON_ERROR);
    expect(($single['success'] ?? false) === true && ($single['fetched'] ?? null) === 3 && ($single['rejected'] ?? null) === 1,
        'Single-vehicle fetch bypassed relay or lost review evidence');
    $bulk = request($root, 'bulk_fetch_messages', true);
    preg_match_all('/^data: (.+)$/m', $bulk, $matches);
    $events = array_map(static fn(string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
    expect(($events[0]['type'] ?? null) === 'init' && ($events[1]['fetched'] ?? null) === 3
        && ($events[1]['rejected'] ?? null) === 1 && ($events[2]['status'] ?? null) === 'fail'
        && end($events)['type'] === 'done' && end($events)['success'] === 1 && end($events)['failed'] === 1,
        'Bulk fetch lost SSE progress, independent failure or relay routing');
    foreach ([['API_DENY_AUTH'=>'1'], ['API_DENY_WRITE'=>'1'], ['CSRF'=>'wrong']] as $deny) {
        $result=json_decode(request($root, 'fetch_messages', false, $deny), true, 512, JSON_THROW_ON_ERROR);
        expect(($result['success'] ?? null) === false && !isset($result['fetched']), 'Protected fetch bypassed authentication, write permission or CSRF');
    }
    $badCsrf=json_decode(request($root, 'bulk_fetch_messages', true, ['CSRF'=>'wrong']), true, 512, JSON_THROW_ON_ERROR);
    expect(($badCsrf['success'] ?? null) === false && !isset($badCsrf['fetched']), 'SSE CSRF boundary was lost');

    foreach (['push_single', 'post_to_latra', 'push_custom_payload', 'retry_push'] as $action) {
        $result = json_decode(request($root, $action), true, 512, JSON_THROW_ON_ERROR);
        expect(($result['success'] ?? false) === true && ($result['queued'] ?? null) === 1
            && str_contains($result['message'] ?? '', 'durable relay'),
            $action . ' did not queue durable relay intent: ' . json_encode($result));
    }
    $bulkPush = request($root, 'bulk_push_activity', true);
    preg_match_all('/^data: (.+)$/m', $bulkPush, $pushMatches);
    $pushEvents = array_map(static fn(string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $pushMatches[1]);
    expect(($pushEvents[0]['type'] ?? null) === 'init'
        && ($pushEvents[1]['latra_status'] ?? null) === 'queued'
        && end($pushEvents)['type'] === 'done'
        && end($pushEvents)['success'] === 1 && end($pushEvents)['failed'] === 1,
        'Bulk activity push did not preserve SSE progress while queuing relay intent');
    echo "PASS API single/bulk submission actions queue through relay; response/SSE and auth/write/CSRF contracts remain intact\n";
} finally {
    foreach (glob($root . '/includes/*') as $path) unlink($path);
    rmdir($root . '/includes'); unlink($root . '/config.php'); unlink($root . '/api.php'); rmdir($root);
}
