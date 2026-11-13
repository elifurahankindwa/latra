<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

interface RelayWindowCollector
{
    public function collectWindow(
        string $unit,
        int $from,
        int $to,
        int $pageSize,
        int $maxRecords,
        int $maxBytes,
        callable $fetchPage,
        callable $map,
    ): array;
}
