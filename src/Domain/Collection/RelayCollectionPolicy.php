<?php
declare(strict_types=1);

namespace Latra\Domain\Collection;

use InvalidArgumentException;
use RuntimeException;

final class RelayCollectionPolicy
{
    public static function assertWindow(string $unit, int $from, int $to, int $pageSize, int $maxRecords, int $maxBytes): void
    {
        if ($unit === '' || strlen($unit) > 64 || $from < 0 || $to < $from
            || $pageSize < 1 || $maxRecords < 1 || $maxBytes < 1) {
            throw new InvalidArgumentException('Explicit bounded window required');
        }
    }

    public static function vehicleUnit(string $unit): string
    {
        return preg_match('/^(?:manual:)?(wialon:[0-9]+)(?::.*)?$/D', $unit, $matched) ? $matched[1] : $unit;
    }

    public static function assertContiguous(array $state, int $from): void
    {
        if ($state['collected_through'] !== null && $from > (int) $state['collected_through'] + 1) {
            throw new RuntimeException('Collection gap requires explicit backfill');
        }
    }

    public static function assertNotLate(array $state, int $time): void
    {
        if ($state['attempted_time'] !== null && $time < (int) $state['attempted_time']) {
            throw new RuntimeException('Late report requires review');
        }
        if ($state['collected_through'] !== null && $time <= (int) $state['collected_through']) {
            throw new RuntimeException('New report behind collection checkpoint requires review');
        }
    }

    /** Preserve item order, suppress known activities and assign immutable local item identities. */
    public static function preparePayload(array $payload, string $sourceKey, int $identity, array $knownActivities): array
    {
        if (!is_array($payload['items'] ?? null) || !$payload['items'] || $identity > intdiv(PHP_INT_MAX - 31, 32)) {
            throw new RuntimeException('Invalid mapped payload');
        }
        $activities = [];
        $newItems = [];
        $newActivities = [];
        foreach ($payload['items'] as $item) {
            $activity = (int) ($item['activity_id'] ?? 0);
            if ($activity < 1 || $activity > 24 || isset($activities[$activity])) {
                throw new RuntimeException('Invalid or repeated activity');
            }
            $activities[$activity] = true;
            if (in_array($activity, $knownActivities, true)) continue;
            $item['idempotency_key'] = $sourceKey . '-a' . $activity;
            $item['MGS_ID'] = (string) ($identity * 32 + $activity);
            $newItems[] = $item;
            $newActivities[] = $activity;
        }
        $payload['items'] = $newItems;
        return [$payload, $newActivities];
    }

    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as &$item) $item = self::canonical($item);
        return $value;
    }
}
