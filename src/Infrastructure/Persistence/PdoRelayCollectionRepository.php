<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Collection\RelayCollectionRepository;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class PdoRelayCollectionRepository implements RelayCollectionRepository
{
    public function __construct(private PDO $database) {}

    public function beginWindow(string $unit, string $vehicleUnit): array
    {
        if ($this->database->inTransaction()) {
            throw new RuntimeException('Relay collection must own its transaction');
        }
        $this->run('INSERT IGNORE INTO relay_units (unit) VALUES (?)', [$vehicleUnit]);
        $this->run('INSERT IGNORE INTO relay_units (unit) VALUES (?)', [$unit]);
        $this->database->beginTransaction();
        try {
            if ($vehicleUnit !== $unit) {
                $this->run('SELECT unit FROM relay_units WHERE unit=? FOR UPDATE', [$vehicleUnit])->fetchColumn();
            }
            return $this->run('SELECT * FROM relay_units WHERE unit=? FOR UPDATE', [$unit])->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $error) {
            $this->rollbackWindow();
            throw $error;
        }
    }

    public function hasIdentity(string $key): bool
    {
        return $this->run('SELECT state FROM relay_identity WHERE delivery_key=?', [$key])->fetchColumn() !== false;
    }

    public function createIdentity(string $key, string $unit, int $time, string $sourceKey): int
    {
        $this->run(
            "INSERT INTO relay_identity (delivery_key, unit, source_time, state, source_key) VALUES (?, ?, ?, 'pending', ?)",
            [$key, $unit, $time, $sourceKey],
        );
        return (int) $this->database->lastInsertId();
    }

    public function setIdentityState(string $key, string $state): void
    {
        $this->run('UPDATE relay_identity SET state=? WHERE delivery_key=?', [$state, $key]);
    }

    public function knownActivities(string $sourceKey): array
    {
        return array_map('intval', $this->run(
            'SELECT activity_id FROM relay_activity_identity WHERE source_key=?', [$sourceKey],
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    public function addActivityIdentity(string $sourceKey, int $activity, string $key): void
    {
        $this->run('INSERT INTO relay_activity_identity VALUES (?,?,?)', [$sourceKey, $activity, $key]);
    }

    public function addPending(string $key, string $unit, int $time, string $body, string $status, ?string $error): void
    {
        $this->run(
            'INSERT INTO relay_pending (delivery_key, unit, source_time, payload, status, last_error, next_attempt) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$key, $unit, $time, $body, $status, $error, $status === 'review' ? null : 0],
        );
    }

    public function saveCheckpoint(string $unit, int $through, array $mappingState): void
    {
        $this->run('UPDATE relay_units SET collected_through=?, state_json=? WHERE unit=?',
            [$through, json_encode($mappingState, JSON_THROW_ON_ERROR), $unit]);
    }

    public function commitWindow(): void { $this->database->commit(); }

    public function rollbackWindow(): void
    {
        if ($this->database->inTransaction()) $this->database->rollBack();
    }

    public function capacity(string $unit, string $vehicleUnit): array
    {
        $where = $vehicleUnit === $unit && !str_starts_with($unit, 'wialon:')
            ? 'unit=?' : '(unit=? OR unit LIKE ? OR unit LIKE ?)';
        $parameters = $where === 'unit=?'
            ? [$unit]
            : [$vehicleUnit, $vehicleUnit . ':%', 'manual:' . $vehicleUnit . ':%'];
        return $this->run(
            'SELECT COUNT(*) AS records, COALESCE(SUM(OCTET_LENGTH(payload)),0) AS bytes
             FROM relay_pending WHERE ' . $where,
            $parameters,
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function run(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }
}
