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
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\PartialSourceFetchException;
use Kazaminosuke\ModManager\Repositories\InstalledMetadataRepository;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Sources\SpigotSource;
use Kazaminosuke\ModManager\Support\CurseForgeFingerprint;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadResult;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchExecutor;
use Kazaminosuke\ModManager\Support\SpigotFileIndex;
use Mockery;
use PHPUnit\Framework\TestCase;
use Throwable;
use ZipArchive;

class ScanPerformanceAndResilienceTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacade = null;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_curseforge_fingerprint_hash_stream_and_file_match_across_multi_chunk_payloads(): void
    {
        $payload = str_repeat("ABCD \t\r\n1234567890!@#\$%^&*()\n", 6000).'tail';
        $expected = CurseForgeFingerprint::hash($payload);

        $fromStream = CurseForgeFingerprint::hashStream(
            static fn () => Utils::streamFor($payload),
        );

        $tmp = tempnam(sys_get_temp_dir(), 'pmm-fp-test-');
        self::assertIsString($tmp);

        try {
            file_put_contents($tmp, $payload);
            $sha256 = hash_init('sha256');
            $fromFile = CurseForgeFingerprint::hashFile(
                $tmp,
                static function (string $chunk) use ($sha256): void {
                    hash_update($sha256, $chunk);
                },
            );

            self::assertSame($expected, $fromStream);
            self::assertSame($expected, $fromFile);
            self::assertSame(hash('sha256', $payload), hash_final($sha256));
        } finally {
            @unlink($tmp);
        }
    }

    public function test_hangar_parallel_hash_lookup_preserves_partial_matches_and_primes_projects(): void
    {
        [$container, $cache] = $this->bootContainer();
        $sourceCache = $this->makeSourceCache($container, $cache);
        $hangar = new HangarSource($sourceCache);

        $hashOk = str_repeat('a', 64);
        $hashMiss = str_repeat('b', 64);
        $hashFail = str_repeat('c', 64);
        $hashMalformed = str_repeat('d', 64);
        $projectEndpointHits = 0;

        Http::fake(static function (HttpClientRequest $request) use ($hashOk, $hashMiss, $hashFail, $hashMalformed, &$projectEndpointHits) {
            $url = $request->url();

            if (str_contains($url, '/versions/hash/'.$hashOk)) {
                return Http::response([
                    'id' => 42,
                    'name' => 'Geyser',
                    'namespace' => ['owner' => 'GeyserMC', 'slug' => 'Geyser'],
                    'description' => 'Bedrock bridge',
                    'avatarUrl' => 'https://hangarcdn.papermc.io/geyser.png',
                    'stats' => ['downloads' => 9999],
                    'lastUpdated' => '2026-11-20T00:00:00Z',
                ], 200);
            }

            if (str_contains($url, '/projects/42/versions')) {
                return Http::response([
                    'result' => [[
                        'id' => 777,
                        'name' => '2.6.0',
                        'createdAt' => '2026-11-20T00:00:00Z',
                        'downloads' => [
                            'PAPER' => [
                                'fileInfo' => [
                                    'name' => 'Geyser.jar',
                                    'sha256Hash' => $hashOk,
                                ],
                                'downloadUrl' => 'https://hangarcdn.papermc.io/Geyser.jar',
                            ],
                        ],
                    ]],
                ], 200);
            }

            if (str_contains($url, '/versions/hash/'.$hashMiss)) {
                return Http::response([], 404);
            }

            if (str_contains($url, '/versions/hash/'.$hashFail)) {
                return Http::response(['message' => 'upstream error'], 500);
            }

            if (str_contains($url, '/versions/hash/'.$hashMalformed)) {
                return Http::response(['id' => 43]);
            }

            if (str_contains($url, '/projects/43/versions')) {
                return Http::response(['result' => ['message' => 'invalid versions payload']]);
            }

            if (preg_match('~/projects/[^/]+$~', $url) === 1) {
                $projectEndpointHits++;
            }

            return Http::response([], 404);
        });

        $caught = null;
        try {
            $hangar->findVersionsByHashAuthoritatively([
                'Geyser.jar' => $hashOk,
                'Unknown.jar' => $hashMiss,
                'Broken.jar' => $hashFail,
                'Malformed.jar' => $hashMalformed,
            ]);
        } catch (PartialSourceFetchException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(PartialSourceFetchException::class, $caught);
        $partial = $caught->fallback();
        self::assertIsArray($partial);
        self::assertArrayHasKey($hashOk, $partial);
        self::assertSame('42', $partial[$hashOk]['project_id']);
        self::assertArrayNotHasKey($hashMiss, $partial);
        self::assertArrayNotHasKey($hashMalformed, $partial);

        // The malformed versions response should cool down, even if the
        // first failure reported by the batch was for another hash.
        try {
            $hangar->findVersionsByHashAuthoritatively(['Malformed.jar' => $hashMalformed]);
            self::fail('An invalid versions response must not be treated as a definitive miss.');
        } catch (\Exception $exception) {
            self::assertStringContainsString('temporarily unavailable', $exception->getMessage());
        }
        Http::assertSentCount(6);

        // Project metadata from the hash match was primed immediately into the project cache.
        $projects = $hangar->getProjectsByIdsAuthoritatively(['42']);
        self::assertSame(0, $projectEndpointHits);
        self::assertSame('Geyser', $projects['42']['title']);
        self::assertSame('Bedrock bridge', $projects['42']['description']);
        self::assertSame('https://hangarcdn.papermc.io/geyser.png', $projects['42']['icon_url']);
    }

    public function test_modrinth_scan_resolves_authors_in_one_batch_request(): void
    {
        [$container, $cache] = $this->bootContainer();
        $sourceCache = $this->makeSourceCache($container, $cache);
        $modrinth = new ModrinthSource($sourceCache);

        $batchUserCalls = 0;
        $singleUserCalls = 0;

        Http::fake(static function (HttpClientRequest $request) use (&$batchUserCalls, &$singleUserCalls) {
            $url = $request->url();

            if (str_contains($url, '/v2/users')) {
                $batchUserCalls++;

                return Http::response([
                    ['id' => 'u1', 'username' => 'kennytv'],
                    ['id' => 'u2', 'username' => 'lucko'],
                ], 200);
            }

            if (str_contains($url, '/v2/user/')) {
                $singleUserCalls++;

                return Http::response(['id' => 'single', 'username' => 'fallback'], 200);
            }

            return Http::response([], 404);
        });

        $modrinth->primeAuthorsByUserIds(['u1', 'u2', 'u1']);

        self::assertSame(1, $batchUserCalls);
        self::assertSame(0, $singleUserCalls);
        // Subsequent resolveAuthor calls hit the cache with zero extra HTTP calls.
        self::assertSame('kennytv', $modrinth->resolveAuthor([], ['author_id' => 'u1']));
        self::assertSame('lucko', $modrinth->resolveAuthor([], ['author_id' => 'u2']));
        self::assertSame(1, $batchUserCalls);
        self::assertSame(0, $singleUserCalls);
    }

    public function test_plugin_scan_downloads_each_jar_once_reports_progress_and_aborts_on_heartbeat_loss(): void
    {
        [$container, $cache] = $this->bootContainer();
        $sourceCache = $this->makeSourceCache($container, $cache);
        $modrinth = new ModrinthSource($sourceCache);

        $spigotJarBytes = $this->buildJar('SpigotSample', '1.0.0', 'DevAuthor');
        $spigotSha256 = hash('sha256', $spigotJarBytes);

        Http::fake(static function (HttpClientRequest $request) {
            $url = $request->url();
            if (str_contains($url, 'api.modrinth.com/v2/version_files')) {
                return Http::response([], 200);
            }
            if (str_contains($url, 'api.spiget.org/v2/search/resources/')) {
                return Http::response([[
                    'id' => 55555,
                    'name' => 'SpigotSample',
                    'tag' => 'Sample plugin',
                    'author' => ['id' => 9, 'name' => 'DevAuthor'],
                    'downloads' => 1000,
                    'updateDate' => 1700000000,
                    'file' => ['type' => '.jar'],
                ]], 200);
            }
            if (str_contains($url, 'api.spiget.org/v2/authors/9')) {
                return Http::response(['id' => 9, 'name' => 'DevAuthor'], 200);
            }
            if (str_contains($url, 'api.spiget.org/v2/resources/55555/versions')) {
                return Http::response([['id' => 888, 'name' => '1.0.0', 'releaseDate' => 1700000000]], 200);
            }
            if (preg_match('~api\.spiget\.org/v2/resources/55555(?:\?|$)~', $url) === 1) {
                return Http::response([
                    'id' => 55555,
                    'name' => 'SpigotSample',
                    'tag' => 'Sample plugin',
                    'author' => ['id' => 9, 'name' => 'DevAuthor'],
                    'downloads' => 1000,
                    'updateDate' => 1700000000,
                    'file' => ['type' => '.jar'],
                ], 200);
            }
            if (str_contains($url, 'api.spigotmc.org/simple/0.2/index.php')) {
                return Http::response([
                    'id' => '55555',
                    'title' => 'SpigotSample',
                    'tag' => 'Sample plugin',
                    'current_version' => '1.0.0',
                    'author' => ['id' => '9', 'username' => 'DevAuthor'],
                    'stats' => ['downloads' => '1000'],
                ], 200);
            }

            return Http::response([], 404);
        });

        $spigot = new class($sourceCache) extends SpigotSource
        {
            public function __construct(SourceCache $sourceCache)
            {
                parent::__construct($sourceCache, new SpigotFileIndex());
            }

            public function findVersionsByHashAuthoritatively(array $hashesByFilename): array
            {
                return [];
            }

            public function rememberInstalledEntry(array $entry): void {}

            protected function rememberHash(array $hashes, array $version, ?string $pluginName = null): void {}
        };

        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('availableFor')->andReturn([$modrinth, $spigot]);
        $registry->shouldReceive('get')->with(ProjectSourceKey::Spigot)->andReturn($spigot);
        $registry->shouldReceive('get')->with(ProjectSourceKey::Modrinth)->andReturn($modrinth);

        $repo = new class extends InstalledMetadataRepository
        {
            public ?InstalledMetadataDocument $doc = null;

            public int $writes = 0;

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
                $this->writes++;
                $this->doc = $callback($this->doc ?? InstalledMetadataDocument::empty());

                return true;
            }
        };

        $service = new class($registry, $repo, $spigotJarBytes) extends InstalledProjectService
        {
            public int $wingsGets = 0;

            public function __construct(
                ProjectSourceRegistry $registry,
                InstalledMetadataRepository $repo,
                private readonly string $jarBytes,
            ) {
                parent::__construct($registry, $repo);
            }

            protected function openDaemonFileStream(DaemonFileRepository $fileRepository, Server $server, string $path): object
            {
                $this->wingsGets++;

                return Utils::streamFor($this->jarBytes);
            }
        };

        $server = new Server();
        $server->forceFill(['id' => 99, 'uuid' => 'srv-99']);
        $egg = new Egg();
        $egg->forceFill(['tags' => ['minecraft'], 'features' => ['plugin_manager'], 'name' => 'Paper']);
        $server->setRelation('egg', $egg);

        $fileRepository = Mockery::mock(DaemonFileRepository::class);
        $fileRepository->shouldReceive('setServer')->with($server)->andReturnSelf();
        $fileRepository->shouldReceive('getDirectory')->with('plugins')->andReturn([[
            'name' => 'SpigotSample.jar',
            'size' => strlen($spigotJarBytes),
            'modified' => '2026-11-20T00:00:00Z',
            'file' => true,
            'directory' => false,
        ]]);

        $progressUpdates = [];
        $result = $service->scanAndImportModsResult(
            $server,
            $fileRepository,
            ProjectType::Plugin,
            true,
            static function (int $completed, int $total) use (&$progressUpdates): void {
                $progressUpdates[] = [$completed, $total];
            },
            static fn (): bool => true,
        );

        self::assertTrue($result->successful, 'Unexpected scan failure: '.($result->failure ?? 'null'));
        self::assertSame(1, $service->wingsGets, 'Plugin JAR must be downloaded from Wings only once (never re-downloaded for plugin.yml extraction).');
        self::assertNotEmpty($progressUpdates);
        self::assertSame(1, $repo->writes);
        self::assertNotNull($repo->doc);
        $entries = $repo->doc->installedMods();
        self::assertCount(1, $entries);
        self::assertSame('spigot', $entries[0]['source']);
        self::assertSame('55555', $entries[0]['project_id']);
        self::assertSame($spigotSha256, $entries[0]['hashes']['sha256'] ?? null);
        self::assertArrayNotHasKey('murmur2', $entries[0]['hashes'] ?? [], 'MurmurHash2 must be skipped when no configured source uses it.');

        // Ownership loss via heartbeat aborts cleanly without writing metadata.
        $writesBefore = $repo->writes;
        $aborted = $service->scanAndImportModsResult(
            $server,
            $fileRepository,
            ProjectType::Plugin,
            true,
            null,
            static fn (): bool => false,
        );

        self::assertFalse($aborted->successful);
        self::assertSame('scan_ownership_lost', $aborted->failure);
        self::assertSame($writesBefore, $repo->writes);
    }

    /** @return array{0: Container, 1: CacheRepository} */
    private function bootContainer(): array
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
                'curseforge_api_key' => '',
                'hangar_api_key' => '',
                'github_token' => '',
            ],
            'queue' => ['default' => 'sync'],
        ]);

        $container->instance('cache', $cache);
        $container->instance(CacheRepository::class, $cache);
        $container->instance('config', $config);
        $container->instance(ConfigRepository::class, $config);
        $container->instance('http', new HttpFactory());
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

        return [$container, $cache];
    }

    private function makeSourceCache(Container $container, CacheRepository $cache): SourceCache
    {
        $operations = new InstalledOperationManager(
            $cache,
            $container->make(ConfigRepository::class),
            new InstalledOperationLease($cache),
        );
        $executor = new SourceFetchExecutor($container);
        $sourceCache = new SourceCache($cache, $operations, $executor);

        $container->instance(InstalledOperationManager::class, $operations);
        $container->instance(SourceFetchExecutor::class, $executor);
        $container->instance(SourceCache::class, $sourceCache);

        return $sourceCache;
    }

    private function buildJar(string $name, string $version, string $author): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pmm-test-jar-');
        self::assertIsString($tmp);

        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('plugin.yml', "name: {$name}\nversion: \"{$version}\"\nauthor: {$author}\nmain: com.example.Main\n");
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
