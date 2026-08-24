<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\CachedProjectMetadata;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Mockery;
use PHPUnit\Framework\TestCase;

class CachedProjectMetadataTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_authoritative_batch_read_retains_the_existing_stale_fast_path(): void
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $spec = $this->batchSpec();
        $stale = ['one' => ['title' => 'Stale']];
        $cache->put($spec->cacheKey(), $this->entry($stale, time() - 1), 300);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $metadata = new CachedProjectMetadata($this->sourceCache($cache, $executor));

        self::assertSame($stale, $metadata->getBatch($spec, authoritative: true));
    }

    public function test_fresh_required_batch_read_revalidates_stale_data_with_the_background_budget(): void
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $spec = $this->batchSpec();
        $cache->put($spec->cacheKey(), $this->entry(['one' => ['title' => 'Stale']], time() - 1), 300);
        $fresh = ['one' => ['title' => 'Fresh']];
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->with($spec, 10.0)
            ->andReturn($fresh);
        $metadata = new CachedProjectMetadata($this->sourceCache($cache, $executor));

        self::assertSame($fresh, $metadata->getBatch($spec, authoritative: true, freshRequired: true));
        self::assertSame($fresh, $cache->get($spec->cacheKey())['data'] ?? null);
    }

    private function sourceCache(
        LaravelCacheRepository $cache,
        SourceFetchExecutorInterface $executor,
    ): SourceCache {
        $config = new LaravelConfigRepository(['queue' => ['default' => 'sync']]);

        return new SourceCache($cache, new InstalledOperationManager($cache, $config), $executor);
    }

    /** @param array<string, mixed> $data
     * @return array{v: int, data: array<string, mixed>, fresh_until: int}
     */
    private function entry(array $data, int $freshUntil): array
    {
        return [
            'v' => SourceCache::SCHEMA_VERSION,
            'data' => $data,
            'fresh_until' => $freshUntil,
        ];
    }

    private function batchSpec(): SourceFetchSpec
    {
        return new SourceFetchSpec('modrinth', 'projects', ['project_ids' => ['one']]);
    }
}
