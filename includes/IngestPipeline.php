<?php
/**
 * Ingest → normalise → validate → store → events → outbox.
 */
class IngestPipeline {
    public static function ingestVehicle(array $vehicle, array $messages): array {
        $inserted = 0;
        $rejected = 0;
        $skipped = 0;
        $vehicleId = (int) $vehicle['id'];
        $prevState = Database::fetch("SELECT * FROM vehicle_state WHERE vehicle_id = ?", [$vehicleId]) ?? [];
        $enabledActs = EventDetector::enabledActivities($vehicleId);
        $speedLimit = self::speedLimit($vehicle);
        $bucketActivities = Automation::enabledBucketActivities();
        $bucketVehicleIds = Automation::enabledBucketVehicleIds();
        $eventEngineOwnsActivities = Schema::config('scheduler_enabled', '1') === '1'
            && Schema::config('event_engine_enabled', '1') === '1';

        $consecutiveOver = (int) ($prevState['overspending_active'] ?? 0);
        $needSamples = Schema::configInt('overspeed_consecutive', 1);

        foreach ($messages as $raw) {
            $canonical = Normaliser::fromWialon($vehicle, $raw);
            $params = $canonical['canonical_params'];
            unset($canonical['canonical_params']);

            $detected = EventDetector::detect($params, $prevState, [
                'speed' => (float) ($canonical['speed'] ?? 0),
                'activity_enabled' => $enabledActs,
                'speed_limit' => $speedLimit,
                'engine_param' => $vehicle['engine_param'] ?? null,
            ]);
            $activityId = (int) $detected['activity_id'];

            $ignited = EventDetector::engineState($params, $vehicle['engine_param'] ?? null);
            $idle = EventDetector::excessiveIdle(
                $prevState,
                $canonical['speed'] !== null ? (float) $canonical['speed'] : null,
                (int) $canonical['wialon_msg_time'],
                $ignited,
                !isset($enabledActs[EventDetector::EXCESSIVE_IDLE])
                    || $enabledActs[EventDetector::EXCESSIVE_IDLE],
                Schema::configInt('excessive_idle_seconds', EventDetector::EXCESSIVE_IDLE_SECONDS)
            );
            if ($idle['activity_id'] !== null) {
                $activityId = $idle['activity_id'];
                $detected = [
                    'activity_id' => $activityId,
                    'reason' => 'Stationary for at least one hour',
                    'event_type' => 'excessive_idle',
                ];
            }

            // Maintain a rolling engine baseline across the whole batch so
            // transitions between consecutive messages are detected, and seed
            // the baseline on the first message that reports ignition state.
            if ($ignited !== null) {
                $prevState['engine_status'] = $ignited ? 'on' : 'off';
            }
            $prevState = array_merge($prevState, $idle['state']);

            if ($activityId === EventDetector::SPEEDING) {
                $consecutiveOver++;
            } else {
                $consecutiveOver = 0;
            }
            $overspeedReady = $consecutiveOver >= $needSamples;

            // Debounce gates only whether a high-frequency EMERGENCY event is
            // opened — it must never relabel the message's activity. Reclassifying
            // speeding as movement here was the bug that made LATRA label every
            // over-limit record "Movement/Logging": the stored activity became 1,
            // and the realtime EventEngine skips activity 1 entirely.
            $emergencyType = EmergencyHandler::mapEmergencyType($activityId);
            if ($emergencyType === 'overspeed' && !$overspeedReady) {
                $emergencyType = null;
            }

            $eventId = null;
            if ($emergencyType) {
                $activeEvt = EmergencyHandler::ensureActiveEvent(
                    $vehicleId,
                    $emergencyType,
                    $detected['reason'],
                    $canonical['speed'] !== null ? (float) $canonical['speed'] : null,
                    $activityId
                );
                if ($activeEvt) {
                    $eventId = $activeEvt['event_id'];
                    EmergencyHandler::bufferRecord($activeEvt, $vehicleId, $raw);
                }
            }

            DeviceMonitor::updateFromMessage($vehicleId, $params, (int) ($raw['rt'] ?? time()));

            $addonCustom = [];
            if ($activityId === EventDetector::ENGINE_OFF) {
                $addonCustom = LatraMapper::buildTripStats($vehicleId, (int) $canonical['wialon_msg_time']);
            }
            $addonInfo = json_encode(LatraMapper::buildAddonInfo($activityId, $params, $addonCustom) ?? []);
            $fuelInfo = $activityId === 16 ? json_encode(LatraMapper::buildFuelInfo($params)) : null;

            $msgData = [
                'vehicle_id' => $vehicleId,
                'wialon_msg_time' => $canonical['wialon_msg_time'],
                'gps_time' => $canonical['gps_time'],
                'data_received_time' => $canonical['data_received_time'],
                'data_processed_time' => $canonical['data_processed_time'],
                'raw_data' => $canonical['raw_data'],
                'lat' => $canonical['lat'],
                'lng' => $canonical['lng'],
                'altitude' => $canonical['altitude'],
                'speed' => $canonical['speed'],
                'bearing' => $canonical['bearing'],
                'satellite_count' => $canonical['satellite_count'],
                'hdop' => $canonical['hdop'],
                'd2d3' => $canonical['d2d3'],
                'rssi' => $canonical['rssi'],
                'lac' => $canonical['lac'],
                'cell_id' => $canonical['cell_id'],
                'mcc' => $canonical['mcc'],
                'mgs_id' => $canonical['mgs_id'],
                'odometer' => $canonical['odometer'],
                'driver_id' => $canonical['driver_id'],
                'battery_pct' => $canonical['battery_pct'],
                'battery_mv' => $canonical['battery_mv'],
                'event_id' => $eventId,
                'activity_id' => $activityId,
                'addon_info' => $addonInfo,
                'fuel_info' => $fuelInfo,
                'latra_status' => 'pending',
                'data_received_at' => $canonical['data_received_at'],
                'idempotency_key' => 'v' . $vehicleId . '-t' . $canonical['wialon_msg_time'] . '-a' . $activityId,
            ];

            $validation = GpsValidator::validate($msgData + [
                'imei' => $vehicle['imei'],
                'vehicle_reg_no' => $vehicle['vehicle_reg_no'],
            ]);
            $msgData['validation_status'] = GpsValidator::statusOf($validation);
            if (!$validation['valid']) {
                $msgData['latra_status'] = 'rejected';
            }

            if (!Automation::storeMessage($msgData)) {
                $skipped++;
                continue;
            }
            $stored = Database::fetch(
                "SELECT * FROM message_log WHERE vehicle_id = ? AND wialon_msg_time = ?",
                [$vehicleId, $canonical['wialon_msg_time']]
            );
            if (!$stored) {
                $skipped++;
                continue;
            }
            if (!$validation['valid']) {
                GpsValidator::recordErrors($stored + $msgData, $validation['errors']);
                $rejected++;
                continue;
            }

            $payload = LatraMapper::buildPayload(
                $vehicle,
                $stored,
                $activityId,
                json_decode($addonInfo, true) ?: [],
                json_decode($fuelInfo ?: '[]', true) ?: []
            );
            if ($eventId) {
                $payload['items'][0]['emergency_event_id'] = $eventId;
            }
            Database::update('message_log', ['latra_payload' => json_encode($payload)], 'id = ?', [$stored['id']]);

            $bucketAllowsActivity = in_array($activityId, $bucketActivities, true)
                && in_array($vehicleId, $bucketVehicleIds, true);
            $eventEngineAllowsActivity = $eventEngineOwnsActivities
                && $activityId !== EventDetector::MOVEMENT
                && in_array($vehicleId, $bucketVehicleIds, true);
            if ($bucketAllowsActivity || $eventEngineAllowsActivity) {
                Outbox::enqueue($stored + ['activity_id' => $activityId, 'idempotency_key' => $msgData['idempotency_key']], $payload, $eventId, $eventId ? 10 : 0);
            }

            // Movement/Logging (1) is the baseline record and Speeding (4) is
            // prepared on top of it whenever the horizontal speed exceeds the
            // limit — both records are then queued for LATRA for the same fix.
            $fixSpeed = $canonical['speed'] !== null ? (float) $canonical['speed'] : null;
            foreach (LatraMapper::activitiesForFix($activityId, $fixSpeed, $speedLimit) as $pairedActivity) {
                if ($pairedActivity === $activityId) {
                    continue;
                }
                $pairedAllowed = (in_array($pairedActivity, $bucketActivities, true)
                        && in_array($vehicleId, $bucketVehicleIds, true))
                    || ($eventEngineOwnsActivities
                        && $pairedActivity !== EventDetector::MOVEMENT
                        && in_array($vehicleId, $bucketVehicleIds, true));
                if (!$pairedAllowed) {
                    continue;
                }
                $pairedPayload = LatraMapper::buildPayload($vehicle, $stored, $pairedActivity, [], []);
                if (empty($pairedPayload['items'])) {
                    continue;
                }
                // The emergency reference belongs to the emergency activity only.
                if ($pairedActivity === EventDetector::SPEEDING && $eventId) {
                    $pairedPayload['items'][0]['emergency_event_id'] = $eventId;
                }
                $pairedKey = 'v' . $vehicleId . '-t' . $canonical['wialon_msg_time'] . '-a' . $pairedActivity;
                Outbox::enqueue(
                    array_merge($stored, ['activity_id' => $pairedActivity, 'idempotency_key' => $pairedKey]),
                    $pairedPayload,
                    ($pairedActivity === EventDetector::SPEEDING) ? $eventId : null,
                    ($pairedActivity === EventDetector::SPEEDING && $eventId) ? 10 : 0
                );
            }
            $inserted++;
        }

        EmergencyHandler::closeEndedEvent($vehicleId, $messages);

        $lastMsg = $messages[0] ?? null;
        if ($lastMsg) {
            $lastPos = $lastMsg['pos'] ?? [];
            $lastParams = ParamResolver::resolve($lastMsg['p'] ?? [], (string) ($vehicle['hw_type'] ?? ''));
            $lastSpeed = isset($lastPos['s']) ? round((float) $lastPos['s'], 2) : 0;
            $lastDetected = EventDetector::detect($lastParams, $prevState, [
                'speed' => $lastSpeed,
                'activity_enabled' => $enabledActs,
                'speed_limit' => $speedLimit,
                'engine_param' => $vehicle['engine_param'] ?? null,
            ]);
            EventDetector::persistState($vehicleId, [
                'engine_status' => EventDetector::engineState($lastParams, $vehicle['engine_param'] ?? null) === true ? 'on' : 'off',
                'external_power' => (int) (EventDetector::externalPowerState($lastParams) === true ? 1 : 0),
                'last_speed_kmh' => $lastSpeed,
                'last_lat' => isset($lastPos['y']) ? round((float) $lastPos['y'], 8) : null,
                'last_lng' => isset($lastPos['x']) ? round((float) $lastPos['x'], 8) : null,
                'last_event_activity_id' => $lastDetected['activity_id'],
                'last_event_at' => date(DATE_FORMAT),
                'overspending_active' => $consecutiveOver,
                'idle_started_at' => (int) ($prevState['idle_started_at'] ?? 0) ?: null,
                'idle_reported' => (int) ($prevState['idle_reported'] ?? 0),
                'idle_moved' => (int) ($prevState['idle_moved'] ?? 0),
                'idle_engine_on' => (int) ($prevState['idle_engine_on'] ?? 0),
            ]);
        }

        return compact('inserted', 'rejected', 'skipped');
    }

    public static function speedLimit(array $vehicle): float {
        if (!empty($vehicle['speed_limit_kmh'])) {
            return (float) $vehicle['speed_limit_kmh'];
        }
        return EventDetector::speedLimit((int) $vehicle['id']);
    }
}
