<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use Latra\Domain\Collection\RelayEnrollmentPolicy;

final class EnrollRelayVehicle
{
    public function __construct(private RelayEnrollmentRepository $repository)
    {
    }

    public function enroll(int $vehicleId, int $unitId, string $profile, array $budgets): void
    {
        if ($vehicleId < 1 || $unitId < 1) {
            throw new \InvalidArgumentException('Valid vehicle and Wialon unit identities required');
        }

        $validatedBudgets = RelayEnrollmentPolicy::validate($profile, $budgets);
        $this->repository->saveEnrollment($vehicleId, $unitId, $profile, $validatedBudgets);
    }
}
