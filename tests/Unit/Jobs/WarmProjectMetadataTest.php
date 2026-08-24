<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Jobs\WarmProjectMetadata;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WarmProjectMetadataTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Mockery::close();

        parent::tearDown();
    }

    public function test_unique_id_is_stable_regardless_of_input_order(): void
    {
        $first = new WarmProjectMetadata('modrinth', ['b', 'a', 'c']);
        $second = new WarmProjectMetadata('modrinth', ['c', 'b', 'a']);

        self::assertSame($first->uniqueId(), $second->uniqueId());
    }

    public function test_unique_id_differs_by_source_and_by_id_set(): void
    {
        $base = new WarmProjectMetadata('modrinth', ['a', 'b']);

        self::assertNotSame($base->uniqueId(), (new WarmProjectMetadata('curseforge', ['a', 'b']))->uniqueId());
        self::assertNotSame($base->uniqueId(), (new WarmProjectMetadata('modrinth', ['a', 'c']))->uniqueId());
    }

    public function test_handle_primes_confirmed_missing_projects_from_a_fresh_batch_response(): void
    {
        $modrinth = Mockery::mock(ModrinthSource::class);
        $modrinth->shouldReceive('isConfigured')->once()->andReturnTrue();
        $modrinth->shouldReceive('peekProjects')->once()->with(['a', 'gone'])->andReturn([
            'a' => ['data' => null, 'pending' => true],
            'gone' => ['data' => null, 'pending' => true],
        ]);
        $modrinth->shouldReceive('getProjectsByIdsForMetadataWarm')
            ->once()
            ->with(['a', 'gone'])
            ->andReturn(['a' => ['title' => 'A']]);
        $modrinth->shouldReceive('primeProjects')
            ->once()
            ->with(['a' => ['title' => 'A'], 'gone' => null]);

        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->once()->with('modrinth')->andReturn($modrinth);

        (new WarmProjectMetadata('modrinth', ['a', 'gone']))->handle($registry);

        self::assertTrue(true);
    }

    public function test_handle_keeps_positive_only_priming_for_non_batch_sources(): void
    {
        $source = Mockery::mock(ProjectSourceInterface::class);
        $source->shouldReceive('isConfigured')->once()->andReturnTrue();
        $source->shouldReceive('getProjectsByIds')->once()->with(['a'])->andReturn([]);
        $source->shouldNotReceive('primeProjects');

        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->once()->with('modrinth')->andReturn($source);

        (new WarmProjectMetadata('modrinth', ['a']))->handle($registry);

        self::assertTrue(true);
    }

    public function test_handle_marks_individual_metadata_entries_for_a_short_retry_delay_when_an_authoritative_batch_fails(): void
    {
        $exception = new RuntimeException('upstream unavailable');
        $exceptionHandler = Mockery::mock(ExceptionHandler::class);
        $exceptionHandler->shouldReceive('report')->once()->with($exception);
        $container = new Container();
        $container->instance(ExceptionHandler::class, $exceptionHandler);
        Container::setInstance($container);

        $modrinth = Mockery::mock(ModrinthSource::class);
        $modrinth->shouldReceive('isConfigured')->once()->andReturnTrue();
        $modrinth->shouldReceive('peekProjects')->once()->with(['a', 'b'])->andReturn([
            'a' => ['data' => null, 'pending' => true],
            'b' => ['data' => null, 'pending' => true],
        ]);
        $modrinth->shouldReceive('getProjectsByIdsForMetadataWarm')
            ->once()
            ->with(['a', 'b'])
            ->andThrow($exception);
        $deferredIds = null;
        $modrinth->shouldReceive('deferProjectMetadataRetries')
            ->once()
            ->with(['a', 'b'])
            ->andReturnUsing(function (array $ids) use (&$deferredIds): void {
                $deferredIds = $ids;
            });
        $modrinth->shouldNotReceive('primeProjects');

        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->once()->with('modrinth')->andReturn($modrinth);

        (new WarmProjectMetadata('modrinth', ['a', 'b']))->handle($registry);

        self::assertSame(['a', 'b'], $deferredIds);
    }

    public function test_handle_refetches_only_ids_still_pending_when_an_overlapping_job_already_filled_cache(): void
    {
        $modrinth = Mockery::mock(ModrinthSource::class);
        $modrinth->shouldReceive('isConfigured')->once()->andReturnTrue();
        $modrinth->shouldReceive('peekProjects')->once()->with(['a', 'b', 'cooldown'])->andReturn([
            'a' => ['data' => ['title' => 'A'], 'pending' => false],
            'b' => ['data' => null, 'pending' => true],
            'cooldown' => ['data' => null, 'pending' => false, 'retry_delayed' => true],
        ]);
        $modrinth->shouldReceive('getProjectsByIdsForMetadataWarm')
            ->once()
            ->with(['b'])
            ->andReturn(['b' => ['title' => 'B']]);
        $modrinth->shouldReceive('primeProjects')
            ->once()
            ->with(['b' => ['title' => 'B']]);

        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->once()->with('modrinth')->andReturn($modrinth);

        (new WarmProjectMetadata('modrinth', ['a', 'b', 'cooldown']))->handle($registry);

        self::assertTrue(true);
    }

    public function test_handle_does_nothing_for_an_empty_id_list(): void
    {
        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldNotReceive('getByValue');

        (new WarmProjectMetadata('modrinth', []))->handle($registry);

        self::assertTrue(true);
    }

    public function test_handle_does_not_negative_prime_when_the_source_was_disabled_after_dispatch(): void
    {
        $source = Mockery::mock(ProjectSourceInterface::class);
        $source->shouldReceive('isConfigured')->once()->andReturnFalse();
        $source->shouldNotReceive('getProjectsByIds');
        $source->shouldNotReceive('primeProjects');
        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->once()->with('curseforge')->andReturn($source);

        (new WarmProjectMetadata('curseforge', ['123']))->handle($registry);

        self::assertTrue(true);
    }

    public function test_unique_id_treats_duplicate_and_blank_ids_as_the_same_batch(): void
    {
        $left = new WarmProjectMetadata('modrinth', ['a', ' ', 'b', 'a']);
        $right = new WarmProjectMetadata('modrinth', ['b', 'a']);

        self::assertSame($left->uniqueId(), $right->uniqueId());
    }

    public function test_handle_skips_priming_when_the_source_is_unrecognized(): void
    {
        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->once()->with('gone')->andReturnNull();

        (new WarmProjectMetadata('gone', ['a']))->handle($registry);

        self::assertTrue(true);
    }
}
