<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

interface RelayEnrollmentRepository
{
    public function saveEnrollment(int $vehicleId, int $unitId, string $profile, array $budgets): void;
}
