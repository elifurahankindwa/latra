<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

interface FuelReportSource
{
    public function open(): bool;
    public function read(int $unitId): ?array;
    public function close(): void;
}
