<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Collection\RelayEnrollmentRepository;
use Latra\Domain\Collection\RelayEnrollmentPolicy;
use PDO;
use Throwable;

final class PdoRelayEnrollmentRepository implements RelayEnrollmentRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function saveEnrollment(int $vehicleId, int $unitId, string $profile, array $budgets): void
    {
        $this->database->beginTransaction();
        try {
            $previous = $this->run(
                'SELECT profile FROM relay_vehicle_settings WHERE vehicle_id=? FOR UPDATE',
                [$vehicleId],
            )->fetchColumn();

            if ($previous !== false && $previous !== $profile) {
                $unit = 'wialon:' . $unitId;
                $hasProgress = $this->run(
                    'SELECT unit FROM relay_units
                     WHERE (unit=? OR unit LIKE ?) AND collected_through IS NOT NULL LIMIT 1',
                    [$unit, $unit . ':%'],
                )->fetchColumn() !== false;
                RelayEnrollmentPolicy::assertProfileChangeAllowed($hasProgress);
            }

            $this->run(
                'INSERT INTO relay_vehicle_settings
                    (vehicle_id,profile,max_records,max_bytes,max_pending_records,max_pending_bytes,is_enabled)
                 VALUES (?,?,?,?,?,?,1)
                 ON DUPLICATE KEY UPDATE profile=VALUES(profile),max_records=VALUES(max_records),
                    max_bytes=VALUES(max_bytes),max_pending_records=VALUES(max_pending_records),
                    max_pending_bytes=VALUES(max_pending_bytes),is_enabled=1',
                [
                    $vehicleId,
                    $profile,
                    $budgets['max_records'],
                    $budgets['max_bytes'],
                    $budgets['max_pending_records'],
                    $budgets['max_pending_bytes'],
                ],
            );
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function run(string $sql, array $parameters): \PDOStatement
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }
}
