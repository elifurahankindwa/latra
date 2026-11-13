<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use RuntimeException;

final class CollectRelayWindow
{
    public function __construct(
        private CompleteMessageWindowSource $source,
        private RelayWindowCollector $collector,
    ) {
    }

    public function collect(
        int $unitId,
        string $unit,
        int $from,
        int $to,
        int $pageSize,
        int $maxRecords,
        int $maxBytes,
        callable $map,
    ): array {
        $messages = $this->source->fetchCompleteWindow(
            $unitId,
            $from,
            $to,
            $pageSize,
            $maxRecords,
            $maxBytes,
        );
        if (!array_is_list($messages)) {
            throw new RuntimeException('Source returned an incomplete message window');
        }

        return $this->collector->collectWindow(
            $unit,
            $from,
            $to,
            $pageSize,
            $maxRecords,
            $maxBytes,
            static fn(int $offset, int $limit): array => [
                'count' => count($messages),
                'messages' => array_slice($messages, $offset, $limit),
            ],
            $map,
        );
    }
}
