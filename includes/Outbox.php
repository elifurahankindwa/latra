<?php
/**
 * Outbox queue.
 */
require_once __DIR__ . '/autoload.php';

class Outbox {
    /** Persist immutable retry data while locking its source against retention. */
    public static function enqueue(array $messageRow, array $payload, ?string $eventId = null, int $priority = 0): int {
        $pdo = Database::getInstance();
        return (new \Latra\Application\Ingestion\QueueOutboxMessage(
            new \Latra\Infrastructure\Persistence\PdoOutboxWriter($pdo),
        ))->enqueue($messageRow, $payload, $eventId, $priority, date(DATE_FORMAT),
            (new \Latra\Infrastructure\Persistence\PdoIngestionSettingsRepository($pdo))->configInt('outbox_max_tries', 8));
    }

    /** Select candidates only; the adapter locks and starts each actual attempt. */
    public static function claimBatch(int $limit, bool $emergencyOnly = false): array {
        $limit = max(1, min(200, $limit));
        $extra = $emergencyOnly ? " AND o.event_id IS NOT NULL AND o.event_id != ''" : '';
        return Database::fetchAll(
            "SELECT o.* FROM outbox o
             WHERE o.status = 'pending' AND (o.next_attempt_at IS NULL OR o.next_attempt_at <= NOW())
               AND o.tries < o.max_tries
               AND EXISTS (SELECT 1 FROM automation_buckets b
                   JOIN bucket_vehicles bv ON bv.bucket_id = b.id
                   WHERE b.is_enabled = 1 AND b.activity_id = o.activity_id AND bv.vehicle_id = o.vehicle_id)
               $extra
             ORDER BY o.priority DESC, o.id ASC LIMIT $limit"
        );
    }

    /** Page all eligible stale work while preserving the caller's original lease timeout. */
    public static function reconcileStale(int $seconds = 300): int {
        $recovered = 0;
        $cursor = 0;
        do {
            $rows = Database::fetchAll("SELECT id, idempotency_key FROM outbox
                WHERE status = 'in_flight' AND in_flight_at < NOW() - INTERVAL ? SECOND AND id > ?
                ORDER BY id LIMIT 2000", [$seconds, $cursor]);
            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                $pdo = Database::getInstance();
                $lock = Delivery::lockName($row['idempotency_key']);
                $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
                $stmt->execute([$lock]);
                if ((int) $stmt->fetchColumn() !== 1) continue;
                try {
                    $stmt = $pdo->prepare("UPDATE outbox SET status = 'pending', in_flight_at = NULL
                        WHERE id = ? AND status = 'in_flight' AND in_flight_at < NOW() - INTERVAL ? SECOND");
                    $stmt->execute([$row['id'], $seconds]);
                    $recovered += $stmt->rowCount();
                } finally {
                    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
                }
            }
        } while (count($rows) === 2000);
        return $recovered;
    }

    public static function retry(int $id): void {
        Database::update('outbox', [
            'status' => 'pending',
            'next_attempt_at' => date(DATE_FORMAT),
            'last_error' => null,
            // An explicit operator retry starts a fresh, bounded retry budget.
            'tries' => 0,
        ], 'id = ? AND status IN (\'failed\',\'rejected\')', [$id]);
    }

    public static function purge(int $id): void {
        Database::update('outbox', ['status' => 'rejected'], "id = ? AND status IN ('pending','failed')", [$id]);
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

    /** Probe alert thresholds without aggregating all retained queue history. */
    public static function pressure(): ?array {
        $pending = count(Database::fetchAll("SELECT id FROM outbox WHERE status = 'pending' LIMIT 501"));
        $failed = count(Database::fetchAll("SELECT id FROM outbox WHERE status = 'failed' LIMIT 51"));
        return $pending > 500 || $failed > 50
            ? ['pending' => $pending, 'failed' => $failed, 'counts_are_lower_bounds' => true] : null;
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
