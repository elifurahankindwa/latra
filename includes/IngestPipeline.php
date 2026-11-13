<?php
/**
 * Compatibility/composition entry point for legacy batch ingestion.
 */
require_once __DIR__ . '/autoload.php';

class IngestPipeline {
    public static function ingestVehicle(array $vehicle, array $messages): array {
        if (defined('DELIVERY_ARCHITECTURE') && DELIVERY_ARCHITECTURE === 'relay') {
            throw new RuntimeException('Use complete-window relay collection instead of legacy batch ingestion');
        }
        return self::ingest($vehicle, $messages, true);
    }

    /** Portal imports validate and persist without creating automatic delivery intent. */
    public static function importVehicle(array $vehicle, array $messages): array {
        if (defined('DELIVERY_ARCHITECTURE') && DELIVERY_ARCHITECTURE === 'relay') {
            throw new RuntimeException('Use complete-window relay collection instead of legacy batch ingestion');
        }
        return self::ingest($vehicle, $messages, false);
    }

    private static function ingest(array $vehicle, array $messages, bool $queueEligible): array {
        return (new \Latra\Application\Ingestion\IngestVehicleMessages(
            new \Latra\Infrastructure\Persistence\LegacyIngestionRepository(),
            new \Latra\Infrastructure\Ingestion\LegacyIngestionEffects(),
        ))->ingest($vehicle, $messages, $queueEligible);
    }

    public static function speedLimit(array $vehicle): float {
        if (!empty($vehicle['speed_limit_kmh'])) {
            return (float) $vehicle['speed_limit_kmh'];
        }
        return EventDetector::speedLimit((int) $vehicle['id']);
    }
}
