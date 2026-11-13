<?php
/** Stage 3 collection/enrollment command; no delivery transport is invoked here. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['vehicle:', 'enroll', 'profile:', 'from:', 'to:', 'max-records:', 'max-bytes:',
    'max-pending-records:', 'max-pending-bytes:', 'status', 'recover-review', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/relay.php --vehicle=ID [--from=EPOCH --to=EPOCH | --status | --recover-review]\n";
    echo "Enroll: --vehicle=ID --enroll --profile=generic|teltonika-fmb\n";
    echo "  --max-records=N --max-bytes=N --max-pending-records=N --max-pending-bytes=N\n";
    echo "Apply migrations/009_durable_relay.sql first. Collection uses enrolled budgets and existing activity rules.\n";
    echo "Apply migrations/010_relay_review_recovery.sql before --recover-review. Recovery never sends to LATRA.\n";
    echo "Enrollment does not enable activities or alter schedules. This command never sends to LATRA.\n";
    exit;
}
function relayArgument(array $options, string $key, int $minimum = 1): int {
    $value = $options[$key] ?? null;
    if (!is_string($value) || !ctype_digit($value) || strlen($value) > 18 || (int) $value < $minimum) {
        throw new InvalidArgumentException('Explicit integer required: --' . $key);
    }
    return (int) $value;
}
try {
    $vehicleId = relayArgument($options, 'vehicle');
    if (isset($options['from']) !== isset($options['to'])) throw new InvalidArgumentException('Provide both --from and --to');
    require_once __DIR__ . '/../config.php';
    if (!RelayRuntime::enabled()) throw new RuntimeException('Relay architecture is not active');
    $vehicle = Database::fetch('SELECT * FROM vehicles WHERE id=?', [$vehicleId]);
    if (!$vehicle || empty($vehicle['is_active']) || empty($vehicle['wialon_unit_id'])) throw new RuntimeException('Active Wialon vehicle required');
    $actions = (int) isset($options['enroll']) + (int) isset($options['status']) + (int) isset($options['recover-review']);
    if ($actions > 1 || ($actions > 0 && (isset($options['from']) || isset($options['to'])))) {
        throw new InvalidArgumentException('Choose one relay action');
    }
    if (isset($options['enroll'])) {
        $budgets = [];
        foreach (['max-records','max-bytes','max-pending-records','max-pending-bytes'] as $key) {
            $budgets[str_replace('-', '_', $key)] = relayArgument($options, $key);
        }
        RelayRuntime::enroll($vehicle, $options['profile'] ?? '', $budgets);
        echo json_encode(['enrolled' => true] + RelayRuntime::status($vehicle), JSON_THROW_ON_ERROR) . "\n";
    } elseif (isset($options['status'])) {
        echo json_encode(RelayRuntime::status($vehicle), JSON_THROW_ON_ERROR) . "\n";
    } elseif (isset($options['recover-review'])) {
        echo json_encode(RelayRuntime::recoverReviews($vehicle) + RelayRuntime::status($vehicle), JSON_THROW_ON_ERROR) . "\n";
    } else {
        $from = isset($options['from']) ? relayArgument($options, 'from', 0) : null;
        $to = isset($options['to']) ? relayArgument($options, 'to', 0) : null;
        $settings = RelayRuntime::settings($vehicleId);
        $allowed = array_map('intval', array_column(Database::fetchAll('SELECT DISTINCT b.activity_id FROM automation_buckets b
            JOIN bucket_vehicles bv ON bv.bucket_id=b.id WHERE b.is_enabled=1 AND bv.vehicle_id=?', [$vehicleId]), 'activity_id'));
        $result = RelayRuntime::collect($vehicle, Schema::configInt('poll_interval_seconds', 180), (int) $settings['max_records'],
            ':poll', $allowed, null, $from, $to);
        echo json_encode($result + RelayRuntime::status($vehicle), JSON_THROW_ON_ERROR) . "\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Relay command failed (" . get_class($error) . "). Verify arguments, migration, enrollment and private runtime configuration.\n");
    exit(1);
}
