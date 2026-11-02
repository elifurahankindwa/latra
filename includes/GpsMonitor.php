<?php
/**
 * GPS monitor.
 */
class GpsMonitor {

    // LATRA max gap between GPS updates.
    const MAX_INTERVAL_SECONDS = 180; // 3 minutes

    // Offline threshold.
    const OFFLINE_SECONDS = 900; // 15 minutes

    // Refresh GPS health for active vehicles.
    public static function refresh(): array {
        $now = time();
        $counts = ['ok' => 0, 'stale' => 0, 'offline' => 0, 'unknown' => 0];

        // One grouped query instead of a MAX() round-trip per vehicle (was N+1
        // across the whole fleet). Uses idx_ml_vehicle_time for the per-vehicle
        // high-water mark.
        $rows = Database::fetchAll(
            "SELECT v.id AS vehicle_id, MAX(ml.wialon_msg_time) AS t
               FROM vehicles v
               LEFT JOIN message_log ml ON ml.vehicle_id = v.id
              WHERE v.is_active = 1
              GROUP BY v.id"
        );

        $batch = [];
        foreach ($rows as $veh) {
            $lastGps = $veh['t'] !== null ? (int) $veh['t'] : null;

            $status = 'unknown';
            $mins = null;
            if ($lastGps) {
                $mins = (int) floor(($now - $lastGps) / 60);
                if (($now - $lastGps) <= self::MAX_INTERVAL_SECONDS) {
                    $status = 'ok';
                } elseif (($now - $lastGps) <= self::OFFLINE_SECONDS) {
                    $status = 'stale';
                } else {
                    $status = 'offline';
                }
            }

            $counts[$status]++;
            $batch[] = [
                (int) $veh['vehicle_id'],
                $lastGps,
                $lastGps !== null ? date(DATE_FORMAT, $lastGps) : null,
                $now,
                $status,
                $mins,
            ];
        }

        self::upsertBatch($batch);
        return $counts;
    }

    /**
     * Upsert many gps_health rows in one (chunked) statement.
     *
     * One INSERT per vehicle meant one auto-commit per row, which dominated the
     * whole refresh on a large fleet; a multi-row upsert collapses that to a
     * single round-trip.
     */
    public static function upsertBatch(array $rows): void {
        if (!$rows) {
            return;
        }
        // 500 rows × 6 small columns stays well under max_allowed_packet.
        foreach (array_chunk($rows, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?)'));
            $params = [];
            foreach ($chunk as $r) {
                foreach ($r as $v) {
                    $params[] = $v;
                }
            }
            Database::query(
                "INSERT INTO gps_health
                    (vehicle_id, last_gps_time, last_gps_at, last_comm_time, status, minutes_since)
                 VALUES $placeholders
                 ON DUPLICATE KEY UPDATE
                    last_gps_time = VALUES(last_gps_time),
                    last_gps_at   = VALUES(last_gps_at),
                    last_comm_time= VALUES(last_comm_time),
                    status        = VALUES(status),
                    minutes_since = VALUES(minutes_since)",
                $params
            );
        }
    }

    // Save GPS health for one vehicle (single atomic upsert).
    public static function upsert(int $vehicleId, ?int $lastGps, int $commTime, string $status, ?int $mins): void {
        Database::query(
            "INSERT INTO gps_health
                (vehicle_id, last_gps_time, last_gps_at, last_comm_time, status, minutes_since)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                last_gps_time = VALUES(last_gps_time),
                last_gps_at   = VALUES(last_gps_at),
                last_comm_time= VALUES(last_comm_time),
                status        = VALUES(status),
                minutes_since = VALUES(minutes_since)",
            [
                $vehicleId,
                $lastGps,
                $lastGps !== null ? date(DATE_FORMAT, $lastGps) : null,
                $commTime,
                $status,
                $mins,
            ]
        );
    }

    // Count vehicles with fresh GPS.
    public static function freshCount(): int {
        $row = Database::fetch("SELECT COUNT(*) AS c FROM gps_health WHERE status = 'ok'");
        return (int)($row['c'] ?? 0);
    }

    // Summary for the health page.
    public static function summary(): array {
        $rows = Database::fetchAll("SELECT status, COUNT(*) AS c FROM gps_health GROUP BY status");
        $out = ['ok' => 0, 'stale' => 0, 'offline' => 0, 'unknown' => 0];
        foreach ($rows as $r) {
            if (isset($out[$r['status']])) $out[$r['status']] = (int)$r['c'];
        }
        return $out;
    }

    // List stale or offline vehicles.
    public static function staleVehicles(): array {
        return Database::fetchAll(
            "SELECT g.vehicle_id, g.status, g.minutes_since, g.last_gps_time, v.vehicle_reg_no, v.imei
             FROM gps_health g JOIN vehicles v ON v.id = g.vehicle_id
             WHERE g.status IN ('stale','offline')
             ORDER BY g.minutes_since DESC"
        );
    }
}
