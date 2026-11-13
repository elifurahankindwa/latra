<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

final class OutboxIntentPolicy
{
    public static function identity(array $message, ?string $eventId): string
    {
        return $message['idempotency_key'] ?? ('msg-' . (int)$message['id'] . '-'
            . (int)$message['activity_id'] . '-' . ($eventId ?: 'n'));
    }
}
