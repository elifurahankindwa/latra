<?php
/**
 * Compatibility adapter for activity detection and vehicle-state persistence.
 */
use Latra\Domain\Ingestion\ActivityDetectionPolicy;

require_once __DIR__ . '/autoload.php';

class EventDetector
{
    public const MOVEMENT = ActivityDetectionPolicy::MOVEMENT;
    public const ENGINE_ON = ActivityDetectionPolicy::ENGINE_ON;
    public const ENGINE_OFF = ActivityDetectionPolicy::ENGINE_OFF;
    public const SPEEDING = ActivityDetectionPolicy::SPEEDING;
    public const HASH_BRAKING = ActivityDetectionPolicy::HASH_BRAKING;
    public const HASH_TURNING = ActivityDetectionPolicy::HASH_TURNING;
    public const HASH_ACCEL = ActivityDetectionPolicy::HASH_ACCEL;
    public const PANIC_DRIVER = ActivityDetectionPolicy::PANIC_DRIVER;
    public const BATTERY_LOW = ActivityDetectionPolicy::BATTERY_LOW;
    public const POWER_DISC = ActivityDetectionPolicy::POWER_DISC;
    public const EXCESSIVE_IDLE = ActivityDetectionPolicy::EXCESSIVE_IDLE;
    public const ACCIDENT = ActivityDetectionPolicy::ACCIDENT;
    public const PANIC_PASSENGER = ActivityDetectionPolicy::PANIC_PASSENGER;
    public const TAMPERING = ActivityDetectionPolicy::TAMPERING;
    public const BLACK_BOX = ActivityDetectionPolicy::BLACK_BOX;
    public const FUEL_REPORT = ActivityDetectionPolicy::FUEL_REPORT;
    public const INVALID_SCAN = ActivityDetectionPolicy::INVALID_SCAN;
    public const ENGINE_START = ActivityDetectionPolicy::ENGINE_START;
    public const ENGINE_STOP = ActivityDetectionPolicy::ENGINE_STOP;
    public const ENTER_BOUNDARY = ActivityDetectionPolicy::ENTER_BOUNDARY;
    public const LEAVE_BOUNDARY = ActivityDetectionPolicy::LEAVE_BOUNDARY;
    public const ENTER_CHECKPOINT = ActivityDetectionPolicy::ENTER_CHECKPOINT;
    public const LEAVE_CHECKPOINT = ActivityDetectionPolicy::LEAVE_CHECKPOINT;
    public const IBUTTON_REGULAR = ActivityDetectionPolicy::IBUTTON_REGULAR;
    public const EVENT_MAP = ActivityDetectionPolicy::EVENT_MAP;

    public static function detect(array $params, array $previousState = [], array $context = []): array
    {
        return ActivityDetectionPolicy::detect($params, $previousState, $context);
    }

    public static function flagOn(array $params, array $keys): ?bool
    {
        return ActivityDetectionPolicy::flagOn($params, $keys);
    }

    public static function engineState(array $params, ?string $engineParam = null): ?bool
    {
        return ActivityDetectionPolicy::engineState($params, $engineParam);
    }

    public static function externalPowerState(array $params): ?bool
    {
        return ActivityDetectionPolicy::externalPowerState($params);
    }

    public static function speedLimit(int $vehicleId): float
    {
        $row = Database::fetch(
            'SELECT speed_limit_kmh FROM overspeed_config WHERE vehicle_id = ? AND is_enabled = 1',
            [$vehicleId],
        );
        if ($row) {
            return (float) $row['speed_limit_kmh'];
        }
        $global = Database::fetch(
            'SELECT speed_limit_kmh FROM overspeed_config WHERE vehicle_id IS NULL AND is_enabled = 1 LIMIT 1',
        );
        return $global ? (float) $global['speed_limit_kmh'] : 80.0;
    }

    public static function enabledActivities(int $vehicleId): array
    {
        $rows = Database::fetchAll(
            'SELECT activity_id, is_enabled FROM activity_mappings WHERE vehicle_id = ?',
            [$vehicleId],
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['activity_id']] = (int) $row['is_enabled'] === 1;
        }
        return $map;
    }

    public static function persistState(int $vehicleId, array $state): void
    {
        $previous = Database::fetch('SELECT * FROM vehicle_state WHERE vehicle_id = ?', [$vehicleId]);
        if ($previous) {
            Database::update('vehicle_state', $state, 'vehicle_id = ?', [$vehicleId]);
        } else {
            Database::insert('vehicle_state', array_merge(['vehicle_id' => $vehicleId], $state));
        }
    }
}
