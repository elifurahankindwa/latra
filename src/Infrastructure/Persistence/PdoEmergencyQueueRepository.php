<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Emergency\EmergencyQueueRepository;
use Latra\Domain\Emergency\EmergencyHandoffStatus;
use PDO;

final class PdoEmergencyQueueRepository implements EmergencyQueueRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function pendingForEvent(string $eventId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM emergency_queue WHERE event_id = ? AND latra_status = 'pending'
             ORDER BY wialon_msg_time ASC"
        );
        $statement->execute([$eventId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function vehicleById(int $vehicleId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, vehicle_reg_no, imei FROM vehicles WHERE id = ?'
        );
        $statement->execute([$vehicleId]);
        $vehicle = $statement->fetch(PDO::FETCH_ASSOC);
        return $vehicle === false ? null : $vehicle;
    }

    public function recordHandoff(int $queueId, EmergencyHandoffStatus $status, string $response): void
    {
        $submittedAt = $status === EmergencyHandoffStatus::Queued ? null : date('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'UPDATE emergency_queue
             SET latra_status = ?, latra_response = ?, submitted_at = ?
             WHERE id = ?'
        );
        $statement->execute([$status->value, $response, $submittedAt, $queueId]);
    }
}
