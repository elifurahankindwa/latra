<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

interface CompleteMessageWindowSource
{
    public function fetchCompleteWindow(
        int $unitId,
        int $from,
        int $to,
        int $pageSize,
        int $maxRecords,
        int $maxBytes,
    ): array;
}
