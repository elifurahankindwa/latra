<?php
/** Read-only evidence for the eight vehicle signals; never classifies or queues delivery. */
class VehicleVst {
    public static function summary(int $vehicleId): array {
        $config = Database::fetch("SELECT config_value FROM config WHERE config_key = 'battery_low_mv'");
        $batteryLowMv = is_numeric($config['config_value'] ?? null) ? (float) $config['config_value'] : 3200.0;
        $definitions = [
            ['braking', 'Harsh Braking', 'io_253', "driving = '2'"],
            ['turning', 'Harsh Turning', 'io_253', "driving = '3'"],
            ['acceleration', 'Harsh Acceleration', 'io_253', "driving = '1'"],
            ['battery_low', 'Internal Battery Low', 'io_67 / pwr_int', 'internal_v IS NOT NULL'],
            ['unplug', 'External Battery Disconnected', 'io_252', "unplug IN ('0', '1')"],
            ['idle', 'Excessive Idle', 'io_251', "idle IN ('0', '1')"],
            ['external', 'External Power Voltage', 'io_66 / pwr_ext', 'external_v IS NOT NULL'],
            ['internal', 'Device Internal Battery Voltage', 'io_67 / pwr_int', 'internal_v IS NOT NULL'],
        ];
        $numeric = static function (string $key): string {
            $value = "JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.p.$key'))";
            return "CASE WHEN $value REGEXP '^[0-9]+([.][0-9]+)?$' THEN CAST($value AS DECIMAL(18,6)) END";
        };
        // Aggregate the selected vehicle in SQL, then load at most eight source messages.
        // Latest means source timestamp, not insertion order or current message-table page.
        $columns = ['COUNT(*) AS total', 'MIN(wialon_msg_time) AS first_time', 'MAX(wialon_msg_time) AS last_time'];
        foreach ($definitions as [$key, $label, $source, $condition]) {
            $columns[] = "COALESCE(SUM(CASE WHEN $condition THEN 1 ELSE 0 END), 0) AS {$key}_count";
            $columns[] = "MAX(CASE WHEN $condition THEN wialon_msg_time END) AS {$key}_time";
        }
        $signals = "SELECT wialon_msg_time,
            JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.p.io_253')) AS driving,
            JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.p.io_252')) AS unplug,
            JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.p.io_251')) AS idle,
            COALESCE((" . $numeric('io_66') . ") / 1000, " . $numeric('pwr_ext') . ") AS external_v,
            COALESCE((" . $numeric('io_67') . ") / 1000, " . $numeric('pwr_int') . ") AS internal_v
            FROM message_log WHERE vehicle_id = ?";
        $aggregate = Database::fetch('SELECT ' . implode(', ', $columns) . " FROM ($signals) AS signals", [$vehicleId]);
        $times = [];
        foreach ($definitions as [$key]) {
            if ($aggregate[$key . '_time'] !== null) $times[] = (int) $aggregate[$key . '_time'];
        }
        $messages = [];
        if ($times) {
            $times = array_values(array_unique($times));
            $placeholders = implode(',', array_fill(0, count($times), '?'));
            foreach (Database::fetchAll("SELECT id, wialon_msg_time, raw_data FROM message_log
                WHERE vehicle_id = ? AND wialon_msg_time IN ($placeholders)", array_merge([$vehicleId], $times)) as $message) {
                $messages[(int) $message['wialon_msg_time']] = $message;
            }
        }
        $rows = [];
        foreach ($definitions as [$key, $label, $source]) {
            $time = $aggregate[$key . '_time'];
            $message = $time !== null ? ($messages[(int) $time] ?? null) : null;
            $raw = $message ? json_decode($message['raw_data'], true) : null;
            $params = $raw['p'] ?? [];
            $value = 'Not reported';
            $note = '';
            if ($message) {
                if (in_array($key, ['braking', 'turning', 'acceleration'], true)) {
                    $value = 'Reported';
                    $note = 'Matching source messages; not a current active state.';
                } elseif ($key === 'unplug') {
                    $value = (string) $params['io_252'] === '1' ? 'Unplug reported' : 'Power present reported';
                } elseif ($key === 'idle') {
                    $value = (string) $params['io_251'] === '1' ? 'Excessive idle reported' : 'Idle ended reported';
                } else {
                    $internal = $key !== 'external';
                    $io = $internal ? 'io_67' : 'io_66';
                    $alias = $internal ? 'pwr_int' : 'pwr_ext';
                    $hasIo = isset($params[$io]) && is_numeric($params[$io]) && (float) $params[$io] >= 0;
                    $volts = $hasIo ? (float) $params[$io] / 1000 : (float) $params[$alias];
                    $source = $hasIo ? $io . ' (mV → V)' : $alias . ' (V)';
                    if ($key === 'battery_low') {
                        $value = $volts > 0 ? ($volts * 1000 < $batteryLowMv ? 'Low — calculated' : 'Not low — calculated') : 'Unknown — zero voltage';
                        $note = number_format($volts, 3) . ' V; voltage rule: below ' . number_format($batteryLowMv / 1000, 3) . ' V. Not a device low-battery flag.';
                    } else {
                        $value = number_format($volts, 3) . ' V';
                        if ($key === 'external' && $volts == 0) $note = 'Zero voltage alone does not confirm an unplug event.';
                    }
                }
            }
            $rows[] = compact('key', 'label', 'source', 'value', 'note', 'time', 'message') + ['count' => (int) $aggregate[$key . '_count']];
        }
        return ['rows' => $rows, 'total' => (int) $aggregate['total'], 'first_time' => $aggregate['first_time'], 'last_time' => $aggregate['last_time']];
    }
}
