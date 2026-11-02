<?php
/**
 * Real-time Wialon event detection engine.
 *
 * Wialon's public ajax.html API is poll-only, so "real-time" here means:
 *   - a persistent Wialon session (auto-reconnect) reused across cycles,
 *   - short cursor-based polling windows (never bulk re-reads of history),
 *   - stateful, telemetry-derived event classification (EventDetector),
 *   - immediate direct submission of event activities to LATRA,
 *     with the outbox as a durable retry fallback,
 *   - events are stored before submission (wialon_events) with dedup.
 *
 * Movement/Logging (activity 1) is intentionally NOT pushed here — it stays
 * on the bucket interval path. This engine drives EVENTS only.
 */
class EventEngine {

    private const RUN_LOCK   = 'latra-event-engine';
    private const SESSION_TTL = 600;

    private static ?WialonAPI $wialon = null;
    private static int $sessionSince = 0;
    private static int $loginBackoffUntil = 0;
    private static array $deniedUnits = [];
    /** Vehicle ids whose engine_param was already lazy-resolved this session. */
    private static array $resolvedEngineParams = [];

    /** Canonical internal event-type slug per LATRA activity (central mapping). */
    private const TYPE_BY_ACTIVITY = [
        1 => 'movement',
        2 => 'engine_on',
        3 => 'engine_off',
        4 => 'speeding',
        5 => 'hard_braking',
        6 => 'hard_turning',
        7 => 'hard_acceleration',
        8 => 'panic_driver',
        9 => 'battery_low',
        10 => 'power_disconnected',
        11 => 'excessive_idle',
        12 => 'accident',
        13 => 'panic_passenger',
        14 => 'tampering',
        15 => 'black_box',
        16 => 'fuel_report',
        17 => 'invalid_scan',
        18 => 'engine_start',
        19 => 'engine_stop',
        20 => 'enter_boundary',
        21 => 'leave_boundary',
        22 => 'enter_checkpoint',
        23 => 'leave_checkpoint',
        24 => 'ibutton_regular',
    ];

    /**
     * Symmetric (two-state) channels: an event is submitted only when the group
     * CHANGES; repeated same-group readings are suppressed. e.g. enter → leave.
     */
    private const SYMMETRIC = [
        'engine_on'         => ['channel' => 'engine',    'state' => 'on'],
        'engine_start'      => ['channel' => 'engine',    'state' => 'on'],
        'engine_off'        => ['channel' => 'engine',    'state' => 'off'],
        'engine_stop'       => ['channel' => 'engine',    'state' => 'off'],
        'enter_boundary'    => ['channel' => 'geofence',  'state' => 'in'],
        'leave_boundary'    => ['channel' => 'geofence',  'state' => 'out'],
        'enter_checkpoint'  => ['channel' => 'checkpoint', 'state' => 'in'],
        'leave_checkpoint'  => ['channel' => 'checkpoint', 'state' => 'out'],
    ];

    /**
     * Alarm channels: submit ONCE on the rising edge, suppress while the alarm
     * condition persists, and re-arm only after the condition clears.
     * channel => [eventType, trigger param keys].
     */
    private const ALARM = [
        'power'   => ['power_disconnected', ['power_disconnected', 'ext_power_off', 'io_66_power_loss']],
        'battery' => ['battery_low', ['battery_low', 'low_battery']],
        'tamper'  => ['tampering', ['tamper', 'tampering', 'antenna_cut', 'io_8']],
    ];

    // ------------------------------------------------------------------
    // Schema + seeds
    // ------------------------------------------------------------------

    public static function ensureEventSchema(): void {
        Database::query("CREATE TABLE IF NOT EXISTS wialon_events (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            event_id VARCHAR(64) NOT NULL,
            vehicle_id INT NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            activity_id INT NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'derived',
            message_log_id BIGINT NULL,
            wialon_msg_time BIGINT NOT NULL,
            lat DECIMAL(10,8) NULL,
            lng DECIMAL(11,8) NULL,
            altitude INT NULL,
            speed DECIMAL(8,2) NULL,
            bearing DECIMAL(6,2) NULL,
            satellite_count INT NULL,
            hdop DECIMAL(6,2) NULL,
            sensor_values JSON NULL,
            raw_data JSON NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'detected',
            submitted_at DATETIME NULL,
            pushed_at DATETIME NULL,
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_event_dedup (vehicle_id, event_type, wialon_msg_time),
            CONSTRAINT fk_wevent_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            CONSTRAINT fk_wevent_msg FOREIGN KEY (message_log_id) REFERENCES message_log(id) ON DELETE SET NULL,
            INDEX idx_wevent_status (status),
            INDEX idx_wevent_vehicle (vehicle_id),
            INDEX idx_wevent_time (wialon_msg_time)
        ) ENGINE=InnoDB");

        // Centralized mapping: Wialon event keyword / derived event → LATRA activity.
        Database::query("CREATE TABLE IF NOT EXISTS wialon_event_map (
            id INT PRIMARY KEY AUTO_INCREMENT,
            event_keyword VARCHAR(64) NOT NULL,
            activity_id INT NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'derived',
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            description VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_event_keyword (event_keyword)
        ) ENGINE=InnoDB");

        // Cursor so each cycle only reads messages newer than the last seen.
        if (!self::hasColumn('vehicle_state', 'event_last_msg_time')) {
            Database::query("ALTER TABLE vehicle_state ADD COLUMN event_last_msg_time BIGINT NULL AFTER last_event_at");
        }

        // External-power presence persisted by EventDetector::persistState();
        // used as the ALARM channel reset for power events.
        if (!self::hasColumn('vehicle_state', 'external_power')) {
            Database::query("ALTER TABLE vehicle_state ADD COLUMN external_power TINYINT(1) NOT NULL DEFAULT 0 AFTER engine_status");
        }

        // Per-vehicle channel state for edge-triggered (state-transition) events.
        // A channel tracks the last pushed state so the SAME condition is only
        // submitted once, until its complement clears it (e.g. power returns).
        Database::query("CREATE TABLE IF NOT EXISTS vehicle_event_state (
            vehicle_id INT NOT NULL,
            channel VARCHAR(24) NOT NULL,
            state VARCHAR(40) NULL,
            event_type VARCHAR(50) NULL,
            wialon_msg_time BIGINT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (vehicle_id, channel),
            CONSTRAINT fk_vevstate_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        self::seedEventMap();
        self::seedConfig();
    }

    private static function seedEventMap(): void {
        $map = EventDetector::EVENT_MAP;
        $desc = self::TYPE_BY_ACTIVITY;
        foreach ($map as $keyword => $activityId) {
            Database::query(
                "INSERT IGNORE INTO wialon_event_map (event_keyword, activity_id, source, is_enabled, description)
                 VALUES (?, ?, 'notification', 1, ?)",
                [
                    $keyword,
                    (int) $activityId,
                    'Wialon event-name keyword -> activity ' . (int) $activityId . ' (' . ($desc[$activityId] ?? '') . ')',
                ]
            );
        }
    }

    private static function seedConfig(): void {
        $defaults = [
            ['event_engine_enabled', '1', 'Real-time event engine master switch'],
            ['event_engine_window_seconds', '900', 'Event engine fetch window (seconds)'],
            ['event_engine_limit', '100', 'Event engine max messages per vehicle per cycle'],
            ['event_engine_cooldown_seconds', '300', 'Min interval between repeated momentary events per vehicle'],
        ];
        foreach ($defaults as [$k, $v, $d]) {
            Database::query(
                "INSERT IGNORE INTO config (config_key, config_value, description) VALUES (?, ?, ?)",
                [$k, $v, $d]
            );
        }
    }

    // ------------------------------------------------------------------
    // Main cycle
    // ------------------------------------------------------------------

    public static function runCycle(): array {
        self::ensureEventSchema();
        $stats = [
            'vehicles' => 0, 'fetched' => 0, 'detected' => 0, 'duplicates' => 0,
            'sent' => 0, 'failed' => 0, 'suppressed' => 0, 'skipped_movement' => 0, 'denied' => 0,
        ];

        $lock = Database::fetch("SELECT GET_LOCK(?, 0) AS acquired", [self::RUN_LOCK]);
        if (!(int) ($lock['acquired'] ?? 0)) {
            return $stats;
        }
        try {
            if (self::enabled()) {
                $vehicles = Database::fetchAll(
                    "SELECT v.* FROM vehicles v
                     JOIN bucket_vehicles bv ON bv.vehicle_id = v.id
                     JOIN automation_buckets b ON b.id = bv.bucket_id AND b.is_enabled = 1
                     WHERE v.is_active = 1
                     GROUP BY v.id"
                );
                if ($vehicles && self::ensureSession()) {
                    foreach ($vehicles as $vehicle) {
                        if (isset(self::$deniedUnits[(int) ($vehicle['wialon_unit_id'] ?? 0)])) {
                            $stats['denied']++;
                            continue;
                        }
                        try {
                            $r = self::processVehicle($vehicle);
                            foreach ($r as $k => $v) {
                                $stats[$k] = ($stats[$k] ?? 0) + (int) $v;
                            }
                            $stats['vehicles']++;
                        } catch (Throwable $e) {
                            self::log('vehicle_error', ['vehicle' => (int) $vehicle['id'], 'error' => $e->getMessage()]);
                            Logger::warn('event_engine.vehicle_error', ['vehicle' => (int) $vehicle['id'], 'error' => $e->getMessage()]);
                        }
                    }
                    self::recordLastRun($stats);
                }
            }
        } finally {
            try {
                Database::query("SELECT RELEASE_LOCK(?)", [self::RUN_LOCK]);
            } catch (Throwable $ignored) {
            }
        }
        // Wipe-guard: any bare rail empty in the same session is acted on (see
        // runCycle watermark pair in runCycle()); here we just report totals.
        return $stats;
    }

    /** Event engine on + master scheduler on. */
    public static function enabled(): bool {
        return Schema::config('scheduler_enabled', '1') === '1'
            && Schema::config('event_engine_enabled', '1') === '1';
    }

    // ------------------------------------------------------------------
    // Session management (persistent + reconnect)
    // ------------------------------------------------------------------

    private static function ensureSession(): bool {
        $now = time();
        if ($now < self::$loginBackoffUntil) {
            return false;
        }
        if (self::$wialon !== null && self::$wialon->isLoggedIn()
            && ($now - self::$sessionSince) < self::SESSION_TTL) {
            return true;
        }
        if (self::$wialon !== null) {
            try { @self::$wialon->logout(); } catch (Throwable $ignored) { }
            self::$wialon = null;
        }
        $w = new WialonAPI(
            Vault::wialonUrl() ?: WIALON_BASE_URL,
            Vault::wialonToken() ?: WIALON_TOKEN
        );
        if ($w->login()) {
            self::$wialon = $w;
            self::$sessionSince = $now;
            self::log('session_logged_in', ['sid' => substr((string) $w->getSid(), 0, 8)]);
            return true;
        }
        self::log('session_login_failed', ['error' => $w->getLastError()]);
        self::$loginBackoffUntil = $now + 60;
        return false;
    }

    /** Fetch only messages newer than the stored cursor (bounded window). */
    private static function fetchMessages(array $vehicle): array {
        $unitId = (int) ($vehicle['wialon_unit_id'] ?? 0);
        if ($unitId <= 0) {
            return ['messages' => [], 'denied' => true];
        }
        $vehicleId = (int) $vehicle['id'];
        $cursor = (int) (Database::fetch(
            "SELECT event_last_msg_time FROM vehicle_state WHERE vehicle_id = ?", [$vehicleId]
        )['event_last_msg_time'] ?? 0);

        $window = max(60, Schema::configInt('event_engine_window_seconds', 900));
        $limit = max(1, Schema::configInt('event_engine_limit', 100));
        $from = max($cursor + 1, time() - $window);
        $to = time();
        if ($from >= $to) {
            return ['messages' => [], 'denied' => false];
        }

        try {
            $msgs = self::$wialon->getMessages($unitId, $from, $to, $limit);
        } catch (Throwable $e) {
            $code = self::$wialon->getLastError();
            if ($code === 7) {
                self::$deniedUnits[$unitId] = true;
                return ['messages' => [], 'denied' => true];
            }
            // Any other error marks the session as stale → reconnect next cycle.
            if ($code !== 0) {
                self::$sessionSince = 0;
            }
            self::log('poll_error', ['vehicle' => $vehicleId, 'code' => $code, 'error' => $e->getMessage()]);
            return ['messages' => [], 'denied' => false];
        }
        if (!$msgs && self::$wialon->getLastError() === 7) {
            self::$deniedUnits[$unitId] = true;
            return ['messages' => [], 'denied' => true];
        }
        return ['messages' => $msgs, 'denied' => false];
    }

    // ------------------------------------------------------------------
    // Per-vehicle processing
    // ------------------------------------------------------------------

    private static function processVehicle(array $vehicle): array {
        $stats = ['fetched' => 0, 'detected' => 0, 'duplicates' => 0, 'sent' => 0, 'failed' => 0, 'suppressed' => 0, 'skipped_movement' => 0];
        $vehicleId = (int) $vehicle['id'];

        $fetch = self::fetchMessages($vehicle);
        if ($fetch['denied']) {
            $stats['denied'] = 1;
            return $stats;
        }
        $messages = $fetch['messages'];
        if (!$messages) {
            return $stats;
        }
        $stats['fetched'] = count($messages);

        // Lazy-resolve the vehicle's Wialon engine ignition sensor param
        // (once per session) so its signal — incl. bitmask forms like
        // "tr_status:11" — feeds detection even when fleet sync hasn't run.
        if (empty($vehicle['engine_param']) && !isset(self::$resolvedEngineParams[$vehicleId])
            && !isset(self::$deniedUnits[(int) ($vehicle['wialon_unit_id'] ?? 0)])) {
            self::$resolvedEngineParams[$vehicleId] = true;
            if (self::$wialon !== null) {
                try {
                    $engineParam = self::$wialon->getEngineSensorParam((int) ($vehicle['wialon_unit_id'] ?? 0));
                    if ($engineParam !== null) {
                        Database::update('vehicles', ['engine_param' => $engineParam], 'id = ?', [$vehicleId]);
                        $vehicle['engine_param'] = $engineParam;
                        self::log('engine_param_resolved', ['vehicle' => $vehicleId, 'param' => $engineParam]);
                    }
                } catch (Throwable $e) {
                    self::log('vehicle_error', ['vehicle' => $vehicleId, 'error' => $e->getMessage()]);
                }
            }
        }

        // Canonical pipeline: normalise → detect → validate → store → emergency → state.
        IngestPipeline::ingestVehicle($vehicle, $messages);

        // Advance the cursor to the newest seen message.
        $newest = 0;
        foreach ($messages as $m) {
            $t = (int) ($m['t'] ?? 0);
            if ($t > $newest) {
                $newest = $t;
            }
        }
        if ($newest > 0) {
            self::updateCursor($vehicleId, $newest);
        }

        // With mutual exclusion, event buckets are switched OFF while the engine
        // runs, so the allowed set must NOT be derived from enabled buckets. The
        // engine owns every non-movement activity defined in the central event map.
        $allowed = array_values(array_unique(array_column(
            Database::fetchAll(
                "SELECT activity_id FROM wialon_event_map WHERE activity_id <> ?",
                [EventDetector::MOVEMENT]
            ),
            'activity_id'
        )));
        if (empty($allowed)) {
            $allowed = array_values(array_diff(range(1, 24), [EventDetector::MOVEMENT]));
        }

        // Oldest → newest so events are processed in occurrence order.
        $ordered = $messages;
        usort($ordered, static fn ($a, $b) => (int) ($a['t'] ?? 0) <=> (int) ($b['t'] ?? 0));

        // Alarm-channel armed state, swept across every message (including
        // movement) so "power restored" clears the armed flag between episodes.
        $chanArmed = [];
        foreach (array_keys(self::ALARM) as $ch) {
            $st = self::loadChannelState($vehicleId, $ch);
            $chanArmed[$ch] = ($st['state'] ?? null) === 'armed';
        }

        foreach ($ordered as $raw) {
            $t = (int) ($raw['t'] ?? 0);
            if ($t <= 0) {
                continue;
            }
            $row = Database::fetch(
                "SELECT id, activity_id, wialon_msg_time,
                        lat, lng, speed, bearing, altitude, satellite_count, hdop,
                        latra_payload, latra_status, raw_data
                 FROM message_log
                 WHERE vehicle_id = ? AND wialon_msg_time = ?",
                [$vehicleId, $t]
            );
            if (!$row || $row['latra_status'] === 'rejected') {
                continue;
            }
            $params = json_decode($row['raw_data'] ?? '{}', true);
            $params = is_array($params) && isset($params['p']) && is_array($params['p']) ? $params['p'] : [];

            // 1. Alarm-condition sweep: update armed state from raw params. A
            //    message that arms a clear channel is a RISING EDGE (new episode).
            $rising = [];
            foreach (self::ALARM as $ch => $spec) {
                if (self::alarmActive($ch, $params)) {
                    if (!$chanArmed[$ch]) {
                        $chanArmed[$ch] = true;
                        $rising[$ch] = true;
                        self::saveChannelState($vehicleId, $ch, 'armed', null, $t);
                    }
                } elseif ($chanArmed[$ch]) {
                    $chanArmed[$ch] = false;
                    self::saveChannelState($vehicleId, $ch, 'clear', null, $t);
                }
            }

            $activityId = (int) $row['activity_id'];
            // Movement stays on the bucket interval path — never here.
            if ($activityId === EventDetector::MOVEMENT) {
                $stats['skipped_movement']++;
                continue;
            }
            if (!in_array($activityId, $allowed, true)) {
                continue;
            }

            $eventType = self::typeForActivity($activityId);
            $dup = Database::fetch(
                "SELECT id, status FROM wialon_events WHERE vehicle_id = ? AND event_type = ? AND wialon_msg_time = ?",
                [$vehicleId, $eventType, $t]
            );
            // Only a successfully-sent event is a real duplicate.
            if ($dup && $dup['status'] !== 'failed') {
                $stats['duplicates']++;
                continue;
            }

            $sym = self::SYMMETRIC[$eventType] ?? null;
            $alarmCh = self::alarmChannelFor($eventType);

            if ($sym !== null) {
                $chState = self::loadChannelState($vehicleId, $sym['channel']);
                // Same group as the last push → same condition still ongoing.
                if (($chState['state'] ?? null) === $sym['state']) {
                    $fid = self::latestFailedEvent($vehicleId, $eventType);
                    if ($fid === null) {
                        $stats['suppressed']++;
                        continue;
                    }
                    $stats['detected']++;
                    $id = self::pushEvent($vehicle, $row, $activityId, $eventType, $params, $fid);
                    $stats[$id !== null ? 'sent' : 'failed']++;
                    continue;
                }
                // Group transition → new legitimate occurrence.
                self::saveChannelState($vehicleId, $sym['channel'], $sym['state'], $eventType, $t);
            } elseif ($alarmCh !== null) {
                // Never submit an alarm event when the physical condition is
                // actually clear (guards against classification false positives).
                if (!self::alarmActive($alarmCh, $params)) {
                    $stats['suppressed']++;
                    continue;
                }
                $armed = $chanArmed[$alarmCh];
                $isRising = isset($rising[$alarmCh]);
                if ($armed && !$isRising) {
                    // Condition persists since an earlier message → suppress,
                    // unless the episode's earlier push is still unsent.
                    $fid = self::latestFailedEvent($vehicleId, $eventType);
                    if ($fid === null) {
                        $stats['suppressed']++;
                        continue;
                    }
                    $stats['detected']++;
                    $id = self::pushEvent($vehicle, $row, $activityId, $eventType, $params, $fid);
                    $stats[$id !== null ? 'sent' : 'failed']++;
                    continue;
                }
                // Rising edge: new episode → submit the alarm event.
                self::saveChannelState($vehicleId, $alarmCh, 'armed', $eventType, $t);
            } else {
                // Momentary events: throttle repeated occurrences per vehicle.
                $cooldown = max(0, Schema::configInt('event_engine_cooldown_seconds', 300));
                $last = Database::fetch(
                    "SELECT id, status, submitted_at FROM wialon_events
                     WHERE vehicle_id = ? AND event_type = ? AND status IN ('sent','failed')
                     ORDER BY id DESC LIMIT 1",
                    [$vehicleId, $eventType]
                );
                if ($last) {
                    if ($last['status'] === 'failed') {
                        $stats['detected']++;
                        $id = self::pushEvent($vehicle, $row, $activityId, $eventType, $params, (int) $last['id']);
                        $stats[$id !== null ? 'sent' : 'failed']++;
                        continue;
                    }
                    $lastAt = is_string($last['submitted_at']) ? strtotime($last['submitted_at']) : 0;
                    if ($cooldown > 0 && $lastAt > time() - $cooldown) {
                        $stats['suppressed']++;
                        continue;
                    }
                }
            }

            $stats['detected']++;
            $eventId = self::pushEvent($vehicle, $row, $activityId, $eventType, $params);
            if ($eventId !== null) {
                $stats['sent']++;
            } else {
                $stats['failed']++;
            }
        }
        return $stats;
    }

    /**
     * Store the normalized event, submit it to LATRA immediately, and fall back
     * to the outbox on failure. Returns the wialon_events id (or null on fail).
     */
    private static function pushEvent(array $vehicle, array $row, int $activityId, string $eventType, array $params, ?int $existingEventId = null): ?int {
        $vehicleId = (int) $vehicle['id'];

        if ($existingEventId !== null) {
            // Retry of an existing episode row: keep the ORIGINAL occurrence time
            // (dedup key) but rebuild the payload from the freshest message.
            $existing = Database::fetch("SELECT id, wialon_msg_time, attempts FROM wialon_events WHERE id = ?", [$existingEventId]);
            if (!$existing) {
                return null;
            }
            $msgTime = (int) $existing['wialon_msg_time'];
        } else {
            $msgTime = (int) $row['wialon_msg_time'];
            $existing = Database::fetch(
                "SELECT id, wialon_msg_time, attempts FROM wialon_events
                 WHERE vehicle_id = ? AND event_type = ? AND wialon_msg_time = ?",
                [$vehicleId, $eventType, $msgTime]
            );
        }
        $eventId = 'EV-' . $vehicleId . '-' . $msgTime . '-' . self::slugify($eventType);

        $source = self::sourceFor($params, $activityId);
        $addonCustom = $activityId === 3 ? LatraMapper::buildTripStats($vehicleId, $msgTime) : [];
        $addonInfo = LatraMapper::buildAddonInfo($activityId, $params, $addonCustom) ?? [];
        $fuelInfo = $activityId === 16 ? LatraMapper::buildFuelInfo($params) : [];

        $payload = json_decode($row['latra_payload'] ?? '', true) ?: [];
        if (!$payload && $row['lat'] !== null && $row['lng'] !== null) {
            $payload = LatraMapper::buildPayload($vehicle, $row, $activityId, $addonInfo, $fuelInfo);
        }
        if (empty($payload['items'])) {
            self::log('push_skipped', ['vehicle' => $vehicleId, 'type' => $eventType, 'reason' => 'no_latra_payload']);
            return null;
        }

        // Retryable event from an earlier failed attempt, or a brand-new event.
        if (!$existing) {
            $existing = null;
        }

        // Same key IngestPipeline uses for the outbox → the fallback dedups.
        $idempotencyKey = 'v' . $vehicleId . '-t' . $msgTime . '-a' . $activityId;
        // Explicit event producers also persist before sending, even when ingest did not queue them.
        Outbox::enqueue(array_replace($row, ['vehicle_id' => $vehicleId,
            'activity_id' => $activityId, 'idempotency_key' => $idempotencyKey]), $payload,
            $row['event_id'] ?? null, 10);
        $result = LatraAdapter::submit($payload, $idempotencyKey);
        if (!empty($result['deferred'])) return null;
        $ok = (bool) $result['ok'];

        if ($existing) {
            Database::update('wialon_events', [
                'status'          => $ok ? 'sent' : 'failed',
                'submitted_at'    => date(DATE_FORMAT),
                'pushed_at'       => $ok ? date(DATE_FORMAT) : null,
                'attempts'        => (int) $existing['attempts'] + (!empty($result['attempted']) ? 1 : 0),
                'last_error'      => $ok ? null : substr((string) ($result['curlError'] ?: $result['response']), 0, 2000),
            ], 'id = ?', [(int) $existing['id']]);
            $eventRowId = (int) $existing['id'];
        } else {
            $eventRowId = Database::insert('wialon_events', [
            'event_id'        => $eventId,
            'vehicle_id'      => $vehicleId,
            'event_type'      => $eventType,
            'activity_id'     => $activityId,
            'source'          => $source,
            'message_log_id'  => (int) $row['id'],
            'wialon_msg_time' => $msgTime,
            'lat'             => $row['lat'] !== null ? (float) $row['lat'] : null,
            'lng'             => $row['lng'] !== null ? (float) $row['lng'] : null,
            'altitude'        => $row['altitude'] !== null ? (int) $row['altitude'] : null,
            'speed'           => $row['speed'] !== null ? (float) $row['speed'] : null,
            'bearing'         => $row['bearing'] !== null ? (float) $row['bearing'] : null,
            'satellite_count' => $row['satellite_count'] !== null ? (int) $row['satellite_count'] : null,
            'hdop'            => $row['hdop'] !== null ? (float) $row['hdop'] : null,
            'sensor_values'   => json_encode($params),
            'raw_data'        => $row['raw_data'],
            'status'          => $ok ? 'sent' : 'failed',
            'submitted_at'    => date(DATE_FORMAT),
            'pushed_at'       => $ok ? date(DATE_FORMAT) : null,
            'attempts'        => 1,
            'last_error'      => $ok ? null : substr((string) ($result['curlError'] ?: $result['response']), 0, 2000),
        ]);
        }

        LatraAdapter::recordPush(
            ['vehicle_id' => $vehicleId, 'id' => (int) $row['id']],
            $activityId,
            $result,
            '',
            1
        );

        if ($ok) {
            Database::update('message_log', [
                'latra_status' => 'sent',
                'pushed_at' => date(DATE_FORMAT),
                'latra_response' => substr((string) $result['response'], 0, 2000),
            ], 'id = ?', [(int) $row['id']]);
            // Delivery finalized the matching queue row while still holding the key lock.
            self::log('sent', [
                'vehicle' => $vehicleId, 'type' => $eventType, 'activity' => $activityId,
                'time' => $msgTime, 'latency_ms' => $result['latency'],
            ]);
        } else {
            // Outbox fallback already exists from ingest for this idempotency key.
            Database::update('message_log', [
                'latra_status' => 'pending',
                'latra_response' => substr((string) ($result['curlError'] ?: $result['response']), 0, 2000),
            ], 'id = ?', [(int) $row['id']]);
            self::log('failed', [
                'vehicle' => $vehicleId, 'type' => $eventType, 'activity' => $activityId,
                'time' => $msgTime, 'error' => $result['curlError'] ?: $result['response'],
                'http' => $result['httpCode'],
            ]);
            Logger::warn('event_engine.failed', [
                'vehicle' => $vehicleId, 'type' => $eventType, 'http' => $result['httpCode'],
            ]);
        }

        return $ok ? (int) $eventRowId : null;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function updateCursor(int $vehicleId, int $time): void {
        Database::query(
            "INSERT INTO vehicle_state (vehicle_id, event_last_msg_time)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE event_last_msg_time = ?",
            [$vehicleId, $time, $time]
        );
    }

    // ------------------------------------------------------------------
    // Edge-triggered suppression helpers
    // ------------------------------------------------------------------

    /** Channel that a momentary/null event maps to, if any. */
    private static function alarmChannelFor(string $eventType): ?string {
        foreach (self::ALARM as $ch => $spec) {
            if ($spec[0] === $eventType) {
                return $ch;
            }
        }
        return null;
    }

    /** Is the alarm condition currently active for this channel, per raw params? */
    private static function alarmActive(string $channel, array $params): bool {
        $spec = self::ALARM[$channel] ?? null;
        if (!$spec) {
            return false;
        }
        [$eventType, $keys] = $spec;
        $flag = EventDetector::flagOn($params, $keys);
        if ($flag !== null) {
            return (bool) $flag;
        }
        if ($channel === 'power') {
            return EventDetector::externalPowerState($params) === false;
        }
        return false;
    }

    private static function loadChannelState(int $vehicleId, string $channel): array {
        return Database::fetch(
            "SELECT state, event_type, wialon_msg_time FROM vehicle_event_state WHERE vehicle_id = ? AND channel = ?",
            [$vehicleId, $channel]
        ) ?: [];
    }

    private static function saveChannelState(int $vehicleId, string $channel, ?string $state, ?string $eventType, ?int $msgTime): void {
        Database::query(
            "INSERT INTO vehicle_event_state (vehicle_id, channel, state, event_type, wialon_msg_time)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE state = VALUES(state), event_type = VALUES(event_type), wialon_msg_time = VALUES(wialon_msg_time)",
            [$vehicleId, $channel, $state, $eventType, $msgTime]
        );
    }

    /** Id of the newest unsent event row for a vehicle+event type (retry target). */
    private static function latestFailedEvent(int $vehicleId, string $eventType): ?int {
        $r = Database::fetch(
            "SELECT id FROM wialon_events
             WHERE vehicle_id = ? AND event_type = ? AND status = 'failed'
             ORDER BY id DESC LIMIT 1",
            [$vehicleId, $eventType]
        );
        return $r ? (int) $r['id'] : null;
    }

    /** Stable internal event-type slug — doubles as the dedup discriminator. */
    private static function typeForActivity(int $activityId): string {
        return self::TYPE_BY_ACTIVITY[$activityId] ?? ('activity_' . $activityId);
    }

    /** Classify where the event came from, for traceability. */
    private static function sourceFor(array $params, int $activityId): string {
        $eventName = strtolower((string) ($params['event'] ?? ''));
        if ($eventName !== '') {
            foreach (EventDetector::EVENT_MAP as $keyword => $act) {
                if ((int) $act === $activityId && strpos($eventName, $keyword) !== false) {
                    return 'notification';
                }
            }
        }
        return match ($activityId) {
            4, 5, 6, 7, 11 => 'derived',
            20, 21, 22, 23 => 'geofence',
            8, 12, 13      => 'notification',
            default        => 'param',
        };
    }

    private static function slugify(string $s): string {
        return preg_replace('/[^a-z0-9]+/', '-', strtolower($s));
    }

    private static function recordLastRun(array $stats): void {
        $json = json_encode([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'stats' => array_merge($stats, ['session_seconds' => time() - self::$sessionSince]),
        ]);
        Database::query(
            "INSERT INTO config (config_key, config_value, description)
             VALUES ('event_engine_last_run', ?, 'Latest event engine cycle summary (JSON)')
             ON DUPLICATE KEY UPDATE config_value = ?",
            [$json, $json]
        );
    }

    private static function hasColumn(string $table, string $column): bool {
        $row = Database::fetch(
            "SELECT COUNT(*) AS c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $column]
        );
        return (int) ($row['c'] ?? 0) > 0;
    }

    private static function log(string $event, array $ctx = []): void {
        Automation::log('event_engine.' . $event, $ctx);
    }
}