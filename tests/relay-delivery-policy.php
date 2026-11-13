<?php
/** Pure delivery classification checks; no database, network, or application credentials. */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Domain\Delivery\DeliveryDisposition;
use Latra\Domain\Delivery\DeliveryResponsePolicy;

function policyExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$cases = [
    [['code' => 200, 'data' => ['status' => '1'], 'error' => ''], 1, 8, DeliveryDisposition::Accepted],
    [['code' => 200, 'data' => ['status' => 1], 'error' => ''], 1, 8, DeliveryDisposition::Retry],
    [['code' => 200, 'data' => ['status' => '0'], 'error' => ''], 1, 8, DeliveryDisposition::Review],
    [['code' => 400, 'data' => null, 'error' => ''], 1, 8, DeliveryDisposition::Review],
    [['code' => 408, 'data' => null, 'error' => ''], 1, 8, DeliveryDisposition::Retry],
    [['code' => 425, 'data' => null, 'error' => ''], 1, 8, DeliveryDisposition::Retry],
    [['code' => 429, 'data' => null, 'error' => ''], 1, 8, DeliveryDisposition::Retry],
    [['code' => 503, 'data' => null, 'error' => ''], 1, 8, DeliveryDisposition::Retry],
    [['code' => 400, 'data' => null, 'error' => 'transport failure'], 1, 8, DeliveryDisposition::Retry],
    [['code' => 503, 'data' => null, 'error' => ''], 8, 8, DeliveryDisposition::Review],
];

foreach ($cases as [$response, $attempt, $maximumAttempts, $expected]) {
    policyExpect(
        DeliveryResponsePolicy::classify($response, $attempt, $maximumAttempts) === $expected,
        'Delivery response classification changed'
    );
}

foreach ([[[], 0, 8], [[], 1, 0], [[], 9, 8]] as [$response, $attempt, $maximumAttempts]) {
    try {
        DeliveryResponsePolicy::classify($response, $attempt, $maximumAttempts);
        throw new LogicException('Invalid delivery attempt was accepted');
    } catch (InvalidArgumentException) {
    }
}

echo "PASS delivery response policy classifications\n";
