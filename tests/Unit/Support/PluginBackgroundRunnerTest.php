<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class PluginBackgroundRunnerTest extends TestCase
{
    public function test_payload_round_trip_is_json_not_php_serialized(): void
    {
        $payload = ['server_id' => 42, 'project_type' => 'mod', 'nested' => ['a' => 1]];
        $encoded = PluginBackgroundRunner::encodePayload($payload);

        self::assertStringStartsNotWith('O:', $encoded);
        self::assertSame($payload, PluginBackgroundRunner::decodePayload($encoded));
    }

    public function test_disabled_runner_never_records_a_spawn(): void
    {
        $runner = PluginBackgroundRunner::disabled();

        self::assertFalse($runner->canSpawn());
        self::assertFalse($runner->run(BackgroundJob::SCAN, ['server_id' => 1]));
        self::assertSame([], $runner->spawned);
    }

    public function test_fake_runner_records_json_payloads_and_coalesces_unique_ids(): void
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $runner = PluginBackgroundRunner::fake($cache);

        self::assertTrue($runner->run(BackgroundJob::REVALIDATE, ['source_key' => 'hangar'], 'same-key', 300));
        self::assertTrue($runner->run(BackgroundJob::REVALIDATE, ['source_key' => 'hangar'], 'same-key', 300));

        self::assertCount(1, $runner->spawned);
        self::assertSame(BackgroundJob::REVALIDATE, $runner->spawned[0]['type']);
        self::assertSame('same-key', $runner->spawned[0]['unique_id']);
        self::assertTrue($cache->has($runner->uniqueCacheKey('same-key')));
    }

    public function test_failed_spawn_releases_the_unique_lock(): void
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $runner = new PluginBackgroundRunner(
            true,
            $cache,
            static fn (string $type, array $payload): bool => false,
        );

        self::assertFalse($runner->run(BackgroundJob::WARM_SEARCH, ['page' => 1], 'warm-1', 60));
        self::assertFalse($cache->has($runner->uniqueCacheKey('warm-1')));
    }

    public function test_spawn_exception_releases_the_unique_lock(): void
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $runner = new PluginBackgroundRunner(
            true,
            $cache,
            static function (): never {
                throw new RuntimeException('spawn failed');
            },
        );

        try {
            $runner->run(BackgroundJob::WARM_SEARCH, ['page' => 1], 'warm-2', 60);
            self::fail('The spawn exception must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('spawn failed', $exception->getMessage());
        }

        self::assertFalse($cache->has($runner->uniqueCacheKey('warm-2')));
    }

    public function test_php_fpm_binaries_are_not_usable(): void
    {
        $method = new ReflectionMethod(PluginBackgroundRunner::class, 'isUsablePhpBinary');

        self::assertFalse($method->invoke(null, '/usr/sbin/php-fpm'));
        self::assertFalse($method->invoke(null, '/usr/sbin/php-fpm8.5'));
        self::assertFalse($method->invoke(null, ''));
        self::assertFalse($method->invoke(null, null));
    }
}
