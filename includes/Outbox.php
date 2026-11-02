<?php
/**
 * Outbox queue.
 */
class Outbox {
    public static function enqueue(array $messageRow, array $payload, ?string $eventId = null, int $priority = 0): int {
        $msgId = (int) $messageRow['id'];
        $key = $messageRow['idempotency_key'] ?? ('msg-' . $msgId . '-' . (int) $messageRow['activity_id'] . '-' . ($eventId ?: 'n'));
        $exists = Database::fetch("SELECT id FROM outbox WHERE idempotency_key = ?", [$key]);
        if ($exists) {
            return (int) $exists['id'];
        }
        return Database::insert('outbox', [
            'message_id' => $msgId,
            'vehicle_id' => (int) $messageRow['vehicle_id'],
            'activity_id' => (int) ($messageRow['activity_id'] ?? 1),
            'event_id' => $eventId,
            'idempotency_key' => $key,
            'payload' => json_encode($payload),
            'status' => 'pending',
            'next_attempt_at' => date(DATE_FORMAT),
            'priority' => $priority,
            'max_tries' => Schema::configInt('outbox_max_tries', 8),
        ]);
    }

    public static function claimBatch(int $limit, bool $emergencyOnly = false): array {
        $limit = max(1, min(200, $limit));
        $extra = $emergencyOnly ? ' AND event_id IS NOT NULL AND event_id != \'\'' : '';
        // Only claim rows for enabled buckets.
        $allowed = Automation::enabledBucketActivities();
        if (Schema::config('scheduler_enabled', '1') === '1'
            && Schema::config('event_engine_enabled', '1') === '1') {
            $allowed = array_values(array_unique(array_merge(
                $allowed,
                range(2, 24)
            )));
        }
        if (empty($allowed)) {
            return [];
        }
        $bucketVehicles = Automation::enabledBucketVehicleIds();
        if (empty($bucketVehicles)) {
            return [];
        }
        $actPlaceholders = implode(',', array_fill(0, count($allowed), '?'));
        $vehPlaceholders = implode(',', array_fill(0, count($bucketVehicles), '?'));
        $rows = Database::fetchAll(
            "SELECT * FROM outbox
             WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
               AND tries < max_tries
               AND activity_id IN ($actPlaceholders)
               AND vehicle_id IN ($vehPlaceholders)
               $extra
             ORDER BY priority DESC, id ASC
             LIMIT $limit",
            array_merge($allowed, $bucketVehicles)
        );
        $claimed = [];
        foreach ($rows as $row) {
            $stmt = Database::query(
                "UPDATE outbox SET status = 'in_flight', in_flight_at = ?, tries = tries + 1
                 WHERE id = ? AND status = 'pending'",
                [date(DATE_FORMAT), $row['id']]
            );
            if ($stmt->rowCount() > 0) {
                $claimed[] = $row;
            }
        }
        return $claimed;
    }

    public static function markSent(int $id): void {
        Database::update('outbox', [
            'status' => 'sent',
            'in_flight_at' => null,
            'last_error' => null,
        ], 'id = ?', [$id]);
    }

    public static function markFailed(int $id, string $error, string $class, int $tries): void {
        $max = Schema::configInt('outbox_max_tries', 8);
        $terminal = $class === 'permanent' || $tries >= $max;
        $backoff = Automation::nextBackoff($tries);
        $cap = min(900, $backoff); // spec cap 15 min
        Database::update('outbox', [
            'status' => $terminal ? 'failed' : 'pending',
            'in_flight_at' => null,
            'last_error' => substr($error, 0, 2000),
            'failure_class' => $class,
            'next_attempt_at' => $terminal ? null : date(DATE_FORMAT, time() + $cap),
        ], 'id = ?', [$id]);
    }

    public static function reconcileStale(int $seconds = 300): int {
        $stmt = Database::query(
            "UPDATE outbox SET status = 'pending', in_flight_at = NULL
             WHERE status = 'in_flight' AND in_flight_at < NOW() - INTERVAL ? SECOND",
            [$seconds]
        );
        return $stmt->rowCount();
    }

    public static function retry(int $id): void {
        Database::update('outbox', [
            'status' => 'pending',
            'next_attempt_at' => date(DATE_FORMAT),
            'last_error' => null,
        ], 'id = ? AND status IN (\'failed\',\'rejected\')', [$id]);
    }

    public static function purge(int $id): void {
        Database::update('outbox', ['status' => 'rejected'], 'id = ?', [$id]);
    }

    // Retry failed rows after a short cooldown.
    public static function resetTerminal(int $cooldownSeconds = 1800): int {
        $stmt = Database::query(
            "UPDATE outbox SET status = 'pending', next_attempt_at = NOW(),
                    in_flight_at = NULL, tries = 0, last_error = NULL
             WHERE status = 'failed'
               AND failure_class != 'permanent'
               AND updated_at < NOW() - INTERVAL ? SECOND
             LIMIT 200",
            [$cooldownSeconds]
        );
        return $stmt->rowCount();
    }

    public static function depth(): array {
        $row = Database::fetch(
            "SELECT
                SUM(status = 'pending') AS pending,
                SUM(status = 'in_flight') AS in_flight,
                SUM(status = 'sent') AS sent,
                SUM(status = 'failed') AS failed,
                SUM(status = 'rejected') AS rejected
             FROM outbox"
        ) ?: [];
        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'in_flight' => (int) ($row['in_flight'] ?? 0),
            'sent' => (int) ($row['sent'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
        ];
    }
}
