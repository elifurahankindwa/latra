<?php
/**
 * Fleet sync.
 */
use Latra\Application\Collection\RelayPayloadMapper;

class FleetSync {
    public static function connector(): WialonAPI {
        return new WialonAPI(Vault::wialonUrl() ?: WIALON_BASE_URL, Vault::wialonToken() ?: WIALON_TOKEN);
    }

    public static function syncAll(): array {
        $wialon = self::connector();
        if (!$wialon->login()) {
            throw new RuntimeException('Wialon login failed');
        }
        $units = $wialon->getUnits();
        $synced = 0;
        $updated = 0;
        foreach ($units as $unit) {
            $mapped = LatraMapper::mapVehicle($unit);
            if (empty($mapped['imei'])) {
                $mapped['imei'] = null;
            }
            $exists = Database::fetch("SELECT id FROM vehicles WHERE wialon_unit_id = ?", [$mapped['wialon_unit_id']]);
            $row = array_merge($mapped, [
                'last_sync_at' => date(DATE_FORMAT),
                'is_active' => 1,
                'engine_param' => $wialon->getEngineSensorParam((int) $mapped['wialon_unit_id']),
            ]);
            if ($exists) {
                Database::update('vehicles', $row, 'id = ?', [$exists['id']]);
                $vid = (int) $exists['id'];
                $updated++;
            } else {
                $vid = Database::insert('vehicles', $row);
                $synced++;
            }
            Database::query(
                "INSERT IGNORE INTO activity_mappings (vehicle_id, activity_id, is_enabled)
                 SELECT ?, id, 0 FROM activity_definitions",
                [$vid]
            );
        }
        $wialon->logout();
        Logger::info('fleet.sync', ['new' => $synced, 'updated' => $updated]);
        return compact('synced', 'updated');
    }

    public static function syncOne(int $vehicleId): void {
        $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vehicleId]);
        if (!$vehicle) {
            throw new RuntimeException('Vehicle not found');
        }
        $wialon = self::connector();
        if (!$wialon->login()) {
            throw new RuntimeException('Wialon login failed');
        }
        $unit = $wialon->getUnitData((int) $vehicle['wialon_unit_id']);
        if ($unit) {
            $mapped = LatraMapper::mapVehicle($unit);
            Database::update('vehicles', array_merge($mapped, [
                'last_sync_at' => date(DATE_FORMAT),
                'is_active' => 1,
                'engine_param' => $wialon->getEngineSensorParam((int) $vehicle['wialon_unit_id']),
            ]), 'id = ?', [$vehicleId]);
        }
        $wialon->logout();
    }

    public static function pollVehicle(array $vehicle, int $windowSeconds = 180, int $limit = 50): array {
        if (defined('DELIVERY_ARCHITECTURE') && DELIVERY_ARCHITECTURE === 'relay') {
            return RelayRuntime::poll($vehicle, $windowSeconds, $limit);
        }
        $wialon = self::connector();
        if (!$wialon->login()) {
            throw new RuntimeException('Wialon login failed');
        }
        $to = time();
        $last = Database::fetch(
            "SELECT MAX(wialon_msg_time) AS t FROM message_log WHERE vehicle_id = ?",
            [$vehicle['id']]
        );
        $from = $last && $last['t'] ? ((int) $last['t'] + 1) : ($to - max(180, $windowSeconds));
        $messages = $wialon->getMessages((int) $vehicle['wialon_unit_id'], $from, $to, $limit);
        $wialon->logout();
        return IngestPipeline::ingestVehicle($vehicle, $messages);
    }

    /** Compatibility wrapper returning the queued count from a complete relay window. */
    public static function collectRelayVehicle(array $vehicle, DurableRelay $relay, RelayPayloadMapper $mapper,
        int $from, int $to, int $pageSize, int $maxRecords, int $maxBytes, ?WialonAPI $wialon = null, string $stream = ''): int {
        return self::collectRelayWindow($vehicle, $relay, $mapper, $from, $to, $pageSize, $maxRecords, $maxBytes, $wialon, $stream)['queued'];
    }

    public static function collectRelayWindow(array $vehicle, DurableRelay $relay, RelayPayloadMapper $mapper,
        int $from, int $to, int $pageSize, int $maxRecords, int $maxBytes, ?WialonAPI $wialon = null, string $stream = ''): array {
        $source = new \Latra\Infrastructure\Wialon\WialonMessageWindowSource($wialon ?? self::connector());
        $useCase = new \Latra\Application\Collection\CollectRelayWindow($source, $relay);
        return $useCase->collect(
            (int) $vehicle['wialon_unit_id'],
            'wialon:' . $vehicle['wialon_unit_id'] . $stream,
            $from,
            $to,
            $pageSize,
            $maxRecords,
            $maxBytes,
            [$mapper, 'map'],
        );
    }
}
