<?php
/** Execute the authors' existing retention policies in smaller transactions. */
class Retention {
    /** Preserve the 24-hour default and original 2,000-row/50-batch purge limits. */
    public static function purgeDelivered(): int {
        $hours = max(1, Schema::configInt('delivered_retention_hours', 24));
        $cutoff = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        return self::locked(static function () use ($cutoff): int {
            $removed = self::deletePages('push_log', "response_status = '1' AND created_at < ? AND " . self::receiptSafe(), [$cutoff], 50);
            $removed += self::deletePages('message_log', "latra_status = 'sent' AND pushed_at IS NOT NULL AND pushed_at < ? AND " . self::sourceSafe(), [$cutoff], 50);
            // Remove completed queue records after source cleanup, never orphan unfinished work.
            $removed += self::deletePages('outbox', "status = 'sent' AND updated_at < ?
                AND NOT EXISTS (SELECT 1 FROM message_log m WHERE m.id = outbox.message_id)", [$cutoff], 50);
            return $removed;
        });
    }

    /** Preserve the daily archive's configured day window and copy before removing source rows. */
    public static function archiveMessages(int $days): int {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        return self::locked(static fn(): int => self::deletePages('message_log',
            "created_at < ? AND latra_status = 'sent' AND " . self::sourceSafe(), [$cutoff], null, true));
    }

    /** Preserve all existing CLI age settings instead of replacing them with scheduler defaults. */
    public static function maintain(): int {
        $stream = self::age('MAINTAIN_STREAM_DAYS', 14);
        $runs = self::age('MAINTAIN_RUN_DAYS', 60);
        $audit = self::age('MAINTAIN_AUDIT_DAYS', 365);
        $ai = self::age('MAINTAIN_AI_DAYS', 180);
        return self::locked(static function () use ($stream, $runs, $audit, $ai): int {
            $removed = self::deletePages('message_log', "latra_status = 'sent' AND pushed_at IS NOT NULL AND pushed_at < ? AND " . self::sourceSafe(), [$stream]);
            $removed += self::deletePages('push_log', 'created_at < ? AND ' . self::receiptSafe(), [$stream]);
            $removed += self::deletePages('wialon_events', "status = 'sent' AND created_at < ?
                AND NOT EXISTS (SELECT 1 FROM message_log m WHERE m.id = wialon_events.message_log_id)", [$stream]);
            // Keep the CLI's original created_at age rule, distinct from the hourly updated_at rule.
            $removed += self::deletePages('outbox', "status = 'sent' AND created_at < ?
                AND NOT EXISTS (SELECT 1 FROM message_log m WHERE m.id = outbox.message_id)", [$stream]);
            $removed += self::deletePages('webhook_events', "status = 'sent' AND created_at < ?", [$stream]);
            // Delete eligible child pages first so parent cascades cannot erase unresolved or huge histories.
            $removed += self::deletePages('automation_run_items', "status IN ('sent','skipped')
                AND EXISTS (SELECT 1 FROM automation_runs r WHERE r.id = automation_run_items.run_id AND r.started_at < ?)", [$runs]);
            $removed += self::deletePages('automation_runs', "started_at < ?
                AND NOT EXISTS (SELECT 1 FROM automation_run_items r WHERE r.run_id = automation_runs.id)", [$runs]);
            $removed += self::deletePages('job_runs', "started_at < ?", [$runs]);
            $removed += self::deletePages('audit_log', 'created_at < ?', [$audit]);
            $removed += self::deletePages('ai_chat_log', 'created_at < ?', [$ai]);
            $removed += self::deletePages('ai_conversations', "updated_at < ?
                AND NOT EXISTS (SELECT 1 FROM ai_chat_log c WHERE c.conversation_id = ai_conversations.conversation_id)", [$ai]);
            $removed += self::rateLimits((int) strtotime($stream . ' UTC'));
            return $removed;
        });
    }

    /** Protect records still required by any unfinished delivery path. */
    private static function sourceSafe(): string {
        return "NOT EXISTS (SELECT 1 FROM outbox o WHERE o.message_id = message_log.id AND o.status <> 'sent')
            AND NOT EXISTS (SELECT 1 FROM wialon_events e WHERE e.message_log_id = message_log.id AND e.status <> 'sent')
            AND NOT EXISTS (SELECT 1 FROM automation_run_items r WHERE r.message_log_id = message_log.id AND r.status NOT IN ('sent','skipped'))";
    }

    /** Keep attempt evidence while its source or dependent delivery remains unresolved. */
    private static function receiptSafe(): string {
        return "NOT EXISTS (SELECT 1 FROM outbox o WHERE o.message_id = push_log.message_log_id AND o.status <> 'sent')
            AND NOT EXISTS (SELECT 1 FROM message_log m WHERE m.id = push_log.message_log_id AND m.latra_status <> 'sent')
            AND NOT EXISTS (SELECT 1 FROM wialon_events e WHERE e.message_log_id = push_log.message_log_id AND e.status <> 'sent')
            AND NOT EXISTS (SELECT 1 FROM automation_run_items r WHERE r.message_log_id = push_log.message_log_id AND r.status NOT IN ('sent','skipped'))";
    }

    /** Share cleanup ownership without changing how often its existing callers run. */
    private static function locked(callable $work): int {
        $pdo = Database::getInstance();
        if ((int) $pdo->query("SELECT GET_LOCK('latra-retention', 0)")->fetchColumn() !== 1) return 0;
        try { return $work(); }
        finally { $pdo->query("SELECT RELEASE_LOCK('latra-retention')"); }
    }

    /** Reuse the original purge page size; uncapped callers still finish all eligible history. */
    private static function deletePages(string $table, string $condition, array $params, ?int $maxBatches = null, bool $archive = false): int {
        $pdo = Database::getInstance();
        $removed = 0;
        for ($batch = 0; $maxBatches === null || $batch < $maxBatches; $batch++) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT id FROM $table WHERE $condition ORDER BY id LIMIT 2000 FOR UPDATE");
                $stmt->execute($params);
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!$ids) { $pdo->commit(); break; }
                $in = implode(',', array_fill(0, count($ids), '?'));
                if ($archive) {
                    // An archive/schema conflict rolls back the move; do not silently discard source data.
                    $pdo->prepare("INSERT INTO message_log_archive SELECT * FROM message_log WHERE id IN ($in)")->execute($ids);
                }
                $stmt = $pdo->prepare("DELETE FROM $table WHERE id IN ($in) AND ($condition)");
                $stmt->execute(array_merge($ids, $params));
                $count = $stmt->rowCount();
                $pdo->commit();
                $removed += $count;
                if ($count === 0) break;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
        return $removed;
    }

    /** Read the same environment values and defaults as the original maintenance script. */
    private static function age(string $variable, int $default): string {
        $value = function_exists('envValue') ? envValue($variable, (string) $default) : (getenv($variable) === false ? $default : getenv($variable));
        return gmdate('Y-m-d H:i:s', time() - (int) $value * 86400);
    }

    /** Page the original CLI counter expiry using its composite primary key. */
    private static function rateLimits(int $cutoff): int {
        $pdo = Database::getInstance();
        $removed = 0;
        while (true) {
            $stmt = $pdo->prepare('SELECT rate_key, window_start FROM rate_limits WHERE window_start < ? ORDER BY window_start LIMIT 2000');
            $stmt->execute([$cutoff]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return $removed;
            $delete = $pdo->prepare('DELETE FROM rate_limits WHERE rate_key = ? AND window_start = ?');
            foreach ($rows as $row) {
                $delete->execute([$row['rate_key'], $row['window_start']]);
                $removed += $delete->rowCount();
            }
        }
    }
}
