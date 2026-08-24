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
