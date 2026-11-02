<?php
/**
 * Data lifecycle / retention engine.
 *
 * Every purge is chunked (`DELETE ... LIMIT`) with a wall-clock time budget so a
 * single cycle can never hold a long row lock or open a multi-hundred-thousand
 * row transaction. Steps are independent — one failure never blocks the rest —
 * and every run is recorded in `retention_log` plus the JSON logger.
 *
 * Tables fall into three classes (see DATA_LIFECYCLE.md):
 *   - permanent business data  → never touched here
 *   - derived current state     → bounded by fleet size (upserts), never touched
 *   - temporary / ingest history → pruned by the policies below
 *
 * Nothing here deletes pending/failed rows that are still queued for LATRA
 * delivery (the outbox references them), or any row inside the configured hot
 * window. Deletion is therefore always safe to re-run.
 */
class Retention {

    /** Identifiers we are ever allowed to interpolate into SQL. */
    private const TABLES = [
        'message_log', 'message_log_archive', 'push_log', 'push_log_archive',
        'validation_errors', 'emergency_queue', 'emergency_events', 'wialon_events',
        'outbox', 'job_runs', 'automation_runs', 'automation_run_items',
        'audit_log', 'webhook_events', 'rate_limits', 'ai_chat_log', 'ai_conversations',
        'retention_log',
    ];

    private static array $collected = [];
    private static float $budget = 25.0;
    private static bool $dryRun = false;

    /**
     * Resolve the effective retention policy. `$overrides` lets callers (e.g.
     * scripts/maintain.php env vars) replace individual windows.
     */
    public static function policy(array $overrides = []): array {
        $c = static fn(string $k, int $d): int => Schema::configInt($k, $d);
        $stream = $overrides['stream_days'] ?? null;
        $run    = $overrides['run_days'] ?? null;
        return [
            'delivered_hours'    => (int) ($overrides['delivered_hours']    ?? $c('delivered_retention_hours', 24)),
            'rejected_days'      => (int) ($overrides['rejected_days']      ?? $c('rejected_retention_days', 30)),
            'stale_pending_days' => (int) ($overrides['stale_pending_days'] ?? $c('stale_pending_retention_days', 7)),
            'validation_days'    => (int) ($overrides['validation_days']    ?? $c('validation_error_retention_days', 30)),
            'push_log_days'      => (int) ($overrides['push_log_days']      ?? $stream ?? $c('push_log_retention_days', 30)),
            'wialon_events_days' => (int) ($overrides['wialon_events_days'] ?? $stream ?? $c('push_log_retention_days', 30)),
            'webhook_days'       => (int) ($overrides['webhook_days']       ?? $c('webhook_retention_days', 14)),
            'rate_limit_days'    => (int) ($overrides['rate_limit_days']    ?? $c('rate_limit_retention_days', 14)),
            'emergency_days'     => (int) ($overrides['emergency_days']     ?? $c('emergency_retention_days', 7)),
            'job_run_days'       => (int) ($overrides['job_run_days']       ?? $run ?? $c('job_run_retention_days', 60)),
            'automation_days'    => (int) ($overrides['automation_days']    ?? $run ?? $c('job_run_retention_days', 60)),
            'audit_days'         => (int) ($overrides['audit_days']         ?? $c('audit_retention_days', 365)),
            'ai_days'            => (int) ($overrides['ai_days']            ?? $c('ai_retention_days', 180)),
            'archive_days'       => (int) ($overrides['archive_days']       ?? $c('archive_days', 180)),
            'hot_days'           => (int) ($overrides['hot_days']           ?? $c('retention_days', 90)),
            'archive_hot'        => (bool) ($overrides['archive_hot']       ?? ($c('archive_hot_messages', 1) === 1)),
            'batch'              => (int) ($overrides['batch']              ?? 2000),
            'max_batches'        => (int) ($overrides['max_batches']        ?? 250),
            'time_budget'        => (float) ($overrides['time_budget']      ?? 25.0),
        ];
    }

    /**
     * Run every retention step. Returns a summary array keyed by step name.
     */
    public static function runAll(array $overrides = []): array {
        $p = self::policy($overrides);
        self::$budget = max(5.0, (float) $p['time_budget']);
        self::$dryRun = !empty($overrides['dry_run']);
        self::$collected = [];
        $started = microtime(true);
        $opts = [
            'batch'       => $p['batch'],
            'max_batches' => $p['max_batches'],
            'time_budget' => self::$budget,
            'dry_run'     => self::$dryRun,
        ];

        // 1. Delivered messages: LATRA already confirmed them.
        //    A message is only removable once every activity record queued for
        //    it has been delivered. One fix can carry both the overspeed
        //    (activity 4) and the movement (activity 1) outbox row, and the
        //    message row has to outlive the one that is still queued.
        self::step('message_log.delivered', function () use ($p, $opts) {
            $sql = "SELECT ml.id
                      FROM message_log ml
                      LEFT JOIN outbox o
                             ON o.message_id = ml.id
                            AND o.status IN ('pending','in_flight','failed')
                     WHERE ml.latra_status = 'sent'
                       AND ml.pushed_at IS NOT NULL
                       AND ml.pushed_at < NOW() - INTERVAL " . $p['delivered_hours'] . " HOUR
                       AND o.id IS NULL
                     ORDER BY ml.id";
            return self::deleteBySelect('message_log', 'id', $sql, [], $opts);
        });

        // 2. Rejected messages: never deliverable, kept only for audit.
        //    Same guard: a paired activity row may still be queued.
        self::step('message_log.rejected', function () use ($p, $opts) {
            $sql = "SELECT ml.id
                      FROM message_log ml
                      LEFT JOIN outbox o
                             ON o.message_id = ml.id
                            AND o.status IN ('pending','in_flight','failed')
                     WHERE ml.latra_status = 'rejected'
                       AND ml.created_at < NOW() - INTERVAL " . $p['rejected_days'] . " DAY
                       AND o.id IS NULL
                     ORDER BY ml.id";
            return self::deleteBySelect('message_log', 'id', $sql, [], $opts);
        });

        // 3. Stale pending/failed rows that are NOT queued in the outbox.
        //    This is the "temporary import data" bucket: activity-1 movement
        //    logging, rows outside any enabled bucket, and rows whose outbox
        //    entry was already purged. Anything still awaiting delivery is
        //    referenced by the outbox and therefore preserved.
        self::step('message_log.stale_pending', function () use ($p, $opts) {
            $sql = "SELECT ml.id
                      FROM message_log ml
                      LEFT JOIN outbox o ON o.message_id = ml.id
                     WHERE ml.latra_status IN ('pending','failed')
                       AND ml.created_at < NOW() - INTERVAL " . $p['stale_pending_days'] . " DAY
                       AND o.id IS NULL
                     ORDER BY ml.id";
            return self::deleteBySelect('message_log', 'id', $sql, [], $opts);
        });

        // 4. Validation errors (duplicate of rejected message_log).
        self::step('validation_errors', function () use ($p, $opts) {
            return self::deleteBatched(
                'validation_errors',
                'created_at < NOW() - INTERVAL ' . $p['validation_days'] . ' DAY',
                [],
                $opts
            );
        });

        // 5. LATRA push receipts.
        self::step('push_log', function () use ($p, $opts) {
            return self::deleteBatched(
                'push_log',
                'created_at < NOW() - INTERVAL ' . $p['push_log_days'] . ' DAY',
                [],
                $opts
            );
        });

        // 6. Wialon event stream.
        self::step('wialon_events', function () use ($p, $opts) {
            return self::deleteBatched(
                'wialon_events',
                'created_at < NOW() - INTERVAL ' . $p['wialon_events_days'] . ' DAY',
                [],
                $opts
            );
        });

        // 7. Completed outbox rows (payload is duplicated in push_log).
        self::step('outbox.sent', function () use ($p, $opts) {
            return self::deleteBatched(
                'outbox',
                "status = 'sent' AND created_at < NOW() - INTERVAL " . $p['push_log_days'] . ' DAY',
                [],
                $opts
            );
        });
        // 7b. Terminal rejected outbox rows (LATRA will never accept them).
        self::step('outbox.rejected', function () use ($p, $opts) {
            return self::deleteBatched(
                'outbox',
                "status = 'rejected' AND created_at < NOW() - INTERVAL " . $p['push_log_days'] . ' DAY',
                [],
                $opts
            );
        });

        // 8. Emergency buffered records: only for events that have ended (or
        //    whose event row is already gone). Active captures are preserved.
        self::step('emergency_queue', function () use ($p, $opts) {
            $sql = "SELECT q.id
                      FROM emergency_queue q
                      LEFT JOIN emergency_events e ON e.id = q.emergency_event_id
                     WHERE (e.id IS NULL OR e.status IN ('ended','expired'))
                       AND q.created_at < NOW() - INTERVAL " . $p['emergency_days'] . " DAY
                     ORDER BY q.id";
            return self::deleteBySelect('emergency_queue', 'id', $sql, [], $opts);
        });

        // 9. Ended emergency events.
        self::step('emergency_events', function () use ($p, $opts) {
            return self::deleteBatched(
                'emergency_events',
                "status IN ('ended','expired') AND COALESCE(ended_at, started_at) < NOW() - INTERVAL " . $p['emergency_days'] . ' DAY',
                [],
                $opts
            );
        });

        // 10. Operational history.
        self::step('job_runs', function () use ($p, $opts) {
            return self::deleteBatched(
                'job_runs',
                'started_at < NOW() - INTERVAL ' . $p['job_run_days'] . ' DAY',
                [],
                $opts
            );
        });
        self::step('automation_runs', function () use ($p, $opts) {
            return self::deleteBatched(
                'automation_runs',
                'started_at < NOW() - INTERVAL ' . $p['automation_days'] . ' DAY',
                [],
                $opts
            );
        });
        self::step('audit_log', function () use ($p, $opts) {
            return self::deleteBatched(
                'audit_log',
                'created_at < NOW() - INTERVAL ' . $p['audit_days'] . ' DAY',
                [],
                $opts
            );
        });
        self::step('webhook_events', function () use ($p, $opts) {
            return self::deleteBatched(
                'webhook_events',
                'created_at < NOW() - INTERVAL ' . $p['webhook_days'] . ' DAY',
                [],
                $opts
            );
        });
        self::step('rate_limits', function () use ($p, $opts) {
            return self::deleteBatched(
                'rate_limits',
                'window_start < ?',
                [time() - $p['rate_limit_days'] * 86400],
                $opts
            );
        });
        self::step('ai_chat_log', function () use ($p, $opts) {
            return self::deleteBatched(
                'ai_chat_log',
                'created_at < NOW() - INTERVAL ' . $p['ai_days'] . ' DAY',
                [],
                $opts
            );
        });
        self::step('ai_conversations', function () use ($p, $opts) {
            return self::deleteBatched(
                'ai_conversations',
                'updated_at < NOW() - INTERVAL ' . $p['ai_days'] . ' DAY',
                [],
                $opts
            );
        });

        // 11. Long-term archive: move rows older than the hot window, then trim
        //     the archive itself. Archiving is idempotent (INSERT IGNORE on the
        //     shared primary key) so an interrupted run resumes safely.
        if (!empty($p['archive_hot'])) {
            self::step('message_log.archive', function () use ($p, $opts) {
                return self::archiveBatched(
                    'message_log',
                    'message_log_archive',
                    'id',
                    'created_at < NOW() - INTERVAL ' . $p['hot_days'] . ' DAY',
                    [],
                    $opts
                );
            });
        }
        self::step('message_log_archive', function () use ($p, $opts) {
            return self::deleteBatched(
                'message_log_archive',
                'created_at < NOW() - INTERVAL ' . $p['archive_days'] . ' DAY',
                [],
                $opts
            );
        });
        self::step('push_log_archive', function () use ($p, $opts) {
            return self::deleteBatched(
                'push_log_archive',
                'created_at < NOW() - INTERVAL ' . $p['archive_days'] . ' DAY',
                [],
                $opts
            );
        });

        // 12. The engine's own audit trail stays bounded too.
        self::step('retention_log', function () use ($p, $opts) {
            return self::deleteBatched(
                'retention_log',
                'ran_at < NOW() - INTERVAL ' . $p['audit_days'] . ' DAY',
                [],
                $opts
            );
        });

        $rows = 0;
        foreach (self::$collected as $row) {
            $rows += (int) ($row['rows'] ?? 0);
        }
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        self::record($elapsed, $rows);

        return self::$collected;
    }

    // ------------------------------------------------------------------
    // Primitives
    // ------------------------------------------------------------------

    /**
     * Delete matching rows in bounded batches.
     *
     * @return array{rows:int,batches:int,reason:string}
     */
    public static function deleteBatched(string $table, string $where, array $params = [], array $opts = []): array {
        self::assertTable($table);
        return self::loop($opts, function (int $limit) use ($table, $where, $params, $opts) {
            if (!empty($opts['dry_run'])) {
                return (int) (Database::fetch("SELECT COUNT(*) c FROM `$table` WHERE $where", $params)['c'] ?? 0);
            }
            return Database::query("DELETE FROM `$table` WHERE $where LIMIT $limit", $params)->rowCount();
        });
    }

    /**
     * Batched delete driven by a SELECT of primary keys.
     *
     * MariaDB does not allow `LIMIT` on a multi-table DELETE, so instead of
     * deleting through a join we select a bounded page of ids (optionally
     * joining other tables) and delete those. `$selectSql` must be a complete
     * `SELECT <pk> ...` with no LIMIT; it is re-used for the dry-run count.
     */
    public static function deleteBySelect(string $table, string $pk, string $selectSql, array $params = [], array $opts = []): array {
        self::assertTable($table);
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $pk)) {
            throw new InvalidArgumentException("Unsafe column: $pk");
        }
        return self::loop($opts, function (int $limit) use ($table, $pk, $selectSql, $params, $opts) {
            if (!empty($opts['dry_run'])) {
                return (int) (Database::fetch("SELECT COUNT(*) c FROM ($selectSql) x", $params)['c'] ?? 0);
            }
            $ids = array_column(Database::fetchAll("$selectSql LIMIT $limit", $params), $pk);
            if (!$ids) {
                return 0;
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            return Database::query("DELETE FROM `$table` WHERE `$pk` IN ($in)", $ids)->rowCount();
        });
    }

    /**
     * Archive + delete in bounded batches. A row is only removed once it is
     * confirmed present in the archive, so an interrupted/failed copy never
     * loses data.
     *
     * @return array{rows:int,batches:int,reason:string}
     */
    public static function archiveBatched(string $table, string $archive, string $pk, string $where, array $params = [], array $opts = []): array {
        self::assertTable($table);
        self::assertTable($archive);
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $pk)) {
            throw new InvalidArgumentException("Unsafe column: $pk");
        }
        $opts = self::normaliseOpts($opts);
        $rows = 0; $batches = 0; $reason = 'done'; $start = microtime(true);

        for ($i = 0; $i < $opts['max_batches']; $i++) {
            if ((microtime(true) - $start) > $opts['time_budget']) { $reason = 'time_budget'; break; }

            $ids = array_column(
                Database::fetchAll("SELECT `$pk` FROM `$table` WHERE $where ORDER BY `$pk` ASC LIMIT {$opts['batch']}", $params),
                $pk
            );
            if (!$ids) { break; }
            if ($opts['dry_run']) {
                $rows = (int) (Database::fetch("SELECT COUNT(*) c FROM `$table` WHERE $where", $params)['c'] ?? 0);
                $batches = 1; $reason = 'dry_run';
                break;
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            Database::query("INSERT IGNORE INTO `$archive` SELECT * FROM `$table` WHERE `$pk` IN ($in)", $ids);
            // Only delete ids we can positively see in the archive.
            $safe = array_column(
                Database::fetchAll("SELECT `$pk` FROM `$archive` WHERE `$pk` IN ($in)", $ids),
                $pk
            );
            if ($safe) {
                $inSafe = implode(',', array_fill(0, count($safe), '?'));
                $rows += Database::query("DELETE FROM `$table` WHERE `$pk` IN ($inSafe)", $safe)->rowCount();
            }
            $batches++;
            if (count($ids) < $opts['batch']) { break; }
        }
        return ['rows' => $rows, 'batches' => $batches, 'reason' => $reason];
    }

    /** Bounded count used by the admin preview / dry runs. */
    public static function count(string $table, string $where, array $params = []): int {
        self::assertTable($table);
        return (int) (Database::fetch("SELECT COUNT(*) c FROM `$table` WHERE $where", $params)['c'] ?? 0);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function loop(array $opts, callable $delete): array {
        $opts = self::normaliseOpts($opts);
        $rows = 0; $batches = 0; $reason = 'done'; $start = microtime(true);
        for ($i = 0; $i < $opts['max_batches']; $i++) {
            if ((microtime(true) - $start) > $opts['time_budget']) { $reason = 'time_budget'; break; }
            $n = (int) $delete($opts['batch']);
            if ($n <= 0) { break; }
            $rows += $n; $batches++;
            if ($opts['dry_run']) { $reason = 'dry_run'; break; }
            if ($n < $opts['batch']) { break; }
        }
        return ['rows' => $rows, 'batches' => $batches, 'reason' => $reason];
    }

    private static function normaliseOpts(array $opts): array {
        return [
            'batch'       => max(1, min(20000, (int) ($opts['batch'] ?? 2000))),
            'max_batches' => max(1, (int) ($opts['max_batches'] ?? 250)),
            'time_budget' => max(1.0, (float) ($opts['time_budget'] ?? self::$budget)),
            'dry_run'     => !empty($opts['dry_run']),
        ];
    }

    private static function step(string $name, callable $fn): void {
        try {
            $r = $fn();
            $r['step'] = $name;
            self::$collected[] = $r;
            if (($r['rows'] ?? 0) > 0) {
                Logger::info('retention.step', $r + ['dry_run' => self::$dryRun]);
            }
        } catch (Throwable $e) {
            self::$collected[] = ['step' => $name, 'rows' => 0, 'batches' => 0, 'reason' => 'error', 'error' => $e->getMessage()];
            Logger::warn('retention.step_failed', ['step' => $name, 'error' => $e->getMessage()]);
        }
    }

    private static function record(int $elapsedMs, int $rows): void {
        try {
            Database::insert('retention_log', [
                'ran_at'      => date(DATE_FORMAT),
                'duration_ms' => $elapsedMs,
                'rows_deleted'=> $rows,
                'dry_run'     => self::$dryRun ? 1 : 0,
                'summary'     => json_encode(self::$collected, JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            // retention_log is optional/best-effort.
            Logger::warn('retention.log_failed', ['error' => $e->getMessage()]);
        }
        Logger::info('retention.run', [
            'rows' => $rows, 'duration_ms' => $elapsedMs, 'dry_run' => self::$dryRun,
            'steps' => array_map(static fn($r) => ($r['step'] . '=' . ($r['rows'] ?? 0)), self::$collected),
        ]);
    }

    private static function assertTable(string $table): void {
        if (!in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException("Retention: table not allowed: $table");
        }
    }
}
