<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Repositories\InstalledMetadataRepository;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadResult;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Kazaminosuke\ModManager\Support\InstalledScanResult;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Mockery;
use PHPUnit\Framework\TestCase;

final class InstalledScanRevalidationTest extends TestCase
{
    private mixed $previousFacadeApplication = null;

    private ?Container $previousContainer = null;

    private CacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousContainer = Container::getInstance();
        $this->cache = new CacheRepository(new ArrayStore());
        $container = new Container();
        $container->instance('cache', $this->cache);
        $container->instance('config', new ConfigRepository([
            'pelican-mod-manager' => ['debug_timing' => false],
        ]));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        Mockery::close();

        parent::tearDown();
    }

    public function test_unchanged_folder_is_reconciled_without_hashing_lookups_or_a_write(): void
    {
        $scan = $this->scanFixture(lookupCalls: 0);

        $result = $scan['service']->scanAndImportModsResult($scan['server'], $scan['files'], ProjectType::Mod);

        self::assertTrue($result->successful);
        self::assertSame(['mystery.jar'], $result->unknownFiles);
        self::assertSame(2, $result->diskFileCount);
        self::assertSame(0, $scan['service']->hashedFiles);
        self::assertEquals($scan['document']->toArray(), $scan['written']()->toArray());
    }

    public function test_explicit_rescan_rechecks_recent_unknown_files_without_rehashing(): void
    {
        $scan = $this->scanFixture(lookupCalls: 1);

        $result = $scan['service']->scanAndImportModsResult($scan['server'], $scan['files'], ProjectType::Mod, force: true);

        self::assertSame(['mystery.jar'], $result->unknownFiles);
        self::assertSame(0, $scan['service']->hashedFiles);
    }

    public function test_external_file_change_is_hashed_and_identified(): void
    {
        $scan = $this->scanFixture(lookupCalls: 1, extraListing: [
            ['name' => 'added.jar', 'size' => 30, 'modified' => '2026-09-01T00:00:00Z', 'file' => true],
        ]);

        $result = $scan['service']->scanAndImportModsResult($scan['server'], $scan['files'], ProjectType::Mod);

        self::assertSame(['mystery.jar', 'added.jar'], $result->unknownFiles);
        self::assertSame(3, $result->diskFileCount);
        self::assertSame(1, $scan['service']->hashedFiles);
    }

    public function test_fresh_result_is_reused_and_a_stale_one_is_revalidated(): void
    {
        $service = $this->scriptedService([
            InstalledScanResult::success(['a.jar'], 1),
            InstalledScanResult::success([], 1),
        ]);
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);

        $service->scanAndImportModsResult($server, $files, ProjectType::Mod);
        $reused = $service->scanAndImportModsResult($server, $files, ProjectType::Mod);

        self::assertTrue($reused->cacheHit);
        self::assertSame(1, $service->scanExecutions);

        $key = $service->getHashScanCacheKey($server, ProjectType::Mod);
        $this->cache->put($key, [
            ...$this->cache->get($key),
            'checked_at' => time() - InstalledScanResult::FRESH_SECONDS - 1,
        ], 60);

        $revalidated = $service->scanAndImportModsResult($server, $files, ProjectType::Mod);

        self::assertFalse($revalidated->cacheHit);
        self::assertSame([], $revalidated->unknownFiles);
        self::assertSame(2, $service->scanExecutions);
    }

    public function test_explicit_rescan_ignores_a_fresh_result(): void
    {
        $service = $this->scriptedService([
            InstalledScanResult::success(['a.jar'], 1),
            InstalledScanResult::success([], 1),
        ]);
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);

        $service->scanAndImportModsResult($server, $files, ProjectType::Mod);
        $service->scanAndImportModsResult($server, $files, ProjectType::Mod, force: true);

        self::assertSame(2, $service->scanExecutions);
    }

    public function test_partial_failure_stays_displayable_but_is_revalidated(): void
    {
        $service = $this->scriptedService([
            InstalledScanResult::failed('hash_lookup_partial_failure', ['a.jar'], 2),
        ]);
        $server = $this->server();

        $result = $service->scanAndImportModsResult($server, Mockery::mock(DaemonFileRepository::class), ProjectType::Mod);
        $cached = InstalledScanResult::fromCache($this->cache->get($service->getHashScanCacheKey($server, ProjectType::Mod)));

        self::assertFalse($result->successful);
        self::assertNotNull($cached);
        self::assertSame(['a.jar'], $cached->unknownFiles);
        self::assertSame(2, $cached->diskFileCount);
        self::assertFalse($cached->isFresh());
    }

    public function test_transport_failure_keeps_the_previous_result(): void
    {
        $service = $this->scriptedService([
            InstalledScanResult::success(['a.jar'], 4),
            InstalledScanResult::failed('wings_directory_unavailable'),
        ]);
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);

        $service->scanAndImportModsResult($server, $files, ProjectType::Mod);
        $service->scanAndImportModsResult($server, $files, ProjectType::Mod, force: true);
        $cached = InstalledScanResult::fromCache($this->cache->get($service->getHashScanCacheKey($server, ProjectType::Mod)));

        self::assertSame(4, $cached?->diskFileCount);
        self::assertSame(['a.jar'], $cached?->unknownFiles);
    }

    public function test_result_cached_before_checked_at_existed_is_stale(): void
    {
        $result = InstalledScanResult::fromCache([
            'schema_version' => 2,
            'successful' => true,
            'unknown_files' => [],
            'disk_file_count' => 1,
        ]);

        self::assertNotNull($result);
        self::assertFalse($result->isFresh());
    }

    /**
     * @param  array<int, array<string, mixed>>  $extraListing
     * @return array{service: RevalidationTestService, server: Server, files: DaemonFileRepository, document: InstalledMetadataDocument, written: Closure(): InstalledMetadataDocument}
     */
    private function scanFixture(int $lookupCalls, array $extraListing = []): array
    {
        $signatureA = ['size' => 10, 'modified_at' => '2026-07-01T00:00:00Z'];
        $signatureB = ['size' => 20, 'modified_at' => '2026-07-02T00:00:00Z'];
        $document = InstalledMetadataDocument::empty()
            ->withInstalledMods([[
                'source' => ProjectSourceKey::Modrinth->value,
                'project_id' => 'sodium',
                'project_slug' => 'sodium',
                'project_title' => 'Sodium',
                'version_id' => 'v1',
                'version_number' => '1.0.0',
                'filename' => 'sodium.jar',
                'installed_at' => '2026-07-01T00:00:00+00:00',
                'file_signature' => $signatureA,
                'hashes' => ['murmur2' => '1', 'sha512' => 'a512', 'sha256' => 'a256'],
            ]])
            ->withUnresolvedFiles([[
                'filename' => 'mystery.jar',
                'file_signature' => $signatureB,
                'hashes' => ['murmur2' => '2', 'sha512' => 'b512', 'sha256' => 'b256'],
                'last_checked_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
            ]]);
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $files->shouldReceive('setServer')->andReturnSelf();
        $files->shouldReceive('getDirectory')->with('mods')->andReturn([
            ['name' => 'sodium.jar', 'size' => 10, 'modified' => '2026-07-01T00:00:00Z', 'file' => true],
            ['name' => 'mystery.jar', 'size' => 20, 'modified' => '2026-07-02T00:00:00Z', 'file' => true],
            ...$extraListing,
        ]);
        $written = null;
        $metadata = Mockery::mock(InstalledMetadataRepository::class);
        $metadata->shouldReceive('read')->andReturn(new InstalledMetadataReadResult($document, InstalledMetadataReadStatus::Current));
        $metadata->shouldReceive('mutate')->andReturnUsing(
            function (Server $server, DaemonFileRepository $files, string $folder, Closure $callback) use ($document, &$written): bool {
                $written = $callback($document);

                return true;
            },
        );
        $source = Mockery::mock(ProjectSourceInterface::class);
        $source->shouldReceive('isConfigured')->andReturnTrue();
        $source->shouldReceive('supportsHashLookup')->andReturnTrue();
        $source->shouldReceive('getHashAlgorithm')->andReturn('sha512');
        $source->shouldReceive('getKey')->andReturn(ProjectSourceKey::Modrinth);
        $source->shouldReceive('findVersionsByHash')->times($lookupCalls)->andReturn([]);
        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('availableFor')->andReturn([$source]);

        return [
            'service' => new RevalidationTestService($registry, $metadata),
            'server' => $server,
            'files' => $files,
            'document' => $document,
            'written' => function () use (&$written): InstalledMetadataDocument {
                self::assertInstanceOf(InstalledMetadataDocument::class, $written);

                return $written;
            },
        ];
    }

    /** @param array<int, InstalledScanResult> $results */
    private function scriptedService(array $results): ScriptedScanService
    {
        return new ScriptedScanService(
            Mockery::mock(ProjectSourceRegistry::class),
            Mockery::mock(InstalledMetadataRepository::class),
            $results,
        );
    }

    private function server(): Server
    {
        $server = new Server();
        $server->forceFill(['id' => 42]);

        return $server;
    }
}

class RevalidationTestService extends InstalledProjectService
{
    public int $hashedFiles = 0;

    protected function computeDaemonFileHashes(DaemonFileRepository $fileRepository, Server $server, string $path): array
    {
        $this->hashedFiles++;

        return ['murmur2' => '3', 'sha512' => 'c512', 'sha256' => 'c256'];
    }
}

class ScriptedScanService extends InstalledProjectService
{
    public int $scanExecutions = 0;

    /** @param array<int, InstalledScanResult> $results */
    public function __construct(
        ProjectSourceRegistry $sourceRegistry,
        InstalledMetadataRepository $metadataRepository,
        private array $results,
    ) {
        parent::__construct($sourceRegistry, $metadataRepository);
    }

    protected function performScan(
        Server $server,
        DaemonFileRepository $fileRepository,
        ?ProjectType $type = null,
        bool $force = false,
    ): InstalledScanResult {
        $this->scanExecutions++;

        return array_shift($this->results) ?? InstalledScanResult::failed('no_scripted_result');
    }
}
