<?php

namespace Kazaminosuke\ModManager\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use JsonException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Starts Mod Manager work in a short-lived `php artisan` process.
 *
 * Pelican's only built-in async path is Laravel's long-running queue
 * worker. Plugin classes autoloaded into that process stay loaded for
 * the worker's lifetime, so a Plugin update cannot take effect until
 * the worker is recycled. This runner never serializes Plugin classes
 * onto that worker: it writes a JSON payload and execs a new PHP CLI
 * process, which boots the Panel and loads the Plugin from disk.
 *
 * It is not a daemon. Each spawn exits when its job finishes.
 */
final class PluginBackgroundRunner
{
    public const UNIQUE_CACHE_PREFIX = 'mod_manager_bg_unique:v1';

    /**
     * Recorded spawns when the runner is in fake/test mode.
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
    ) {}

    public static function disabled(): self
    {
        return new self(false);
    }

    public static function fake(?CacheRepository $cache = null): self
    {
        return new self(true, $cache, recordOnly: true);
    }

    public static function forRuntime(?CacheRepository $cache = null): self
    {
        $app = Container::getInstance();

        if (is_object($app) && method_exists($app, 'runningUnitTests') && $app->runningUnitTests()) {
            return self::disabled();
        }

        if (function_exists('env') && env('APP_ENV') === 'testing') {
            return self::disabled();
        }

        return new self(self::detectCanSpawn(), $cache);
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
                : $this->spawn($type, $payload);

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
     * Encode a payload the way the artisan command expects it. Exposed for
     * tests that assert the queued form is JSON, not a PHP serialized class.
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
    private function spawn(string $type, array $payload): bool
    {
        $php = self::phpBinary();
        $artisan = self::artisanPath();

        if ($php === null || $artisan === null) {
            return false;
        }

        $encoded = self::encodePayload($payload);
        $log = function_exists('storage_path')
            ? storage_path('logs/mod-manager-background.log')
            : sys_get_temp_dir().'/mod-manager-background.log';

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'start /B "" '.escapeshellarg($php).' '.escapeshellarg($artisan)
                .' mod-manager:run-job '.escapeshellarg($type).' '.escapeshellarg($encoded)
                .' >> '.escapeshellarg($log).' 2>&1';
            $handle = popen($cmd, 'r');

            if (!is_resource($handle)) {
                return false;
            }

            pclose($handle);

            return true;
        }

        if (!function_exists('proc_open')) {
            return false;
        }

        $cmd = 'nohup '.escapeshellarg($php).' '.escapeshellarg($artisan)
            .' mod-manager:run-job '.escapeshellarg($type).' '.escapeshellarg($encoded)
            .' >> '.escapeshellarg($log).' 2>&1 < /dev/null &';

        $process = proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, dirname($artisan));

        if (!is_resource($process)) {
            return false;
        }

        proc_close($process);

        return true;
    }

    public static function detectCanSpawn(): bool
    {
        $disabled = array_map(trim(...), explode(',', (string) ini_get('disable_functions')));

        if (PHP_OS_FAMILY === 'Windows') {
            if (in_array('popen', $disabled, true) || in_array('pclose', $disabled, true)) {
                return false;
            }
        } elseif (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return false;
        }

        return self::phpBinary() !== null && self::artisanPath() !== null;
    }

    public static function phpBinary(): ?string
    {
        $finder = new PhpExecutableFinder();
        $found = $finder->find(false);

        if (self::isUsablePhpBinary($found)) {
            return $found;
        }

        foreach (['/usr/bin/php', '/usr/local/bin/php'] as $candidate) {
            if (self::isUsablePhpBinary($candidate)) {
                return $candidate;
            }
        }

        if (defined('PHP_BINARY') && self::isUsablePhpBinary(PHP_BINARY)) {
            return PHP_BINARY;
        }

        return null;
    }

    public static function artisanPath(): ?string
    {
        if (!function_exists('base_path')) {
            return null;
        }

        $path = base_path('artisan');

        return is_file($path) ? $path : null;
    }

    private static function isUsablePhpBinary(mixed $path): bool
    {
        if (!is_string($path) || $path === '' || !is_executable($path)) {
            return false;
        }

        return !str_contains(strtolower(basename($path)), 'fpm');
    }
}
