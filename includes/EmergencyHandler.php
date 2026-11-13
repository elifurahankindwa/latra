<?php
/**
 * Emergency event handler.
 */
class EmergencyHandler {

    /** Emergency event types that trigger high-frequency capture. */
    const EMERGENCY_TYPES = ['overspeed', 'panic', 'accident'];

    /** Map a detected LATRA activity_id to an emergency event type (or null). */
    public static function mapEmergencyType(int $activityId): ?string {
        return match ($activityId) {
            LatraMapper::ACTIVITY_SPEEDING => 'overspeed',
            LatraMapper::ACTIVITY_PANIC_DRIVER, LatraMapper::ACTIVITY_PANIC_PASSENGER => 'panic',
            LatraMapper::ACTIVITY_ACCIDENT => 'accident',
            default => null,
        };
    }

    /**
     * After a fetch, end any active emergency event for a vehicle whose
     * triggering condition no longer holds in the returned messages.
     */
    public static function closeEndedEvent(int $vehicleId, array $messages): void {
        if (!$messages) return;
        $active = self::activeForVehicle($vehicleId);
        if (!$active) return;

        // If none of the fetched messages are still in the emergency state,
        // the event has ended — close it.
        $nowEmergency = false;
        foreach ($messages as $m) {
            $p = $m['p'] ?? [];
            $speed = isset($m['pos']['s']) ? round((float)$m['pos']['s'], 2) : 0;
            $det = EventDetector::detect($p, [], ['speed' => $speed, 'activity_enabled' => [], 'speed_limit' => EventDetector::speedLimit($vehicleId)]);
            if (self::mapEmergencyType($det['activity_id'])) { $nowEmergency = true; break; }
        }
        if (!$nowEmergency) {
            self::endEvent((int)$active['id']);
        }
    }

    /**
     * Ensure an active emergency event exists for a vehicle + type, or create one.
     * Returns the active event row (event_id field).
     */
    public static function ensureActiveEvent(int $vehicleId, string $eventType, ?string $reason = null, ?float $speed = null, ?int $activityId = null): ?array {
        $eventType = in_array($eventType, self::EMERGENCY_TYPES, true) ? $eventType : 'other';
        $active = Database::fetch(
            "SELECT * FROM emergency_events WHERE vehicle_id = ? AND status = 'active' AND event_type = ? LIMIT 1",
            [$vehicleId, $eventType]
        );
        if ($active) return $active;

        $eventId = self::generateEventId($vehicleId);
        $id = Database::insert('emergency_events', [
            'event_id'       => $eventId,
            'vehicle_id'     => $vehicleId,
            'event_type'     => $eventType,
            'activity_id'    => $activityId ?: ($eventType === 'overspeed' ? 4 : ($eventType === 'accident' ? 12 : 8)),
            'status'         => 'active',
            'started_at'     => date(DATE_FORMAT),
            'trigger_reason' => $reason,
            'max_speed_kmh'  => $speed,
        ]);
        Automation::log('emergency.start', ['event' => $eventId, 'vehicle' => $vehicleId, 'type' => $eventType]);
        Webhooks::emit('emergency_start', ['event' => $eventId, 'vehicle' => $vehicleId, 'type' => $eventType]);
        return Database::fetch("SELECT * FROM emergency_events WHERE id = ?", [$id]);
    }

    /**
     * Buffer a high-frequency GPS record against an active event.
     * Dedupes by (event_id, wialon_msg_time, vehicle_id). Returns true if inserted.
     */
    public static function bufferRecord(array $event, int $vehicleId, array $msg): bool {
        $pos = $msg['pos'] ?? [];
        try {
            Database::insert('emergency_queue', [
                'event_id'          => $event['event_id'],
                'emergency_event_id'=> $event['id'],
                'vehicle_id'        => $vehicleId,
                'wialon_msg_time'   => $msg['t'] ?? time(),
                'lat'               => isset($pos['y']) ? round((float)$pos['y'], 8) : null,
                'lng'               => isset($pos['x']) ? round((float)$pos['x'], 8) : null,
                'altitude'          => $pos['z'] ?? null,
                'speed'             => isset($pos['s']) ? round((float)$pos['s'], 2) : null,
                'bearing'           => $pos['c'] ?? null,
                'satellite_count'   => $pos['sc'] ?? null,
                'hdop'              => ($msg['p']['hdop'] ?? null),
                'raw_data'          => json_encode($msg),
                'latra_status'      => 'pending',
            ]);
            Database::query(
                "UPDATE emergency_events SET record_count = record_count + 1 WHERE id = ?",
                [$event['id']]
            );
            return true;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') return false; // duplicate already buffered
            throw $e;
        }
    }

    /**
     * Close an active emergency event, returning the vehicle to normal cadence.
     */
    public static function endEvent(int $eventId): void {
        Database::update('emergency_events', [
            'status'   => 'ended',
            'ended_at' => date(DATE_FORMAT),
        ], 'id = ?', [$eventId]);
        $ev = Database::fetch("SELECT * FROM emergency_events WHERE id = ?", [$eventId]);
        if ($ev) {
            Automation::log('emergency.end', ['event' => $ev['event_id'], 'vehicle' => $ev['vehicle_id'], 'type' => $ev['event_type']]);
            Webhooks::emit('emergency_end', ['event' => $ev['event_id'], 'vehicle' => $ev['vehicle_id']]);
        }
    }

    /**
     * End ALL active emergency events of a given type for a vehicle healthily.
     * Used when the triggering condition (e.g. speed) no longer applies.
     */
    public static function endActiveByVehicle(int $vehicleId, string $eventType): void {
        $rows = Database::fetchAll(
            "SELECT id FROM emergency_events WHERE vehicle_id = ? AND event_type = ? AND status = 'active'",
            [$vehicleId, $eventType]
        );
        foreach ($rows as $r) self::endEvent((int)$r['id']);
    }

    /**
     * Get the currently active emergency event for a vehicle (any type), if any.
     */
    public static function activeForVehicle(int $vehicleId): ?array {
        return Database::fetch(
            "SELECT e.*, v.vehicle_reg_no, v.imei FROM emergency_events e
             JOIN vehicles v ON v.id = e.vehicle_id
             WHERE e.vehicle_id = ? AND e.status = 'active'
             ORDER BY e.started_at DESC LIMIT 1",
            [$vehicleId]
        );
    }

    /**
     * Count of currently active emergency events (fleet-wide).
     */
    public static function activeCount(): int {
        $row = Database::fetch("SELECT COUNT(*) AS c FROM emergency_events WHERE status = 'active'");
        return (int)($row['c'] ?? 0);
    }

    /** List all emergency events, newest first (for audit UI). */
    public static function listEvents(int $limit = 50): array {
        return Database::fetchAll(
            "SELECT e.*, v.vehicle_reg_no FROM emergency_events e
             JOIN vehicles v ON v.id = e.vehicle_id
             ORDER BY e.started_at DESC LIMIT $limit"
        );
    }

    /**
     * Submit all pending emergency_queue records for a vehicle's active event
     * to LATRA. Returns counts [submitted, queued, failed, skipped].
     */
    public static function flushPending(array $event, int $vehicleId): array {
        $useCase = new \Latra\Application\Emergency\FlushPendingEmergencyReports(
            new \Latra\Infrastructure\Persistence\PdoEmergencyQueueRepository(Database::getInstance()),
            static function (array $event, array $row, array $vehicle): array {
                $payload = LatraMapper::buildPayload($vehicle, [
                    'lat' => $row['lat'], 'lng' => $row['lng'], 'altitude' => $row['altitude'],
                    'speed' => $row['speed'], 'bearing' => $row['bearing'],
                    'satellite_count' => $row['satellite_count'], 'hdop' => $row['hdop'],
                    'd2d3' => null, 'mgs_id' => $row['wialon_msg_time'],
                    'wialon_msg_time' => $row['wialon_msg_time'],
                ], (int) $event['activity_id']);
                $payload['items'][0]['emergency_event_id'] = $event['event_id'];
                return $payload;
            },
            static fn(array $payload, int $ownerVehicleId): array => self::pushPayload($payload, $ownerVehicleId),
        );
        return $useCase->flush($event, $vehicleId);
    }

    /** Direct POST of a full LATRA payload (shared HTTP helper). */
    public static function pushPayload(array $payload, ?int $vehicleId = null): array {
        if (defined('DELIVERY_ARCHITECTURE') && DELIVERY_ARCHITECTURE === 'relay') {
            if (!class_exists('RelayRuntime', false) && !class_exists('RelayRuntime', true)) {
                return ['ok' => false, 'response' => 'Relay queueing is unavailable in this runtime'];
            }
            try {
                $result = RelayRuntime::queueForVehicle($payload, $vehicleId);
                return ['ok' => !empty($result['success']), 'queued' => (int) ($result['queued'] ?? 0),
                    'recorded' => true, 'response' => $result['message'] ?? 'queued for relay delivery'];
            } catch (Throwable $e) {
                return ['ok' => false, 'response' => $e->getMessage()];
            }
        }
        return LatraAdapter::submit($payload);
    }

    private static function generateEventId(int $vehicleId): string {
        return 'EMG-' . $vehicleId . '-' . strtoupper(bin2hex(random_bytes(5)));
    }
}
