<?php
/** Temporary durable delivery intent, independent of message_log retention. */
use Latra\Application\Delivery\DeliverPendingReport;
use Latra\Application\Collection\RelayWindowCollector;
use Latra\Application\Collection\RecoverRelayReviews;
use Latra\Application\Collection\PersistRelayWindow;
use Latra\Infrastructure\Persistence\PdoRelayCollectionRepository;
use Latra\Infrastructure\Persistence\PdoRelayDeliveryRepository;
use Latra\Infrastructure\Persistence\PdoRelayReviewRecoveryRepository;

final class DurableRelay implements RelayWindowCollector {
    private $fault;
    private $backoff;
    private DeliverPendingReport $delivery;
    private PersistRelayWindow $collection;
    private RecoverRelayReviews $reviewRecovery;

    public function __construct(private PDO $db, private int $maxAttempts, callable $backoff,
        private int $maxPendingRecords, private int $maxPendingBytes, ?callable $fault = null) {
        if (min($maxAttempts, $maxPendingRecords, $maxPendingBytes) < 1) {
            throw new InvalidArgumentException('Explicit positive retry/capacity budgets required');
        }
        $this->backoff = $backoff;
        $this->fault = $fault;
        $this->collection = new PersistRelayWindow(
            new PdoRelayCollectionRepository($db),
            $maxPendingRecords,
            $maxPendingBytes,
            $fault,
        );
        $this->reviewRecovery = new RecoverRelayReviews(
            new PdoRelayReviewRecoveryRepository($db, $maxPendingBytes, $fault),
        );
        $this->delivery = new DeliverPendingReport(
            new PdoRelayDeliveryRepository($db, fn(string $stage) => $this->hit($stage)),
            $maxAttempts,
            $backoff,
            fn(string $stage) => $this->hit($stage),
        );
    }

    private function run(string $sql, array $values = []): PDOStatement {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        return $stmt;
    }

    private function hit(string $stage): void {
        if ($this->fault) ($this->fault)($stage);
    }

    /** A complete bounded window, queue intent and collection watermark commit together. */
    public function collect(string $unit, int $from, int $to, int $pageSize, int $maxRecords,
        int $maxBytes, callable $fetchPage, callable $map): int {
        return $this->collectWindow($unit, $from, $to, $pageSize, $maxRecords, $maxBytes, $fetchPage, $map)['queued'];
    }

    /** Per-window counters distinguish durable review evidence from sendable intent and replay. */
    public function collectWindow(string $unit, int $from, int $to, int $pageSize, int $maxRecords,
        int $maxBytes, callable $fetchPage, callable $map): array {
        return $this->collection->collectWindow(
            $unit,
            $from,
            $to,
            $pageSize,
            $maxRecords,
            $maxBytes,
            $fetchPage,
            $map,
        );
    }

    /** Payload-free progress and queue counts for operators. */
    public function status(string $unit): array {
        $row = $this->run('SELECT collected_through, delivered_time, attempted_time FROM relay_units WHERE unit=?', [$unit])->fetch(PDO::FETCH_ASSOC);
        $counts = $this->run("SELECT COUNT(*) AS unresolved, COALESCE(SUM(status='review'),0) AS review,
            COALESCE(SUM(OCTET_LENGTH(payload)),0) AS bytes FROM relay_pending WHERE unit=?", [$unit])->fetch(PDO::FETCH_ASSOC);
        return ['progress' => $row ?: null, 'queue' => $counts];
    }

    /** Explicitly revalidate safe, never-attempted mapping-review evidence for one vehicle. */
    public function recoverReviews(string $vehicleUnit, callable $map): array {
        return $this->reviewRecovery->recover($vehicleUnit, $map);
    }

    /** One unit at a time; a failed head blocks only that unit, not collection or other units. */
    public function deliver(string $unit, int $now, callable $send, ?callable $canSend = null): string {
        return $this->delivery->deliver($unit, $now, $send, $canSend);
    }
}
