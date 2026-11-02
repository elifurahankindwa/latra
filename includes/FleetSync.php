<?php
/**
 * Fleet sync.
 */
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
                 SELECT ?, id, IF(id = ?, 1, 0) FROM activity_definitions",
                [$vid, EventDetector::SPEEDING]
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

    /**
     * Poll many vehicles using ONE Wialon session.
     *
     * jobPoll() used to call pollVehicle() per vehicle, which logged in and out
     * of Wialon 405 times per cycle. Sharing the session removes that N+1 and
     * the per-vehicle window is derived from the stored high-water mark.
     *
     * @param int $maxVehicles  Stop after this many vehicles (0 = all).
     * @param int $timeBudget   Soft wall-clock limit in seconds (0 = none).
     */
    public static function pollMany(array $vehicles, int $windowSeconds = 180, int $limit = 80, int $maxVehicles = 0, int $timeBudget = 0): array {
        $out = ['inserted' => 0, 'rejected' => 0, 'skipped' => 0, 'processed' => 0, 'remaining' => 0];
        if (!$vehicles) {
            return $out;
        }
        $wialon = self::connector();
        if (!$wialon->login()) {
            throw new RuntimeException('Wialon login failed');
        }
        $started = microtime(true);
        $n = 0;
        try {
            foreach ($vehicles as $v) {
                if ($maxVehicles > 0 && $n >= $maxVehicles) break;
                if ($timeBudget > 0 && (microtime(true) - $started) > $timeBudget) break;
                try {
                    $to = time();
                    $last = Database::fetch(
                        "SELECT MAX(wialon_msg_time) AS t FROM message_log WHERE vehicle_id = ?",
                        [$v['id']]
                    );
                    $from = $last && $last['t'] ? ((int) $last['t'] + 1) : ($to - max(180, $windowSeconds));
                    $messages = $wialon->getMessages((int) $v['wialon_unit_id'], $from, $to, $limit);
                    if ($messages) {
                        $r = IngestPipeline::ingestVehicle($v, $messages);
                        $out['inserted'] += $r['inserted'];
                        $out['rejected'] += $r['rejected'];
                        $out['skipped']  += $r['skipped'];
                    }
                } catch (Throwable $e) {
                    Logger::warn('poll.vehicle_failed', ['vehicle' => $v['id'], 'error' => $e->getMessage()]);
                    $out['skipped']++;
                }
                $n++;
            }
        } finally {
            $wialon->logout();
        }
        $out['processed'] = $n;
        $out['remaining'] = max(0, count($vehicles) - $n);
        return $out;
    }

    /**
     * Bulk-fetch stored telemetry for many vehicles using one Wialon session.
     * Behaviour mirrors the legacy api.php "fetch_messages" action: rows are
     * stored/validated but no LATRA submission is triggered.
     */
    public static function fetchMany(array $vehicleIds, int $from, int $to, int $limit = 100, int $maxVehicles = 0, int $timeBudget = 0): array {
        $out = ['inserted' => 0, 'rejected' => 0, 'failed' => 0, 'processed' => 0, 'remaining' => 0];
        if (!$vehicleIds) {
            return $out;
        }
        $wialon = self::connector();
        if (!$wialon->login()) {
            throw new RuntimeException('Wialon login failed');
        }
        $started = microtime(true);
        $n = 0;
        try {
            foreach ($vehicleIds as $vid) {
                if ($maxVehicles > 0 && $n >= $maxVehicles) break;
                if ($timeBudget > 0 && (microtime(true) - $started) > $timeBudget) break;
                $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vid]);
                if (!$vehicle) {
                    $out['failed']++;
                    $n++;
                    continue;
                }
                try {
                    $messages = $wialon->getMessages((int) $vehicle['wialon_unit_id'], $from, $to, $limit);
                    $r = self::storeFetched($vehicle, $messages);
                    $out['inserted'] += $r['inserted'];
                    $out['rejected'] += $r['rejected'];
                } catch (Throwable $e) {
                    Logger::warn('fetch.vehicle_failed', ['vehicle' => $vid, 'error' => $e->getMessage()]);
                    $out['failed']++;
                }
                $n++;
            }
        } finally {
            $wialon->logout();
        }
        $out['processed'] = $n;
        $out['remaining'] = max(0, count($vehicleIds) - $n);
        $out['remaining_ids'] = array_slice($vehicleIds, $n);
        return $out;
    }

    /**
     * Store a Wialon message batch without queueing LATRA delivery (legacy
     * "fetch messages" semantics). Kept separate from IngestPipeline so the
     * manual pull keeps its original store-only behaviour.
     */
    private static function storeFetched(array $vehicle, array $messages): array {
        $vehicleId = (int) $vehicle['id'];
        $inserted = 0;
        $rejected = 0;
        if (!$messages) {
            return compact('inserted', 'rejected');
        }
        $prevState = Database::fetch("SELECT * FROM vehicle_state WHERE vehicle_id = ?", [$vehicleId]) ?? [];
        $enabledActs = EventDetector::enabledActivities($vehicleId);
        $speedLimit = EventDetector::speedLimit($vehicleId);

        foreach ($messages as $msg) {
            $pos = $msg['pos'] ?? [];
            $params = $msg['p'] ?? [];
            $speed = isset($pos['s']) ? round((float) $pos['s'], 2) : null;
            $detected = EventDetector::detect($params, $prevState, [
                'speed' => $speed ?? 0,
                'activity_enabled' => $enabledActs,
                'speed_limit' => $speedLimit,
                'engine_param' => $vehicle['engine_param'] ?? null,
            ]);
            $activityId = $detected['activity_id'];

            $ignited = EventDetector::engineState($params, $vehicle['engine_param'] ?? null);
            if ($ignited !== null) {
                $prevState['engine_status'] = $ignited ? 'on' : 'off';
            }
            $addonInfo = json_encode(LatraMapper::buildAddonInfo($activityId, $params) ?? []);
            $fuelInfo = $activityId === 16 ? json_encode(LatraMapper::buildFuelInfo($params)) : null;

            $emergencyType = EmergencyHandler::mapEmergencyType($activityId);
            if ($emergencyType) {
                $activeEvt = EmergencyHandler::ensureActiveEvent($vehicleId, $emergencyType, $detected['reason'], $speed, $activityId);
                if ($activeEvt) {
                    EmergencyHandler::bufferRecord($activeEvt, $vehicleId, $msg);
                }
            }

            DeviceMonitor::updateFromMessage($vehicleId, $params, $msg['rt'] ?? time());

            $msgData = [
                'vehicle_id' => $vehicleId,
                'wialon_msg_time' => $msg['t'] ?? time(),
                'raw_data' => json_encode($msg),
                'lat' => isset($pos['y']) ? round((float) $pos['y'], 8) : null,
                'lng' => isset($pos['x']) ? round((float) $pos['x'], 8) : null,
                'altitude' => $pos['z'] ?? null,
                'speed' => $speed,
                'bearing' => $pos['c'] ?? null,
                'satellite_count' => $pos['sc'] ?? null,
                'hdop' => $params['hdop'] ?? null,
                'd2d3' => isset($pos['z']) && $pos['z'] !== 0 ? 3 : 2,
                'rssi' => $params['rssi'] ?? $params['gsm'] ?? null,
                'lac' => $params['lac'] ?? $params['gsm_lac'] ?? $params['lbs_lac'] ?? null,
                'cell_id' => $params['cell_id'] ?? $params['cellid'] ?? $params['cid'] ?? $params['gsm_cellid'] ?? null,
                'mcc' => $params['mcc'] ?? null,
                'mgs_id' => $msg['t'] ?? null,
                'activity_id' => $activityId,
                'addon_info' => $addonInfo,
                'fuel_info' => $fuelInfo,
                'latra_status' => 'pending',
                'data_received_at' => $msg['rt'] ?? time(),
            ];

            $validation = GpsValidator::validate($msgData + ['imei' => $vehicle['imei'], 'vehicle_reg_no' => $vehicle['vehicle_reg_no']]);
            $msgData['validation_status'] = GpsValidator::statusOf($validation);
            if (!$validation['valid']) {
                GpsValidator::recordErrors($msgData, $validation['errors']);
                $msgData['latra_status'] = 'rejected';
                $rejected++;
            }
            if (Automation::storeMessage($msgData)) {
                if ($validation['valid']) {
                    $inserted++;
                }
            }
        }
        EmergencyHandler::closeEndedEvent($vehicleId, $messages);
        return compact('inserted', 'rejected');
    }

    /**
     * Bulk LATRA push: send the latest stored telemetry for each vehicle under
     * one activity. Mirrors the api.php "bulk_push_activity" action.
     *
     * @param int $maxVehicles Stop after this many vehicles (0 = all).
     * @param int $timeBudget  Soft wall-clock limit in seconds (0 = none).
     */
    public static function pushMany(array $vehicleIds, int $activityId, int $maxVehicles = 0, int $timeBudget = 0): array {
        $out = ['sent' => 0, 'failed' => 0, 'processed' => 0, 'remaining' => 0];
        if (!$vehicleIds || $activityId <= 0) {
            return $out;
        }
        $started = microtime(true);
        $n = 0;
        foreach ($vehicleIds as $vid) {
            if ($maxVehicles > 0 && $n >= $maxVehicles) break;
            if ($timeBudget > 0 && (microtime(true) - $started) > $timeBudget) break;
            $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vid]);
            if (!$vehicle) {
                $out['failed']++;
                $n++;
                continue;
            }
            try {
                $latest = Database::fetch("SELECT * FROM message_log WHERE vehicle_id = ? ORDER BY wialon_msg_time DESC LIMIT 1", [$vid]);
                $source = $latest ?: $vehicle;
                $raw = $latest ? json_decode($latest['raw_data'] ?? '{}', true) : [];
                $params = is_array($raw) && isset($raw['p']) && is_array($raw['p']) ? $raw['p'] : [];
                $addonCustom = [];
                if ($activityId === EventDetector::ENGINE_OFF) {
                    $addonCustom = LatraMapper::buildTripStats($vid, (int) ($source['wialon_msg_time'] ?? time()));
                }
                $addonInfo = LatraMapper::buildAddonInfo($activityId, $params, $addonCustom) ?? [];
                $fuelInfo = $activityId === 16 ? LatraMapper::buildFuelInfo($params) : [];
                $payload = LatraMapper::buildPayload($vehicle, $source, $activityId, $addonInfo, $fuelInfo);
                $idemKey = 'v' . $vid . '-t' . ($source['wialon_msg_time'] ?? time()) . '-a' . $activityId;
                $result = LatraAdapter::submit($payload, $idemKey);
                // No source message yet for this vehicle: push_log.message_log_id
                // is a foreign key, so it must stay NULL instead of 0.
                LatraAdapter::recordPush(['vehicle_id' => $vid, 'id' => $latest ? (int) $latest['id'] : null], $activityId, $result, '', 1);
                if (!empty($result['ok']) || !empty($result['skipped'])) {
                    $out['sent']++;
                } else {
                    $out['failed']++;
                }
            } catch (Throwable $e) {
                $out['failed']++;
                Logger::warn('push.vehicle_failed', ['vehicle' => $vid, 'error' => $e->getMessage()]);
            }
            $n++;
        }
        $out['processed'] = $n;
        $out['remaining'] = max(0, count($vehicleIds) - $n);
        $out['remaining_ids'] = array_slice($vehicleIds, $n);
        return $out;
    }
}
