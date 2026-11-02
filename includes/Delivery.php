<?php
/** Coordinate adapter callers without holding a database transaction during HTTP. */
class Delivery {
    /** Keep lock names short and identical across workers and recovery. */
    public static function lockName(string $key): string {
        return 'latra-send-' . substr(hash('sha256', $key), 0, 48);
    }

    /** Serialize a key, reuse its stored body, and finalize queue state before unlocking. */
    public static function submit(array $payload, string $key, callable $send): array {
        if ($key === '') {
            // Legacy unkeyed calls cannot be deduplicated, but still use transport preflight.
            return $send($payload, static function (): void {});
        }
        $pdo = Database::getInstance();
        $lock = self::lockName($key);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$lock]);
        if ((int) $stmt->fetchColumn() !== 1) return self::deferred($payload, 'Delivery key is busy');
        try {
            $stmt = $pdo->prepare('SELECT * FROM outbox WHERE idempotency_key = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                // Retry identity includes the original body, not today's mutable source projection.
                $payload = json_decode($row['payload'], true);
                if (!is_array($payload) || !is_array($payload['items'] ?? null)
                    || !isset($payload['items'][0]) || !is_array($payload['items'][0])) {
                    // Quarantine poison rows without repeatedly blocking the head of the queue.
                    $stmt = $pdo->prepare("UPDATE outbox SET status = 'failed', failure_class = 'permanent',
                        last_error = 'Invalid stored delivery payload' WHERE id = ? AND status = 'pending'");
                    $stmt->execute([$row['id']]);
                    return self::deferred([], 'Invalid stored delivery payload requires operator review');
                }
                if ($row['status'] === 'sent') {
                    return ['ok' => true, 'payload' => $payload, 'response' => 'Already accepted',
                        'responseData' => ['status' => '1'], 'httpCode' => 200, 'latency' => 0,
                        'curlError' => '', 'attempted' => false, 'deduplicated' => true];
                }
                if ($row['status'] !== 'pending' || (int) $row['tries'] >= (int) $row['max_tries']
                    || (!empty($row['next_attempt_at']) && strtotime($row['next_attempt_at'] . ' UTC') > time())) {
                    return self::deferred($payload, 'Delivery is not due or requires operator review');
                }
            }
            $result = $send($payload, static function () use ($pdo, $row): void {
                if (!$row) return;
                // Use the lock-owning connection; reconnecting must never silently lose ownership.
                $stmt = $pdo->prepare("UPDATE outbox SET status = 'in_flight', in_flight_at = ?, tries = tries + 1
                    WHERE id = ? AND status = 'pending' AND tries < max_tries");
                $stmt->execute([gmdate('Y-m-d H:i:s'), $row['id']]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('Delivery claim changed before transport');
            });
            if ($row) {
                $attempted = !empty($result['attempted']);
                $tries = (int) $row['tries'] + ($attempted ? 1 : 0);
                $class = !empty($result['ok']) ? null : Automation::classifyFailure(
                    $result['httpCode'], $result['responseData'], $result['curlError']);
                // Respect both the persisted row ceiling and the existing configured retry ceiling.
                $ceiling = min((int) $row['max_tries'], Schema::configInt('outbox_max_tries', 8));
                $terminal = $attempted && ($class === 'permanent' || $tries >= $ceiling);
                $status = !empty($result['ok']) ? 'sent' : ($terminal ? 'failed' : 'pending');
                // A local deferral waits the existing first-attempt backoff, without spending an attempt.
                $delay = min(900, Automation::nextBackoff(max(1, $tries)));
                // Commit the receipt with the queue outcome so a later caller can safely deduplicate.
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare('UPDATE outbox SET status = ?, in_flight_at = NULL,
                        failure_class = ?, last_error = ?, next_attempt_at = ? WHERE id = ? AND status = ?');
                    $stmt->execute([$status, $class, !empty($result['ok']) ? null : substr($result['curlError'] ?: $result['response'], 0, 2000),
                        ($terminal || $status === 'sent') ? null : gmdate('Y-m-d H:i:s', time() + $delay),
                        $row['id'], $attempted ? 'in_flight' : 'pending']);
                    if ($stmt->rowCount() !== 1) {
                        // MySQL may report zero changes for repeated zero-jitter local deferrals.
                        $check = $pdo->prepare('SELECT status FROM outbox WHERE id = ?');
                        $check->execute([$row['id']]);
                        if ($attempted || $check->fetchColumn() !== 'pending') {
                            throw new RuntimeException('Delivery state changed before completion');
                        }
                    }
                    if ($attempted) {
                        self::recordAttempt($pdo, $row, $result, $tries);
                        $result['recorded'] = true;
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }
            return $result;
        } finally {
            // The same PDO session must release the advisory lock it acquired.
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }

    /** Write real transport evidence on the same connection as its queue outcome. */
    private static function recordAttempt(PDO $pdo, array $row, array $result, int $tries): void {
        $stmt = $pdo->prepare('INSERT INTO push_log (vehicle_id, activity_id, message_log_id, payload,
            response_status, response_body, http_code, latency_ms, claim_id, attempt, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$row['vehicle_id'], $row['activity_id'], $row['message_id'],
            json_encode($result['payload'], JSON_THROW_ON_ERROR), $result['responseData']['status'] ?? null,
            substr((string) ($result['response'] ?: $result['curlError']), 0, 8000),
            $result['httpCode'], $result['latency'], (string) $row['id'], $tries, gmdate('Y-m-d H:i:s')]);
    }

    /** Return a non-attempt so callers preserve queued work without recording a receipt. */
    private static function deferred(array $payload, string $reason): array {
        return ['ok' => false, 'payload' => $payload, 'response' => $reason, 'responseData' => [],
            'httpCode' => 0, 'latency' => 0, 'curlError' => $reason, 'attempted' => false, 'deferred' => true];
    }
}
