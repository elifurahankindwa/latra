<?php
/** Pilot transport deliberately has no route to a real LATRA endpoint or credentials. */
final class RelayMockTransport {
    public function __construct(private string $url) {
        if (!preg_match('#^http://127\.0\.0\.1:([0-9]{1,5})/$#D', $url, $match)
            || (int) $match[1] < 1 || (int) $match[1] > 65535) {
            throw new InvalidArgumentException('An explicit 127.0.0.1 mock URL is required');
        }
    }

    public function __invoke(string $body, string $key): array {
        $curl = curl_init($this->url);
        $response = '';
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Idempotency-Key: ' . $key],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 65536) return 0;
                $response .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($curl);
        return ['code' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'data' => $ok === false ? null : json_decode($response, true), 'error' => curl_error($curl)];
    }
}
