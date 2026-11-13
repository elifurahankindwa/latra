<?php
declare(strict_types=1);
/** Separate, explicitly supplied SQLite store. Never loads app config, queues or workers. */
class CollectionStore {
    public function __construct(private PDO $pdo) {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || $pdo->inTransaction()) {
            throw new InvalidArgumentException('Collection requires a dedicated SQLite connection');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $allowed = ['collection_windows', 'collection_messages', 'collection_window_messages', 'sqlite_sequence'];
        foreach ($pdo->query("SELECT name,type FROM sqlite_master WHERE type IN ('table','view','trigger')") as $row) {
            if ($row['type'] !== 'table' || !in_array($row['name'], $allowed, true)) {
                throw new RuntimeException('Collection database contains unrelated objects');
            }
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA synchronous = FULL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS collection_windows (
            id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, unit TEXT NOT NULL,
            time_from INTEGER NOT NULL, time_to INTEGER NOT NULL, expected_count INTEGER NOT NULL,
            fetched_at INTEGER NOT NULL, context_json TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS collection_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, unit TEXT NOT NULL,
            fingerprint TEXT NOT NULL, occurrence INTEGER NOT NULL, source_time INTEGER NOT NULL,
            raw_json TEXT NOT NULL, UNIQUE(source, unit, fingerprint, occurrence))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS collection_window_messages (
            window_id INTEGER NOT NULL REFERENCES collection_windows(id), position INTEGER NOT NULL,
            message_id INTEGER NOT NULL REFERENCES collection_messages(id),
            PRIMARY KEY(window_id, position))');
    }

    /** All pages and their completed-window checkpoint commit together, or none do. */
    public function capture(string $source, string $unit, int $from, int $to, array $context,
                            int $pageSize, int $maxRecords, callable $fetch): array {
        if ($source === '' || $unit === '' || $from < 0 || $to < $from || $pageSize < 1 || $maxRecords < 1) {
            throw new InvalidArgumentException('Explicit source, unit, window and positive bounds required');
        }
        if ($this->pdo->inTransaction()) throw new RuntimeException('Caller transaction is not supported');
        $contextJson = self::canonicalJson($context);
        $this->pdo->beginTransaction();
        try {
            $window = $this->pdo->prepare('INSERT INTO collection_windows
                (source,unit,time_from,time_to,expected_count,fetched_at,context_json) VALUES (?,?,?,?,?,?,?)');
            $window->execute([$source, $unit, $from, $to, 0, time(), $contextJson]);
            $windowId = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare('INSERT INTO collection_messages
                (source,unit,fingerprint,occurrence,source_time,raw_json) VALUES (?,?,?,?,?,?)
                ON CONFLICT(source,unit,fingerprint,occurrence) DO NOTHING');
            $lookup = $this->pdo->prepare('SELECT id FROM collection_messages
                WHERE source=? AND unit=? AND fingerprint=? AND occurrence=?');
            $link = $this->pdo->prepare('INSERT INTO collection_window_messages VALUES (?,?,?)');
            $offset = 0;
            $total = null;
            $occurrences = [];
            $new = 0;
            do {
                // The source must expose one immutable loaded window, with a total on every page.
                $page = $fetch($offset, $pageSize);
                if (!is_array($page) || array_key_exists('error', $page) || !isset($page['count'], $page['messages'])
                    || !is_int($page['count']) || $page['count'] < 0 || $page['count'] > $maxRecords
                    || !is_array($page['messages']) || !array_is_list($page['messages'])) {
                    throw new RuntimeException('Failed, malformed or over-bound collection page');
                }
                if ($total !== null && $total !== $page['count']) throw new RuntimeException('Source count changed');
                $total = $page['count'];
                if (count($page['messages']) !== min($pageSize, $total - $offset)) {
                    throw new RuntimeException('Incomplete or oversized collection page');
                }
                foreach ($page['messages'] as $message) {
                    if (!is_array($message) || !isset($message['t']) || !is_int($message['t'])
                        || $message['t'] < $from || $message['t'] > $to) {
                        throw new RuntimeException('Message timestamp is missing or outside requested window');
                    }
                    $raw = self::canonicalJson($message);
                    $hash = hash('sha256', $raw);
                    // Preserve identical-message multiplicity; overlapping windows reuse each occurrence.
                    $ordinal = ($occurrences[$hash] ?? 0) + 1;
                    $occurrences[$hash] = $ordinal;
                    $insert->execute([$source, $unit, $hash, $ordinal, $message['t'], $raw]);
                    $new += $insert->rowCount();
                    $lookup->execute([$source, $unit, $hash, $ordinal]);
                    $link->execute([$windowId, $offset++, (int) $lookup->fetchColumn()]);
                }
            } while ($offset < $total);
            $this->pdo->prepare('UPDATE collection_windows SET expected_count=? WHERE id=?')->execute([$total, $windowId]);
            $this->pdo->commit();
            return ['window_id' => $windowId, 'captured' => $total, 'new_messages' => $new];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Window ledgers are checkpoints for exact intervals, never a misleading global high-water mark. */
    public function window(int $id): array {
        $stmt = $this->pdo->prepare('SELECT * FROM collection_windows WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Unknown completed collection window');
        return $row;
    }

    /** Replay one immutable completed window in source order, including identical occurrences. */
    public function replay(int $windowId): Generator {
        $this->window($windowId);
        $stmt = $this->pdo->prepare('SELECT m.raw_json FROM collection_window_messages w
            JOIN collection_messages m ON m.id=w.message_id WHERE w.window_id=? ORDER BY w.position');
        $stmt->execute([$windowId]);
        while ($row = $stmt->fetch()) yield json_decode($row['raw_json'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** Sort object keys only; list ordering, nulls, zeroes and original fields remain intact. */
    private static function canonicalJson(array $value): string {
        $sort = static function ($item) use (&$sort) {
            if (is_array($item)) {
                if (!array_is_list($item)) ksort($item, SORT_STRING);
                foreach ($item as &$child) $child = $sort($child);
                unset($child);
            } elseif (is_object($item)) {
                throw new InvalidArgumentException('Collection values must be JSON arrays/scalars');
            }
            return $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
