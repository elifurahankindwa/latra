<?php
/** Application cutover coordinator. Every active producer uses the same durable relay. */
use Latra\Application\Collection\RelayPayloadMapper;
use Latra\Application\Collection\EnrollRelayVehicle;
use Latra\Application\Collection\CollectRelayBucket;
use Latra\Application\Collection\CollectRelayEventCycle;
use Latra\Infrastructure\Persistence\PdoRelayEnrollmentRepository;
use Latra\Infrastructure\Persistence\PdoRelayRuntimeRepository;

final class RelayRuntime {
    public static function enabled(): bool {
        return defined('DELIVERY_ARCHITECTURE') && DELIVERY_ARCHITECTURE === 'relay';
    }

    public static function settings(int $vehicleId): array {
        $row = self::settingsOrNull($vehicleId);
        if ($row === null) throw new RuntimeException('Vehicle is not enrolled for relay collection');
        return $row;
    }

    /** Enrolled settings, or null so iteration can skip instead of aborting the whole run. */
    public static function settingsOrNull(int $vehicleId): ?array {
        $row = Database::fetch('SELECT * FROM relay_vehicle_settings WHERE vehicle_id=? AND is_enabled=1', [$vehicleId]);
        if (!$row) return null;
        foreach (['max_records', 'max_bytes', 'max_pending_records', 'max_pending_bytes'] as $key) {
            if ((int) $row[$key] < 1) throw new RuntimeException('Explicit relay resource budgets required');
        }
        return $row;
    }

    private static function queue(array $settings): DurableRelay {
        return new DurableRelay(Database::getInstance(), Schema::configInt('outbox_max_tries', 8),
            static fn(int $attempt): int => min(900, Automation::nextBackoff($attempt)),
            (int) $settings['max_pending_records'], (int) $settings['max_pending_bytes']);
    }

    /** Enrollment changes only relay settings, never activity mappings or schedules. */
    public static function enroll(array $vehicle, string $profile, array $budgets): void {
        (new EnrollRelayVehicle(new PdoRelayEnrollmentRepository(Database::getInstance())))
            ->enroll((int) $vehicle['id'], (int) $vehicle['wialon_unit_id'], $profile, $budgets);
    }

    /** Payload-free operator status includes each independent collection stream. */
    public static function status(array $vehicle): array {
        $settings = self::settings((int) $vehicle['id']);
        $unit = 'wialon:' . $vehicle['wialon_unit_id'];
        $streams = Database::fetchAll('SELECT unit,collected_through,delivered_time,attempted_time FROM relay_units WHERE unit=? OR unit LIKE ? ORDER BY unit', [$unit, $unit . ':%']);
        $counts = Database::fetch("SELECT COUNT(*) AS unresolved,COALESCE(SUM(status='review'),0) AS review,
            COALESCE(SUM(OCTET_LENGTH(payload)),0) AS bytes FROM relay_pending WHERE unit=? OR unit LIKE ?", [$unit, $unit . ':%']);
        return ['profile' => $settings['profile'], 'streams' => $streams, 'queue' => array_map('intval', $counts)];
    }

    /** Operator-triggered recovery uses current identity and mapping policy without fetching source data. */
    public static function recoverReviews(array $vehicle): array {
        $settings = self::settings((int) $vehicle['id']);
        $vehicleUnit = 'wialon:' . $vehicle['wialon_unit_id'];
        $queue = self::queue($settings);
        $map = static function (array $source, string $unit) use ($vehicle, $settings, $vehicleUnit): array {
            $stream = substr($unit, strlen($vehicleUnit));
            $events = $stream === ':events';
            if ($events) {
                $allowed = array_map('intval', array_column(Database::fetchAll(
                    'SELECT DISTINCT activity_id FROM wialon_event_map WHERE activity_id<>1 AND is_enabled=1'), 'activity_id'));
            } elseif (preg_match('/^:activity:([0-9]+)$/D', $stream, $match)) {
                $allowed = [(int) $match[1]];
            } elseif ($stream === ':poll') {
                $allowed = self::allowed((int) $vehicle['id']);
            } else {
                throw new RuntimeException('Unsupported collection stream for automatic recovery');
            }
            return self::mapper($vehicle, $settings, $allowed, $events)->map($source, []);
        };
        return $queue->recoverReviews($vehicleUnit, $map);
    }

    private static function allowed(int $vehicleId): array {
        $rows = Database::fetchAll('SELECT DISTINCT b.activity_id FROM automation_buckets b
            JOIN bucket_vehicles bv ON bv.bucket_id=b.id WHERE b.is_enabled=1 AND bv.vehicle_id=?', [$vehicleId]);
        return array_map('intval', array_column($rows, 'activity_id'));
    }

    private static function mapper(array $vehicle, array $settings, array $allowed, bool $events): RelayPayloadMapper {
        return new RelayPayloadMapper($vehicle, EventDetector::enabledActivities((int) $vehicle['id']), $allowed,
            IngestPipeline::speedLimit($vehicle), Schema::configInt('overspeed_consecutive', 2),
            Schema::configInt('battery_low_mv', 3200), $settings['profile'],
            $events ? max(0, Schema::configInt('event_engine_cooldown_seconds', 300)) : 0,
            ParamResolver::enabledAliases());
    }

    /** Independent stream cursors preserve bucket cadences while events use their own cadence. */
    public static function collect(array $vehicle, int $window, int $limit, string $stream, array $allowed,
        ?WialonAPI $source = null, ?int $explicitFrom = null, ?int $explicitTo = null): array {
        $settings = self::settingsOrNull((int) $vehicle['id']);
        if ($settings === null) {
            Logger::warn('relay.not_enrolled', ['vehicle' => (int) $vehicle['id']]);
            return ['fetched' => 0, 'queued' => 0, 'inserted' => 0, 'rejected' => 0, 'skipped' => 0];
        }
        $queue = self::queue($settings);
        $mapper = null;
        return (new \Latra\Application\Collection\CollectRelayStream(
            static fn(string $unit): ?array => $queue->status($unit)['progress'],
            static function (int $from, int $to, int $pageSize, int $maximum, int $maxBytes) use (
                $vehicle, $settings, $allowed, $source, $queue, $stream, &$mapper,
            ): array {
                $mapper ??= self::mapper($vehicle, $settings, $allowed, $stream === ':events');
                return FleetSync::collectRelayWindow(
                    $vehicle, $queue, $mapper, $from, $to, $pageSize, $maximum, $maxBytes, $source, $stream,
                );
            },
            static fn(): int => time(),
        ))->collect(
            'wialon:' . $vehicle['wialon_unit_id'], $stream, $window, $limit,
            (int) $settings['max_records'], (int) $settings['max_bytes'], $explicitFrom, $explicitTo,
        );
    }

    public static function poll(array $vehicle, int $window, int $limit): array {
        return self::collect($vehicle, $window, $limit, ':poll', self::allowed((int) $vehicle['id']));
    }

    public static function bucket(array $bucket, ?callable $sourceFactory = null): array {
        return (new CollectRelayBucket(
            new PdoRelayRuntimeRepository(Database::getInstance()),
            static fn(array $vehicle, int $window, int $limit, string $stream, array $allowed, ?WialonAPI $source): array =>
                self::collect($vehicle, $window, $limit, $stream, $allowed, $source),
            $sourceFactory ?? static fn(array $vehicle): null => null,
            static fn(string $channel, array $context): mixed => Logger::warn($channel, $context),
        ))->run($bucket);
    }

    public static function cycle(): array {
        $stats = ['vehicles' => 0, 'fetched' => 0, 'detected' => 0, 'sent' => 0, 'failed' => 0];
        if (Schema::config('scheduler_enabled', '1') !== '1' || Schema::config('event_engine_enabled', '1') !== '1') return $stats;
        return (new CollectRelayEventCycle(
            new PdoRelayRuntimeRepository(Database::getInstance()),
            static fn(array $vehicle, int $window, int $limit, string $stream, array $allowed): array =>
                self::collect($vehicle, $window, $limit, $stream, $allowed),
            static fn(string $channel, array $context): mixed => Logger::warn($channel, $context),
        ))->run(
            Schema::configInt('event_engine_window_seconds', 900),
            Schema::configInt('event_engine_limit', 100),
        );
    }

    /** No real LATRA transport is enabled during cutover. Mock delivery is an explicit setting. */
    public static function flush(int $limit, bool $emergencyOnly, int $seconds): int {
        $url = Schema::config('relay_mock_url', '');
        if ($url === '') return 0;
        $transport = new RelayMockTransport($url);
        $activeUnit = null;
        $activeVehicle = null;
        $findVehicle = static function (string $unit) use (&$activeUnit, &$activeVehicle): ?array {
            if ($activeUnit !== $unit) {
                $activeUnit = $unit;
                if (!preg_match('/^(?:manual:)?wialon:([0-9]+)(?::.*)?$/D', $unit, $match)) {
                    $activeVehicle = null;
                } else {
                    $activeVehicle = Database::fetch(
                        'SELECT * FROM vehicles WHERE wialon_unit_id=? AND is_active=1',
                        [(int) $match[1]]
                    );
                }
            }
            return $activeVehicle;
        };
        $flush = new \Latra\Application\Delivery\FlushPendingRelayReports(
            static fn(int $now): array => Database::fetchAll("SELECT DISTINCT p.unit FROM relay_pending p
                WHERE p.status='pending' AND p.next_attempt<=? ORDER BY p.unit LIMIT 200", [$now]),
            static function (string $unit, int $now, callable $send, callable $canSend) use (
                $findVehicle,
                &$activeUnit,
                &$activeVehicle,
            ): string {
                try {
                    $vehicle = $findVehicle($unit);
                    if (!$vehicle) return 'empty';
                    $settings = self::settingsOrNull((int) $vehicle['id']);
                    if ($settings === null) return 'empty';
                    return self::queue($settings)->deliver(
                        $unit,
                        $now,
                        $send,
                        static fn(array $pending): bool => $canSend($unit, $pending),
                    );
                } finally {
                    $activeUnit = null;
                    $activeVehicle = null;
                }
            },
            static function (string $unit, array $pending) use ($findVehicle): bool {
                $vehicle = $findVehicle($unit);
                if (!$vehicle) return false;
                $enabled = EventDetector::enabledActivities((int) $vehicle['id']);
                $allowed = self::allowed((int) $vehicle['id']);
                if (str_ends_with($unit, ':events') && Schema::config('event_engine_enabled', '1') === '1') {
                    $allowed = array_map('intval', array_column(Database::fetchAll(
                        'SELECT DISTINCT activity_id FROM wialon_event_map WHERE activity_id<>1 AND is_enabled=1'), 'activity_id'));
                }
                $activityIds = array_map(
                    'intval',
                    array_column(json_decode($pending['payload'], true)['items'] ?? [], 'activity_id'),
                );
                foreach ($activityIds as $activityId) {
                    if (empty($enabled[$activityId]) || !in_array($activityId, $allowed, true)) return false;
                }
                return true;
            },
            static fn(): int => Schema::configInt('latra_rate_per_minute', 300),
            static fn(string $body, string $key): array => $transport($body, $key),
        );
        return $flush->flush($limit, $emergencyOnly, $seconds);
    }

    /** Resolve the exact owning vehicle before any relay intent is enqueued. */
    public static function queueForVehicle(array $payload, ?int $vehicleId = null): array {
        $vehicle = null;
        if ($vehicleId !== null) {
            $vehicle = Database::fetch('SELECT * FROM vehicles WHERE id = ? LIMIT 1', [(int) $vehicleId]);
        }
        if (!$vehicle) {
            $vehicleReg = $payload['vehicle_reg_no'] ?? null;
            $imei = $payload['imei'] ?? null;
            if ($vehicleReg !== null || $imei !== null) {
                $clauses = [];
                $params = [];
                if ($vehicleReg !== null) {
                    $clauses[] = 'vehicle_reg_no = ?';
                    $params[] = $vehicleReg;
                }
                if ($imei !== null) {
                    $clauses[] = 'imei = ?';
                    $params[] = $imei;
                }
                $vehicle = Database::fetch('SELECT * FROM vehicles WHERE ' . implode(' OR ', $clauses) . ' LIMIT 1', $params);
            }
        }
        if (!$vehicle) {
            throw new RuntimeException('Relay mode requires a known vehicle identity for queued payloads');
        }
        $vehicleReg = $payload['vehicle_reg_no'] ?? null;
        $imei = $payload['imei'] ?? null;
        if (($vehicleReg !== null && (string) $vehicleReg !== (string) ($vehicle['vehicle_reg_no'] ?? ''))
            || ($imei !== null && (string) $imei !== (string) ($vehicle['imei'] ?? ''))
        ) {
            throw new RuntimeException('Relay payload identity does not match the owning vehicle');
        }
        return self::queuePayload($vehicle, $payload);
    }

    /** Preserve manual submission as durable intent, never a direct network bypass. */
    public static function queuePayload(array $vehicle, array $payload): array {
        $settings = self::settings((int) $vehicle['id']);
        if (($payload['vehicle_reg_no'] ?? null) !== $vehicle['vehicle_reg_no']
            || ($payload['imei'] ?? null) !== $vehicle['imei'] || empty($payload['items'])) {
            throw new RuntimeException('Payload vehicle identity mismatch');
        }
        foreach ($payload['items'] as $item) {
            $one = $payload; $one['items'] = [$item];
            if (LatraMapper::validatePayload($one)) throw new RuntimeException('Invalid manual payload');
        }
        $timestamp = $payload['items'][0]['timestamp'] ?? null;
        if (!is_string($timestamp) || !ctype_digit($timestamp)) throw new RuntimeException('Source timestamp required');
        $time = intdiv((int) $timestamp, 1000);
        $raw = ['t' => $time, 'payload' => $payload];
        $manualStream = 'manual:wialon:' . $vehicle['wialon_unit_id'] . ':' . substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 24);
        $added = self::queue($settings)->collect($manualStream, $time, $time,
            1, 1, (int) $settings['max_bytes'], static fn(): array => ['count' => 1, 'messages' => [$raw]],
            static fn(array $message, array $state): array => ['payload' => $message['payload'], 'state' => $state]);
        return ['success' => true, 'queued' => $added, 'message' => 'Saved to the durable relay. Real LATRA delivery is blocked.'];
    }
}
