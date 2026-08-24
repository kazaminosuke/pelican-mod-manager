<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\BulkUpdateInstalledProjects;
use Kazaminosuke\ModManager\Jobs\ResetInstalledMetadata;
use Kazaminosuke\ModManager\Jobs\ScanInstalledProjects;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Throwable;

final class InstalledOperationManager
{
    public const OPERATION_SCAN = 'scan';

    public const OPERATION_BULK_UPDATE = 'bulk_update';

    private const CACHE_PREFIX = 'mod_manager_operation:v1';

    private const CACHE_TTL_MINUTES = 120;

    /**
     * Persist running progress at most this often. The first update and the
     * terminal complete/fail write are always flushed immediately.
     */
    private const PROGRESS_FLUSH_SECONDS = 1.5;

    /**
     * In-process coalescing buffer keyed by cache key. A bulk update of
     * hundreds of files would otherwise GET+PUT Redis on every item.
     *
     * @var array<string, array{state: InstalledOperationState, flushed_at: float}>
     */
    private array $progressBuffer = [];

    private readonly InstalledOperationLease $leases;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        ?InstalledOperationLease $leases = null,
    ) {
        $this->leases = $leases ?? new InstalledOperationLease($cache);
    }

    public function supportsAsyncDispatch(): bool
    {
        return !in_array(
            (string) $this->config->get('queue.default', 'sync'),
            ['sync', 'null'],
            true,
        );
    }

    /**
     * The manager never falls back to synchronous execution. Callers can use
     * the reason to show an operator-facing queue configuration warning.
     *
     * @return array{dispatched: bool, reason: null|'already_active'|'sync_queue'|'dispatch_failed'|'missing_actor'|'unsupported_type', state: ?InstalledOperationState}
     */
    public function dispatchScan(
        Server|int $server,
        ProjectType $projectType,
        bool $force = false,
        ?int $actorUserId = null,
    ): array {
        $serverId = $this->serverId($server);

        if (!$projectType->usesArchiveMetadata()) {
            return [
                'dispatched' => false,
                'reason' => 'unsupported_type',
                'state' => null,
            ];
        }

        $current = $this->operationStateOrActive($serverId, $projectType, self::OPERATION_SCAN);

        if ($actorUserId === null || $actorUserId <= 0) {
            return [
                'dispatched' => false,
                'reason' => 'missing_actor',
                'state' => $current,
            ];
        }

        if ($current?->isActive()) {
            return [
                'dispatched' => false,
                'reason' => 'already_active',
                'state' => $current,
            ];
        }

        if (!$this->supportsAsyncDispatch()) {
            return [
                'dispatched' => false,
                'reason' => 'sync_queue',
                'state' => $current,
            ];
        }

        $leaseToken = $this->leases->tryAcquire(
            $serverId,
            $projectType,
            InstalledOperationLease::OPERATION_SCAN,
        );

        if ($leaseToken === null) {
            return [
                'dispatched' => false,
                'reason' => 'already_active',
                'state' => $current,
            ];
        }

        $state = $current;

        try {
            $state = $this->queue($serverId, $projectType, self::OPERATION_SCAN, [
                'force' => $force,
                'actor_user_id' => $actorUserId,
            ]);
            // PendingDispatch acquires the ShouldBeUnique lock before the
            // dispatcher sees the job. Calling Dispatcher::dispatch() here
            // would queue duplicate scan jobs under concurrent requests.
            ScanInstalledProjects::dispatch(
                serverId: $serverId,
                projectType: $projectType->value,
                leaseToken: $leaseToken,
                force: $force,
                actorUserId: $actorUserId,
            );
        } catch (Throwable $exception) {
            report($exception);

            try {
                $state = $this->fail(
                    $serverId,
                    $projectType,
                    self::OPERATION_SCAN,
                    'dispatch_failed',
                    leaseToken: $leaseToken,
                );
            } catch (Throwable $stateException) {
                report($stateException);
            }

            return [
                'dispatched' => false,
                'reason' => 'dispatch_failed',
                'state' => $state,
            ];
        }

        return [
            'dispatched' => true,
            'reason' => null,
            'state' => $state,
        ];
    }

    public function state(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
    ): ?InstalledOperationState {
        $serverId = $this->serverId($server);
        $key = $this->cacheKey($serverId, $projectType, $operation);

        if (isset($this->progressBuffer[$key])) {
            return $this->progressBuffer[$key]['state'];
        }

        return InstalledOperationState::fromCachePayload($this->cache->get($key));
    }

    /**
     * One cache round trip for several operation keys (Redis MGET).
     *
     * @param  array<int, string>  $operations
     * @return array<string, InstalledOperationState|null>
     */
    public function states(
        Server|int $server,
        ProjectType $projectType,
        array $operations,
    ): array {
        $serverId = $this->serverId($server);
        $keys = [];

        foreach ($operations as $operation) {
            $keys[$operation] = $this->cacheKey($serverId, $projectType, $operation);
        }

        $payloads = $this->cache->many(array_values($keys));
        $states = [];

        foreach ($keys as $operation => $key) {
            $states[$operation] = isset($this->progressBuffer[$key])
                ? $this->progressBuffer[$key]['state']
                : InstalledOperationState::fromCachePayload($payloads[$key] ?? null);
        }

        $hasActiveState = false;
        foreach ($states as $state) {
            if ($state?->isActive()) {
                $hasActiveState = true;

                break;
            }
        }

        if ($hasActiveState) {
            $leaseOperation = $this->leases->currentOperation($serverId, $projectType);

            foreach ($states as $operation => $state) {
                $states[$operation] = $this->stateMatchingLease($state, $leaseOperation);
            }
        }

        return $states;
    }

    /**
     * Scan result plus scan/bulk operation state in one cache round trip.
     *
     * @return array{scan_result: mixed, scan: InstalledOperationState|null, bulk: InstalledOperationState|null}
     */
    public function installedTabCacheSnapshot(
        Server|int $server,
        ProjectType $projectType,
        string $scanResultCacheKey,
    ): array {
        $serverId = $this->serverId($server);
        $scanKey = $this->cacheKey($serverId, $projectType, self::OPERATION_SCAN);
        $bulkKey = $this->cacheKey($serverId, $projectType, self::OPERATION_BULK_UPDATE);
        $payloads = $this->cache->many([$scanResultCacheKey, $scanKey, $bulkKey]);

        $scanState = isset($this->progressBuffer[$scanKey])
            ? $this->progressBuffer[$scanKey]['state']
            : InstalledOperationState::fromCachePayload($payloads[$scanKey] ?? null);
        $bulkState = isset($this->progressBuffer[$bulkKey])
            ? $this->progressBuffer[$bulkKey]['state']
            : InstalledOperationState::fromCachePayload($payloads[$bulkKey] ?? null);

        if ($scanState?->isActive() || $bulkState?->isActive()) {
            $leaseOperation = $this->leases->currentOperation($serverId, $projectType);
            $scanState = $this->stateMatchingLease($scanState, $leaseOperation);
            $bulkState = $this->stateMatchingLease($bulkState, $leaseOperation);
        }

        return [
            'scan_result' => $payloads[$scanResultCacheKey] ?? null,
            'scan' => $scanState,
            'bulk' => $bulkState,
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    public function queue(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
        array $result = [],
    ): InstalledOperationState {
        return $this->put(InstalledOperationState::queued(
            operation: $operation,
            serverId: $this->serverId($server),
            projectType: $projectType,
            result: $result,
        ));
    }

    public function start(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
        ?int $total = null,
    ): InstalledOperationState {
        $serverId = $this->serverId($server);
        $state = $this->state($serverId, $projectType, $operation)
            ?? InstalledOperationState::queued($operation, $serverId, $projectType);

        return $this->put($state->running($total));
    }

    public function progress(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
        int $progress,
        ?int $total = null,
    ): InstalledOperationState {
        $serverId = $this->serverId($server);
        $key = $this->cacheKey($serverId, $projectType, $operation);
        $state = ($this->progressBuffer[$key]['state'] ?? $this->state($serverId, $projectType, $operation))
            ?? InstalledOperationState::queued($operation, $serverId, $projectType);
        $state = $state->withProgress($progress, $total);
        $flushedAt = $this->progressBuffer[$key]['flushed_at'] ?? null;
        $now = microtime(true);

        if ($flushedAt === null || ($now - $flushedAt) >= self::PROGRESS_FLUSH_SECONDS) {
            $this->put($state);
            $this->progressBuffer[$key] = [
                'state' => $state,
                'flushed_at' => $now,
            ];

            return $state;
        }

        $this->progressBuffer[$key] = [
            'state' => $state,
            'flushed_at' => $flushedAt,
        ];

        return $state;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function defer(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
        array $result = [],
    ): InstalledOperationState {
        $serverId = $this->serverId($server);
        $state = $this->state($serverId, $projectType, $operation)
            ?? InstalledOperationState::queued($operation, $serverId, $projectType);

        return $this->put($state->requeue($result));
    }

    /**
     * @param array<string, mixed> $result
     */
    public function complete(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
        array $result = [],
        ?string $leaseToken = null,
    ): InstalledOperationState {
        $serverId = $this->serverId($server);

        if ($leaseToken !== null && !$this->leases->owns($serverId, $projectType, $leaseToken)) {
            return $this->state($serverId, $projectType, $operation)
                ?? InstalledOperationState::queued($operation, $serverId, $projectType);
        }

        try {
            $state = $this->state($serverId, $projectType, $operation)
                ?? InstalledOperationState::queued($operation, $serverId, $projectType);

            return $this->put($state->completed($result));
        } finally {
            if ($leaseToken !== null) {
                $this->leases->release($serverId, $projectType, $leaseToken);
            }
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    public function fail(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
        string $error,
        array $result = [],
        ?string $leaseToken = null,
    ): InstalledOperationState {
        $serverId = $this->serverId($server);

        if ($leaseToken !== null && !$this->leases->owns($serverId, $projectType, $leaseToken)) {
            return $this->state($serverId, $projectType, $operation)
                ?? InstalledOperationState::queued($operation, $serverId, $projectType);
        }

        try {
            $state = $this->state($serverId, $projectType, $operation)
                ?? InstalledOperationState::queued($operation, $serverId, $projectType);

            return $this->put($state->failed($error, $result));
        } finally {
            if ($leaseToken !== null) {
                $this->leases->release($serverId, $projectType, $leaseToken);
            }
        }
    }

    public function forget(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
    ): void {
        $serverId = $this->serverId($server);
        $key = $this->cacheKey($serverId, $projectType, $operation);
        unset($this->progressBuffer[$key]);
        $this->cache->forget($key);
    }

    private function operationStateOrActive(
        int $serverId,
        ProjectType $projectType,
        string $requestedOperation,
    ): ?InstalledOperationState {
        $leaseOperation = null;
        $current = $this->state($serverId, $projectType, $requestedOperation);

        if ($current?->isActive()) {
            $leaseOperation = $this->leases->currentOperation($serverId, $projectType);
            $current = $this->stateMatchingLease($current, $leaseOperation);

            if ($current !== null) {
                return $current;
            }
        }

        $otherOperation = $requestedOperation === self::OPERATION_SCAN
            ? self::OPERATION_BULK_UPDATE
            : self::OPERATION_SCAN;
        $other = $this->state($serverId, $projectType, $otherOperation);

        if ($other?->isActive()) {
            $leaseOperation ??= $this->leases->currentOperation($serverId, $projectType);
            $other = $this->stateMatchingLease($other, $leaseOperation);
        }

        return $other?->isActive() ? $other : $current;
    }

    private function stateMatchingLease(
        ?InstalledOperationState $state,
        ?string $leaseOperation,
    ): ?InstalledOperationState {
        if (!$state?->isActive()) {
            return $state;
        }

        $matches = match ($state->operation) {
            self::OPERATION_SCAN => in_array($leaseOperation, [
                InstalledOperationLease::OPERATION_SCAN,
                InstalledOperationLease::OPERATION_CLEAR,
            ], true),
            self::OPERATION_BULK_UPDATE => $leaseOperation === InstalledOperationLease::OPERATION_BULK_UPDATE,
            default => false,
        };

        return $matches ? $state : null;
    }

    private function put(InstalledOperationState $state): InstalledOperationState
    {
        $key = $this->cacheKey($state->serverId, $state->projectType, $state->operation);
        unset($this->progressBuffer[$key]);
        $this->cache->put(
            $key,
            $state->toCachePayload(),
            new DateTimeImmutable('+'.self::CACHE_TTL_MINUTES.' minutes'),
        );

        return $state;
    }

    private function cacheKey(
        int $serverId,
        ProjectType $projectType,
        string $operation,
    ): string {
        return implode(':', [
            self::CACHE_PREFIX,
            $serverId,
            $projectType->value,
            $operation,
        ]);
    }

    private function serverId(Server|int $server): int
    {
        $serverId = $server instanceof Server ? (int) $server->getKey() : $server;

        if ($serverId < 1) {
            throw new \InvalidArgumentException('The installed operation server ID must be positive.');
        }

        return $serverId;
    }

    /**
     * @return array{dispatched: bool, reason: null|'already_active'|'sync_queue'|'dispatch_failed'|'unsupported_type', state: ?InstalledOperationState}
     */
    public function dispatchBulkUpdate(
        Server|int $server,
        ProjectType $projectType,
    ): array {
        $serverId = $this->serverId($server);

        if (!$projectType->usesArchiveMetadata()) {
            return [
                'dispatched' => false,
                'reason' => 'unsupported_type',
                'state' => null,
            ];
        }

        $current = $this->operationStateOrActive($serverId, $projectType, self::OPERATION_BULK_UPDATE);

        if ($current?->isActive()) {
            return [
                'dispatched' => false,
                'reason' => 'already_active',
                'state' => $current,
            ];
        }

        if (!$this->supportsAsyncDispatch()) {
            return [
                'dispatched' => false,
                'reason' => 'sync_queue',
                'state' => $current,
            ];
        }

        $leaseToken = $this->leases->tryAcquire(
            $serverId,
            $projectType,
            InstalledOperationLease::OPERATION_BULK_UPDATE,
        );

        if ($leaseToken === null) {
            return [
                'dispatched' => false,
                'reason' => 'already_active',
                'state' => $current,
            ];
        }

        $state = $current;

        try {
            $state = $this->queue($serverId, $projectType, self::OPERATION_BULK_UPDATE);
            // See dispatchScan(): the static Dispatchable path is required
            // for Laravel to acquire this job's ShouldBeUnique lock.
            BulkUpdateInstalledProjects::dispatch(
                serverId: $serverId,
                projectType: $projectType->value,
                leaseToken: $leaseToken,
            );
        } catch (Throwable $exception) {
            report($exception);

            try {
                $state = $this->fail(
                    $serverId,
                    $projectType,
                    self::OPERATION_BULK_UPDATE,
                    'dispatch_failed',
                    leaseToken: $leaseToken,
                );
            } catch (Throwable $stateException) {
                report($stateException);
            }

            return [
                'dispatched' => false,
                'reason' => 'dispatch_failed',
                'state' => $state,
            ];
        }

        return [
            'dispatched' => true,
            'reason' => null,
            'state' => $state,
        ];
    }

    /**
     * Queue one authorization-gated metadata reset for all requested archive
     * types. Every long operation lease is claimed before any state is queued
     * or metadata is deleted; a failed claim rolls the earlier claims back.
     *
     * @param  array<int, ProjectType>  $projectTypes
     * @return array{dispatched: bool, reason: null|'already_active'|'sync_queue'|'dispatch_failed'|'missing_actor'|'unsupported_type'|'no_types', states: array<string, InstalledOperationState>}
     */
    public function dispatchMetadataReset(
        Server|int $server,
        array $projectTypes,
        ?int $actorUserId = null,
    ): array {
        $serverId = $this->serverId($server);
        $types = [];

        foreach ($projectTypes as $type) {
            if (!$type instanceof ProjectType || !$type->usesArchiveMetadata()) {
                return [
                    'dispatched' => false,
                    'reason' => 'unsupported_type',
                    'states' => [],
                ];
            }

            $types[$type->value] = $type;
        }

        ksort($types, SORT_STRING);

        if ($types === []) {
            return [
                'dispatched' => false,
                'reason' => 'no_types',
                'states' => [],
            ];
        }

        if ($actorUserId === null || $actorUserId <= 0) {
            return [
                'dispatched' => false,
                'reason' => 'missing_actor',
                'states' => [],
            ];
        }

        if (!$this->supportsAsyncDispatch()) {
            return [
                'dispatched' => false,
                'reason' => 'sync_queue',
                'states' => [],
            ];
        }

        foreach ($types as $type) {
            if ($this->operationStateOrActive($serverId, $type, self::OPERATION_SCAN)?->isActive()) {
                return [
                    'dispatched' => false,
                    'reason' => 'already_active',
                    'states' => [],
                ];
            }
        }

        $leaseTokens = $this->leases->tryAcquireMany(
            $serverId,
            array_values($types),
            InstalledOperationLease::OPERATION_CLEAR,
        );

        if ($leaseTokens === null) {
            return [
                'dispatched' => false,
                'reason' => 'already_active',
                'states' => [],
            ];
        }

        $states = [];

        try {
            foreach ($types as $typeValue => $type) {
                $states[$typeValue] = $this->queue($serverId, $type, self::OPERATION_SCAN, [
                    'force' => true,
                    'metadata_reset' => true,
                    'actor_user_id' => $actorUserId,
                ]);
            }

            ResetInstalledMetadata::dispatch(
                serverId: $serverId,
                projectTypes: array_keys($types),
                leaseTokens: $leaseTokens,
                actorUserId: $actorUserId,
            );
        } catch (Throwable $exception) {
            report($exception);

            foreach ($types as $typeValue => $type) {
                try {
                    $states[$typeValue] = $this->fail(
                        $serverId,
                        $type,
                        self::OPERATION_SCAN,
                        'dispatch_failed',
                        leaseToken: $leaseTokens[$typeValue],
                    );
                } catch (Throwable $stateException) {
                    report($stateException);
                }
            }

            return [
                'dispatched' => false,
                'reason' => 'dispatch_failed',
                'states' => $states,
            ];
        }

        return [
            'dispatched' => true,
            'reason' => null,
            'states' => $states,
        ];
    }
}
