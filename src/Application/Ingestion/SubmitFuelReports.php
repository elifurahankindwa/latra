<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

use Closure;
use Latra\Domain\Ingestion\FuelReportPolicy;
use Latra\Domain\Ingestion\LatraPayloadPolicy;
use RuntimeException;
use Throwable;

final class SubmitFuelReports
{
    private Closure $submit;
    private Closure $log;

    public function __construct(
        private FuelReportRepository $repository,
        private FuelReportSource $source,
        callable $submit,
        callable $log,
        private bool $relay,
    ) {
        $this->submit = Closure::fromCallable($submit);
        $this->log = Closure::fromCallable($log);
    }

    public function run(): int
    {
        $vehicles = $this->repository->vehicles();
        if (!$vehicles) {
            return 0;
        }
        $pushed = $skipped = 0;
        try {
            if (!$this->source->open()) {
                throw new RuntimeException('Wialon login failed for fuel report');
            }
            foreach ($vehicles as $vehicle) {
                $unit = (int) $vehicle['wialon_unit_id'];
                $reading = FuelReportPolicy::read($vehicle, $this->source->read($unit));
                if (isset($reading['reason'])) {
                    $skipped++;
                    $context = ['vehicle_id' => $vehicle['id'], 'reason' => $reading['reason'], 'unit_id' => $unit];
                    if ($reading['reason'] === 'no_live_fuel_value') {
                        $context['sensor'] = $vehicle['fuel_level_sensor'];
                    }
                    ($this->log)('fuel.skipped', $context, false);
                    continue;
                }
                $payload = LatraPayloadPolicy::buildPayload($vehicle, $reading['source'], 16, [], $reading['fuel']);
                try {
                    $result = ($this->submit)($vehicle, $payload);
                } catch (Throwable $error) {
                    if (!$this->relay) {
                        throw $error;
                    }
                    $result = ['message' => $error->getMessage()];
                }
                $ok = $this->relay
                    ? !empty($result['success'])
                    : (!empty($result['ok']) || !empty($result['skipped']));
                if ($ok) {
                    $pushed++;
                    continue;
                }
                $skipped++;
                $error = $this->relay
                    ? ($result['message'] ?? 'unknown')
                    : ($result['curlError'] ?? ($result['response'] ?? 'unknown'));
                ($this->log)(
                    $this->relay ? 'fuel.queue_failed' : 'fuel.push_failed',
                    ['vehicle_id' => $vehicle['id'], 'error' => $error],
                    true,
                );
            }
        } finally {
            $this->source->close();
        }
        ($this->log)('fuel.report', ['pushed' => $pushed, 'skipped' => $skipped], false);
        return $pushed;
    }
}
