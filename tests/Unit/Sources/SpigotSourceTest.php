<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\SpigotSource;
use Kazaminosuke\ModManager\Support\CatalogCompatibilityOverride;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Kazaminosuke\ModManager\Support\SpigotFileIndex;
use Mockery;
use PHPUnit\Framework\TestCase;

class SpigotSourceTest extends TestCase
{
    private static ?Capsule $capsule = null;

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
            'pelican-mod-manager' => ['debug_timing' => false],
        ]));
        $factory = new Factory();
        $container->instance(Factory::class, $factory);
        $container->instance('cache', new LaravelCacheRepository(new ArrayStore()));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Http::swap($factory);

        if (self::$capsule === null) {
            self::$capsule = new Capsule();
            self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
        }

        Capsule::schema()->dropIfExists('mod_manager_spigot_file_index');
        Capsule::schema()->create('mod_manager_spigot_file_index', function ($table): void {
            $table->id();
            $table->string('sha256', 64)->unique();
            $table->string('resource_id', 32);
            $table->string('version_id', 32);
            $table->string('version_number', 128);
            $table->string('plugin_name', 191)->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        CatalogCompatibilityOverride::clear();
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Mockery::close();
        parent::tearDown();
    }

    public function test_label_is_always_spigot_and_never_mentions_spiget(): void
    {
        $source = $this->source();

        self::assertSame('Spigot', $source->getLabel());
        self::assertSame('spigot', $source->getKey()->value);
        self::assertStringNotContainsStringIgnoringCase('spiget', $source->getLabel());
    }

    public function test_catalog_search_uses_spiget_and_keeps_the_spigot_source_key(): void
    {
        Http::fake([
            'api.spiget.org/v2/search/resources/WorldGuard*' => Http::response([
                [
                    'id' => 11431,
                    'name' => 'WorldGuard',
                    'tag' => 'World protection',
                    'downloads' => 12,
                    'updateDate' => 1_700_000_000,
                    'author' => ['name' => 'sk89q'],
                    'testedVersions' => ['1.21'],
                ],
            ], 200, ['X-Total-Count' => '1']),
        ]);

        $result = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'search', [
            'page' => 1,
            'query' => 'WorldGuard',
            'sort' => 'downloads',
            'version' => '1.21.4',
        ]), 1.5);

        self::assertSame('11431', $result['hits'][0]['project_id']);
        self::assertSame('Spigot', $this->source()->getLabel());
        self::assertSame('spigot', $result['hits'][0]['source']);
        self::assertSame(1, $result['total_hits']);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'api.spiget.org/v2/search/resources/WorldGuard')
                && str_contains($request->url(), 'field=name');
        });
    }

    public function test_official_api_is_used_for_canonical_project_metadata(): void
    {
        Http::fake([
            'api.spigotmc.org/simple/0.2/index.php*' => Http::response([
                'id' => 11431,
                'title' => 'WorldGuard',
                'tag' => 'Protect worlds',
                'downloads' => 99,
                'updateDate' => 1_700_000_000,
                'author' => ['username' => 'sk89q'],
                'icon_link' => 'https://www.spigotmc.org/icon.png',
            ]),
        ]);

        $project = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'project', [
            'project_id' => '11431',
        ]), 1.5);

        self::assertSame('11431', $project['project_id']);
        self::assertSame('WorldGuard', $project['title']);
        self::assertSame('sk89q', $project['author']);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), 'api.spigotmc.org/simple/0.2/index.php')
                && ($query['action'] ?? null) === 'getResource'
                && ($query['id'] ?? null) === '11431';
        });
    }

    public function test_premium_and_external_version_files_are_not_downloadable(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/99/versions/7/download' => Http::response('', 302, [
                'Location' => 'https://cdn.spiget.org/file.jar',
            ]),
            'api.spiget.org/v2/resources/99/versions*' => Http::response([
                ['id' => 7, 'name' => '1.0.0', 'releaseDate' => 1_700_000_000, 'downloads' => 1],
            ]),
            'api.spiget.org/v2/resources/99' => Http::response([
                'id' => 99,
                'name' => 'PremiumPlugin',
                'premium' => true,
                'external' => false,
                'version' => ['id' => 7],
            ]),
        ]);

        $versions = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'versions', [
            'project_id' => '99',
            'resolve_downloads' => true,
        ]), 2.0);

        self::assertSame([], $versions[0]['files']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download'));
    }

    public function test_download_redirects_are_accepted_only_from_the_spiget_cdn(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/50/versions/3/download' => Http::response('', 302, [
                'Location' => 'https://www.spigotmc.org/resources/secret/download',
                'X-Spiget-File-Source' => 'spigotmc',
            ]),
            'api.spiget.org/v2/resources/50/versions*' => Http::response([
                ['id' => 3, 'name' => '1.2.0', 'releaseDate' => 1_700_000_000, 'downloads' => 4],
            ]),
            'api.spiget.org/v2/resources/50' => Http::response([
                'id' => 50,
                'name' => 'FreePlugin',
                'premium' => false,
                'external' => false,
                'version' => ['id' => 3],
            ]),
        ]);

        $versions = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'versions', [
            'project_id' => '50',
            'resolve_downloads' => true,
        ]), 2.0);

        self::assertSame([], $versions[0]['files']);
    }

    public function test_cdn_download_redirect_is_kept_for_a_free_direct_file(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/50/versions/3/download' => Http::response('', 302, [
                'Location' => 'https://cdn.spiget.org/file/50.jar',
            ]),
            'api.spiget.org/v2/resources/50/versions*' => Http::response([
                ['id' => 3, 'name' => '1.2.0', 'releaseDate' => 1_700_000_000, 'downloads' => 4],
            ]),
            'api.spiget.org/v2/resources/50' => Http::response([
                'id' => 50,
                'name' => 'FreePlugin',
                'premium' => false,
                'external' => false,
                'version' => ['id' => 3],
            ]),
        ]);

        $versions = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'versions', [
            'project_id' => '50',
            'resolve_downloads' => true,
        ]), 2.0);

        self::assertSame('https://cdn.spiget.org/file/50.jar', $versions[0]['files'][0]['url']);
    }

    public function test_identification_requires_a_unique_name_and_version_match(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/search/resources/WorldGuard*' => Http::response([
                [
                    'id' => 11431,
                    'name' => 'WorldGuard',
                    'downloads' => 10,
                    'author' => ['name' => 'sk89q'],
                ],
            ], 200, ['X-Total-Count' => '1']),
            'api.spiget.org/v2/resources/11431/versions*' => Http::response([
                ['id' => 88, 'name' => '7.0.9', 'releaseDate' => 1, 'downloads' => 1],
            ]),
            'api.spiget.org/v2/resources/11431' => Http::response([
                'id' => 11431,
                'name' => 'WorldGuard',
                'premium' => false,
                'external' => false,
            ]),
        ]);

        $version = $source->identifyFromArchiveMetadata([
            'name' => 'WorldGuard',
            'version' => '7.0.9',
            'authors' => ['sk89q'],
        ], ['sha256' => str_repeat('ab', 32)]);

        self::assertSame('11431', $version['project_id']);
        self::assertSame('88', $version['id']);
        self::assertSame('7.0.9', $version['version_number']);
        self::assertSame([
            'resource_id' => '11431',
            'version_id' => '88',
            'version_number' => '7.0.9',
            'plugin_name' => 'WorldGuard',
        ], (new SpigotFileIndex())->findBySha256(str_repeat('ab', 32)));
    }

    public function test_ambiguous_name_matches_are_not_confirmed(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/search/resources/Chat*' => Http::response([
                ['id' => 1, 'name' => 'Chat', 'author' => ['name' => 'One']],
                ['id' => 2, 'name' => 'Chat', 'author' => ['name' => 'Two']],
            ], 200, ['X-Total-Count' => '2']),
        ]);

        self::assertNull($source->identifyFromArchiveMetadata([
            'name' => 'Chat',
            'version' => '1.0.0',
        ], []));
    }

    public function test_website_resource_id_is_preferred_over_name_search(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/42/versions*' => Http::response([
                ['id' => 5, 'name' => '3.1', 'releaseDate' => 1, 'downloads' => 0],
            ]),
            'api.spiget.org/v2/resources/42' => Http::response([
                'id' => 42,
                'name' => 'Example',
                'premium' => false,
            ]),
        ]);

        $version = $source->identifyFromArchiveMetadata([
            'name' => 'Example',
            'version' => '3.1',
            'website' => 'https://www.spigotmc.org/resources/example.42/',
        ], []);

        self::assertSame('42', $version['project_id']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/search/resources/'));
    }

    public function test_saved_project_and_version_metadata_is_preferred_over_search(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake();

        $version = $source->identifyFromArchiveMetadata([
            'name' => 'RenamedLocally',
            'version' => '9.9.9',
            'known_project_id' => '42',
            'known_version_id' => '5',
            'known_version_number' => '3.1',
        ], ['sha256' => str_repeat('ef', 32)]);

        self::assertSame('42', $version['project_id']);
        self::assertSame('5', $version['id']);
        self::assertSame('3.1', $version['version_number']);
        self::assertSame([
            'resource_id' => '42',
            'version_id' => '5',
            'version_number' => '3.1',
            'plugin_name' => 'RenamedLocally',
        ], (new SpigotFileIndex())->findBySha256(str_repeat('ef', 32)));
        Http::assertNothingSent();
    }

    public function test_local_hash_index_is_the_only_hash_lookup(): void
    {
        $hash = str_repeat('cd', 32);
        (new SpigotFileIndex())->remember($hash, '9', '4', '1.4.0', 'Indexed');

        $matched = $this->source()->findVersionsByHashAuthoritatively(['plugin.jar' => $hash]);

        self::assertSame('9', $matched[$hash]['project_id']);
        self::assertSame('4', $matched[$hash]['id']);
        Http::assertNothingSent();
    }

    public function test_search_is_skipped_for_non_plugin_types(): void
    {
        $server = Mockery::mock(Server::class);

        self::assertSame(
            ['hits' => [], 'total_hits' => 0],
            $this->source()->search($server, ProjectType::Mod),
        );
        Http::assertNothingSent();
    }

    private function source(): SpigotSource
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');

        return new SpigotSource(new SourceCache(
            $cache,
            new InstalledOperationManager($cache, app('config')),
            $executor,
        ));
    }

    private function sourceWithExecutor(): SpigotSource
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $executor = new class implements SourceFetchExecutorInterface
        {
            public ?SpigotSource $source = null;

            public function fetch(SourceFetchSpec $spec, float $timeoutSeconds): mixed
            {
                return $this->source->fetchSourceData($spec, $timeoutSeconds);
            }

            public function emptyResult(SourceFetchSpec $spec): mixed
            {
                return $this->source->emptySourceData($spec);
            }
        };
        $source = new SpigotSource(new SourceCache(
            $cache,
            new InstalledOperationManager($cache, app('config')),
            $executor,
        ));
        $executor->source = $source;

        return $source;
    }
}
