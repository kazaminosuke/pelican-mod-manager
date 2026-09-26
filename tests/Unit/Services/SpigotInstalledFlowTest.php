<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Repositories\InstalledMetadataRepository;
use Kazaminosuke\ModManager\Services\InstalledArchiveTransaction;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Services\InstalledProjectUpdateService;
use Kazaminosuke\ModManager\Services\VersionLookupCoordinator;
use Kazaminosuke\ModManager\Sources\SpigotSource;
use Kazaminosuke\ModManager\Support\BukkitPluginDescriptor;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadResult;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Kazaminosuke\ModManager\Support\SpigotFileIndex;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the Installed tab's Spigot flows: saved
 * identities are authoritative, plugin.yml identifies new JARs, confirmed
 * matches are remembered in the local hash index, and bulk updates install
 * only files Spiget's CDN actually mirrors.
 */
class SpigotInstalledFlowTest extends TestCase
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
        $container->instance('date', new DateFactory());
        $container->instance(ExceptionHandler::class, Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing());
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Http::swap($factory);
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
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Mockery::close();
        parent::tearDown();
    }

    public function test_saved_spigot_identity_is_reused_without_reading_the_jar_or_calling_upstream(): void
    {
        Http::fake();
        $service = $this->service();
        $hashes = ['sha256' => str_repeat('aa', 32)];
        $existing = [
            'source' => 'spigot',
            'project_id' => '28140',
            'project_slug' => '28140',
            'project_title' => 'LuckPerms',
            'version_id' => '648014',
            'version_number' => '5.5.71',
            'filename' => 'LuckPerms.jar',
            'installed_at' => '2026-09-01T00:00:00+00:00',
        ];

        $matched = $service->identify(['LuckPerms-renamed.jar'], [
            'LuckPerms-renamed.jar' => ['file_signature' => ['size' => 10, 'modified_at' => 'now']],
        ], ['LuckPerms-renamed.jar' => $hashes], ['luckperms-renamed.jar' => $existing]);

        $entry = $matched['LuckPerms-renamed.jar'];
        self::assertSame('28140', $entry['project_id']);
        self::assertSame('648014', $entry['version_id']);
        self::assertSame('LuckPerms-renamed.jar', $entry['filename']);
        self::assertSame($hashes, $entry['hashes']);
        self::assertSame(['size' => 10, 'modified_at' => 'now'], $entry['file_signature']);
        self::assertSame(0, $service->extractions);
        Http::assertNothingSent();
    }

    public function test_plugin_yml_website_identifies_the_resource_with_official_metadata(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/11431/versions*' => Http::response([
                ['id' => 88, 'name' => '7.0.9', 'releaseDate' => 1_700_000_000, 'downloads' => 1, 'resource' => 11431],
                ['id' => 87, 'name' => '7.0.8', 'releaseDate' => 1_690_000_000, 'downloads' => 1, 'resource' => 11431],
            ]),
            'api.spiget.org/v2/resources/11431' => Http::response([
                'id' => 11431,
                'name' => 'WorldGuard',
                'premium' => false,
                'external' => false,
                'file' => ['type' => '.jar'],
                'version' => ['id' => 88],
            ]),
            'api.spigotmc.org/simple/0.2/index.php*' => Http::response([
                'id' => 11431,
                'title' => 'WorldGuard',
                'tag' => 'Protect worlds',
                'author' => ['id' => 1, 'username' => 'sk89q'],
                'stats' => ['downloads' => 12_345],
                'last_update' => 1_700_000_000,
            ]),
        ]);
        $service = $this->service([
            'worldguard.jar' => new BukkitPluginDescriptor(
                name: 'WorldGuard',
                version: '7.0.9',
                authors: ['sk89q'],
                website: 'https://www.spigotmc.org/resources/worldguard.11431/',
                main: 'com.sk89q.worldguard.bukkit.WorldGuardPlugin',
                filename: 'worldguard.jar',
            ),
        ]);
        $hash = str_repeat('bb', 32);

        $matched = $service->identify(['worldguard.jar'], [], ['worldguard.jar' => ['sha256' => $hash]], []);

        $entry = $matched['worldguard.jar'];
        self::assertSame('spigot', $entry['source']);
        self::assertSame('11431', $entry['project_id']);
        self::assertSame('WorldGuard', $entry['project_title']);
        self::assertSame('sk89q', $entry['author']);
        self::assertSame('88', $entry['version_id']);
        self::assertSame('7.0.9', $entry['version_number']);
        self::assertSame('11431', (new SpigotFileIndex())->findBySha256($hash)['resource_id'] ?? null);
        // Identification never probes a download.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/download'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/search/resources/'));
    }

    public function test_unmatched_plugin_versions_are_left_unidentified(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/11431/versions*' => Http::response([
                ['id' => 88, 'name' => '7.0.9', 'releaseDate' => 1_700_000_000, 'downloads' => 1],
            ]),
            'api.spiget.org/v2/resources/11431' => Http::response(['id' => 11431, 'name' => 'WorldGuard']),
        ]);
        $service = $this->service([
            'worldguard.jar' => new BukkitPluginDescriptor(
                name: 'WorldGuard',
                version: '7.1.0-SNAPSHOT',
                authors: [],
                website: 'https://www.spigotmc.org/resources/worldguard.11431/',
                main: null,
                filename: 'worldguard.jar',
            ),
        ]);

        self::assertSame([], $service->identify(['worldguard.jar', 'readme.txt'], [], [], []));
        self::assertSame(1, $service->extractions);
    }

    public function test_disabled_spigot_source_skips_archive_identification(): void
    {
        Http::fake();
        $service = $this->service([], available: false);

        self::assertSame([], $service->identify(['plugin.jar'], [], [], []));
        self::assertSame(0, $service->extractions);
        Http::assertNothingSent();
    }

    public function test_confirmed_spigot_entries_are_recorded_in_the_local_hash_index(): void
    {
        Http::fake();
        $service = $this->service();
        $spigotHash = str_repeat('cc', 32);
        $modrinthHash = str_repeat('dd', 32);

        $service->remember([
            'source' => 'spigot',
            'project_id' => '34315',
            'project_title' => 'Vault',
            'version_id' => '344916',
            'version_number' => '1.7.3',
            'hashes' => ['sha256' => $spigotHash],
        ]);
        $service->remember([
            'source' => 'modrinth',
            'project_id' => 'abc',
            'version_id' => 'v1',
            'version_number' => '1.0',
            'hashes' => ['sha256' => $modrinthHash],
        ]);

        $found = $service->source->findVersionsByHashAuthoritatively([
            'Vault.jar' => $spigotHash,
            'other.jar' => $modrinthHash,
        ]);

        self::assertSame(['34315'], array_values(array_map(fn (array $version): string => $version['project_id'], $found)));
        self::assertSame('344916', $found[$spigotHash]['id']);
        self::assertSame([], $found[$spigotHash]['files']);
        Http::assertNothingSent();
    }

    public function test_bulk_update_installs_the_cdn_file_and_skips_uninstallable_spigot_updates(): void
    {
        Http::fake([
            'api.spiget.org/v2/resources/50/download' => Http::response('', 302, [
                'X-Spiget-File-Source' => 'cdn',
                'Location' => 'https://cdn.spiget.org/file/spiget-resources/50.jar',
            ]),
            'api.spiget.org/v2/resources/50/versions/latest' => Http::response([
                'name' => '1.2.0',
                'releaseDate' => 1_700_000_000,
                'id' => 3,
            ]),
            'api.spiget.org/v2/resources/50' => Http::response([
                'id' => 50,
                'name' => 'FreePlugin',
                'premium' => false,
                'external' => false,
                'file' => ['type' => '.jar'],
                'version' => ['id' => 3],
            ]),
            'api.spiget.org/v2/resources/9089/versions/latest' => Http::response([
                'name' => '2.22.0',
                'releaseDate' => 1_780_242_808,
                'id' => 639442,
            ]),
            'api.spiget.org/v2/resources/9089' => Http::response([
                'id' => 9089,
                'name' => 'EssentialsX',
                'premium' => false,
                'external' => true,
                'file' => ['type' => 'external'],
                'version' => ['id' => 639442],
            ]),
        ]);
        $source = $this->service()->source;
        $installed = [
            $this->installedEntry('50', '2', '1.1.0', 'FreePlugin.jar'),
            $this->installedEntry('9089', '600000', '2.21.0', 'EssentialsX.jar'),
        ];
        $projects = Mockery::mock(InstalledProjectService::class);
        $projects->shouldReceive('getInstalledMetadataReadResult')->once()->andReturn(new InstalledMetadataReadResult(
            InstalledMetadataDocument::empty()->withInstalledMods($installed),
            InstalledMetadataReadStatus::Current,
        ));
        $projects->shouldReceive('saveInstalledMetadataDocument')->andReturnTrue();
        $projects->shouldReceive('getHashScanCacheKey')->once()->andReturn('scan-key');
        $versions = Mockery::mock(VersionLookupCoordinator::class);
        $versions->shouldReceive('lookupInstalled')->once()->andReturnUsing(
            fn (array $mods, Server $server, ProjectType $type) => $source->lookupLatestVersions(
                array_values(array_filter(array_map(LatestVersionLookupRequest::fromInstalledMod(...), $mods))),
                $server,
                $type,
            ),
        );
        $archives = Mockery::mock(InstalledArchiveTransaction::class);
        $archives->shouldReceive('installOrUpdate')
            ->once()
            ->withArgs(function (...$arguments): bool {
                [, , , $record, $version, $primaryFile] = $arguments;

                return $record['project_id'] === '50'
                    && $version['id'] === '3'
                    && $primaryFile['url'] === 'https://cdn.spiget.org/file/spiget-resources/50.jar'
                    && $primaryFile['filename'] === 'FreePlugin-1.2.0.jar';
            });
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('forget')->once()->with('scan-key');
        $server = new Server();
        $server->forceFill(['id' => 7]);

        $result = (new InstalledProjectUpdateService($projects, $archives, $versions, $cache))
            ->updateAll($server, Mockery::mock(DaemonFileRepository::class), ProjectType::Plugin);

        self::assertSame(['total' => 2, 'updated' => 1, 'failed' => 0, 'skipped' => 1], $result);
    }

    /** @return array<string, mixed> */
    private function installedEntry(string $projectId, string $versionId, string $versionNumber, string $filename): array
    {
        return [
            'source' => 'spigot',
            'project_id' => $projectId,
            'project_slug' => $projectId,
            'project_title' => $filename,
            'version_id' => $versionId,
            'version_number' => $versionNumber,
            'filename' => $filename,
            'installed_at' => '2026-09-01T00:00:00+00:00',
        ];
    }

    /** @param array<string, BukkitPluginDescriptor> $descriptors */
    private function service(array $descriptors = [], bool $available = true): TestableSpigotInstalledService
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
        $source = new SpigotSource(new SourceCache($cache, new InstalledOperationManager($cache, app('config')), $executor));
        $executor->source = $source;

        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('get')->with(ProjectSourceKey::Spigot)->andReturn($source);
        $registry->shouldReceive('availableFor')->andReturn($available ? [$source] : []);

        return new TestableSpigotInstalledService($registry, $source, $descriptors);
    }
}

class TestableSpigotInstalledService extends InstalledProjectService
{
    public int $extractions = 0;

    /** @param array<string, BukkitPluginDescriptor> $descriptors */
    public function __construct(
        ProjectSourceRegistry $registry,
        public readonly SpigotSource $source,
        private readonly array $descriptors,
    ) {
        parent::__construct($registry, Mockery::mock(InstalledMetadataRepository::class));
    }

    /**
     * @param array<int, string> $filenames
     * @param array<string, array<string, mixed>> $filesToResolve
     * @param array<string, array<string, string>> $hashesByFilename
     * @param array<string, array<string, mixed>> $installedByFilename
     * @return array<string, array<string, mixed>>
     */
    public function identify(array $filenames, array $filesToResolve, array $hashesByFilename, array $installedByFilename): array
    {
        $server = new Server();
        $server->forceFill(['id' => 7]);

        return $this->identifyRemainingFromArchiveMetadata(
            $server,
            Mockery::mock(DaemonFileRepository::class),
            'plugins',
            ProjectType::Plugin,
            $filenames,
            $filesToResolve,
            $hashesByFilename,
            $installedByFilename,
        );
    }

    /** @param array<string, mixed> $entry */
    public function remember(array $entry): void
    {
        $this->rememberSpigotIndex($entry);
    }

    protected function extractBukkitPluginDescriptor(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $path,
        string $filename,
    ): ?BukkitPluginDescriptor {
        $this->extractions++;

        return $this->descriptors[$filename] ?? null;
    }
}
