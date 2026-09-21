<?php

namespace Kazaminosuke\ModManager\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use JsonException;
use Kazaminosuke\ModManager\Contracts\BackgroundJobQueue;

/**
 * Enqueues Mod Manager work as JSON payloads for Pelican's scheduler.
 *
 * Pelican's only long-running async path is Laravel's queue worker.
 * Plugin classes autoloaded into that process stay loaded for the
 * worker's lifetime, so a Plugin update cannot take effect until the
 * worker is recycled. This runner never serializes Plugin classes onto
 * that worker: it persists a JSON payload and lets the short-lived
 * `php artisan schedule:run` process (`mod-manager:process-jobs`) boot
 * the Panel and load the Plugin from disk.
 *
 * It does not spawn subprocesses or invoke a process runner.
 */
final class PluginBackgroundRunner
{
    public const UNIQUE_CACHE_PREFIX = 'mod_manager_bg_unique:v1';

    /**
     * Recorded enqueues when the runner is in fake/test mode.
     *
     * @var array<int, array{type: string, payload: array<string, mixed>, unique_id: ?string}>
     */
    public array $spawned = [];

    /**
     * @param  (callable(string, array<string, mixed>): bool)|null  $spawner
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly ?CacheRepository $cache = null,
        private readonly mixed $spawner = null,
        private readonly bool $recordOnly = false,
        private readonly ?BackgroundJobQueue $queue = null,
    ) {}

    public static function disabled(): self
    {
        return new self(false);
    }

    public static function fake(?CacheRepository $cache = null): self
    {
        return new self(true, $cache, recordOnly: true);
    }

    public static function forRuntime(?CacheRepository $cache = null, ?BackgroundJobQueue $queue = null): self
    {
        $app = Container::getInstance();

        if (is_object($app) && method_exists($app, 'runningUnitTests') && $app->runningUnitTests()) {
            return self::disabled();
        }

        if (function_exists('env') && env('APP_ENV') === 'testing') {
            return self::disabled();
        }

        $queue ??= self::resolveQueue($app);

        return new self($queue !== null, $cache, queue: $queue);
    }

    public function canSpawn(): bool
    {
        return $this->enabled;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function run(string $type, array $payload, ?string $uniqueId = null, int $uniqueFor = 0): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $uniqueKey = $uniqueId !== null && $uniqueId !== '' && $uniqueFor > 0
            ? $this->uniqueCacheKey($uniqueId)
            : null;

        if ($uniqueKey !== null && !$this->acquireUnique($uniqueKey, $uniqueFor)) {
            return true;
        }

        if ($uniqueKey !== null) {
            $payload['_unique_key'] = $uniqueKey;
        }

        try {
            if ($this->recordOnly) {
                $this->spawned[] = [
                    'type' => $type,
                    'payload' => $payload,
                    'unique_id' => $uniqueId,
                ];

                return true;
            }

            $started = $this->spawner !== null
                ? (bool) ($this->spawner)($type, $payload)
                : $this->enqueue($type, $payload);

            if (!$started) {
                $this->releaseUnique($uniqueKey);
            }

            return $started;
        } catch (\Throwable $exception) {
            $this->releaseUnique($uniqueKey);

            throw $exception;
        }
    }

    /**
     * Encode a payload the way tests assert JSON rather than a PHP
     * serialized class. Runtime jobs persist the array directly.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public static function encodePayload(array $payload): string
    {
        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function decodePayload(string $encoded): array
    {
        $json = base64_decode($encoded, true);

        if ($json === false) {
            throw new JsonException('The background job payload is not valid base64.');
        }

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($payload)) {
            throw new JsonException('The background job payload must decode to an object.');
        }

        return $payload;
    }

    public function uniqueCacheKey(string $uniqueId): string
    {
        return self::UNIQUE_CACHE_PREFIX.':'.$uniqueId;
    }

    public function releaseUniqueKey(?string $uniqueKey): void
    {
        $this->releaseUnique($uniqueKey);
    }

    private function acquireUnique(string $uniqueKey, int $uniqueFor): bool
    {
        if ($this->cache === null) {
            return true;
        }

        return $this->cache->add($uniqueKey, 1, $uniqueFor);
    }

    private function releaseUnique(?string $uniqueKey): void
    {
        if ($uniqueKey !== null) {
            $this->cache?->forget($uniqueKey);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function enqueue(string $type, array $payload): bool
    {
        return $this->queue?->push($type, $payload) ?? false;
    }

    private static function resolveQueue(mixed $app): ?BackgroundJobQueue
    {
        if (!is_object($app) || !method_exists($app, 'bound') || !$app->bound(BackgroundJobQueue::class)) {
            return new DatabaseBackgroundJobQueue();
        }

        try {
            $queue = $app->make(BackgroundJobQueue::class);
        } catch (\Throwable) {
            return new DatabaseBackgroundJobQueue();
        }

        return $queue instanceof BackgroundJobQueue ? $queue : new DatabaseBackgroundJobQueue();
    }
}
