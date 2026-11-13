<?php
declare(strict_types=1);

namespace Latra\Domain\Delivery;

use InvalidArgumentException;

final class DeliveryResponsePolicy
{
    public static function classify(array $response, int $attempt, int $maximumAttempts): DeliveryDisposition
    {
        if ($attempt < 1 || $maximumAttempts < 1 || $attempt > $maximumAttempts) {
            throw new InvalidArgumentException('Delivery attempt must be within the configured attempt budget');
        }

        if (($response['code'] ?? null) === 200
            && ($response['data']['status'] ?? null) === '1'
            && empty($response['error'])) {
            return DeliveryDisposition::Accepted;
        }

        $code = $response['code'] ?? 0;
        $permanent = empty($response['error'])
            && (($code >= 400 && $code < 500 && !in_array($code, [408, 425, 429], true))
                || ($code === 200 && ($response['data']['status'] ?? null) === '0'));

        return $permanent || $attempt >= $maximumAttempts
            ? DeliveryDisposition::Review
            : DeliveryDisposition::Retry;
    }
}
