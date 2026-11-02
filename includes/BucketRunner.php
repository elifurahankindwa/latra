<?php

class BucketRunner {

    // Run one bucket.
    public static function run(array $bucket): array {
        $bucketId = (int) $bucket['id'];
        $activityId = (int) $bucket['activity_id'];
        $interval = 180;
        $maxSeconds = max(30, (int) ($bucket['max_run_seconds'] ?? 300));
        $maxItems = max(1, (int) ($bucket['max_items_per_run'] ?? 100));

        $stats = ['fetched' => 0, 'inserted' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => 0, 'denied' => 0];

        $vehicles = Database::fetchAll(
            "SELECT v.*, bv.last_pushed_at, bv.last_pushed_at_message_time
             FROM bucket_vehicles bv
             JOIN vehicles v ON v.id = bv.vehicle_id
             WHERE bv.bucket_id = ? AND v.is_active = 1",
            [$bucketId]
        );
        if (!$vehicles) {
            return $stats;
        }

        $wialon = new WialonAPI();
        if (!$wialon->login()) {
            throw new RuntimeException('Wialon login failed for bucket ' . $bucketId);
        }

        $started = microtime(true);
        $deniedUnits = self::deniedUnitIds();

        try {
            foreach ($vehicles as $vehicle) {
                if ((microtime(true) - $started) > $maxSeconds || $stats['pushed'] + $stats['failed'] >= $maxItems) {
                    break;
                }
                $unitId = (int) ($vehicle['wialon_unit_id'] ?? 0);
                if ($unitId <= 0 || isset($deniedUnits[$unitId])) {
                    $stats['denied']++;
                    continue;
                }

                $from = self::fetchFrom($vehicle, $interval);
                $to = time();
                $messages = [];
                try {
                    $messages = $wialon->getMessages($unitId, $from, $to, 100);
                } catch (Throwable $e) {
                    if ($wialon->getLastError() === 7) {
                        self::rememberDenied($unitId);
                        $stats['denied']++;
                        continue;
                    }
                    Logger::warn('bucket.wialon_fetch', ['vehicle' => (int) $vehicle['id'], 'error' => $e->getMessage()]);
                    $stats['skipped']++;
                    continue;
                }
                if (!$messages && $wialon->getLastError() === 7) {
                    self::rememberDenied($unitId);
                    $stats['denied']++;
                    continue;
                }
                if (!$messages) {
                    $stats['fetched'] += 0;
                    continue;
                }

                $result = self::processVehicle($vehicle, $messages, $activityId, $from);
                foreach ($result as $k => $v) {
                    $stats[$k] = ($stats[$k] ?? 0) + $v;
                }
            }
        } finally {
            $wialon->logout();
        }

        return $stats;
    }

    /**
     * Normalise/store then push the LATEST valid message to LATRA.
     * @return array<string,int>
     */
    private static function processVehicle(array $vehicle, array $messages, int $activityId, int $from): array {
        $out = ['fetched' => count($messages), 'inserted' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => 0];

        // Latest message time for this run.
        $latestMsgTime = null;
        foreach ($messages as $raw) {
            $t = (int) ($raw['t'] ?? $raw['rt'] ?? 0);
            if ($t > $latestMsgTime) {
                $latestMsgTime = $t;
            }
        }

        $ingested = IngestPipeline::ingestVehicle($vehicle, $messages);
        $out['inserted'] = (int) $ingested['inserted'];

        // Take the NEWEST message for this vehicle — like push_log.php's manual push.
        $target = Database::fetch(
            "SELECT id, wialon_msg_time, activity_id, lat, lng, speed, bearing,
                    altitude, satellite_count, hdop, d2d3, rssi, lac, cell_id, mcc, mgs_id,
                    odometer, addon_info, fuel_info, latra_payload, vehicle_id, raw_data
             FROM message_log
             WHERE vehicle_id = ? AND wialon_msg_time > ?
             ORDER BY wialon_msg_time DESC
             LIMIT 1",
            [(int) $vehicle['id'], $from]
        );
        if (!$target) {
            $out['skipped']++;
            self::markPushed((int) $vehicle['id'], $latestMsgTime);
            return $out;
        }

        // Send the activity that was actually DETECTED for this message, not the
        // bucket's own activity_id. The bucket governs polling cadence only: a
        // speeding/panic/engine message ingested while the movement bucket polls
        // must reach LATRA under its real activity (IDD: activity 1 is the
        // "Movement/Logging (Default)" catch-all, so stamping every message with
        // the bucket id silently reclassified all exceptions as movement).
        // Fall back to the bucket activity when the stored activity is unset.
        $detectedActivity = (int) ($target['activity_id'] ?? 0);
        $primaryActivity = $detectedActivity > 0 ? $detectedActivity : $activityId;

        // Movement/Logging (1) is the baseline record and Speeding (4) is
        // prepared on top of it whenever the horizontal speed exceeds the limit,
        // so both records are submitted to LATRA for the same fix.
        $speed = ($target['speed'] !== null && is_numeric($target['speed']))
            ? (float) $target['speed']
            : null;
        $activities = LatraMapper::activitiesForFix(
            $primaryActivity,
            $speed,
            IngestPipeline::speedLimit($vehicle)
        );

        $params = json_decode($target['raw_data'] ?? '{}', true);
        $params = is_array($params) && isset($params['p']) && is_array($params['p']) ? $params['p'] : [];

        foreach ($activities as $sendActivity) {
            $addonInfo = LatraMapper::buildAddonInfo($sendActivity, $params) ?? [];
            $fuelInfo = $sendActivity === 16 ? LatraMapper::buildFuelInfo($params) : [];
            $payload = LatraMapper::buildPayload($vehicle, $target, $sendActivity, $addonInfo, $fuelInfo);
            $idempotencyKey = 'v' . $vehicle['id'] . '-t' . $target['wialon_msg_time'] . '-a' . $sendActivity;

            $result = LatraAdapter::submit($payload, $idempotencyKey);
            LatraAdapter::recordPush(
                ['vehicle_id' => (int) $vehicle['id'], 'id' => (int) $target['id']],
                $sendActivity,
                $result,
                '',
                1
            );
            if (!empty($result['skipped'])) {
                $out['skipped']++;
                continue;
            }
            if ($result['ok']) {
                Database::update('message_log', [
                    'latra_status' => 'sent',
                    'pushed_at' => date(DATE_FORMAT),
                    'latra_response' => substr((string) $result['response'], 0, 2000),
                ], 'id = ?', [(int) $target['id']]);
                $out['pushed']++;
                continue;
            }
            // Retry through the outbox.
            $class = Automation::classifyFailure($result['httpCode'], $result['responseData'], $result['curlError']);
            Outbox::enqueue(
                array_merge($target, [
                    'vehicle_id' => (int) $vehicle['id'],
                    'activity_id' => $sendActivity,
                    'idempotency_key' => $idempotencyKey,
                ]),
                $payload,
                null,
                0
            );
            Database::update('message_log', [
                'latra_status' => $class === 'permanent' ? 'failed' : 'pending',
                'latra_response' => substr((string) ($result['curlError'] ?: $result['response']), 0, 2000),
            ], 'id = ?', [(int) $target['id']]);
            $out['failed']++;
        }

        self::markPushed((int) $vehicle['id'], $latestMsgTime);

        return $out;
    }

    // Resolve the fetch start time.
    private static function fetchFrom(array $vehicle, int $interval): int {
        if (!empty($vehicle['last_pushed_at_message_time'])) {
            return (int) $vehicle['last_pushed_at_message_time'] + 1;
        }
        if (!empty($vehicle['last_pushed_at'])) {
            return strtotime($vehicle['last_pushed_at']) + 1;
        }
        return time() - $interval;
    }

    
    private static function markPushed(int $vehicleId, ?int $latestMsgTime): void {
        Database::query(
            "UPDATE bucket_vehicles
             SET last_pushed_at = NOW(),
                 last_pushed_at_message_time = COALESCE(?, last_pushed_at_message_time)
             WHERE vehicle_id = ?",
            [$latestMsgTime, $vehicleId]
        );
    }

    // Unit IDs denied by Wialon in this process.
    private static array $deniedCache = [];

    private static function deniedUnitIds(): array {
        return self::$deniedCache;
    }

    private static function rememberDenied(int $unitId): void {
        self::$deniedCache[$unitId] = true;
    }
}
