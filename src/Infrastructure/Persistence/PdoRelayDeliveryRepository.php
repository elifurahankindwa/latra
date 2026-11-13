<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Closure;
use Latra\Application\Delivery\RelayDeliveryRepository;
use Latra\Domain\Delivery\DeliveryClaim;
use Latra\Domain\Delivery\DeliveryClaimStatus;
use PDO;
use PDOStatement;
use Throwable;

final class PdoRelayDeliveryRepository implements RelayDeliveryRepository
{
    private ?Closure $cleanupHook;
    private ?string $ownedUnit = null;
    private ?int $connectionId = null;

    public function __construct(private PDO $database, ?callable $cleanupHook = null)
    {
        $this->cleanupHook = $cleanupHook === null ? null : Closure::fromCallable($cleanupHook);
    }

    public function acquireUnitLock(string $unit): bool
    {
        if ($this->ownedUnit !== null) throw new \LogicException('Delivery repository already owns a unit');
        if ((int) $this->run('SELECT GET_LOCK(?, 0)', [$this->lockName($unit)])->fetchColumn() !== 1) return false;
        $this->ownedUnit = $unit;
        $this->connectionId = (int) $this->run('SELECT CONNECTION_ID()')->fetchColumn();
        $this->assertUnitLock($unit);
        return true;
    }

    public function assertUnitLock(string $unit): void
    {
        try {
            $owner = $this->run('SELECT IS_USED_LOCK(?)', [$this->lockName($unit)])->fetchColumn();
            $connection = (int) $this->run('SELECT CONNECTION_ID()')->fetchColumn();
        } catch (\PDOException $error) {
            throw new \Latra\Domain\Exception\OwnershipLost('Relay delivery lock connection was lost', 0, $error);
        }
        if ($this->ownedUnit !== $unit || $this->connectionId !== $connection || (int) $owner !== $connection) {
            throw new \Latra\Domain\Exception\OwnershipLost('Relay delivery lock ownership was lost');
        }
    }

    public function releaseUnitLock(string $unit): void
    {
        if ($this->ownedUnit !== $unit) return;
        try {
            // Never reconnect to release a lock. A dead connection releases its own locks.
            $this->run('SELECT RELEASE_LOCK(?)', [$this->lockName($unit)]);
        } catch (\PDOException) {
            // Preserve the original dispatch/finalization failure.
        } finally {
            $this->ownedUnit = null;
            $this->connectionId = null;
        }
    }

    public function claimPending(
        string $unit,
        int $now,
        int $maximumAttempts,
        ?callable $canSend = null,
    ): DeliveryClaim {
        $this->assertUnitLock($unit);
        $this->database->beginTransaction();
        try {
            $this->run('SELECT unit FROM relay_units WHERE unit=? FOR UPDATE', [$unit])->fetchColumn();
            $pendingReport = $this->run(
                'SELECT * FROM relay_pending WHERE unit=? ORDER BY source_time, id LIMIT 1',
                [$unit]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$pendingReport) {
                return $this->commitClaim(DeliveryClaimStatus::Empty);
            }

            if ($pendingReport['status'] !== 'pending' || (int) $pendingReport['next_attempt'] > $now) {
                return $this->commitClaim(DeliveryClaimStatus::Blocked);
            }

            if ($canSend !== null) {
                $eligible = $canSend($pendingReport);
                $this->assertUnitLock($unit);
                if (!$eligible) return $this->commitClaim(DeliveryClaimStatus::Deferred);
            }

            if ((int) $pendingReport['attempts'] >= $maximumAttempts) {
                $this->run(
                    "UPDATE relay_pending SET status='review', next_attempt=NULL, last_error='Attempt budget exhausted' WHERE id=?",
                    [$pendingReport['id']]
                );
                $this->run(
                    "UPDATE relay_identity SET state='review' WHERE delivery_key=?",
                    [$pendingReport['delivery_key']]
                );
                return $this->commitClaim(DeliveryClaimStatus::Review);
            }

            $this->assertUnitLock($unit);
            $this->run('UPDATE relay_pending SET attempts=attempts+1 WHERE id=?', [$pendingReport['id']]);
            $this->run(
                'UPDATE relay_units SET attempted_time=GREATEST(COALESCE(attempted_time,0),?) WHERE unit=?',
                [$pendingReport['source_time'], $unit]
            );
            $this->database->commit();
            return new DeliveryClaim(DeliveryClaimStatus::Ready, $pendingReport);
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    public function scheduleRetry(int $pendingId, string $deliveryKey, string $status, ?int $nextAttempt, string $error): void
    {
        $this->inTransaction(function () use ($pendingId, $deliveryKey, $status, $nextAttempt, $error): void {
            $this->run(
                'UPDATE relay_pending SET status=?, next_attempt=?, last_error=? WHERE id=?',
                [$status, $nextAttempt, $error, $pendingId]
            );
            $this->run('UPDATE relay_identity SET state=? WHERE delivery_key=?', [$status, $deliveryKey]);
        });
    }

    public function completeAccepted(string $unit, array $pendingReport): void
    {
        $this->inTransaction(function () use ($unit, $pendingReport): void {
            $this->run(
                "UPDATE relay_identity SET state='delivered' WHERE delivery_key=?",
                [$pendingReport['delivery_key']]
            );
            $this->run(
                'UPDATE relay_units SET delivered_time=GREATEST(COALESCE(delivered_time,0),?) WHERE unit=?',
                [$pendingReport['source_time'], $unit]
            );
            $this->run('DELETE FROM relay_pending WHERE id=?', [$pendingReport['id']]);
            if ($this->cleanupHook !== null) {
                ($this->cleanupHook)('cleanup');
            }
        });
    }

    private function commitClaim(DeliveryClaimStatus $status): DeliveryClaim
    {
        $this->database->commit();
        return new DeliveryClaim($status);
    }

    private function inTransaction(callable $operation): void
    {
        $this->assertUnitLock($this->ownedUnit ?? '');
        $this->database->beginTransaction();
        try {
            $operation();
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function run(string $sql, array $values = []): PDOStatement
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($values);
        return $statement;
    }

    private function lockName(string $unit): string
    {
        return 'relay:' . substr(hash('sha256', $unit), 0, 56);
    }
}
