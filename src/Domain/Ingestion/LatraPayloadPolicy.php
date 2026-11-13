<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

final class LatraPayloadPolicy
{
    public const ACTIVITY_MOVEMENT = 1;
    public const ACTIVITY_ENGINE_ON = 2;
    public const ACTIVITY_ENGINE_OFF = 3;
    public const ACTIVITY_SPEEDING = 4;
    public const ACTIVITY_HASH_BRAKING = 5;
    public const ACTIVITY_HASH_TURNING = 6;
    public const ACTIVITY_HASH_ACCEL = 7;
    public const ACTIVITY_PANIC_DRIVER = 8;
    public const ACTIVITY_BATTERY_LOW = 9;
    public const ACTIVITY_POWER_DISCONNECT = 10;
    public const ACTIVITY_EXCESSIVE_IDLE = 11;
    public const ACTIVITY_ACCIDENT = 12;
    public const ACTIVITY_PANIC_PASSENGER = 13;
    public const ACTIVITY_TAMPERING = 14;
    public const ACTIVITY_BLACK_BOX = 15;
    public const ACTIVITY_FUEL_REPORT = 16;
    public const ACTIVITY_INVALID_SCAN = 17;
    public const ACTIVITY_ENGINE_START = 18;
    public const ACTIVITY_ENGINE_STOP = 19;
    public const ACTIVITY_ENTER_BOUNDARY = 20;
    public const ACTIVITY_LEAVE_BOUNDARY = 21;
    public const ACTIVITY_ENTER_CHECKPOINT = 22;
    public const ACTIVITY_LEAVE_CHECKPOINT = 23;
    public const ACTIVITY_IBUTTON_REGULAR = 24;

    public const ACTIVITIES = [
        1 => 'Movement/Logging',
        2 => 'Engine ON',
        3 => 'Engine OFF',
        4 => 'Speeding',
        5 => 'Hash Braking',
        6 => 'Hash Turning',
        7 => 'Hash Acceleration',
        8 => 'Panic Button (Driver)',
        9 => 'Internal Battery Low',
        10 => 'External Power Disconnected',
        11 => 'Excessive Idle',
        12 => 'Accident',
        13 => 'Panic Button (Passenger)',
        14 => 'Device Tampering',
        15 => 'Black Box Data Logging',
        16 => 'Fuel Data Report',
        17 => 'Invalid Scan',
        18 => 'Engine Start',
        19 => 'Engine Stop',
        20 => 'Enter Boundary',
        21 => 'Leave Boundary',
        22 => 'Enter Checkpoint',
        23 => 'Leave Checkpoint',
        24 => 'iButton Scan (Regular)',
    ];

    public const HARDWARE_FAULTS = [
        0 => 'No Fault',
        1 => 'Probe Cable Failed (connection fail)',
        2 => 'Probe Cable Intermediate (connection lose)',
        3 => 'Reserved',
        4 => 'Sensor Coupling Layer abnormal',
        5 => 'Detected fuel level zero',
        6 => 'Reserved',
        7 => 'Reserved',
        8 => 'Reserved',
        9 => 'Reserved',
    ];

    public static function mapVehicle(array $wialonUnit): array
    {
        $position = $wialonUnit['pos'] ?? [];
        $lastMessage = $wialonUnit['lmsg'] ?? [];
        $params = $lastMessage['p'] ?? [];
        $source = !empty($lastMessage['pos']) ? $lastMessage['pos'] : $position;

        return [
            'wialon_unit_id' => $wialonUnit['id'] ?? null,
            'vehicle_reg_no' => self::extractRegistration($wialonUnit),
            'name' => $wialonUnit['nm'] ?? null,
            'imei' => $wialonUnit['uid'] ?? $wialonUnit['uid2'] ?? null,
            'hw_type' => $wialonUnit['hw'] ?? null,
            'phone' => $wialonUnit['ph'] ?? null,
            'lat' => isset($source['y']) ? round((float) $source['y'], 8) : null,
            'lng' => isset($source['x']) ? round((float) $source['x'], 8) : null,
            'altitude' => isset($source['z']) ? (int) $source['z'] : null,
            'speed' => isset($source['s']) ? round((float) $source['s'], 2) : null,
            'bearing' => isset($source['c']) ? round((float) $source['c'], 2) : null,
            'satellite_count' => isset($source['sc']) ? (int) $source['sc'] : null,
            'hdop' => isset($params['hdop']) ? round((float) $params['hdop'], 2) : null,
            'd2d3' => self::detect2D3D($source),
            'rssi' => isset($params['rssi']) ? (int) $params['rssi'] : (isset($params['gsm']) ? (int) $params['gsm'] : null),
            'lac' => isset($params['lac']) ? (int) $params['lac']
                : (isset($params['gsm_lac']) ? (int) $params['gsm_lac']
                    : (isset($params['lbs_lac']) ? (int) $params['lbs_lac'] : null)),
            'cell_id' => isset($params['cell_id']) ? (int) $params['cell_id']
                : (isset($params['cellid']) ? (int) $params['cellid']
                    : (isset($params['cid']) ? (int) $params['cid']
                        : (isset($params['gsm_cellid']) ? (int) $params['gsm_cellid']
                            : (isset($params['gsm_cell_id']) ? (int) $params['gsm_cell_id'] : null)))),
            'mcc' => isset($params['mcc']) ? (int) $params['mcc'] : null,
            'mgs_id' => isset($lastMessage['t']) ? (int) $lastMessage['t'] : (isset($position['t']) ? (int) $position['t'] : null),
            'last_message_time' => isset($lastMessage['t']) ? (int) $lastMessage['t'] : (isset($position['t']) ? (int) $position['t'] : null),
        ];
    }

    public static function buildPayload(
        array $vehicle,
        array $message,
        int $activityId,
        array $addonInfo = [],
        array $fuelInfo = [],
    ): array {
        $timestamp = self::toEpochMs(
            $message['wialon_msg_time'] ?? $message['last_message_time'] ?? $vehicle['last_message_time'] ?? time(),
        );
        $item = [
            'latitude' => self::format($message['lat'] ?? $vehicle['lat'] ?? null, 'string'),
            'longitude' => self::format($message['lng'] ?? $vehicle['lng'] ?? null, 'string'),
            'altitude' => self::format($message['altitude'] ?? $vehicle['altitude'] ?? null, 'string'),
            'timestamp' => self::format($timestamp, 'string'),
            'horizontal_speed' => self::format($message['speed'] ?? $vehicle['speed'] ?? null, 'string'),
            'vertical_speed' => self::format($message['vertical_speed'] ?? $vehicle['vertical_speed'] ?? null, 'string'),
            'bearing' => self::format($message['bearing'] ?? $vehicle['bearing'] ?? null, 'string'),
            'satellite_count' => self::format($message['satellite_count'] ?? $vehicle['satellite_count'] ?? null, 'string'),
            'HDOP' => self::format($message['hdop'] ?? $vehicle['hdop'] ?? null, 'string'),
            'd2d3' => self::format($message['d2d3'] ?? $vehicle['d2d3'] ?? null, 'string'),
            'RSSI' => self::format($message['rssi'] ?? $vehicle['rssi'] ?? null, 'string'),
            'LAC' => self::format($message['lac'] ?? $vehicle['lac'] ?? null, 'string'),
            'Cell_ID' => self::format($message['cell_id'] ?? $vehicle['cell_id'] ?? null, 'string'),
            'MGS_ID' => self::format($message['mgs_id'] ?? $vehicle['mgs_id'] ?? null, 'string'),
            'MCC' => self::format($message['mcc'] ?? $vehicle['mcc'] ?? null, 'string'),
            'activity_id' => (string) $activityId,
        ];
        $odometer = $message['odometer'] ?? $vehicle['odometer'] ?? null;
        if ($odometer !== null && $odometer !== '') {
            $item['odometer'] = self::format($odometer, 'string');
        }
        if (!empty($addonInfo) && in_array($activityId, [2, 3, 4, 9, 10, 11, 14, 16, 17, 24], true)) {
            $item['addon_info'] = $addonInfo;
        }
        if ($activityId === 16 && !empty($fuelInfo)) {
            $item['fuel_info'] = $fuelInfo;
        }
        return [
            'vehicle_reg_no' => $vehicle['vehicle_reg_no'] ?? null,
            'type' => 'poi',
            'imei' => $vehicle['imei'] ?: null,
            'items' => [$item],
        ];
    }

    public static function buildAddonInfo(int $activityId, array $wialonParams = [], array $customData = []): ?array
    {
        switch ($activityId) {
            case 2:
                return [
                    'idleTime' => self::format($customData['idleTime'] ?? $wialonParams['idle_time'] ?? null, 'string', '0'),
                    'v_driver_identification_no' => self::format(
                        $customData['driverId'] ?? $wialonParams['driver_id'] ?? $wialonParams['tco_driver1_id'] ?? $wialonParams['pass'] ?? null,
                        'string',
                        'FFFFFFFFFFFFFFFF',
                    ),
                ];
            case 3:
                return [
                    'distance_travelled' => self::format($customData['distance'] ?? $wialonParams['mileage'] ?? $wialonParams['mil'] ?? null, 'string', '0'),
                    'trip_duration' => self::format($customData['duration'] ?? $wialonParams['tco_activity_tm'] ?? null, 'string', '0'),
                    'avgSpeed' => self::format($customData['avgSpeed'] ?? null, 'string', '0'),
                    'maxSpeed' => self::format($customData['maxSpeed'] ?? null, 'string', '0'),
                ];
            case 9:
            case 10:
                $external = $customData['extVolt'] ?? $wialonParams['pwr_ext'] ?? $wialonParams['ext_power'] ?? $wialonParams['io_66'] ?? null;
                $internal = $customData['intVolt'] ?? $wialonParams['pwr_int'] ?? $wialonParams['int_battery'] ?? $wialonParams['io_67'] ?? null;
                $output = [];
                if ($external !== null && $external !== '') {
                    $output['ext_power_voltage'] = self::format($external, 'string');
                }
                if ($internal !== null && $internal !== '') {
                    $output['int_battery_voltage'] = self::format($internal, 'string');
                }
                return empty($output) ? null : $output;
            case 16:
                return null;
            case 17:
            case 24:
                return [
                    'v_driver_identification_no' => self::format(
                        $customData['driverId'] ?? $wialonParams['driver_id'] ?? $wialonParams['tco_driver1_id'] ?? $wialonParams['pass'] ?? 'FFFFFFFFFFFFFFFF',
                        'string',
                    ),
                ];
            default:
                return null;
        }
    }

    public static function buildFuelInfo(array $wialonParams = [], array $customData = []): array
    {
        $fuelLevel = $customData['fuelLevel'] ?? $wialonParams['fuel'] ?? $wialonParams['fuel_level'] ?? null;
        $realTimeFuelLevel = $customData['rtFuelLevel'] ?? $wialonParams['rt_fuel'] ?? $fuelLevel;
        return [
            'validFlag' => self::format($customData['validFlag'] ?? '0', 'string'),
            'signalLevel' => self::format($customData['signalLevel'] ?? $wialonParams['fuel_signal'] ?? '75', 'string'),
            'softStatus' => self::format($customData['softStatus'] ?? '0', 'string'),
            'hardFault' => self::format($customData['hardFault'] ?? '0', 'string'),
            'fuelLevel' => self::format($fuelLevel !== null ? (int) ($fuelLevel * 10) : null, 'string', '0'),
            'rtFuelLevel' => self::format($realTimeFuelLevel !== null ? (int) ($realTimeFuelLevel * 10) : null, 'string', '0'),
            'tankTemp' => self::format($customData['tankTemp'] ?? $wialonParams['tank_temp'] ?? null, 'string', '0'),
            'channel' => self::format($customData['channel'] ?? '1', 'string'),
        ];
    }

    public static function detectActivity(array $wialonParams, array $previousState = []): int
    {
        if (isset($wialonParams['event'])) {
            $event = strtolower((string) $wialonParams['event']);
            $eventMap = [
                'engine_on' => 2, 'engine start' => 2, 'ignition_on' => 2,
                'engine_off' => 3, 'engine stop' => 3, 'ignition_off' => 3,
                'speeding' => 4, 'overspeed' => 4,
                'harsh_braking' => 5, 'harshbrake' => 5,
                'harsh_turning' => 6, 'harsturn' => 6,
                'harsh_acceleration' => 7, 'harshaccel' => 7,
                'panic' => 8, 'sos' => 8, 'alarm' => 8,
                'battery_low' => 9, 'low_battery' => 9,
                'power_disconnected' => 10, 'ext_power_off' => 10,
                'excessive_idle' => 11, 'long_idle' => 11,
                'accident' => 12, 'crash' => 12,
                'passenger_panic' => 13,
                'tamper' => 14, 'tampering' => 14, 'antenna_cut' => 14,
                'black_box' => 15,
                'fuel' => 16, 'fuel_data' => 16,
                'invalid_scan' => 17, 'invalid_ibutton' => 17,
                'boundary_enter' => 20, 'geofence_in' => 20,
                'boundary_exit' => 21, 'geofence_out' => 21,
                'checkpoint_enter' => 22,
                'checkpoint_exit' => 23,
                'ibutton' => 24, 'driver_scan' => 24,
            ];
            foreach ($eventMap as $key => $activityId) {
                if (strpos($event, $key) !== false) {
                    return $activityId;
                }
            }
        }
        if (isset($wialonParams['pass']) || isset($wialonParams['tco_driver1_id'])) {
            return 24;
        }
        if (isset($wialonParams['fuel']) || isset($wialonParams['fuel_level'])) {
            return 16;
        }
        return 1;
    }

    public static function toEpochMs(mixed $timestamp): string
    {
        if (is_numeric($timestamp)) {
            if (strlen((string) $timestamp) > 10) {
                return (string) $timestamp;
            }
            return (string) ((int) $timestamp * 1000);
        }
        if (is_string($timestamp)) {
            $epoch = strtotime($timestamp);
            return $epoch ? (string) ($epoch * 1000) : (string) (time() * 1000);
        }
        return (string) (time() * 1000);
    }

    public static function activityRequiresAddon(int $activityId): bool
    {
        return in_array($activityId, [2, 3, 4, 9, 10, 11, 14, 17, 24], true);
    }

    public static function getActivityName(int $activityId): string
    {
        return self::ACTIVITIES[$activityId] ?? 'Unknown';
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $latitudeDelta = deg2rad($lat2 - $lat1);
        $longitudeDelta = deg2rad($lng2 - $lng1);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($longitudeDelta / 2) ** 2;
        return 2 * $earthRadius * asin(sqrt($a));
    }

    public static function validatePayload(array $payload): array
    {
        $errors = [];
        if (empty($payload['vehicle_reg_no'])) {
            $errors[] = 'vehicle_reg_no is required';
        }
        if (empty($payload['imei'])) {
            $errors[] = 'imei is required';
        }
        if (empty($payload['items']) || !is_array($payload['items'])) {
            $errors[] = 'items array is required';
        } else {
            $item = $payload['items'][0];
            foreach (['latitude', 'longitude', 'timestamp', 'activity_id'] as $field) {
                if (!array_key_exists($field, $item) || $item[$field] === null || $item[$field] === '') {
                    $errors[] = "items[0].$field is required";
                }
            }
            $nullable = [
                'altitude', 'horizontal_speed', 'vertical_speed', 'bearing', 'satellite_count',
                'HDOP', 'd2d3', 'RSSI', 'LAC', 'Cell_ID', 'MGS_ID', 'MCC',
            ];
            foreach ($nullable as $field) {
                if (array_key_exists($field, $item) && $item[$field] !== null && $item[$field] !== ''
                    && !is_numeric($item[$field])) {
                    $errors[] = "items[0].$field must be numeric";
                }
            }
            $activityId = (int) ($item['activity_id'] ?? 0);
            if (self::activityRequiresAddon($activityId) && empty($item['addon_info'])) {
                $errors[] = "items[0].addon_info is required for activity $activityId";
            }
            if ($activityId === 16 && empty($item['fuel_info'])) {
                $errors[] = 'items[0].fuel_info is required for activity 16';
            }
        }
        return $errors;
    }

    private static function extractRegistration(array $unit): string
    {
        foreach ($unit['flds'] ?? [] as $field) {
            $name = strtolower($field['n'] ?? '');
            if (in_array($name, ['reg_no', 'registration', 'plate', 'plate_no', 'vehicle_reg'])) {
                return strtoupper(trim($field['v'] ?? ''));
            }
        }
        $name = strtoupper(trim($unit['nm'] ?? ''));
        if (preg_match('/^[A-Z0-9\-]{3,20}$/', $name)) {
            return $name;
        }
        return 'W' . ($unit['id'] ?? '00000');
    }

    private static function detect2D3D(array $position): ?int
    {
        $flags = $position['f'] ?? 0;
        if ($flags & 0x02) {
            return 3;
        }
        if (isset($position['z']) && $position['z'] !== 0) {
            return 3;
        }
        return 2;
    }

    private static function format(mixed $value, string $type, mixed $default = null): mixed
    {
        if ($value === null || $value === '' || $value === 'null') {
            return $default;
        }
        return match ($type) {
            'int' => (int) $value,
            'float' => round((float) $value, 2),
            default => (string) $value,
        };
    }
}
