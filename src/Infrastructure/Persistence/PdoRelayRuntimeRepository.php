<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Collection\RelayRuntimeRepository;
use PDO;
use PDOStatement;

final class PdoRelayRuntimeRepository implements RelayRuntimeRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function activeVehiclesForBucket(int $bucketId): array
    {
        return $this->run(
            'SELECT v.* FROM vehicles v JOIN bucket_vehicles bv ON bv.vehicle_id=v.id
             WHERE bv.bucket_id=? AND v.is_active=1',
            [$bucketId],
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeVehiclesForEventCollection(): array
    {
        return $this->run(
            'SELECT DISTINCT v.* FROM vehicles v
             JOIN relay_vehicle_settings r ON r.vehicle_id=v.id AND r.is_enabled=1
             JOIN bucket_vehicles bv ON bv.vehicle_id=v.id
             JOIN automation_buckets b ON b.id=bv.bucket_id AND b.is_enabled=1
             WHERE v.is_active=1',
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function enabledEventActivities(): array
    {
        return array_map(
            'intval',
            array_column($this->run(
                'SELECT DISTINCT activity_id FROM wialon_event_map
                 WHERE activity_id<>1 AND is_enabled=1',
            )->fetchAll(PDO::FETCH_ASSOC), 'activity_id'),
        );
    }

    private function run(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }
}
