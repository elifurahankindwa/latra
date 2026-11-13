<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/WialonAPI.php';

class FixtureWialonAPI extends WialonAPI
{
    public bool $loginResult = true;
    public bool $loggedOut = false;
    public int $calls = 0;

    public function __construct(private array $messages)
    {
    }

    public function login(): bool
    {
        return $this->loginResult;
    }

    public function logout(): void
    {
        $this->loggedOut = true;
    }

    public function getCompleteWindow(
        int $unitId,
        int $from,
        int $to,
        int $pageSize,
        int $maxRecords,
        int $maxBytes,
    ): array {
        $this->calls++;
        if ([$unitId, $from, $to, $pageSize, $maxRecords, $maxBytes] !== [123, 100, 104, 2, 10, 4096]) {
            throw new RuntimeException('Wialon window bounds changed');
        }
        return $this->messages;
    }
}

class FixtureWindowCollector implements \Latra\Application\Collection\RelayWindowCollector
{
    public array $collected = [];

    public function collectWindow(
        string $unit,
        int $from,
        int $to,
        int $pageSize,
        int $maxRecords,
        int $maxBytes,
        callable $fetchPage,
        callable $map,
    ): array {
        $this->collected = [$unit, $from, $to, $pageSize, $maxRecords, $maxBytes];
        $firstPage = $fetchPage(0, $pageSize);
        $secondPage = $fetchPage(2, $pageSize);
        $mapped = $map($firstPage['messages'][0], []);
        return [
            'fetched' => $firstPage['count'],
            'pages' => [$firstPage['messages'], $secondPage['messages']],
            'mapped' => $mapped,
        ];
    }
}

$client = new FixtureWialonAPI([
    ['t' => 100, 'p' => ['io_239' => 0]],
    ['t' => 101, 'p' => ['io_239' => 1]],
    ['t' => 102, 'p' => ['io_239' => 0]],
]);
$collector = new FixtureWindowCollector();
$useCase = new \Latra\Application\Collection\CollectRelayWindow(
    new \Latra\Infrastructure\Wialon\WialonMessageWindowSource($client),
    $collector,
);
$result = $useCase->collect(
    123,
    'wialon:123:poll',
    100,
    104,
    2,
    10,
    4096,
    static fn(array $message, array $state): array => [
        'payload' => ['items' => [['timestamp' => (string) $message['t']]]],
        'state' => $state,
    ],
);

if ($result['fetched'] !== 3 || $result['pages'][1][0]['t'] !== 102
    || $result['mapped']['payload']['items'][0]['timestamp'] !== '100'
    || $client->calls !== 1 || !$client->loggedOut
    || $collector->collected !== ['wialon:123:poll', 100, 104, 2, 10, 4096]) {
    throw new RuntimeException('Relay collection use case changed source, mapping or window behavior');
}

$failedLogin = new FixtureWialonAPI([]);
$failedLogin->loginResult = false;
$failed = new \Latra\Infrastructure\Wialon\WialonMessageWindowSource($failedLogin);
try {
    $failed->fetchCompleteWindow(123, 100, 104, 2, 10, 4096);
    throw new LogicException('Expected login failure');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Wialon login failed' || !$failedLogin->loggedOut) {
        throw $error;
    }
}

echo "PASS collection use case separates Wialon source, bounded window and relay persistence\n";
