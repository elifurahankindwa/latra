<?php
require_once 'config.php'; require_once 'includes/Database.php'; require_once 'includes/WialonAPI.php';
require_once 'includes/LatraMapper.php'; require_once 'includes/Auth.php'; require_once 'includes/Automation.php';
require_once 'includes/GpsValidator.php'; require_once 'includes/EventDetector.php'; require_once 'includes/EmergencyHandler.php'; require_once 'includes/DeviceMonitor.php'; require_once 'includes/DataCleaner.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Mutating actions must be POST + carry a CSRF token. Read-only actions stay GET.
$writeActions = [
    'sync_wialon', 'sync_single_vehicle', 'fetch_messages', 'bulk_fetch_messages',
    'toggle_activity', 'toggle_vehicle', 'push_single', 'bulk_push_activity',
    'post_to_latra', 'push_custom_payload', 'delete_push', 'retry_push',
    'clear_data', 'latra_interrogate',
];
$sseActions = ['bulk_fetch_messages', 'bulk_push_activity'];
$pushActions = ['push_single', 'bulk_push_activity', 'post_to_latra', 'push_custom_payload', 'retry_push'];

// Authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Basic per-client rate limit (all api traffic).
if (!Auth::rateLimit('api:' . Auth::clientKey(), 300, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Try again shortly.']);
    exit;
}

// SSE actions can only be GET (EventSource) — enforce CSRF via query token instead.
if (in_array($action, $sseActions, true)) {
    if (!Auth::verifyCsrf($_GET['_csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// Write actions: require POST + CSRF, and cap downstream push volume per client/day.
if (in_array($action, $writeActions, true)) {
    // SSE actions are GET EventSource streams — CSRF is already enforced via the
    // query string above, so skip the POST + form-CSRF requirements for them.
    if (!in_array($action, $sseActions, true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'POST required for this action']);
            exit;
        }
        Auth::requireCsrf();
    }
    // LATRA roles may only run the read-backed interrogation action below.
    // Everything else stays restricted to internal admin/operator roles.
    if ($action !== 'latra_interrogate') {
        Auth::requireWrite();
    }
    if (in_array($action, $pushActions, true) && !Auth::rateLimit('push:' . Auth::clientKey(), 500, 86400)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Daily push limit reached.']);
        exit;
    }
}

function extractWialonParams(array $message): array {
    $raw = json_decode($message['raw_data'] ?? '{}', true);
    return is_array($raw) && isset($raw['p']) && is_array($raw['p']) ? $raw['p'] : [];
}

function startSse(): void {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-store');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) ob_end_flush();
    echo ": connected\n\n";
    flush();
}

function sendSse(array $data): void {
    echo 'data: ' . json_encode($data) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

function queueRelayPayload(array $vehicle, array $payload): ?array {
    if (!RelayRuntime::enabled()) {
        return null;
    }
    try {
        $result = RelayRuntime::queueForVehicle($payload, (int) $vehicle['id']);
        return [
            'success' => (bool) ($result['success'] ?? false),
            'queued' => (int) ($result['queued'] ?? 0),
            'message' => $result['message'] ?? 'Saved to the durable relay. Real LATRA delivery is blocked.',
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

try {
    switch ($action) {

        case 'sync_wialon':
            $wialon = new WialonAPI();
            if (!$wialon->login()) {
                throw new Exception('Wialon login failed. Check token in Settings.');
            }

            $units = $wialon->getUnits();
            $synced = 0;
            $updated = 0;

            foreach ($units as $unit) {
                $mapped = LatraMapper::mapVehicle($unit);
                $exists = Database::fetch("SELECT id FROM vehicles WHERE wialon_unit_id = ?", [$mapped['wialon_unit_id']]);

                if ($exists) {
                    Database::update('vehicles', array_merge($mapped, [
                        'last_sync_at' => date(DATE_FORMAT),
                        'is_active' => 1
                    ]), 'id = ?', [$exists['id']]);
                    $updated++;
                } else {
                    Database::insert('vehicles', array_merge($mapped, [
                        'created_at' => date(DATE_FORMAT),
                        'last_sync_at' => date(DATE_FORMAT)
                    ]));
                    $synced++;
                }

                $vid = $exists ? $exists['id'] : Database::getInstance()->lastInsertId();
                Database::query(
                    "INSERT IGNORE INTO activity_mappings (vehicle_id, activity_id, is_enabled)
                     SELECT ?, id, 0 FROM activity_definitions",
                    [$vid]
                );
            }

            $wialon->logout();
            echo json_encode(['success' => true, 'message' => "Synced: $synced new, $updated updated"]);
            break;

        case 'sync_single_vehicle':
            $vid = intval($_REQUEST['id'] ?? 0);
            $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vid]);
            if (!$vehicle) throw new Exception('Vehicle not found');

            $wialon = new WialonAPI();
            if (!$wialon->login()) throw new Exception('Wialon login failed');

            // Use 0xFFFFFFFF to get all data (or 13645191 for what we need)
            $unit = $wialon->getUnitData((int)$vehicle['wialon_unit_id']);
            if ($unit) {
                $mapped = LatraMapper::mapVehicle($unit);
                Database::update('vehicles', array_merge($mapped, [
                    'last_sync_at' => date(DATE_FORMAT),
                    'is_active' => 1
                ]), 'id = ?', [$vid]);
            }
            $wialon->logout();
            echo json_encode(['success' => true, 'message' => "Vehicle data updated from Wialon"]);
            break;

        case 'fetch_messages':
            $vehicleId = intval($_REQUEST['vehicle_id'] ?? 0);
            if (!$vehicleId) throw new Exception('Vehicle ID required');

            $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vehicleId]);
            if (!$vehicle) throw new Exception('Vehicle not found');

            if (RelayRuntime::enabled()) {
                $collected = RelayRuntime::poll($vehicle, 86400, 100);
                echo json_encode(['success' => true, 'message' => $collected['fetched'] . ' source reports collected; '
                    . $collected['rejected'] . ' newly held for review.'] + $collected);
                break;
            }

            $wialon = new WialonAPI();
            if (!$wialon->login()) throw new Exception('Wialon login failed');

            $to = time();
            $from = $to - 86400;
            $messages = $wialon->getMessages((int)$vehicle['wialon_unit_id'], $from, $to, 100);
            $wialon->logout();

            $inserted = 0;
            $skippedInvalid = 0;
            $prevState = Database::fetch("SELECT * FROM vehicle_state WHERE vehicle_id = ?", [$vehicleId]) ?? [];
            $enabledActs = EventDetector::enabledActivities($vehicleId);
            $speedLimit = EventDetector::speedLimit($vehicleId);

            foreach ($messages as $msg) {
                $pos = $msg['pos'] ?? [];
                $params = $msg['p'] ?? [];

                $speed = isset($pos['s']) ? round((float)$pos['s'], 2) : null;
                $detected = EventDetector::detect($params, $prevState, [
                    'speed' => $speed ?? 0,
                    'activity_enabled' => $enabledActs,
                    'speed_limit' => $speedLimit,
                    'engine_param' => $vehicle['engine_param'] ?? null,
                ]);
                $activityId = $detected['activity_id'];

                // Rolling engine baseline across the batch (see IngestPipeline).
                $ignited = EventDetector::engineState($params, $vehicle['engine_param'] ?? null);
                if ($ignited !== null) {
                    $prevState['engine_status'] = $ignited ? 'on' : 'off';
                }
                $addonInfo = json_encode(LatraMapper::buildAddonInfo($activityId, $params) ?? []);
                $fuelInfo = $activityId === 16 ? json_encode(LatraMapper::buildFuelInfo($params)) : null;

                // Start a high-frequency event for emergencies.
                $emergencyType = EmergencyHandler::mapEmergencyType($activityId);
                if ($emergencyType) {
                    $activeEvt = EmergencyHandler::ensureActiveEvent($vehicleId, $emergencyType, $detected['reason'], $speed, $activityId);
                    if ($activeEvt) {
                        EmergencyHandler::bufferRecord($activeEvt, $vehicleId, $msg);
                    }
                } else {
                    // Close any ended emergency event.
                }

                // Update device health.
                DeviceMonitor::updateFromMessage($vehicleId, $params, $msg['rt'] ?? time());

                $msgData = [
                    'vehicle_id' => $vehicleId,
                    'wialon_msg_time' => $msg['t'] ?? time(),
                    'raw_data' => json_encode($msg),
                    'lat' => isset($pos['y']) ? round((float)$pos['y'], 8) : null,
                    'lng' => isset($pos['x']) ? round((float)$pos['x'], 8) : null,
                    'altitude' => $pos['z'] ?? null,
                    'speed' => $speed,
                    'bearing' => $pos['c'] ?? null,
                    'satellite_count' => $pos['sc'] ?? null,
                    'hdop' => $params['hdop'] ?? null,
                    'd2d3' => isset($pos['z']) && $pos['z'] !== 0 ? 3 : 2,
                    'rssi' => $params['rssi'] ?? $params['gsm'] ?? null,
                    'lac' => $params['lac'] ?? $params['gsm_lac'] ?? $params['lbs_lac'] ?? null,
                    'cell_id' => $params['cell_id'] ?? $params['cellid'] ?? $params['cid'] ?? $params['gsm_cellid'] ?? $params['gsm_cell_id'] ?? $params['lbs_cell_id'] ?? null,
                    'mcc' => $params['mcc'] ?? null,
                    'mgs_id' => $msg['t'] ?? null,
                    'activity_id' => $activityId,
                    'addon_info' => $addonInfo,
                    'fuel_info' => $fuelInfo,
                    'latra_status' => 'pending',
                    'data_received_at' => $msg['rt'] ?? time(),
                ];

                // Check the row against LATRA rules.
                $validation = GpsValidator::validate($msgData + ['imei' => $vehicle['imei'], 'vehicle_reg_no' => $vehicle['vehicle_reg_no']]);
                $msgData['validation_status'] = GpsValidator::statusOf($validation);
                if (!$validation['valid']) {
                    GpsValidator::recordErrors($msgData, $validation['errors']);
                    $msgData['latra_status'] = 'rejected';
                    $skippedInvalid++;
                }

                if (Automation::storeMessage($msgData)) $inserted++;
            }

            // Close any ended emergency event.
            EmergencyHandler::closeEndedEvent($vehicleId, $messages);

            // Save the newest evaluated message. Wialon array order is not a source-order
            // guarantee, so select by source time instead of trusting the first element.
            $lastMsg = \Latra\Domain\Ingestion\NewestSourceMessagePolicy::select($messages);
            if ($lastMsg) {
                $lastPos = $lastMsg['pos'] ?? [];
                $lastParams = $lastMsg['p'] ?? [];
                $lastSpeed = isset($lastPos['s']) ? round((float)$lastPos['s'], 2) : 0;
                $lastDetected = EventDetector::detect($lastParams, $prevState, [
                    'speed' => $lastSpeed, 'activity_enabled' => $enabledActs, 'speed_limit' => $speedLimit,
                    'engine_param' => $vehicle['engine_param'] ?? null,
                ]);
                EventDetector::persistState($vehicleId, [
                    'engine_status' => EventDetector::engineState($lastParams, $vehicle['engine_param'] ?? null) === true ? 'on' : 'off',
                    'last_speed_kmh' => $lastSpeed,
                    'last_lat' => isset($lastPos['y']) ? round((float)$lastPos['y'], 8) : null,
                    'last_lng' => isset($lastPos['x']) ? round((float)$lastPos['x'], 8) : null,
                    'last_event_activity_id' => $lastDetected['activity_id'],
                    'last_event_at' => date(DATE_FORMAT),
                ]);
            }

            echo json_encode(['success' => true, 'message' => "$inserted messages fetched and stored ($skippedInvalid invalid/rejected)"]);
            break;

        case 'toggle_activity':
            $vid = intval($_REQUEST['vehicle_id'] ?? 0);
            $aid = intval($_REQUEST['activity_id'] ?? 0);
            $enabled = intval($_REQUEST['enabled'] ?? 0);

            $exists = Database::fetch("SELECT id FROM activity_mappings WHERE vehicle_id = ? AND activity_id = ?", [$vid, $aid]);
            if ($exists) {
                Database::update('activity_mappings', ['is_enabled' => $enabled], 'id = ?', [$exists['id']]);
            } else {
                Database::insert('activity_mappings', [
                    'vehicle_id' => $vid,
                    'activity_id' => $aid,
                    'is_enabled' => $enabled
                ]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'toggle_vehicle':
            $id = intval($_REQUEST['id'] ?? 0);
            $status = intval($_REQUEST['status'] ?? 0);
            Database::update('vehicles', ['is_active' => $status], 'id = ?', [$id]);
            echo json_encode(['success' => true]);
            break;

        case 'push_single':
            $msgId = intval($_REQUEST['message_id'] ?? 0);
            $msg = Database::fetch("SELECT ml.*, v.vehicle_reg_no, v.imei, v.name, ml.id as msg_id FROM message_log ml JOIN vehicles v ON ml.vehicle_id = v.id WHERE ml.id = ?", [$msgId]);
            if (!$msg) throw new Exception('Message not found');
            $payload = LatraMapper::buildPayload($msg, $msg, $msg['activity_id'], 
                json_decode($msg['addon_info'] ?? '[]', true),
                json_decode($msg['fuel_info'] ?? '[]', true)
            );

            if (RelayRuntime::enabled()) {
                $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [(int) $msg['vehicle_id']]);
                if (!$vehicle) throw new Exception('Vehicle not found');
                $result = queueRelayPayload($vehicle, $payload);
                echo json_encode($result ?: ['success' => true, 'message' => 'Saved to the durable relay. Real LATRA delivery is blocked.']);
                break;
            }

            Database::update('message_log', ['latra_payload' => json_encode($payload)], 'id = ?', [$msgId]);

            $ch = curl_init(LATRA_API_URL);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode(LATRA_USERNAME . ':' . LATRA_PASSWORD)
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $start = microtime(true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $latency = round((microtime(true) - $start) * 1000);
            curl_close($ch);

            $respData = json_decode($response, true);
            $status = ($httpCode === 200 && isset($respData['status']) && $respData['status'] === '1') ? 'sent' : 'failed';

            Database::update('message_log', [
                'latra_status' => $status,
                'latra_response' => $response,
                'pushed_at' => date(DATE_FORMAT)
            ], 'id = ?', [$msgId]);

            Database::insert('push_log', [
                'vehicle_id' => $msg['vehicle_id'],
                'activity_id' => $msg['activity_id'],
                'message_log_id' => $msgId,
                'payload' => json_encode($payload),
                'response_status' => $respData['status'] ?? null,
                'response_body' => $response,
                'http_code' => $httpCode,
                'latency_ms' => $latency
            ]);

            echo json_encode(['success' => $status === 'sent', 'message' => $status === 'sent' ? 'Pushed successfully' : 'Push failed: ' . $response]);
            break;

        case 'get_response':
            $logId = intval($_GET['log_id'] ?? 0);
            $log = Database::fetch("SELECT * FROM push_log WHERE id = ?", [$logId]);
            if (!$log) throw new Exception('Log not found');
            $decoded = json_decode($log['response_body'] ?? '{}', true);
            echo json_encode($decoded ?: ['response' => $log['response_body']]);
            break;

        case 'get_payload':
            if (isset($_GET['log_id'])) {
                $logId = intval($_GET['log_id']);
                $log = Database::fetch("SELECT * FROM push_log WHERE id = ?", [$logId]);
                if (!$log) throw new Exception('Log not found');
                $decoded = json_decode($log['payload'] ?? '{}', true);
                echo json_encode($decoded ?: ['payload' => $log['payload']]);
                break;
            }

            $msgId = intval($_GET['message_id'] ?? 0);
            $msg = Database::fetch("SELECT ml.*, v.vehicle_reg_no, v.imei, v.name FROM message_log ml JOIN vehicles v ON ml.vehicle_id = v.id WHERE ml.id = ?", [$msgId]);
            if (!$msg) throw new Exception('Message not found');

            $payload = json_decode($msg['latra_payload'] ?? '', true);
            if (!is_array($payload) || empty($payload)) {
                $payload = LatraMapper::buildPayload($msg, $msg, (int)$msg['activity_id'],
                    json_decode($msg['addon_info'] ?? '[]', true),
                    json_decode($msg['fuel_info'] ?? '[]', true)
                );
            }

            echo json_encode(['payload' => $payload]);
            break;

        case 'post_to_latra':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST request required');
            }

            $msgId = intval($_POST['message_id'] ?? 0);
            $msg = Database::fetch("SELECT ml.*, v.vehicle_reg_no, v.imei, v.name FROM message_log ml JOIN vehicles v ON ml.vehicle_id = v.id WHERE ml.id = ?", [$msgId]);
            if (!$msg) throw new Exception('Message not found');

            $payload = json_decode($_POST['payload'] ?? '', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || empty($payload['items']) || !is_array($payload['items'])) {
                throw new Exception('Payload must contain an items array');
            }
            $validationErrors = LatraMapper::validatePayload($payload);
            if ($validationErrors) {
                throw new Exception('Invalid LATRA payload: ' . implode('; ', $validationErrors));
            }
            if (RelayRuntime::enabled()) {
                $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [(int) $msg['vehicle_id']]);
                if (!$vehicle) throw new Exception('Vehicle not found');
                $result = queueRelayPayload($vehicle, $payload);
                echo json_encode($result ?: ['success' => true, 'message' => 'Saved to the durable relay. Real LATRA delivery is blocked.']);
                break;
            }
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
            Database::update('message_log', ['latra_payload' => $payloadJson], 'id = ?', [$msgId]);

            $ch = curl_init(LATRA_API_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Basic ' . base64_encode(LATRA_USERNAME . ':' . LATRA_PASSWORD)
                ],
                CURLOPT_TIMEOUT => 30
            ]);

            $start = microtime(true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $latency = round((microtime(true) - $start) * 1000);

            $respData = json_decode($response ?: '', true);
            $status = ($httpCode === 200 && isset($respData['status']) && $respData['status'] === '1') ? 'sent' : 'failed';
            if ($response === false) {
                $response = $curlError ?: 'LATRA request failed';
            }

            Database::update('message_log', [
                'latra_status' => $status,
                'latra_response' => $response,
                'pushed_at' => date(DATE_FORMAT)
            ], 'id = ?', [$msgId]);

            Database::insert('push_log', [
                'vehicle_id' => $msg['vehicle_id'],
                'activity_id' => $msg['activity_id'],
                'message_log_id' => $msgId,
                'payload' => $payloadJson,
                'response_status' => $respData['status'] ?? null,
                'response_body' => $response,
                'http_code' => $httpCode,
                'latency_ms' => $latency
            ]);

            echo json_encode([
                'success' => $status === 'sent',
                'message' => $status === 'sent' ? 'Posted to LATRA successfully' : 'LATRA rejected the payload: ' . $response
            ]);
            break;

        case 'activity_stats':
            $stats = Database::fetchAll("SELECT activity_id, COUNT(*) as c FROM push_log GROUP BY activity_id");
            $labels = [];
            $values = [];
            foreach ($stats as $s) {
                $labels[] = LatraMapper::getActivityName($s['activity_id']);
                $values[] = $s['c'];
            }
            echo json_encode(['labels' => $labels, 'values' => $values]);
            break;

        case 'test_wialon':
            $wialon = new WialonAPI();
            $ok = $wialon->login();
            if ($ok) $wialon->logout();
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Wialon connection OK' : 'Wialon login failed']);
            break;

        case 'test_latra':
            $url = Vault::latraUrl();
            if ($url === '') {
                echo json_encode(['success' => false, 'message' => 'LATRA_API_URL is not configured in .env']);
                break;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode(Vault::latraUser() . ':' . Vault::latraPass()),
                ],
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $start = microtime(true);
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $latency = (int) round((microtime(true) - $start) * 1000);
            curl_close($ch);
            if ($httpCode > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Endpoint reachable (HTTP ' . $httpCode . ' in ' . $latency . ' ms). ' .
                        (in_array($httpCode, [401, 403], true) ? 'Basic-auth check: credentials ' . ($httpCode === 401 ? 'rejected' : 'insufficient'). '.' : 'No auth challenge at this path.'),
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Unreachable: ' . ($curlError ?: 'no response')]);
            }
            break;

        case 'generate_payload':
            $vid = intval($_GET['vehicle_id'] ?? 0);
            $aid = intval($_GET['activity_id'] ?? 0);
            $mode = $_GET['mode'] ?? 'single';
            $meta = null;
            $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vid]);
            if (!$vehicle) throw new Exception('Vehicle not found');

            if ($mode === 'bulk') {
                $messages = Database::fetchAll("SELECT * FROM message_log WHERE vehicle_id = ? ORDER BY wialon_msg_time DESC LIMIT 10", [$vid]);
                if (!$messages) {
                    $addonInfo = LatraMapper::buildAddonInfo($aid) ?? [];
                    $fuelInfo = $aid === 16 ? LatraMapper::buildFuelInfo() : [];
                    $payload = LatraMapper::buildPayload($vehicle, $vehicle, $aid, $addonInfo, $fuelInfo);
                } else {
                    $items = [];
                    foreach ($messages as $msg) {
                        $params = extractWialonParams($msg);
                        $addonCustom = [];
                        if ($aid === EventDetector::ENGINE_OFF) {
                            $addonCustom = LatraMapper::buildTripStats($vid, (int) $msg['wialon_msg_time']);
                        }
                        $addonInfo = LatraMapper::buildAddonInfo($aid, $params, $addonCustom) ?? [];
                        $fuelInfo = $aid === 16 ? LatraMapper::buildFuelInfo($params) : [];
                        $p = LatraMapper::buildPayload($vehicle, $msg, $aid, $addonInfo, $fuelInfo);
                        if (!empty($p['items'])) {
                            $items[] = $p['items'][0];
                        }
                    }
                    $payload = [
                        'vehicle_reg_no' => $vehicle['vehicle_reg_no'] ?? 'UNKNOWN',
                        'type'           => 'poi',
                        'imei'           => $vehicle['imei'] ?? '000000000000000',
                        'items'          => $items
                    ];
                }
            } else {
                $latest = Database::fetch("SELECT * FROM message_log WHERE vehicle_id = ? ORDER BY wialon_msg_time DESC LIMIT 1", [$vid]);
                $source = $latest ?: $vehicle;
                $params = $latest ? extractWialonParams($latest) : [];
                $liveUsed = false;

                // If stored telemetry is stale, fetch the newest message LIVE from
                // Wialon so "Update JSON" reflects recent data, not just a rebuilt format.
                $latestTs = $latest ? (int) $latest['wialon_msg_time'] : 0;
                if ($latestTs < time() - 600) {
                    try {
                        $wialon = new WialonAPI();
                        if (!$wialon->login()) {
                            throw new RuntimeException('Wialon login failed');
                        }
                        $unitId = (int) ($vehicle['wialon_unit_id'] ?? 0);
                        $unit = null;
                        foreach ($wialon->getUnits() as $u) {
                            if ((int) ($u['id'] ?? 0) === $unitId) {
                                $unit = $u;
                                break;
                            }
                        }
                        $msgs = $wialon->getMessages($unitId, time() - 7 * 86400, time(), 1);
                        $vlive = $msgs[0] ?? null;
                        if ($vlive && (int) ($vlive['t'] ?? 0) > $latestTs) {
                            $unitBase = is_array($unit) ? $unit : [];
                            $unitBase['lmsg'] = $vlive;
                            $row = LatraMapper::mapVehicle($unitBase);
                            $row['wialon_msg_time'] = (int) $vlive['t'];
                            // Freshen vehicle identity (imei, reg no) + telemetry from the
                            // live unit/message, keeping DB values as fallback for gaps.
                            $vehicle = array_merge($vehicle, array_filter($row, fn ($v) => $v !== null));
                            $source = $row;
                            $params = $vlive['p'] ?? [];
                            $liveUsed = true;
                        }
                    } catch (Throwable $e) {
                        // Wialon unavailable/access denied → keep the stored fallback.
                    }
                }

                $addonInfo = LatraMapper::buildAddonInfo($aid, $params) ?? [];
                $fuelInfo = $aid === 16 ? LatraMapper::buildFuelInfo($params) : [];
                $payload = LatraMapper::buildPayload($vehicle, $source, $aid, $addonInfo, $fuelInfo);

                $sourceTime = (int) ($source['wialon_msg_time'] ?? $source['last_message_time'] ?? 0);
                $meta = [
                    'source'     => $liveUsed ? 'live' : ($latest ? 'stored' : 'vehicle'),
                    'source_time' => $sourceTime,
                    'is_recent'  => $liveUsed || $latestTs >= time() - 600,
                ];
            }

            echo json_encode(['payload' => $payload, 'meta' => $meta]);
            break;

        case 'push_custom_payload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST request required');
            $vid = intval($_POST['vehicle_id'] ?? 0);
            $aid = intval($_POST['activity_id'] ?? 0);
            $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vid]);
            if (!$vehicle) throw new Exception('Vehicle not found');

            $payloadJson = $_POST['payload'] ?? '';
            $payload = json_decode($payloadJson, true);
            if (!$payload) throw new Exception('Invalid JSON payload');
            $validationErrors = LatraMapper::validatePayload($payload);
            if ($validationErrors) {
                throw new Exception('Invalid LATRA payload: ' . implode('; ', $validationErrors));
            }
            if (RelayRuntime::enabled()) {
                $result = queueRelayPayload($vehicle, $payload);
                echo json_encode($result ?: ['success' => true, 'message' => 'Saved to the durable relay. Real LATRA delivery is blocked.']);
                break;
            }
            $ch = curl_init(LATRA_API_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Basic ' . base64_encode(LATRA_USERNAME . ':' . LATRA_PASSWORD)
                ],
                CURLOPT_TIMEOUT => 30
            ]);

            $start = microtime(true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $latency = round((microtime(true) - $start) * 1000);

            $respData = json_decode($response ?: '', true);
            $status = ($httpCode === 200 && isset($respData['status']) && $respData['status'] === '1') ? 'sent' : 'failed';
            if ($response === false) $response = $curlError ?: 'LATRA request failed';

            Database::insert('push_log', [
                'vehicle_id' => $vid,
                'activity_id' => $aid,
                'message_log_id' => null,
                'payload' => $payloadJson,
                'response_status' => $respData['status'] ?? null,
                'response_body' => $response,
                'http_code' => $httpCode,
                'latency_ms' => $latency
            ]);

            echo json_encode([
                'success' => $status === 'sent',
                'message' => $status === 'sent' ? 'Posted to LATRA successfully' : 'LATRA rejected the payload: ' . $response
            ]);
            break;

        case 'delete_push':
            $id = intval($_REQUEST['id'] ?? 0);
            Database::query("DELETE FROM push_log WHERE id = ?", [$id]);
            echo json_encode(['success' => true, 'message' => 'Log entry deleted successfully']);
            break;

        case 'clear_data':
            $category = trim((string) ($_POST['category'] ?? ''));
            $filters = [
                'vehicle' => trim((string) ($_POST['vehicle'] ?? '')),
                'imei' => trim((string) ($_POST['imei'] ?? '')),
                'activity' => trim((string) ($_POST['activity'] ?? '')),
                'status' => trim((string) ($_POST['status'] ?? '')),
                'event_type' => trim((string) ($_POST['event_type'] ?? '')),
                'event' => trim((string) ($_POST['event'] ?? '')),
                'response_status' => trim((string) ($_POST['response_status'] ?? '')),
                'from' => trim((string) ($_POST['from'] ?? '')),
                'to' => trim((string) ($_POST['to'] ?? '')),
                'older_than_days' => trim((string) ($_POST['older_than_days'] ?? '')),
            ];
            if ($category === 'reset_all') {
                $result = DataCleaner::resetAll();
                Auth::audit('data.reset_all', '', json_encode($result), 'admin');
                echo json_encode(['success' => true, 'message' => 'All generated data reset', 'result' => $result]);
                break;
            }
            $result = DataCleaner::clear($category, $filters);
            Auth::audit('data.clear', $category, json_encode(['filters' => $filters, 'result' => $result]), 'admin');
            echo json_encode(['success' => true, 'message' => ucfirst(str_replace('_', ' ', $category)) . ' cleared', 'result' => $result]);
            break;

        case 'retry_push':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST request required');
            $id = intval($_POST['id'] ?? 0);
            $log = Database::fetch("SELECT * FROM push_log WHERE id = ?", [$id]);
            if (!$log) throw new Exception('Log entry not found');
            $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [(int) $log['vehicle_id']]);
            if (!$vehicle) throw new Exception('Vehicle not found');

            $payloadJson = $log['payload'];
            $payload = json_decode($payloadJson ?? '', true);
            if (!$payload) throw new Exception('Stored payload is invalid JSON. Use Edit to rebuild it.');
            $validationErrors = LatraMapper::validatePayload($payload);
            if ($validationErrors) {
                throw new Exception('Invalid LATRA payload: ' . implode('; ', $validationErrors) . '. Use Edit and Update JSON before retrying.');
            }
            if (RelayRuntime::enabled()) {
                $result = queueRelayPayload($vehicle, $payload);
                echo json_encode($result ?: ['success' => true, 'message' => 'Saved to the durable relay. Real LATRA delivery is blocked.']);
                break;
            }
            $ch = curl_init(LATRA_API_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Basic ' . base64_encode(LATRA_USERNAME . ':' . LATRA_PASSWORD)
                ],
                CURLOPT_TIMEOUT => 30
            ]);

            $start = microtime(true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $latency = round((microtime(true) - $start) * 1000);

            $respData = json_decode($response ?: '', true);
            $status = ($httpCode === 200 && isset($respData['status']) && $respData['status'] === '1') ? 'sent' : 'failed';
            if ($response === false) $response = $curlError ?: 'LATRA request failed';

            // Insert new log for the retry
            Database::insert('push_log', [
                'vehicle_id' => $log['vehicle_id'],
                'activity_id' => $log['activity_id'],
                'message_log_id' => $log['message_log_id'],
                'payload' => $payloadJson,
                'response_status' => $respData['status'] ?? null,
                'response_body' => $response,
                'http_code' => $httpCode,
                'latency_ms' => $latency
            ]);

            echo json_encode([
                'success' => $status === 'sent',
                'message' => $status === 'sent' ? 'Retry successful' : 'Retry failed: ' . $response
            ]);
            break;

        case 'bulk_fetch_messages':
            // SSE streaming: fetches messages for multiple vehicles sequentially
            $rawIds = $_GET['vehicle_ids'] ?? '';
            $vehicleIds = array_filter(array_map('intval', explode(',', $rawIds)));
            if (empty($vehicleIds)) throw new Exception('No vehicle IDs provided');

            // Switch to SSE mode
            startSse();

            $total = count($vehicleIds);
            $successCount = 0;
            $failCount = 0;
            sendSse(['type' => 'init', 'total' => $total, 'message' => 'Connected. Starting vehicle fetch...']);

            $wialon = RelayRuntime::enabled() ? null : new WialonAPI();
            if ($wialon !== null && !$wialon->login()) {
                sendSse(['type' => 'error', 'message' => 'Wialon login failed']);
                break;
            }

            foreach ($vehicleIds as $i => $vid) {
                $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vid]);
                if (!$vehicle) {
                    sendSse([
                        'type' => 'progress', 'current' => $i + 1, 'total' => $total,
                        'vehicle' => 'Vehicle #' . $vid, 'inserted' => 0, 'status' => 'fail'
                    ]);
                    $failCount++;
                    continue;
                }

                try {
                    if (RelayRuntime::enabled()) {
                        $collected = RelayRuntime::poll($vehicle, 86400, 100);
                        sendSse(['type' => 'progress', 'current' => $i + 1, 'total' => $total,
                            'vehicle' => $vehicle['vehicle_reg_no'], 'status' => 'ok'] + $collected);
                        $successCount++;
                        continue;
                    }
                    $to = time();
                    $from = $to - 86400;
                    $messages = $wialon->getMessages((int)$vehicle['wialon_unit_id'], $from, $to, 100);
                    $inserted = 0;

                    foreach ($messages as $msg) {
                        $pos = $msg['pos'] ?? [];
                        $params = $msg['p'] ?? [];
                        $activityId = LatraMapper::detectActivity($params);
$addonCustom = [];
                if ($activityId === EventDetector::ENGINE_OFF) {
                            $addonCustom = LatraMapper::buildTripStats($vid, (int) ($msg['t'] ?? 0));
                }
                $addonInfo = json_encode(LatraMapper::buildAddonInfo($activityId, $params, $addonCustom) ?? []);
                        $fuelInfo = $activityId === 16 ? json_encode(LatraMapper::buildFuelInfo($params)) : null;
                        $msgData = [
                            'vehicle_id' => $vid,
                            'wialon_msg_time' => $msg['t'] ?? time(),
                            'raw_data' => json_encode($msg),
                            'lat' => isset($pos['y']) ? round((float)$pos['y'], 8) : null,
                            'lng' => isset($pos['x']) ? round((float)$pos['x'], 8) : null,
                            'altitude' => $pos['z'] ?? null,
                            'speed' => $pos['s'] ?? null,
                            'bearing' => $pos['c'] ?? null,
                            'satellite_count' => $pos['sc'] ?? null,
                            'hdop' => $params['hdop'] ?? null,
                            'd2d3' => isset($pos['z']) && $pos['z'] !== 0 ? 3 : 2,
                            'rssi' => $params['rssi'] ?? $params['gsm'] ?? null,
                            'lac' => $params['lac'] ?? $params['gsm_lac'] ?? $params['lbs_lac'] ?? null,
                            'cell_id' => $params['cell_id'] ?? $params['cellid'] ?? $params['cid'] ?? $params['gsm_cellid'] ?? null,
                            'mcc' => $params['mcc'] ?? null,
                            'mgs_id' => $msg['t'] ?? null,
                            'activity_id' => $activityId,
                            'addon_info' => $addonInfo,
                            'fuel_info' => $fuelInfo,
                            'latra_status' => 'pending'
                        ];
                        if (Automation::storeMessage($msgData)) $inserted++;
                    }

                    sendSse([
                        'type' => 'progress', 'current' => $i + 1, 'total' => $total,
                        'vehicle' => $vehicle['vehicle_reg_no'], 'inserted' => $inserted, 'status' => 'ok'
                    ]);
                    $successCount++;
                } catch (Exception $ex) {
                    sendSse([
                        'type' => 'progress', 'current' => $i + 1, 'total' => $total,
                        'vehicle' => $vehicle['vehicle_reg_no'], 'inserted' => 0, 'status' => 'fail'
                    ]);
                    $failCount++;
                }
            }

            $wialon?->logout();
            sendSse(['type' => 'done', 'success' => $successCount, 'failed' => $failCount]);
            break;

        case 'bulk_push_activity':
            // SSE streaming: pushes a single activity to LATRA for multiple vehicles
            $rawIds = $_GET['vehicle_ids'] ?? '';
            $vehicleIds = array_filter(array_map('intval', explode(',', $rawIds)));
            $aid = intval($_GET['activity_id'] ?? 0);
            if (empty($vehicleIds)) throw new Exception('No vehicle IDs provided');
            if (!$aid) throw new Exception('Activity ID required');
            startSse();

            $total = count($vehicleIds);
            $successCount = 0;
            $failCount = 0;
            sendSse(['type' => 'init', 'total' => $total, 'message' => 'Connected. Starting LATRA push...']);

            foreach ($vehicleIds as $i => $vid) {
                $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vid]);
                if (!$vehicle) {
                    sendSse([
                        'type' => 'progress', 'current' => $i + 1, 'total' => $total,
                        'vehicle' => 'Vehicle #' . $vid, 'latra_status' => 'fail'
                    ]);
                    $failCount++;
                    continue;
                }

                try {
                    // Use latest message or fall back to vehicle data
                    $latest = Database::fetch("SELECT * FROM message_log WHERE vehicle_id = ? ORDER BY wialon_msg_time DESC LIMIT 1", [$vid]);
                    $source = $latest ?: $vehicle;
                    $params = $latest ? extractWialonParams($latest) : [];
                    $addonCustom = [];
                    if ($aid === EventDetector::ENGINE_OFF) {
                        $addonCustom = LatraMapper::buildTripStats($vid, (int) ($source['wialon_msg_time'] ?? $latest['wialon_msg_time'] ?? time()));
                    }
                    $addonInfo = LatraMapper::buildAddonInfo($aid, $params, $addonCustom) ?? [];
                    $fuelInfo = $aid === 16 ? LatraMapper::buildFuelInfo($params) : [];
                    $payload = LatraMapper::buildPayload($vehicle, $source, $aid, $addonInfo, $fuelInfo);
                    if (RelayRuntime::enabled()) {
                        $result = queueRelayPayload($vehicle, $payload);
                        $latraStatus = ($result['success'] ?? false) ? 'queued' : 'fail';
                        sendSse([
                            'type' => 'progress', 'current' => $i + 1, 'total' => $total,
                            'vehicle' => $vehicle['vehicle_reg_no'], 'latra_status' => $latraStatus,
                            'message' => $result['message'] ?? 'Saved to the durable relay. Real LATRA delivery is blocked.'
                        ]);
                        if ($latraStatus === 'queued') $successCount++; else $failCount++;
                        continue;
                    }
                    $payloadJson = json_encode($payload);

                    $ch = curl_init(LATRA_API_URL);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $payloadJson,
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'Authorization: Basic ' . base64_encode(LATRA_USERNAME . ':' . LATRA_PASSWORD)
                        ],
                        CURLOPT_TIMEOUT => 30
                    ]);

                    $start = microtime(true);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    $latency = round((microtime(true) - $start) * 1000);
                    curl_close($ch);

                    $respData = json_decode($response ?: '', true);
                    $latraStatus = ($httpCode === 200 && isset($respData['status']) && $respData['status'] === '1') ? 'sent' : 'failed';

                    Database::insert('push_log', [
                        'vehicle_id' => $vid,
                        'activity_id' => $aid,
                        'message_log_id' => $latest['id'] ?? null,
                        'payload' => $payloadJson,
                        'response_status' => $respData['status'] ?? null,
                        'response_body' => $response ?: $curlError,
                        'http_code' => $httpCode,
                        'latency_ms' => $latency
                    ]);

                    sendSse([
                        'type' => 'progress', 'current' => $i + 1, 'total' => $total,
                        'vehicle' => $vehicle['vehicle_reg_no'], 'latra_status' => $latraStatus
                    ]);

                    if ($latraStatus === 'sent') $successCount++; else $failCount++;
                } catch (Exception $ex) {
                    sendSse([
                        'type' => 'progress', 'current' => $i + 1, 'total' => $total,
                        'vehicle' => $vehicle['vehicle_reg_no'], 'latra_status' => 'fail'
                    ]);
                    $failCount++;
                }
            }

            sendSse(['type' => 'done', 'success' => $successCount, 'failed' => $failCount]);
            break;

        case 'latra_interrogate':
            Auth::requirePermission('device.interrogate');
            $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
            if ($vehicleId <= 0) {
                throw new Exception('vehicle_id is required');
            }
            $vehicle = Database::fetch("SELECT * FROM vehicles WHERE id = ?", [$vehicleId]);
            if (!$vehicle) {
                throw new Exception('Vehicle not found');
            }
            $device = Database::fetch("SELECT * FROM device_status WHERE vehicle_id = ?", [$vehicleId]);
            $latest = Database::fetch(
                "SELECT m.*, v.vehicle_reg_no FROM message_log m JOIN vehicles v ON v.id = m.vehicle_id
                 WHERE m.vehicle_id = ? ORDER BY m.wialon_msg_time DESC LIMIT 1",
                [$vehicleId]
            );
            $snapshot = [
                'vehicle' => [
                    'id' => (int) $vehicle['id'],
                    'vehicle_reg_no' => $vehicle['vehicle_reg_no'],
                    'name' => $vehicle['name'] ?? '',
                    'imei' => $vehicle['imei'] ?? '',
                    'hw_type' => $vehicle['hw_type'] ?? '',
                    'phone' => $vehicle['phone'] ?? '',
                    'speed_limit_kmh' => isset($vehicle['speed_limit_kmh']) ? (float) $vehicle['speed_limit_kmh'] : null,
                    'odometer' => isset($vehicle['odometer']) ? (float) $vehicle['odometer'] : null,
                    'last_message_time' => isset($vehicle['last_message_time']) && $vehicle['last_message_time'] ? (int) $vehicle['last_message_time'] : null,
                    'is_active' => (bool) $vehicle['is_active'],
                    'last_sync_at' => $vehicle['last_sync_at'] ?? null,
                ],
                'device' => $device
                    ? [
                        'is_online' => (bool) $device['is_online'],
                        'online_at' => $device['online_at'] ?? null,
                        'last_comm_at' => $device['last_comm_at'] ?? null,
                        'last_gps_at' => isset($device['last_gps_at']) && $device['last_gps_at'] ? (int) $device['last_gps_at'] : null,
                        'internal_battery_low' => (bool) $device['internal_battery_low'],
                        'internal_battery_pct' => isset($device['internal_battery_pct']) ? (float) $device['internal_battery_pct'] : null,
                        'internal_battery_mv' => isset($device['internal_battery_mv']) ? (int) $device['internal_battery_mv'] : null,
                        'external_power_mv' => isset($device['external_power_mv']) ? (int) $device['external_power_mv'] : null,
                        'external_power_disconnected' => (bool) $device['external_power_disconnected'],
                        'tampered' => (bool) $device['tampered'],
                        'last_status' => $device['last_status'] ?? null,
                        'updated_at' => $device['updated_at'] ?? null,
                    ]
                    : null,
                'latest_message' => $latest
                    ? [
                        'wialon_msg_time' => isset($latest['wialon_msg_time']) ? (int) $latest['wialon_msg_time'] : null,
                        'created_at' => $latest['created_at'] ?? null,
                        'lat' => isset($latest['lat']) ? (float) $latest['lat'] : null,
                        'lng' => isset($latest['lng']) ? (float) $latest['lng'] : null,
                        'altitude' => isset($latest['altitude']) ? (int) $latest['altitude'] : null,
                        'speed' => isset($latest['speed']) ? (float) $latest['speed'] : null,
                        'bearing' => isset($latest['bearing']) ? (float) $latest['bearing'] : null,
                        'satellite_count' => isset($latest['satellite_count']) ? (int) $latest['satellite_count'] : null,
                        'activity_id' => isset($latest['activity_id']) ? (int) $latest['activity_id'] : null,
                        'driver_id' => $latest['driver_id'] ?? null,
                        'odometer' => isset($latest['odometer']) ? (float) $latest['odometer'] : null,
                        'latra_status' => $latest['latra_status'] ?? '',
                        'latra_response' => $latest['latra_response'] ?? null,
                        'validation_status' => $latest['validation_status'] ?? null,
                    ]
                    : null,
                'stale' => $device && $device['updated_at']
                    ? (strtotime((string) $device['updated_at']) < time() - (int) (Database::fetch("SELECT config_value FROM config WHERE config_key = 'offline_seconds'")['config_value'] ?? 900))
                    : true,
            ];
            Auth::audit(
                'latra.interrogate',
                (string) $vehicle['vehicle_reg_no'],
                json_encode([
                    'request_id' => 'INT-' . strtoupper(substr(hash('sha256', microtime() . Auth::clientKey()), 0, 10)),
                    'vehicle_id' => (int) $vehicle['id'],
                    'imei' => $vehicle['imei'] ?? '',
                    'state' => $snapshot['stale'] ? 'stale' : 'current',
                ])
            );
            echo json_encode(['success' => true, 'snapshot' => $snapshot]);
            break;

        case 'scheduler_health':
            $lastRun = Database::fetch("SELECT MAX(started_at) AS s FROM automation_runs")['s'] ?? null;
            $enabledBuckets = (int) (Database::fetch("SELECT COUNT(*) AS c FROM automation_buckets WHERE is_enabled = 1")['c'] ?? 0);
            $backlog = (int) (Database::fetch("SELECT COUNT(*) AS c FROM message_log WHERE latra_status = 'pending'")['c'] ?? 0);
            $deadLetter = (int) (Database::fetch("SELECT COUNT(*) AS c FROM message_log WHERE latra_status IN ('manual','dead')")['c'] ?? 0);
            $lastTick = @file_get_contents(LOGS_DIR . '/last_tick.txt');
            echo json_encode([
                'ok' => true,
                'last_tick_at' => $lastTick,
                'last_run_at' => $lastRun,
                'enabled_buckets' => $enabledBuckets,
                'backlog' => $backlog,
                'dead_letter' => $deadLetter,
                'app_env' => APP_ENV,
                'app_version' => APP_VERSION,
            ]);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);

    }

    if (in_array($action, $writeActions, true)) {
        $target = $_POST['target_label'] ?? $_POST['id'] ?? $_POST['message_id'] ?? $_POST['vehicle_id'] ?? $_POST['activity_id'] ?? '';
        Auth::audit('api.' . $action, (string) $target);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
