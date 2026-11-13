<?php
/**
 * Wialon API client.
 */
class WialonAPI {
    private string $baseUrl;
    private string $token;
    private ?string $sid = null;
    private int $lastError = 0;

    public function __construct(string $baseUrl = WIALON_BASE_URL, string $token = WIALON_TOKEN) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    /**
     * Execute Wialon API request
     */
    public function request(string $svc, array $params = [], ?int $maxResponseBytes = null): ?array {
        $url = $this->baseUrl;
        if (!str_ends_with($url, '/wialon/ajax.html')) {
            $url = rtrim($url, '/') . '/wialon/ajax.html';
        }
        $url .= '?svc=' . urlencode($svc);

        if ($this->sid) {
            $url .= '&sid=' . urlencode($this->sid);
        }

        $postData = 'params=' . urlencode(json_encode($params));

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        // The relay bounds each response before decoding. Existing callers keep their transport policy.
        $boundedBody = '';
        if ($maxResponseBytes !== null) {
            if ($maxResponseBytes < 1) throw new InvalidArgumentException('Positive response bound required');
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$boundedBody, $maxResponseBytes): int {
                if (strlen($boundedBody) + strlen($chunk) > $maxResponseBytes) return 0;
                $boundedBody .= $chunk;
                return strlen($chunk);
            });
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || $response === false) {
            error_log("Wialon API HTTP Error: $httpCode");
            return null;
        }

        if ($maxResponseBytes !== null) $response = $boundedBody;

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            $this->lastError = (int) $data['error'];
            if ($this->lastError !== 0) {
                error_log("Wialon API Error {$this->lastError}: " . ($data['reason'] ?? 'Unknown'));
                return null;
            }
        }

        return $data;
    }

    /**
     * Login with token
     */
    public function login(): bool {
        if (empty($this->token)) {
            error_log("Wialon token is empty");
            return false;
        }

        $result = $this->request('token/login', [
            'token' => $this->token
        ]);

        if ($result && isset($result['eid'])) {
            $this->sid = $result['eid'];
            return true;
        }

        return false;
    }

    /**
     * Logout
     */
    public function logout(): void {
        if ($this->sid) {
            $this->request('core/logout', []);
            $this->sid = null;
        }
    }

    /**
     * Get all units (vehicles)
     * flags: 13644935 = base + position + last message + custom fields
     */
    public function getUnits(): array {
        $result = $this->request('core/search_items', [
            'spec' => [
                'itemsType' => 'avl_unit',
                'propName' => 'sys_name',
                'propValueMask' => '*',
                'sortType' => 'sys_name'
            ],
            'force' => 1,
            'flags' => 4294967295, // Get all available unit information including uid, ph, hw
            'from' => 0,
            'to' => 4294967295
        ]);

        return $result['items'] ?? [];
    }

    /**
     * Get single unit by ID with position data
     */
    public function getUnit(int $unitId): ?array {
        $result = $this->request('core/search_item', [
            'id' => $unitId,
            'flags' => 1025 // name + position
        ]);

        return $result['item'] ?? null;
    }

    /**
     * Get messages for a unit in time range
     */
    public function getMessages(int $unitId, int $from, int $to, int $limit = 100): array {
        $result = $this->request('messages/load_interval', [
            'itemId' => $unitId,
            'timeFrom' => $from,
            'timeTo' => $to,
            'flags' => 0,
            'flagsMask' => 0,
            'loadCount' => $limit
        ]);

        return $result['messages'] ?? [];
    }

    /**
     * Get last messages for unit
     */
    public function getLastMessages(int $unitId, int $limit = 10): array {
        $to = time();
        $from = $to - 86400; // Last 24 hours
        return $this->getMessages($unitId, $from, $to, $limit);
    }

    /** Load one data-message window in this session; never turn a partial page into progress. */
    public function getCompleteWindow(int $unitId, int $from, int $to, int $pageSize, int $maxRecords, int $maxBytes): array {
        if ($unitId < 1 || $from < 0 || $to < $from || min($pageSize, $maxRecords, $maxBytes) < 1) {
            throw new InvalidArgumentException('Explicit bounded Wialon window required');
        }
        try {
            $loaded = $this->request('messages/load_interval', [
                'itemId' => $unitId, 'timeFrom' => $from, 'timeTo' => $to,
                'flags' => 0, 'flagsMask' => 0xFF00, 'loadCount' => min($pageSize, $maxRecords),
            ], $maxBytes);
            $total = $loaded['count'] ?? null;
            if (is_int($total) && $total > $maxRecords) throw new RuntimeException('Wialon window needs subdivision', 1001);
            if (!is_int($total) || $total < 0 || $total > $maxRecords) {
                throw new RuntimeException('Invalid or over-bound Wialon count');
            }
            $messages = [];
            $bytes = 0;
            for ($offset = 0; $offset < $total; $offset += $pageSize) {
                $page = $offset === 0 ? ($loaded['messages'] ?? null) : $this->request('messages/get_messages', [
                    // The loaded-message API uses an exclusive upper boundary (verified on the pilot).
                    'indexFrom' => $offset, 'indexTo' => min($total, $offset + $pageSize),
                ], $maxBytes);
                if (!is_array($page) || !array_is_list($page) || count($page) !== min($pageSize, $total - $offset)) {
                    throw new RuntimeException('Incomplete Wialon page');
                }
                foreach ($page as $message) {
                    if (!is_array($message) || !is_int($message['t'] ?? null) || $message['t'] < $from || $message['t'] > $to) {
                        throw new RuntimeException('Invalid Wialon source timestamp');
                    }
                    $bytes += strlen(json_encode($message, JSON_THROW_ON_ERROR));
                    if ($bytes > $maxBytes) throw new RuntimeException('Wialon window exceeds byte bound');
                    $messages[] = $message;
                }
            }
            if ($total === 0 && ($loaded['messages'] ?? null) !== []) {
                throw new RuntimeException('Inconsistent empty Wialon window');
            }
            return $messages;
        } finally {
            $this->request('messages/unload', []);
        }
    }

    /**
     * Get unit sensors
     */
    public function getSensors(int $unitId): array {
        $result = $this->request('unit/get_sensors', [
            'itemId' => $unitId
        ]);

        return $result ?? [];
    }

    /**
     * Get the parameter name of the unit's Engine ignition sensor
     * (type 'engine operation') if configured. Returns null when absent.
     */
    public function getEngineSensorParam(int $unitId): ?string {
        $unit = $this->getUnitData($unitId);
        foreach (($unit['sens'] ?? []) as $sensor) {
            if (($sensor['t'] ?? null) === 'engine operation') {
                $param = trim((string) ($sensor['p'] ?? ''));
                return $param !== '' ? $param : null;
            }
        }
        return null;
    }

    /**
     * Get unit data (extended info)
     */
    public function getUnitData(int $unitId): ?array {
        $result = $this->request('core/search_item', [
            'id' => $unitId,
            'flags' => 0xFFFFFFFF
        ]);

        return $result['item'] ?? null;
    }

    /**
     * Execute raw request
     */
    public function rawRequest(string $svc, array $params = []): ?array {
        return $this->request($svc, $params);
    }

    /**
     * Check if logged in
     */
    public function isLoggedIn(): bool {
        return $this->sid !== null;
    }

    /**
     * Get last error code
     */
    public function getLastError(): int {
        return $this->lastError;
    }

    /**
     * Get session ID
     */
    public function getSid(): ?string {
        return $this->sid;
    }
}
