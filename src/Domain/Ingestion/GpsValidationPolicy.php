<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

final class GpsValidationPolicy
{
    public const MISSING_IMEI = 'MISSING_IMEI';
    public const MISSING_VEHICLE_REGISTRATION = 'MISSING_VEHICLE_REGISTRATION';
    public const INVALID_COORDINATES = 'INVALID_COORDINATES';
    public const INVALID_TIMESTAMP = 'INVALID_TIMESTAMP';
    public const INVALID_SPEED = 'INVALID_SPEED';
    public const INVALID_BEARING = 'INVALID_BEARING';
    public const INVALID_ALTITUDE = 'INVALID_ALTITUDE';
    public const INVALID_SATELLITES = 'INVALID_SATELLITES';
    public const INVALID_HDOP = 'INVALID_HDOP';
    public const INVALID_GSM = 'INVALID_GSM';
    public const INVALID_NUMERIC = 'INVALID_NUMERIC';
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const UNKNOWN_ACTIVITY = 'UNKNOWN_ACTIVITY';

    public static function validate(array $message): array
    {
        $errors = [];
        $messageTime = $message['wialon_msg_time'] ?? null;
        $imei = $message['imei'] ?? null;
        if (empty($imei)) {
            $errors[] = self::error('imei', $imei, 'IMEI is missing', self::MISSING_IMEI);
        } elseif (!preg_match('/^\d{8,20}$/', (string) $imei)) {
            $errors[] = self::error('imei', $imei, 'IMEI format invalid', self::MISSING_IMEI);
        }

        $registration = $message['vehicle_reg_no'] ?? null;
        if (empty($registration)) {
            $errors[] = self::error(
                'vehicle_reg_no',
                $registration,
                'Vehicle registration is missing',
                self::MISSING_VEHICLE_REGISTRATION,
            );
        }

        foreach (['lat' => [-90, 90], 'lng' => [-180, 180]] as $coordinate => [$minimum, $maximum]) {
            $value = $message[$coordinate] ?? null;
            if ($value === null || $value === '') {
                $errors[] = self::error($coordinate, $value, 'Coordinate is required', self::INVALID_COORDINATES);
                continue;
            }
            if (!is_numeric($value)) {
                $errors[] = self::error($coordinate, $value, 'Must be numeric', self::INVALID_COORDINATES);
            } elseif ((float) $value < $minimum || (float) $value > $maximum) {
                $errors[] = self::error(
                    $coordinate,
                    $value,
                    "Must be within [$minimum, $maximum]",
                    self::INVALID_COORDINATES,
                );
            }
        }

        if ($messageTime === null || $messageTime === '') {
            $errors[] = self::error('wialon_msg_time', $messageTime, 'GPS timestamp is missing', self::INVALID_TIMESTAMP);
        } elseif (!is_numeric($messageTime)) {
            $errors[] = self::error('wialon_msg_time', $messageTime, 'GPS timestamp must be epoch', self::INVALID_TIMESTAMP);
        } else {
            $timestamp = (int) $messageTime;
            if ($timestamp > time() + 3600) {
                $errors[] = self::error(
                    'wialon_msg_time',
                    $messageTime,
                    'GPS timestamp is in the future',
                    self::INVALID_TIMESTAMP,
                );
            } elseif ($timestamp < time() - 30 * 86400) {
                $errors[] = self::error(
                    'wialon_msg_time',
                    $messageTime,
                    'GPS timestamp is too old',
                    self::INVALID_TIMESTAMP,
                );
            }
        }

        self::validateRange($message, 'speed', 0, 400, self::INVALID_SPEED, 'Speed out of range [0, 400] km/h', $errors);
        self::validateRange($message, 'bearing', 0, 360, self::INVALID_BEARING, 'Bearing out of range [0, 360)', $errors, true);
        self::validateRange($message, 'altitude', -500, 11000, self::INVALID_ALTITUDE, 'Altitude out of range', $errors);
        self::validateIntegerRange($message, 'satellite_count', 0, 40, self::INVALID_SATELLITES, 'Satellite count out of range [0, 40]', $errors);
        self::validateRange($message, 'hdop', 0, 99, self::INVALID_HDOP, 'HDOP out of range [0, 99]', $errors);

        foreach (['rssi', 'lac', 'cell_id', 'mcc'] as $field) {
            if (self::present($message[$field] ?? null) && !is_numeric($message[$field])) {
                $errors[] = self::error($field, $message[$field], 'Must be numeric', self::INVALID_GSM);
            }
        }

        return ['valid' => count($errors) === 0, 'errors' => $errors];
    }

    public static function statusOf(array $result): string
    {
        return $result['valid'] ? 'valid' : 'failed';
    }

    private static function validateRange(
        array $message,
        string $field,
        float $minimum,
        float $maximum,
        string $code,
        string $rangeReason,
        array &$errors,
        bool $maximumExclusive = false,
    ): void {
        $value = $message[$field] ?? null;
        if (!self::present($value)) {
            return;
        }
        if (!is_numeric($value)) {
            $errors[] = self::error($field, $value, 'Must be numeric', $code);
            return;
        }
        $number = (float) $value;
        if ($number < $minimum || ($maximumExclusive ? $number >= $maximum : $number > $maximum)) {
            $errors[] = self::error($field, $value, $rangeReason, $code);
        }
    }

    private static function validateIntegerRange(
        array $message,
        string $field,
        int $minimum,
        int $maximum,
        string $code,
        string $rangeReason,
        array &$errors,
    ): void {
        $value = $message[$field] ?? null;
        if (!self::present($value)) {
            return;
        }
        if (!is_numeric($value)) {
            $errors[] = self::error($field, $value, 'Must be numeric', $code);
            return;
        }
        $number = (int) $value;
        if ($number < $minimum || $number > $maximum) {
            $errors[] = self::error($field, $value, $rangeReason, $code);
        }
    }

    private static function present(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private static function error(string $field, mixed $value, string $reason, string $code): array
    {
        return ['field' => $field, 'value' => $value, 'reason' => $reason, 'code' => $code];
    }
}
