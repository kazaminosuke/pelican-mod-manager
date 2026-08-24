<?php

namespace Kazaminosuke\ModManager\Support;

use Illuminate\Cache\RateLimiter;

/**
 * Self-imposed rate limit applied only to warm-job-originated upstream
 * requests (see Jobs\WarmCatalogSearch) - never to a request made while
 * actually serving a user (search, install, tab switch, an Installed-tab
 * SourceCache miss, ...), which all stay unthrottled so warming can never
 * add latency to a real visitor.
 *
 * Cache-backed via Laravel's RateLimiter (itself backed by the app's
 * default cache store), so the limit holds across multiple queue workers
 * rather than being tracked per-process - a single in-memory counter would
 * only cap one worker's traffic, not the fleet's.
 *
 * See config/pelican-mod-manager.php's warm_rate_limit block for
 * where the per-source default numbers come from and which ones are
 * officially published versus a conservative guess.
 */
final class WarmRequestThrottle
{
    /** A fixed one-minute window, matching how each per-source limit is expressed ("N req/min"). */
    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * Whether a warm job may make one more upstream request for this
     * source right now. Consumes one slot of the per-minute budget when it
     * returns true.
     */
    public function tryAcquire(string $sourceKey): bool
    {
        $limit = $this->perMinuteLimit($sourceKey);

        if ($limit === null) {
            return true;
        }

        // A configured limit of 0 (or negative) is an explicit per-source
        // kill switch for warm traffic, distinct from warm_catalog_enabled
        // turning warming off entirely.
        if ($limit <= 0) {
            return false;
        }

        $key = $this->key($sourceKey);

        if ($this->limiter->tooManyAttempts($key, $limit)) {
            return false;
        }

        $this->limiter->hit($key, self::DECAY_SECONDS);

        return true;
    }

    /** Null means no self-imposed limit is configured for this source. */
    public function perMinuteLimit(string $sourceKey): ?int
    {
        $configured = config("pelican-mod-manager.warm_rate_limit.$sourceKey");

        return is_numeric($configured) ? (int) $configured : null;
    }

    /**
     * How many warm requests for this source are still available in the
     * current window, for observability/testing. Does not consume a slot.
     */
    public function remaining(string $sourceKey): ?int
    {
        $limit = $this->perMinuteLimit($sourceKey);

        if ($limit === null) {
            return null;
        }

        return max(0, $this->limiter->remaining($this->key($sourceKey), $limit));
    }

    private function key(string $sourceKey): string
    {
        return "mod_manager_warm_throttle:$sourceKey";
    }
}
