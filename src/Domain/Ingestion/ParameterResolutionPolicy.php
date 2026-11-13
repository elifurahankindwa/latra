<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

final class ParameterResolutionPolicy
{
    public static function resolve(array $parameters, ?string $deviceType, array $aliases): array
    {
        $resolved = $parameters;
        foreach ($aliases as $alias) {
            if (empty($alias['is_enabled'])) {
                continue;
            }
            $type = $alias['device_type'];
            if ($type !== '*' && $deviceType && strcasecmp($type, $deviceType) !== 0) {
                continue;
            }
            $source = $alias['param_key'];
            $canonical = $alias['canonical_key'];
            if (array_key_exists($source, $parameters) && !array_key_exists($canonical, $resolved)) {
                $resolved[$canonical] = $parameters[$source];
            }
        }
        return $resolved;
    }

    public static function pick(array $canonical, string $key, array $fallbacks = []): mixed
    {
        if (array_key_exists($key, $canonical) && $canonical[$key] !== '' && $canonical[$key] !== null) {
            return $canonical[$key];
        }
        foreach ($fallbacks as $fallback) {
            if (array_key_exists($fallback, $canonical)
                && $canonical[$fallback] !== ''
                && $canonical[$fallback] !== null) {
                return $canonical[$fallback];
            }
        }
        return null;
    }
}
