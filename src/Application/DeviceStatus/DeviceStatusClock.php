<?php
declare(strict_types=1);

namespace Latra\Application\DeviceStatus;

interface DeviceStatusClock
{
    public function now(): string;

    public function at(int $timestamp): string;
}
