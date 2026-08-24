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
        $claimed = $this->cache->add(
            self::key($serverId, $type),
            [
                'operation' => $operation,
                'token' => $token,
            ],
            $ttlSeconds ?? self::TTL_SECONDS,
        );

        return $claimed ? $token : null;
    }

    public function release(int $serverId, ProjectType $type, ?string $token = null): void
    {
        $key = self::key($serverId, $type);

        if ($token !== null) {
            $current = $this->cache->get($key);

            if (!is_array($current) || ($current['token'] ?? null) !== $token) {
                return;
            }
        }

        $this->cache->forget($key);
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
}
