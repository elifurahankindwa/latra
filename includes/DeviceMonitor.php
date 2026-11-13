<?php
/**
 * Device status monitor.
 */
require_once __DIR__ . '/autoload.php';

class DeviceMonitor {

    // Offline after 15 minutes without contact.
    const OFFLINE_SECONDS = 900;

    // Low battery warning level.
    const BATTERY_LOW_MV = \Latra\Domain\Ingestion\DeviceStatusPolicy::BATTERY_LOW_MV;

    // Low battery percent.
    const BATTERY_LOW_PCT = \Latra\Domain\Ingestion\DeviceStatusPolicy::BATTERY_LOW_PCT;

    // External power low level.
    const EXT_POWER_LOW_MV = \Latra\Domain\Ingestion\DeviceStatusPolicy::EXT_POWER_LOW_MV;

    // Update a device from the latest message.
    public static function updateFromMessage(int $vehicleId, array $params, int $commTime): void {
        $useCase = new \Latra\Application\DeviceStatus\UpdateDeviceStatus(
            new \Latra\Infrastructure\Persistence\LegacyDeviceStatusRepository(),
            new \Latra\Infrastructure\Ingestion\LegacyDeviceStatusEffects(),
            new \Latra\Infrastructure\Ingestion\LegacyDeviceStatusClock(),
        );
        $useCase->execute($vehicleId, $params, $commTime);
    }

    // Mark devices offline when they stop replying.
    public static function markOffline(): int {
        $now = time();
        $rows = Database::fetchAll(
            "SELECT d.vehicle_id FROM device_status d
             WHERE d.is_online = 1 AND d.last_comm_time IS NOT NULL
               AND d.last_comm_time < ? - " . self::OFFLINE_SECONDS,
            [$now]
        );
        $marked = 0;
        foreach ($rows as $r) {
            Database::update('device_status', ['is_online' => 0, 'status_changed_at' => date(DATE_FORMAT)], 'vehicle_id = ?', [$r['vehicle_id']]);
            self::event((int)$r['vehicle_id'], 'DEVICE_OFFLINE', 'Device lost connection (heartbeat expired)');
            $marked++;
        }
        return $marked;
    }

    // Fleet summary for the dashboard.
    public static function summary(): array {
        $rows = Database::fetchAll(
            "SELECT
                SUM(is_online) AS online,
                SUM(internal_battery_low) AS battery_low,
                SUM(external_power_disconnected) AS power_disconnected,
                SUM(tampered) AS tampered,
                COUNT(*) AS total
             FROM device_status"
        );
        $s = $rows[0] ?? [];
        return [
            'online' => (int)($s['online'] ?? 0),
            'battery_low' => (int)($s['battery_low'] ?? 0),
            'power_disconnected' => (int)($s['power_disconnected'] ?? 0),
            'tampered' => (int)($s['tampered'] ?? 0),
            'total' => (int)($s['total'] ?? 0),
        ];
    }

    // List devices with their status.
    public static function statusList(int $limit = 200): array {
        return Database::fetchAll(
            "SELECT d.*, v.vehicle_reg_no, v.imei
             FROM device_status d JOIN vehicles v ON v.id = d.vehicle_id
             ORDER BY d.is_online DESC, d.last_comm_at DESC LIMIT $limit"
        );
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private static function event(int $vehicleId, string $type, string $detail): void {
        (new \Latra\Infrastructure\Ingestion\LegacyDeviceStatusEffects())
            ->recordTransition($vehicleId, $type, $detail);
    }

}
