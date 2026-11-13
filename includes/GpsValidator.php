<?php
/**
 * Compatibility adapter for GPS validation and validation-error persistence.
 */
use Latra\Domain\Ingestion\GpsValidationPolicy;

require_once __DIR__ . '/autoload.php';

class GpsValidator
{
    public const MISSING_IMEI = GpsValidationPolicy::MISSING_IMEI;
    public const MISSING_VEHICLE_REGISTRATION = GpsValidationPolicy::MISSING_VEHICLE_REGISTRATION;
    public const INVALID_COORDINATES = GpsValidationPolicy::INVALID_COORDINATES;
    public const INVALID_TIMESTAMP = GpsValidationPolicy::INVALID_TIMESTAMP;
    public const INVALID_SPEED = GpsValidationPolicy::INVALID_SPEED;
    public const INVALID_BEARING = GpsValidationPolicy::INVALID_BEARING;
    public const INVALID_ALTITUDE = GpsValidationPolicy::INVALID_ALTITUDE;
    public const INVALID_SATELLITES = GpsValidationPolicy::INVALID_SATELLITES;
    public const INVALID_HDOP = GpsValidationPolicy::INVALID_HDOP;
    public const INVALID_GSM = GpsValidationPolicy::INVALID_GSM;
    public const INVALID_NUMERIC = GpsValidationPolicy::INVALID_NUMERIC;
    public const VALIDATION_FAILED = GpsValidationPolicy::VALIDATION_FAILED;
    public const UNKNOWN_ACTIVITY = GpsValidationPolicy::UNKNOWN_ACTIVITY;

    public static function validate(array $message): array
    {
        return GpsValidationPolicy::validate($message);
    }

    public static function recordErrors(array $message, array $errors): int
    {
        if (!$errors) {
            return 0;
        }
        $count = 0;
        foreach ($errors as $error) {
            Database::insert('validation_errors', [
                'message_log_id' => $message['id'] ?? null,
                'vehicle_id' => $message['vehicle_id'] ?? null,
                'vehicle_reg_no' => $message['vehicle_reg_no'] ?? null,
                'imei' => $message['imei'] ?? null,
                'field' => $error['field'],
                'invalid_value' => isset($error['value'])
                    ? (is_scalar($error['value']) ? (string) $error['value'] : json_encode($error['value']))
                    : null,
                'reason' => $error['reason'],
                'error_code' => $error['code'] ?? null,
                'activity_id' => $message['activity_id'] ?? null,
                'validation_status' => 'failed',
                'gps_timestamp' => $message['wialon_msg_time'] ?? null,
            ]);
            $count++;
        }
        return $count;
    }

    public static function statusOf(array $result): string
    {
        return GpsValidationPolicy::statusOf($result);
    }
}
