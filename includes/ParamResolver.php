<?php
/**
 * Compatibility adapter for configured Wialon parameter aliases.
 */
use Latra\Domain\Ingestion\ParameterResolutionPolicy;
use Latra\Infrastructure\Persistence\PdoParameterAliasRepository;

require_once __DIR__ . '/autoload.php';

class ParamResolver
{
    private static ?array $map = null;

    public static function resolve(array $params, ?string $deviceType = null): array
    {
        return ParameterResolutionPolicy::resolve($params, $deviceType, self::enabledAliases());
    }

    public static function pick(array $canonical, string $key, array $fallbacks = []): mixed
    {
        return ParameterResolutionPolicy::pick($canonical, $key, $fallbacks);
    }

    public static function enabledAliases(): array
    {
        if (self::$map === null) {
            try {
                self::$map = (new PdoParameterAliasRepository(Database::getInstance()))->enabledAliases();
            } catch (Throwable $error) {
                self::$map = [];
            }
        }
        return self::$map;
    }
}
