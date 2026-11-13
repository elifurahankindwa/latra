<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

final class FuelReportPolicy
{
    public static function read(array $vehicle, ?array $data): array
    {
        if ($data === null) {
            return ['reason' => 'no_unit_data'];
        }
        $value = $unit = null;
        foreach (($data['sens'] ?? []) as $sensor) {
            if ((string) ($sensor['t'] ?? '') === (string) $vehicle['fuel_level_sensor']) {
                $value = $sensor['v'] ?? null;
                $unit = $sensor['u'] ?? null;
                break;
            }
        }
        if ($value === null || $value === '') {
            return ['reason' => 'no_live_fuel_value'];
        }
        $position = $data['pos'] ?? null;
        if (!is_array($position) || !isset($position['t'], $position['x'], $position['y'])
            || !is_numeric($position['t']) || !is_numeric($position['x']) || !is_numeric($position['y'])
            || (int) $position['t'] <= 0) {
            return ['reason' => 'no_live_position'];
        }
        return [
            'source' => [
                'wialon_msg_time' => (int) $position['t'],
                'lat' => $position['y'],
                'lng' => $position['x'],
                'altitude' => $position['z'] ?? null,
                'speed' => $position['s'] ?? null,
                'bearing' => $position['c'] ?? null,
                'satellite_count' => $position['sc'] ?? null,
            ],
            'fuel' => ['fuel_level' => $value, 'fuel_sensor_param' => $vehicle['fuel_level_sensor'], 'unit' => $unit],
        ];
    }
}
