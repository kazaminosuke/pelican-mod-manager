<?php

namespace Kazaminosuke\ModManager\Support;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Exceptions\PartialSourceFetchException;
use Kazaminosuke\ModManager\Jobs\RevalidateSourceCache;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class SourceCache
{
    public const SCHEMA_VERSION = 1;

    private const FAILURE_MARKER_VERSION = 1;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly InstalledOperationManager $operations,
        private readonly SourceFetchExecutorInterface $executor,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function swr(SourceFetchSpec $spec, CacheProfile $profile): mixed
    {
        $shouldProfile = $spec->operation === 'search' && RequestPerformanceProfiler::isCapturing();
        $cacheStartedAt = $shouldProfile ? microtime(true) : 0.0;
        $status = 'MISS';
        $cacheMs = 0;

        try {
            $entry = $this->readEntry($spec);
            if ($shouldProfile) {
                $cacheMs = (int) round((microtime(true) - $cacheStartedAt) * 1000);
            }

            if ($entry !== null && $entry['fresh_until'] > time()) {
                $status = 'HIT';

                return $entry['data'];
            }

            if ($this->hasFailureMarker($spec)) {
                $status = $entry !== null ? 'STALE' : 'MISS';

                return $entry !== null ? $entry['data'] : $this->emptyResult($spec);
            }

            if ($entry !== null && $this->supportsAsyncDispatch()) {
                $this->dispatchRevalidation($spec, $profile);
                $status = 'STALE';

                return $entry['data'];
            }

            try {
                $status = 'MISS';

                return $this->fetchAndStore($spec, $profile, $profile->inlineBudgetSeconds());
            } catch (Throwable $exception) {
                $this->markFailure($spec, $profile, $exception);
                $status = $entry !== null ? 'STALE' : 'MISS';

                if ($entry !== null) {
                    return $entry['data'];
                }

                if ($this->supportsAsyncDispatch()) {
                    // The marker suppresses subsequent requests, but the request
                    // that observed the miss still queues one background attempt.
                    $this->dispatchRevalidation($spec, $profile, ignoreFailureMarker: true);
                }

                if ($exception instanceof PartialSourceFetchException) {
                    return $exception->fallback();
                }

                return $this->emptyResult($spec);
            }
        } finally {
            if ($shouldProfile) {
                RequestPerformanceProfiler::recordCatalogCache($spec->sourceKey, $status, $cacheMs);
            }
        }
    }

    /**
     * Fetch data for an authoritative workflow such as an Installed scan.
     *
     * Unlike the render-oriented swr() method, a cold upstream failure is
     * rethrown so callers cannot mistake transport failure for a valid empty
     * result and persist it as resolved metadata.
     */
    public function swrRequired(SourceFetchSpec $spec, CacheProfile $profile): mixed
    {
        $entry = $this->readEntry($spec);

        if ($entry !== null) {
            return $entry['data'];
        }

        if ($this->hasFailureMarker($spec)) {
            throw new RuntimeException("Source [{$spec->sourceKey}] operation [{$spec->operation}] is temporarily unavailable.");
        }

        try {
            return $this->fetchAndStore($spec, $profile, $profile->backgroundTimeoutSeconds());
        } catch (Throwable $exception) {
            $this->markFailure($spec, $profile, $exception);

            if ($this->supportsAsyncDispatch()) {
                $this->dispatchRevalidation($spec, $profile, ignoreFailureMarker: true);
            }

            throw $exception;
        }
    }

    /**
     * Fetch a value that is both authoritative and fresh.
     *
     * This is intentionally narrower than swrRequired(): Installed metadata
     * warming may write a negative per-project entry for an id missing from a
     * successful batch response, so a stale batch result is not sufficient
     * evidence that the project is still absent. Fresh entries retain the
     * ordinary cache fast path; stale entries are revalidated synchronously.
     */
    public function swrRequiredFresh(SourceFetchSpec $spec, CacheProfile $profile): mixed
    {
        $entry = $this->readEntry($spec);

        if ($entry !== null && $entry['fresh_until'] > time()) {
            return $entry['data'];
        }

        if ($this->hasFailureMarker($spec)) {
            throw new RuntimeException("Source [{$spec->sourceKey}] operation [{$spec->operation}] is temporarily unavailable.");
        }

        try {
            return $this->fetchAndStore($spec, $profile, $profile->backgroundTimeoutSeconds());
        } catch (Throwable $exception) {
            $this->markFailure($spec, $profile, $exception);

            // A stale entry remains available to ordinary render-path SWR
            // reads. Do not queue another attempt from this already-queued
            // metadata warming job.
            if ($entry === null && $this->supportsAsyncDispatch()) {
                $this->dispatchRevalidation($spec, $profile, ignoreFailureMarker: true);
            }

            throw $exception;
        }
    }

    /**
     * Execute a queued refresh. Failures intentionally leave any stale entry
     * untouched and are absorbed after recording a short-lived marker.
     */
    public function revalidate(SourceFetchSpec $spec, CacheProfile $profile): bool
    {
        try {
            $this->fetchAndStore($spec, $profile, $profile->backgroundTimeoutSeconds());

            return true;
        } catch (Throwable $exception) {
            $this->markFailure($spec, $profile, $exception);

            return false;
        }
    }

    /**
     * Return a cached value without fetching or dispatching.
     *
     * The boolean distinguishes a cached null from a miss and is used by
     * progressive-enrichment render paths.
     *
     * @return array{hit: bool, data: mixed, fresh: bool, retry_delayed: bool}
     */
    public function peek(SourceFetchSpec $spec): array
    {
        $entry = $this->readEntry($spec);

        return [
            'hit' => $entry !== null,
            'data' => $entry['data'] ?? null,
            'fresh' => $entry !== null && $entry['fresh_until'] > time(),
            // A failure marker means the last fetch failed. It is deliberately
            // distinct from a cached null: callers must not treat a temporary
            // retry cooldown as proof that the project was removed upstream.
            'retry_delayed' => $entry === null && $this->hasFailureMarker($spec),
        ];
    }

    /**
     * Batched read-only counterpart to peek(). Cache stores such as Redis map
     * this to one MGET, avoiding one network round trip per Installed row.
     * Retry-cooldown markers are read in that same MGET so a cold entry whose
     * last refresh failed can remain distinguishable from a real cache miss.
     *
     * @param array<string, SourceFetchSpec> $specs
     * @return array<string, array{hit: bool, data: mixed, fresh: bool, retry_delayed: bool}>
     */
    public function peekMany(array $specs): array
    {
        if ($specs === []) {
            return [];
        }

        $keys = [];
        $failureMarkerKeys = [];
        foreach ($specs as $key => $spec) {
            $keys[$key] = $spec->cacheKey();
            $failureMarkerKeys[$key] = $this->failureMarkerKey($spec);
        }

        $payloads = $this->cache->many(array_values(array_unique([
            ...array_values($keys),
            ...array_values($failureMarkerKeys),
        ])));
        $now = time();
        $results = [];

        foreach ($keys as $key => $cacheKey) {
            $entry = $this->entryFromPayload($payloads[$cacheKey] ?? null);
            $this->rememberEntryPayload($cacheKey, $entry);
            $failureKey = $failureMarkerKeys[$key];
            $failurePayload = $payloads[$failureKey] ?? null;
            $this->rememberFailurePayload($failureKey, $failurePayload);
            $results[$key] = [
                'hit' => $entry !== null,
                'data' => $entry['data'] ?? null,
                'fresh' => $entry !== null && $entry['fresh_until'] > $now,
                'retry_delayed' => $entry === null && $this->hasActiveFailureMarker(
                    $failurePayload,
                    $now,
                ),
            ];
        }

        return $results;
    }

    /**
     * Queue a refresh without performing an inline fetch.
     */
    public function revalidateAsync(SourceFetchSpec $spec, CacheProfile $profile): bool
    {
        if (!$this->supportsAsyncDispatch() || $this->hasFailureMarker($spec)) {
            return false;
        }

        return $this->dispatchRevalidation($spec, $profile);
    }

    /** Clear the queue-worker memo between jobs. */
    public function clearRuntimeCaches(): void
    {
        $this->processMemos = null;
    }

    /**
     * Non-blocking counterpart to swr(): never performs an inline fetch.
     *
     * A fresh hit returns immediately. A stale hit also returns
     * immediately, and queues a background revalidation exactly like
     * swr() does. A miss queues a background fetch (when the queue
     * supports it - see revalidateAsync()) and returns the operation's
     * empty result instead of waiting for one, so a render path can show
     * a placeholder rather than block on a cold cache. Callers that need
     * to tell "genuinely empty" apart from "not checked yet" should use
     * the pending flag rather than inspecting the returned data.
     *
     * @return array{data: mixed, pending: bool, retry_delayed: bool}
     */
    public function swrDeferred(SourceFetchSpec $spec, CacheProfile $profile): array
    {
        $peeked = $this->peek($spec);

        if ($peeked['hit']) {
            if (!$peeked['fresh']) {
                $this->revalidateAsync($spec, $profile);
            }

            return ['data' => $peeked['data'], 'pending' => false, 'retry_delayed' => false];
        }

        // A failure marker (or a sync/null queue) means no background fetch
        // was actually scheduled. Reporting this as pending keeps callers
        // polling forever for a value that cannot change until the marker
        // expires or queue configuration is fixed.
        $pending = $this->revalidateAsync($spec, $profile);

        return [
            'data' => $this->emptyResult($spec),
            'pending' => $pending,
            'retry_delayed' => $peeked['retry_delayed'],
        ];
    }

    /** @return array{v: int, data: mixed, fresh_until: int}|null */
    private function readEntry(SourceFetchSpec $spec, bool $refresh = false): ?array
    {
        $key = $spec->cacheKey();
        $memos = $this->memos();
        $entries = $memos['entries'];

        if (!$refresh && array_key_exists($key, $entries)) {
            return $entries[$key] === false ? null : $entries[$key];
        }

        $entry = $this->entryFromPayload($this->cache->get($key));
        $entries[$key] = $entry ?? false;
        $memos['entries'] = $entries;

        return $entry;
    }

    /** @return array{v: int, data: mixed, fresh_until: int}|null */
    private function entryFromPayload(mixed $payload): ?array
    {
        if (!is_array($payload)
            || ($payload['v'] ?? null) !== self::SCHEMA_VERSION
            || !array_key_exists('data', $payload)
            || !is_int($payload['fresh_until'] ?? null)) {
            return null;
        }

        return [
            'v' => self::SCHEMA_VERSION,
            'data' => $payload['data'],
            'fresh_until' => $payload['fresh_until'],
        ];
    }

    private function fetchAndStore(
        SourceFetchSpec $spec,
        CacheProfile $profile,
        float $timeoutSeconds,
    ): mixed {
        if ($spec->operation !== 'search' || !method_exists($this->cache, 'getStore')) {
            return $this->performFetchAndStore($spec, $profile, $timeoutSeconds);
        }

        $store = $this->cache->getStore();
        if (!$store instanceof LockProvider) {
            return $this->performFetchAndStore($spec, $profile, $timeoutSeconds);
        }

        // Hangar's catalog search is ~1s. The after-response warm and a
        // visitor who opens that tab while it is in flight must share one
        // upstream call instead of stacking two. Wait up to this request's
        // own budget for the in-flight fetch to land, then read the entry.
        $lockTtl = max(12, (int) ceil($timeoutSeconds) + 1);
        $lock = $this->cache->lock('mmr_src_fetch:'.$spec->cacheKey(), $lockTtl);
        $waitSeconds = max(1, (int) ceil($timeoutSeconds));
        $acquired = false;

        try {
            $lock->block($waitSeconds);
            $acquired = true;

            $entry = $this->readEntry($spec, refresh: true);
            if ($entry !== null && $entry['fresh_until'] > time()) {
                return $entry['data'];
            }

            return $this->performFetchAndStore($spec, $profile, $timeoutSeconds);
        } catch (LockTimeoutException) {
            $entry = $this->readEntry($spec, refresh: true);
            if ($entry !== null) {
                // Do not start a second upstream request while the lock holder
                // is still in flight; stale data is safer than a herd.
                return $entry['data'];
            }

            throw new RuntimeException(
                "Source [{$spec->sourceKey}] operation [{$spec->operation}] is already being fetched.",
            );
        } finally {
            if ($acquired) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The TTL may already have elapsed while Hangar was answering.
                }
            }
        }
    }

    private function performFetchAndStore(
        SourceFetchSpec $spec,
        CacheProfile $profile,
        float $timeoutSeconds,
    ): mixed {
        $data = $this->executor->fetch($spec, $timeoutSeconds);
        $this->storeEntry($spec, $data, $profile);

        return $data;
    }

    /**
     * Write already-fetched data under the same fresh/stale semantics
     * fetchAndStore() applies, without performing a fetch. Used to prime
     * many per-entity cache entries at once from a single batched upstream
     * call (see Jobs\WarmProjectMetadata) - the batch call itself still
     * goes through the executor directly rather than through swr(), so it
     * is never cached as one combined entry keyed by the whole batch.
     *
     * @param array<int, array{spec: SourceFetchSpec, data: mixed}> $entries
     */
    public function primeMany(array $entries, CacheProfile $profile): void
    {
        $staleTtl = $profile->staleTtlSeconds();

        if ($staleTtl === null) {
            foreach ($entries as $entry) {
                $this->storeEntry($entry['spec'], $entry['data'], $profile);
            }

            return;
        }

        $payloads = [];
        $failureMarkers = [];
        $freshUntil = time() + $profile->freshTtlSeconds();

        foreach ($entries as $entry) {
            $cacheKey = $entry['spec']->cacheKey();
            $payloads[$cacheKey] = [
                'v' => self::SCHEMA_VERSION,
                'data' => $entry['data'],
                'fresh_until' => $freshUntil,
            ];
            $failureMarkers[$this->failureMarkerKey($entry['spec'])] = true;
        }

        if ($payloads === []) {
            return;
        }

        // Laravel's Redis store writes putMany() as one MULTI/EXEC
        // transaction. Project metadata warming therefore avoids one network
        // write per row while retaining each entry's existing TTL semantics.
        $this->cache->putMany($payloads, $staleTtl);

        foreach (array_keys($payloads) as $cacheKey) {
            $this->rememberEntryPayload($cacheKey, $payloads[$cacheKey]);
        }

        foreach (array_keys($failureMarkers) as $failureMarker) {
            $this->forgetFailureMarker($failureMarker);
        }
    }

    /**
     * Record a short retry cooldown for several entity entries without
     * persisting an empty/negative result. This is used when one authoritative
     * batch request fails before it can prime its individual project entries:
     * the batch cache key has a failure marker, but the next render reads the
     * per-project keys.
     *
     * @param array<int, SourceFetchSpec> $specs
     */
    public function markRetryDelayedMany(array $specs, CacheProfile $profile): void
    {
        if ($specs === []) {
            return;
        }

        $ttl = $profile->failureMarkerTtlSeconds();
        $failedUntil = time() + $ttl;
        $markers = [];

        foreach ($specs as $spec) {
            $markers[$this->failureMarkerKey($spec)] = [
                'v' => self::FAILURE_MARKER_VERSION,
                'failed_until' => $failedUntil,
            ];
        }

        if ($markers !== []) {
            $this->cache->putMany($markers, $ttl);
            foreach ($markers as $failureKey => $payload) {
                $this->rememberFailurePayload($failureKey, $payload);
            }
        }
    }

    private function storeEntry(SourceFetchSpec $spec, mixed $data, CacheProfile $profile): void
    {
        $entry = [
            'v' => self::SCHEMA_VERSION,
            'data' => $data,
            'fresh_until' => time() + $profile->freshTtlSeconds(),
        ];
        $staleTtl = $profile->staleTtlSeconds();

        if ($staleTtl === null) {
            $this->cache->forever($spec->cacheKey(), $entry);
        } else {
            $this->cache->put($spec->cacheKey(), $entry, $staleTtl);
        }

        $this->rememberEntryPayload($spec->cacheKey(), $entry);
        $this->forgetFailureMarker($this->failureMarkerKey($spec));
    }

    private function emptyResult(SourceFetchSpec $spec): mixed
    {
        try {
            return $this->executor->emptyResult($spec);
        } catch (Throwable $exception) {
            $this->logFailure('Unable to resolve the empty source-cache result.', $spec, $exception);

            return [];
        }
    }

    private function supportsAsyncDispatch(): bool
    {
        return $this->operations->supportsAsyncDispatch();
    }

    private function dispatchRevalidation(
        SourceFetchSpec $spec,
        CacheProfile $profile,
        bool $ignoreFailureMarker = false,
    ): bool {
        if (!$ignoreFailureMarker && $this->hasFailureMarker($spec)) {
            return false;
        }

        try {
            // Dispatchable's PendingDispatch acquires Laravel's ShouldBeUnique
            // lock. Calling Bus\Dispatcher::dispatch() directly would bypass
            // that acquisition path.
            RevalidateSourceCache::dispatch($spec, $profile);

            return true;
        } catch (Throwable $exception) {
            $this->logFailure('Unable to dispatch source-cache revalidation.', $spec, $exception);

            return false;
        }
    }

    private function hasFailureMarker(SourceFetchSpec $spec): bool
    {
        $key = $this->failureMarkerKey($spec);
        $memos = $this->memos();
        $failures = $memos['failures'];

        if (array_key_exists($key, $failures)) {
            $marker = $failures[$key] === false ? null : $failures[$key];
        } else {
            $marker = $this->cache->get($key);
            $failures[$key] = is_array($marker) ? $marker : false;
            $memos['failures'] = $failures;
        }

        if (!$this->hasActiveFailureMarker($marker, time())) {
            // An expired (but not yet evicted) marker is harmless, but remove
            // it here because this path is already doing a single-key read.
            if (is_array($marker)
                && ($marker['v'] ?? null) === self::FAILURE_MARKER_VERSION
                && is_int($marker['failed_until'] ?? null)) {
                $this->cache->forget($key);
                $failures[$key] = false;
                $memos['failures'] = $failures;
            }

            return false;
        }

        return true;
    }

    private function hasActiveFailureMarker(mixed $marker, int $now): bool
    {
        return is_array($marker)
            && ($marker['v'] ?? null) === self::FAILURE_MARKER_VERSION
            && is_int($marker['failed_until'] ?? null)
            && $marker['failed_until'] > $now;
    }

    private function markFailure(SourceFetchSpec $spec, CacheProfile $profile, Throwable $exception): void
    {
        $ttl = $profile->failureMarkerTtlSeconds();
        $marker = [
            'v' => self::FAILURE_MARKER_VERSION,
            'failed_until' => time() + $ttl,
        ];
        $this->cache->put($this->failureMarkerKey($spec), $marker, $ttl);
        $this->rememberFailurePayload($this->failureMarkerKey($spec), $marker);

        $this->logFailure('Source-cache fetch failed.', $spec, $exception);
    }

    private function failureMarkerKey(SourceFetchSpec $spec): string
    {
        return $spec->cacheKey().':failure:v1';
    }

    /**
     * Request-scoped probe memo. SourceCache is a singleton, so the bag lives
     * on the current HTTP request when one exists and otherwise on this
     * instance (queue workers / unit tests).
     */
    private ?\ArrayObject $processMemos = null;

    private function memos(): \ArrayObject
    {
        $request = $this->currentRequest();
        if ($request !== null) {
            $memos = $request->attributes->get('mmr_source_cache_memo');
            if ($memos instanceof \ArrayObject) {
                return $memos;
            }

            $memos = $this->newMemoBag();
            $request->attributes->set('mmr_source_cache_memo', $memos);

            return $memos;
        }

        return $this->processMemos ??= $this->newMemoBag();
    }

    private function newMemoBag(): \ArrayObject
    {
        return new \ArrayObject([
            'entries' => [],
            'failures' => [],
        ]);
    }

    private function currentRequest(): ?Request
    {
        try {
            $request = request();
        } catch (Throwable) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }

    /**
     * @param  array{v: int, data: mixed, fresh_until: int}|null  $entry
     */
    private function rememberEntryPayload(string $cacheKey, ?array $entry): void
    {
        $memos = $this->memos();
        $entries = $memos['entries'];
        $entries[$cacheKey] = $entry ?? false;
        $memos['entries'] = $entries;
    }

    private function rememberFailurePayload(string $failureKey, mixed $payload): void
    {
        $memos = $this->memos();
        $failures = $memos['failures'];
        $failures[$failureKey] = is_array($payload) ? $payload : false;
        $memos['failures'] = $failures;
    }

    /**
     * Drop a retry cooldown only when this request already observed one.
     * Failure markers expire in 30s, which is shorter than every fresh TTL,
     * so an unobserved leftover cannot block a later stale revalidation.
     */
    private function forgetFailureMarker(string $failureKey): void
    {
        $memos = $this->memos();
        $failures = $memos['failures'];
        $known = $failures[$failureKey] ?? null;

        if ($known !== null && $known !== false && $this->hasActiveFailureMarker($known, time())) {
            $this->cache->forget($failureKey);
        }

        $failures[$failureKey] = false;
        $memos['failures'] = $failures;
    }

    private function logFailure(string $message, SourceFetchSpec $spec, Throwable $exception): void
    {
        $this->logger?->warning($message, [
            'source' => $spec->sourceKey,
            'operation' => $spec->operation,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
