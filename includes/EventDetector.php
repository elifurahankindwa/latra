<?php
/**
 * Activity detector.
 */
class EventDetector {

    // Activity IDs from LATRA spec (see LatraMapper::ACTIVITIES).
    const MOVEMENT        = 1;
    const ENGINE_ON       = 2;
    const ENGINE_OFF      = 3;
    const SPEEDING        = 4;
    const HASH_BRAKING    = 5;
    const HASH_TURNING    = 6;
    const HASH_ACCEL      = 7;
    const PANIC_DRIVER    = 8;
    const BATTERY_LOW     = 9;
    const POWER_DISC      = 10;
    const EXCESSIVE_IDLE  = 11;
    const ACCIDENT        = 12;
    const PANIC_PASSENGER = 13;
    const TAMPERING       = 14;
    const BLACK_BOX       = 15;
    const FUEL_REPORT     = 16;
    const INVALID_SCAN    = 17;
    const ENGINE_START    = 18;
    const ENGINE_STOP     = 19;
    const ENTER_BOUNDARY  = 20;
    const LEAVE_BOUNDARY  = 21;
    const ENTER_CHECKPOINT = 22;
    const LEAVE_CHECKPOINT = 23;
    const IBUTTON_REGULAR = 24;
    public const EXCESSIVE_IDLE_SECONDS = 3600;

    /** Wialon event-name keyword → LATRA activity (passive/event match). */
    public const EVENT_MAP = [
        'engine_on' => 2, 'engine start' => 2, 'ignition_on' => 2, 'ignition_on1' => 2,
        'engine_off' => 3, 'engine stop' => 3, 'ignition_off' => 3,
        'speeding' => 4, 'overspeed' => 4,
        'harsh_braking' => 5, 'harshbrake' => 5, 'hard_braking' => 5,
        'harsh_turning' => 6, 'harsturn' => 6, 'hard_turn' => 6,
        'harsh_acceleration' => 7, 'harshaccel' => 7, 'hard_acceleration' => 7,
        'panic' => 8, 'sos' => 8, 'alarm' => 8, 'panic_button' => 8,
        'battery_low' => 9, 'low_battery' => 9,
        'power_disconnected' => 10, 'ext_power_off' => 10,
        'excessive_idle' => 11, 'long_idle' => 11,
        'accident' => 12, 'crash' => 12,
        'passenger_panic' => 13, 'passenger_panic_button' => 13,
        'tamper' => 14, 'tampering' => 14, 'antenna_cut' => 14, 'tamper_detected' => 14,
        'black_box' => 15, 'black_box_data' => 15,
        'fuel' => 16, 'fuel_data' => 16, 'fuel_report' => 16,
        'invalid_scan' => 17, 'invalid_ibutton' => 17,
        'engine_start' => 18, 'power ignition on' => 18,
        'engine_stop' => 19, 'power ignition off' => 19,
        'boundary_enter' => 20, 'geofence_in' => 20, 'enter_zone' => 20,
        'boundary_exit' => 21, 'geofence_out' => 21, 'leave_zone' => 21,
        'checkpoint_enter' => 22, 'enter_checkpoint' => 22,
        'checkpoint_exit' => 23, 'leave_checkpoint' => 23,
        'ibutton' => 24, 'driver_scan' => 24, 'ibutton_scan' => 24,
    ];

    /**
     * Map Wialon parameters + previous state to an LATRA activity.
     *
     * @param array $params        Wialon message parameters (raw_data.p)
     * @param array $prevState     vehicle_state row (may be empty)
     * @param array $ctx           ['speed' => kmh, 'lat'=>, 'lng'=>, 'activity_enabled'=> [id=>bool], 'speed_limit'=>]
     * @return array               ['activity_id' => int, 'reason' => string, 'event_type' => string|null]
     */
    public static function detect(array $params, array $prevState = [], array $ctx = []): array {
        $speed = isset($ctx['speed']) ? (float)$ctx['speed'] : (float)($params['speed'] ?? 0);
        $activityEnabled = $ctx['activity_enabled'] ?? [];

        $check = static function (int $id, string $reason, ?string $eventType = null) use ($activityEnabled) {
            // If activity mapping is defined and disabled, do not fire it.
            if ($activityEnabled && isset($activityEnabled[$id]) && !$activityEnabled[$id]) {
                return null;
            }
            return ['activity_id' => $id, 'reason' => $reason, 'event_type' => $eventType];
        };

        // 1. Event-name based detection (explicit Wialon event flags / notifications).
        $eventName = strtolower((string)($params['event'] ?? ''));
        if ($eventName) {
            foreach (self::EVENT_MAP as $key => $activityId) {
                if (strpos($eventName, $key) !== false) {
                    $hit = $check($activityId, "Wialon event '$eventName'", $key);
                    if ($hit) return $hit;
                }
            }
        }

        // 2. Panic buttons (emergency) — explicit params.
        if (self::flagOn($params, ['panic', 'sos', 'alarm', 'io_104'])) {
            $hit = $check(self::PANIC_DRIVER, 'Panic button (driver) triggered', 'panic_driver');
            if ($hit) return $hit;
        }
        if (self::flagOn($params, ['passenger_panic', 'io_105'])) {
            $hit = $check(self::PANIC_PASSENGER, 'Panic button (passenger) triggered', 'panic_passenger');
            if ($hit) return $hit;
        }

        // 3. Speed-limit (overspeed) detection → emergency event (activity 4).
        $limit = (float)($ctx['speed_limit'] ?? 80);
        if ($speed > $limit && $speed > 0) {
            $hit = $check(self::SPEEDING, "Speed $speed exceeds limit $limit km/h", 'overspeed');
            if ($hit) return $hit;
        }

        // 4. Harsh driving (acceleration-based) via g-force params if present.
        $gForce = self::gForce($params);
        if ($gForce !== null) {
            if ($gForce < -0.45) { $hit = $check(self::HASH_BRAKING, "Braking g-force $gForce", 'harsh_braking'); if ($hit) return $hit; }
            if ($gForce > 0.45)  { $hit = $check(self::HASH_ACCEL, "Acceleration g-force $gForce", 'harsh_accel'); if ($hit) return $hit; }
            if (abs($gForce) > 0.45 && !in_array(($hit['activity_id'] ?? 0), [5,7], true)) {
                $hit = $check(self::HASH_TURNING, "Lateral g-force $gForce", 'harsh_turning');
                if ($hit) return $hit;
            }
        }

        // 5. Engine state via params (differs from generic movement logging).
        // Prefer the per-vehicle Wialon engine sensor param when configured.
        $ignition = self::engineState($params, $ctx['engine_param'] ?? null);
        $prevEngine = $prevState['engine_status'] ?? null;
        if ($ignition !== null) {
            $newState = $ignition ? 'on' : 'off';
            if ($newState === 'on' && $prevEngine === 'off') {
                $hit = $check(self::ENGINE_ON, 'Ignition turned ON', 'engine_on'); if ($hit) return $hit;
            }
            if ($newState === 'off' && $prevEngine === 'on') {
                $hit = $check(self::ENGINE_OFF, 'Ignition turned OFF', 'engine_off'); if ($hit) return $hit;
            }
        }

        // 6. Device health events.
        if (self::flagOn($params, ['battery_low', 'low_battery'])) {
            $hit = $check(self::BATTERY_LOW, 'Internal battery low', 'battery_low'); if ($hit) return $hit;
        }
        // External power disconnected: fire ONLY on a real loss. A truthy mention
        // flag counts; otherwise we rely on external voltage actually being absent
        // (io_66 / pwr_ext). (Always-present sensor values like io_86 must NOT be
        // treated as a power-loss flag — that was spamming activity 10.)
        $powerFlag = self::flagOn($params, ['power_disconnected', 'ext_power_off', 'io_66_power_loss']);
        if ($powerFlag === true) {
            $hit = $check(self::POWER_DISC, 'External power disconnected (flag)', 'power_disconnected'); if ($hit) return $hit;
        } elseif ($powerFlag === null && self::externalPowerState($params) === false) {
            $hit = $check(self::POWER_DISC, 'External power disconnected (voltage lost)', 'power_disconnected'); if ($hit) return $hit;
        }
        if (self::flagOn($params, ['tamper', 'tampering', 'antenna_cut', 'io_8'])) {
            $hit = $check(self::TAMPERING, 'Device tampering detected', 'tampering'); if ($hit) return $hit;
        }

        // 7. iButton / driver scan.
        if (isset($params['pass']) || isset($params['tco_driver1_id']) || self::flagOn($params, ['ibutton', 'driver_scan'])) {
            $hit = $check(self::IBUTTON_REGULAR, 'iButton driver scan', 'ibutton'); if ($hit) return $hit;
        }
        if (isset($params['invalid_scan']) || self::flagOn($params, ['invalid_ibutton', 'io_123'])) {
            $hit = $check(self::INVALID_SCAN, 'Invalid iButton scan', 'invalid_scan'); if ($hit) return $hit;
        }

        // 8. Fuel data.
        if (isset($params['fuel']) || isset($params['fuel_level']) || self::flagOn($params, ['fuel_report'])) {
            $hit = $check(self::FUEL_REPORT, 'Fuel data report', 'fuel'); if ($hit) return $hit;
        }

        // 9. Black box / accident.
        if (self::flagOn($params, ['black_box', 'io_335'])) {
            $hit = $check(self::BLACK_BOX, 'Black box data logging', 'black_box'); if ($hit) return $hit;
        }
        if (self::flagOn($params, ['accident', 'crash', 'io_233'])) {
            $hit = $check(self::ACCIDENT, 'Accident detected', 'accident'); if ($hit) return $hit;
        }

        // 10. Geofence enter/leave — requires geofence context in $ctx.
        if (!empty($ctx['geofence']) && ($ctx['geofence']['relation'] ?? null)) {
            $rel = $ctx['geofence']['relation']; // 'inside' | 'outside' | null
            $prevInside = (bool)($prevState['last_geofence_inside'] ?? false);
            $gType = $ctx['geofence']['type'] ?? 'boundary';
            if ($rel === 'inside' && !$prevInside) {
                $hit = $check($gType === 'checkpoint' ? self::ENTER_CHECKPOINT : self::ENTER_BOUNDARY,
                              'Entered ' . $gType, $gType === 'checkpoint' ? 'enter_checkpoint' : 'enter_boundary');
                if ($hit) return $hit;
            } elseif ($rel === 'outside' && $prevInside) {
                $hit = $check($gType === 'checkpoint' ? self::LEAVE_CHECKPOINT : self::LEAVE_BOUNDARY,
                              'Left ' . $gType, $gType === 'checkpoint' ? 'leave_checkpoint' : 'leave_boundary');
                if ($hit) return $hit;
            }
        }

        // Default: regular movement/logging.
        return $check(self::MOVEMENT, 'Movement/Logging', 'movement') ?? ['activity_id' => self::MOVEMENT, 'reason' => 'Movement/Logging', 'event_type' => 'movement'];
    }

    /**
     * Track a stationary period and emit one excessive-idle event after one
     * hour at exactly 0 km/h. The period ends after movement and engine-on
     * have both been observed.
     *
     * @return array{activity_id:int|null,state:array<string,int>}
     */
    public static function excessiveIdle(
        array $state,
        ?float $speed,
        int $messageTime,
        ?bool $engineOn,
        bool $enabled,
        int $thresholdSeconds = self::EXCESSIVE_IDLE_SECONDS
    ): array {
        $startedAt = (int) ($state['idle_started_at'] ?? 0);
        $reported = (int) ($state['idle_reported'] ?? 0) === 1;
        $moved = (int) ($state['idle_moved'] ?? 0) === 1;
        $engineSeen = (int) ($state['idle_engine_on'] ?? 0) === 1;
        $messageTime = $messageTime > 0 ? $messageTime : time();

        if ($speed > 0) {
            $moved = true;
        }
        if ($engineOn === true) {
            $engineSeen = true;
        }

        if ($speed !== 0.0 && !$reported) {
            $startedAt = 0;
            $moved = false;
            $engineSeen = false;
        } elseif ($speed !== 0.0 && $reported) {
            // Preserve a reported idle period until engine-on is also seen.
            $startedAt = $startedAt ?: $messageTime;
        } elseif ($startedAt === 0) {
            $startedAt = $messageTime;
            $reported = false;
            $moved = false;
            $engineSeen = $engineOn === true;
        }

        $activityId = null;
        if ($speed === 0.0 && !$reported
            && ($messageTime - $startedAt) >= max(1, $thresholdSeconds)) {
            $reported = true;
            if ($enabled) {
                $activityId = self::EXCESSIVE_IDLE;
            }
        }

        if ($reported && $moved && $engineSeen) {
            $startedAt = 0;
            $reported = false;
            $moved = false;
            $engineSeen = false;
        }

        return [
            'activity_id' => $activityId,
            'state' => [
                'idle_started_at' => $startedAt,
                'idle_reported' => $reported ? 1 : 0,
                'idle_moved' => $moved ? 1 : 0,
                'idle_engine_on' => $engineSeen ? 1 : 0,
            ],
        ];
    }

    /**
     * Is the horizontal speed known and above the configured limit?
     * A stationary vehicle (speed 0) is never an overspeed, and an unknown
     * speed is never treated as one.
     */
    public static function isOverspeed(?float $speed, float $limit): bool {
        return $speed !== null && $speed > 0 && $speed > $limit;
    }

    /**
     * Determine current speed limit for a vehicle (config or global default).
     */
    public static function speedLimit(int $vehicleId): float {
        $row = Database::fetch("SELECT speed_limit_kmh FROM overspeed_config WHERE vehicle_id = ? AND is_enabled = 1", [$vehicleId]);
        if ($row) return (float)$row['speed_limit_kmh'];
        $global = Database::fetch("SELECT speed_limit_kmh FROM overspeed_config WHERE vehicle_id IS NULL AND is_enabled = 1 LIMIT 1");
        return $global ? (float)$global['speed_limit_kmh'] : 80.0;
    }

    /** Load activity enablement map for a vehicle from activity_mappings. */
    public static function enabledActivities(int $vehicleId): array {
        $rows = Database::fetchAll("SELECT activity_id, is_enabled FROM activity_mappings WHERE vehicle_id = ?", [$vehicleId]);
        $map = [];
        foreach ($rows as $r) $map[(int)$r['activity_id']] = (int)$r['is_enabled'] === 1;
        return $map;
    }

    /** Persist updated vehicle_state after detection. */
    public static function persistState(int $vehicleId, array $state): void {
        $prev = Database::fetch("SELECT * FROM vehicle_state WHERE vehicle_id = ?", [$vehicleId]);
        if ($prev) {
            Database::update('vehicle_state', $state, 'vehicle_id = ?', [$vehicleId]);
        } else {
            Database::insert('vehicle_state', array_merge(['vehicle_id' => $vehicleId], $state));
        }
    }

    public static function flagOn(array $params, array $keys): ?bool {
        foreach ($keys as $k) {
            if (strpos((string) $k, ':') !== false) {
                [$param, $bit] = explode(':', (string) $k, 2);
                if (array_key_exists($param, $params) && is_numeric($bit)) {
                    $v = (int) $params[$param];
                    return ((int) floor($v / (1 << (int) $bit))) % 2 === 1;
                }
                continue;
            }
            if (array_key_exists($k, $params)) {
                $v = $params[$k];
                return $v !== 0 && $v !== '0' && $v !== false;
            }
        }
        return null;
    }

    /**
     * Resolve engine ignition state for a message.
     *
     * When a per-vehicle engine sensor param is configured (from Wialon
     * sensor config, e.g. 'io_239' or a bitmask form 'tr_status:11'), it is
     * authoritative. Otherwise fall back to the common signal-name list.
     */
    public static function engineState(array $params, ?string $engineParam = null): ?bool {
        if (is_string($engineParam) && trim($engineParam) !== '') {
            $state = (bool)preg_match('/^[^:]+:\d+$/', trim($engineParam));
            if ($state) {
                return self::flagOn($params, [trim($engineParam)]);
            }
            if (array_key_exists(trim($engineParam), $params)) {
                $v = $params[trim($engineParam)];
                return $v !== 0 && $v !== '0' && $v !== false;
            }
        }
        return self::flagOn($params, ['ignition', 'io_5', 'io_239', 'ignit', 'engine_on', 'io_69']);
    }

    /**
     * External-power presence derived from voltage readouts.
     * Returns true when external power is present, false when it is absent,
     * or null when no voltage source is reported (unknown).
     */
    public static function externalPowerState(array $params): ?bool {
        foreach (['io_66', 'pwr_ext', 'ext_voltage', 'io_66_power'] as $k) {
            if (isset($params[$k]) && is_numeric($params[$k])) {
                return (float) $params[$k] > 0;
            }
        }
        return null;
    }

    /** Best-effort g-force extraction from Wialon params (varies by device). */
    private static function gForce(array $params): ?float {
        foreach (['ho'] as $k) { if (isset($params[$k]) && is_numeric($params[$k])) return (float)$params[$k]; }
        // io_ g-force params in some Teltonika units
        foreach (['io_79', 'io_80'] as $k) {
            if (isset($params[$k]) && is_numeric($params[$k])) {
                $raw = (int)$params[$k];
                // Some units store signed value in uint32 two's complement
                if ($raw > 0x7FFFFFFF) $raw -= 0x100000000;
                return $raw / 1000.0; // milli-g to g
            }
        }
        return null;
    }
}
