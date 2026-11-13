<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Ingestion\TripStatisticsRepository;
use Latra\Domain\Ingestion\LatraPayloadPolicy;
use PDO;

final class PdoTripStatisticsRepository implements TripStatisticsRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function latestEngineOnTime(int $vehicleId, int $beforeTime): ?int
    {
        $statement = $this->database->prepare(
            'SELECT wialon_msg_time FROM message_log
             WHERE vehicle_id = ? AND activity_id = ? AND wialon_msg_time < ?
             ORDER BY wialon_msg_time DESC LIMIT 1',
        );
        $statement->execute([$vehicleId, LatraPayloadPolicy::ACTIVITY_ENGINE_ON, $beforeTime]);
        $messageTime = $statement->fetchColumn();

        return $messageTime === false ? null : (int) $messageTime;
    }

    public function messagesBetween(int $vehicleId, int $fromTime, int $toTime): array
    {
        $statement = $this->database->prepare(
            'SELECT lat, lng, speed FROM message_log
             WHERE vehicle_id = ? AND wialon_msg_time >= ? AND wialon_msg_time <= ?
             ORDER BY wialon_msg_time ASC',
        );
        $statement->execute([$vehicleId, $fromTime, $toTime]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
