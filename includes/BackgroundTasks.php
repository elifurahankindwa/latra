<?php
/**
 * Background task queue.
 *
 * Large imports and data-processing jobs (fleet sync, bulk Wialon fetch, bulk
 * LATRA push) are enqueued here and executed by the scheduler worker instead of
 * inside the originating HTTP request. That keeps request time bounded and
 * removes the gateway-timeout failure mode for large unit sets.
 *
 * Claiming mirrors Outbox::claimBatch(): a single atomic UPDATE stamps a random
 * batch token, then the worker reads back its own rows. MariaDB 10.4 has no
 * SELECT ... SKIP LOCKED, so this token pattern is used instead.
 */
class BackgroundTasks {

    /** Registered task types → handler method. */
    private const TYPES = ['wialon_sync', 'wialon_fetch', 'latra_push'];

    public static function enqueue(string $type, array $params = [], int $priority = 0, int $createdBy = 0): int {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown background task: $type");
        }
        $id = Database::insert('background_tasks', [
            'task_type'   => $type,
            'params'      => json_encode($params, JSON_UNESCAPED_SLASHES),
            'status'      => 'pending',
            'priority'    => $priority,
            'run_after'   => date(DATE_FORMAT),
            'created_by'  => $createdBy ?: null,
            'created_at'  => date(DATE_FORMAT),
        ]);
        Logger::info('bg.enqueue', ['task' => $id, 'type' => $type]);
        return $id;
    }

    /** Claim up to $limit due tasks for this worker. */
    public static function claim(int $limit = 2, int $leaseSeconds = 600): array {
        $limit = max(1, min(20, $limit));
        $token = bin2hex(random_bytes(16));
        $lease = date(DATE_FORMAT, time() + max(30, $leaseSeconds));
        Database::query(
            "UPDATE background_tasks
                SET status = 'running', batch_token = ?, locked_until = ?,
                    attempts = attempts + 1, started_at = COALESCE(started_at, NOW())
              WHERE status = 'pending' AND (run_after IS NULL OR run_after <= NOW())
              ORDER BY priority DESC, id ASC
              LIMIT $limit",
            [$token, $lease]
        );
        return Database::fetchAll("SELECT * FROM background_tasks WHERE batch_token = ?", [$token]);
    }

    public static function complete(int $id, $result): void {
        Database::update('background_tasks', [
            'status'       => 'done',
            'result'       => is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES),
            'error'        => null,
            'finished_at'  => date(DATE_FORMAT),
            'batch_token'  => null,
            'locked_until' => null,
        ], 'id = ?', [$id]);
    }

    public static function fail(int $id, string $error): void {
        $row = Database::fetch("SELECT attempts, max_attempts FROM background_tasks WHERE id = ?", [$id]);
        $attempts = (int) ($row['attempts'] ?? 1);
        $max = max(1, (int) ($row['max_attempts'] ?? 3));
        $terminal = $attempts >= $max;
        Database::update('background_tasks', [
            'status'       => $terminal ? 'failed' : 'pending',
            'error'        => substr($error, 0, 2000),
            'finished_at'  => $terminal ? date(DATE_FORMAT) : null,
            'run_after'    => $terminal ? null : date(DATE_FORMAT, time() + min(300, 15 * $attempts)),
            'batch_token'  => null,
            'locked_until' => null,
        ], 'id = ?', [$id]);
        if ($terminal) {
            Logger::error('bg.failed', ['task' => $id, 'error' => $error]);
            Webhooks::emit('background_task_failed', ['task' => $id, 'error' => $error]);
        } else {
            Logger::warn('bg.retry', ['task' => $id, 'attempt' => $attempts, 'error' => $error]);
        }
    }

    /** Return any tasks whose worker died (lease expired) to the queue. */
    public static function reapStale(): int {
        $stmt = Database::query(
            "UPDATE background_tasks
                SET status = 'pending', batch_token = NULL, locked_until = NULL
              WHERE status = 'running' AND locked_until IS NOT NULL AND locked_until < NOW()"
        );
        return $stmt->rowCount();
    }

    /** Public status snapshot for the polling endpoint. */
    public static function status(int $id): ?array {
        $row = Database::fetch(
            "SELECT id, task_type, status, attempts, result, error, created_at, started_at, finished_at
               FROM background_tasks WHERE id = ?",
            [$id]
        );
        return $row ?: null;
    }

    /**
     * Execute due tasks. Bounded by a task count and a wall-clock budget so the
     * worker always returns to its other duties (polling, flushing) on time.
     */
    public static function runDue(int $maxTasks = 5, int $timeBudget = 120, int $maxPerTask = 0): array {
        $reaped = self::reapStale();
        $tasks = self::claim($maxTasks);
        $done = 0; $failed = 0; $skipped = 0;
        $started = microtime(true);

        foreach ($tasks as $task) {
            $remainingBudget = (int) ($timeBudget - (microtime(true) - $started));
            if ($remainingBudget <= 0) {
                // Give the un-run task straight back to the queue.
                Database::update('background_tasks', [
                    'status' => 'pending', 'batch_token' => null, 'locked_until' => null,
                ], 'id = ?', [$task['id']]);
                $skipped++;
                continue;
            }
            $type = (string) $task['task_type'];
            $params = json_decode($task['params'] ?? '[]', true) ?: [];
            try {
                if (!in_array($type, self::TYPES, true)) {
                    throw new RuntimeException("Unsupported task type: $type");
                }
                Logger::info('bg.start', ['task' => $task['id'], 'type' => $type]);
                $result = self::dispatch($type, $params, $maxPerTask, $remainingBudget);
                self::complete((int) $task['id'], $result);
                $done++;
                // If the per-task budget cut the batch short, queue the rest so
                // no vehicle is silently dropped.
                $rest = $result['remaining_ids'] ?? [];
                if ($rest) {
                    self::enqueue($type, array_merge($params, ['vehicle_ids' => array_values($rest)]), 0, 0);
                    Logger::info('bg.continuation', ['task' => $task['id'], 'type' => $type, 'remaining' => count($rest)]);
                }
            } catch (Throwable $e) {
                self::fail((int) $task['id'], $e->getMessage());
                $failed++;
            }
        }
        return ['reaped' => $reaped, 'done' => $done, 'failed' => $failed, 'skipped' => $skipped, 'claimed' => count($tasks)];
    }

    private static function dispatch(string $type, array $params, int $maxPerTask, int $timeBudget = 0): array {
        return match ($type) {
            'wialon_sync'  => FleetSync::syncAll(),
            'wialon_fetch' => FleetSync::fetchMany(
                array_map('intval', $params['vehicle_ids'] ?? []),
                (int) ($params['from'] ?? (time() - 86400)),
                (int) ($params['to'] ?? time()),
                (int) ($params['limit'] ?? 100),
                $maxPerTask,
                $timeBudget
            ),
            'latra_push'   => FleetSync::pushMany(
                array_map('intval', $params['vehicle_ids'] ?? []),
                (int) ($params['activity_id'] ?? 0),
                $maxPerTask,
                $timeBudget
            ),
            default        => throw new RuntimeException("Unsupported task type: $type"),
        };
    }

    /**
     * Is a scheduler worker actually ticking? Used by the API to decide whether
     * a heavy operation can be safely handed off, or must run inline (e.g. a
     * deployment with no cron/worker installed yet). Mirrors health.php.
     */
    public static function workerActive(int $withinSeconds = 180): bool {
        $file = LOGS_DIR . '/last_tick.txt';
        if (!is_readable($file)) {
            return false;
        }
        $ts = strtotime((string) @file_get_contents($file));
        return $ts !== false && (time() - $ts) <= max(30, $withinSeconds);
    }

    /** Lightweight backlog counters for the health page. */
    public static function depth(): array {
        $row = Database::fetch(
            "SELECT SUM(status = 'pending') AS pending, SUM(status = 'running') AS running, SUM(status = 'failed') AS failed
               FROM background_tasks"
        ) ?: [];
        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'running' => (int) ($row['running'] ?? 0),
            'failed'  => (int) ($row['failed'] ?? 0),
        ];
    }
}
