<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Ingestion\FuelReportRepository;
use PDO;

final class PdoFuelReportRepository implements FuelReportRepository
{
    public function __construct(private PDO $database) {}
    public function vehicles(): array
    {
        return $this->database->query("SELECT v.* FROM vehicles v WHERE is_active=1 AND wialon_unit_id>0
            AND fuel_level_sensor IS NOT NULL AND fuel_level_sensor<>''")->fetchAll(PDO::FETCH_ASSOC);
    }
}
