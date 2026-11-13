<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use InvalidArgumentException;

final class RecoverRelayReviews
{
    public function __construct(private RelayReviewRecoveryRepository $repository)
    {
    }

    public function recover(string $vehicleUnit, callable $map): array
    {
        if (!preg_match('/^wialon:[0-9]+$/D', $vehicleUnit)) {
            throw new InvalidArgumentException('Explicit Wialon vehicle unit required');
        }

        return $this->repository->recoverReviews($vehicleUnit, $map);
    }
}
