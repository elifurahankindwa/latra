<?php
/** Local receiver model: intentionally supports idempotency. This is not a LATRA contract. */
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit; }
header('Content-Type: application/json');
$db = new PDO('sqlite:' . getenv('RELAY_RECEIVER_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE IF NOT EXISTS accepted (delivery_key TEXT PRIMARY KEY, payload TEXT, requests INTEGER)');
$mode = $_GET['mode'] ?? 'ok';
if (in_array($mode, ['503', '429', '400'], true)) {
    http_response_code((int) $mode);
    echo '{"status":"0"}';
    exit;
}
$key = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';
$body = file_get_contents('php://input');
if ($key === '' || !is_array(json_decode($body, true)['items'] ?? null)) {
    http_response_code(400); echo '{"status":"0"}'; exit;
}
foreach (json_decode($body, true)['items'] as $item) {
    if (!preg_match('/^[a-f0-9]{64}-a' . (int) ($item['activity_id'] ?? 0) . '$/D', $item['idempotency_key'] ?? '')) {
        http_response_code(400); echo '{"status":"0"}'; exit;
    }
}
// A receiver ignoring client keys demonstrates the limit of client-side replay protection.
if (in_array($mode, ['lost-no-dedup', 'no-dedup'], true)) $key .= '-' . bin2hex(random_bytes(6));
$stmt = $db->prepare('SELECT payload FROM accepted WHERE delivery_key=?');
$stmt->execute([$key]);
$old = $stmt->fetchColumn();
if ($old !== false && $old !== $body) { http_response_code(409); echo '{"status":"0"}'; exit; }
$stmt = $db->prepare('INSERT INTO accepted VALUES (?, ?, 1)
    ON CONFLICT(delivery_key) DO UPDATE SET requests=requests+1');
$stmt->execute([$key, $body]);
// Commit acceptance before simulating a missing or delayed acknowledgement.
if (in_array($mode, ['lost', 'lost-no-dedup'], true)) { echo '{incomplete'; exit; }
if ($mode === 'slow') sleep(2);
echo json_encode(['status' => '1', 'duplicate' => $old !== false]);
