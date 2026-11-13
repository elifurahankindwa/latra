<?php
/**
 * Relay payload mapping. Lives in the application layer because it combines domain policies with
 * per-vehicle enrollment/eligibility state; it performs no alerts, HTTP calls or source-history writes.
 */
declare(strict_types=1);

namespace Latra\Application\Collection;

use InvalidArgumentException;
use Latra\Domain\Collection\RelayEnrollmentPolicy;
use Latra\Domain\Ingestion\ActivityDetectionPolicy as EventDetector;
use Latra\Domain\Ingestion\GpsValidationPolicy as GpsValidator;
use Latra\Domain\Ingestion\LatraPayloadPolicy;
use Latra\Domain\Ingestion\ParameterResolutionPolicy;
use Latra\Domain\Ingestion\WialonMessageNormalizationPolicy;
use RuntimeException;

final class RelayPayloadMapper {
    public function __construct(private array $vehicle, private array $enabled, private array $allowed,
        private float $speedLimit, private int $overspeedSamples, private int $batteryLowMv,
        private string $profile, private int $eventCooldown = 0, private array $parameterAliases = []) {
        if (!in_array($profile, RelayEnrollmentPolicy::PROFILES, true)
            || $overspeedSamples < 1 || $batteryLowMv < 1) {
            throw new InvalidArgumentException('Explicit supported mapping policy required');
        }
    }

    private static function voltage(array $params, string $io, string $alias, bool $fmb): ?float {
        // IO numbers are profile-specific; named Wialon voltage parameters are expressed in volts.
        $key = $fmb && array_key_exists($io, $params) ? $io : $alias;
        if (!array_key_exists($key, $params) || $params[$key] === null) return null;
        if (!is_numeric($params[$key]) || (float) $params[$key] < 0) throw new RuntimeException('Invalid voltage');
        return (float) $params[$key] / ($key === $io ? 1000 : 1);
    }

    public function map(array $raw, array $state): array {
        if (!is_int($raw['t'] ?? null) || !is_array($raw['p'] ?? []) || !is_array($raw['pos'] ?? [])) {
            throw new RuntimeException('Invalid source structure');
        }
        foreach (($raw['pos'] ?? []) as $key => $value) {
            if (in_array($key, ['x', 'y', 'z', 's', 'c', 'sc'], true) && $value !== null && !is_numeric($value)) {
                throw new RuntimeException('Invalid position field');
            }
        }
        $params = ParameterResolutionPolicy::resolve(
            $raw['p'] ?? [],
            (string) ($this->vehicle['hw_type'] ?? ''),
            $this->parameterAliases,
        );
        $message = WialonMessageNormalizationPolicy::normalize($this->vehicle, $raw, $params);
        $params = $message['canonical_params'];
        $fmb = $this->profile === 'teltonika-fmb';
        $external = self::voltage($params, 'io_66', 'pwr_ext', $fmb);
        $internal = self::voltage($params, 'io_67', 'pwr_int', $fmb);
        $voltage = [];
        if ($external !== null) $voltage['ext_power_voltage'] = (string) $external;
        if ($internal !== null) $voltage['int_battery_voltage'] = (string) $internal;
        $activities = [EventDetector::MOVEMENT => true];
        // Retain the existing detector's supported activity, alongside independent signal matches.
        // Zero voltage alone is not authoritative unplug evidence in this new path.
        $detectParams = $params;
        unset($detectParams['io_66'], $detectParams['pwr_ext'], $detectParams['ext_voltage'], $detectParams['io_66_power']);
        $detected = EventDetector::detect($detectParams, $state, [
            'speed' => $message['speed'], 'activity_enabled' => $this->enabled,
            'speed_limit' => $this->speedLimit, 'engine_param' => $this->vehicle['engine_param'] ?? null,
        ]);
        $activity = (int) $detected['activity_id'];
        // Prefer the existing exact event-name mapping over a shorter substring (passenger_panic vs panic).
        $eventName = strtolower((string) ($params['event'] ?? ''));
        if (isset(EventDetector::EVENT_MAP[$eventName])) $activity = EventDetector::EVENT_MAP[$eventName];
        $over = $message['speed'] !== null && $message['speed'] > $this->speedLimit;
        $state['overspending_active'] = $over ? (int) ($state['overspending_active'] ?? 0) + 1 : 0;
        if ($activity !== EventDetector::SPEEDING || $state['overspending_active'] >= $this->overspeedSamples) {
            $activities[$activity] = true;
        }
        $engine = EventDetector::engineState($params, $this->vehicle['engine_param'] ?? null);
        $priorEngine = $state['engine_status'] ?? null;
        if ($engine !== null) {
            if ($priorEngine === 'off' && $engine) $activities[EventDetector::ENGINE_ON] = true;
            if ($priorEngine === 'on' && !$engine) $activities[EventDetector::ENGINE_OFF] = true;
            $state['engine_status'] = $engine ? 'on' : 'off';
        }
        if ($fmb) {
            // Explicit FMB profile only: Teltonika enum 1=acceleration, 2=braking, 3=turning.
            $driving = [1 => EventDetector::HASH_ACCEL, 2 => EventDetector::HASH_BRAKING, 3 => EventDetector::HASH_TURNING];
            $code = $params['io_253'] ?? null;
            if ((is_int($code) || is_string($code)) && isset($driving[$code])) $activities[$driving[$code]] = true;
            if (in_array($params['io_252'] ?? null, [1, '1'], true)) $activities[EventDetector::POWER_DISC] = true;
            if (in_array($params['io_251'] ?? null, [1, '1'], true)) $activities[EventDetector::EXCESSIVE_IDLE] = true;
        }
        // Reuse the configured voltage rule; a zero/missing voltage is unknown, not low battery.
        if ($internal !== null && $internal > 0 && $internal * 1000 < $this->batteryLowMv) {
            $activities[EventDetector::BATTERY_LOW] = true;
        }

        // A compact trip accumulator replaces the successful-telemetry history dependency.
        $trip = $state['trip'] ?? null;
        if ($engine === true && $priorEngine === 'off') {
            $trip = ['from' => $raw['t'], 'distance' => 0, 'sum' => 0, 'count' => 0, 'max' => 0];
        }
        if ($trip !== null) {
            if ($message['lat'] !== null && $message['lng'] !== null) {
                if (isset($trip['lat'], $trip['lng'])) $trip['distance'] += LatraPayloadPolicy::haversineKm(
                    $trip['lat'], $trip['lng'], $message['lat'], $message['lng']);
                $trip['lat'] = $message['lat'];
                $trip['lng'] = $message['lng'];
            }
            if ($message['speed'] !== null) {
                $trip['sum'] += $message['speed']; $trip['count']++;
                $trip['max'] = max($trip['max'], $message['speed']);
            }
        }
        $payload = null;
        ksort($activities);
        foreach (array_keys($activities) as $id) {
            if (empty($this->enabled[$id]) || !in_array($id, $this->allowed, true)) continue;
            if ($this->eventCooldown > 0 && !in_array($id, [1,2,3,9,10,14,18,19,20,21,22,23], true)) {
                // Preserve the event engine's wall-clock cooldown when creating durable event intent.
                if (time() - (int) ($state['momentary_at'][$id] ?? 0) < $this->eventCooldown) continue;
                $state['momentary_at'][$id] = time();
            }
            $custom = [];
            if ($id === EventDetector::ENGINE_OFF) {
                // An initial engine-on baseline does not establish a trip start.
                if ($trip === null) throw new RuntimeException('Engine-off trip baseline unavailable');
                $custom = ['distance' => round($trip['distance'], 2),
                    'duration' => round(($raw['t'] - $trip['from']) / 60, 2),
                    'avgSpeed' => $trip['count'] ? round($trip['sum'] / $trip['count'], 2) : null,
                    'maxSpeed' => $trip['max']];
            }
            $addon = LatraPayloadPolicy::buildAddonInfo($id, $params, $custom) ?? [];
            // All available voltage observations travel with the report, including ordinary movement.
            unset($addon['ext_power_voltage'], $addon['int_battery_voltage']);
            $addon = array_merge($addon, $voltage);
            $fuel = $id === EventDetector::FUEL_REPORT ? LatraPayloadPolicy::buildFuelInfo($params) : [];
            // Do not borrow the vehicle's latest GPS fix for a historical source report.
            $vehicle = array_intersect_key($this->vehicle, array_flip(['id', 'imei', 'vehicle_reg_no']));
            $one = LatraPayloadPolicy::buildPayload($vehicle, $message, $id, $addon, $fuel);
            if ($addon) $one['items'][0]['addon_info'] = $addon;
            $validation = GpsValidator::validate($message + ['activity_id' => $id]);
            if (!$validation['valid'] || LatraPayloadPolicy::validatePayload($one)) {
                throw new RuntimeException('Source or payload validation requires review');
            }
            if ($payload === null) $payload = $one;
            else $payload['items'][] = $one['items'][0];
        }
        $state['trip'] = $engine === false ? null : $trip;
        return ['payload' => $payload, 'state' => $state, 'suppressed' => $payload === null];
    }
}
