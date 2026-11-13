<?php
declare(strict_types=1);

namespace Latra\Domain\Delivery;

enum DeliveryDisposition
{
    case Accepted;
    case Retry;
    case Review;
}
