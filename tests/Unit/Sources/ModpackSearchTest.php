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
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Mockery;
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
        self::assertSame('9620727', $download['egg_version_id']);
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
        self::assertSame('https://edge.forgecdn.net/client.zip', $download['url']);
        self::assertTrue($download['has_server_pack']);
        self::assertFalse($download['server_pack']);
        self::assertNull($download['egg_version_id']);
        self::assertNull($source->modpackDownloadById('10', '6'));
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
