<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Closure;
use InvalidArgumentException;
use JsonException;
use Latra\Application\Collection\RelayReviewRecoveryRepository;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class PdoRelayReviewRecoveryRepository implements RelayReviewRecoveryRepository
{
    private ?Closure $fault;

    public function __construct(
        private PDO $database,
        private int $maximumPendingBytes,
        ?callable $fault = null,
    ) {
        if ($maximumPendingBytes < 1) {
            throw new InvalidArgumentException('Explicit positive pending byte capacity required');
        }
        $this->fault = $fault === null ? null : Closure::fromCallable($fault);
    }

    public function recoverReviews(string $vehicleUnit, callable $map): array
    {
        if ($this->database->inTransaction()) {
            throw new RuntimeException('Review recovery must own its transaction');
        }
        $this->database->beginTransaction();
        try {
            $units = $this->run(
                'SELECT unit FROM relay_units
                 WHERE unit=? OR unit LIKE ? OR unit LIKE ? ORDER BY unit FOR UPDATE',
                [$vehicleUnit, $vehicleUnit . ':%', 'manual:' . $vehicleUnit . ':%'],
            )->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array($vehicleUnit, $units, true)) {
                throw new RuntimeException('Vehicle relay state is unavailable');
            }
            $attempted = $this->run(
                'SELECT MAX(attempted_time) FROM relay_units
                 WHERE unit=? OR unit LIKE ? OR unit LIKE ?',
                [$vehicleUnit, $vehicleUnit . ':%', 'manual:' . $vehicleUnit . ':%'],
            )->fetchColumn();
            $rows = $this->run(
                "SELECT p.*, i.id AS identity_id, i.source_key AS identity_source_key
                 FROM relay_pending p JOIN relay_identity i ON i.delivery_key=p.delivery_key
                 WHERE (p.unit=? OR p.unit LIKE ? OR p.unit LIKE ?) AND p.status='review'
                 ORDER BY p.source_time, p.id FOR UPDATE",
                [$vehicleUnit, $vehicleUnit . ':%', 'manual:' . $vehicleUnit . ':%'],
            )->fetchAll(PDO::FETCH_ASSOC);
            $stored = $this->run(
                'SELECT payload FROM relay_pending WHERE unit=? OR unit LIKE ? OR unit LIKE ?',
                [$vehicleUnit, $vehicleUnit . ':%', 'manual:' . $vehicleUnit . ':%'],
            )->fetchAll(PDO::FETCH_COLUMN);
            $pendingBytes = array_sum(array_map('strlen', $stored));
            $recovered = $held = $skipped = 0;
            foreach ($rows as $row) {
                if ((int) $row['attempts'] !== 0 || $row['last_error'] !== 'Mapping or late source requires review'
                    || ($attempted !== false && $attempted !== null && (int) $row['source_time'] < (int) $attempted)) {
                    $skipped++;
                    continue;
                }
                $source = json_decode($row['payload'], true);
                if (!is_array($source) || !is_int($source['t'] ?? null)
                    || $source['t'] !== (int) $row['source_time']) {
                    $held++;
                    continue;
                }
                $sourceKey = $row['identity_source_key'];
                if ($sourceKey === null) {
                    $rawHash = hash('sha256', $row['payload']);
                    $legacyKey = hash('sha256', $row['unit'] . ':' . $rawHash . ':1');
                    if (!hash_equals($legacyKey, $row['delivery_key'])) {
                        $skipped++;
                        continue;
                    }
                    $sourceKey = hash('sha256', $vehicleUnit . ':' . $rawHash . ':1');
                }
                if (!preg_match('/^[a-f0-9]{64}$/D', $sourceKey)) {
                    $skipped++;
                    continue;
                }
                try {
                    $mapped = $map($source, $row['unit']);
                } catch (RuntimeException|InvalidArgumentException) {
                    $held++;
                    continue;
                }
                $payload = $mapped['payload'] ?? null;
                if (!is_array($payload) || !is_array($payload['items'] ?? null) || !$payload['items']) {
                    $held++;
                    continue;
                }
                if ((int) $row['identity_id'] > intdiv(PHP_INT_MAX - 31, 32)) {
                    $held++;
                    continue;
                }
                $newItems = [];
                $activities = [];
                foreach ($payload['items'] as $item) {
                    $activity = is_array($item) ? (int) ($item['activity_id'] ?? 0) : 0;
                    if ($activity < 1 || $activity > 24 || isset($activities[$activity])) {
                        $held++;
                        continue 2;
                    }
                    $activities[$activity] = true;
                    if ($this->run(
                        'SELECT delivery_key FROM relay_activity_identity WHERE source_key=? AND activity_id=?',
                        [$sourceKey, $activity],
                    )->fetchColumn() !== false) {
                        continue;
                    }
                    $item['idempotency_key'] = $sourceKey . '-a' . $activity;
                    $item['MGS_ID'] = (string) ((int) $row['identity_id'] * 32 + $activity);
                    $newItems[] = $item;
                }
                if (!$newItems) {
                    $skipped++;
                    continue;
                }
                $payload['items'] = $newItems;
                try {
                    $body = json_encode($payload, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $held++;
                    continue;
                }
                $updatedBytes = $pendingBytes + strlen($body) - strlen($row['payload']);
                if ($updatedBytes > $this->maximumPendingBytes) {
                    $skipped++;
                    continue;
                }
                foreach (array_keys($activities) as $activity) {
                    if ($this->run(
                        'SELECT delivery_key FROM relay_activity_identity WHERE source_key=? AND activity_id=?',
                        [$sourceKey, $activity],
                    )->fetchColumn() === false) {
                        $this->run(
                            'INSERT INTO relay_activity_identity (source_key, activity_id, delivery_key)
                             VALUES (?, ?, ?)',
                            [$sourceKey, $activity, $row['delivery_key']],
                        );
                    }
                }
                $this->run(
                    "UPDATE relay_pending SET payload=?, status='pending', last_error=NULL
                     WHERE id=? AND status='review'",
                    [$body, $row['id']],
                );
                $this->run(
                    "UPDATE relay_identity SET state='pending', source_key=? WHERE delivery_key=?",
                    [$sourceKey, $row['delivery_key']],
                );
                $this->hit('recovery-intent');
                $pendingBytes = $updatedBytes;
                $recovered++;
            }
            $this->database->commit();
            return ['recovered' => $recovered, 'held' => $held, 'skipped' => $skipped];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function hit(string $stage): void
    {
        if ($this->fault !== null) {
            ($this->fault)($stage);
        }
    }

    private function run(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }
}
