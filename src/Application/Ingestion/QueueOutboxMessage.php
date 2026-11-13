<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

use Latra\Domain\Ingestion\OutboxIntentPolicy;

final class QueueOutboxMessage
{
    public function __construct(private OutboxWriter $writer) {}
    public function enqueue(array $message, array $payload, ?string $eventId, int $priority, string $queuedAt, int $maxTries): int
    {
        return $this->writer->append($message, json_encode($payload, JSON_THROW_ON_ERROR),
            OutboxIntentPolicy::identity($message, $eventId), $eventId, $priority, $queuedAt, $maxTries);
    }
}
