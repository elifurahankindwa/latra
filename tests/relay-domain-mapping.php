<?php
/** Domain mapping must work without legacy adapters, logging, SQL or runtime bootstrap. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Application\Collection\RelayPayloadMapper;

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Latra\\')) {
        throw new LogicException('Domain mapping requested legacy dependency: ' . $class);
    }
}, true, true);

function domainMappingExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$vehicle = ['id' => 1, 'imei' => '123456789012345', 'vehicle_reg_no' => 'SYNTHETIC',
    'engine_param' => 'io_239'];
$mapper = new RelayPayloadMapper($vehicle, array_fill_keys(range(1, 24), true), range(1, 24),
    80, 2, 3200, 'teltonika-fmb');
$now = time() - 120;
$source = static fn(array $parameters, int $offset = 0, float $longitude = 39.2): array => [
    't' => $now + $offset,
    'pos' => ['x' => $longitude, 'y' => -6.8, 's' => 20, 'z' => 10, 'c' => 90, 'sc' => 8],
    'p' => $parameters,
];
$items = static fn(array $mapped): array => array_column($mapped['payload']['items'], null, 'activity_id');

$movement = $items($mapper->map($source([]), []));
domainMappingExpect(array_keys($movement) === [1] && !isset($movement[1]['addon_info']),
    'Movement mapping fabricated addon data');

// Missing internal voltage must remain absent even when external-disconnect evidence exists.
$power = $items($mapper->map($source(['io_252' => 1, 'io_66' => 0]), []));
domainMappingExpect(isset($power[10])
    && $power[10]['addon_info'] === ['ext_power_voltage' => '0'],
    'Power-disconnect mapping lost zero voltage or invented an internal voltage');
$battery = $items($mapper->map($source(['io_67' => 3000]), []));
domainMappingExpect(isset($battery[9])
    && $battery[9]['addon_info'] === ['int_battery_voltage' => '3'],
    'Battery mapping lost units or invented an external voltage');

$off = $mapper->map($source(['io_239' => 0]), []);
$on = $mapper->map($source(['io_239' => 1], 1), $off['state']);
$trip = $items($mapper->map($source(['io_239' => 0], 61, 39.21), $on['state']));
domainMappingExpect(isset($trip[2]) === false && isset($trip[3])
    && (float) $trip[3]['addon_info']['distance_travelled'] > 0
    && $trip[3]['addon_info']['trip_duration'] === '1'
    && $trip[3]['addon_info']['avgSpeed'] === '20',
    'Engine-off mapping lost accumulated distance, duration or speed');

$fuel = $items($mapper->map($source(['event' => 'fuel', 'fuel_level' => 50]), []));
domainMappingExpect(isset($fuel[16]) && $fuel[16]['fuel_info']['fuelLevel'] === '500'
    && !isset($fuel[16]['addon_info']), 'Fuel mapping lost its separate fuel_info contract');

echo "PASS standalone domain mapping, missing-voltage handling, trip and fuel payloads\n";
