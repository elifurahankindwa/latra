<?php
/**
 * Scheduler.
 */
class Scheduler {
    public static function tick(bool $loopOnce = true): void {
        Schema::ensure();
        @file_put_contents(LOGS_DIR . '/last_tick.txt', date(DATE_FORMAT));

        // Buckets run under their own lock so heavy background jobs can never
        // starve the LATRA data submission cadence.
        self::tickBuckets();
        self::tickJobs();
    }

    /** Run due automation buckets under a dedicated lock. */
    public static function tickBuckets(): void {
        $lock = Database::fetch("SELECT GET_LOCK('latra-scheduler-buckets', 0) AS acquired");
        if (!(int) ($lock['acquired'] ?? 0)) {
            echo "[" . date(DATE_FORMAT) . "] Bucket scheduler already running.\n";
            return;
        }
        try {
            if (Schema::config('scheduler_enabled', '1') !== '1') {
                return;
            }
            self::runDueBuckets();
        } catch (Throwable $e) {
            Logger::error('bucket.tick', ['error' => $e->getMessage()]);
        } finally {
            try {
                Database::query("SELECT RELEASE_LOCK('latra-scheduler-buckets')");
            } catch (Throwable $ignored) {
            }
        }
    }

    /** Run due background jobs under a separate lock. */
    public static function tickJobs(): void {
        $lock = Database::fetch("SELECT GET_LOCK('latra-scheduler', 0) AS acquired");
        if (!(int) ($lock['acquired'] ?? 0)) {
            echo "[" . date(DATE_FORMAT) . "] Scheduler already running.\n";
            return;
        }
        try {
            if (Schema::config('scheduler_enabled', '1') !== '1') {
                echo "[" . date(DATE_FORMAT) . "] Scheduler disabled.\n";
                return;
            }
            self::runDueJobs();
        } finally {
            try {
                Database::query("SELECT RELEASE_LOCK('latra-scheduler')");
            } catch (Throwable $ignored) {
            }
        }
    }

    private static function runDueJobs(): void {
        // Hard rule: activity-1 rows must never reach LATRA. Sweep any strays
        // left from configs that predate the exclusion before jobs run.

        $jobs = Database::fetchAll(
            "SELECT * FROM jobs WHERE is_enabled = 1 AND (next_run_at IS NULL OR next_run_at <= NOW())
             ORDER BY FIELD(job_key, 'reconcile','emergency_poll','flush_outbox','poll_messages','offline_sweep','purge_delivered','fleet_sync','backup','retention','audit_rollup')"
        );
        foreach ($jobs as $job) {
            $key = $job['job_key'];
            $jlock = Database::fetch("SELECT GET_LOCK(?, 0) AS acquired", ['latra-job-' . $key]);
            if (!(int) ($jlock['acquired'] ?? 0)) {
                continue;
            }
            $started = microtime(true);
            $runId = Database::insert('job_runs', [
                'job_key' => $key,
                'status' => 'running',
                'started_at' => date(DATE_FORMAT),
            ]);
            $items = 0;
            $error = null;
            try {
                $items = self::execute($key);
                Database::update('job_runs', [
                    'status' => 'ok',
                    'finished_at' => date(DATE_FORMAT),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'items_processed' => $items,
                ], 'id = ?', [$runId]);
                Database::update('jobs', [
                    'last_run_at' => date(DATE_FORMAT),
                    'next_run_at' => date(DATE_FORMAT, time() + max(1, (int) $job['interval_seconds'])),
                    'last_status' => 'ok',
                    'last_error' => null,
                    'run_count' => (int) $job['run_count'] + 1,
                ], 'id = ?', [$job['id']]);
            } catch (Throwable $e) {
                $error = $e->getMessage();
                Logger::error('job.failed', ['job' => $key, 'error' => $error]);
                try {
                    Database::update('job_runs', [
                        'status' => 'failed',
                        'finished_at' => date(DATE_FORMAT),
                        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                        'error' => $error,
                    ], 'id = ?', [$runId]);
                    Database::update('jobs', [
                        'last_run_at' => date(DATE_FORMAT),
                        'next_run_at' => date(DATE_FORMAT, time() + 30),
                        'last_status' => 'failed',
                        'last_error' => $error,
                    ], 'id = ?', [$job['id']]);
                    Webhooks::emit('job_failed', ['job' => $key]);
                } catch (Throwable $ignored) {
                }
            } finally {
                try {
                    Database::query("SELECT RELEASE_LOCK(?)", ['latra-job-' . $key]);
                } catch (Throwable $ignored) {
                }
            }
        }
    }

    private static function execute(string $key): int {
        return match ($key) {
            'reconcile' => self::jobReconcile(),
            'fleet_sync' => self::jobFleetSync(),
            'fuel_report' => self::jobFuelReport(),
            'poll_messages' => self::jobPoll(),
            'emergency_poll' => self::jobEmergencyPoll(),
            'flush_outbox' => self::jobFlush(false),
            'offline_sweep' => self::jobOffline(),
            'backup' => self::jobBackup(),
            'retention' => self::jobRetention(),
            'purge_delivered' => self::purgeDelivered(),
            'audit_rollup' => self::jobRollup(),
            default => 0,
        };
    }

    private static function jobReconcile(): int {
        $n = Outbox::reconcileStale(300);
        // Preserve the original automatic cooldown; only actual attempts consume retry budget.
        $reset = Outbox::resetTerminal(1800);
        Database::query(
            "UPDATE jobs SET locked_until = NULL WHERE locked_until IS NOT NULL AND locked_until < NOW()"
        );
        if ($n > 0 || $reset > 0) {
            Logger::warn('outbox.reconciled', ['stale' => $n, 'reset' => $reset]);
        }
        return $n + $reset;
    }

    private static function jobFleetSync(): int {
        $r = FleetSync::syncAll();
        return $r['synced'] + $r['updated'];
    }

    private static function jobPoll(): int {
        $vehicles = Database::fetchAll("SELECT * FROM vehicles WHERE is_active = 1");
        $activeEmergency = Database::fetchAll(
            "SELECT DISTINCT vehicle_id FROM emergency_events WHERE status = 'active'"
        );
        $skip = [];
        foreach ($activeEmergency as $e) {
            $skip[(int) $e['vehicle_id']] = true;
        }
        $bucketVehicles = Database::fetchAll(
            "SELECT DISTINCT vehicle_id FROM bucket_vehicles bv
             JOIN automation_buckets b ON b.id = bv.bucket_id AND b.is_enabled = 1"
        );
        foreach ($bucketVehicles as $bv) {
            $skip[(int) $bv['vehicle_id']] = true;
        }
        $items = 0;
        $window = Schema::configInt('poll_interval_seconds', 180);
        foreach ($vehicles as $v) {
            if (isset($skip[(int) $v['id']])) {
                continue;
            }
            try {
                $r = FleetSync::pollVehicle($v, $window, 80);
                $items += $r['inserted'] + $r['rejected'];
            } catch (Throwable $e) {
                Logger::warn('poll.vehicle_failed', ['vehicle' => $v['id'], 'error' => $e->getMessage()]);
            }
        }
        return $items;
    }

    private static function jobEmergencyPoll(): int {
        $events = Database::fetchAll("SELECT DISTINCT vehicle_id FROM emergency_events WHERE status = 'active'");
        if (!$events) {
            return 0;
        }
        $items = 0;
        foreach ($events as $e) {
            $v = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$e['vehicle_id']]);
            if (!$v) {
                continue;
            }
            try {
                $r = FleetSync::pollVehicle($v, 5, 20);
                $items += $r['inserted'];
            } catch (Throwable $ex) {
                Logger::warn('emergency.poll_failed', ['vehicle' => $v['id']]);
            }
        }
        $items += self::jobFlush(true);
        return $items;
    }

    /** Drain small candidate pages without claiming work that cannot start in time. */
    private static function jobFlush(bool $emergencyOnly): int {
        $started = hrtime(true);
        $budget = 120; // The original two-minute budget applies to both flush modes.
        $limit = $emergencyOnly ? 40 : 80;
        $ok = $fail = $deferred = $processed = 0;
        // Start only work that fits within the original overall deadline.
        while ($processed < $limit && (hrtime(true) - $started) / 1e9 < $budget) {
            $rows = Outbox::claimBatch(1, $emergencyOnly);
            if (!$rows) break;
            $row = $rows[0];
            // Delivery validates and reuses the stored body while holding its key lock.
            $remaining = (int) floor($budget - (hrtime(true) - $started) / 1e9);
            if ($remaining <= 0) break; // Selection alone does not claim or charge the row.
            $result = LatraAdapter::submit([], $row['idempotency_key'], $remaining);
            $processed++;
            if (!empty($result['deferred'])) {
                $deferred++;
                // Another sender may own this first candidate; do not busy-spin on it.
                break;
            }
            LatraAdapter::recordPush(['vehicle_id' => $row['vehicle_id'], 'id' => $row['message_id']],
                (int) $row['activity_id'], $result, (string) $row['id'], (int) $row['tries'] + 1);
            if (!empty($result['ok'])) {
                Database::update('message_log', ['latra_status' => 'sent',
                    'pushed_at' => date(DATE_FORMAT),
                    'latra_response' => substr((string) $result['response'], 0, 2000)], 'id = ?', [$row['message_id']]);
                $ok++;
            } else {
                // Delivery owns retry state; source projections must not overwrite that state.
                $fail++;
            }
        }
        // Preserve pressure alerts using bounded index probes instead of a full-history SUM.
        $pressure = Outbox::pressure();
        if ($pressure !== null) Webhooks::emit('outbox_pressure', $pressure);
        // Flush telemetry is constant work rather than an aggregate over all retained queue history.
        Logger::info('outbox.flush', ['ok' => $ok, 'fail' => $fail, 'deferred' => $deferred,
            'elapsed_s' => round((hrtime(true) - $started) / 1e9, 3), 'emergency' => $emergencyOnly]);
        return $processed;
    }

    private static function jobFuelReport(): int {
        $vehicles = Database::fetchAll(
            "SELECT id, wialon_unit_id, name, fuel_level_sensor
             FROM vehicles
             WHERE is_active = 1 AND wialon_unit_id > 0
               AND fuel_level_sensor IS NOT NULL AND fuel_level_sensor <> ''"
        );
        if (empty($vehicles)) {
            return 0;
        }

        $pushCount = 0;
        $skipped = 0;
        foreach ($vehicles as $veh) {
            $unitId = (int) $veh['wialon_unit_id'];
            $data = WialonAPI::getUnitData($unitId);
            if (!is_array($data)) {
                $skipped++;
                Logger::info('fuel.skipped', [
                    'vehicle_id' => $veh['id'],
                    'reason'     => 'no_unit_data',
                    'unit_id'    => $unitId,
                ]);
                continue;
            }

            $fuelValue = null;
            $fuelUnit = null;
            foreach (($data['sens'] ?? []) as $sens) {
                $t = (string) ($sens['t'] ?? '');
                if ($t === (string) $veh['fuel_level_sensor']) {
                    $fuelValue = $sens['v'] ?? null;
                    $fuelUnit = $sens['u'] ?? null;
                    break;
                }
            }
            if ($fuelValue === null || $fuelValue === '') {
                $skipped++;
                Logger::info('fuel.skipped', [
                    'vehicle_id' => $veh['id'],
                    'reason'     => 'no_live_fuel_value',
                    'unit_id'    => $unitId,
                    'sensor'     => $veh['fuel_level_sensor'],
                ]);
                continue;
            }

            $payload = LatraMapper::buildPayload(
                $veh, $veh, EventDetector::FUEL_REPORT,
                [],
                ['fuel_level' => $fuelValue, 'fuel_sensor_param' => $veh['fuel_level_sensor'], 'unit' => $fuelUnit]
            );
            $result = LatraAdapter::submit($payload, 'fuel-report-' . $veh['id'] . '-' . floor(time() / 1800));
            if (!empty($result['ok']) || !empty($result['skipped'])) {
                $pushCount++;
            } else {
                $skipped++;
                Logger::warn('fuel.push_failed', [
                    'vehicle_id' => $veh['id'],
                    'error'      => $result['curlError'] ?? ($result['response'] ?? 'unknown'),
                ]);
            }
        }
        Logger::info('fuel.report', ['pushed' => $pushCount, 'skipped' => $skipped]);
        return $pushCount;
    }

    private static function jobOffline(): int {
        GpsMonitor::refresh();
        $n = DeviceMonitor::markOffline();
        if ($n > 0) {
            Webhooks::emit('device_offline', ['count' => $n]);
        }
        return $n;
    }

    private static function jobBackup(): int {
        $script = dirname(__DIR__) . '/scripts/backup.sh';
        if (!is_executable($script) && !is_file($script)) {
            throw new RuntimeException('backup.sh missing');
        }
        $cmd = 'bash ' . escapeshellarg($script);
        exec($cmd, $out, $code);
        if ($code !== 0) {
            Webhooks::emit('backup_failed', ['code' => $code]);
            throw new RuntimeException('backup exit ' . $code);
        }
        return 1;
    }

    /** Archive in small transactions while honoring the original daily retention window. */
    private static function jobRetention(): int {
        $days = Schema::configInt('retention_days', 90);
        $moved = Retention::archiveMessages($days);
        $moved += self::purgeDelivered();
        // Keep the original log age policy and leave filesystem cleanup on its existing schedule.
        foreach (glob(LOGS_DIR . '/*.log') ?: [] as $file) {
            if (filemtime($file) < time() - $days * 86400) @unlink($file);
        }
        return $moved;
    }

    /** Honor the original hour-based purge policy and its existing scheduled job. */
    public static function purgeDelivered(): int {
        return Retention::purgeDelivered();
    }

    private static function jobRollup(): int {
        Database::query(
            "INSERT INTO kpi_daily (day, vehicles, messages, sent, failed, rejected, emergencies)
             SELECT CURDATE(),
                (SELECT COUNT(*) FROM vehicles),
                (SELECT COUNT(*) FROM message_log WHERE DATE(created_at) = CURDATE()),
                (SELECT COUNT(*) FROM outbox WHERE status = 'sent' AND DATE(updated_at) = CURDATE()),
                (SELECT COUNT(*) FROM outbox WHERE status = 'failed' AND DATE(updated_at) = CURDATE()),
                (SELECT COUNT(*) FROM message_log WHERE latra_status = 'rejected' AND DATE(created_at) = CURDATE()),
                (SELECT COUNT(*) FROM emergency_events WHERE DATE(started_at) = CURDATE())
             ON DUPLICATE KEY UPDATE
                vehicles = VALUES(vehicles), messages = VALUES(messages), sent = VALUES(sent),
                failed = VALUES(failed), rejected = VALUES(rejected), emergencies = VALUES(emergencies)"
        );
        return 1;
    }

    // Bucket automation overlay.
    private static function runDueBuckets(): void {
        // Clean orphaned running jobs.
        Database::query(
            "UPDATE automation_runs SET status = 'orphaned', finished_at = NOW(), error_message = 'run timed out (orphan cleanup)'
             WHERE status = 'running' AND started_at < NOW() - INTERVAL 3 MINUTE"
        );

        $buckets = Database::fetchAll(
            "SELECT * FROM automation_buckets WHERE is_enabled = 1 AND (next_run_at IS NULL OR next_run_at <= NOW()) ORDER BY COALESCE(next_run_at, '1970-01-01'), id"
        );
        foreach ($buckets as $bucket) {
            self::runBucket($bucket);
        }
    }

    public static function runBucket(array $bucket): void {
        $bucketId = (int) $bucket['id'];
        if (!Database::fetch("SELECT GET_LOCK(?, 0) AS acquired", ['latra-bucket-' . $bucketId])['acquired']) {
            return;
        }
        // Reserve the slot before running.
        $triggeredBy = (int) ($bucket['run_on_demand'] ?? 0) === 1 ? 'manual' : 'schedule';
        $interval = 180;
        $cooldown = 0;
        Database::update('automation_buckets', [
            'next_run_at' => date(DATE_FORMAT, time() + $interval + $cooldown),
            'run_count' => (int) $bucket['run_count'] + 1,
            'run_on_demand' => 0,
        ], 'id = ?', [$bucketId]);

        $started = microtime(true);
        $runId = null;
        try {
            $runId = Database::insert('automation_runs', [
                'bucket_id' => $bucketId,
                'status' => 'running',
                'triggered_by' => $triggeredBy,
                'started_at' => date(DATE_FORMAT),
            ]);
            $stats = BucketRunner::run($bucket);
            $failed = (int) ($stats['failed'] ?? 0);
            $success = (int) ($stats['pushed'] ?? 0);
            $skipped = (int) ($stats['skipped'] ?? 0) + (int) ($stats['denied'] ?? 0);
            $total = $success + $failed + $skipped;
            $skipReason = '';
            if ($skipped > 0) {
                if ((int) ($stats['denied'] ?? 0) > 0) {
                    $skipReason = 'Some vehicles were skipped because Wialon access was denied or the unit ID is unavailable.';
                } else {
                    $skipReason = 'Some vehicles were skipped because no new Wialon telemetry was available for this interval.';
                }
            }
            Database::update('automation_runs', [
                'status' => $failed ? 'completed_failed' : 'completed',
                'finished_at' => date(DATE_FORMAT),
                'duration_seconds' => (int) round(microtime(true) - $started),
                'total_count' => $total,
                'success_count' => $success,
                'failed_count' => $failed,
                'manual_count' => 0,
                'skipped_count' => $skipped,
                'error_message' => $skipReason ?: null,
            ], 'id = ?', [$runId]);
            Database::update('automation_buckets', [
                'last_run_at' => date(DATE_FORMAT),
                'fail_count' => $failed > 0 ? ((int) $bucket['fail_count'] + $failed) : (int) $bucket['fail_count'],
                'consecutive_failures' => $failed > 0 ? ((int) $bucket['consecutive_failures'] + 1) : 0,
                'last_error' => $failed ? "$failed failed, $skipped skipped" : ($skipReason ?: null),
            ], 'id = ?', [$bucketId]);
            if ($skipped > 0) {
                Logger::warn('bucket.partial', ['bucket' => $bucketId, 'skipped' => $skipped, 'denied' => (int) ($stats['denied'] ?? 0)]);
            }
        } catch (Throwable $e) {
            Logger::error('bucket.error', ['bucket' => $bucketId, 'error' => $e->getMessage()]);
            if ($runId) {
                Database::update('automation_runs', [
                    'status' => 'failed',
                    'finished_at' => date(DATE_FORMAT),
                    'error_message' => $e->getMessage(),
                ], 'id = ?', [$runId]);
            }
            Database::update('automation_buckets', [
                'last_run_at' => date(DATE_FORMAT),
                'fail_count' => (int) $bucket['fail_count'] + 1,
                'consecutive_failures' => (int) $bucket['consecutive_failures'] + 1,
                'last_error' => $e->getMessage(),
            ], 'id = ?', [$bucketId]);
        } finally {
            Database::query("SELECT RELEASE_LOCK(?)", ['latra-bucket-' . $bucketId]);
        }
    }
}
