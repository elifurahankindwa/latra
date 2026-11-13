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

    public function __construct(private PDO $database, ?callable $cleanupHook = null)
    {
        $this->cleanupHook = $cleanupHook === null ? null : Closure::fromCallable($cleanupHook);
    }

    public function acquireUnitLock(string $unit): bool
    {
        return (int) $this->run('SELECT GET_LOCK(?, 0)', [$this->lockName($unit)])->fetchColumn() === 1;
    }

    public function releaseUnitLock(string $unit): void
    {
        $this->run('SELECT RELEASE_LOCK(?)', [$this->lockName($unit)]);
    }

    public function claimPending(
        string $unit,
        int $now,
        int $maximumAttempts,
        ?callable $canSend = null,
    ): DeliveryClaim {
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

            if ($canSend !== null && !$canSend($pendingReport)) {
                return $this->commitClaim(DeliveryClaimStatus::Deferred);
            }

            if ((int) $pendingReport['attempts'] >= $maximumAttempts) {
                $this->run(
                    "UPDATE relay_pending SET status='review', last_error='Attempt budget exhausted' WHERE id=?",
                    [$pendingReport['id']]
                );
                $this->run(
                    "UPDATE relay_identity SET state='review' WHERE delivery_key=?",
                    [$pendingReport['delivery_key']]
                );
                return $this->commitClaim(DeliveryClaimStatus::Review);
            }

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
