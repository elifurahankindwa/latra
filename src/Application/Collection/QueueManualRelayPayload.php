<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use Latra\Domain\Exception\InvalidSubmission;
use Latra\Domain\Ingestion\LatraPayloadPolicy;

/** Manual submissions create durable intent and never invoke a transport. */
final class QueueManualRelayPayload
{
    public function __construct(private RelayWindowCollector $collector, private int $maxBytes) {}
    public function queue(array $vehicle, array $payload): array
    {
        if (($payload['vehicle_reg_no'] ?? null) !== $vehicle['vehicle_reg_no']
            || ($payload['imei'] ?? null) !== $vehicle['imei']
            || !is_array($payload['items'] ?? null) || !$payload['items'] || !array_is_list($payload['items'])) {
            throw new InvalidSubmission('Payload vehicle identity mismatch');
        }
        foreach ($payload['items'] as $item) {
            if (!is_array($item)) throw new InvalidSubmission('Invalid manual payload');
            $one = $payload;
            $one['items'] = [$item];
            if (LatraPayloadPolicy::validatePayload($one)) throw new InvalidSubmission('Invalid manual payload');
        }
        $timestamp = $payload['items'][0]['timestamp'] ?? null;
        if (!is_string($timestamp) || !ctype_digit($timestamp)) throw new InvalidSubmission('Source timestamp required');
        $time = intdiv((int)$timestamp, 1000);
        $raw = ['t'=>$time, 'payload'=>$payload];
        $stream = 'manual:wialon:' . $vehicle['wialon_unit_id'] . ':'
            . substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 24);
        $result = $this->collector->collectWindow($stream, $time, $time, 1, 1, $this->maxBytes,
            static fn(): array => ['count'=>1,'messages'=>[$raw]],
            static fn(array $message, array $state): array => ['payload'=>$message['payload'],'state'=>$state]);
        return ['success'=>true,'queued'=>$result['queued'],
            'message'=>'Saved to the durable relay. Real LATRA delivery is blocked.'];
    }
}
