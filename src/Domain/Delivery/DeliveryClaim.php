<?php
declare(strict_types=1);

namespace Latra\Domain\Delivery;

use InvalidArgumentException;

final class DeliveryClaim
{
    public readonly DeliveryClaimStatus $status;
    private readonly ?array $pendingReport;

    public function __construct(
        DeliveryClaimStatus $status,
        ?array $report = null,
    ) {
        if (($status === DeliveryClaimStatus::Ready) !== ($report !== null)) {
            throw new InvalidArgumentException('A ready delivery claim must include its pending report');
        }
        $this->status = $status;
        $this->pendingReport = $report;
    }

    public function outcome(): string
    {
        if ($this->status === DeliveryClaimStatus::Ready) {
            throw new InvalidArgumentException('A ready delivery claim has no terminal outcome');
        }

        return $this->status->value;
    }

    public function report(): array
    {
        if ($this->pendingReport === null) {
            throw new InvalidArgumentException('Only a ready delivery claim contains a pending report');
        }

        return $this->pendingReport;
    }
}
