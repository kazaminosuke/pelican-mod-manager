<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\InstalledScanResult;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Throwable;

final class InstalledOperationManager
{
    public const OPERATION_SCAN = 'scan';

    public const OPERATION_BULK_UPDATE = 'bulk_update';

    public const OPERATION_MODPACK = 'modpack_install';

    private const CACHE_PREFIX = 'mod_manager_operation:v1';

    private const CACHE_TTL_MINUTES = 120;

    /**
     * Crash recovery for a queued job whose row was lost. It matches the
     * operation lease TTL; a started job releases the marker itself.
     */
    private const PENDING_DISPATCH_TTL_SECONDS = InstalledOperationLease::TTL_SECONDS;

    /** Minimum spacing between automatic (page-triggered) scans. */
    public const AUTOMATIC_SCAN_INTERVAL_SECONDS = 300;

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

    private readonly PluginBackgroundRunner $runner;

    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
        ?InstalledOperationLease $leases = null,
        ?PluginBackgroundRunner $runner = null,
    ) {
        unset($config);
        $this->leases = $leases ?? new InstalledOperationLease($cache);
        $this->runner = $runner ?? self::resolveRunner();
    }

    /**
     * Whether persisted background work can be processed later by
     * `schedule:run`. This is independent of Laravel's queue driver.
     * Mod Manager never serializes Plugin job classes onto a long-running
     * worker, and it never falls back to running a Wings scan inside the
     * HTTP request.
     */
    public function supportsAsyncDispatch(): bool
    {
        return $this->runner->canSpawn();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function startBackgroundJob(
        string $type,
        array $payload,
        ?string $uniqueId = null,
        int $uniqueFor = 0,
    ): bool {
        return $this->runner->run($type, $payload, $uniqueId, $uniqueFor);
    }

    public function backgroundRunner(): PluginBackgroundRunner
    {
        return $this->runner;
    }

    private static function resolveRunner(): PluginBackgroundRunner
    {
        $app = Container::getInstance();

        if (!is_object($app) || !method_exists($app, 'bound') || !$app->bound(PluginBackgroundRunner::class)) {
            return PluginBackgroundRunner::disabled();
        }

        try {
            $runner = $app->make(PluginBackgroundRunner::class);
        } catch (Throwable) {
            return PluginBackgroundRunner::disabled();
        }

        return $runner instanceof PluginBackgroundRunner
            ? $runner
            : PluginBackgroundRunner::disabled();
    }

    /**
     * The manager never falls back to synchronous execution. Callers can use
     * the reason to show an operator-facing queue configuration warning.
     *
     * A queued scan does not hold the installed-file operation lease. The
     * job only claims that lease once `schedule:run` starts it, so installs,
     * updates, and removals stay available while a scan waits in the queue.
     * Duplicate scans are coalesced by a pending-dispatch marker instead.
     *
     * A background scan revalidates data the page already shows and is kept
     * out of the UI. An explicit request for a scan that is already queued in
     * the background makes that scan visible instead of being refused.
     *
     * @return array{dispatched: bool, reason: null|'already_active'|'sync_queue'|'dispatch_failed'|'missing_actor'|'unsupported_type', state: ?InstalledOperationState}
     */
    public function dispatchScan(
        Server|int $server,
        ProjectType $projectType,
        bool $force = false,
        ?int $actorUserId = null,
        bool $background = false,
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
            if (!$background
                && $current->operation === self::OPERATION_SCAN
                && $current->isBackground()) {
                return [
                    'dispatched' => true,
                    'reason' => null,
                    'state' => $this->put($current->withResult([
                        ...$current->result,
                        'background' => false,
                        'force' => $force || ($current->result['force'] ?? false) === true,
                    ])),
                ];
            }

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

        $dispatchToken = bin2hex(random_bytes(16));
        $pendingKey = $this->pendingKey($serverId, $projectType, self::OPERATION_SCAN);

        if (!$this->cache->add($pendingKey, $dispatchToken, self::PENDING_DISPATCH_TTL_SECONDS)) {
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
                'background' => $background,
            ]);
            if (!$this->runner->run(
                BackgroundJob::SCAN,
                [
                    'server_id' => $serverId,
                    'project_type' => $projectType->value,
                    'dispatch_token' => $dispatchToken,
                    'force' => $force,
                    'actor_user_id' => $actorUserId,
                ],
            )) {
                throw new \RuntimeException('Unable to start the installed-file scan process.');
            }
        } catch (Throwable $exception) {
            report($exception);

            try {
                $state = $this->fail(
                    $serverId,
                    $projectType,
                    self::OPERATION_SCAN,
                    'dispatch_failed',
                );
            } catch (Throwable $stateException) {
                report($stateException);
            } finally {
                $this->releasePendingDispatch($serverId, $projectType, self::OPERATION_SCAN, $dispatchToken);
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
     * Automatic scan for a page render. Nothing is queued while the cached
     * result is fresh. A missing result queues a visible scan; a stale one a
     * background revalidation that keeps the current data on screen.
     *
     * Automatic attempts are throttled per server/type, so a scan that keeps
     * failing (Wings offline, upstream outage) is retried every few minutes
     * instead of being queued again on every render and poll.
     *
     * @return array{dispatched: bool, reason: null|'already_active'|'sync_queue'|'dispatch_failed'|'missing_actor'|'unsupported_type', state: ?InstalledOperationState}|null
     *         null when no scan was due
     */
    public function dispatchScanIfDue(
        Server|int $server,
        ProjectType $projectType,
        ?InstalledScanResult $cachedResult,
        ?int $actorUserId,
    ): ?array {
        if ($cachedResult?->isFresh() || !$projectType->usesArchiveMetadata()) {
            return null;
        }

        $serverId = $this->serverId($server);
        $throttleKey = $this->cacheKey($serverId, $projectType, self::OPERATION_SCAN).':auto';

        if (!$this->cache->add($throttleKey, true, self::AUTOMATIC_SCAN_INTERVAL_SECONDS)) {
            return null;
        }

        $dispatch = $this->dispatchScan(
            $serverId,
            $projectType,
            actorUserId: $actorUserId,
            background: $cachedResult !== null,
        );

        // Only a scan that was queued (now or earlier) should hold back the
        // next page load; a bulk update in progress must not delay it.
        $scanQueued = $dispatch['dispatched']
            || ($dispatch['reason'] === 'already_active'
                && $dispatch['state']?->operation === self::OPERATION_SCAN);

        if (!$scanQueued) {
            $this->cache->forget($throttleKey);
        }

        return $dispatch;
    }

    /**
     * Claim the operation lease for a queued scan that `schedule:run` has
     * just started.
     *
     * - `stale`: the dispatch was superseded or already finished, so this
     *   job must not do any work.
     * - `busy`: another managed operation (an install, update, removal,
     *   bulk update, or metadata reset) holds the lease; the job should be
     *   released back to the queue and retried later.
     * - `claimed`: the job owns the lease and must finish with complete()
     *   or fail() using `lease_token`, then releasePendingDispatch().
     *
     * @return array{status: 'stale'|'busy'|'claimed', lease_token: ?string, state: ?InstalledOperationState}
     */
    public function claimQueuedScan(int $serverId, ProjectType $projectType, string $dispatchToken): array
    {
        $state = $this->state($serverId, $projectType, self::OPERATION_SCAN);

        if (!$this->ownsPendingDispatch($serverId, $projectType, self::OPERATION_SCAN, $dispatchToken)) {
            return ['status' => 'stale', 'lease_token' => null, 'state' => $state];
        }

        $leaseToken = $this->leases->tryAcquire($serverId, $projectType, InstalledOperationLease::OPERATION_SCAN);

        return [
            'status' => $leaseToken === null ? 'busy' : 'claimed',
            'lease_token' => $leaseToken,
            'state' => $state,
        ];
    }

    public function ownsPendingDispatch(
        int $serverId,
        ProjectType $projectType,
        string $operation,
        string $dispatchToken,
    ): bool {
        $current = $this->cache->get($this->pendingKey($serverId, $projectType, $operation));

        return is_string($current) && hash_equals($current, $dispatchToken);
    }

    public function releasePendingDispatch(
        int $serverId,
        ProjectType $projectType,
        string $operation,
        string $dispatchToken,
    ): void {
        if ($this->ownsPendingDispatch($serverId, $projectType, $operation, $dispatchToken)) {
            $this->cache->forget($this->pendingKey($serverId, $projectType, $operation));
        }
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

        return $this->put($state->requeue([...$state->result, ...$result]));
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

            return $this->put($state->completed($this->withCarriedFlags($state, $result)));
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

            return $this->put($state->failed($error, $this->withCarriedFlags($state, $result)));
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
            self::OPERATION_MODPACK => $leaseOperation === InstalledOperationLease::OPERATION_MODPACK,
            default => false,
        };

        // A queued scan waits without the lease; its pending-dispatch marker
        // is what keeps it current until the job starts.
        if (!$matches
            && $state->operation === self::OPERATION_SCAN
            && $state->status === InstalledOperationState::STATUS_QUEUED) {
            $matches = $this->cache->has($this->pendingKey($state->serverId, $state->projectType, $state->operation));
        }

        return $matches ? $state : null;
    }

    /**
     * Terminal and requeued states replace the result payload. Keep the
     * flag that decides whether the page shows this operation at all.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withCarriedFlags(?InstalledOperationState $state, array $result): array
    {
        if ($state?->isBackground() && !array_key_exists('background', $result)) {
            $result['background'] = true;
        }

        return $result;
    }

    private function pendingKey(int $serverId, ProjectType $projectType, string $operation): string
    {
        return $this->cacheKey($serverId, $projectType, $operation).':pending';
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
            if (!$this->runner->run(
                BackgroundJob::BULK_UPDATE,
                [
                    'server_id' => $serverId,
                    'project_type' => $projectType->value,
                    'lease_token' => $leaseToken,
                ],
            )) {
                throw new \RuntimeException('Unable to start the bulk-update process.');
            }
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
     * Queue a modpack install. The job downloads the pack and places files.
     * Only mod servers accept it; the mod catalog itself is unchanged.
     *
     * @return array{dispatched: bool, reason: null|'already_active'|'sync_queue'|'dispatch_failed'|'unsupported_type', state: ?InstalledOperationState}
     */
    public function dispatchModpackInstall(
        Server|int $server,
        ProjectType $projectType,
        string $source,
        string $projectId,
        ?string $versionId = null,
        ?int $deferSeconds = null,
    ): array {
        $serverId = $this->serverId($server);
        $source = trim($source);
        $projectId = trim($projectId);

        if ($projectType !== ProjectType::Mod || $source === '' || $projectId === '' || str_contains($projectId, ':')) {
            return [
                'dispatched' => false,
                'reason' => 'unsupported_type',
                'state' => null,
            ];
        }

        $current = $this->operationStateOrActive($serverId, $projectType, self::OPERATION_MODPACK);
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
            InstalledOperationLease::OPERATION_MODPACK,
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
            $state = $this->queue($serverId, $projectType, self::OPERATION_MODPACK);
            if (!$this->runner->run(
                BackgroundJob::MODPACK,
                [
                    'server_id' => $serverId,
                    'project_type' => $projectType->value,
                    'source' => $source,
                    'project_id' => $projectId,
                    'lease_token' => $leaseToken,
                    'version_id' => $versionId !== null ? trim($versionId) : '',
                    'defer_until' => $deferSeconds !== null && $deferSeconds > 0 ? time() + $deferSeconds : 0,
                ],
            )) {
                throw new \RuntimeException('Unable to start the modpack install.');
            }
        } catch (Throwable $exception) {
            report($exception);

            try {
                $state = $this->fail(
                    $serverId,
                    $projectType,
                    self::OPERATION_MODPACK,
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

            if (!$this->runner->run(
                BackgroundJob::RESET_METADATA,
                [
                    'server_id' => $serverId,
                    'project_types' => array_keys($types),
                    'lease_tokens' => $leaseTokens,
                    'actor_user_id' => $actorUserId,
                ],
            )) {
                throw new \RuntimeException('Unable to start the metadata-reset process.');
            }
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
