<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

final class TripStatisticsPolicy
{
    public static function calculate(int $tripEndTime, int $tripStartTime, array $messages): array
    {
        $distance = 0.0;
        $speeds = [];
        $maxSpeed = 0.0;
        $previous = null;

        foreach ($messages as $message) {
            if (($message['speed'] ?? null) !== null) {
                $speed = (float) $message['speed'];
                $speeds[] = $speed;
                $maxSpeed = max($maxSpeed, $speed);
            }
            if ($previous !== null
                && $message['lat'] !== null && $message['lng'] !== null
                && $previous['lat'] !== null && $previous['lng'] !== null) {
                $distance += LatraPayloadPolicy::haversineKm(
                    (float) $previous['lat'],
                    (float) $previous['lng'],
                    (float) $message['lat'],
                    (float) $message['lng'],
                );
            }
            $previous = $message;
        }

        return [
            'distance' => round($distance, 2),
            'duration' => round(max(0, $tripEndTime - $tripStartTime) / 60.0, 2),
            'avgSpeed' => $speeds ? round(array_sum($speeds) / count($speeds), 2) : 0,
            'maxSpeed' => $maxSpeed,
        ];
    }
}
