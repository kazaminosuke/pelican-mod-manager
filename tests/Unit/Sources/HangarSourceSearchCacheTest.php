<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Support\CacheVersion;
use Kazaminosuke\ModManager\Support\CatalogCompatibilityOverride;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Mockery;
use PHPUnit\Framework\TestCase;

class HangarSourceSearchCacheTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'queue' => ['default' => 'sync'],
            'pelican-mod-manager' => ['hangar_api_key' => null],
        ]));
        $container->instance('cache', new LaravelCacheRepository(new ArrayStore()));
        $container->instance(Factory::class, new Factory());
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        CatalogCompatibilityOverride::clear();
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Mockery::close();
        parent::tearDown();
    }

    public function test_catalog_version_options_use_the_official_platform_metadata_endpoint(): void
    {
        Http::fake([
            'hangar.papermc.io/api/v1/platforms/PAPER/versions*' => Http::response([
                ['version' => '1.21', 'subVersions' => ['1.21.4', '1.21.1']],
                ['version' => '1.20', 'subVersions' => ['1.20.6']],
            ]),
        ]);

        $cache = new LaravelCacheRepository(new ArrayStore());
        $operations = new InstalledOperationManager($cache, app('config'));
        $source = new HangarSource(new SourceCache(
            $cache,
            $operations,
            Mockery::mock(SourceFetchExecutorInterface::class),
        ));

        self::assertSame([
            '1.21.4' => '1.21.4',
            '1.21.1' => '1.21.1',
            '1.21' => '1.21',
            '1.20.6' => '1.20.6',
            '1.20' => '1.20',
        ], $source->catalogVersionOptions('paper'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://hangar.papermc.io/api/v1/platforms/PAPER/versions');
    }

    public function test_search_passes_supported_platform_version_category_tag_and_sort_filters(): void
    {
        $server = Mockery::mock(Server::class);
        CatalogCompatibilityOverride::set($server, '1.21.4', 'paper');

        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function ($spec): bool {
                self::assertSame([
                    'platform' => 'VELOCITY',
                    'version' => '1.21.1',
                    'limit' => 20,
                    'offset' => 20,
                    'sort' => '-recent_downloads',
                    'category' => 'admin_tools',
                    'tag' => 'SUPPORTS_FOLIA',
                ], $spec->arguments['params']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 0]);

        $cache = new LaravelCacheRepository(new ArrayStore());
        $operations = new InstalledOperationManager($cache, app('config'));
        $source = new HangarSource(new SourceCache($cache, $operations, $executor));

        self::assertSame(
            ['hits' => [], 'total_hits' => 0],
            $source->search($server, ProjectType::Plugin, 2, filters: [
                'sort' => 'recent_downloads',
                'platform' => 'VELOCITY',
                'version' => '1.21.1',
                'category' => 'admin_tools',
                'tag' => 'SUPPORTS_FOLIA',
            ]),
        );
    }

    public function test_explicit_platform_change_does_not_reuse_an_incompatible_automatic_version(): void
    {
        $server = Mockery::mock(Server::class);
        CatalogCompatibilityOverride::set($server, '1.21.11', 'paper');

        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function ($spec): bool {
                self::assertSame([
                    'platform' => 'VELOCITY',
                    'limit' => 20,
                    'offset' => 0,
                    'sort' => '-downloads',
                    'tag' => 'LIBRARY',
                ], $spec->arguments['params']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 0]);

        $cache = new LaravelCacheRepository(new ArrayStore());
        $operations = new InstalledOperationManager($cache, app('config'));
        $source = new HangarSource(new SourceCache($cache, $operations, $executor));

        $source->search($server, ProjectType::Plugin, filters: [
            'platform' => 'VELOCITY',
            'tag' => 'LIBRARY',
        ]);
    }

    public function test_velocity_server_search_uses_its_proxy_version(): void
    {
        $variables = Mockery::mock(HasMany::class);
        $variables->shouldReceive('whereIn')->with('env_variable', ['VELOCITY_VERSION'])->andReturnSelf();
        $variables->shouldReceive('pluck')->with('server_value', 'env_variable')->andReturn(collect(['VELOCITY_VERSION' => '3.4.0']));
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('variables')->andReturn($variables);
        CatalogCompatibilityOverride::set($server, '26.1.2', 'velocity');

        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function ($spec): bool {
                self::assertSame('VELOCITY', $spec->arguments['params']['platform']);
                self::assertSame('3.4.0', $spec->arguments['params']['version']);
                self::assertArrayNotHasKey('platformVersion', $spec->arguments['params']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 0]);

        $cache = new LaravelCacheRepository(new ArrayStore());
        $source = new HangarSource(new SourceCache($cache, new InstalledOperationManager($cache, app('config')), $executor));

        $source->search($server, ProjectType::Plugin);
    }

    public function test_velocity_latest_alias_omits_the_version_filter(): void
    {
        $variables = Mockery::mock(HasMany::class);
        $variables->shouldReceive('whereIn')->with('env_variable', ['VELOCITY_VERSION'])->andReturnSelf();
        $variables->shouldReceive('pluck')->with('server_value', 'env_variable')->andReturn(collect(['VELOCITY_VERSION' => 'latest']));
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('variables')->andReturn($variables);
        CatalogCompatibilityOverride::set($server, '26.1.2', 'velocity');

        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function ($spec): bool {
                self::assertSame('VELOCITY', $spec->arguments['params']['platform']);
                self::assertArrayNotHasKey('version', $spec->arguments['params']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 0]);

        $cache = new LaravelCacheRepository(new ArrayStore());
        $source = new HangarSource(new SourceCache($cache, new InstalledOperationManager($cache, app('config')), $executor));

        $source->search($server, ProjectType::Plugin);
    }

    public function test_hash_lookup_404_is_a_normal_miss_without_a_failure_marker(): void
    {
        $hash = str_repeat('a', 64);
        // The Http facade's resolved Factory outlives each test's container
        // swap, and fake() only appends stubs - swap in a fresh factory so an
        // earlier test's stub for the same URL pattern cannot win.
        Http::swap($factory = new Factory());
        $factory->fake([
            'hangar.papermc.io/api/v1/versions/hash/*' => $factory->response(
                ['message' => 'No project found for version hash'],
                404,
            ),
        ]);

        $cache = new LaravelCacheRepository(new ArrayStore());
        $operations = new InstalledOperationManager($cache, app('config'));
        $executor = new class implements SourceFetchExecutorInterface
        {
            public ?HangarSource $source = null;

            public function fetch(SourceFetchSpec $spec, float $timeoutSeconds): mixed
            {
                return $this->source->fetchSourceData($spec, $timeoutSeconds);
            }

            public function emptyResult(SourceFetchSpec $spec): mixed
            {
                return $this->source->emptySourceData($spec);
            }
        };
        $source = new HangarSource(new SourceCache($cache, $operations, $executor));
        $executor->source = $source;

        // A SpigotMC-only plugin has no Hangar project: the scan must treat
        // that 404 as an ordinary "no match" instead of a source outage.
        self::assertSame([], $source->findVersionsByHashAuthoritatively(['spigot-only.jar' => $hash]));

        $spec = new SourceFetchSpec('hangar', 'hash_match', [
            'generation' => CacheVersion::hangarHash(),
            'hash' => $hash,
        ]);
        self::assertNull($cache->get($spec->cacheKey().':failure:v1'));
        // The negative result is intentionally not persisted.
        self::assertNull($cache->get($spec->cacheKey()));
        Http::assertSentCount(1);
    }

    public function test_hash_lookup_5xx_remains_a_source_failure(): void
    {
        $hash = str_repeat('b', 64);
        Http::swap($factory = new Factory());
        $factory->fake([
            'hangar.papermc.io/api/v1/versions/hash/*' => $factory->response('upstream exploded', 500),
        ]);

        $cache = new LaravelCacheRepository(new ArrayStore());
        $operations = new InstalledOperationManager($cache, app('config'));
        $executor = new class implements SourceFetchExecutorInterface
        {
            public ?HangarSource $source = null;

            public function fetch(SourceFetchSpec $spec, float $timeoutSeconds): mixed
            {
                return $this->source->fetchSourceData($spec, $timeoutSeconds);
            }

            public function emptyResult(SourceFetchSpec $spec): mixed
            {
                return $this->source->emptySourceData($spec);
            }
        };
        $source = new HangarSource(new SourceCache($cache, $operations, $executor));
        $executor->source = $source;

        try {
            $source->findVersionsByHashAuthoritatively(['plugin.jar' => $hash]);
            self::fail('A transport-level failure must still propagate.');
        } catch (RequestException) {
            // Expected: real outages keep the existing failure semantics.
        }

        $spec = new SourceFetchSpec('hangar', 'hash_match', [
            'generation' => CacheVersion::hangarHash(),
            'hash' => $hash,
        ]);
        self::assertIsArray($cache->get($spec->cacheKey().':failure:v1'));
    }

    public function test_all_hangar_catalog_sort_presets_use_descending_prefix(): void
    {
        $server = Mockery::mock(Server::class);
        CatalogCompatibilityOverride::set($server, '1.21.4', 'paper');

        foreach (['stars', 'recent_downloads', 'downloads', 'updated', 'newest'] as $sort) {
            $executor = Mockery::mock(SourceFetchExecutorInterface::class);
            $executor->shouldReceive('fetch')
                ->once()
                ->withArgs(function ($spec) use ($sort): bool {
                    self::assertSame('-'.$sort, $spec->arguments['params']['sort']);

                    return true;
                })
                ->andReturn(['hits' => [], 'total_hits' => 0]);

            $cache = new LaravelCacheRepository(new ArrayStore());
            $operations = new InstalledOperationManager($cache, app('config'));
            $source = new HangarSource(new SourceCache($cache, $operations, $executor));

            $source->search($server, ProjectType::Plugin, 1, filters: ['sort' => $sort]);
        }
    }

    public function test_versions_derive_jar_filename_from_direct_external_jar_url_and_mark_webpage_urls_external(): void
    {
        Http::swap($factory = new Factory());
        $factory->fake([
            'hangar.papermc.io/api/v1/projects/ProtocolLib/versions*' => $factory->response([
                'result' => [
                    [
                        'id' => 101,
                        'name' => '2.22.0',
                        'channel' => ['name' => 'Release'],
                        'stats' => ['totalDownloads' => 10],
                        'createdAt' => '2026-10-01T00:00:00Z',
                        'description' => 'Webpage release link',
                        'downloads' => [
                            'PAPER' => [
                                'fileInfo' => null,
                                'downloadUrl' => null,
                                'externalUrl' => 'https://github.com/EssentialsX/Essentials/releases/tag/2.22.0',
                            ],
                        ],
                    ],
                    [
                        'id' => 100,
                        'name' => '5.4.0',
                        'channel' => ['name' => 'Release'],
                        'stats' => ['totalDownloads' => 50],
                        'createdAt' => '2026-09-01T00:00:00Z',
                        'description' => 'Direct JAR link',
                        'downloads' => [
                            'PAPER' => [
                                'fileInfo' => null,
                                'downloadUrl' => null,
                                'externalUrl' => 'https://github.com/dmulloy2/ProtocolLib/releases/download/5.4.0/ProtocolLib.jar',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $cache = new LaravelCacheRepository(new ArrayStore());
        $operations = new InstalledOperationManager($cache, app('config'));
        $executor = new class implements SourceFetchExecutorInterface
        {
            public ?HangarSource $source = null;

            public function fetch(SourceFetchSpec $spec, float $timeoutSeconds): mixed
            {
                return $this->source->fetchSourceData($spec, $timeoutSeconds);
            }

            public function emptyResult(SourceFetchSpec $spec): mixed
            {
                return $this->source->emptySourceData($spec);
            }
        };
        $source = new HangarSource(new SourceCache($cache, $operations, $executor));
        $executor->source = $source;

        $server = Mockery::mock(Server::class);
        CatalogCompatibilityOverride::set($server, '1.21.4', 'paper');

        $versions = $source->getVersions('ProtocolLib', $server, ProjectType::Plugin);
        self::assertCount(2, $versions);
        self::assertSame([], $versions[0]['files']);
        self::assertSame('external', $versions[0]['download_unavailable']);
        self::assertSame('ProtocolLib.jar', $versions[1]['files'][0]['filename']);
        self::assertSame(
            'https://github.com/dmulloy2/ProtocolLib/releases/download/5.4.0/ProtocolLib.jar',
            $versions[1]['files'][0]['url'],
        );

        $latest = $source->fetchSourceData(new SourceFetchSpec('hangar', 'latest', [
            'project_ids' => ['ProtocolLib'],
            'platform' => 'PAPER',
            'params' => ['platform' => 'PAPER', 'limit' => 25],
        ]), 5.0);
        self::assertSame('100', $latest['versions']['ProtocolLib']['id']);
        self::assertSame('ProtocolLib.jar', $latest['versions']['ProtocolLib']['files'][0]['filename']);
    }
}
