<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use Closure;
use InvalidArgumentException;
use Latra\Domain\Collection\RelayCollectionPolicy;
use RuntimeException;
use Throwable;

final class PersistRelayWindow implements RelayWindowCollector
{
    private ?Closure $fault;

    public function __construct(
        private RelayCollectionRepository $repository,
        private int $maximumPendingRecords,
        private int $maximumPendingBytes,
        ?callable $fault = null,
    ) {
        if (min($maximumPendingRecords, $maximumPendingBytes) < 1) {
            throw new InvalidArgumentException('Explicit positive collection capacity required');
        }
        $this->fault = $fault === null ? null : Closure::fromCallable($fault);
    }

    public function collectWindow(
        string $unit,
        int $from,
        int $to,
        int $pageSize,
        int $maxRecords,
        int $maxBytes,
        callable $fetchPage,
        callable $map,
    ): array {
        RelayCollectionPolicy::assertWindow($unit, $from, $to, $pageSize, $maxRecords, $maxBytes);
        $vehicleUnit = RelayCollectionPolicy::vehicleUnit($unit);
        $state = $this->repository->beginWindow($unit, $vehicleUnit);
        try {
            return $this->collectInTransaction(
                $unit, $vehicleUnit, $from, $to, $pageSize, $maxRecords, $maxBytes, $fetchPage, $map, $state,
            );
        } catch (Throwable $error) {
            $this->repository->rollbackWindow();
            throw $error;
        }
    }

    private function collectInTransaction(
        string $unit,
        string $vehicleUnit,
        int $from,
        int $to,
        int $pageSize,
        int $maxRecords,
        int $maxBytes,
        callable $fetchPage,
        callable $map,
        array $state,
    ): array {
        RelayCollectionPolicy::assertContiguous($state, $from);
        $mappingState = json_decode($state['state_json'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $messages = $this->readPages($from, $to, $pageSize, $maxRecords, $maxBytes, $fetchPage);
        usort($messages, static fn(array $a, array $b): int =>
            ($a['time'] <=> $b['time']) ?: strcmp($a['raw'], $b['raw']));

        $occurrences = [];
        $added = 0;
        $rejected = $skipped = 0;
        $mappedBytes = 0;
        $capacity = $this->repository->capacity($unit, $vehicleUnit);

        foreach ($messages as $entry) {
            $hash = hash('sha256', $entry['raw']);
            $ordinal = $occurrences[$hash] = ($occurrences[$hash] ?? 0) + 1;
            $key = hash('sha256', $unit . ':' . $hash . ':' . $ordinal);
            $sourceKey = hash('sha256', $vehicleUnit . ':' . $hash . ':' . $ordinal);
            if ($this->repository->hasIdentity($key)) {
                $skipped++;
                continue;
            }

            $identity = $this->repository->createIdentity($key, $unit, $entry['time'], $sourceKey);
            [$status, $error, $body, $nextState, $newActivities, $isSkipped] =
                $this->mapEntry($entry, $state, $mappingState, $identity, $sourceKey, $key, $map);
            if ($isSkipped) {
                $mappingState = $nextState;
                $skipped++;
                continue;
            }

            $mappedBytes += strlen($body);
            if ($mappedBytes > $maxBytes) {
                throw new RuntimeException('Mapped payload exceeds byte bound');
            }
            if ((int) $capacity['records'] + $added + 1 > $this->maximumPendingRecords
                || (int) $capacity['bytes'] + $mappedBytes > $this->maximumPendingBytes) {
                throw new RuntimeException('Pending capacity reached; retain window for later recollection');
            }

            $this->repository->setIdentityState($key, $status);
            if ($status === 'pending') {
                foreach ($newActivities as $activity) {
                    $this->repository->addActivityIdentity($sourceKey, $activity, $key);
                }
            }
            $mappingState = $nextState;
            $this->repository->addPending($key, $unit, $entry['time'], $body, $status, $error);
            $added++;
            if ($status === 'review') {
                $rejected++;
            }
            $this->hit('intent');
        }

        $this->repository->saveCheckpoint($unit, max($to, (int) ($state['collected_through'] ?? 0)), $mappingState);
        $this->hit('checkpoint');
        $this->repository->commitWindow();

        return [
            'fetched' => count($messages),
            'queued' => $added,
            'inserted' => $added - $rejected,
            'rejected' => $rejected,
            'skipped' => $skipped,
        ];
    }

    private function readPages(
        int $from,
        int $to,
        int $pageSize,
        int $maxRecords,
        int $maxBytes,
        callable $fetchPage,
    ): array {
        $total = null;
        $messages = [];
        $bytes = 0;
        for ($offset = 0; $total === null || $offset < $total; $offset += $pageSize) {
            $page = $fetchPage($offset, $pageSize);
            if (!is_array($page) || !is_int($page['count'] ?? null) || $page['count'] < 0
                || !is_array($page['messages'] ?? null) || !array_is_list($page['messages'])) {
                throw new RuntimeException('Invalid source page');
            }
            $total ??= $page['count'];
            if ($total > $maxRecords || $page['count'] !== $total
                || count($page['messages']) !== min($pageSize, $total - $offset)) {
                throw new RuntimeException('Incomplete, changed or over-bound source window');
            }
            foreach ($page['messages'] as $message) {
                if (!is_array($message) || !is_int($message['t'] ?? null)
                    || $message['t'] < $from || $message['t'] > $to) {
                    throw new RuntimeException('Invalid source time');
                }
                $raw = json_encode(RelayCollectionPolicy::canonical($message), JSON_THROW_ON_ERROR);
                $bytes += strlen($raw);
                if ($bytes > $maxBytes) {
                    throw new RuntimeException('Source byte bound exceeded');
                }
                $messages[] = ['time' => $message['t'], 'raw' => $raw, 'message' => $message];
            }
        }
        return $messages;
    }

    private function mapEntry(
        array $entry,
        array $state,
        array $mappingState,
        int $identity,
        string $sourceKey,
        string $key,
        callable $map,
    ): array {
        $status = 'pending';
        $error = null;
        $nextState = $mappingState;
        $newActivities = [];
        try {
            RelayCollectionPolicy::assertNotLate($state, $entry['time']);
            $mapped = $map($entry['message'], $mappingState);
            $payload = $mapped['payload'] ?? null;
            $nextState = $mapped['state'] ?? $mappingState;
            if (($mapped['suppressed'] ?? false) === true && $payload === null && is_array($nextState)) {
                $this->repository->setIdentityState($key, 'suppressed');
                return [$status, $error, '', $nextState, [], true];
            }
            if (!is_array($payload) || !is_array($payload['items'] ?? null) || !$payload['items']
                || !is_array($nextState) || $identity > intdiv(PHP_INT_MAX - 31, 32)) {
                throw new RuntimeException('Invalid mapped payload');
            }
            [$payload, $newActivities] = RelayCollectionPolicy::preparePayload(
                $payload, $sourceKey, $identity, $this->repository->knownActivities($sourceKey),
            );
            if (!$payload['items']) {
                $this->repository->setIdentityState($key, 'suppressed');
                return [$status, $error, '', $nextState, [], true];
            }
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $status = 'review';
            $error = 'Mapping or late source requires review';
            $body = $entry['raw'];
            $nextState = $mappingState;
        }
        return [$status, $error, $body, $nextState, $newActivities, false];
    }

    private function hit(string $stage): void
    {
        if ($this->fault !== null) {
            ($this->fault)($stage);
        }
    }
}
