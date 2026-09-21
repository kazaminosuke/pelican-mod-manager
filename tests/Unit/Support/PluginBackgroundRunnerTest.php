<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use PHPUnit\Framework\TestCase;
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

    public function test_failed_enqueue_releases_the_unique_lock(): void
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

    public function test_enqueue_exception_releases_the_unique_lock(): void
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $runner = new PluginBackgroundRunner(
            true,
            $cache,
            static function (): never {
                throw new RuntimeException('enqueue failed');
            },
        );

        try {
            $runner->run(BackgroundJob::WARM_SEARCH, ['page' => 1], 'warm-2', 60);
            self::fail('The enqueue exception must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('enqueue failed', $exception->getMessage());
        }

        self::assertFalse($cache->has($runner->uniqueCacheKey('warm-2')));
    }

    public function test_runtime_source_does_not_spawn_processes(): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 3).'/src/Support/PluginBackgroundRunner.php');

        foreach (['popen(', 'pclose(', 'proc_open(', 'proc_close(', 'shell_exec(', 'passthru(', 'Symfony\\Component\\Process'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $contents, $forbidden);
        }

        self::assertDoesNotMatchRegularExpression('/(?<!["\'])\\bexec\\s*\\(/', $contents);
        self::assertDoesNotMatchRegularExpression('/(?<!["\'])\\bsystem\\s*\\(/', $contents);
    }
}
