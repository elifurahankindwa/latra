<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

interface OutboxWriter
{
    /** Join a caller-owned transaction; never replace the original retry body. */
    public function append(array $message, string $body, string $key, ?string $eventId, int $priority, string $queuedAt, int $maxTries): int;
}
