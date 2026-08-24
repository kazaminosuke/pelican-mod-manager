<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\BulkUpdateInstalledProjects;
use Kazaminosuke\ModManager\Jobs\ScanInstalledProjects;
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

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ) {}

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
     * @return array{dispatched: bool, reason: null|'already_active'|'sync_queue'|'dispatch_failed'|'missing_actor', state: ?InstalledOperationState}
     */
    public function dispatchScan(
        Server|int $server,
        ProjectType $projectType,
        bool $force = false,
        ?int $actorUserId = null,
    ): array {
        $serverId = $this->serverId($server);
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

        $state = $this->queue($serverId, $projectType, self::OPERATION_SCAN, [
            'force' => $force,
            'actor_user_id' => $actorUserId,
        ]);

        try {
            // PendingDispatch acquires the ShouldBeUnique lock before the
            // dispatcher sees the job. Calling Dispatcher::dispatch() here
            // would queue duplicate scan jobs under concurrent requests.
            ScanInstalledProjects::dispatch(
                serverId: $serverId,
                projectType: $projectType->value,
                force: $force,
                actorUserId: $actorUserId,
            );
        } catch (Throwable $exception) {
            report($exception);

            $state = $this->fail(
                $serverId,
                $projectType,
                self::OPERATION_SCAN,
                'dispatch_failed',
            );

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

        return [
            'scan_result' => $payloads[$scanResultCacheKey] ?? null,
            'scan' => isset($this->progressBuffer[$scanKey])
                ? $this->progressBuffer[$scanKey]['state']
                : InstalledOperationState::fromCachePayload($payloads[$scanKey] ?? null),
            'bulk' => isset($this->progressBuffer[$bulkKey])
                ? $this->progressBuffer[$bulkKey]['state']
                : InstalledOperationState::fromCachePayload($payloads[$bulkKey] ?? null),
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
    ): InstalledOperationState {
        $serverId = $this->serverId($server);
        $state = $this->state($serverId, $projectType, $operation)
            ?? InstalledOperationState::queued($operation, $serverId, $projectType);

        return $this->put($state->completed($result));
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
    ): InstalledOperationState {
        $serverId = $this->serverId($server);
        $state = $this->state($serverId, $projectType, $operation)
            ?? InstalledOperationState::queued($operation, $serverId, $projectType);

        return $this->put($state->failed($error, $result));
    }

    public function forget(
        Server|int $server,
        ProjectType $projectType,
        string $operation,
    ): void {
        $key = $this->cacheKey($this->serverId($server), $projectType, $operation);
        unset($this->progressBuffer[$key]);
        $this->cache->forget($key);
    }

    private function operationStateOrActive(
        int $serverId,
        ProjectType $projectType,
        string $requestedOperation,
    ): ?InstalledOperationState {
        $current = $this->state($serverId, $projectType, $requestedOperation);

        if ($current?->isActive()) {
            return $current;
        }

        $otherOperation = $requestedOperation === self::OPERATION_SCAN
            ? self::OPERATION_BULK_UPDATE
            : self::OPERATION_SCAN;
        $other = $this->state($serverId, $projectType, $otherOperation);

        return $other?->isActive() ? $other : $current;
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
     * @return array{dispatched: bool, reason: null|'already_active'|'sync_queue'|'dispatch_failed', state: ?InstalledOperationState}
     */
    public function dispatchBulkUpdate(
        Server|int $server,
        ProjectType $projectType,
    ): array {
        $serverId = $this->serverId($server);
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

        $state = $this->queue($serverId, $projectType, self::OPERATION_BULK_UPDATE);

        try {
            // See dispatchScan(): the static Dispatchable path is required
            // for Laravel to acquire this job's ShouldBeUnique lock.
            BulkUpdateInstalledProjects::dispatch(
                serverId: $serverId,
                projectType: $projectType->value,
            );
        } catch (Throwable $exception) {
            report($exception);

            $state = $this->fail(
                $serverId,
                $projectType,
                self::OPERATION_BULK_UPDATE,
                'dispatch_failed',
            );

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
}
