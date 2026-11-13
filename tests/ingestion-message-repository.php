<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Infrastructure\Persistence\PdoIngestionMessageRepository;

$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database->exec(
    'CREATE TABLE message_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL,
        wialon_msg_time INTEGER NOT NULL,
        latra_payload TEXT,
        UNIQUE (vehicle_id, wialon_msg_time)
    )',
);
$repository = new PdoIngestionMessageRepository($database);
$message = [
    'vehicle_id' => 17,
    'wialon_msg_time' => 1_800_000_000,
];

if (!$repository->storeMessage($message)) {
    throw new RuntimeException('First source message insert was skipped');
}
$stored = $repository->findMessage(17, 1_800_000_000);
if ($stored === null || (int) $stored['vehicle_id'] !== 17
    || (int) $stored['wialon_msg_time'] !== 1_800_000_000) {
    throw new RuntimeException('Inserted source message could not be read back');
}

$repository->updatePayload($stored, ['items' => [['activity_id' => 3]]]);
$stored = $repository->findMessage(17, 1_800_000_000);
if (json_decode($stored['latra_payload'] ?? '', true) !== ['items' => [['activity_id' => 3]]]) {
    throw new RuntimeException('Source payload update changed');
}

if ($repository->storeMessage($message) !== false) {
    throw new RuntimeException('Duplicate source identity was not skipped');
}
$count = (int) $database->query('SELECT COUNT(*) FROM message_log')->fetchColumn();
if ($count !== 1) {
    throw new RuntimeException('Duplicate source message created an additional row');
}

$database->beginTransaction();
$rolledBackMessage = ['vehicle_id' => 18, 'wialon_msg_time' => 1_800_000_001];
if (!$repository->storeMessage($rolledBackMessage)) {
    throw new RuntimeException('Distinct source message was not inserted');
}
$database->rollBack();
if ($repository->findMessage(18, 1_800_000_001) !== null) {
    throw new RuntimeException('Source message insert escaped its caller transaction');
}

$databaseInstance = new ReflectionProperty(Database::class, 'instance');
$sensitiveConfigScrubbed = new ReflectionProperty(Database::class, 'sensitiveConfigScrubbed');
$previousDatabase = $databaseInstance->getValue();
$previousScrubState = $sensitiveConfigScrubbed->getValue();
$firstConnection = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$secondConnection = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$createTable = static function (PDO $connection): void {
    $connection->exec(
        'CREATE TABLE message_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            vehicle_id INTEGER NOT NULL,
            wialon_msg_time INTEGER NOT NULL,
            latra_payload TEXT,
            UNIQUE (vehicle_id, wialon_msg_time)
        )',
    );
};
$createTable($firstConnection);
$createTable($secondConnection);
$legacyRepository = new \Latra\Infrastructure\Persistence\LegacyIngestionRepository();
try {
    $databaseInstance->setValue(null, $firstConnection);
    $sensitiveConfigScrubbed->setValue(null, true);
    $legacyRepository->beginMessage();
    if (!$legacyRepository->storeMessage(['vehicle_id' => 27, 'wialon_msg_time' => 2_000])) {
        throw new RuntimeException('Legacy adapter did not store through its active transaction');
    }
    $legacyRepository->commitMessage();

    $databaseInstance->setValue(null, $secondConnection);
    $legacyRepository->beginMessage();
    if ($firstConnection->inTransaction() || !$secondConnection->inTransaction()
        || !$legacyRepository->storeMessage(['vehicle_id' => 27, 'wialon_msg_time' => 2_000])) {
        throw new RuntimeException('Legacy adapter did not refresh the message repository connection');
    }
    $stored = $legacyRepository->findMessage(27, 2_000);
    if ($stored === null) {
        throw new RuntimeException('Legacy adapter could not read from the active transaction connection');
    }
    $legacyRepository->updateMessagePayload($stored, ['items' => []]);
    $legacyRepository->rollbackMessage();
    if ($secondConnection->inTransaction()
        || $secondConnection->query('SELECT COUNT(*) FROM message_log')->fetchColumn() != 0
        || $firstConnection->query('SELECT COUNT(*) FROM message_log')->fetchColumn() != 1) {
        throw new RuntimeException('Legacy adapter message operations escaped their captured transaction');
    }
} finally {
    if ($firstConnection->inTransaction()) {
        $firstConnection->rollBack();
    }
    if ($secondConnection->inTransaction()) {
        $secondConnection->rollBack();
    }
    $databaseInstance->setValue(null, $previousDatabase);
    $sensitiveConfigScrubbed->setValue(null, $previousScrubState);
}

echo "PASS message insert, duplicate detection, payload update, and caller transaction behavior\n";
