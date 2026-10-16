<?php
/** Loopback-only test destination; no requests are forwarded to a real integration. */
$code = (int) ($_GET['response'] ?? 200);
http_response_code($code);
header('Content-Type: application/json');
echo json_encode(['status' => $code === 200 ? '1' : '0',
    'idempotency_key' => $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '',
    'received' => json_decode(file_get_contents('php://input'), true)]);
