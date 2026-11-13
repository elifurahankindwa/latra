<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use PDO;
use PDOException;

final class PdoIngestionMessageRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function storeMessage(array $message): bool
    {
        $existing = $this->database->prepare(
            'SELECT id FROM message_log WHERE vehicle_id = ? AND wialon_msg_time = ?',
        );
        $existing->execute([$message['vehicle_id'], $message['wialon_msg_time']]);
        if ($existing->fetchColumn() !== false) {
            return false;
        }

        $columns = array_keys($message);
        $quotedColumns = array_map(static fn (string $column): string => '`' . $column . '`', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $statement = $this->database->prepare(
            'INSERT INTO `message_log` (' . implode(', ', $quotedColumns) . ') VALUES (' . $placeholders . ')',
        );
        try {
            $statement->execute(array_values($message));
            return true;
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                return false;
            }
            throw $error;
        }
    }

    public function findMessage(int $vehicleId, int $messageTime): ?array
    {
        $statement = $this->database->prepare(
            'SELECT * FROM message_log WHERE vehicle_id = ? AND wialon_msg_time = ?',
        );
        $statement->execute([$vehicleId, $messageTime]);
        $message = $statement->fetch(PDO::FETCH_ASSOC);

        return $message === false ? null : $message;
    }

    public function updatePayload(array $message, array $payload): void
    {
        $statement = $this->database->prepare('UPDATE message_log SET latra_payload = ? WHERE id = ?');
        $statement->execute([json_encode($payload), $message['id']]);
    }
}
