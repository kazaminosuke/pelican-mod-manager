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
        self::assertArrayNotHasKey('spigot_download_block', $luckPerms);
        self::assertArrayNotHasKey('spigot_download_block', $result['hits'][1]);
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
            self::assertSame($projectId === '99' ? 'premium' : 'external', $versions[0]['download_unavailable']);
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download'));
    }

    /**
     * Regression: install and update used Spiget's CDN redirect as the file
     * URL. The file URL is the official Spigot download for the version id
     * already present in the Spiget metadata, and the CDN is not a fallback.
     */
    public function test_installable_versions_use_the_official_spigot_download_url(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/50/download' => Http::response('', 302, [
                'X-Spiget-File-Source' => 'cdn',
                'Location' => 'https://cdn.spiget.org/file/spiget-resources/50.jar',
            ]),
            'cdn.spiget.org/*' => Http::response('jar', 200),
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

        self::assertSame('https://www.spigotmc.org/resources/50/download?version=3', $versions[0]['files'][0]['url']);
        self::assertSame('FreePlugin-1.2.0.jar', $versions[0]['files'][0]['filename']);
        self::assertSame('https://www.spigotmc.org/resources/50/download?version=2', $versions[1]['files'][0]['url']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download')
            || str_contains($request->url(), 'cdn.spiget.org'));
    }

    /**
     * Regression: array_merge() renumbered the numeric resource ids of
     * per-resource failures, so a failed lookup was attributed to "0" and
     * reported as an unresolved project instead of a failure.
     */
    public function test_latest_version_failures_keep_their_resource_id(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/28140/versions/latest*' => Http::response('', 500),
            'api.spiget.org/v2/resources/28140?*' => Http::response('', 500),
        ]);
        $request = new LatestVersionLookupRequest('spigot', '28140', '1');

        $result = $source->lookupLatestVersions([$request], Mockery::mock(Server::class), ProjectType::Plugin);

        self::assertArrayHasKey($request->key(), $result->failures());
        self::assertSame([], $result->versions());
    }

    public function test_each_version_uses_its_own_official_download_url(): void
    {
        Http::fake([
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

        self::assertSame('https://www.spigotmc.org/resources/50/download?version=3', $versions[0]['files'][0]['url']);
        self::assertSame('FreePlugin-1.2.0.jar', $versions[0]['files'][0]['filename']);
        self::assertSame('2023-11-14T22:13:20+00:00', $versions[0]['date_published']);
        self::assertSame('https://www.spigotmc.org/resources/50/download?version=2', $versions[1]['files'][0]['url']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download'));
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

    public function test_latest_version_lookup_names_the_current_version_and_attaches_its_official_file(): void
    {
        Http::fake([
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
        self::assertSame('https://www.spigotmc.org/resources/28140/download?version=648014', $luckPerms['files'][0]['url']);
        self::assertSame('LuckPerms-5.5.71.jar', $luckPerms['files'][0]['filename']);
        // An external file cannot be installed, so it is not offered as an update.
        self::assertArrayNotHasKey('9089', $result['versions']);
        self::assertSame(['404', '9089'], $result['unresolved']);
        self::assertArrayNotHasKey('failures', $result);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/resources/9089/download'));
    }

    public function test_latest_version_uses_its_own_id_when_the_resource_record_lags(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/50/versions/latest*' => Http::response([
                'name' => '1.3.0',
                'releaseDate' => 1_700_000_000,
                'id' => 4,
            ]),
            'api.spiget.org/v2/resources/50?*' => Http::response($this->freeSpigetResource(50, 3)),
        ]);

        $result = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'latest', [
            'project_ids' => ['50'],
        ]), 2.0);

        self::assertSame('4', $result['versions']['50']['id']);
        self::assertSame(
            'https://www.spigotmc.org/resources/50/download?version=4',
            $result['versions']['50']['files'][0]['url'],
        );
        self::assertSame([], $result['unresolved']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download'));
    }

    public function test_latest_version_lookup_distributes_results_through_the_cache(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
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
        self::assertSame('https://www.spigotmc.org/resources/50/download?version=3', $latest['files'][0]['url']);
        self::assertSame($first->versions(), $second->versions());
        self::assertSame([], $second->pendingKeys());
        Http::assertSentCount(2);
    }

    public function test_install_takes_the_cdn_primary_file_from_cached_versions(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/50/versions*' => Http::response([
                ['id' => 3, 'name' => '1.2.0', 'releaseDate' => 1_700_000_000, 'downloads' => 4],
                ['id' => 2, 'name' => '1.1.0', 'releaseDate' => 1_600_000_000, 'downloads' => 9],
            ]),
            'api.spiget.org/v2/resources/50?*' => Http::response($this->freeSpigetResource(50, 3)),
        ]);
        $server = Mockery::mock(Server::class);

        $versions = $source->getVersions('50', $server, ProjectType::Plugin);
        $cached = $source->getVersions('https://www.spigotmc.org/resources/freeplugin.50/', $server, ProjectType::Plugin);

        self::assertSame(
            'https://www.spigotmc.org/resources/50/download?version=3',
            ProjectPrimaryFile::fromVersion($versions[0])['url'] ?? null,
        );
        self::assertSame(
            'https://www.spigotmc.org/resources/50/download?version=2',
            ProjectPrimaryFile::fromVersion($versions[1])['url'] ?? null,
        );
        self::assertSame([], $source->getVersions('50', $server, ProjectType::Mod));
        Http::assertSentCount(2);
        self::assertCount(2, $cached);
    }

    /**
     * Regression: an install read the historical version list, and a missing
     * CDN file made every install fail. The installable version is built
     * from the resource and /versions/latest, with the official Spigot URL.
     */
    public function test_authoritative_install_resolves_the_official_file_without_version_history(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/28140/download' => Http::response('', 302, [
                'X-Spiget-File-Source' => 'cdn',
                'Location' => 'https://cdn.spiget.org/file/spiget-resources/28140.jar',
            ]),
            'cdn.spiget.org/*' => Http::response('jar', 200),
            'api.spiget.org/v2/resources/28140/versions/latest*' => Http::response([
                'name' => '5.5.71',
                'releaseDate' => 1_786_045_114,
                'id' => 648014,
                'downloads' => 4305,
            ]),
            'api.spiget.org/v2/resources/28140?*' => Http::response($this->spigetResource(28140, 'LuckPerms', ['1.21'])),
        ]);
        $server = Mockery::mock(Server::class);

        $versions = $source->getVersionsAuthoritatively('28140', $server, ProjectType::Plugin);

        self::assertSame('648014', $versions[0]['id']);
        self::assertSame('5.5.71', $versions[0]['version_number']);
        self::assertSame(
            'https://www.spigotmc.org/resources/28140/download?version=648014',
            ProjectPrimaryFile::fromVersion($versions[0])['url'] ?? null,
        );
        self::assertSame('LuckPerms-5.5.71.jar', ProjectPrimaryFile::fromVersion($versions[0])['filename'] ?? null);
        Http::assertNotSent(fn ($request): bool => preg_match('~/versions(?:\?|$)~', $request->url()) === 1
            || str_contains($request->url(), '/download')
            || str_contains($request->url(), 'cdn.spiget.org'));
    }

    public function test_authoritative_install_does_not_reuse_a_cached_cdn_url(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/28140/versions*' => Http::response([
                ['id' => 648014, 'name' => '5.5.71', 'releaseDate' => 1_786_045_114, 'downloads' => 1],
            ]),
            'api.spiget.org/v2/resources/28140?*' => Http::response($this->spigetResource(28140, 'LuckPerms', ['1.21'])),
        ]);
        $server = Mockery::mock(Server::class);
        $cached = $source->getVersions('28140', $server, ProjectType::Plugin);
        self::assertSame(
            'https://www.spigotmc.org/resources/28140/download?version=648014',
            ProjectPrimaryFile::fromVersion($cached[0])['url'] ?? null,
        );

        // Http::fake() appends stubs, so the history response would keep
        // matching /versions/latest. Swap the client before the install read.
        $factory = new Factory();
        $factory->preventStrayRequests();
        Http::swap($factory);
        Http::fake([
            'api.spiget.org/v2/resources/28140/download' => Http::response('', 302, [
                'X-Spiget-File-Source' => 'cdn',
                'Location' => 'https://cdn.spiget.org/file/spiget-resources/28140.jar',
            ]),
            'cdn.spiget.org/*' => Http::response('jar', 200),
            'api.spiget.org/v2/resources/28140/versions/latest*' => Http::response([
                'name' => '5.5.71',
                'releaseDate' => 1_786_045_114,
                'id' => 648014,
            ]),
            'api.spiget.org/v2/resources/28140?*' => Http::response($this->spigetResource(28140, 'LuckPerms', ['1.21'])),
        ]);

        $versions = $source->getVersionsAuthoritatively('28140', $server, ProjectType::Plugin);

        self::assertSame(
            'https://www.spigotmc.org/resources/28140/download?version=648014',
            ProjectPrimaryFile::fromVersion($versions[0])['url'] ?? null,
        );
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download')
            || str_contains($request->url(), 'cdn.spiget.org'));
    }

    public function test_authoritative_install_leaves_premium_and_external_resources_without_a_file(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/99/versions/latest*' => Http::response([
                'name' => '1.0.0',
                'releaseDate' => 1_700_000_000,
                'id' => 7,
            ]),
            'api.spiget.org/v2/resources/99?*' => Http::response([
                'id' => 99,
                'name' => 'PremiumPlugin',
                'premium' => true,
                'external' => false,
                'file' => ['type' => '.jar'],
                'version' => ['id' => 7],
            ]),
            'api.spiget.org/v2/resources/98/versions/latest*' => Http::response([
                'name' => '2.0.0',
                'releaseDate' => 1_700_000_000,
                'id' => 8,
            ]),
            'api.spiget.org/v2/resources/98?*' => Http::response([
                'id' => 98,
                'name' => 'ExternalPlugin',
                'premium' => false,
                'external' => true,
                'file' => ['type' => 'external', 'externalUrl' => 'https://example.com/plugin.jar'],
                'version' => ['id' => 8],
            ]),
        ]);
        $server = Mockery::mock(Server::class);

        $premium = $source->getVersionsAuthoritatively('99', $server, ProjectType::Plugin);
        $external = $source->getVersionsAuthoritatively('98', $server, ProjectType::Plugin);

        self::assertSame('7', $premium[0]['id']);
        self::assertNull(ProjectPrimaryFile::fromVersion($premium[0]));
        self::assertSame('premium', $premium[0]['download_unavailable']);
        self::assertSame('8', $external[0]['id']);
        self::assertNull(ProjectPrimaryFile::fromVersion($external[0]));
        self::assertSame('external', $external[0]['download_unavailable']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download'));
    }

    /**
     * Production installs of SkinsRestorer (2124) failed with
     * "No downloadable file found". Spiget and the official Simple API both
     * mark it external, and its download redirects to a GitHub releases page.
     * The version is kept, without a file URL, and the reason is recorded.
     */
    public function test_authoritative_install_of_skinsrestorer_does_not_invent_a_download_url(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/2124/download' => Http::response('', 302, [
                'X-Spiget-File-Source' => 'external',
                'Location' => 'https://github.com/SkinsRestorer/SkinsRestorer/releases/latest',
            ]),
            'cdn.spiget.org/*' => Http::response('jar', 200),
            'api.spiget.org/v2/resources/2124/versions/latest*' => Http::response([
                'downloads' => 1,
                'name' => 'latest',
                'releaseDate' => 1_700_000_000,
                'resource' => 2124,
                'uuid' => '003acc70-806f-4f80-0039-991c3e012e47',
                'id' => 606394,
            ]),
            'api.spiget.org/v2/resources/2124?*' => Http::response([
                'id' => 2124,
                'name' => 'SkinsRestorer',
                'premium' => false,
                'external' => true,
                'file' => [
                    'type' => 'external',
                    'size' => 0,
                    'sizeUnit' => '',
                    'url' => 'resources/skinsrestorer.2124/download?version=606394',
                    'externalUrl' => 'https://github.com/SkinsRestorer/SkinsRestorer/releases/latest',
                ],
                'version' => ['id' => 606394],
            ]),
        ]);
        $server = Mockery::mock(Server::class);

        $versions = $source->getVersionsAuthoritatively('2124', $server, ProjectType::Plugin);

        self::assertSame('606394', $versions[0]['id']);
        self::assertSame('2124', $versions[0]['project_id']);
        self::assertSame('latest', $versions[0]['version_number']);
        self::assertSame([], $versions[0]['files']);
        self::assertSame('external', $versions[0]['download_unavailable']);
        self::assertNull(ProjectPrimaryFile::fromVersion($versions[0]));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download')
            || str_contains($request->url(), 'cdn.spiget.org')
            || str_contains($request->url(), 'spigotmc.org/resources/'));
    }

    public function test_a_non_numeric_version_id_does_not_become_an_official_download_url(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/50/versions/latest*' => Http::response([
                'name' => '1.2.0',
                'releaseDate' => 1_700_000_000,
                'id' => 0,
            ]),
            'api.spiget.org/v2/resources/50?*' => Http::response($this->freeSpigetResource(50, 0)),
        ]);
        $server = Mockery::mock(Server::class);

        $versions = $source->getVersionsAuthoritatively('50', $server, ProjectType::Plugin);

        self::assertSame('0', $versions[0]['id']);
        self::assertSame([], $versions[0]['files']);
        self::assertSame('invalid', $versions[0]['download_unavailable']);
        self::assertNull(ProjectPrimaryFile::fromVersion($versions[0]));
    }

    public function test_catalog_rows_record_external_resources_and_leave_hosted_ones_installable(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources?*' => Http::response([
                $this->spigetResource(28140, 'LuckPerms', ['1.21']),
                [
                    'id' => 2124,
                    'name' => 'SkinsRestorer',
                    'tag' => 'Restore skins',
                    'premium' => false,
                    'external' => true,
                    'downloads' => 10,
                    'updateDate' => 1_700_000_000,
                    'file' => [
                        'type' => 'external',
                        'externalUrl' => 'https://github.com/SkinsRestorer/SkinsRestorer/releases/latest',
                    ],
                ],
                [
                    'id' => 99,
                    'name' => 'PremiumPlugin',
                    'tag' => 'Paid',
                    'premium' => true,
                    'external' => false,
                    'downloads' => 1,
                    'file' => ['type' => '.jar'],
                ],
            ], 200, ['X-Total' => '3']),
        ]);

        $result = $this->source()->fetchSourceData(new SourceFetchSpec('spigot', 'search', [
            'page' => 1,
            'query' => '',
            'sort' => 'downloads',
            'version' => null,
        ]), 2.0);

        self::assertArrayNotHasKey('spigot_download_block', $result['hits'][0]);
        self::assertSame('external', $result['hits'][1]['spigot_download_block']);
        self::assertSame('premium', $result['hits'][2]['spigot_download_block']);
    }

    public function test_authoritative_install_ignores_a_spiget_download_redirect(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/50/download' => Http::response('', 302, [
                'X-Spiget-File-Source' => 'external',
                'Location' => 'https://github.com/example/releases/download/plugin.jar',
            ]),
            'cdn.spiget.org/*' => Http::response('jar', 200),
            'api.spiget.org/v2/resources/50/versions/latest*' => Http::response([
                'name' => '1.2.0',
                'releaseDate' => 1_700_000_000,
                'id' => 3,
            ]),
            'api.spiget.org/v2/resources/50?*' => Http::response($this->freeSpigetResource(50, 3)),
        ]);
        $server = Mockery::mock(Server::class);

        $versions = $source->getVersionsAuthoritatively('50', $server, ProjectType::Plugin);

        self::assertSame('3', $versions[0]['id']);
        self::assertSame(
            'https://www.spigotmc.org/resources/50/download?version=3',
            ProjectPrimaryFile::fromVersion($versions[0])['url'] ?? null,
        );
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download')
            || str_contains($request->url(), 'cdn.spiget.org'));
    }

    public function test_authoritative_install_does_not_depend_on_the_spiget_download_endpoint(): void
    {
        $source = $this->sourceWithExecutor();
        Http::fake([
            'api.spiget.org/v2/resources/28140/download' => Http::response('', 503),
            'cdn.spiget.org/*' => Http::response('', 503),
            'api.spiget.org/v2/resources/28140/versions/latest*' => Http::response([
                'name' => '5.5.71',
                'releaseDate' => 1_786_045_114,
                'id' => 648014,
            ]),
            'api.spiget.org/v2/resources/28140?*' => Http::response($this->spigetResource(28140, 'LuckPerms', ['1.21'])),
        ]);
        $server = Mockery::mock(Server::class);

        $versions = $source->getVersionsAuthoritatively('28140', $server, ProjectType::Plugin);

        self::assertSame(
            'https://www.spigotmc.org/resources/28140/download?version=648014',
            ProjectPrimaryFile::fromVersion($versions[0])['url'] ?? null,
        );
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download')
            || str_contains($request->url(), 'cdn.spiget.org'));
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
