#!/usr/bin/env php
<?php
/** Apply delivery indexes explicitly; request startup must not build large indexes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
$pdo = Database::getInstance();
$lock = $pdo->query("SELECT GET_LOCK('latra-delivery-migration', 0)")->fetchColumn();
if ((int) $lock !== 1) throw new RuntimeException('Delivery migration is already running');
try {
    $sql = file_get_contents(__DIR__ . '/../migrations/008_delivery_retention.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (explode(';', $sql) as $statement) {
        if (!preg_match('/ALTER TABLE (\w+) ADD INDEX (\w+)/', $statement, $match)) continue;
        $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $check->execute([$match[1], $match[2]]);
        if ((int) $check->fetchColumn() > 0) continue;
        $pdo->exec(trim($statement));
        echo "Added {$match[1]}.{$match[2]}\n";
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('latra-delivery-migration')");
}
