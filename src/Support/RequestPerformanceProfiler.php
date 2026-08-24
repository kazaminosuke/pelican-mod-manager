<?php

namespace Kazaminosuke\ModManager\Support;

use Illuminate\Http\Request;
use Throwable;

/**
 * Request-local catalog timing bag used by the Mod Manager Performance
 * Profiler. Capture starts only from ModManagerPage::boot() when
 * MOD_MANAGER_DEBUG_TIMING is on, so ordinary requests and queue jobs pay
 * a single boolean check and do not leak state across requests.
 *
 * @phpstan-type ProfilerState array{
 *     request_id: string,
 *     source: ?string,
 *     page: ?int,
 *     search: string,
 *     filter_category: ?string,
 *     filter_environment: ?string,
 *     sort: ?string,
 *     table_loaded: bool,
 *     php_ms: int,
 *     render_ms: int,
 *     render_started_at: float,
 *     cache: string,
 *     cache_ms: int,
 *     api_source: ?string,
 *     api_ms: int,
 *     version_lookup_count: int,
 *     version_lookup_ms: int,
 *     hits: ?int
 * }
 */
final class RequestPerformanceProfiler
{
    public const EVENT = 'mmr-profiler-server';

    /**
     * @return array<string, mixed>
     */
    public static function emptySnapshot(): array
    {
        return [
            'request_id' => '',
            'source' => null,
            'page' => null,
            'search' => '',
            'filter_category' => null,
            'filter_environment' => null,
            'sort' => null,
            'table_loaded' => false,
            'php_ms' => 0,
            'render_ms' => 0,
            'cache' => 'NONE',
            'cache_ms' => 0,
            'api_source' => null,
            'api_ms' => 0,
            'version_lookup_count' => 0,
            'version_lookup_ms' => 0,
            'hits' => null,
        ];
    }

    public static function isCapturing(): bool
    {
        $state = self::state();

        return $state !== null;
    }

    public static function start(string $requestId): void
    {
        $request = self::request();

        if ($request === null) {
            return;
        }

        $state = self::emptySnapshot();
        $state['request_id'] = $requestId;
        $request->attributes->set('mmr_profiler', $state);
    }

    public static function markRenderStart(): void
    {
        $state = self::state();

        if ($state === null) {
            return;
        }

        $state['render_started_at'] = microtime(true);
        self::store($state);
    }

    public static function markRenderEnd(): void
    {
        $state = self::state();

        if ($state === null || ($state['render_started_at'] ?? 0.0) <= 0.0) {
            return;
        }

        $state['render_ms'] = (int) round((microtime(true) - $state['render_started_at']) * 1000);
        unset($state['render_started_at']);
        self::store($state);
    }

    public static function recordCatalogCache(string $sourceKey, string $status, int $cacheMs): void
    {
        $state = self::state();

        if ($state === null) {
            return;
        }

        $state['source'] = $sourceKey;
        $state['cache'] = $status;
        $state['cache_ms'] = $cacheMs;
        self::store($state);
    }

    public static function addSearchApi(string $sourceKey, int $apiMs): void
    {
        $state = self::state();

        if ($state === null) {
            return;
        }

        $state['api_source'] = $sourceKey;
        $state['api_ms'] = (int) $state['api_ms'] + max(0, $apiMs);
        self::store($state);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function mergeContext(array $context): void
    {
        $state = self::state();

        if ($state === null) {
            return;
        }

        foreach ([
            'source',
            'page',
            'search',
            'filter_category',
            'filter_environment',
            'sort',
            'table_loaded',
            'php_ms',
            'version_lookup_count',
            'version_lookup_ms',
            'hits',
        ] as $key) {
            if (array_key_exists($key, $context)) {
                $state[$key] = $context[$key];
            }
        }

        self::store($state);
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        $state = self::state() ?? self::emptySnapshot();
        unset($state['render_started_at']);

        return $state;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function state(): ?array
    {
        $request = self::request();
        $state = $request?->attributes->get('mmr_profiler');

        return is_array($state) ? $state : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function store(array $state): void
    {
        self::request()?->attributes->set('mmr_profiler', $state);
    }

    private static function request(): ?Request
    {
        try {
            if (!function_exists('request')) {
                return null;
            }

            $request = request();

            return $request instanceof Request ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }
}
