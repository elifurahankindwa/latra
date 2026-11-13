<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Ingestion;

use Latra\Application\DeviceStatus\DeviceStatusEffects;

final class LegacyDeviceStatusEffects implements DeviceStatusEffects
{
    public function recordTransition(int $vehicleId, string $type, string $detail): void
    {
        \Automation::log('device.status_change', [
            'vehicle' => $vehicleId,
            'type' => $type,
            'detail' => $detail,
        ]);
        if (class_exists('Auth')) {
            \Auth::audit('device.' . strtolower($type), (string) $vehicleId, $detail, 'system');
        }
    }

    public function statusChanged(int $vehicleId, array $status): void
    {
        \Automation::log('device.status', [
            'vehicle' => $vehicleId,
            'online' => $status['is_online'],
            'battery_low' => $status['internal_battery_low'],
            'ext_power_off' => $status['external_power_disconnected'],
            'tampered' => $status['tampered'],
        ]);
    }
}
