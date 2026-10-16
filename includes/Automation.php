<?php

class Automation {

    public static function ensureSchema(): void {
        Database::query("CREATE TABLE IF NOT EXISTS automation_buckets (
            id INT PRIMARY KEY AUTO_INCREMENT,
            bucket_name VARCHAR(100) NOT NULL,
            activity_id INT NOT NULL,
            interval_seconds INT NOT NULL DEFAULT 300,
            cooldown_seconds INT NOT NULL DEFAULT 30,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            max_items_per_run INT NOT NULL DEFAULT 100,
            max_run_seconds INT NOT NULL DEFAULT 300,
            claim_lease_seconds INT NOT NULL DEFAULT 120,
            run_on_demand TINYINT(1) NOT NULL DEFAULT 0,
            consecutive_failures INT NOT NULL DEFAULT 0,
            last_run_at TIMESTAMP NULL,
            next_run_at TIMESTAMP NULL,
            run_count INT NOT NULL DEFAULT 0,
            fail_count INT NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_bucket_due (is_enabled, next_run_at),
            FOREIGN KEY (activity_id) REFERENCES activity_definitions(id)
        ) ENGINE=InnoDB");
        Database::query("CREATE TABLE IF NOT EXISTS device_status (
            vehicle_id INT PRIMARY KEY,
            is_online TINYINT(1) NOT NULL DEFAULT 0,
            online_at DATETIME NULL,
            last_comm_time BIGINT NULL,
            last_comm_at DATETIME NULL,
            last_gps_at BIGINT NULL,
            internal_battery_low TINYINT(1) NOT NULL DEFAULT 0,
            internal_battery_mv DECIMAL(8,2) NULL,
            external_power_disconnected TINYINT(1) NOT NULL DEFAULT 0,
            external_power_mv DECIMAL(8,2) NULL,
            tampered TINYINT(1) NOT NULL DEFAULT 0,
            status_changed_at DATETIME NULL,
            last_status TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_devstatus_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            INDEX idx_devstatus_online (is_online),
            INDEX idx_devstatus_bat (internal_battery_low),
            INDEX idx_devstatus_pwr (external_power_disconnected),
            INDEX idx_devstatus_tamper (tampered)
        ) ENGINE=InnoDB");
        Database::query("CREATE TABLE IF NOT EXISTS bucket_vehicles (
            bucket_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            PRIMARY KEY (bucket_id, vehicle_id),
            FOREIGN KEY (bucket_id) REFERENCES automation_buckets(id) ON DELETE CASCADE,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        Database::query("CREATE TABLE IF NOT EXISTS automation_runs (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            bucket_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            triggered_by VARCHAR(10) NOT NULL DEFAULT 'schedule',
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            duration_seconds INT NOT NULL DEFAULT 0,
            total_count INT NOT NULL DEFAULT 0,
            success_count INT NOT NULL DEFAULT 0,
            failed_count INT NOT NULL DEFAULT 0,
            manual_count INT NOT NULL DEFAULT 0,
            skipped_count INT NOT NULL DEFAULT 0,
            error_message TEXT DEFAULT NULL,
            INDEX idx_run_started (started_at),
            FOREIGN KEY (bucket_id) REFERENCES automation_buckets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        Database::query("CREATE TABLE IF NOT EXISTS automation_run_items (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            run_id BIGINT NOT NULL,
            bucket_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            activity_id INT NOT NULL,
            message_log_id BIGINT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            next_retry_at DATETIME NULL,
            claim_id VARCHAR(40) NULL,
            attempt TINYINT UNSIGNED NOT NULL DEFAULT 0,
            failure_class VARCHAR(20) NULL,
            last_error TEXT DEFAULT NULL,
            completed_at DATETIME NULL,
            INDEX idx_item_status (status),
            INDEX idx_item_retry (status, next_retry_at),
            FOREIGN KEY (run_id) REFERENCES automation_runs(id) ON DELETE CASCADE,
            FOREIGN KEY (bucket_id) REFERENCES automation_buckets(id) ON DELETE CASCADE,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            FOREIGN KEY (message_log_id) REFERENCES message_log(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        // LATRA compliance: validation error log.
        Database::query("CREATE TABLE IF NOT EXISTS validation_errors (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            message_log_id BIGINT NULL,
            vehicle_id INT NULL,
            vehicle_reg_no VARCHAR(50) NULL,
            imei VARCHAR(20) NULL,
            field VARCHAR(64) NOT NULL,
            invalid_value TEXT NULL,
            reason VARCHAR(255) NOT NULL,
            error_code VARCHAR(64) NULL,
            activity_id INT NULL,
            validation_status VARCHAR(20) NOT NULL DEFAULT 'failed',
            gps_timestamp BIGINT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_veh (vehicle_id),
            INDEX idx_imei (imei),
            INDEX idx_status (validation_status),
            INDEX idx_created (created_at),
            INDEX idx_message (message_log_id),
            CONSTRAINT fk_valerr_msg FOREIGN KEY (message_log_id) REFERENCES message_log(id) ON DELETE SET NULL,
            CONSTRAINT fk_valerr_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        // LATRA compliance: event detection configuration and state.
        Database::query("CREATE TABLE IF NOT EXISTS overspeed_config (
            id INT PRIMARY KEY AUTO_INCREMENT,
            vehicle_id INT NULL,
            speed_limit_kmh DECIMAL(6,2) NOT NULL DEFAULT 80.00,
            duration_seconds INT NOT NULL DEFAULT 5,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_overspeed_vehicle (vehicle_id),
            CONSTRAINT fk_overspeed_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        Database::query("CREATE TABLE IF NOT EXISTS geofence_definitions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            type ENUM('boundary','checkpoint') NOT NULL DEFAULT 'boundary',
            shape ENUM('circle','polygon') NOT NULL DEFAULT 'circle',
            latitude DECIMAL(10,8) NULL,
            longitude DECIMAL(11,8) NULL,
            radius_meters DECIMAL(12,3) NULL,
            polygon_geo JSON NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_geotype (type),
            INDEX idx_enabled (is_enabled)
        ) ENGINE=InnoDB");
        Database::query("CREATE TABLE IF NOT EXISTS vehicle_state (
            vehicle_id INT PRIMARY KEY,
            engine_status VARCHAR(20) DEFAULT NULL,
            last_speed_kmh DECIMAL(8,2) DEFAULT NULL,
            last_lat DECIMAL(10,8) DEFAULT NULL,
            last_lng DECIMAL(11,8) DEFAULT NULL,
            last_geofence_id INT NULL,
            last_geofence_inside TINYINT(1) NOT NULL DEFAULT 0,
            last_checkpoint_id INT NULL,
            overspending_active TINYINT(1) NOT NULL DEFAULT 0,
            last_event_activity_id INT NULL,
            last_event_at DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_vstate_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        Database::query("CREATE TABLE IF NOT EXISTS gps_health (
            vehicle_id INT PRIMARY KEY,
            last_gps_time BIGINT NULL,
            last_gps_at DATETIME NULL,
            last_comm_time BIGINT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'unknown',
            minutes_since INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_gpshealth_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            INDEX idx_gps_status (status),
            INDEX idx_gps_time (last_gps_time)
        ) ENGINE=InnoDB");
        Database::query("CREATE TABLE IF NOT EXISTS emergency_events (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            event_id VARCHAR(40) NOT NULL,
            vehicle_id INT NOT NULL,
            event_type VARCHAR(30) NOT NULL DEFAULT 'overspeed',
            activity_id INT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            trigger_reason TEXT NULL,
            max_speed_kmh DECIMAL(8,2) NULL,
            record_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_emergency_event (event_id),
            INDEX idx_emergency_vehicle (vehicle_id),
            INDEX idx_emergency_status (status),
            INDEX idx_emergency_active (vehicle_id, status),
            CONSTRAINT fk_emergency_veh FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        Database::query("CREATE TABLE IF NOT EXISTS emergency_queue (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            event_id VARCHAR(40) NOT NULL,
            emergency_event_id BIGINT NULL,
            vehicle_id INT NOT NULL,
            wialon_msg_time BIGINT NOT NULL,
            lat DECIMAL(10,8) NULL,
            lng DECIMAL(11,8) NULL,
            altitude INT NULL,
            speed DECIMAL(8,2) NULL,
            bearing DECIMAL(6,2) NULL,
            satellite_count INT NULL,
            hdop DECIMAL(6,2) NULL,
            raw_data JSON NULL,
            latra_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            latra_response TEXT NULL,
            submitted_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_emergency_msg (event_id, wialon_msg_time, vehicle_id),
            INDEX idx_emq_status (latra_status),
            INDEX idx_emq_vehicle (vehicle_id),
            INDEX idx_emq_event (event_id),
            CONSTRAINT fk_emq_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        // message_log: attempts bookkeeping (legacy), then recovery columns.
        self::addColumn('message_log', 'latra_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0');
        self::addColumn('message_log', 'latra_manual_at DATETIME NULL');
        self::addColumn('message_log', 'latra_claim_id VARCHAR(40) NULL');
        self::addColumn('message_log', 'latra_claimed_at DATETIME NULL');
        self::addColumn('message_log', 'next_retry_at DATETIME NULL');
        self::addColumn('message_log', 'max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5');
        self::addColumn('message_log', 'validation_status VARCHAR(20) NULL');
        self::addColumn('message_log', 'data_received_at BIGINT NULL');
        self::addColumn('message_log', 'data_processed_at DATETIME NULL');

        self::addColumn('automation_buckets', 'max_items_per_run INT NOT NULL DEFAULT 100');
        self::addColumn('automation_buckets', 'max_run_seconds INT NOT NULL DEFAULT 300');
        self::addColumn('automation_buckets', 'claim_lease_seconds INT NOT NULL DEFAULT 120');
        self::addColumn('automation_buckets', 'run_on_demand TINYINT(1) NOT NULL DEFAULT 0');
        self::addColumn('automation_buckets', 'consecutive_failures INT NOT NULL DEFAULT 0');
        self::addColumn('automation_buckets', 'is_event TINYINT(1) NOT NULL DEFAULT 0');

        // Movement (activity 1) is interval-driven; all other activities are event-driven.
        Database::query("UPDATE automation_buckets SET is_event = CASE WHEN activity_id = 1 THEN 0 ELSE 1 END WHERE activity_id IS NOT NULL");

        self::addColumn('bucket_vehicles', 'last_pushed_at TIMESTAMP NULL');
        self::addColumn('bucket_vehicles', 'last_pushed_at_message_time BIGINT NULL');

        self::addColumn('automation_runs', 'triggered_by VARCHAR(10) NOT NULL DEFAULT \'schedule\'');
        self::addColumn('automation_runs', 'duration_seconds INT NOT NULL DEFAULT 0');
        self::addColumn('automation_runs', 'skipped_count INT NOT NULL DEFAULT 0');

        // Buckets run on an exact three-minute cadence; cron checks due buckets.
        Database::query("UPDATE automation_buckets SET interval_seconds = 180, cooldown_seconds = 0 WHERE interval_seconds <> 180 OR cooldown_seconds <> 0");

        Database::query(
            "UPDATE automation_buckets SET last_error = NULL
             WHERE last_error = ?",
            ['Intentional: Activity 1 (Movement/Logging) is stored locally but never forwarded to LATRA.']
        );
        Database::query(
            "UPDATE automation_runs SET error_message = NULL
             WHERE error_message = ?",
            ['Intentional: Activity 1 (Movement/Logging) is stored locally but never forwarded to LATRA.']
        );

        self::addColumn('automation_run_items', 'next_retry_at DATETIME NULL');
        self::addColumn('automation_run_items', 'claim_id VARCHAR(40) NULL');
        self::addColumn('automation_run_items', 'attempt TINYINT UNSIGNED NOT NULL DEFAULT 0');
        self::addColumn('automation_run_items', 'failure_class VARCHAR(20) NULL');

        self::addColumn('push_log', 'claim_id VARCHAR(40) NULL');
        self::addColumn('push_log', 'attempt TINYINT UNSIGNED NOT NULL DEFAULT 1');

        // Unique guard against duplicate messages (race-safe ingest).
        $idx = Database::fetch(
            "SELECT COUNT(*) AS c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'message_log' AND index_name = 'uk_msg_dedup'"
        );
        if ((int) ($idx['c'] ?? 0) === 0) {
            Database::query(
                "DELETE m1 FROM message_log m1
                 JOIN message_log m2 ON m1.vehicle_id = m2.vehicle_id
                  AND m1.wialon_msg_time = m2.wialon_msg_time AND m1.id < m2.id"
            );
            try {
                Database::query("ALTER TABLE message_log ADD UNIQUE KEY uk_msg_dedup (vehicle_id, wialon_msg_time)");
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate key name') === false
                    && strpos($e->getMessage(), 'already exists') === false) throw $e;
            }
        }
    }

    private static function addColumn(string $table, string $definition): void {
        try {
            Database::query("ALTER TABLE `$table` ADD COLUMN $definition");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) throw $e;
        }
    }

    /**
     * Race-safe message insert (dedup on vehicle_id + wialon_msg_time).
     */
    public static function storeMessage(array $msgData): bool {
        $exists = Database::fetch(
            "SELECT id FROM message_log WHERE vehicle_id = ? AND wialon_msg_time = ?",
            [$msgData['vehicle_id'], $msgData['wialon_msg_time']]
        );
        if ($exists) return false;
        try {
            Database::insert('message_log', $msgData);
            return true;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') return false;
            throw $e;
        }
    }

    /**
     * Claim-on-push: takes ownership of a message for one attempt.
     * Only the claim owner may push; a stale claim (lease expired) is reclaimable.
     */
    public static function claimMessage(int $msgId, int $leaseSeconds): ?string {
        $claimId = bin2hex(random_bytes(16));
        $stmt = Database::query(
            "UPDATE message_log
             SET latra_claim_id = ?, latra_claimed_at = ?, latra_attempts = latra_attempts + 1
             WHERE id = ? AND latra_status IN ('pending','failed') AND max_attempts > latra_attempts
               AND (next_retry_at IS NULL OR next_retry_at <= NOW())
               AND (latra_claimed_at IS NULL OR latra_claimed_at < NOW() - INTERVAL ? SECOND)",
            [$claimId, date(DATE_FORMAT), $msgId, $leaseSeconds]
        );
        return $stmt->rowCount() > 0 ? $claimId : null;
    }

    /** Terminal update after a claim owner has pushed. */
    public static function finishMessage(int $msgId, string $status, array $result, string $claimId, ?string $failClass = null, ?int $backoff = null): void {
        $nextRetry = $backoff !== null ? date(DATE_FORMAT, time() + $backoff) : null;
        Database::update('message_log', [
            'latra_claim_id' => null,
            'latra_claimed_at' => null,
            'latra_status' => $status,
            'latra_response' => $result['curlError'] ?: ($result['response'] ?: 'LATRA rejected the payload'),
            'pushed_at' => date(DATE_FORMAT),
            'next_retry_at' => $nextRetry,
            'latra_manual_at' => $status === 'manual' ? date(DATE_FORMAT) : null,
        ], 'id = ? AND latra_claim_id = ?', [$msgId, $claimId]);
    }

    public static function recordPush(array $msg, int $activityId, array $result, string $claimId, int $attempt): void {
        // Preserve legacy HTTP receipts, but do not duplicate centrally recorded or local-only results.
        if (!empty($result['recorded']) || !empty($result['deduplicated'])
            || (array_key_exists('attempted', $result) && !$result['attempted'])) return;
        Database::insert('push_log', [
            'vehicle_id' => $msg['vehicle_id'],
            'activity_id' => $activityId,
            'message_log_id' => $msg['msg_id'] ?? $msg['id'] ?? null,
            'payload' => json_encode($result['payload']),
            'response_status' => $result['responseData']['status'] ?? null,
            'response_body' => $result['response'] ?: $result['curlError'],
            'http_code' => $result['httpCode'],
            'latency_ms' => $result['latency'],
            'claim_id' => $claimId,
            'attempt' => $attempt,
        ]);
    }

    /** Permanent vs transient so retries only burn on recoverable failures. */
    public static function classifyFailure(int $httpCode, array $responseData, string $curlError): string {
        if ($curlError !== '') return 'transient';
        // Throttling and request timeouts must wait and retry, not become permanent rejection.
        if (in_array($httpCode, [0, 408, 425, 429], true) || $httpCode >= 500) return 'transient';
        if ($httpCode >= 400 && $httpCode <= 499) return 'permanent';
        return 'permanent'; // HTTP 200 with status != '1' — the API rejected the payload
    }

    /**
     * Get activity_ids from all enabled buckets.
     * Returns [1, 2, 3] if buckets for movement/engine_on/engine_off are enabled.
     * Empty array means no activities should be pushed to LATRA.
     */
    public static function enabledBucketActivities(): array {
        $rows = Database::fetchAll(
            "SELECT DISTINCT activity_id FROM automation_buckets WHERE is_enabled = 1"
        );
        return array_map('intval', array_column($rows, 'activity_id'));
    }

    /**
     * Check if a given activity_id has an enabled bucket configured for it.
     * If no buckets are enabled at all, returns false (nothing should be pushed).
     */
    public static function isActivityAllowed(int $activityId): bool {
        $allowed = self::enabledBucketActivities();
        if (empty($allowed)) {
            return false;
        }
        return in_array($activityId, $allowed, true);
    }

    /**
     * Get vehicle_ids from all enabled buckets.
     * Only vehicles in these buckets should have their data pushed to LATRA.
     */
    public static function enabledBucketVehicleIds(): array {
        $rows = Database::fetchAll(
            "SELECT DISTINCT bv.vehicle_id FROM bucket_vehicles bv
             JOIN automation_buckets b ON b.id = bv.bucket_id AND b.is_enabled = 1"
        );
        return array_map('intval', array_column($rows, 'vehicle_id'));
    }

    /** Full-jitter exponential backoff: random_int(0, min(cap, base*2^n)). */
    public static function nextBackoff(int $attempt): int {
        $cap = 600;
        $base = 30 * (2 ** ($attempt - 1));
        return random_int(0, min($cap, max(5, $base)));
    }

    /** Structured single-line JSON to logs/automation-YYYY-MM-DD.log */
    public static function log(string $event, array $ctx = []): void {
        $line = json_encode(array_merge(['ts' => gmdate('Y-m-d\TH:i:s\Z')], ['event' => $event], $ctx));
        @file_put_contents(LOGS_DIR . '/automation-' . date('Y-m-d') . '.log', $line . "\n", FILE_APPEND);
    }

    public static function push(array $message, int $activityId): array {
        $payload = LatraMapper::buildPayload($message, $message, $activityId,
            json_decode($message['addon_info'] ?? '[]', true) ?: [],
            json_decode($message['fuel_info'] ?? '[]', true) ?: []
        );
        return LatraAdapter::submit($payload, (string) ($message['idempotency_key'] ?? ('msg-' . ($message['id'] ?? 'x'))));
    }
}