<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

final class DeviceStatusPolicy
{
    public const BATTERY_LOW_MV = 3200;
    public const BATTERY_LOW_PCT = 20;
    public const EXT_POWER_LOW_MV = 1000;

    public static function evaluate(array $parameters, array $previous): array
    {
        $externalPowerMv = self::toMillivolts(
            $parameters['pwr_ext'] ?? $parameters['ext_power'] ?? $parameters['io_66'] ?? null,
        );
        $internalBatteryMv = self::toMillivolts(
            $parameters['pwr_int'] ?? $parameters['battery_charge'] ?? $parameters['io_67'] ?? null,
        );
        $internalBatteryPct = self::toPercentage(
            $parameters['battery'] ?? $parameters['battery_pct'] ?? null,
        );
        $externalPowerDisconnected = $externalPowerMv !== null
            && $externalPowerMv < self::EXT_POWER_LOW_MV
            && $externalPowerMv > 0;
        $batteryLow = ($internalBatteryMv !== null
                && $internalBatteryMv < self::BATTERY_LOW_MV
                && $internalBatteryMv > 0)
            || ($internalBatteryPct !== null && $internalBatteryPct < self::BATTERY_LOW_PCT);
        $tampered = ActivityDetectionPolicy::flagOn(
            $parameters,
            ['tamper', 'tampering', 'antenna_cut', 'io_8'],
        ) === true;

        $values = [
            'internal_battery_low' => $batteryLow ? 1 : 0,
            'internal_battery_mv' => $internalBatteryMv,
            'internal_battery_pct' => $internalBatteryPct,
            'external_power_disconnected' => $externalPowerDisconnected ? 1 : 0,
            'external_power_mv' => $externalPowerMv,
            'tampered' => $tampered ? 1 : 0,
        ];
        $events = [];

        if (isset($previous['internal_battery_low'])
            && (int) $previous['internal_battery_low'] !== $values['internal_battery_low']
            && $batteryLow) {
            $events[] = [
                'type' => 'BATTERY_LOW',
                'detail' => "Internal battery low ({$internalBatteryMv} mV)",
            ];
        }
        if (isset($previous['external_power_disconnected'])
            && (int) $previous['external_power_disconnected'] !== $values['external_power_disconnected']
            && $externalPowerDisconnected) {
            $events[] = [
                'type' => 'POWER_DISCONNECTED',
                'detail' => 'External power disconnected',
            ];
        }
        if (isset($previous['tampered'])
            && (int) $previous['tampered'] !== $values['tampered']
            && $tampered) {
            $events[] = [
                'type' => 'TAMPERING',
                'detail' => 'Device tampering detected',
            ];
        }

        $changed = $previous
            ? $previous['is_online'] != 1
                || $previous['internal_battery_low'] != $values['internal_battery_low']
                || $previous['external_power_disconnected'] != $values['external_power_disconnected']
                || $previous['tampered'] != $values['tampered']
            : false;

        return [
            ...$values,
            'events' => $events,
            'changed' => $changed,
        ];
    }

    private static function toMillivolts(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $millivolts = (float) $value;
        if ($millivolts < 100) {
            return round($millivolts * 1000, 2);
        }
        return round($millivolts, 2);
    }

    private static function toPercentage(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $percentage = (float) $value;
        if ($percentage > 0 && $percentage <= 1) {
            $percentage *= 100;
        }
        return round(max(0, min(100, $percentage)), 2);
    }
}
