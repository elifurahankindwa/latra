<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use PDO;

/** Configuration reads use the same connection as the ingestion operation. */
final class PdoIngestionSettingsRepository
{
    public function __construct(private PDO $database) {}
    private function rows(string $sql, array $values = []): array
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($values);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function activityAllowed(int $activity): bool
    {
        return $this->rows('SELECT id FROM automation_buckets WHERE is_enabled=1 AND activity_id=?', [$activity]) !== [];
    }
    public function enabledBucketVehicleIds(): array
    {
        return array_map('intval', array_column($this->rows('SELECT DISTINCT bv.vehicle_id FROM bucket_vehicles bv
            JOIN automation_buckets b ON b.id=bv.bucket_id AND b.is_enabled=1'), 'vehicle_id'));
    }
    public function speedLimit(int $vehicleId): float
    {
        $rows = $this->rows('SELECT speed_limit_kmh FROM overspeed_config WHERE vehicle_id=? AND is_enabled=1', [$vehicleId]);
        if (!$rows) $rows = $this->rows('SELECT speed_limit_kmh FROM overspeed_config WHERE vehicle_id IS NULL AND is_enabled=1 LIMIT 1');
        return $rows ? (float)$rows[0]['speed_limit_kmh'] : 80.0;
    }
    public function enabledActivities(int $vehicleId): array
    {
        $map = [];
        foreach ($this->rows('SELECT activity_id,is_enabled FROM activity_mappings WHERE vehicle_id=?', [$vehicleId]) as $row) {
            $map[(int)$row['activity_id']] = (int)$row['is_enabled'] === 1;
        }
        return $map;
    }
    public function configInt(string $key, int $default): int
    {
        $rows = $this->rows('SELECT config_value FROM config WHERE config_key=?', [$key]);
        $value = $rows[0]['config_value'] ?? $default;
        return is_numeric($value) ? (int)$value : $default;
    }
}
