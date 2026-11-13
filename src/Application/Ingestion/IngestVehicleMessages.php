<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

use Latra\Domain\Ingestion\ActivityDetectionPolicy;
use Latra\Domain\Ingestion\GpsValidationPolicy;
use Latra\Domain\Ingestion\LatraPayloadPolicy;
use Latra\Domain\Ingestion\WialonMessageNormalizationPolicy;
use Throwable;

final class IngestVehicleMessages
{
    public function __construct(
        private IngestionRepository $repository,
        private IngestionEffects $effects,
    ) {
    }

    public function ingest(array $vehicle, array $messages): array
    {
        $inserted = 0;
        $rejected = 0;
        $skipped = 0;
        $vehicleId = (int) $vehicle['id'];
        $previousState = $this->repository->loadVehicleState($vehicleId);
        $enabledActivities = $this->repository->enabledActivities($vehicleId);
        $speedLimit = $this->repository->speedLimit($vehicle);
        $consecutiveOver = (int) ($previousState['overspending_active'] ?? 0);
        $requiredSamples = $this->repository->overspeedConsecutiveSamples();

        foreach ($messages as $raw) {
            $parameters = $this->repository->resolveParameters(
                $raw['p'] ?? [],
                (string) ($vehicle['hw_type'] ?? ''),
            );
            $canonical = WialonMessageNormalizationPolicy::normalize($vehicle, $raw, $parameters);
            unset($canonical['canonical_params']);

            $detected = ActivityDetectionPolicy::detect($parameters, $previousState, [
                'speed' => (float) ($canonical['speed'] ?? 0),
                'activity_enabled' => $enabledActivities,
                'speed_limit' => $speedLimit,
                'engine_param' => $vehicle['engine_param'] ?? null,
            ]);
            $activityId = (int) $detected['activity_id'];

            $ignited = ActivityDetectionPolicy::engineState($parameters, $vehicle['engine_param'] ?? null);
            if ($ignited !== null) {
                $previousState['engine_status'] = $ignited ? 'on' : 'off';
            }

            if ($activityId === ActivityDetectionPolicy::SPEEDING) {
                $consecutiveOver++;
            } else {
                $consecutiveOver = 0;
            }
            $overspeedReady = $consecutiveOver >= $requiredSamples;

            $emergencyType = $this->effects->mapEmergencyType($activityId);
            if ($emergencyType === 'overspeed' && !$overspeedReady) {
                $emergencyType = null;
                $activityId = ActivityDetectionPolicy::MOVEMENT;
            }

            $eventId = null;
            if ($emergencyType !== null) {
                $activeEvent = $this->effects->ensureActiveEvent(
                    $vehicleId,
                    $emergencyType,
                    $detected['reason'],
                    $canonical['speed'] !== null ? (float) $canonical['speed'] : null,
                    $activityId,
                );
                if ($activeEvent) {
                    $eventId = $activeEvent['event_id'];
                    $this->effects->bufferEmergencyRecord($activeEvent, $vehicleId, $raw);
                }
            }

            $this->effects->updateDeviceStatus($vehicleId, $parameters, (int) ($raw['rt'] ?? time()));

            $customAddonData = [];
            if ($activityId === ActivityDetectionPolicy::ENGINE_OFF) {
                $customAddonData = $this->effects->buildTripStats(
                    $vehicleId,
                    (int) $canonical['wialon_msg_time'],
                );
            }
            $addonInfo = json_encode(
                $this->effects->buildAddonInfo($activityId, $parameters, $customAddonData) ?? [],
            );
            $fuelInfo = $activityId === LatraPayloadPolicy::ACTIVITY_FUEL_REPORT
                ? json_encode(LatraPayloadPolicy::buildFuelInfo($parameters))
                : null;

            $messageData = [
                'vehicle_id' => $vehicleId,
                'wialon_msg_time' => $canonical['wialon_msg_time'],
                'gps_time' => $canonical['gps_time'],
                'data_received_time' => $canonical['data_received_time'],
                'data_processed_time' => $canonical['data_processed_time'],
                'raw_data' => $canonical['raw_data'],
                'lat' => $canonical['lat'],
                'lng' => $canonical['lng'],
                'altitude' => $canonical['altitude'],
                'speed' => $canonical['speed'],
                'bearing' => $canonical['bearing'],
                'satellite_count' => $canonical['satellite_count'],
                'hdop' => $canonical['hdop'],
                'd2d3' => $canonical['d2d3'],
                'rssi' => $canonical['rssi'],
                'lac' => $canonical['lac'],
                'cell_id' => $canonical['cell_id'],
                'mcc' => $canonical['mcc'],
                'mgs_id' => $canonical['mgs_id'],
                'odometer' => $canonical['odometer'],
                'driver_id' => $canonical['driver_id'],
                'battery_pct' => $canonical['battery_pct'],
                'battery_mv' => $canonical['battery_mv'],
                'event_id' => $eventId,
                'activity_id' => $activityId,
                'addon_info' => $addonInfo,
                'fuel_info' => $fuelInfo,
                'latra_status' => 'pending',
                'data_received_at' => $canonical['data_received_at'],
                'idempotency_key' => 'v' . $vehicleId . '-t' . $canonical['wialon_msg_time'] . '-a' . $activityId,
            ];

            $validation = GpsValidationPolicy::validate($messageData + [
                'imei' => $vehicle['imei'],
                'vehicle_reg_no' => $vehicle['vehicle_reg_no'],
            ]);
            $messageData['validation_status'] = GpsValidationPolicy::statusOf($validation);
            if (!$validation['valid']) {
                $messageData['latra_status'] = 'rejected';
            }

            $this->repository->beginMessage();
            try {
                if (!$this->repository->storeMessage($messageData)) {
                    $this->repository->commitMessage();
                    $skipped++;
                    continue;
                }
                $storedMessage = $this->repository->findMessage(
                    $vehicleId,
                    (int) $canonical['wialon_msg_time'],
                );
                if (!$storedMessage) {
                    throw new \RuntimeException('Stored source message could not be loaded');
                }
                if (!$validation['valid']) {
                    $this->repository->recordValidationErrors(
                        $storedMessage + $messageData,
                        $validation['errors'],
                    );
                    $this->repository->commitMessage();
                    $rejected++;
                    continue;
                }

                $payload = LatraPayloadPolicy::buildPayload(
                    $vehicle,
                    $storedMessage,
                    $activityId,
                    json_decode($addonInfo, true) ?: [],
                    json_decode($fuelInfo ?: '[]', true) ?: [],
                );
                if ($eventId) {
                    $payload['items'][0]['emergency_event_id'] = $eventId;
                }
                $this->repository->updateMessagePayload($storedMessage, $payload);

                if ($this->repository->activityAllowed($activityId)
                    && in_array($vehicleId, $this->repository->enabledBucketVehicleIds(), true)) {
                    $this->repository->enqueue(
                        $storedMessage + [
                            'activity_id' => $activityId,
                            'idempotency_key' => $messageData['idempotency_key'],
                        ],
                        $payload,
                        $eventId,
                        $eventId ? 10 : 0,
                    );
                }
                $this->repository->commitMessage();
                $inserted++;
            } catch (Throwable $error) {
                $this->repository->rollbackMessage();
                throw $error;
            }
        }

        $this->effects->closeEndedEvent($vehicleId, $messages);

        // Persist the newest source message's state. Wialon may return messages in any order, so
        // select by source time instead of trusting the first array element.
        $newestMessage = null;
        foreach ($messages as $candidate) {
            if ($newestMessage === null
                || (int) ($candidate['t'] ?? 0) >= (int) ($newestMessage['t'] ?? 0)) {
                $newestMessage = $candidate;
            }
        }
        if ($newestMessage !== null) {
            $lastPosition = $newestMessage['pos'] ?? [];
            $lastParameters = $this->repository->resolveParameters(
                $newestMessage['p'] ?? [],
                (string) ($vehicle['hw_type'] ?? ''),
            );
            $lastSpeed = isset($lastPosition['s']) ? round((float) $lastPosition['s'], 2) : 0;
            $lastDetected = ActivityDetectionPolicy::detect($lastParameters, $previousState, [
                'speed' => $lastSpeed,
                'activity_enabled' => $enabledActivities,
                'speed_limit' => $speedLimit,
                'engine_param' => $vehicle['engine_param'] ?? null,
            ]);
            $this->repository->persistVehicleState($vehicleId, [
                'engine_status' => ActivityDetectionPolicy::engineState(
                    $lastParameters,
                    $vehicle['engine_param'] ?? null,
                ) === true ? 'on' : 'off',
                'external_power' => (int) (ActivityDetectionPolicy::externalPowerState($lastParameters) === true ? 1 : 0),
                'last_speed_kmh' => $lastSpeed,
                'last_lat' => isset($lastPosition['y']) ? round((float) $lastPosition['y'], 8) : null,
                'last_lng' => isset($lastPosition['x']) ? round((float) $lastPosition['x'], 8) : null,
                'last_event_activity_id' => $lastDetected['activity_id'],
                'last_event_at' => $this->repository->formattedDateTime(),
                'overspending_active' => $consecutiveOver,
            ]);
        }

        return compact('inserted', 'rejected', 'skipped');
    }
}
