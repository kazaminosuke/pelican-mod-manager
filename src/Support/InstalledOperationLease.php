<?php

namespace Kazaminosuke\ModManager\Support;

use Closure;
use Exception;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kazaminosuke\ModManager\Enums\ProjectType;

/**
 * Atomic exclusive lease for mutating installed-file operations on one
 * server/type. This is not the metadata document lock: downloads, scans,
 * and bulk updates hold this lease for their full duration, while metadata
 * commits use a separate shorter lock around Wings GET/PUT only.
 */
final class InstalledOperationLease
{
    public const OPERATION_SCAN = 'scan';

    public const OPERATION_BULK_UPDATE = 'bulk_update';

    public const OPERATION_INSTALL = 'install';

    public const OPERATION_UPDATE = 'update';

    public const OPERATION_UNINSTALL = 'uninstall';

    public const OPERATION_CLEAR = 'clear';

    public const KEY_PREFIX = 'mod_manager_op_lease:v1';

    private const GUARD_SUFFIX = ':owner_guard';

    /**
     * Cover the longest mutating job (bulk uniqueFor / foreground pull).
     * Owners release early on success or failure; this is crash recovery.
     */
    public const TTL_SECONDS = 1200;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public static function key(int $serverId, ProjectType $type): string
    {
        return self::KEY_PREFIX.':'.$serverId.':'.$type->value;
    }

    public function tryAcquire(
        int $serverId,
        ProjectType $type,
        string $operation,
        ?int $ttlSeconds = null,
    ): ?string {
        $token = bin2hex(random_bytes(16));
        $key = self::key($serverId, $type);
        $claimed = $this->synchronized(
            $key,
            fn (): bool => $this->cache->add(
                $key,
                [
                    'operation' => $operation,
                    'token' => $token,
                ],
                $ttlSeconds ?? self::TTL_SECONDS,
            ),
        );

        return $claimed ? $token : null;
    }

    public function release(int $serverId, ProjectType $type, string $token): void
    {
        $key = self::key($serverId, $type);

        $this->synchronized($key, function () use ($key, $token): void {
            $current = $this->cache->get($key);

            if (!is_array($current)
                || !hash_equals((string) ($current['token'] ?? ''), $token)) {
                return;
            }

            $this->cache->forget($key);
        });
    }

    public function owns(int $serverId, ProjectType $type, string $token): bool
    {
        $current = $this->cache->get(self::key($serverId, $type));

        return is_array($current) && hash_equals((string) ($current['token'] ?? ''), $token);
    }

    /**
     * Reconfirm dispatch-time ownership and renew the crash-recovery TTL for
     * a job that has just left the queue. Without this, most of the lease may
     * already have elapsed while the job was waiting, allowing it to expire
     * during otherwise valid work.
     */
    public function refresh(
        int $serverId,
        ProjectType $type,
        string $token,
        ?int $ttlSeconds = null,
    ): bool {
        $key = self::key($serverId, $type);

        return $this->synchronized($key, function () use ($key, $token, $ttlSeconds): bool {
            $current = $this->cache->get($key);

            if (!is_array($current)
                || !hash_equals((string) ($current['token'] ?? ''), $token)) {
                return false;
            }

            return $this->cache->put($key, $current, $ttlSeconds ?? self::TTL_SECONDS);
        });
    }

    /**
     * Atomically from the caller's perspective, acquire one lease for every
     * requested type. Cache::add() is atomic per key; sorting the keys avoids
     * two multi-type reset requests acquiring them in opposite orders. If any
     * claim fails, every claim made by this call is released with its exact
     * owner token before null is returned.
     *
     * @param  array<int, ProjectType>  $types
     * @return array<string, string>|null Tokens keyed by ProjectType value.
     */
    public function tryAcquireMany(
        int $serverId,
        array $types,
        string $operation,
        ?int $ttlSeconds = null,
    ): ?array {
        $normalized = [];

        foreach ($types as $type) {
            $normalized[$type->value] = $type;
        }

        ksort($normalized, SORT_STRING);
        $tokens = [];

        foreach ($normalized as $typeValue => $type) {
            $token = $this->tryAcquire($serverId, $type, $operation, $ttlSeconds);

            if ($token === null) {
                $this->releaseMany($serverId, $tokens);

                return null;
            }

            $tokens[$typeValue] = $token;
        }

        return $tokens;
    }

    /** @param array<string, string> $tokens Tokens keyed by ProjectType value. */
    public function releaseMany(int $serverId, array $tokens): void
    {
        foreach ($tokens as $typeValue => $token) {
            $type = ProjectType::tryFrom((string) $typeValue);

            if ($type !== null) {
                $this->release($serverId, $type, $token);
            }
        }
    }

    public function currentOperation(int $serverId, ProjectType $type): ?string
    {
        $current = $this->cache->get(self::key($serverId, $type));

        return is_array($current) && is_string($current['operation'] ?? null)
            ? $current['operation']
            : null;
    }

    public function isHeld(int $serverId, ProjectType $type): bool
    {
        return $this->currentOperation($serverId, $type) !== null;
    }

    /**
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(int $serverId, ProjectType $type, string $operation, Closure $callback): mixed
    {
        $token = $this->tryAcquire($serverId, $type, $operation);

        if ($token === null) {
            throw new Exception('A managed file operation is already running.');
        }

        try {
            return $callback();
        } finally {
            $this->release($serverId, $type, $token);
        }
    }

    /**
     * Serialize owner-sensitive read/modify/write sequences with Laravel's
     * cache-store lock. The application's real cache repository supplies
     * withoutOverlapping() for every supported Panel store. The fallback is
     * retained for narrow unit doubles implementing only the cache contract.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function synchronized(string $key, Closure $callback): mixed
    {
        if (method_exists($this->cache, 'withoutOverlapping')) {
            return $this->cache->withoutOverlapping(
                $key.self::GUARD_SUFFIX,
                $callback,
                lockFor: 5,
                waitFor: 5,
            );
        }

        return $callback();
    }
}
