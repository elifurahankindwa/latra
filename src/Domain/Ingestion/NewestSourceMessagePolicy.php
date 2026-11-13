<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

/**
 * Selects the newest source message from a Wialon batch.
 *
 * Wialon does not guarantee array order, so callers must not trust element zero. Equal source
 * times keep the later element, which matches the previous coordinator behavior.
 */
final class NewestSourceMessagePolicy
{
    public static function select(array $messages): ?array
    {
        $newest = null;
        $newestTime = null;

        foreach ($messages as $message) {
            $time = (int) ($message['t'] ?? 0);
            if ($newestTime === null || $time >= $newestTime) {
                $newest = $message;
                $newestTime = $time;
            }
        }

        return $newest;
    }
}
