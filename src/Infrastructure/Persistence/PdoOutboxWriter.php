<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Ingestion\OutboxWriter;
use PDO;
use RuntimeException;
use Throwable;

final class PdoOutboxWriter implements OutboxWriter
{
    public function __construct(private PDO $database) {}
    public function append(array $messageRow, string $body, string $key, ?string $eventId, int $priority, string $queuedAt, int $maxTries): int
    {
        $pdo = $this->database;
        $msgId = (int)$messageRow['id'];
        // Ingestion owns the source transaction; standalone producers still own theirs.
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $source = $pdo->prepare('SELECT id FROM message_log WHERE id = ? FOR UPDATE');
            $source->execute([$msgId]);
            if (!$source->fetch()) {
                throw new RuntimeException('Cannot queue a missing source message');
            }
            // The unique key resolves concurrent producers without replacing an existing retry body.
            $stmt = $pdo->prepare("INSERT INTO outbox
                (message_id, vehicle_id, activity_id, event_id, idempotency_key, payload,
                 status, next_attempt_at, priority, max_tries)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
                ON DUPLICATE KEY UPDATE id = id");
            $stmt->execute([$msgId, (int) $messageRow['vehicle_id'], (int) ($messageRow['activity_id'] ?? 1),
                $eventId, $key, $body, $queuedAt, $priority, $maxTries]);
            $lookup = $pdo->prepare('SELECT id FROM outbox WHERE idempotency_key = ?');
            $lookup->execute([$key]);
            $id = (int) $lookup->fetchColumn();
            if ($ownsTransaction) $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
