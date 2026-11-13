<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use PDO;

/**
 * Persists per-field validation failures as retained evidence for rejected source messages.
 *
 * The row shape, scalar casting and JSON fallback are the existing contract used by reporting,
 * data cleanup and review of rejected messages, so they are preserved rather than simplified.
 */
final class PdoValidationEvidenceRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function recordErrors(array $message, array $errors): int
    {
        if ($errors === []) {
            return 0;
        }

        $statement = $this->database->prepare(
            'INSERT INTO `validation_errors` ('
            . '`message_log_id`, `vehicle_id`, `vehicle_reg_no`, `imei`, `field`, `invalid_value`, '
            . '`reason`, `error_code`, `activity_id`, `validation_status`, `gps_timestamp`'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );

        $count = 0;
        foreach ($errors as $error) {
            $statement->execute($this->evidenceValues($message, $error));
            $count++;
        }

        return $count;
    }

    private function evidenceValues(array $message, array $error): array
    {
        return [
            $message['id'] ?? null,
            $message['vehicle_id'] ?? null,
            $message['vehicle_reg_no'] ?? null,
            $message['imei'] ?? null,
            $error['field'],
            $this->invalidValue($error['value'] ?? null),
            $error['reason'],
            $error['code'] ?? null,
            $message['activity_id'] ?? null,
            'failed',
            $message['wialon_msg_time'] ?? null,
        ];
    }

    /**
     * Non-scalar values keep their JSON payload; scalars stay readable without quotes.
     */
    private function invalidValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }
}
