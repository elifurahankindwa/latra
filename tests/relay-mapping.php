<?php
/** Source and mapping contracts. Synthetic data only; no runtime credentials or HTTP. */
require_once __DIR__ . '/bootstrap.php';
foreach (['ParamResolver', 'Normaliser', 'LatraMapper', 'EventDetector', 'GpsValidator', 'RelayMapper',
    'WialonAPI', 'RelayMockTransport'] as $class) require_once __DIR__ . '/../includes/' . $class . '.php';
$db = testDatabase(); fixture($db);
function verify(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function rejects(callable $action): void {
    try { $action(); } catch (Throwable $error) { return; }
    throw new RuntimeException('Expected rejection');
}
$vehicle = ['id' => 1, 'wialon_unit_id' => 123, 'imei' => '123456789012345',
    'vehicle_reg_no' => 'SYNTHETIC', 'engine_param' => 'io_239'];
$now = time() - 60;
function source(array $params = [], int $offset = 0): array {
    global $now;
    return ['t' => $now + $offset, 'pos' => ['x' => 39.2, 'y' => -6.8, 's' => 20, 'z' => 10, 'c' => 90, 'sc' => 8], 'p' => $params];
}
function ids(array $mapped): array { return array_map('intval', array_column($mapped['payload']['items'], 'activity_id')); }
function mapper(array $enabled = [], string $profile = 'teltonika-fmb', int $batteryLow = 3200): RelayMapper {
    global $vehicle;
    return new RelayMapper($vehicle, $enabled ?: array_fill_keys(range(1, 24), true), range(1, 24), 80, 2, $batteryLow, $profile);
}
$map = mapper();
foreach ([1 => 7, 2 => 5, 3 => 6] as $code => $id) {
    $result = $map->map(source(['io_253' => $code]), []);
    verify(in_array($id, ids($result), true), 'Harsh driving enum lost');
}
$many = $map->map(source(['io_253' => 2, 'io_252' => 1, 'io_251' => 1, 'io_67' => 3000, 'io_66' => 0]), []);
verify(ids($many) === [1, 5, 9, 10, 11], 'Simultaneous activities lost');
foreach ($many['payload']['items'] as $item) {
    verify($item['addon_info']['ext_power_voltage'] === '0' && $item['addon_info']['int_battery_voltage'] === '3', 'Voltage units/zero lost');
}
verify(ids($map->map(source(['io_67' => 0, 'io_66' => 0, 'io_252' => 0, 'io_251' => 0]), [])) === [1], 'Zero voltage or ended flags fabricated an event');
verify(ids($map->map(source(['io_243' => 9999]), [])) === [1], 'Green driving duration became idle');
verify(ids(mapper([], 'generic')->map(source(['io_253' => 2, 'io_252' => 1, 'io_251' => 1]), [])) === [1], 'Unknown profile used device-specific IO');
verify(!isset($map->map(source(), [])['payload']['items'][0]['addon_info']), 'Unknown voltage fabricated');
verify(!in_array(9, ids(mapper([], 'teltonika-fmb', 2900)->map(source(['io_67' => 3000]), [])), true), 'Custom battery policy ignored');
$fuelPayload = LatraMapper::buildPayload($vehicle,
    ['wialon_msg_time'=>$now,'lat'=>-6.8,'lng'=>39.2], 16, [], ['fuel_level'=>'50']);
verify(!LatraMapper::activityRequiresAddon(16)
    && LatraMapper::validatePayload($fuelPayload) === [],
    'Fuel report incorrectly requires generic addon_info instead of its separate fuel_info');
$fuelPayload['items'][0]['fuel_info'] = [];
verify(in_array('items[0].fuel_info is required for activity 16', LatraMapper::validatePayload($fuelPayload), true),
    'Activity 16 accepted a missing fuel_info block');
$disabled = array_fill_keys(range(1, 24), false); $disabled[1] = true;
verify(ids(mapper($disabled)->map(source(['io_253' => 2, 'io_252' => 1]), [])) === [1], 'Disabled activity queued');
$notAllowed = new RelayMapper($vehicle, array_fill_keys(range(1, 24), true), [1], 80, 2, 3200, 'teltonika-fmb');
verify(ids($notAllowed->map(source(['io_253' => 2]), [])) === [1], 'Bucket eligibility bypassed');
echo "PASS eight signals, simultaneous activities, voltage units/null/zero, profile and eligibility\n";
$cooled = new RelayMapper($vehicle, [1 => false, 5 => true], [5], 80, 2, 3200, 'teltonika-fmb', 300);
$episode = $cooled->map(source(['io_253' => 2]), []);
verify(($cooled->map(source(['io_253' => 2], 1), $episode['state'])['suppressed'] ?? false) === true,
    'Configured momentary-event cooldown was lost');
$expired = $episode['state']; $expired['momentary_at'][5] = time() - 301;
verify(ids($cooled->map(source(['io_253' => 2], 2), $expired)) === [5], 'Expired cooldown did not rearm');
echo "PASS configured event cooldown suppresses repeat intent and rearms\n";

$first = $map->map(source(['io_239' => 0]), []);
verify(ids($first) === [1], 'Unknown initial ignition created a transition');
$on = $map->map(source(['io_239' => 1], 1), $first['state']);
verify(in_array(2, ids($on), true), 'Engine on lost');
$off = $map->map(source(['io_239' => 0], 2), $on['state']);
verify(in_array(3, ids($off), true), 'Engine off/trip lost');
$over = source(['pwr_ext' => 12.5]); $over['pos']['s'] = 90;
$one = $map->map($over, []); $two = $map->map($over, $one['state']);
verify(!in_array(4, ids($one), true) && in_array(4, ids($two), true), 'Overspeed consecutive policy changed');
$withoutAddon = source(); $withoutAddon['pos']['s'] = 90;
rejects(static fn() => $map->map($withoutAddon, $one['state']));
// Existing explicit driver/passenger identities must not collapse to the driver's generic keyword.
verify(in_array(13, ids($map->map(source(['event' => 'passenger_panic']), [])), true), 'Passenger panic misclassified');
rejects(static fn() => $map->map(source(['io_66' => 'broken']), []));
$bad = source(); $bad['pos']['y'] = 'broken'; rejects(static fn() => $map->map($bad, []));
$missing = $vehicle; $missing['imei'] = null;
rejects(static fn() => (new RelayMapper($missing, [1 => true], [1], 80, 2, 3200, 'generic'))->map(source(), []));
echo "PASS ignition/trip state, consecutive overspeed, panic identity and invalid-source rejection\n";

class WindowSource extends WialonAPI {
    public array $calls = [];
    public function __construct(public array $loaded, public array $pages = []) {}
    public function request(string $svc, array $params = [], ?int $maxResponseBytes = null): ?array {
        $this->calls[] = [$svc, $params];
        return match ($svc) {
            'messages/load_interval' => $this->loaded,
            'messages/get_messages' => array_shift($this->pages),
            'messages/unload' => [],
            default => throw new RuntimeException('Unexpected request'),
        };
    }
}
$window = new WindowSource(['count' => 3, 'messages' => [source(), source([], 1)]], [[source([], 2)]]);
verify(count($window->getCompleteWindow(123, $now, $now + 3, 2, 10, 16384)) === 3, 'Complete window lost a page');
verify($window->calls[1][1] === ['indexFrom' => 2, 'indexTo' => 3], 'Exclusive loader page boundary lost the final record');
foreach ([new WindowSource(['count' => 3, 'messages' => [source(), source([], 1)]], [[]]),
    new WindowSource(['count' => 11, 'messages' => []]),
    new WindowSource(['count' => 0, 'messages' => [source()]]),
    new WindowSource(['count' => 1, 'messages' => [source([], 10)]])] as $broken) {
    rejects(static fn() => $broken->getCompleteWindow(123, $now, $now + 3, 2, 10, 16384));
    verify(end($broken->calls)[0] === 'messages/unload', 'Failure leaked message loader');
}
$empty = new WindowSource(['count' => 0, 'messages' => []]);
verify($empty->getCompleteWindow(123, $now, $now + 3, 2, 10, 16384) === [], 'Empty complete window rejected');
foreach (['https://example.com/', 'http://localhost:8000/', 'http://127.0.0.1:8000/redirect',
    'http://127.0.0.1:8000@evil.example/', 'http://127.0.0.1:99999/'] as $url) rejects(static fn() => new RelayMockTransport($url));
echo "PASS loaded-session pagination, incomplete/over-bound windows, cleanup and real-destination blocking\n";
