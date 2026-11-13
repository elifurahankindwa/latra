<?php
/**
 * Compatibility adapter for Wialon message normalization.
 */
use Latra\Domain\Ingestion\WialonMessageNormalizationPolicy;

require_once __DIR__ . '/autoload.php';

class Normaliser {
    public static function fromWialon(array $vehicle, array $msg): array {
        $params = ParamResolver::resolve($msg['p'] ?? [], (string) ($vehicle['hw_type'] ?? ''));
        return WialonMessageNormalizationPolicy::normalize($vehicle, $msg, $params);
    }

    public static function toMv($value): ?float {
        return WialonMessageNormalizationPolicy::toMv($value);
    }

    public static function toPct($value): ?float {
        return WialonMessageNormalizationPolicy::toPct($value);
    }
}
