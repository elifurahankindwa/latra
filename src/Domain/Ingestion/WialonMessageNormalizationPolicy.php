<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

final class WialonMessageNormalizationPolicy
{
    public static function normalize(array $vehicle, array $message, array $params): array
    {
        $position = $message['pos'] ?? [];
        $epoch = isset($message['t']) ? (int) $message['t'] : time();
        $received = isset($message['rt']) ? (int) $message['rt'] : time();

        $batteryMv = self::toMv(ParameterResolutionPolicy::pick($params, 'battery_mv', ['pwr_int', 'io_67']));
        $batteryPct = self::toPct(ParameterResolutionPolicy::pick($params, 'battery_pct', ['battery']));
        $driver = ParameterResolutionPolicy::pick($params, 'driver_id', ['pass', 'tco_driver1_id']);
        $odometer = ParameterResolutionPolicy::pick($params, 'odometer', ['mileage', 'mil']);

        return [
            'vehicle_id' => (int) $vehicle['id'],
            'wialon_msg_time' => $epoch,
            'gps_time' => gmdate('Y-m-d H:i:s', $epoch),
            'data_received_time' => gmdate('Y-m-d H:i:s', $received),
            'data_processed_time' => gmdate('Y-m-d H:i:s'),
            'raw_data' => json_encode($message),
            'lat' => isset($position['y']) ? round((float) $position['y'], 8) : null,
            'lng' => isset($position['x']) ? round((float) $position['x'], 8) : null,
            'altitude' => isset($position['z']) ? (int) $position['z'] : null,
            'speed' => isset($position['s']) ? round((float) $position['s'], 2) : null,
            'bearing' => isset($position['c']) ? round((float) $position['c'], 2) : null,
            'satellite_count' => isset($position['sc']) ? (int) $position['sc'] : null,
            'hdop' => isset($params['hdop']) ? round((float) $params['hdop'], 2) : null,
            'd2d3' => !empty($position['z']) ? 3 : 2,
            'rssi' => self::intOrNull(ParameterResolutionPolicy::pick($params, 'rssi', ['gsm'])),
            'lac' => self::intOrNull(ParameterResolutionPolicy::pick($params, 'lac')),
            'cell_id' => self::intOrNull(ParameterResolutionPolicy::pick($params, 'cell_id')),
            'mcc' => self::intOrNull($params['mcc'] ?? null),
            'mgs_id' => $epoch,
            'odometer' => $odometer !== null && is_numeric($odometer) ? round((float) $odometer, 2) : null,
            'driver_id' => $driver !== null ? (string) $driver : null,
            'imei' => $vehicle['imei'] ?: null,
            'vehicle_reg_no' => $vehicle['vehicle_reg_no'] ?? null,
            'battery_pct' => $batteryPct,
            'battery_mv' => $batteryMv,
            'data_received_at' => $received,
            'canonical_params' => $params,
        ];
    }

    public static function toMv(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $volts = (float) $value;
        return $volts < 100 ? round($volts * 1000, 2) : round($volts, 2);
    }

    public static function toPct(mixed $value): ?float
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

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (int) $value;
    }
}
