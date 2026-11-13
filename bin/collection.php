<?php
declare(strict_types=1);
/** Offline capture/replay only. No application bootstrap, network client or delivery dependencies. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/CollectionStore.php';
umask(0077);
try {
    if ($argc !== 7 || $argv[1] !== 'capture-fixture') {
        throw new InvalidArgumentException('Usage: php bin/collection.php capture-fixture STORE FIXTURE PAGE_SIZE MAX_RECORDS MAX_BYTES');
    }
    [$script, $command, $path, $fixturePath, $page, $max, $bytes] = $argv;
    foreach ([$page, $max, $bytes] as $bound) {
        if (!ctype_digit($bound) || (int) $bound < 1) throw new InvalidArgumentException('Positive integer bounds required');
    }
    if (is_link($path) || (file_exists($path) && (!is_file($path) || (fileperms($path) & 0077)))) {
        throw new RuntimeException('Store must be a private regular file, without group/other access');
    }
    $parent = realpath(dirname($path));
    $repo = realpath(__DIR__ . '/..');
    if ($parent === false || (fileperms($parent) & 0077)
        || $parent === $repo || str_starts_with($parent, $repo . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Collection store requires a private directory outside the application checkout');
    }
    $path = $parent . DIRECTORY_SEPARATOR . basename($path);
    if (!is_file($fixturePath) || filesize($fixturePath) > (int) $bytes) throw new RuntimeException('Fixture exceeds byte bound');
    $fixture = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($fixture) || !isset($fixture['source'], $fixture['unit'], $fixture['from'], $fixture['to'], $fixture['context'], $fixture['messages'])
        || !is_array($fixture['messages']) || !array_is_list($fixture['messages'])) {
        throw new RuntimeException('Fixture requires source, unit, from, to, context and messages');
    }
    $store = new CollectionStore(new PDO('sqlite:' . $path));
    $result = $store->capture($fixture['source'], $fixture['unit'], $fixture['from'], $fixture['to'],
        $fixture['context'], (int) $page, (int) $max, static fn($offset, $limit) =>
            ['count' => count($fixture['messages']), 'messages' => array_slice($fixture['messages'], $offset, $limit)]);
    // Prove replay without printing potentially sensitive raw messages.
    $hash = hash_init('sha256');
    $count = 0;
    foreach ($store->replay($result['window_id']) as $message) {
        hash_update($hash, json_encode($message, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n");
        $count++;
    }
    echo json_encode($result + ['replayed' => $count, 'replay_sha256' => hash_final($hash)], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    // Do not expose source values, database paths or driver diagnostics in shared logs.
    fwrite(STDERR, 'Collection failed (' . get_class($e) . "). No raw data printed.\n");
    exit(1);
}
