<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\CachedSearchOperations;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Mockery;
use PHPUnit\Framework\TestCase;

class CachedSearchOperationsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_null_spec_is_a_conclusive_empty_search_without_cache_or_upstream_io(): void
    {
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $executor->shouldNotReceive('emptyResult');
        $search = new CachedSearchOperations($this->sourceCache($executor));

        self::assertSame(['hits' => [], 'total_hits' => 0], $search->search(null));
        self::assertTrue($search->hasCached(null));
        self::assertTrue($search->hasFreshCached(null));
        self::assertFalse($search->warm(null));
    }

    public function test_warm_search_uses_background_budget_and_all_probes_share_the_same_spec(): void
    {
        $spec = new SourceFetchSpec('modrinth', 'search', ['page' => 2, 'query' => 'sodium']);
        $payload = ['hits' => [['project_id' => 'sodium']], 'total_hits' => 1];
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, 10.0)
            ->andReturn($payload);
        $search = new CachedSearchOperations($this->sourceCache($executor));

        self::assertFalse($search->hasCached($spec));
        self::assertFalse($search->hasFreshCached($spec));
        self::assertTrue($search->warm($spec));
        self::assertTrue($search->hasCached($spec));
        self::assertTrue($search->hasFreshCached($spec));
        self::assertSame($payload, $search->search($spec));
        self::assertFalse($search->warm($spec));
    }

    private function sourceCache(SourceFetchExecutorInterface $executor): SourceCache
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $config = new LaravelConfigRepository(['queue' => ['default' => 'sync']]);

        return new SourceCache($cache, new InstalledOperationManager($cache, $config), $executor);
    }
}
