<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use PDO;

/**
 * Reads and writes the per-vehicle rolling event state used by ingestion.
 *
 * The table is keyed by vehicle_id; the legacy reader returned an empty array for a missing row and
 * the writer chose insert-vs-update by first reading the row, both of which are preserved here.
 */
final class PdoVehicleStateRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function find(int $vehicleId): array
    {
        $statement = $this->database->prepare('SELECT * FROM vehicle_state WHERE vehicle_id = ?');
        $statement->execute([$vehicleId]);
        $state = $statement->fetch(PDO::FETCH_ASSOC);

        return $state === false ? [] : $state;
    }

    public function save(int $vehicleId, array $state): void
    {
        if ($this->find($vehicleId) !== []) {
            $this->update($state, $vehicleId);
            return;
        }

        $values = ['vehicle_id' => $vehicleId] + $state;
        $columns = array_keys($values);
        $quotedColumns = array_map(static fn (string $column): string => '`' . $column . '`', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $statement = $this->database->prepare(
            'INSERT INTO `vehicle_state` (' . implode(', ', $quotedColumns) . ') VALUES (' . $placeholders . ')',
        );
        $statement->execute(array_values($values));
    }

    private function update(array $state, int $vehicleId): void
    {
        $assignments = [];
        foreach (array_keys($state) as $column) {
            $assignments[] = '`' . $column . '` = ?';
        }
        $statement = $this->database->prepare(
            'UPDATE `vehicle_state` SET ' . implode(', ', $assignments) . ' WHERE vehicle_id = ?',
        );
        $statement->execute([...array_values($state), $vehicleId]);
    }
}
