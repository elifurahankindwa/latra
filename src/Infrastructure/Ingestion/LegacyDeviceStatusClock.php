<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Ingestion;

use Latra\Application\DeviceStatus\DeviceStatusClock;

final class LegacyDeviceStatusClock implements DeviceStatusClock
{
    public function now(): string
    {
        return date(\DATE_FORMAT);
    }

    public function at(int $timestamp): string
    {
        return date(\DATE_FORMAT, $timestamp);
    }
}
