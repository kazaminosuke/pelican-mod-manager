<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Egg;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\WarmProjectMetadata;
use Kazaminosuke\ModManager\Repositories\InstalledMetadataRepository;
use Kazaminosuke\ModManager\Repositories\ServerModManagerSettingRepository;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\GitHubReleasesSource;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Sources\SpigotSource;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadResult;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchExecutor;
use Mockery;
use PHPUnit\Framework\TestCase;
use Throwable;

class InstalledMetadataPrimingAndRetryTest extends TestCase
{
    private static ?Capsule $capsule = null;

    private ?Container $previousContainer = null;

    private mixed $previousFacade = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$capsule === null) {
            self::$capsule = new Capsule();
            self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
        }

        if (!Capsule::schema()->hasTable('mod_manager_server_settings')) {
            Capsule::schema()->create('mod_manager_server_settings', function ($table): void {
                $table->id();
                $table->unsignedInteger('server_id')->unique();
                $table->timestamps();
            });
        }

        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacade);

        parent::tearDown();
    }

    public function test_modrinth_scan_primes_per_project_display_cache_immediately_with_icon_description_stats_and_author(): void
    {
        [$container, $cache, $operations, $sourceCache, $runner] = $this->bootEnvironment();
        $modrinth = new ModrinthSource($sourceCache);
        $container->instance(ModrinthSource::class, $modrinth);

        $viaBytes = 'viaversion-jar-payload';
        $viaBackwardsBytes = 'viabackwards-jar-payload';
        $viaHash = hash('sha512', $viaBytes);
        $viaBackwardsHash = hash('sha512', $viaBackwardsBytes);

        $projectBatchCalls = 0;

        Http::fake(static function (HttpClientRequest $request) use ($viaHash, $viaBackwardsHash, &$projectBatchCalls) {
            $url = $request->url();

            if (str_contains($url, '/v2/version_files')) {
                return Http::response([
                    $viaHash => [
                        'id' => 'ver-via',
                        'project_id' => 'P1OZGk5p',
                        'version_number' => '5.1.0',
                        'author_id' => 'u-kenny',
                    ],
                    $viaBackwardsHash => [
                        'id' => 'ver-viab',
                        'project_id' => 'NpvuJQoq',
                        'version_number' => '5.1.0',
                        'author_id' => 'u-kenny',
                    ],
                ], 200);
            }

            if (str_contains($url, '/v2/projects')) {
                $projectBatchCalls++;

                return Http::response([
                    [
                        'id' => 'P1OZGk5p',
                        'slug' => 'viaversion',
                        'title' => 'ViaVersion',
                        'description' => 'Allows newer Minecraft versions to connect',
                        'icon_url' => 'https://cdn.modrinth.com/data/P1OZGk5p/icon.png',
                        'downloads' => 15000000,
                        'updated' => '2026-11-20T00:00:00Z',
                        'project_type' => 'mod',
                    ],
                    [
                        'id' => 'NpvuJQoq',
                        'slug' => 'viabackwards',
                        'title' => 'ViaBackwards',
                        'description' => 'Allows older Minecraft versions to connect',
                        'icon_url' => 'https://cdn.modrinth.com/data/NpvuJQoq/icon.png',
                        'downloads' => 8000000,
                        'updated' => '2026-11-19T00:00:00Z',
                        'project_type' => 'mod',
                    ],
                ], 200);
            }

            if (str_contains($url, '/v2/user/u-kenny')) {
                return Http::response(['id' => 'u-kenny', 'username' => 'kennytv'], 200);
            }

            return Http::response([], 404);
        });

        $registry = $this->makeRegistry($operations, modrinth: $modrinth);
        $repo = $this->makeInMemoryRepo();

        $filesByPath = [
            'plugins/ViaVersion.jar' => $viaBytes,
            'plugins/ViaBackwards.jar' => $viaBackwardsBytes,
        ];
        $service = new class($registry, $repo, $filesByPath) extends InstalledProjectService
        {
            /** @param array<string, string> $filesByPath */
            public function __construct(
                ProjectSourceRegistry $registry,
                InstalledMetadataRepository $repo,
                private readonly array $filesByPath,
            ) {
                parent::__construct($registry, $repo);
            }

            protected function openDaemonFileStream(DaemonFileRepository $fileRepository, Server $server, string $path): object
            {
                return Utils::streamFor($this->filesByPath[$path] ?? '');
            }
        };

        $server = $this->makePluginServer();
        $fileRepository = Mockery::mock(DaemonFileRepository::class);
        $fileRepository->shouldReceive('setServer')->with($server)->andReturnSelf();
        $fileRepository->shouldReceive('getDirectory')->with('plugins')->andReturn([
            ['name' => 'ViaVersion.jar', 'size' => strlen($viaBytes), 'modified' => '2026-11-20T00:00:00Z', 'file' => true, 'directory' => false],
            ['name' => 'ViaBackwards.jar', 'size' => strlen($viaBackwardsBytes), 'modified' => '2026-11-20T00:00:00Z', 'file' => true, 'directory' => false],
        ]);

        $result = $service->scanAndImportModsResult($server, $fileRepository, ProjectType::Plugin, true);
        self::assertTrue($result->successful, 'Unexpected scan failure: '.($result->failure ?? 'null'));
        self::assertSame(1, $projectBatchCalls);

        $installed = $repo->doc?->installedMods() ?? [];
        self::assertCount(2, $installed);

        // Immediately after scan, peekInstalled() returns full metadata with ZERO pending rows and ZERO warm jobs.
        $rows = $registry->peekInstalled($installed, $server);
        self::assertCount(2, $rows);
        self::assertSame([], $runner->spawned);

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['project_id']] = $row;
        }

        self::assertSame('ViaVersion', $byId['P1OZGk5p']['title']);
        self::assertSame('Allows newer Minecraft versions to connect', $byId['P1OZGk5p']['description']);
        self::assertSame('https://cdn.modrinth.com/data/P1OZGk5p/icon.png', $byId['P1OZGk5p']['icon_url']);
        self::assertSame(15000000, $byId['P1OZGk5p']['downloads']);
        self::assertSame('2026-11-20T00:00:00Z', $byId['P1OZGk5p']['date_modified']);
        self::assertSame('kennytv', $byId['P1OZGk5p']['author']);
        self::assertArrayNotHasKey('enrichment_pending', $byId['P1OZGk5p']);

        self::assertSame('ViaBackwards', $byId['NpvuJQoq']['title']);
        self::assertSame('Allows older Minecraft versions to connect', $byId['NpvuJQoq']['description']);
        self::assertSame('https://cdn.modrinth.com/data/NpvuJQoq/icon.png', $byId['NpvuJQoq']['icon_url']);
        self::assertSame(8000000, $byId['NpvuJQoq']['downloads']);
        self::assertSame('kennytv', $byId['NpvuJQoq']['author']);
        self::assertArrayNotHasKey('enrichment_pending', $byId['NpvuJQoq']);

        // Subsequent batch lookup reuses primed per-project entries without hitting Modrinth again.
        $again = $modrinth->getProjectsByIdsAuthoritatively(['P1OZGk5p', 'NpvuJQoq']);
        self::assertSame(1, $projectBatchCalls);
        self::assertArrayHasKey('P1OZGk5p', $again);
        self::assertArrayHasKey('NpvuJQoq', $again);
    }

    public function test_curseforge_batch_lookup_primes_per_project_cache_and_reuses_hits(): void
    {
        [$container, , , $sourceCache] = $this->bootEnvironment(curseForgeApiKey: 'cf-test-key');
        $curseForge = new CurseForgeSource($sourceCache);
        $container->instance(CurseForgeSource::class, $curseForge);

        $batchCalls = 0;

        Http::fake(static function (HttpClientRequest $request) use (&$batchCalls) {
            if (str_contains($request->url(), '/v1/mods')) {
                $batchCalls++;

                return Http::response([
                    'data' => [[
                        'id' => 238222,
                        'slug' => 'jei',
                        'name' => 'Just Enough Items (JEI)',
                        'summary' => 'View Items and Recipes',
                        'logo' => ['thumbnailUrl' => 'https://media.forgecdn.net/jei.png'],
                        'authors' => [['name' => 'mezz']],
                        'downloadCount' => 300000000,
                        'dateModified' => '2026-11-18T00:00:00Z',
                    ]],
                ], 200);
            }

            return Http::response([], 404);
        });

        $first = $curseForge->getProjectsByIdsAuthoritatively(['238222']);
        self::assertSame(1, $batchCalls);
        self::assertSame('Just Enough Items (JEI)', $first['238222']['title']);

        // Per-project cache is now warm immediately.
        $peeked = $curseForge->peekProjects(['238222']);
        self::assertIsArray($peeked['238222']['data']);
        self::assertFalse($peeked['238222']['pending']);
        self::assertSame('https://media.forgecdn.net/jei.png', $peeked['238222']['data']['icon_url']);

        // Second authoritative batch lookup hits per-project cache with zero upstream calls.
        $second = $curseForge->getProjectsByIdsAuthoritatively(['238222']);
        self::assertSame(1, $batchCalls);
        self::assertSame('View Items and Recipes', $second['238222']['description']);
    }

    public function test_metadata_warm_failure_enters_cooldown_and_recovers_automatically_after_expiry(): void
    {
        [$container, $cache, $operations, $sourceCache, $runner] = $this->bootEnvironment();
        $modrinth = new ModrinthSource($sourceCache);
        $container->instance(ModrinthSource::class, $modrinth);
        $registry = $this->makeRegistry($operations, modrinth: $modrinth);

        $shouldFail = true;
        Http::fake(static function (HttpClientRequest $request) use (&$shouldFail) {
            if (str_contains($request->url(), '/v2/projects')) {
                if ($shouldFail) {
                    return Http::response(['error' => 'upstream timeout'], 503);
                }

                return Http::response([[
                    'id' => 'P1OZGk5p',
                    'slug' => 'viaversion',
                    'title' => 'ViaVersion',
                    'description' => 'Recovered description',
                    'icon_url' => 'https://cdn.modrinth.com/data/P1OZGk5p/icon.png',
                    'downloads' => 15000000,
                    'updated' => '2026-11-20T00:00:00Z',
                    'project_type' => 'mod',
                ]], 200);
            }

            return Http::response([], 404);
        });

        $server = $this->makePluginServer();
        $installed = [[
            'source' => 'modrinth',
            'project_id' => 'P1OZGk5p',
            'project_slug' => 'viaversion',
            'project_title' => 'ViaVersion',
            'author' => 'kennytv',
        ]];

        // 1) Initial cold peek dispatches one WarmProjectMetadata job.
        $initial = $registry->peekInstalled($installed, $server);
        self::assertTrue($initial[0]['enrichment_pending'] ?? false);
        self::assertCount(1, $runner->spawned);

        // 2) Warm job hits a transient 503 failure -> sets retry_delayed failure marker.
        (new WarmProjectMetadata('modrinth', ['P1OZGk5p']))->handle($registry, $cache);

        // 3) During cooldown, peekInstalled() marks retry_delayed => true without dispatching another job.
        $runner->spawned = [];
        $duringCooldown = $registry->peekInstalled($installed, $server);
        self::assertTrue($duringCooldown[0]['retry_delayed'] ?? false);
        self::assertArrayNotHasKey('enrichment_pending', $duringCooldown[0]);
        self::assertArrayNotHasKey('unavailable', $duringCooldown[0]);
        self::assertSame([], $runner->spawned);

        // 4) Once cooldown expires and upstream recovers, a subsequent request/worker dispatches a retry warm job.
        $cache->clear();
        $shouldFail = false;
        $freshSourceCache = new SourceCache($cache, $operations, new SourceFetchExecutor($container));
        $freshModrinth = new ModrinthSource($freshSourceCache);
        $container->instance(SourceCache::class, $freshSourceCache);
        $container->instance(ModrinthSource::class, $freshModrinth);
        $freshRegistry = $this->makeRegistry($operations, modrinth: $freshModrinth);

        $afterCooldown = $freshRegistry->peekInstalled($installed, $server);
        self::assertTrue($afterCooldown[0]['enrichment_pending'] ?? false);
        self::assertCount(1, $runner->spawned);

        // 5) Retry warm job succeeds and primes full metadata; subsequent peek renders full metadata.
        (new WarmProjectMetadata('modrinth', ['P1OZGk5p']))->handle($freshRegistry, $cache);
        $recovered = $freshRegistry->peekInstalled($installed, $server);
        self::assertSame('Recovered description', $recovered[0]['description']);
        self::assertSame('https://cdn.modrinth.com/data/P1OZGk5p/icon.png', $recovered[0]['icon_url']);
        self::assertSame(15000000, $recovered[0]['downloads']);
        self::assertArrayNotHasKey('enrichment_pending', $recovered[0]);
        self::assertArrayNotHasKey('retry_delayed', $recovered[0]);
    }

    public function test_warm_project_metadata_folds_slug_case_but_requires_exact_modrinth_ids(): void
    {
        [$container, $cache, $operations, $sourceCache] = $this->bootEnvironment();
        $modrinth = new ModrinthSource($sourceCache);
        $container->instance(ModrinthSource::class, $modrinth);
        $registry = $this->makeRegistry($operations, modrinth: $modrinth);

        Http::fake(static function (HttpClientRequest $request) {
            if (str_contains($request->url(), '/v2/projects')) {
                return Http::response([[
                    'id' => 'P1OZGk5p',
                    'slug' => 'viaversion',
                    'title' => 'ViaVersion',
                    'description' => 'Slug matched project',
                    'icon_url' => 'https://cdn.modrinth.com/data/P1OZGk5p/icon.png',
                    'downloads' => 1000,
                    'updated' => '2026-11-20T00:00:00Z',
                    'project_type' => 'mod',
                ]], 200);
            }

            return Http::response([], 404);
        });

        $projectIds = ['ViaVersion', 'P1OZGk5p', 'p1ozgk5p'];
        (new WarmProjectMetadata('modrinth', $projectIds))->handle($registry, $cache);

        $peeked = $modrinth->peekProjects($projectIds);
        self::assertIsArray($peeked['ViaVersion']['data']);
        self::assertSame('Slug matched project', $peeked['ViaVersion']['data']['description']);
        self::assertSame('ViaVersion', $peeked['P1OZGk5p']['data']['title']);
        self::assertNull($peeked['p1ozgk5p']['data']);
        self::assertFalse($peeked['p1ozgk5p']['pending']);
    }

    public function test_modrinth_single_project_request_exposes_normalized_display_and_raw_fields(): void
    {
        [$container, , , $sourceCache] = $this->bootEnvironment();
        $modrinth = new ModrinthSource($sourceCache);
        $container->instance(ModrinthSource::class, $modrinth);

        Http::fake(static function (HttpClientRequest $request) {
            if (str_contains($request->url(), '/v2/project/P1OZGk5p')) {
                return Http::response([
                    'id' => 'P1OZGk5p',
                    'slug' => 'viaversion',
                    'title' => 'ViaVersion',
                    'description' => 'Single project fetch',
                    'icon_url' => 'https://cdn.modrinth.com/data/P1OZGk5p/icon.png',
                    'downloads' => 42000,
                    'updated' => '2026-11-20T00:00:00Z',
                    'team' => 'team-123',
                    'server_side' => 'required',
                ], 200);
            }

            return Http::response([], 404);
        });

        $project = $modrinth->getProject('P1OZGk5p');
        self::assertIsArray($project);
        self::assertSame('P1OZGk5p', $project['project_id']);
        self::assertSame('P1OZGk5p', $project['id']);
        self::assertSame('2026-11-20T00:00:00Z', $project['date_modified']);
        self::assertSame(42000, $project['downloads']);
        self::assertSame('team-123', $project['team']);
        self::assertSame('required', $project['server_side']);
    }

    /**
     * @return array{0: Container, 1: CacheRepository, 2: InstalledOperationManager, 3: SourceCache, 4: object}
     */
    private function bootEnvironment(string $curseForgeApiKey = ''): array
    {
        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $cache = new CacheRepository(new ArrayStore());
        $config = new ConfigRepository([
            'pelican-mod-manager' => [
                'debug_timing' => false,
                'modrinth_token' => '',
                'curseforge_api_key' => $curseForgeApiKey,
                'hangar_api_key' => '',
                'github_token' => '',
            ],
            'queue' => ['default' => 'sync'],
        ]);

        $runner = PluginBackgroundRunner::fake($cache);

        $operations = new InstalledOperationManager(
            $cache,
            $config,
            new InstalledOperationLease($cache),
            $runner,
        );
        $executor = new SourceFetchExecutor($container);
        $sourceCache = new SourceCache($cache, $operations, $executor);

        $container->instance('cache', $cache);
        $container->instance(CacheRepository::class, $cache);
        $container->instance('config', $config);
        $container->instance(ConfigRepository::class, $config);
        $container->instance('http', new HttpFactory());
        $container->instance(InstalledOperationManager::class, $operations);
        $container->instance(SourceFetchExecutor::class, $executor);
        $container->instance(SourceCache::class, $sourceCache);
        $container->instance(ExceptionHandler::class, new class implements ExceptionHandler
        {
            public function report(Throwable $e): void {}

            public function shouldReport(Throwable $e): bool
            {
                return false;
            }

            public function render($request, Throwable $e)
            {
                return null;
            }

            public function renderForConsole($output, Throwable $e): void {}
        });

        return [$container, $cache, $operations, $sourceCache, $runner];
    }

    private function makeRegistry(
        InstalledOperationManager $operations,
        ?ModrinthSource $modrinth = null,
    ): ProjectSourceRegistry {
        $modrinth ??= Mockery::mock(ModrinthSource::class);
        $curseForge = Mockery::mock(CurseForgeSource::class);
        $curseForge->shouldReceive('getKey')->andReturn(ProjectSourceKey::CurseForge);
        $curseForge->shouldReceive('isConfigured')->andReturn(false);
        $curseForge->shouldReceive('supportsProjectType')->andReturn(false);
        $curseForge->shouldReceive('supportsHashLookup')->andReturn(false);

        $hangar = Mockery::mock(HangarSource::class);
        $hangar->shouldReceive('getKey')->andReturn(ProjectSourceKey::Hangar);
        $hangar->shouldReceive('isConfigured')->andReturn(false);
        $hangar->shouldReceive('supportsProjectType')->andReturn(false);
        $hangar->shouldReceive('supportsHashLookup')->andReturn(false);

        $spigot = Mockery::mock(SpigotSource::class);
        $spigot->shouldReceive('getKey')->andReturn(ProjectSourceKey::Spigot);
        $spigot->shouldReceive('isConfigured')->andReturn(false);
        $spigot->shouldReceive('supportsProjectType')->andReturn(false);
        $spigot->shouldReceive('supportsHashLookup')->andReturn(false);

        $github = Mockery::mock(GitHubReleasesSource::class);
        $github->shouldReceive('getKey')->andReturn(ProjectSourceKey::GitHubReleases);
        $github->shouldReceive('isConfigured')->andReturn(false);
        $github->shouldReceive('supportsProjectType')->andReturn(false);
        $github->shouldReceive('supportsHashLookup')->andReturn(false);

        return new ProjectSourceRegistry(
            $modrinth,
            $curseForge,
            $hangar,
            $spigot,
            $github,
            $operations,
            new ServerModManagerSettings(new ServerModManagerSettingRepository()),
        );
    }

    private function makeInMemoryRepo(): InstalledMetadataRepository
    {
        return new class extends InstalledMetadataRepository
        {
            public ?InstalledMetadataDocument $doc = null;

            public function __construct() {}

            public function read(Server $server, DaemonFileRepository $fileRepository, string $folder): InstalledMetadataReadResult
            {
                return new InstalledMetadataReadResult(
                    $this->doc ?? InstalledMetadataDocument::empty(),
                    $this->doc === null ? InstalledMetadataReadStatus::Missing : InstalledMetadataReadStatus::Current,
                );
            }

            public function mutate(Server $server, DaemonFileRepository $fileRepository, string $folder, Closure $callback): bool
            {
                $this->doc = $callback($this->doc ?? InstalledMetadataDocument::empty());

                return true;
            }
        };
    }

    private function makePluginServer(): Server
    {
        $server = new Server();
        $server->forceFill(['id' => 77, 'uuid' => 'srv-77']);
        $egg = new Egg();
        $egg->forceFill(['tags' => ['minecraft'], 'features' => ['plugin_manager'], 'name' => 'Paper']);
        $server->setRelation('egg', $egg);

        return $server;
    }
}
