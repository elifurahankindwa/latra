<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Wialon;

use Latra\Application\Collection\CompleteMessageWindowSource;
use RuntimeException;
use WialonAPI;

final class WialonMessageWindowSource implements CompleteMessageWindowSource
{
    public function __construct(private WialonAPI $client)
    {
    }

    public function fetchCompleteWindow(
        int $unitId,
        int $from,
        int $to,
        int $pageSize,
        int $maxRecords,
        int $maxBytes,
    ): array {
        try {
            if (!$this->client->login()) {
                throw new RuntimeException('Wialon login failed');
            }

            return $this->client->getCompleteWindow($unitId, $from, $to, $pageSize, $maxRecords, $maxBytes);
        } finally {
            $this->client->logout();
        }
    }
}
