<?php
/**
 * Compatibility adapter for GPS validation and validation-error persistence.
 */
use Latra\Domain\Ingestion\GpsValidationPolicy;
use Latra\Infrastructure\Persistence\PdoValidationEvidenceRepository;

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
        if ($errors === []) {
            return 0;
        }

        return (new PdoValidationEvidenceRepository(Database::getInstance()))
            ->recordErrors($message, $errors);
    }

    public static function statusOf(array $result): string
    {
        return GpsValidationPolicy::statusOf($result);
    }
}
