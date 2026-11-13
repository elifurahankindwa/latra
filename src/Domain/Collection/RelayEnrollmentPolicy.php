<?php
declare(strict_types=1);

namespace Latra\Domain\Collection;

use InvalidArgumentException;
use RuntimeException;

final class RelayEnrollmentPolicy
{
    public const PROFILES = ['generic', 'teltonika-fmb'];

    public static function validate(string $profile, array $budgets): array
    {
        if (!in_array($profile, self::PROFILES, true)) {
            throw new InvalidArgumentException('Unsupported relay profile');
        }

        foreach (['max_records', 'max_bytes', 'max_pending_records', 'max_pending_bytes'] as $key) {
            if (!is_int($budgets[$key] ?? null) || $budgets[$key] < 1 || $budgets[$key] > 2147483647) {
                throw new InvalidArgumentException('Explicit positive relay budgets required');
            }
        }

        return $budgets;
    }

    public static function assertProfileChangeAllowed(bool $hasCollectedProgress): void
    {
        if ($hasCollectedProgress) {
            throw new RuntimeException(
                'Changing a collected mapping profile requires an explicit state migration',
            );
        }
    }
}
