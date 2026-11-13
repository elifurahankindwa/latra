<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

interface RelayReviewRecoveryRepository
{
    public function recoverReviews(string $vehicleUnit, callable $map): array;
}
