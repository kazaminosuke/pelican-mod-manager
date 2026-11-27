<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\DownloadUnavailableException;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Sources\AtlauncherModpackSource;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\FtbModpackSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Sources\TechnicModpackSource;
use Kazaminosuke\ModManager\Support\CatalogCompatibilityOverride;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\Modpacks\CurseForgeManifestReader;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackArchive;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModpackSearchTest extends TestCase
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
            'pelican-mod-manager' => [
                'egg_autodetect_enabled' => false,
                'curseforge_api_key' => 'test-key',
                'debug_timing' => false,
            ],
            'queue' => ['default' => 'sync'],
        ]));
        $factory = new Factory();
        $container->instance(Factory::class, $factory);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Http::swap($factory);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        CatalogCompatibilityOverride::clear();
        MinecraftVersionResolver::clear();
        Mockery::close();

        parent::tearDown();
    }

    public function test_modrinth_modpack_search_uses_the_modpack_facet_and_not_the_mod_facet(): void
    {
        $executor = new CapturingExecutor();
        $source = new ModrinthSource($this->cache($executor));
        $server = $this->server();
        CatalogCompatibilityOverride::set($server, null, 'fabric');

        $source->searchModpacks($server, 1, 'atm');

        $facets = json_decode($executor->spec->arguments['query']['facets'], true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(['project_type:modpack'], $facets);
        self::assertContains(['versions:1.21.1'], $facets);
        self::assertContains(['categories:fabric'], $facets);
        self::assertSame('atm', $executor->spec->arguments['query']['query']);
        self::assertSame('modpack', $executor->spec->arguments['catalog']);
    }

    public function test_modrinth_rejects_a_different_release_in_the_selected_version_response(): void
    {
        Http::fake([
            'api.modrinth.com/v2/version/requested' => Http::response([
                'id' => 'different',
                'project_id' => 'project',
                'version_number' => '2',
                'files' => [['filename' => 'pack.mrpack', 'url' => 'https://cdn.modrinth.com/pack.mrpack']],
            ]),
        ]);

        $source = new ModrinthSource($this->cache(new CapturingExecutor()));
        self::assertNull($source->modpackDownloadById('project', 'requested'));
    }

    public function test_curseforge_build_is_read_from_a_bounded_range_of_the_client_zip(): void
    {
        $metadata = '{"manifestType":"minecraftModpack","minecraft":{"version":"1.21.1","modLoaders":[{"id":"neoforge-21.1.266","primary":true}]}}';
        $temp = tempnam(sys_get_temp_dir(), 'cf-test');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($temp, \ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', $metadata);
        $zip->addFromString('variables.txt', 'MODLOADER_VERSION=0.18.4');
        $zip->addFromString('overrides/large.bin', str_repeat('x', 2 * 1024 * 1024));
        $zip->setCompressionName('overrides/large.bin', \ZipArchive::CM_STORE);
        $zip->close();
        $bytes = file_get_contents($temp);
        unlink($temp);
        $size = strlen($bytes);
        $url = 'https://mediafilez.forgecdn.net/pack.zip';
        Http::fake([$url => function ($request) use ($bytes, $size) {
            $range = $request->header('Range')[0];
            if ($range === 'bytes=-1048576') {
                $start = $size - 1048576;
                $end = $size - 1;
            } else {
                [$start, $end] = array_map('intval', explode('-', substr($range, 6)));
            }

            return Http::response(substr($bytes, $start, $end - $start + 1), 206, [
                'Content-Range' => "bytes {$start}-{$end}/{$size}",
            ]);
        }]);

        $result = (new CurseForgeManifestReader())->read($url);
        self::assertSame($metadata, $result);
        self::assertSame(['minecraft' => '1.21.1', 'loader' => 'neoforge', 'build' => '21.1.266'], ModpackArchive::curseForgeInstallMetadata($result));
        Http::assertSentCount(2);
        self::assertSame('MODLOADER_VERSION=0.18.4', (new CurseForgeManifestReader())->read($url, 'variables.txt'));
    }

    public function test_curseforge_manifest_probe_does_not_follow_redirects_or_accept_full_responses(): void
    {
        Http::fake([
            'mediafilez.forgecdn.net/redirect.zip' => Http::response('', 302, ['Location' => 'https://example.com/pack.zip']),
            'mediafilez.forgecdn.net/full.zip' => Http::response('zip', 200),
        ]);
        $reader = new CurseForgeManifestReader();
        self::assertNull($reader->read('https://example.com/pack.zip'));
        self::assertNull($reader->read('https://mediafilez.forgecdn.net/redirect.zip'));
        self::assertNull($reader->read('https://mediafilez.forgecdn.net/full.zip'));
        Http::assertSentCount(2);
    }

    public function test_curseforge_modpack_search_uses_the_modpack_class(): void
    {
        $executor = new CapturingExecutor();
        $source = new CurseForgeSource($this->cache($executor));
        $server = $this->server();
        CatalogCompatibilityOverride::set($server, null, 'fabric');

        $source->searchModpacks($server, 1, 'atm');

        self::assertSame(CurseForgeSource::CLASS_ID_MODPACK, $executor->spec->arguments['params']['classId']);
        self::assertSame(432, $executor->spec->arguments['params']['gameId']);
        self::assertSame('atm', $executor->spec->arguments['params']['searchFilter']);
        self::assertSame('1.21.1', $executor->spec->arguments['params']['gameVersion']);
        self::assertSame(4, $executor->spec->arguments['params']['modLoaderType']);
    }

    public function test_wizard_catalog_passes_filters_and_page_to_the_selected_provider(): void
    {
        $executor = new CapturingExecutor(['hits' => [['project_id' => '10', 'title' => 'Pack']], 'total_hits' => 40]);
        $cache = $this->cache($executor);
        $catalog = new ModpackCatalog(
            new ModrinthSource($cache),
            new CurseForgeSource($cache),
            new FtbModpackSource(),
            new TechnicModpackSource(),
            new AtlauncherModpackSource(),
        );

        $result = $catalog->searchPage(new Server(), ModpackProvider::CurseForge, '', 2, '1.21.1', 'neoforge');
        self::assertSame('curseforge:10', $result['hits'][0]['key']);
        self::assertSame('CurseForge', $result['hits'][0]['source']);
        self::assertSame(40, $result['total_hits']);
        self::assertSame('1.21.1', $executor->spec->arguments['params']['gameVersion']);
        self::assertSame(6, $executor->spec->arguments['params']['modLoaderType']);
        self::assertSame(20, $executor->spec->arguments['params']['index']);

        $catalog->searchPage(new Server(), ModpackProvider::Modrinth, 'sky', 2, '1.20.1', 'fabric');
        $query = $executor->spec->arguments['query'];
        self::assertSame('sky', $query['query']);
        self::assertSame(20, $query['offset']);
        self::assertSame(
            [['project_type:modpack'], ['versions:1.20.1'], ['categories:fabric']],
            json_decode($query['facets'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_curseforge_server_pack_is_downloaded_instead_of_the_client_zip(): void
    {
        Http::fake([
            'api.curseforge.com/v1/mods/10/files/9' => Http::response(['data' => [
                'id' => 9,
                'fileName' => 'server.zip',
                'displayName' => 'Server',
                'downloadUrl' => 'https://edge.forgecdn.net/server.zip',
                'isServerPack' => true,
            ]]),
            'api.curseforge.com/v1/mods/10/files*' => Http::response(['data' => [[
                'id' => 5,
                'fileName' => 'client.zip',
                'displayName' => 'Client',
                'fileDate' => '2026-02-01T00:00:00Z',
                'downloadUrl' => 'https://edge.forgecdn.net/client.zip',
                'serverPackFileId' => 9,
            ]]]),
        ]);

        $server = $this->server();
        CatalogCompatibilityOverride::set($server, null, 'fabric');

        $download = (new CurseForgeSource($this->cache(new CapturingExecutor())))
            ->latestModpackDownload($server, '10');

        self::assertSame('https://edge.forgecdn.net/server.zip', $download['url']);
        self::assertSame('server.zip', $download['filename']);
        self::assertSame('5', $download['version_id']);
        self::assertSame('9', $download['artifact_id']);
        self::assertTrue($download['server_pack']);
    }

    public function test_selected_curseforge_release_matches_only_its_own_additional_server_zip(): void
    {
        $release = [
            'id' => 9620701,
            'modId' => 925200,
            'fileName' => 'ATM10-8.6.zip',
            'gameVersions' => ['NeoForge', '1.21.1'],
            'downloadUrl' => 'https://edge.forgecdn.net/client.zip',
        ];
        $additional = [
            'id' => 9620727,
            'modId' => 925200,
            'fileName' => 'ServerFiles-8.6.zip',
            'parentProjectFileId' => 9620701,
            'isServerPack' => true,
            'downloadUrl' => 'https://edge.forgecdn.net/server.zip',
        ];
        Http::fake([
            'api.curseforge.com/v1/mods/925200/files/9620701' => Http::response(['data' => $release]),
            'api.curseforge.com/v1/mods/925200/files?*' => Http::response(['data' => [
                array_replace($additional, ['id' => 9620728, 'parentProjectFileId' => 8]),
                array_replace($additional, ['id' => 9620729, 'isServerPack' => false]),
                $additional,
                $release,
            ]]),
        ]);
        $source = new CurseForgeSource($this->cache(new CapturingExecutor()));
        $download = $source->modpackDownloadById('925200', '9620701');

        self::assertSame('9620701', $download['version_id']);
        self::assertSame('9620727', $download['artifact_id']);
        self::assertSame('https://edge.forgecdn.net/server.zip', $download['url']);
        self::assertSame('1.21.1', $download['minecraft']);
        self::assertSame('neoforge', $download['loader']);
        self::assertSame(['9620701'], array_column($source->listModpackVersions('925200'), 'id'));
    }

    public function test_unavailable_server_zip_preserves_its_parent_release_and_distribution_rules(): void
    {
        $client = [
            'id' => 5,
            'fileName' => 'client.zip',
            'downloadUrl' => 'https://edge.forgecdn.net/client.zip',
            'serverPackFileId' => 9,
        ];
        Http::fake([
            'api.curseforge.com/v1/mods/10/files/5' => Http::response(['data' => $client]),
            'api.curseforge.com/v1/mods/10/files/9' => Http::response([], 404),
            'api.curseforge.com/v1/mods/10/files/6' => Http::response(['data' => array_replace($client, [
                'id' => 6,
                'downloadUrl' => 'https://127.0.0.1/client.zip',
            ])]),
            'api.curseforge.com/v1/mods/10/files?*' => Http::response(['data' => []]),
        ]);
        $source = new CurseForgeSource($this->cache(new CapturingExecutor()));
        $download = $source->modpackDownloadById('10', '5');

        self::assertSame('5', $download['version_id']);
        self::assertSame('5', $download['artifact_id']);
        self::assertSame('https://edge.forgecdn.net/client.zip', $download['url']);
        self::assertTrue($download['has_server_pack']);
        self::assertFalse($download['server_pack']);
        self::assertNull($source->modpackDownloadById('10', '6'));
    }

    #[DataProvider('curseForgeAlternateFiles')]
    public function test_curseforge_alternate_file_is_used_only_for_a_matching_server_pack(array $alternate, bool $serverPack): void
    {
        $clientUrl = 'https://edge.forgecdn.net/client.zip';
        $serverUrl = 'https://edge.forgecdn.net/server.zip';
        $release = [
            'id' => 5, 'modId' => 10, 'fileName' => 'client.zip', 'downloadUrl' => $clientUrl, 'alternateFileId' => 9,
        ];
        Http::fake([
            'api.curseforge.com/v1/mods/10/files/5' => Http::response(['data' => $release]),
            'api.curseforge.com/v1/mods/10/files/9' => Http::response(['data' => [
                ...$alternate, 'id' => 9, 'fileName' => 'server.zip', 'downloadUrl' => $serverUrl,
            ]]),
            'api.curseforge.com/v1/mods/10/files?*' => Http::response(['data' => [$release]]),
        ]);
        $source = new CurseForgeSource($this->cache(new CapturingExecutor()));
        $server = $this->server();
        CatalogCompatibilityOverride::set($server, '1.21.1', 'fabric');

        foreach ([$source->modpackDownloadById('10', '5'), $source->latestModpackDownload($server, '10')] as $download) {
            self::assertSame('5', $download['version_id']);
            self::assertSame($serverPack ? '9' : '5', $download['artifact_id']);
            self::assertSame($serverPack ? $serverUrl : $clientUrl, $download['url']);
            self::assertSame($serverPack, $download['server_pack']);
            self::assertSame($serverPack, $download['has_server_pack']);
            self::assertSame($clientUrl, $download['manifest_url']);
        }
    }

    public static function curseForgeAlternateFiles(): array
    {
        return [
            'explicit server pack' => [['modId' => 10, 'isServerPack' => true], true],
            'client pack' => [['modId' => 10, 'isServerPack' => false], false],
            'different project' => [['modId' => 11, 'isServerPack' => true], false],
            'different release' => [['modId' => 10, 'isServerPack' => true, 'parentProjectFileId' => 8], false],
        ];
    }

    public function test_only_an_official_download_url_can_supply_a_missing_server_pack_url(): void
    {
        Http::fake([
            'api.curseforge.com/v1/mods/10/files/5' => Http::response(['data' => [
                'id' => 5,
                'fileName' => 'client.zip',
                'downloadUrl' => 'https://edge.forgecdn.net/client.zip',
                'serverPackFileId' => 9,
            ]]),
            'api.curseforge.com/v1/mods/10/files/9' => Http::response(['data' => [
                'id' => 9,
                'fileName' => 'server.zip',
                'isServerPack' => true,
                'parentProjectFileId' => 5,
            ]]),
            'api.curseforge.com/v1/mods/10/files/9/download-url' => Http::sequence()
                ->push(['data' => 'https://edge.forgecdn.net/server.zip'])
                ->push([], 403),
            'api.curseforge.com/v1/mods/10/files?*' => Http::response(['data' => []]),
        ]);
        $source = new CurseForgeSource($this->cache(new CapturingExecutor()));

        self::assertSame('https://edge.forgecdn.net/server.zip', $source->modpackDownloadById('10', '5')['url']);
        $fallback = $source->modpackDownloadById('10', '5');
        self::assertTrue($fallback['has_server_pack']);
        self::assertFalse($fallback['server_pack']);
        self::assertSame('https://edge.forgecdn.net/client.zip', $fallback['url']);
    }

    public function test_missing_curseforge_download_url_is_a_distribution_block(): void
    {
        Http::fake([
            'api.curseforge.com/v1/mods/10/files*' => Http::response(['data' => [[
                'id' => 4,
                'fileName' => 'hidden.jar',
                'displayName' => 'Hidden',
                'downloadUrl' => null,
            ]]]),
        ]);

        $versions = (new CurseForgeSource($this->cache(new CapturingExecutor())))
            ->fetchSourceData(new SourceFetchSpec('curseforge', 'versions', [
                'project_id' => '10',
                'params' => [],
                'project_type' => ProjectType::Mod->value,
            ]), 2.0);

        self::assertSame([], $versions[0]['files']);
        self::assertSame(DownloadUnavailableException::DISTRIBUTION, $versions[0]['download_unavailable']);
    }

    public function test_modpack_search_preserves_api_versions_and_loaders_without_guessing(): void
    {
        Http::fake([
            'api.modrinth.com/v2/search*' => Http::response([
                'hits' => [[
                    'project_id' => 'fo-pack',
                    'slug' => 'fabulously-optimized',
                    'title' => 'Fabulously Optimized',
                    'description' => 'Fast performance modpack',
                    'author' => 'robotkoer',
                    'downloads' => 5000000,
                    'date_modified' => '2026-11-20T00:00:00Z',
                    'versions' => ['1.20.1', '1.21.4', '24w14a', '1.21.1'],
                    'display_categories' => ['fabric', 'quilt', 'optimization'],
                    'categories' => ['fabric', 'optimization'],
                ]],
                'total_hits' => 1,
            ]),
            'api.curseforge.com/v1/mods/search*' => Http::response([
                'data' => [[
                    'id' => 925200,
                    'slug' => 'atm10',
                    'name' => 'All the Mods 10',
                    'summary' => 'Kitchen sink pack',
                    'authors' => [['name' => 'ATMTeam']],
                    'downloadCount' => 1200000,
                    'dateModified' => '2026-11-19T00:00:00Z',
                    'latestFilesIndexes' => [
                        ['gameVersion' => '1.21.1', 'modLoader' => 6],
                        ['gameVersion' => '1.20.1', 'modLoader' => 1],
                    ],
                ]],
                'pagination' => ['totalCount' => 1],
            ]),
            'api.technicpack.net/search*' => Http::response([
                'modpacks' => [[
                    'slug' => 'tekkit-2',
                    'name' => 'Tekkit 2',
                    'iconUrl' => 'https://cdn.technicpack.net/icon.png',
                ]],
            ]),
        ]);

        $container = Container::getInstance();
        $cacheRepo = new LaravelCacheRepository(new ArrayStore());
        $container->instance('cache', $cacheRepo);

        $executor = new class implements SourceFetchExecutorInterface
        {
            public ?ModrinthSource $modrinth = null;

            public ?CurseForgeSource $curseForge = null;

            public function fetch(SourceFetchSpec $spec, float $timeoutSeconds): mixed
            {
                return $spec->sourceKey === 'modrinth'
                    ? $this->modrinth?->fetchSourceData($spec, $timeoutSeconds)
                    : $this->curseForge?->fetchSourceData($spec, $timeoutSeconds);
            }

            public function emptyResult(SourceFetchSpec $spec): mixed
            {
                return ['hits' => [], 'total_hits' => 0];
            }
        };
        $sourceCache = new SourceCache($cacheRepo, new InstalledOperationManager($cacheRepo, app('config')), $executor);

        $modrinth = new ModrinthSource($sourceCache);
        $curseForge = new CurseForgeSource($sourceCache);
        $executor->modrinth = $modrinth;
        $executor->curseForge = $curseForge;

        $catalog = new ModpackCatalog(
            $modrinth,
            $curseForge,
            new FtbModpackSource(),
            new TechnicModpackSource(),
            new AtlauncherModpackSource(),
        );

        $modrinthPage = $catalog->searchPage(new Server(), ModpackProvider::Modrinth, 'fabulously', 1);
        self::assertCount(1, $modrinthPage['hits']);
        self::assertSame(['1.21.4', '1.21.1', '1.20.1'], $modrinthPage['hits'][0]['minecraft_versions']);
        self::assertSame(['fabric', 'quilt'], $modrinthPage['hits'][0]['loaders']);

        $curseForgePage = $catalog->searchPage(new Server(), ModpackProvider::CurseForge, 'atm', 1);
        self::assertCount(1, $curseForgePage['hits']);
        self::assertSame(['1.21.1', '1.20.1'], $curseForgePage['hits'][0]['minecraft_versions']);
        self::assertSame(['neoforge', 'forge'], $curseForgePage['hits'][0]['loaders']);

        $technicPage = $catalog->searchPage(new Server(), ModpackProvider::Technic, 'tekkit', 1);
        self::assertCount(1, $technicPage['hits']);
        self::assertSame([], $technicPage['hits'][0]['minecraft_versions']);
        self::assertSame([], $technicPage['hits'][0]['loaders']);
    }

    public function test_curseforge_uses_the_official_endpoint_for_a_missing_archive_url(): void
    {
        Http::fake([
            'api.curseforge.com/v1/mods/10/files/9' => Http::response(['data' => [
                'id' => 9,
                'fileName' => 'server.zip',
                'isServerPack' => true,
            ]]),
            'api.curseforge.com/v1/mods/10/files/9/download-url' => Http::sequence()
                ->push(['data' => 'https://edge.forgecdn.net/server.zip'])
                ->push([], 403),
        ]);
        $source = new CurseForgeSource($this->cache(new CapturingExecutor()));

        self::assertSame('https://edge.forgecdn.net/server.zip', $source->modpackDownloadById('10', '9')['url']);
        self::assertNull($source->modpackDownloadById('10', '9'));
    }

    private function cache(SourceFetchExecutorInterface $executor): SourceCache
    {
        $cache = new LaravelCacheRepository(new ArrayStore());

        return new SourceCache($cache, new InstalledOperationManager($cache, app('config')), $executor);
    }

    private function server(): Server
    {
        $variables = Mockery::mock(HasMany::class);
        $variables->shouldReceive('whereIn')->andReturnSelf();
        $variables->shouldReceive('pluck')->andReturn(collect(['MINECRAFT_VERSION' => '1.21.1']));
        $egg = new \stdClass();
        $egg->tags = [];
        $egg->inherit_features = [];
        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getKey')->andReturn(1);
        $server->shouldReceive('variables')->andReturn($variables);
        $server->shouldReceive('loadMissing')->andReturnSelf();
        $server->shouldReceive('offsetExists')->with('egg')->andReturn(true);
        $server->shouldReceive('offsetGet')->with('egg')->andReturn($egg);

        return $server;
    }
}

final class CapturingExecutor implements SourceFetchExecutorInterface
{
    public ?SourceFetchSpec $spec = null;

    public function __construct(private readonly array $result = ['hits' => [], 'total_hits' => 0]) {}

    public function fetch(SourceFetchSpec $spec, float $timeoutSeconds): mixed
    {
        $this->spec = $spec;

        return $this->result;
    }

    public function emptyResult(SourceFetchSpec $spec): mixed
    {
        return ['hits' => [], 'total_hits' => 0];
    }
}
