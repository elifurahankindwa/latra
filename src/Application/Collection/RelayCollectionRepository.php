<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

interface RelayCollectionRepository
{
    /** Own a new transaction and lock the shared vehicle row before its stream row. */
    public function beginWindow(string $unit, string $vehicleUnit): array;
    public function capacity(string $unit, string $vehicleUnit): array;
    public function hasIdentity(string $key): bool;
    public function createIdentity(string $key, string $unit, int $time, string $sourceKey): int;
    public function setIdentityState(string $key, string $state): void;
    public function knownActivities(string $sourceKey): array;
    public function addActivityIdentity(string $sourceKey, int $activity, string $key): void;
    public function addPending(string $key, string $unit, int $time, string $body, string $status, ?string $error): void;
    public function saveCheckpoint(string $unit, int $through, array $mappingState): void;
    public function commitWindow(): void;
    public function rollbackWindow(): void;
}
