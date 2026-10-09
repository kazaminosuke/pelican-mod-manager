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
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\DownloadUnavailableException;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
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

    public function fetch(SourceFetchSpec $spec, float $timeoutSeconds): mixed
    {
        $this->spec = $spec;

        return ['hits' => [], 'total_hits' => 0];
    }

    public function emptyResult(SourceFetchSpec $spec): mixed
    {
        return ['hits' => [], 'total_hits' => 0];
    }
}
