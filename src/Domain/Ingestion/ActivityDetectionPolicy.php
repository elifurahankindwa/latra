<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

final class ActivityDetectionPolicy
{
    public const MOVEMENT = 1;
    public const ENGINE_ON = 2;
    public const ENGINE_OFF = 3;
    public const SPEEDING = 4;
    public const HASH_BRAKING = 5;
    public const HASH_TURNING = 6;
    public const HASH_ACCEL = 7;
    public const PANIC_DRIVER = 8;
    public const BATTERY_LOW = 9;
    public const POWER_DISC = 10;
    public const EXCESSIVE_IDLE = 11;
    public const ACCIDENT = 12;
    public const PANIC_PASSENGER = 13;
    public const TAMPERING = 14;
    public const BLACK_BOX = 15;
    public const FUEL_REPORT = 16;
    public const INVALID_SCAN = 17;
    public const ENGINE_START = 18;
    public const ENGINE_STOP = 19;
    public const ENTER_BOUNDARY = 20;
    public const LEAVE_BOUNDARY = 21;
    public const ENTER_CHECKPOINT = 22;
    public const LEAVE_CHECKPOINT = 23;
    public const IBUTTON_REGULAR = 24;

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

    public static function detect(array $params, array $previousState = [], array $context = []): array
    {
        $speed = isset($context['speed']) ? (float) $context['speed'] : (float) ($params['speed'] ?? 0);
        $enabled = $context['activity_enabled'] ?? [];
        $check = static function (int $id, string $reason, ?string $eventType = null) use ($enabled): ?array {
            if ($enabled && isset($enabled[$id]) && !$enabled[$id]) {
                return null;
            }
            return ['activity_id' => $id, 'reason' => $reason, 'event_type' => $eventType];
        };

        $eventName = strtolower((string) ($params['event'] ?? ''));
        if ($eventName !== '') {
            foreach (self::EVENT_MAP as $key => $activityId) {
                if (strpos($eventName, $key) !== false) {
                    $hit = $check($activityId, "Wialon event '$eventName'", $key);
                    if ($hit !== null) {
                        return $hit;
                    }
                }
            }
        }

        if (self::flagOn($params, ['panic', 'sos', 'alarm', 'io_104'])) {
            $hit = $check(self::PANIC_DRIVER, 'Panic button (driver) triggered', 'panic_driver');
            if ($hit !== null) {
                return $hit;
            }
        }
        if (self::flagOn($params, ['passenger_panic', 'io_105'])) {
            $hit = $check(self::PANIC_PASSENGER, 'Panic button (passenger) triggered', 'panic_passenger');
            if ($hit !== null) {
                return $hit;
            }
        }

        $limit = (float) ($context['speed_limit'] ?? 80);
        if ($speed > $limit && $speed > 0) {
            $hit = $check(self::SPEEDING, "Speed $speed exceeds limit $limit km/h", 'overspeed');
            if ($hit !== null) {
                return $hit;
            }
        }

        $gForce = self::gForce($params);
        if ($gForce !== null) {
            if ($gForce < -0.45) {
                $hit = $check(self::HASH_BRAKING, "Braking g-force $gForce", 'harsh_braking');
                if ($hit !== null) {
                    return $hit;
                }
            }
            if ($gForce > 0.45) {
                $hit = $check(self::HASH_ACCEL, "Acceleration g-force $gForce", 'harsh_accel');
                if ($hit !== null) {
                    return $hit;
                }
            }
            if (abs($gForce) > 0.45 && !in_array(($hit['activity_id'] ?? 0), [5, 7], true)) {
                $hit = $check(self::HASH_TURNING, "Lateral g-force $gForce", 'harsh_turning');
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        $ignition = self::engineState($params, $context['engine_param'] ?? null);
        $previousEngine = $previousState['engine_status'] ?? null;
        if ($ignition !== null) {
            $newState = $ignition ? 'on' : 'off';
            if ($newState === 'on' && $previousEngine === 'off') {
                $hit = $check(self::ENGINE_ON, 'Ignition turned ON', 'engine_on');
                if ($hit !== null) {
                    return $hit;
                }
            }
            if ($newState === 'off' && $previousEngine === 'on') {
                $hit = $check(self::ENGINE_OFF, 'Ignition turned OFF', 'engine_off');
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        if (self::flagOn($params, ['battery_low', 'low_battery'])) {
            $hit = $check(self::BATTERY_LOW, 'Internal battery low', 'battery_low');
            if ($hit !== null) {
                return $hit;
            }
        }
        $powerFlag = self::flagOn($params, ['power_disconnected', 'ext_power_off', 'io_66_power_loss']);
        if ($powerFlag === true) {
            $hit = $check(self::POWER_DISC, 'External power disconnected (flag)', 'power_disconnected');
            if ($hit !== null) {
                return $hit;
            }
        } elseif ($powerFlag === null && self::externalPowerState($params) === false) {
            $hit = $check(self::POWER_DISC, 'External power disconnected (voltage lost)', 'power_disconnected');
            if ($hit !== null) {
                return $hit;
            }
        }
        if (self::flagOn($params, ['tamper', 'tampering', 'antenna_cut', 'io_8'])) {
            $hit = $check(self::TAMPERING, 'Device tampering detected', 'tampering');
            if ($hit !== null) {
                return $hit;
            }
        }

        if (isset($params['pass']) || isset($params['tco_driver1_id'])
            || self::flagOn($params, ['ibutton', 'driver_scan'])) {
            $hit = $check(self::IBUTTON_REGULAR, 'iButton driver scan', 'ibutton');
            if ($hit !== null) {
                return $hit;
            }
        }
        if (isset($params['invalid_scan']) || self::flagOn($params, ['invalid_ibutton', 'io_123'])) {
            $hit = $check(self::INVALID_SCAN, 'Invalid iButton scan', 'invalid_scan');
            if ($hit !== null) {
                return $hit;
            }
        }
        if (isset($params['fuel']) || isset($params['fuel_level']) || self::flagOn($params, ['fuel_report'])) {
            $hit = $check(self::FUEL_REPORT, 'Fuel data report', 'fuel');
            if ($hit !== null) {
                return $hit;
            }
        }
        if (self::flagOn($params, ['black_box', 'io_335'])) {
            $hit = $check(self::BLACK_BOX, 'Black box data logging', 'black_box');
            if ($hit !== null) {
                return $hit;
            }
        }
        if (self::flagOn($params, ['accident', 'crash', 'io_233'])) {
            $hit = $check(self::ACCIDENT, 'Accident detected', 'accident');
            if ($hit !== null) {
                return $hit;
            }
        }

        if (!empty($context['geofence']) && ($context['geofence']['relation'] ?? null)) {
            $relation = $context['geofence']['relation'];
            $wasInside = (bool) ($previousState['last_geofence_inside'] ?? false);
            $type = $context['geofence']['type'] ?? 'boundary';
            if ($relation === 'inside' && !$wasInside) {
                $id = $type === 'checkpoint' ? self::ENTER_CHECKPOINT : self::ENTER_BOUNDARY;
                $hit = $check($id, 'Entered ' . $type, $type === 'checkpoint' ? 'enter_checkpoint' : 'enter_boundary');
                if ($hit !== null) {
                    return $hit;
                }
            } elseif ($relation === 'outside' && $wasInside) {
                $id = $type === 'checkpoint' ? self::LEAVE_CHECKPOINT : self::LEAVE_BOUNDARY;
                $hit = $check($id, 'Left ' . $type, $type === 'checkpoint' ? 'leave_checkpoint' : 'leave_boundary');
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return $check(self::MOVEMENT, 'Movement/Logging', 'movement')
            ?? ['activity_id' => self::MOVEMENT, 'reason' => 'Movement/Logging', 'event_type' => 'movement'];
    }

    public static function flagOn(array $params, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (strpos((string) $key, ':') !== false) {
                [$parameter, $bit] = explode(':', (string) $key, 2);
                if (array_key_exists($parameter, $params) && is_numeric($bit)) {
                    $value = (int) $params[$parameter];
                    return ((int) floor($value / (1 << (int) $bit))) % 2 === 1;
                }
                continue;
            }
            if (array_key_exists($key, $params)) {
                $value = $params[$key];
                return $value !== 0 && $value !== '0' && $value !== false;
            }
        }
        return null;
    }

    public static function engineState(array $params, ?string $engineParam = null): ?bool
    {
        if (is_string($engineParam) && trim($engineParam) !== '') {
            $engineParam = trim($engineParam);
            if (preg_match('/^[^:]+:\d+$/', $engineParam)) {
                return self::flagOn($params, [$engineParam]);
            }
            if (array_key_exists($engineParam, $params)) {
                $value = $params[$engineParam];
                return $value !== 0 && $value !== '0' && $value !== false;
            }
        }
        return self::flagOn($params, ['ignition', 'io_5', 'io_239', 'ignit', 'engine_on', 'io_69']);
    }

    public static function externalPowerState(array $params): ?bool
    {
        foreach (['io_66', 'pwr_ext', 'ext_voltage', 'io_66_power'] as $key) {
            if (isset($params[$key]) && is_numeric($params[$key])) {
                return (float) $params[$key] > 0;
            }
        }
        return null;
    }

    private static function gForce(array $params): ?float
    {
        if (isset($params['ho']) && is_numeric($params['ho'])) {
            return (float) $params['ho'];
        }
        foreach (['io_79', 'io_80'] as $key) {
            if (isset($params[$key]) && is_numeric($params[$key])) {
                $raw = (int) $params[$key];
                if ($raw > 0x7FFFFFFF) {
                    $raw -= 0x100000000;
                }
                return $raw / 1000.0;
            }
        }
        return null;
    }
}
