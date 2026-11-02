<?php
/**
 * LATRA mapper.
 */
class LatraMapper {

    /** LATRA reads MGS_ID as a signed 32-bit integer. */
    private const MAX_MGS_ID = 2147483647;

    /** Named activity constants (mirror ACTIVITIES). */
    const ACTIVITY_MOVEMENT        = 1;
    const ACTIVITY_ENGINE_ON       = 2;
    const ACTIVITY_ENGINE_OFF      = 3;
    const ACTIVITY_SPEEDING        = 4;
    const ACTIVITY_HASH_BRAKING    = 5;
    const ACTIVITY_HASH_TURNING    = 6;
    const ACTIVITY_HASH_ACCEL      = 7;
    const ACTIVITY_PANIC_DRIVER    = 8;
    const ACTIVITY_BATTERY_LOW     = 9;
    const ACTIVITY_POWER_DISCONNECT= 10;
    const ACTIVITY_EXCESSIVE_IDLE  = 11;
    const ACTIVITY_ACCIDENT        = 12;
    const ACTIVITY_PANIC_PASSENGER = 13;
    const ACTIVITY_TAMPERING       = 14;
    const ACTIVITY_BLACK_BOX       = 15;
    const ACTIVITY_FUEL_REPORT     = 16;
    const ACTIVITY_INVALID_SCAN    = 17;
    const ACTIVITY_ENGINE_START    = 18;
    const ACTIVITY_ENGINE_STOP     = 19;
    const ACTIVITY_ENTER_BOUNDARY  = 20;
    const ACTIVITY_LEAVE_BOUNDARY  = 21;
    const ACTIVITY_ENTER_CHECKPOINT = 22;
    const ACTIVITY_LEAVE_CHECKPOINT = 23;
    const ACTIVITY_IBUTTON_REGULAR = 24;

    /**
     * Activity ID definitions per LATRA spec
     */
    const ACTIVITIES = [
        1  => 'Movement/Logging',
        2  => 'Engine ON',
        3  => 'Engine OFF',
        4  => 'Speeding',
        5  => 'Hash Braking',
        6  => 'Hash Turning',
        7  => 'Hash Acceleration',
        8  => 'Panic Button (Driver)',
        9  => 'Internal Battery Low',
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
        24 => 'iButton Scan (Regular)'
    ];

    /**
     * Hardware fault codes per LATRA Table 2
     */
    const HARDWARE_FAULTS = [
        0 => 'No Fault',
        1 => 'Probe Cable Failed (connection fail)',
        2 => 'Probe Cable Intermediate (connection lose)',
        3 => 'Reserved',
        4 => 'Sensor Coupling Layer abnormal',
        5 => 'Detected fuel level zero',
        6 => 'Reserved',
        7 => 'Reserved',
        8 => 'Reserved',
        9 => 'Reserved'
    ];

    /**
     * Map Wialon unit data to LATRA vehicle record
     */
    public static function mapVehicle(array $wialonUnit): array {
        $pos = $wialonUnit['pos'] ?? [];
        $lmsg = $wialonUnit['lmsg'] ?? [];
        $params = $lmsg['p'] ?? [];

        // Use last message position if available, otherwise use pos
        $source = !empty($lmsg['pos']) ? $lmsg['pos'] : $pos;

        return [
            'wialon_unit_id' => $wialonUnit['id'] ?? null,
            'vehicle_reg_no' => self::extractRegNo($wialonUnit),
            'name' => $wialonUnit['nm'] ?? null,
            'imei' => $wialonUnit['uid'] ?? $wialonUnit['uid2'] ?? null,
            'hw_type' => $wialonUnit['hw'] ?? null,
            'phone' => $wialonUnit['ph'] ?? null,
            'lat' => isset($source['y']) ? round((float)$source['y'], 8) : null,
            'lng' => isset($source['x']) ? round((float)$source['x'], 8) : null,
            'altitude' => isset($source['z']) ? (int)$source['z'] : null,
            'speed' => isset($source['s']) ? round((float)$source['s'], 2) : null,
            'bearing' => isset($source['c']) ? round((float)$source['c'], 2) : null,
            'satellite_count' => isset($source['sc']) ? (int)$source['sc'] : null,
            'hdop' => isset($params['hdop']) ? round((float)$params['hdop'], 2) : null,
            'd2d3' => self::detect2D3D($source),
            'rssi' => isset($params['rssi']) ? (int)$params['rssi'] : (isset($params['gsm']) ? (int)$params['gsm'] : null),
            'lac' => isset($params['lac']) ? (int)$params['lac'] : (isset($params['gsm_lac']) ? (int)$params['gsm_lac'] : (isset($params['lbs_lac']) ? (int)$params['lbs_lac'] : null)),
            'cell_id' => isset($params['cell_id']) ? (int)$params['cell_id'] : (isset($params['cellid']) ? (int)$params['cellid'] : (isset($params['cid']) ? (int)$params['cid'] : (isset($params['gsm_cellid']) ? (int)$params['gsm_cellid'] : (isset($params['gsm_cell_id']) ? (int)$params['gsm_cell_id'] : null)))),
            'mcc' => isset($params['mcc']) ? (int)$params['mcc'] : null,
            'mgs_id' => isset($lmsg['t']) ? (int)$lmsg['t'] : (isset($pos['t']) ? (int)$pos['t'] : null),
            'last_message_time' => isset($lmsg['t']) ? (int)$lmsg['t'] : (isset($pos['t']) ? (int)$pos['t'] : null),
        ];
    }

    /**
     * Build LATRA API payload for a specific activity
     * CRITICAL: All required LATRA item fields must be present.
     */
    public static function buildPayload(array $vehicle, array $message, int $activityId, array $addonInfo = [], array $fuelInfo = []): array {
        $timestamp = self::toEpochMs($message['wialon_msg_time'] ?? $message['last_message_time'] ?? $vehicle['last_message_time'] ?? time());

        // Core payload — never replace an unavailable field with a fabricated value.
        // Unknown fields stay null so downstream validation can flag them as gaps,
        // and so LATRA receives genuine data only (per compliance requirements).
        $item = [
            'latitude'           => self::fmt($message['lat'] ?? $vehicle['lat'] ?? null, 'string'),
            'longitude'          => self::fmt($message['lng'] ?? $vehicle['lng'] ?? null, 'string'),
            'altitude'           => self::fmt($message['altitude'] ?? $vehicle['altitude'] ?? null, 'string'),
            'timestamp'          => self::fmt($timestamp, 'string'),
            'speed'              => self::fmt($message['speed'] ?? $vehicle['speed'] ?? null, 'string'),
            'horizontal_speed'   => self::fmt($message['speed'] ?? $vehicle['speed'] ?? null, 'string'),
            'vertical_speed'     => self::fmt($message['vertical_speed'] ?? $vehicle['vertical_speed'] ?? null, 'string'),
            'bearing'            => self::fmt($message['bearing'] ?? $vehicle['bearing'] ?? null, 'string'),
            'satellite_count'    => self::fmt($message['satellite_count'] ?? $vehicle['satellite_count'] ?? null, 'string'),
            'HDOP'               => self::fmt($message['hdop'] ?? $vehicle['hdop'] ?? null, 'string'),
            'd2d3'               => self::fmt($message['d2d3'] ?? $vehicle['d2d3'] ?? null, 'string'),
            'RSSI'               => self::fmt($message['rssi'] ?? $vehicle['rssi'] ?? null, 'string'),
            'LAC'                => self::fmt($message['lac'] ?? $vehicle['lac'] ?? null, 'string'),
            'Cell_ID'            => self::fmt($message['cell_id'] ?? $vehicle['cell_id'] ?? null, 'string'),
            'MGS_ID'             => self::uniqueMgsId($message, $activityId),
            'MCC'                => self::fmt($message['mcc'] ?? $vehicle['mcc'] ?? null, 'string'),
            'activity_id'        => (string)$activityId,
        ];

        $odometer = $message['odometer'] ?? $vehicle['odometer'] ?? null;
        if ($odometer !== null && $odometer !== '') {
            $item['odometer'] = self::fmt($odometer, 'string');
        }

        // Activity 4 (Speeding), 11 (Excessive Idle) and 14 (Device Tampering)
        // do NOT carry addon_info — activity_definitions.requires_addon = 0 for
        // them. Keep this list in sync with validatePayload() and
        // activityRequiresAddon().
        if (!empty($addonInfo) && in_array($activityId, [2, 3, 9, 10, 16, 17, 24], true)) {
            $item['addon_info'] = $addonInfo;
        }

        if ($activityId === 16 && !empty($fuelInfo)) {
            $item['fuel_info'] = $fuelInfo;
        }

        $payload = [
            'vehicle_reg_no' => $vehicle['vehicle_reg_no'] ?? null,
            'type'           => 'poi',
            'imei'           => $vehicle['imei'] ?: null,
            'items'          => [$item]
        ];

        return $payload;
    }

    /**
     * LATRA uses MGS_ID as a unique data running number. Keep it timestamp-based
     * and stable on retries, while distinguishing activities at the same time.
     *
     * LATRA parses MGS_ID as a signed 32-bit integer: a millisecond value
     * overflows it and the API rejects the whole payload with
     * `For input string: "<value>"`. Epoch seconds stay inside the accepted
     * range and the activity offset keeps the movement (1) and overspeed (4)
     * records of the same fix apart.
     */
    private static function uniqueMgsId(array $message, int $activityId): string {
        $epochSeconds = self::toEpochSeconds(
            $message['wialon_msg_time'] ?? $message['last_message_time'] ?? time()
        );
        if ($epochSeconds < 0 || $epochSeconds > self::MAX_MGS_ID - $activityId) {
            throw new RuntimeException('Cannot build a unique LATRA MGS_ID from this message');
        }

        return (string) ($epochSeconds + $activityId);
    }

    /**
     * Activities prepared for a telemetry fix.
     *
     * Movement/Logging (1) is the baseline record every fix produces. Speeding
     * (4) is prepared on top of it as soon as the horizontal speed exceeds the
     * configured limit, so LATRA receives BOTH records for the same fix.
     *
     * Fixes classified as another event (panic, accident, engine state …) are
     * not part of the movement path and keep their single activity.
     *
     * @param int        $detectedActivity activity the classifier assigned to the fix
     * @param float|null $speed            horizontal speed in km/h (null = unknown)
     * @param float      $speedLimit       configured speed limit in km/h
     * @return int[] activity IDs to submit, baseline first
     */
    public static function activitiesForFix(int $detectedActivity, ?float $speed, float $speedLimit): array {
        if (Schema::config('overspeed_dual_send', '1') !== '1') {
            return [$detectedActivity];
        }
        if (!in_array($detectedActivity, [self::ACTIVITY_MOVEMENT, self::ACTIVITY_SPEEDING], true)) {
            return [$detectedActivity];
        }

        $speeding = $detectedActivity === self::ACTIVITY_SPEEDING
            || EventDetector::isOverspeed($speed, $speedLimit);

        $activities = [self::ACTIVITY_MOVEMENT];
        if ($speeding) {
            $activities[] = self::ACTIVITY_SPEEDING;
        }
        return $activities;
    }

    /**
     * Build addon_info for specific activities
     */
    public static function buildAddonInfo(int $activityId, array $wialonParams = [], array $customData = []): ?array {
        switch ($activityId) {
            case 2: // Engine ON
                return [
                    'idleTime'                    => self::fmt($customData['idleTime'] ?? $wialonParams['idle_time'] ?? null, 'string', '0'),
                    'v_driver_identification_no' => self::fmt($customData['driverId'] ?? $wialonParams['driver_id'] ?? $wialonParams['tco_driver1_id'] ?? $wialonParams['pass'] ?? null, 'string', 'FFFFFFFFFFFFFFFF')
                ];

            case 3: // Engine OFF (Trip End)
                return [
                    'distance_travelled' => self::fmt($customData['distance'] ?? $wialonParams['mileage'] ?? $wialonParams['mil'] ?? null, 'string', '0'),
                    'trip_duration'      => self::fmt($customData['duration'] ?? $wialonParams['tco_activity_tm'] ?? null, 'string', '0'),
                    'avgSpeed'           => self::fmt($customData['avgSpeed'] ?? null, 'string', '0'),
                    'maxSpeed'           => self::fmt($customData['maxSpeed'] ?? null, 'string', '0')
                ];

            case 9:  // Internal Battery Low
            case 10: // External Power Disconnected
                $extVolt = $customData['extVolt'] ?? $wialonParams['pwr_ext'] ?? $wialonParams['ext_power'] ?? $wialonParams['io_66'] ?? null;
                $intVolt = $customData['intVolt'] ?? $wialonParams['pwr_int'] ?? $wialonParams['int_battery'] ?? $wialonParams['io_67'] ?? null;

                if ($extVolt === null || $extVolt === '') {
                    Logger::info('voltage.skip', [
                        'field'  => 'ext_power_voltage',
                        'reason' => 'no_live_value',
                    ]);
                    $extVolt = null;
                }
                if ($intVolt === null || $intVolt === '') {
                    Logger::info('voltage.skip', [
                        'field'  => 'int_battery_voltage',
                        'reason' => 'no_live_value',
                    ]);
                    $intVolt = null;
                }

                $out = [];
                if ($extVolt !== null && $extVolt !== '') {
                    $out['ext_power_voltage'] = self::fmt($extVolt, 'string');
                }
                if ($intVolt !== null && $intVolt !== '') {
                    $out['int_battery_voltage'] = self::fmt($intVolt, 'string');
                }
                return empty($out) ? null : $out;

            case 16: // Fuel Data Report
                return null; // fuel_info is separate

            case 17: // Invalid Scan
            case 24: // iButton Scan (Regular)
                return [
                    'v_driver_identification_no' => self::fmt(
                        $customData['driverId'] ?? $wialonParams['driver_id'] ?? $wialonParams['tco_driver1_id'] ?? $wialonParams['pass'] ?? 'FFFFFFFFFFFFFFFF',
                        'string'
                    )
                ];

            default:
                return null;
        }
    }

    /**
     * Build fuel_info object for activity 16
     */
    public static function buildFuelInfo(array $wialonParams = [], array $customData = []): array {
        $fuelLevel = $customData['fuelLevel'] ?? $wialonParams['fuel'] ?? $wialonParams['fuel_level'] ?? null;
        $rtFuelLevel = $customData['rtFuelLevel'] ?? $wialonParams['rt_fuel'] ?? $fuelLevel;

        return [
            'validFlag'    => self::fmt($customData['validFlag'] ?? '0', 'string'),
            'signalLevel'  => self::fmt($customData['signalLevel'] ?? $wialonParams['fuel_signal'] ?? '75', 'string'),
            'softStatus'   => self::fmt($customData['softStatus'] ?? '0', 'string'),
            'hardFault'    => self::fmt($customData['hardFault'] ?? '0', 'string'),
            'fuelLevel'    => self::fmt($fuelLevel !== null ? (int)($fuelLevel * 10) : null, 'string', '0'),
            'rtFuelLevel'  => self::fmt($rtFuelLevel !== null ? (int)($rtFuelLevel * 10) : null, 'string', '0'),
            'tankTemp'     => self::fmt($customData['tankTemp'] ?? $wialonParams['tank_temp'] ?? null, 'string', '0'),
            'channel'      => self::fmt($customData['channel'] ?? '1', 'string')
        ];
    }

    /**
     * Detect activity from Wialon message parameters
     */
    public static function detectActivity(array $wialonParams, array $previousState = [], array $ctx = []): int {
        $detected = EventDetector::detect($wialonParams, $previousState, $ctx);
        return (int) $detected['activity_id'];
    }

    /**
     * Convert to epoch milliseconds (LATRA Note 2)
     */
    public static function toEpochMs($timestamp): string {
        if (is_numeric($timestamp)) {
            if (strlen((string)$timestamp) > 10) {
                return (string)$timestamp; // Already in ms
            }
            return (string)((int)$timestamp * 1000);
        }
        if (is_string($timestamp)) {
            $ts = strtotime($timestamp);
            return $ts ? (string)($ts * 1000) : (string)(time() * 1000);
        }
        return (string)(time() * 1000);
    }

    /**
     * Convert to epoch seconds.
     *
     * Used for MGS_ID, which LATRA reads as a 32-bit integer — millisecond
     * values do not fit and are rejected by the API.
     */
    public static function toEpochSeconds($timestamp): int {
        if (is_numeric($timestamp)) {
            $value = (int) $timestamp;
            // Values longer than 10 digits are already in milliseconds.
            return strlen((string) $timestamp) > 10 ? intdiv($value, 1000) : $value;
        }
        if (is_string($timestamp)) {
            $ts = strtotime($timestamp);
            return $ts ?: time();
        }
        return time();
    }

    /**
     * Check if activity requires addon_info
     */
    public static function activityRequiresAddon(int $activityId): bool {
        return in_array($activityId, [2, 3, 9, 10, 16, 17, 24], true);
    }

    /**
     * Extract registration number from Wialon unit
     */
    private static function extractRegNo(array $unit): string {
        // Try custom fields first
        $fields = $unit['flds'] ?? [];
        foreach ($fields as $field) {
            $name = strtolower($field['n'] ?? '');
            if (in_array($name, ['reg_no', 'registration', 'plate', 'plate_no', 'vehicle_reg'])) {
                return strtoupper(trim($field['v'] ?? ''));
            }
        }

        // Try name as registration number
        $name = strtoupper(trim($unit['nm'] ?? ''));
        if (preg_match('/^[A-Z0-9\-]{3,20}$/', $name)) {
            return $name;
        }

        // Use unit ID as fallback
        return 'W' . ($unit['id'] ?? '00000');
    }

    /**
     * Detect 2D/3D satellite mode from Wialon data
     */
    private static function detect2D3D(array $pos): ?int {
        $flags = $pos['f'] ?? 0;
        // If position flags indicate 3D fix
        if ($flags & 0x02) {
            return 3;
        }
        // If we have altitude data, likely 3D
        if (isset($pos['z']) && $pos['z'] !== 0) {
            return 3;
        }
        return 2;
    }

    /**
     * Format value - preserves null, converts types
     */
    private static function fmt($value, string $type, $default = null) {
        if ($value === null || $value === '' || $value === 'null') {
            return $default;
        }

        switch ($type) {
            case 'int':
                return (int)$value;
            case 'float':
                return round((float)$value, 2);
            case 'string':
            default:
                return (string)$value;
        }
    }

    /**
     * Get activity name
     */
    public static function getActivityName(int $activityId): string {
        return self::ACTIVITIES[$activityId] ?? 'Unknown';
    }

    /**
     * Compute Engine OFF (activity 3) trip statistics from stored telemetry:
     * distance (haversine over consecutive fixes), elapsed minutes, and
     * average/max speed for the window since the last Engine ON event.
     * Falls back to the prior 24h if no Engine ON marker exists.
     */
    public static function buildTripStats(int $vehicleId, int $engineOffMsgTime): array {
        $start = Database::fetch(
            "SELECT wialon_msg_time FROM message_log
             WHERE vehicle_id = ? AND activity_id = ? AND wialon_msg_time < ?
             ORDER BY wialon_msg_time DESC LIMIT 1",
            [$vehicleId, 2, $engineOffMsgTime]
        );
        $from = $start ? (int) $start['wialon_msg_time'] : $engineOffMsgTime - 86400;

        $rows = Database::fetchAll(
            "SELECT lat, lng, speed FROM message_log
             WHERE vehicle_id = ? AND wialon_msg_time >= ? AND wialon_msg_time <= ?
             ORDER BY wialon_msg_time ASC",
            [$vehicleId, $from, $engineOffMsgTime]
        );

        $distance = 0.0;
        $speeds = [];
        $maxSpeed = 0.0;
        $prev = null;
        foreach ($rows as $r) {
            if (($r['speed'] ?? null) !== null) {
                $s = (float) $r['speed'];
                $speeds[] = $s;
                $maxSpeed = max($maxSpeed, $s);
            }
            if ($prev !== null
                && $r['lat'] !== null && $r['lng'] !== null
                && $prev['lat'] !== null && $prev['lng'] !== null) {
                $distance += self::haversineKm((float) $prev['lat'], (float) $prev['lng'], (float) $r['lat'], (float) $r['lng']);
            }
            $prev = $r;
        }

        return [
            'distance' => round($distance, 2),
            'duration' => round(max(0, $engineOffMsgTime - $from) / 60.0, 2),
            'avgSpeed' => $speeds ? round(array_sum($speeds) / count($speeds), 2) : 0,
            'maxSpeed' => $maxSpeed,
        ];
    }

    /** Great-circle distance in km between two coordinates (WGS-84 / haversine). */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $earthRadius * asin(sqrt($a));
    }

    /**
     * Validate LATRA payload structure.
     *
     * Distinguishes core required fields (must be present and non-empty) from
     * nullable fields (may be null when the source device does not report them).
     * Values are NEVER auto-filled — a missing source simply yields null/absent.
     */
    public static function validatePayload(array $payload): array {
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
            foreach ($payload['items'] as $index => $activityItem) {
                $rawActivityId = is_array($activityItem) ? ($activityItem['activity_id'] ?? null) : null;
                if ((!is_int($rawActivityId) && !is_string($rawActivityId))
                    || !preg_match('/^\d+$/', (string) $rawActivityId)
                    || !isset(self::ACTIVITIES[(int) $rawActivityId])) {
                    $errors[] = "items[$index].activity_id must be a LATRA activity ID from 1 to 24";
                }
            }

            if (!isset($payload['items'][0]) || !is_array($payload['items'][0])) {
                $errors[] = 'items[0] must be an object';
            } else {
                $item = $payload['items'][0];

                // Always required for a valid position/event record.
                $required = ['latitude', 'longitude', 'timestamp', 'activity_id'];
                foreach ($required as $field) {
                    if (!array_key_exists($field, $item) || $item[$field] === null || $item[$field] === '') {
                        $errors[] = "items[0].$field is required";
                    }
                }

                // Optional / nullable — present and non-empty if available, else left null.
                // These are not fabricated as '0' when the source is unknown.
                $nullable = ['altitude', 'speed', 'horizontal_speed', 'vertical_speed', 'bearing',
                             'satellite_count', 'HDOP', 'd2d3', 'RSSI', 'LAC', 'Cell_ID', 'MGS_ID', 'MCC'];
                foreach ($nullable as $field) {
                    if (array_key_exists($field, $item)
                        && $item[$field] !== null && $item[$field] !== '') {
                        $val = $item[$field];
                        if (!is_numeric($val)) {
                            $errors[] = "items[0].$field must be numeric";
                        }
                    }
                }

                $activityId = (int)($item['activity_id'] ?? 0);
                if (in_array($activityId, [2, 3, 9, 10, 16, 17, 24], true) && empty($item['addon_info'])) {
                    $errors[] = "items[0].addon_info is required for activity $activityId";
                }
                if ($activityId === 16 && empty($item['fuel_info'])) {
                    $errors[] = "items[0].fuel_info is required for activity 16";
                }
            }
        }

        return $errors;
    }
}
