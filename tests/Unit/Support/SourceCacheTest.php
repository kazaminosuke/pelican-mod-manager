<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchHandlerInterface;
use Kazaminosuke\ModManager\Exceptions\PartialSourceFetchException;
use Kazaminosuke\ModManager\Jobs\RevalidateSourceCache;
use Kazaminosuke\ModManager\Jobs\WarmProjectMetadata;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchExecutor;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SourceCacheTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Mockery::close();

        parent::tearDown();
    }

    public function test_fresh_hit_returns_cached_data_without_fetching(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $data = ['hits' => [['project_id' => 'one']], 'total_hits' => 1];
        $cache->put($spec->cacheKey(), $this->entry($data, time() + 60), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'database', $executor)
            ->swr($spec, CacheProfile::Search);

        self::assertSame($data, $result);
    }

    public function test_peek_many_uses_one_cache_many_read_and_preserves_hit_and_miss_state(): void
    {
        $first = new SourceFetchSpec('modrinth', 'project', ['project_id' => 'first']);
        $second = new SourceFetchSpec('modrinth', 'project', ['project_id' => 'second']);
        $missing = new SourceFetchSpec('modrinth', 'project', ['project_id' => 'missing']);
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('many')
            ->once()
            ->with([
                $first->cacheKey(),
                $second->cacheKey(),
                $missing->cacheKey(),
                $first->cacheKey().':failure:v1',
                $second->cacheKey().':failure:v1',
                $missing->cacheKey().':failure:v1',
            ])
            ->andReturn([
                $first->cacheKey() => $this->entry(['title' => 'First'], time() + 60),
                $second->cacheKey() => $this->entry(null, time() - 60),
            ]);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'sync', $executor)->peekMany([
            'first' => $first,
            'second' => $second,
            'missing' => $missing,
        ]);

        self::assertSame(['title' => 'First'], $result['first']['data']);
        self::assertTrue($result['first']['hit']);
        self::assertTrue($result['first']['fresh']);
        self::assertTrue($result['second']['hit']);
        self::assertFalse($result['second']['fresh']);
        self::assertNull($result['second']['data']);
        self::assertFalse($result['missing']['hit']);
        self::assertFalse($result['missing']['retry_delayed']);
    }

    public function test_peek_many_reads_the_same_payload_shape_from_the_laravel_cache_repository(): void
    {
        $cache = $this->cache();
        $first = new SourceFetchSpec('modrinth', 'project', ['project_id' => 'first']);
        $second = new SourceFetchSpec('modrinth', 'project', ['project_id' => 'second']);
        $cache->put($first->cacheKey(), $this->entry(['title' => 'First'], time() + 60), 300);
        $cache->put($second->cacheKey(), $this->entry(['title' => 'Second'], time() - 60), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'sync', $executor)->peekMany([
            'first' => $first,
            'second' => $second,
        ]);

        self::assertSame(['title' => 'First'], $result['first']['data']);
        self::assertTrue($result['first']['fresh']);
        self::assertSame(['title' => 'Second'], $result['second']['data']);
        self::assertFalse($result['second']['fresh']);
    }

    public function test_peek_many_marks_a_cold_entry_with_an_active_failure_marker_as_retry_delayed(): void
    {
        $cache = $this->cache();
        $spec = new SourceFetchSpec('modrinth', 'project', ['project_id' => 'offline']);
        $cache->put($spec->cacheKey().':failure:v1', ['v' => 1, 'failed_until' => time() + 30], 30);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'sync', $executor)->peekMany(['offline' => $spec]);

        self::assertFalse($result['offline']['hit']);
        self::assertNull($result['offline']['data']);
        self::assertTrue($result['offline']['retry_delayed']);
    }

    public function test_prime_many_skips_unobserved_failure_marker_deletes(): void
    {
        $first = new SourceFetchSpec('modrinth', 'project', ['project_id' => 'first']);
        $second = new SourceFetchSpec('modrinth', 'project', ['project_id' => 'second']);
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('putMany')->once()->withArgs(function (array $payloads, int $ttl) use ($first, $second): bool {
            self::assertSame(CacheProfile::ProjectMetadata->staleTtlSeconds(), $ttl);
            self::assertSame(['title' => 'First'], $payloads[$first->cacheKey()]['data']);
            self::assertNull($payloads[$second->cacheKey()]['data']);
            self::assertSame(SourceCache::SCHEMA_VERSION, $payloads[$first->cacheKey()]['v']);
            self::assertIsInt($payloads[$first->cacheKey()]['fresh_until']);

            return true;
        });
        $cache->shouldNotReceive('forget');
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');

        $this->sourceCache($cache, 'sync', $executor)->primeMany([
            ['spec' => $first, 'data' => ['title' => 'First']],
            ['spec' => $second, 'data' => null],
        ], CacheProfile::ProjectMetadata);
    }

    public function test_prime_many_persists_a_negative_project_cache_hit(): void
    {
        $cache = $this->cache();
        $spec = new SourceFetchSpec('modrinth', 'project', ['project_id' => 'gone']);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');
        $sourceCache = $this->sourceCache($cache, 'sync', $executor);

        $sourceCache->primeMany([
            ['spec' => $spec, 'data' => null],
        ], CacheProfile::ProjectMetadata);

        $peeked = $sourceCache->peek($spec);
        self::assertTrue($peeked['hit']);
        self::assertTrue($peeked['fresh']);
        self::assertNull($peeked['data']);
    }

    public function test_hot_probes_share_one_entry_cache_read(): void
    {
        $store = new class extends ArrayStore
        {
            public int $getCalls = 0;

            public function get($key)
            {
                $this->getCalls++;

                return parent::get($key);
            }
        };
        $cache = new LaravelCacheRepository($store);
        $spec = $this->spec();
        $data = ['hits' => [['project_id' => 'one']], 'total_hits' => 1];
        $cache->put($spec->cacheKey(), $this->entry($data, time() + 60), 300);
        $store->getCalls = 0;
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $sourceCache = $this->sourceCache($cache, 'database', $executor);

        self::assertTrue($sourceCache->peek($spec)['hit']);
        self::assertTrue($sourceCache->peek($spec)['fresh']);
        self::assertSame($data, $sourceCache->swr($spec, CacheProfile::Search));
        self::assertTrue($sourceCache->peek($spec)['fresh']);
        self::assertSame(1, $store->getCalls);
    }

    public function test_prime_many_clears_an_observed_failure_marker_only(): void
    {
        $cache = $this->cache();
        $spec = new SourceFetchSpec('modrinth', 'project', ['project_id' => 'first']);
        $failureKey = $spec->cacheKey().':failure:v1';
        $cache->put($failureKey, [
            'v' => 1,
            'failed_until' => time() + 30,
        ], 30);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $sourceCache = $this->sourceCache($cache, 'sync', $executor);

        self::assertTrue($sourceCache->peek($spec)['retry_delayed']);

        $sourceCache->primeMany([
            ['spec' => $spec, 'data' => ['title' => 'First']],
        ], CacheProfile::ProjectMetadata);

        self::assertNull($cache->get($failureKey));
        self::assertSame('First', $sourceCache->peek($spec)['data']['title']);
    }

    public function test_fresh_required_fetch_revalidates_a_stale_entry_instead_of_using_it_as_authoritative(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $cache->put($spec->cacheKey(), $this->entry(['old'], time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->backgroundTimeoutSeconds())
            ->andReturn(['new']);
        $executor->shouldNotReceive('emptyResult');

        self::assertSame(
            ['new'],
            $this->sourceCache($cache, 'sync', $executor)->swrRequiredFresh($spec, CacheProfile::Search),
        );
        self::assertSame(['new'], $cache->get($spec->cacheKey())['data']);
    }

    public function test_stale_hit_returns_immediately_and_dispatches_one_unique_job_for_repeated_reads(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $stale = ['hits' => [['project_id' => 'stale']], 'total_hits' => 1];
        $cache->put($spec->cacheKey(), $this->entry($stale, time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');
        $dispatcher = $this->prepareDispatchContainer($cache);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (RevalidateSourceCache $job): bool => $job->uniqueId() === $spec->cacheKey())
            ->andReturnNull();

        $sourceCache = $this->sourceCache($cache, 'database', $executor);

        self::assertSame($stale, $sourceCache->swr($spec, CacheProfile::Search));
        self::assertSame($stale, $sourceCache->swr($spec, CacheProfile::Search));

        $peeked = $sourceCache->peek($spec);
        self::assertTrue($peeked['hit']);
        self::assertFalse($peeked['fresh']);
    }

    public function test_search_cold_fetch_is_serialized_by_a_store_lock(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $data = ['hits' => [['project_id' => 'one']], 'total_hits' => 1];
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')->once()->andReturn($data);
        $executor->shouldNotReceive('emptyResult');
        $sourceCache = $this->sourceCache($cache, 'sync', $executor);

        self::assertSame($data, $sourceCache->swr($spec, CacheProfile::Search));
        self::assertSame($data, $sourceCache->swr($spec, CacheProfile::Search));
        self::assertTrue($sourceCache->peek($spec)['fresh']);
    }

    public function test_search_lock_timeout_never_starts_a_second_inline_fetch(): void
    {
        $lock = Mockery::mock(CacheLock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException());
        $lock->shouldNotReceive('release');
        $store = new class($lock) extends ArrayStore
        {
            public function __construct(private readonly CacheLock $forcedLock)
            {
                parent::__construct();
            }

            public function lock($name, $seconds = 0, $owner = null): CacheLock
            {
                return $this->forcedLock;
            }
        };
        $cache = new LaravelCacheRepository($store);
        $spec = $this->spec();
        $empty = ['hits' => [], 'total_hits' => 0];
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldReceive('emptyResult')->once()->with($spec)->andReturn($empty);

        $result = $this->sourceCache($cache, 'sync', $executor)
            ->swr($spec, CacheProfile::Search);

        self::assertSame($empty, $result);
        self::assertIsArray($cache->get($spec->cacheKey().':failure:v1'));
    }

    public function test_queue_runtime_reset_discards_process_probe_memos(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $old = ['hits' => [['project_id' => 'old']], 'total_hits' => 1];
        $new = ['hits' => [['project_id' => 'new']], 'total_hits' => 1];
        $cache->put($spec->cacheKey(), $this->entry($old, time() + 60), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $sourceCache = $this->sourceCache($cache, 'sync', $executor);

        self::assertSame($old, $sourceCache->peek($spec)['data']);
        $cache->put($spec->cacheKey(), $this->entry($new, time() + 60), 300);

        // A queue-loop reset represents the boundary between two jobs on the
        // same singleton worker process.
        $sourceCache->clearRuntimeCaches();

        self::assertSame($new, $sourceCache->peek($spec)['data']);
    }

    public function test_fetch_failure_returns_stale_data_and_writes_failure_marker(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $stale = ['hits' => [['project_id' => 'stale']], 'total_hits' => 1];
        $cache->put($spec->cacheKey(), $this->entry($stale, time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->inlineBudgetSeconds())
            ->andThrow(new RuntimeException('offline'));
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'sync', $executor)
            ->swr($spec, CacheProfile::Search);

        self::assertSame($stale, $result);
        self::assertIsArray($cache->get($spec->cacheKey().':failure:v1'));
        self::assertSame($stale, $cache->get($spec->cacheKey())['data']);
    }

    public function test_fetch_failure_without_stale_returns_empty_marks_failure_and_queues_one_retry(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $empty = ['hits' => [], 'total_hits' => 0];
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->inlineBudgetSeconds())
            ->andThrow(new RuntimeException('offline'));
        $executor->shouldReceive('emptyResult')->twice()->with($spec)->andReturn($empty);
        $dispatcher = $this->prepareDispatchContainer($cache);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (RevalidateSourceCache $job): bool => $job->uniqueId() === $spec->cacheKey())
            ->andReturnNull();
        $sourceCache = $this->sourceCache($cache, 'database', $executor);

        self::assertSame($empty, $sourceCache->swr($spec, CacheProfile::Search));
        self::assertIsArray($cache->get($spec->cacheKey().':failure:v1'));

        // The marker suppresses another inline call and another queued job.
        self::assertSame($empty, $sourceCache->swr($spec, CacheProfile::Search));
    }

    public function test_partial_fetch_returns_uncached_fallback_and_marks_failure(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $fallback = ['hits' => [['project_id' => 'partial']], 'total_hits' => 1];
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->inlineBudgetSeconds())
            ->andThrow(new PartialSourceFetchException('partial', $fallback));
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'sync', $executor)
            ->swr($spec, CacheProfile::Search);

        self::assertSame($fallback, $result);
        self::assertNull($cache->get($spec->cacheKey()));
        self::assertIsArray($cache->get($spec->cacheKey().':failure:v1'));
    }

    public function test_required_fetch_propagates_a_cold_failure_instead_of_returning_empty(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->backgroundTimeoutSeconds())
            ->andThrow(new RuntimeException('offline'));
        $executor->shouldNotReceive('emptyResult');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('offline');

        $this->sourceCache($cache, 'sync', $executor)
            ->swrRequired($spec, CacheProfile::Search);
    }

    public function test_required_fetch_accepts_stale_data_without_contacting_upstream(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $stale = ['hits' => [['project_id' => 'stale']], 'total_hits' => 1];
        $cache->put($spec->cacheKey(), $this->entry($stale, time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'sync', $executor)
            ->swrRequired($spec, CacheProfile::Search);

        self::assertSame($stale, $result);
    }

    public function test_sync_queue_refreshes_stale_data_inline_without_dispatching(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $cache->put($spec->cacheKey(), $this->entry(['old'], time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->inlineBudgetSeconds())
            ->andReturn(['new']);
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'null', $executor)
            ->swr($spec, CacheProfile::Search);

        self::assertSame(['new'], $result);
        self::assertSame(['new'], $cache->get($spec->cacheKey())['data']);
    }

    public function test_deferred_fresh_hit_returns_data_without_fetching_or_dispatching(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $data = ['hits' => [['project_id' => 'one']], 'total_hits' => 1];
        $cache->put($spec->cacheKey(), $this->entry($data, time() + 60), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');

        $result = $this->sourceCache($cache, 'database', $executor)
            ->swrDeferred($spec, CacheProfile::Search);

        self::assertSame($data, $result['data']);
        self::assertFalse($result['pending']);
    }

    public function test_deferred_stale_hit_returns_data_immediately_and_queues_one_revalidation(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $stale = ['hits' => [['project_id' => 'stale']], 'total_hits' => 1];
        $cache->put($spec->cacheKey(), $this->entry($stale, time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');
        $dispatcher = $this->prepareDispatchContainer($cache);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (RevalidateSourceCache $job): bool => $job->uniqueId() === $spec->cacheKey())
            ->andReturnNull();

        $result = $this->sourceCache($cache, 'database', $executor)
            ->swrDeferred($spec, CacheProfile::Search);

        self::assertSame($stale, $result['data']);
        self::assertFalse($result['pending']);
    }

    public function test_deferred_miss_never_fetches_inline_and_queues_one_revalidation(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $empty = ['hits' => [], 'total_hits' => 0];
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldReceive('emptyResult')->once()->with($spec)->andReturn($empty);
        $dispatcher = $this->prepareDispatchContainer($cache);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (RevalidateSourceCache $job): bool => $job->uniqueId() === $spec->cacheKey())
            ->andReturnNull();

        $result = $this->sourceCache($cache, 'database', $executor)
            ->swrDeferred($spec, CacheProfile::Search);

        self::assertSame($empty, $result['data']);
        self::assertTrue($result['pending']);
    }

    public function test_deferred_miss_on_sync_queue_never_fetches_inline_or_reports_an_unresolvable_pending_state(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $empty = ['hits' => [], 'total_hits' => 0];
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldReceive('emptyResult')->once()->with($spec)->andReturn($empty);

        $result = $this->sourceCache($cache, 'sync', $executor)
            ->swrDeferred($spec, CacheProfile::Search);

        self::assertSame($empty, $result['data']);
        self::assertFalse($result['pending']);
        self::assertNull($cache->get($spec->cacheKey()));
    }

    public function test_deferred_miss_with_a_failure_marker_does_not_report_a_pending_refresh(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $empty = ['hits' => [], 'total_hits' => 0];
        $cache->put($spec->cacheKey().':failure:v1', ['v' => 1, 'failed_until' => time() + 30], 30);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldReceive('emptyResult')->once()->with($spec)->andReturn($empty);
        $dispatcher = $this->prepareDispatchContainer($cache);
        $dispatcher->shouldNotReceive('dispatch');

        $result = $this->sourceCache($cache, 'database', $executor)
            ->swrDeferred($spec, CacheProfile::Search);

        self::assertSame($empty, $result['data']);
        self::assertFalse($result['pending']);
        self::assertTrue($result['retry_delayed']);
    }

    public function test_revalidation_failure_preserves_existing_stale_entry(): void
    {
        $cache = $this->cache();
        $spec = $this->spec();
        $cache->put($spec->cacheKey(), $this->entry(['stale'], time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::Search->backgroundTimeoutSeconds())
            ->andThrow(new RuntimeException('offline'));

        $this->sourceCache($cache, 'database', $executor)
            ->revalidate($spec, CacheProfile::Search);

        self::assertSame(['stale'], $cache->get($spec->cacheKey())['data']);
        self::assertIsArray($cache->get($spec->cacheKey().':failure:v1'));
    }

    public function test_successful_project_batch_revalidation_queues_individual_metadata_priming(): void
    {
        $cache = $this->cache();
        $spec = new SourceFetchSpec('modrinth', 'projects', ['project_ids' => ['b', 'a', '']]);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, CacheProfile::ProjectMetadata->backgroundTimeoutSeconds())
            ->andReturn(['a' => ['title' => 'A'], 'b' => ['title' => 'B']]);
        $sourceCache = $this->sourceCache($cache, 'database', $executor);
        $source = Mockery::mock(ModrinthSource::class);
        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->once()->with('modrinth')->andReturn($source);
        $dispatcher = $this->prepareDispatchContainer($cache);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (WarmProjectMetadata $job): bool => $job->sourceKey === 'modrinth' && $job->projectIds === ['b', 'a'])
            ->andReturnNull();

        (new RevalidateSourceCache($spec, CacheProfile::ProjectMetadata))->handle($sourceCache, $registry);

        self::assertTrue(true);
    }

    public function test_cache_profiles_match_the_stage_three_policy(): void
    {
        self::assertSame(30 * 24 * 60 * 60, CacheProfile::HashMatch->freshTtlSeconds());
        self::assertNull(CacheProfile::HashMatch->staleTtlSeconds());
        self::assertSame(30 * 24 * 60 * 60, CacheProfile::VersionFile->freshTtlSeconds());
        self::assertNull(CacheProfile::VersionFile->staleTtlSeconds());
        self::assertSame(10 * 60, CacheProfile::Search->freshTtlSeconds());
        self::assertSame(24 * 60 * 60, CacheProfile::Search->staleTtlSeconds());
        self::assertSame(24 * 60 * 60, CacheProfile::ProjectMetadata->freshTtlSeconds());
        self::assertSame(7 * 24 * 60 * 60, CacheProfile::ProjectMetadata->staleTtlSeconds());
        self::assertSame(30 * 60, CacheProfile::InstalledLatest->freshTtlSeconds());
        self::assertSame(24 * 60 * 60, CacheProfile::InstalledLatest->staleTtlSeconds());
        self::assertSame(7 * 24 * 60 * 60, CacheProfile::Identity->freshTtlSeconds());
        self::assertSame(30 * 24 * 60 * 60, CacheProfile::Identity->staleTtlSeconds());
        self::assertSame(1.5, CacheProfile::Search->inlineBudgetSeconds());
        self::assertSame(30, CacheProfile::Search->failureMarkerTtlSeconds());
    }

    public function test_fetch_spec_is_canonical_and_rejects_non_serializable_arguments(): void
    {
        $left = new SourceFetchSpec('modrinth', 'search', [
            'filters' => ['sort' => 'downloads', 'category' => null],
            'page' => 1,
        ]);
        $right = new SourceFetchSpec('modrinth', 'search', [
            'page' => 1,
            'filters' => ['category' => null, 'sort' => 'downloads'],
        ]);

        self::assertSame($left->cacheKey(), $right->cacheKey());

        $this->expectException(InvalidArgumentException::class);
        new SourceFetchSpec('modrinth', 'search', ['server' => new \stdClass()]);
    }

    public function test_revalidation_job_is_unique_by_source_cache_key(): void
    {
        $spec = $this->spec();
        $job = new RevalidateSourceCache($spec, CacheProfile::Search);

        self::assertInstanceOf(ShouldBeUnique::class, $job);
        self::assertSame($spec->cacheKey(), $job->uniqueId());
        self::assertSame($job->uniqueId(), (new RevalidateSourceCache($spec, CacheProfile::Search))->uniqueId());
    }

    public function test_executor_resolves_registry_lazily_and_delegates_to_source_handler(): void
    {
        $spec = $this->spec();
        $handler = Mockery::mock(ProjectSourceInterface::class, SourceFetchHandlerInterface::class);
        $handler->shouldReceive('fetchSourceData')->once()->with($spec, 1.5)->andReturn(['ok']);
        $handler->shouldReceive('emptySourceData')->once()->with($spec)->andReturn([]);
        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('getByValue')->twice()->with('modrinth')->andReturn($handler);
        $container = Mockery::mock(\Illuminate\Contracts\Container\Container::class);
        $container->shouldReceive('make')->twice()->with(ProjectSourceRegistry::class)->andReturn($registry);
        $executor = new SourceFetchExecutor($container);

        self::assertSame(['ok'], $executor->fetch($spec, 1.5));
        self::assertSame([], $executor->emptyResult($spec));
    }

    private function cache(): LaravelCacheRepository
    {
        return new LaravelCacheRepository(new ArrayStore());
    }

    private function sourceCache(
        CacheRepository $cache,
        string $queueDriver,
        SourceFetchExecutorInterface $executor,
    ): SourceCache {
        $config = new LaravelConfigRepository(['queue' => ['default' => $queueDriver]]);
        $operations = new InstalledOperationManager($cache, $config);

        return new SourceCache($cache, $operations, $executor);
    }

    private function prepareDispatchContainer(CacheRepository $cache): Dispatcher
    {
        $config = new LaravelConfigRepository([
            'cache' => ['default' => 'array'],
            'queue' => ['default' => 'database'],
        ]);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $context = Mockery::mock(ContextRepository::class);
        $context->shouldReceive('addHidden')->zeroOrMoreTimes();
        $context->shouldReceive('forgetHidden')->zeroOrMoreTimes();
        $container = new Container();
        $container->instance(CacheRepository::class, $cache);
        $container->instance(ConfigRepository::class, $config);
        $container->instance('config', $config);
        $container->instance(Dispatcher::class, $dispatcher);
        $container->instance(ContextRepository::class, $context);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        return $dispatcher;
    }

    /** @return array{v: int, data: mixed, fresh_until: int} */
    private function entry(mixed $data, int $freshUntil): array
    {
        return [
            'v' => SourceCache::SCHEMA_VERSION,
            'data' => $data,
            'fresh_until' => $freshUntil,
        ];
    }

    private function spec(): SourceFetchSpec
    {
        return new SourceFetchSpec('modrinth', 'search', [
            'page' => 1,
            'query' => 'sodium',
        ]);
    }
}
