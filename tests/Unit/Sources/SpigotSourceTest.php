<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\SourceFetchNotFoundException;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\SpigotSource;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\CatalogCompatibilityOverride;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\ProjectPrimaryFile;
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
        $container->instance(ExceptionHandler::class, Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing());
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Http::swap($factory);
        // Every upstream call must be faked; a stray request would reach the live APIs.
        Http::preventStrayRequests();

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
                $this->spigetResource(11431, 'WorldGuard', ['1.20', '1.21']),
                $this->spigetResource(500, 'WorldGuard Legacy', ['1.8']),
            ], 200, ['X-Total' => '2', 'X-Page-Count' => '1']),
        ]);

        $result = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'search', [
            'page' => 1,
            'query' => 'WorldGuard',
            'sort' => 'downloads',
            'version' => '1.21.4',
        ]), 1.5);

        self::assertCount(1, $result['hits']);
        self::assertSame('11431', $result['hits'][0]['project_id']);
        self::assertSame('Spigot', $this->source()->getLabel());
        self::assertSame('spigot', $result['hits'][0]['source']);
        self::assertSame(2, $result['total_hits']);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'api.spiget.org/v2/search/resources/WorldGuard')
                && str_contains($request->url(), 'field=name');
        });
        // Full search rows already carry statistics: one request per page.
        Http::assertSentCount(1);
    }

    public function test_version_filtered_catalog_reads_the_spiget_match_envelope(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/for/*' => Http::response([
                'check' => ['1.21.4', '1.21'],
                'method' => 'any',
                // /resources/for returns only these three fields per row.
                'match' => [
                    ['testedVersions' => ['1.21'], 'name' => 'LuckPerms', 'id' => 28140],
                    ['testedVersions' => ['1.21'], 'name' => 'Vault', 'id' => 34315],
                ],
            ], 200, ['X-Page-Count' => '5887', 'X-Total' => '17659']),
            'api.spiget.org/v2/resources/28140?*' => Http::response($this->spigetResource(28140, 'LuckPerms', ['1.21'])),
            'api.spiget.org/v2/resources/34315?*' => Http::response(['error' => 'resource not found'], 404),
        ]);

        $result = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'search', [
            'page' => 1,
            'query' => '',
            'sort' => 'downloads',
            'version' => '1.21.4',
        ]), 1.5);

        self::assertSame(17659, $result['total_hits']);
        self::assertSame(['28140', '34315'], array_column($result['hits'], 'project_id'));
        $luckPerms = $result['hits'][0];
        self::assertSame('LuckPerms', $luckPerms['title']);
        self::assertSame('A permissions plugin', $luckPerms['description']);
        self::assertSame(8_932_095, $luckPerms['downloads']);
        self::assertSame('2026-08-06T19:38:34+00:00', $luckPerms['date_modified']);
        self::assertSame('https://www.spigotmc.org/data/resource_icons/28/28140.jpg?1490821714', $luckPerms['icon_url']);
        self::assertSame('spigot', $luckPerms['source']);
        // A row whose details are unavailable stays listed but never invents zero.
        self::assertSame('Vault', $result['hits'][1]['title']);
        self::assertNull($result['hits'][1]['downloads']);
        self::assertNull($result['hits'][1]['date_modified']);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), 'api.spiget.org/v2/resources/for/1.21.4,1.21?')
                && ($query['method'] ?? null) === 'any'
                && ($query['sort'] ?? null) === '-downloads'
                && ($query['size'] ?? null) === '20';
        });
        // The official API rate-limits page-sized bursts; the catalog never uses it.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.spigotmc.org'));
    }

    public function test_version_without_any_tested_resource_is_an_empty_catalog(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/for/*' => Http::response([
                'check' => ['1.99'],
                'method' => 'any',
                'match' => [],
            ], 404, ['X-Total' => '0']),
        ]);

        $result = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'search', [
            'page' => 1,
            'query' => '',
            'sort' => 'updated',
            'version' => '1.99',
        ]), 1.5);

        self::assertSame(['hits' => [], 'total_hits' => 0], $result);
    }

    public function test_unfiltered_catalog_rows_are_normalized_from_spiget(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources?*' => Http::response([
                $this->spigetResource(28140, 'LuckPerms', ['1.21']),
            ], 200, ['X-Total' => '1', 'X-Page-Count' => '1']),
        ]);

        $result = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'search', [
            'page' => 1,
            'query' => '',
            'sort' => 'newest',
            'version' => null,
        ]), 1.5);

        $hit = $result['hits'][0];
        self::assertSame('LuckPerms', $hit['title']);
        self::assertSame(8_932_095, $hit['downloads']);
        // Spiget reports Unix seconds.
        self::assertSame('2026-08-06T19:38:34+00:00', $hit['date_modified']);
        self::assertSame('https://www.spigotmc.org/data/resource_icons/28/28140.jpg?1490821714', $hit['icon_url']);
        // Spiget exposes only the author id.
        self::assertNull($hit['author']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sort=-releaseDate'));
        Http::assertSentCount(1);
    }

    public function test_official_api_is_used_for_canonical_project_metadata(): void
    {
        Http::fake([
            'api.spigotmc.org/simple/0.2/index.php*' => Http::response($this->officialResource(11431, 'WorldGuard')),
        ]);

        $project = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'project', [
            'project_id' => '11431',
        ]), 1.5);

        self::assertSame('11431', $project['project_id']);
        self::assertSame('WorldGuard', $project['title']);
        self::assertSame('Protect worlds', $project['description']);
        self::assertSame('sk89q', $project['author']);
        self::assertSame('https://www.spigotmc.org/data/resource_icons/11/11431.jpg', $project['icon_url']);
        // Simple API 0.2 nests downloads under stats and reports seconds.
        self::assertSame(5_906_503, $project['downloads']);
        self::assertSame('2026-05-31T15:53:28+00:00', $project['date_modified']);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), 'api.spigotmc.org/simple/0.2/index.php')
                && ($query['action'] ?? null) === 'getResource'
                && ($query['id'] ?? null) === '11431';
        });
    }

    public function test_official_metadata_without_stats_reports_unknown_rather_than_zero(): void
    {
        $resource = $this->officialResource(42, 'Example');
        unset($resource['stats'], $resource['last_update'], $resource['author']);
        Http::fake([
            'api.spigotmc.org/simple/0.2/index.php*' => Http::response($resource),
        ]);

        $project = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'project', [
            'project_id' => '42',
        ]), 1.5);

        self::assertNull($project['downloads']);
        self::assertNull($project['author']);
        self::assertSame('2015-07-06T20:12:48+00:00', $project['date_modified']);
    }

    public function test_official_not_found_response_is_a_definitive_miss(): void
    {
        Http::fake([
            'api.spigotmc.org/simple/0.2/index.php*' => Http::response([
                'code' => 404,
                'message' => 'Nothing was found for that request.',
            ], 404),
        ]);

        $this->expectException(SourceFetchNotFoundException::class);

        $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'project', [
            'project_id' => '999999999',
        ]), 1.5);
    }

    public function test_official_outage_is_not_mistaken_for_a_missing_project(): void
    {
        Http::fake([
            'api.spigotmc.org/simple/0.2/index.php*' => Http::response(null, 503),
        ]);

        try {
            $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'project', [
                'project_id' => '11431',
            ]), 1.5);
            self::fail('An upstream outage must surface as a failure.');
        } catch (SourceFetchNotFoundException) {
            self::fail('An upstream outage must not be cached as a missing project.');
        } catch (RequestException $exception) {
            self::assertSame(503, $exception->response->status());
        }
    }

    public function test_premium_and_external_version_files_are_not_downloadable(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/99/download' => Http::response('', 302, [
                'Location' => 'https://cdn.spiget.org/file/spiget-resources/99.jar',
            ]),
            'api.spiget.org/v2/resources/98/download' => Http::response('', 302, [
                'Location' => 'https://cdn.spiget.org/file/spiget-resources/98.jar',
            ]),
            'api.spiget.org/v2/resources/99/versions*' => Http::response([
                ['id' => 7, 'name' => '1.0.0', 'releaseDate' => 1_700_000_000, 'downloads' => 1],
            ]),
            'api.spiget.org/v2/resources/98/versions*' => Http::response([
                ['id' => 8, 'name' => '2.0.0', 'releaseDate' => 1_700_000_000, 'downloads' => 1],
            ]),
            'api.spiget.org/v2/resources/99?*' => Http::response([
                'id' => 99,
                'name' => 'PremiumPlugin',
                'premium' => true,
                'price' => 8.99,
                'external' => false,
                'file' => ['type' => '.jar'],
                'version' => ['id' => 7],
            ]),
            'api.spiget.org/v2/resources/98?*' => Http::response([
                'id' => 98,
                'name' => 'ExternalPlugin',
                'premium' => false,
                'external' => true,
                'file' => ['type' => 'external', 'externalUrl' => 'https://github.com/example/releases'],
                'version' => ['id' => 8],
            ]),
        ]);

        foreach (['99', '98'] as $projectId) {
            $versions = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'versions', [
                'project_id' => $projectId,
                'resolve_downloads' => true,
            ]), 2.0);

            self::assertSame([], $versions[0]['files']);
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download'));
    }

    public function test_download_redirects_are_accepted_only_from_the_spiget_cdn(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/50/download' => Http::response('', 302, [
                'Location' => 'https://spigotmc.org/resources/50/download?version=3',
            ]),
            'api.spiget.org/v2/resources/50/versions*' => Http::response([
                ['id' => 3, 'name' => '1.2.0', 'releaseDate' => 1_700_000_000, 'downloads' => 4],
            ]),
            'api.spiget.org/v2/resources/50?*' => Http::response($this->freeSpigetResource(50, 3)),
        ]);

        $versions = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'versions', [
            'project_id' => '50',
            'resolve_downloads' => true,
        ]), 2.0);

        self::assertSame([], $versions[0]['files']);
    }

    public function test_only_the_current_version_uses_the_cdn_file(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/50/download' => Http::response('', 302, [
                'X-Spiget-File-Source' => 'cdn',
                'Location' => 'https://cdn.spiget.org/file/spiget-resources/50.jar',
            ]),
            'api.spiget.org/v2/resources/50/versions*' => Http::response([
                ['id' => 3, 'name' => '1.2.0', 'releaseDate' => 1_700_000_000, 'downloads' => 4, 'resource' => 50],
                ['id' => 2, 'name' => '1.1.0', 'releaseDate' => 1_600_000_000, 'downloads' => 9, 'resource' => 50],
            ]),
            'api.spiget.org/v2/resources/50?*' => Http::response($this->freeSpigetResource(50, 3)),
        ]);

        $versions = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'versions', [
            'project_id' => '50',
            'resolve_downloads' => true,
        ]), 2.0);

        self::assertSame('https://cdn.spiget.org/file/spiget-resources/50.jar', $versions[0]['files'][0]['url']);
        self::assertSame('FreePlugin-1.2.0.jar', $versions[0]['files'][0]['filename']);
        self::assertSame('2023-11-14T22:13:20+00:00', $versions[0]['date_published']);
        // Older versions only redirect to spigotmc.org and stay unavailable.
        self::assertSame([], $versions[1]['files']);
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/versions/3/download'));
    }

    public function test_single_resource_requests_carry_an_hourly_cache_token(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/50/versions*' => Http::response([
                ['id' => 3, 'name' => '1.2.0', 'releaseDate' => 1_700_000_000, 'downloads' => 4],
            ]),
            'api.spiget.org/v2/resources/50?*' => Http::response($this->freeSpigetResource(50, 3)),
        ]);
        $bucket = intdiv(time(), 3600) * 3600;

        $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'versions', [
            'project_id' => '50',
            'resolve_downloads' => false,
        ]), 2.0);

        // Cloudflare otherwise serves weeks-old single-resource payloads.
        $tokens = [];
        Http::assertSent(function ($request) use (&$tokens): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $tokens[] = (int) ($query['_'] ?? 0);

            return true;
        });
        self::assertCount(2, $tokens);
        foreach ($tokens as $token) {
            self::assertContains($token, [$bucket, $bucket + 3600]);
        }
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/resources/50/versions?')
                && ($query['size'] ?? null) === '25'
                && ($query['page'] ?? null) === '1'
                && ($query['sort'] ?? null) === '-releaseDate';
        });
    }

    public function test_versions_for_identification_skip_download_resolution(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/50/versions*' => Http::response([
                ['id' => 3, 'name' => '1.2.0', 'releaseDate' => 1_700_000_000, 'downloads' => 4],
            ]),
            'api.spiget.org/v2/resources/50?*' => Http::response($this->freeSpigetResource(50, 3)),
        ]);

        $versions = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'versions', [
            'project_id' => '50',
            'resolve_downloads' => false,
        ]), 2.0);

        self::assertSame('1.2.0', $versions[0]['version_number']);
        self::assertSame([], $versions[0]['files']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download'));
    }

    public function test_latest_version_lookup_names_the_current_version_and_attaches_its_cdn_file(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/28140/download' => Http::response('', 302, [
                'X-Spiget-File-Source' => 'cdn',
                'Location' => 'https://cdn.spiget.org/file/spiget-resources/28140.jar',
            ]),
            'api.spiget.org/v2/resources/28140/versions/latest*' => Http::response([
                'downloads' => 4305,
                'name' => '5.5.71',
                'rating' => ['count' => 0, 'average' => 0],
                'releaseDate' => 1_786_045_114,
                'resource' => 28140,
                'uuid' => '023bffe3-2919-e143-0039-d7d95035b999',
                'id' => 648014,
            ]),
            'api.spiget.org/v2/resources/28140?*' => Http::response($this->spigetResource(28140, 'LuckPerms', ['1.21'])),
            'api.spiget.org/v2/resources/9089/versions/latest*' => Http::response([
                'name' => '2.22.0',
                'releaseDate' => 1_780_242_808,
                'resource' => 9089,
                'id' => 639442,
            ]),
            'api.spiget.org/v2/resources/9089?*' => Http::response([
                'external' => true,
                'file' => ['type' => 'external', 'externalUrl' => 'https://github.com/EssentialsX/Essentials/releases/tag/2.22.0'],
                'name' => 'EssentialsX',
                'version' => ['id' => 639442],
                'premium' => false,
                'id' => 9089,
            ]),
            'api.spiget.org/v2/resources/404/versions/latest*' => Http::response(['error' => 'resource not found'], 404),
            'api.spiget.org/v2/resources/404?*' => Http::response(['error' => 'resource not found'], 404),
        ]);

        $result = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'latest', [
            'project_ids' => ['28140', '404', '9089'],
        ]), 2.0);

        $luckPerms = $result['versions']['28140'];
        self::assertSame('648014', $luckPerms['id']);
        self::assertSame('5.5.71', $luckPerms['version_number']);
        self::assertSame('2026-08-06T19:38:34+00:00', $luckPerms['date_published']);
        self::assertSame('https://cdn.spiget.org/file/spiget-resources/28140.jar', $luckPerms['files'][0]['url']);
        self::assertSame('LuckPerms-5.5.71.jar', $luckPerms['files'][0]['filename']);
        // An external file cannot be installed, so it is not offered as an update.
        self::assertArrayNotHasKey('9089', $result['versions']);
        self::assertSame(['404', '9089'], $result['unresolved']);
        self::assertArrayNotHasKey('failures', $result);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/resources/9089/download'));
    }

    public function test_latest_version_without_a_matching_cdn_file_is_not_offered_as_an_update(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/50/versions/latest*' => Http::response([
                'name' => '1.3.0',
                'releaseDate' => 1_700_000_000,
                'id' => 4,
            ]),
            // Spiget has not caught up: its mirrored file is still version 3.
            'api.spiget.org/v2/resources/50?*' => Http::response($this->freeSpigetResource(50, 3)),
        ]);

        $result = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'latest', [
            'project_ids' => ['50'],
        ]), 2.0);

        self::assertSame([], $result['versions']);
        self::assertSame(['50'], $result['unresolved']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download'));
    }

    public function test_latest_version_lookup_distributes_results_through_the_cache(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/50/download' => Http::response('', 302, [
                'Location' => 'https://cdn.spiget.org/file/spiget-resources/50.jar',
            ]),
            'api.spiget.org/v2/resources/50/versions/latest*' => Http::response([
                'name' => '1.2.0',
                'releaseDate' => 1_700_000_000,
                'id' => 3,
            ]),
            'api.spiget.org/v2/resources/50?*' => Http::response($this->freeSpigetResource(50, 3)),
        ]);
        $server = Mockery::mock(Server::class);
        $request = new LatestVersionLookupRequest('spigot', '50', '2');

        $first = $source->lookupLatestVersions([$request], $server, ProjectType::Plugin);
        $second = $source->peekLatestVersions([$request], $server, ProjectType::Plugin);

        $latest = $first->versions()[$request->key()];
        self::assertSame('3', $latest['id']);
        self::assertSame('https://cdn.spiget.org/file/spiget-resources/50.jar', $latest['files'][0]['url']);
        self::assertSame($first->versions(), $second->versions());
        self::assertSame([], $second->pendingKeys());
        Http::assertSentCount(3);
    }

    public function test_install_takes_the_cdn_primary_file_from_cached_versions(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/50/download' => Http::response('', 302, [
                'Location' => 'https://cdn.spiget.org/file/spiget-resources/50.jar',
            ]),
            'api.spiget.org/v2/resources/50/versions*' => Http::response([
                ['id' => 3, 'name' => '1.2.0', 'releaseDate' => 1_700_000_000, 'downloads' => 4],
                ['id' => 2, 'name' => '1.1.0', 'releaseDate' => 1_600_000_000, 'downloads' => 9],
            ]),
            'api.spiget.org/v2/resources/50?*' => Http::response($this->freeSpigetResource(50, 3)),
        ]);
        $server = Mockery::mock(Server::class);

        $versions = $source->getVersions('50', $server, ProjectType::Plugin);
        $cached = $source->getVersions('https://www.spigotmc.org/resources/freeplugin.50/', $server, ProjectType::Plugin);

        // The page installs versions[0]; it must carry the CDN primary file.
        self::assertSame(
            'https://cdn.spiget.org/file/spiget-resources/50.jar',
            ProjectPrimaryFile::fromVersion($versions[0])['url'] ?? null,
        );
        self::assertNull(ProjectPrimaryFile::fromVersion($versions[1]));
        self::assertSame([], $source->getVersions('50', $server, ProjectType::Mod));
        Http::assertSentCount(3);
        self::assertCount(2, $cached);
    }

    public function test_cache_entries_written_before_the_schema_change_are_ignored(): void
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $sourceCache = new SourceCache($cache, new InstalledOperationManager($cache, app('config')), $executor);
        $sourceCache->primeMany([[
            'spec' => new SourceFetchSpec('spigot', 'project', ['project_id' => '42']),
            'data' => ['project_id' => '42', 'downloads' => 0, 'date_modified' => null],
        ]], CacheProfile::ProjectMetadata);

        $peeked = (new SpigotSource($sourceCache))->peekProject('42', dispatchOnMiss: false);

        self::assertSame(['data' => null, 'pending' => true, 'retry_delayed' => false], $peeked);
    }

    public function test_identification_requires_a_unique_name_and_version_match(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/search/resources/WorldGuard*' => Http::response([
                $this->spigetResource(11431, 'WorldGuard', ['1.21']),
            ], 200, ['X-Total' => '1']),
            'api.spiget.org/v2/resources/11431/versions*' => Http::response([
                ['id' => 88, 'name' => '7.0.9', 'releaseDate' => 1, 'downloads' => 1],
            ]),
            'api.spiget.org/v2/resources/11431?*' => Http::response([
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
                $this->spigetResource(1, 'Chat', ['1.21']),
                $this->spigetResource(2, 'Chat', ['1.21']),
            ], 200, ['X-Total' => '2']),
        ]);

        self::assertNull($source->identifyFromArchiveMetadata([
            'name' => 'Chat',
            'version' => '1.0.0',
        ], []));
        self::assertNull($source->identifyFromArchiveMetadata([
            'name' => 'Chat',
            'version' => '1.0.0',
            'authors' => ['Someone else'],
        ], []));
    }

    public function test_website_resource_id_is_preferred_over_name_search(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/42/versions*' => Http::response([
                ['id' => 5, 'name' => '3.1', 'releaseDate' => 1, 'downloads' => 0],
            ]),
            'api.spiget.org/v2/resources/42?*' => Http::response([
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

    /** @return array<string, mixed> */
    private function freeSpigetResource(int $id, int $currentVersionId): array
    {
        return [
            'id' => $id,
            'name' => 'FreePlugin',
            'premium' => false,
            'external' => false,
            'file' => ['type' => '.jar', 'size' => 12, 'sizeUnit' => 'KB'],
            'version' => ['id' => $currentVersionId],
        ];
    }

    /**
     * Shape of a full resource row from Spiget's /resources and
     * /search/resources endpoints.
     *
     * @param  array<int, string>  $testedVersions
     * @return array<string, mixed>
     */
    private function spigetResource(int $id, string $name, array $testedVersions): array
    {
        return [
            'external' => false,
            'file' => ['type' => '.jar', 'size' => 1.4, 'sizeUnit' => 'MB', 'url' => "resources/plugin.{$id}/download?version=648014"],
            'likes' => 10,
            'testedVersions' => $testedVersions,
            'name' => $name,
            'tag' => 'A permissions plugin',
            'version' => ['id' => 648014, 'uuid' => '023bffe3-2919-e143-0039-d7d95035b999'],
            'author' => ['id' => 100356],
            'category' => ['id' => 21],
            'rating' => ['count' => 972, 'average' => 4.7],
            'icon' => ['url' => 'data/resource_icons/28/28140.jpg?1490821714', 'data' => ''],
            'releaseDate' => 1_471_719_960,
            'updateDate' => 1_786_045_114,
            'downloads' => 8_932_095,
            'premium' => false,
            'existenceStatus' => 1,
            'id' => $id,
        ];
    }

    /**
     * Shape of `action=getResource` from the official Simple API 0.2.
     *
     * @return array<string, mixed>
     */
    private function officialResource(int $id, string $title): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'tag' => 'Protect worlds',
            'current_version' => '7.0.9',
            'native_minecraft_version' => '',
            'supported_minecraft_versions' => ['1.20', '1.21'],
            'icon_link' => "https://www.spigotmc.org/data/resource_icons/11/{$id}.jpg",
            'author' => ['id' => 1, 'username' => 'sk89q'],
            'premium' => ['price' => '0.00', 'currency' => ''],
            'stats' => [
                'downloads' => 5_906_503,
                'updates' => 32,
                'reviews' => ['unique' => 1, 'total' => 1],
                'rating' => '4.5',
            ],
            'external_download_url' => '',
            'first_release' => 1_436_213_568,
            'last_update' => 1_780_242_808,
        ];
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
