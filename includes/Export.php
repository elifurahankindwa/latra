<?php
/**
 * Stream a CSV download. Set headers, encode, and exit.
 */
class Export
{
    /** Read a stable, bounded page at a time instead of materializing the whole receipt history. */
    public static function pushLogRows(string $status = ''): iterable
    {
        $conditions = ['sent' => "pl.response_status = '1'", 'pending' => 'pl.response_status IS NULL',
            'failed' => "pl.response_status IS NOT NULL AND pl.response_status <> '1'"];
        $condition = $conditions[$status] ?? '1=1';
        $ceiling = (int) (Database::fetch('SELECT MAX(id) AS id FROM push_log')['id'] ?? 0);
        $cursorTime = null;
        $cursorId = 0;
        $hasCursor = false;
        do {
            $params = [$ceiling];
            $cursorSql = '';
            if ($hasCursor) {
                // NULL timestamps sort last; advance their IDs instead of repeating that final page.
                if ($cursorTime === null) {
                    $cursorSql = ' AND pl.created_at IS NULL AND pl.id < ?';
                    $params[] = $cursorId;
                } else {
                    $cursorSql = ' AND (pl.created_at IS NULL OR pl.created_at < ? OR (pl.created_at = ? AND pl.id < ?))';
                    $params = array_merge($params, [$cursorTime, $cursorTime, $cursorId]);
                }
            }
            $rows = Database::fetchAll("SELECT pl.id, v.vehicle_reg_no, ad.activity_name, pl.activity_id,
                    pl.http_code, pl.response_status, pl.latency_ms, pl.created_at, pl.response_body
                FROM push_log pl LEFT JOIN vehicles v ON pl.vehicle_id = v.id
                LEFT JOIN activity_definitions ad ON pl.activity_id = ad.id
                WHERE pl.id <= ? AND ($condition) $cursorSql
                ORDER BY pl.created_at DESC, pl.id DESC LIMIT 500", $params);
            foreach ($rows as $row) {
                yield [$row['id'], $row['vehicle_reg_no'] ?? '', $row['activity_name'] ?? $row['activity_id'] ?? '',
                    $row['http_code'] ?? '', $row['response_status'] === '1' ? 'Sent' : ($row['response_status'] ?? 'Pending'),
                    $row['latency_ms'] ?? '', $row['created_at'], mb_substr((string) ($row['response_body'] ?? ''), 0, 500)];
            }
            if ($rows) {
                $last = end($rows);
                $hasCursor = true;
                $cursorTime = $last['created_at'];
                $cursorId = (int) $last['id'];
            }
        } while (count($rows) === 500);
    }

    public static function stream(string $filename, array $headers, iterable $rows): void
    {
        $filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // BOM for Excel UTF-8 detection
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}