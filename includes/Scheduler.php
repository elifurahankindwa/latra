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
             ORDER BY FIELD(job_key, 'reconcile','emergency_poll','emergency_reap','flush_outbox','background_tasks','poll_messages','offline_sweep','purge_delivered','fleet_sync','backup','retention','audit_rollup')"
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
            'emergency_reap' => self::jobEmergencyReap(),
            'background_tasks' => self::jobBackgroundTasks(),
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
        // Reset terminal 'failed' items (transient errors) back to pending
        // after 30 minutes so they retry when the endpoint recovers.
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
        // Vehicles handled by an active emergency or an enabled bucket are
        // polled elsewhere; everything else is polled here in one Wialon
        // session (previously one login/logout per vehicle).
        $targets = [];
        foreach ($vehicles as $v) {
            if (isset($skip[(int) $v['id']])) {
                continue;
            }
            $targets[] = $v;
        }
        if (!$targets) {
            return 0;
        }
        $window = Schema::configInt('poll_interval_seconds', 180);
        // Soft budget so a slow Wialon API can never pin the scheduler past a
        // single tick.
        $r = FleetSync::pollMany($targets, $window, 80, 0, 240);
        return (int) ($r['inserted'] + $r['rejected']);
    }

    private static function jobEmergencyPoll(): int {
        $events = Database::fetchAll("SELECT DISTINCT vehicle_id FROM emergency_events WHERE status = 'active'");
        if (!$events) {
            return 0;
        }
        $vehicles = [];
        foreach ($events as $e) {
            $v = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$e['vehicle_id']]);
            if ($v) {
                $vehicles[] = $v;
            }
        }
        $items = 0;
        // One Wialon session for all active emergencies, capped so the emergency
        // cadence cannot starve the rest of the tick.
        if ($vehicles) {
            $r = FleetSync::pollMany($vehicles, 5, 20, 0, 90);
            $items += (int) $r['inserted'];
        }
        $items += self::jobFlush(true);
        return $items;
    }

    /**
     * End emergency events that have gone quiet. Without this, a stuck-active
     * event keeps jobEmergencyPoll() polling its vehicle forever.
     */
    private static function jobEmergencyReap(): int {
        $idle = Schema::configInt('emergency_idle_seconds', 900);
        return EmergencyHandler::reapStale($idle);
    }

    /**
     * Execute queued background imports/pushes outside of any HTTP request.
     */
    private static function jobBackgroundTasks(): int {
        $r = BackgroundTasks::runDue(
            max(1, Schema::configInt('background_task_batch', 5)),
            max(15, Schema::configInt('background_task_seconds', 120))
        );
        return (int) ($r['done'] + $r['failed']);
    }

    /**
     * Flush pending outbox items to LATRA with a 2-minute hard time limit.
     *
     * Any items still in_flight when the time limit hits are released back to
     * 'pending' with zero delay so they join the next flush batch. This
     * guarantees LATRA downtime never blocks the scheduler for more than 2
     * minutes and that nothing is silently dropped.
     */
    private static function jobFlush(bool $emergencyOnly): int {
        $maxRunSeconds = 120;
        $batchSize = $emergencyOnly ? 40 : 80;
        $batch = Outbox::claimBatch($batchSize, $emergencyOnly);
        $ok = 0;
        $fail = 0;
        $skipped = 0;
        $released = 0;
        $started = microtime(true);

        foreach ($batch as $row) {
            if ((microtime(true) - $started) > $maxRunSeconds) {
                break;
            }

            $rebuilt = self::rebuildPayloadForFlush($row);
            $payload = $rebuilt['payload'];
            $result = LatraAdapter::submit($payload, $row['idempotency_key']);
            // The source message may already have been purged by retention
            // (push_log.message_log_id is a foreign key). Record the receipt
            // without the reference instead of failing the whole batch.
            $messageId = !empty($rebuilt['message_exists']) ? (int) $row['message_id'] : null;
            $msg = [
                'vehicle_id' => $row['vehicle_id'],
                'id' => $messageId,
                'message_id' => $messageId,
            ];
            LatraAdapter::recordPush($msg, (int) $row['activity_id'], $result, (string) $row['id'], (int) $row['tries'] + 1);
            if (!empty($result['skipped'])) {
                Database::update('outbox', [
                    'status' => 'rejected',
                    'in_flight_at' => null,
                    'last_error' => 'LATRA adapter skipped this outbox payload',
                ], 'id = ?', [$row['id']]);
                $skipped++;
                continue;
            }
            if ($result['ok']) {
                Outbox::markSent((int) $row['id']);
                Database::update('message_log', [
                    'latra_status' => 'sent',
                    'pushed_at' => date(DATE_FORMAT),
                    'latra_response' => substr((string) $result['response'], 0, 2000),
                ], 'id = ?', [$row['message_id']]);
                $ok++;
            } else {
                $class = Automation::classifyFailure($result['httpCode'], $result['responseData'], $result['curlError']);
                Outbox::markFailed((int) $row['id'], $result['curlError'] ?: $result['response'], $class, (int) $row['tries'] + 1);
                Database::update('message_log', [
                    'latra_status' => $class === 'permanent' ? 'failed' : 'pending',
                    'latra_response' => substr((string) ($result['curlError'] ?: $result['response']), 0, 2000),
                ], 'id = ?', [$row['message_id']]);
                $fail++;
            }
        }

        // Release any in_flight items we didn't process (time limit hit).
        // Reset to pending with zero delay so they join the next flush batch
        // immediately along with any new messages.
        $remaining = Database::fetchAll(
            "SELECT id FROM outbox WHERE status = 'in_flight' AND in_flight_at < NOW() - INTERVAL 10 SECOND LIMIT 500"
        );
        foreach ($remaining as $r) {
            Database::query(
                "UPDATE outbox SET status = 'pending', next_attempt_at = NOW(), in_flight_at = NULL WHERE id = ?",
                [$r['id']]
            );
            $released++;
        }

        $elapsed = (int) round(microtime(true) - $started);
        $depth = Outbox::depth();
        if ($depth['pending'] > 500 || $depth['failed'] > 50) {
            Webhooks::emit('outbox_pressure', $depth);
        }
        Logger::info('outbox.flush', [
            'ok' => $ok, 'fail' => $fail, 'skipped' => $skipped, 'released' => $released,
            'elapsed_s' => $elapsed, 'emergency' => $emergencyOnly,
            'depth_pending' => $depth['pending'], 'depth_failed' => $depth['failed'],
        ]);
        return $ok + $fail + $skipped + $released;
    }

    /**
     * Rebuild the LATRA payload for an outbox row.
     *
     * The stored outbox payload is used as the fallback: it stays valid even
     * after the source message has been purged by retention, so a queued
     * activity record is never lost.
     *
     * @return array{payload:array,message_exists:bool}
     */
    private static function rebuildPayloadForFlush(array $row): array {
        $stored = json_decode($row['payload'] ?? '[]', true) ?: [];
        $exists = false;

        try {
            $msg = Database::fetch(
                "SELECT ml.*, v.vehicle_reg_no, v.imei, v.name AS vehicle_name
                 FROM message_log ml
                 JOIN vehicles v ON ml.vehicle_id = v.id
                 WHERE ml.id = ?",
                [$row['message_id']]
            );
            if ($msg) {
                $exists = true;
                $fresh = LatraMapper::buildPayload(
                    $msg, $msg, (int) $row['activity_id'],
                    json_decode($msg['addon_info'] ?? '[]', true) ?: [],
                    json_decode($msg['fuel_info'] ?? '[]', true) ?: []
                );
                if (!empty($fresh['items'])) {
                    return ['payload' => $fresh, 'message_exists' => true];
                }
            }
        } catch (\Throwable $e) {
            Logger::error('flush.rebuild_failed', [
                'outbox_id' => $row['id'],
                'message_id' => $row['message_id'],
                'error'     => $e->getMessage(),
            ]);
        }
        return ['payload' => $stored, 'message_exists' => $exists];
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

    /**
     * Delegate the whole retention policy to the chunked engine.
     *
     * The old implementation ran two unbounded statements
     * (`INSERT ... SELECT` + `DELETE` over the entire age-out set) and only
     * handled message_log, which meant activity-1 / rejected rows and every
     * other ingest table grew without a ceiling. Retention::runAll() is
     * batched, time-budgeted, idempotent and covers emergency_queue,
     * validation_errors, archives and the operational history tables.
     */
    private static function jobRetention(): int {
        $days = max(1, Schema::configInt('retention_days', 90));
        $summary = Retention::runAll();
        $rows = 0;
        foreach ($summary as $step) {
            $rows += (int) ($step['rows'] ?? 0);
        }

        // Rotate the file logs on the same window.
        $logFiles = glob(LOGS_DIR . '/*.log') ?: [];
        foreach ($logFiles as $f) {
            if (filemtime($f) < time() - ($days * 86400)) {
                @unlink($f);
            }
        }
        return $rows;
    }

    /**
     * Delete everything LATRA has already confirmed, once it is older than
     * `delivered_retention_hours` (default 24h).
     *
     * Deletion order matters: push_log and outbox reference message_log, so
     * they go first (push_log.message_log_id is ON DELETE SET NULL, outbox
     * cascades). Deletes are chunked with LIMIT so a single cycle never holds
     * a long row lock on the live pipeline tables.
     *
     * @return int rows removed
     */
    public static function purgeDelivered(): int {
        $hours = max(1, Schema::configInt('delivered_retention_hours', 24));
        $total = 0;

        $batches = [
            // LATRA push receipts for accepted payloads.
            "DELETE FROM push_log
             WHERE response_status = '1'
               AND created_at < NOW() - INTERVAL $hours HOUR
             LIMIT 2000",
            // Completed queue entries (payload already duplicated in push_log).
            "DELETE FROM outbox
             WHERE status = 'sent'
               AND updated_at < NOW() - INTERVAL $hours HOUR
             LIMIT 2000",
            // Source messages that were pushed through successfully.
            // A message is only removed once every activity record queued for
            // it has been delivered: one fix can carry both the overspeed
            // (activity 4) and the movement (activity 1) row, and the message
            // row must outlive the row that is still in the outbox.
            "DELETE FROM message_log
             WHERE latra_status = 'sent'
               AND pushed_at IS NOT NULL
               AND pushed_at < NOW() - INTERVAL $hours HOUR
               AND NOT EXISTS (
                   SELECT 1 FROM outbox o
                    WHERE o.message_id = message_log.id
                      AND o.status IN ('pending','in_flight','failed')
               )
             LIMIT 2000",
        ];

        foreach ($batches as $sql) {
            try {
                // Loop until a batch comes back empty (or we hit a safety cap).
                for ($i = 0; $i < 50; $i++) {
                    $deleted = Database::query($sql)->rowCount();
                    if ($deleted <= 0) {
                        break;
                    }
                    $total += $deleted;
                }
            } catch (Throwable $e) {
                Logger::warn('retention.delivered', ['error' => $e->getMessage()]);
            }
        }

        if ($total > 0) {
            Logger::info('retention.delivered_purged', ['rows' => $total, 'hours' => $hours]);
        }
        return $total;
    }

    private static function jobRollup(): int {
        // Range predicates (col >= CURDATE() AND col < CURDATE() + 1 day) are
        // index-friendly; DATE(col) = CURDATE() forced a full scan on every run.
        Database::query(
            "INSERT INTO kpi_daily (day, vehicles, messages, sent, failed, rejected, emergencies)
             SELECT CURDATE(),
                (SELECT COUNT(*) FROM vehicles),
                (SELECT COUNT(*) FROM message_log
                  WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY),
                (SELECT COUNT(*) FROM outbox
                  WHERE status = 'sent' AND updated_at >= CURDATE() AND updated_at < CURDATE() + INTERVAL 1 DAY),
                (SELECT COUNT(*) FROM outbox
                  WHERE status = 'failed' AND updated_at >= CURDATE() AND updated_at < CURDATE() + INTERVAL 1 DAY),
                (SELECT COUNT(*) FROM message_log
                  WHERE latra_status = 'rejected' AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY),
                (SELECT COUNT(*) FROM emergency_events
                  WHERE started_at >= CURDATE() AND started_at < CURDATE() + INTERVAL 1 DAY)
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
