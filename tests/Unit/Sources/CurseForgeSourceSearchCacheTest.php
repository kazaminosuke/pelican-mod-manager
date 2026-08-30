<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Support\CatalogCompatibilityOverride;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Mockery;
use PHPUnit\Framework\TestCase;

class CurseForgeSourceSearchCacheTest extends TestCase
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
        MinecraftVersionResolver::clear();
        CatalogCompatibilityOverride::clear();
        Mockery::close();

        parent::tearDown();
    }

    public function test_returns_true_without_touching_the_cache_when_unconfigured(): void
    {
        $this->bindApiKey(null);
        $source = new CurseForgeSource($this->sourceCache($this->cache()));

        // No expectations are set on this Server mock: isConfigured() must
        // short-circuit before the source ever reads anything from it.
        $server = Mockery::mock(Server::class);

        self::assertTrue($source->hasCachedSearch($server, ProjectType::Plugin, 1, null, []));
    }

    public function test_a_cache_entry_written_by_search_is_detected_as_a_hit(): void
    {
        $this->bindApiKey('test-key');
        $cache = $this->cache();
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')->once()->andReturn(['hits' => [], 'total_hits' => 0]);
        $source = new CurseForgeSource($this->sourceCache($cache, $executor));
        $server = $this->server();

        self::assertFalse($source->hasCachedSearch($server, ProjectType::Plugin, 1, null, []));

        $source->search($server, ProjectType::Plugin, 1, null, []);

        self::assertTrue($source->hasCachedSearch($server, ProjectType::Plugin, 1, null, []));
    }

    public function test_datapack_search_uses_the_data_pack_category_without_a_mod_loader_filter(): void
    {
        $this->bindApiKey('test-key');
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function (SourceFetchSpec $spec): bool {
                self::assertSame('search', $spec->operation);
                self::assertSame(ProjectType::Datapack->value, $spec->arguments['project_type']);
                self::assertSame(12, $spec->arguments['params']['classId']);
                self::assertSame(5193, $spec->arguments['params']['categoryId']);
                self::assertArrayNotHasKey('modLoaderType', $spec->arguments['params']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 0]);
        $source = new CurseForgeSource($this->sourceCache($this->cache(), $executor));

        self::assertTrue($source->supportsProjectType(ProjectType::Datapack));
        self::assertSame(['hits' => [], 'total_hits' => 0], $source->search($this->server(), ProjectType::Datapack));
    }

    public function test_resource_pack_search_uses_the_texture_pack_class_without_the_data_pack_category(): void
    {
        $this->bindApiKey('test-key');
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function (SourceFetchSpec $spec): bool {
                self::assertSame(ProjectType::ResourcePack->value, $spec->arguments['project_type']);
                self::assertSame(12, $spec->arguments['params']['classId']);
                self::assertArrayNotHasKey('categoryId', $spec->arguments['params']);
                self::assertArrayNotHasKey('modLoaderType', $spec->arguments['params']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 0]);
        $source = new CurseForgeSource($this->sourceCache($this->cache(), $executor));

        self::assertTrue($source->supportsProjectType(ProjectType::ResourcePack));
        self::assertSame(['hits' => [], 'total_hits' => 0], $source->search($this->server(), ProjectType::ResourcePack));
    }

    public function test_resource_pack_search_maps_multiple_categories_version_and_creation_sort(): void
    {
        $this->bindApiKey('test-key');
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function (SourceFetchSpec $spec): bool {
                self::assertSame('1.20.6', $spec->arguments['params']['gameVersion']);
                self::assertSame('[12,34]', $spec->arguments['params']['categoryIds']);
                self::assertSame(11, $spec->arguments['params']['sortField']);
                self::assertSame('desc', $spec->arguments['params']['sortOrder']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 0]);

        (new CurseForgeSource($this->sourceCache($this->cache(), $executor)))
            ->search($this->server(), ProjectType::ResourcePack, filters: [
                'version' => '1.20.6',
                'categories' => ['12', '34'],
                'sort' => 'created',
            ]);
    }

    public function test_search_maps_multiple_game_versions_to_the_official_or_parameter(): void
    {
        $this->bindApiKey('test-key');
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function (SourceFetchSpec $spec): bool {
                self::assertSame('["1.20.6","1.21.1"]', $spec->arguments['params']['gameVersions']);
                self::assertArrayNotHasKey('gameVersion', $spec->arguments['params']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 0]);

        (new CurseForgeSource($this->sourceCache($this->cache(), $executor)))
            ->search($this->server(), ProjectType::ResourcePack, filters: [
                'versions' => ['1.20.6', '1.21.1'],
            ]);
    }

    public function test_category_filter_keeps_automatic_mod_version_and_loader_params(): void
    {
        $this->bindApiKey('test-key');
        $server = $this->server();
        CatalogCompatibilityOverride::set($server, '1.21.1', 'neoforge');
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function (SourceFetchSpec $spec): bool {
                self::assertSame('1.21.1', $spec->arguments['params']['gameVersion']);
                self::assertSame(6, $spec->arguments['params']['modLoaderType']);
                self::assertSame('[421]', $spec->arguments['params']['categoryIds']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 0]);

        (new CurseForgeSource($this->sourceCache($this->cache(), $executor)))
            ->search($server, ProjectType::Mod, filters: ['categories' => ['421']]);
    }

    public function test_mod_search_maps_multiple_official_loader_types(): void
    {
        $this->bindApiKey('test-key');
        $server = $this->server();
        CatalogCompatibilityOverride::set($server, '1.21.1', 'neoforge');
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function (SourceFetchSpec $spec): bool {
                self::assertSame('[4,6]', $spec->arguments['params']['modLoaderTypes']);
                self::assertArrayNotHasKey('modLoaderType', $spec->arguments['params']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 0]);

        (new CurseForgeSource($this->sourceCache($this->cache(), $executor)))
            ->search($server, ProjectType::Mod, filters: ['loaders' => ['4', '6']]);
    }

    public function test_search_clamps_an_unreachable_page_to_curseforges_final_supported_offset(): void
    {
        $this->bindApiKey('test-key');
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function (SourceFetchSpec $spec): bool {
                self::assertSame(9980, $spec->arguments['params']['index']);
                self::assertSame(20, $spec->arguments['params']['pageSize']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 12_000]);
        $source = new CurseForgeSource($this->sourceCache($this->cache(), $executor));

        self::assertSame(
            ['hits' => [], 'total_hits' => 10_000],
            $source->search($this->server(), ProjectType::Datapack, 501),
        );
    }

    public function test_project_metadata_retry_cooldown_is_visible_to_the_batched_peek_without_becoming_pending(): void
    {
        $this->bindApiKey('test-key');
        $source = new CurseForgeSource($this->sourceCache($this->cache()));

        $source->deferProjectMetadataRetries(['123']);
        $peeked = $source->peekProjects(['123']);

        self::assertNull($peeked['123']['data']);
        self::assertFalse($peeked['123']['pending']);
        self::assertTrue($peeked['123']['retry_delayed']);
    }

    private function bindApiKey(?string $key): void
    {
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            // egg_autodetect_enabled: false - this test is about search
            // caching, not egg auto-detection (Stage 8); without this,
            // MinecraftVersionResolver::resolve() (called from search())
            // would also invoke EggProfileResolver::resolve() against the
            // bare Server mock below, which stubs none of that.
            'pelican-mod-manager' => ['curseforge_api_key' => $key, 'egg_autodetect_enabled' => false],
        ]));
        Container::setInstance($container);
    }

    private function cache(): LaravelCacheRepository
    {
        return new LaravelCacheRepository(new ArrayStore());
    }

    private function sourceCache(CacheRepository $cache, ?SourceFetchExecutorInterface $executor = null): SourceCache
    {
        $config = new LaravelConfigRepository(['queue' => ['default' => 'sync']]);
        $operations = new InstalledOperationManager($cache, $config);

        return new SourceCache($cache, $operations, $executor ?? Mockery::mock(SourceFetchExecutorInterface::class));
    }

    private function server(): Server
    {
        $variables = Mockery::mock(HasMany::class);
        $variables->shouldReceive('whereIn')->with('env_variable', ['MINECRAFT_VERSION', 'MC_VERSION'])->andReturnSelf();
        $variables->shouldReceive('pluck')->with('server_value', 'env_variable')->andReturn(collect(['MINECRAFT_VERSION' => '1.21.1']));

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getKey')->andReturn(1);
        $server->shouldReceive('variables')->andReturn($variables);

        return $server;
    }
}
