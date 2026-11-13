<?php
declare(strict_types=1);

namespace Latra\Domain\Delivery;

enum DeliveryClaimStatus: string
{
    case Empty = 'empty';
    case Blocked = 'blocked';
    case Deferred = 'deferred';
    case Review = 'review';
    case Ready = 'ready';
}
