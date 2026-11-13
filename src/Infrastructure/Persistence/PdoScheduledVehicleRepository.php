<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use PDO;
use Latra\Application\Ingestion\ScheduledVehicleRepository;

final class PdoScheduledVehicleRepository implements ScheduledVehicleRepository
{
    public function __construct(private PDO $database) {}
    private function rows(string $sql): array { return $this->database->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function ordinaryVehicles(): array
    {
        $vehicles = $this->rows('SELECT * FROM vehicles WHERE is_active = 1');
        $skip = [];
        foreach ($this->rows("SELECT DISTINCT vehicle_id FROM emergency_events WHERE status = 'active'") as $row) {
            $skip[(int)$row['vehicle_id']] = true;
        }
        foreach ($this->rows('SELECT DISTINCT vehicle_id FROM bucket_vehicles bv
            JOIN automation_buckets b ON b.id=bv.bucket_id AND b.is_enabled=1') as $row) {
            $skip[(int)$row['vehicle_id']] = true;
        }
        return array_values(array_filter($vehicles, static fn(array $v): bool => !isset($skip[(int)$v['id']])));
    }
    public function emergencyVehicles(): array
    {
        $vehicles = [];
        foreach ($this->rows("SELECT DISTINCT vehicle_id FROM emergency_events WHERE status = 'active'") as $row) {
            $statement = $this->database->prepare('SELECT * FROM vehicles WHERE id=?');
            $statement->execute([$row['vehicle_id']]);
            $vehicle = $statement->fetch(PDO::FETCH_ASSOC);
            if ($vehicle) $vehicles[] = $vehicle;
        }
        return $vehicles;
    }
}
