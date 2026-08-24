<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use InvalidArgumentException;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Repositories\InstalledMetadataRepository;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Mockery;
use PHPUnit\Framework\TestCase;

class IncrementalInstalledScanTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_hashes_are_reused_only_when_size_modified_time_and_all_algorithms_match(): void
    {
        $service = new TestableInstalledProjectService();
        $signature = ['size' => 123, 'modified_at' => '2026-07-30T00:00:00Z'];
        $hashes = ['murmur2' => '1', 'sha512' => 'two', 'sha256' => 'three'];
        $entry = ['file_signature' => $signature, 'hashes' => $hashes];

        self::assertSame($hashes, $service->exposeReusableHashes($entry, $signature));
        self::assertNull($service->exposeReusableHashes($entry, ['size' => 124, 'modified_at' => $signature['modified_at']]));
        self::assertNull($service->exposeReusableHashes([
            'file_signature' => $signature,
            'hashes' => ['sha512' => 'two', 'sha256' => 'three'],
        ], $signature));
    }

    public function test_datapack_world_name_must_be_a_single_safe_path_segment(): void
    {
        $service = new TestableInstalledProjectService();

        self::assertSame('custom-world', $service->exposeDatapackWorldName('custom-world'));

        foreach (['.', '..', '../outside', '/outside', 'outside/', 'nested/world', '\\outside', "world\0"] as $invalid) {
            try {
                $service->exposeDatapackWorldName($invalid);
                self::fail("Expected invalid world name: {$invalid}");
            } catch (\Exception $exception) {
                self::assertSame('Invalid datapack world name.', $exception->getMessage());
            }
        }
    }

    public function test_metadata_delete_failure_is_propagated_before_cache_invalidation(): void
    {
        $service = new InstalledProjectService(
            Mockery::mock(ProjectSourceRegistry::class),
            $metadata = Mockery::mock(InstalledMetadataRepository::class),
        );
        $server = new Server();
        $server->forceFill(['id' => 42]);
        $files = Mockery::mock(DaemonFileRepository::class);
        $metadata->shouldReceive('delete')
            ->once()
            ->with($server, $files, 'mods')
            ->andReturnFalse();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to delete installed metadata.');

        $service->clearInstalledModsMetadata($server, $files, ProjectType::Mod);
    }

    public function test_resource_pack_is_rejected_by_the_archive_scan_cache_boundary(): void
    {
        $service = new InstalledProjectService(
            Mockery::mock(ProjectSourceRegistry::class),
            Mockery::mock(InstalledMetadataRepository::class),
        );
        $server = new Server();
        $server->forceFill(['id' => 42]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Resource packs use dedicated URL and SHA-1 metadata');

        $service->getHashScanCacheKey($server, ProjectType::ResourcePack);
    }

    public function test_resource_pack_metadata_never_reads_the_installed_archive_document(): void
    {
        $metadata = Mockery::mock(InstalledMetadataRepository::class);
        $metadata->shouldNotReceive('read');
        $service = new InstalledProjectService(
            Mockery::mock(ProjectSourceRegistry::class),
            $metadata,
        );
        $server = new Server();
        $server->forceFill(['id' => 42]);

        $result = $service->getInstalledMetadataReadResult(
            $server,
            Mockery::mock(DaemonFileRepository::class),
            ProjectType::ResourcePack,
        );

        self::assertSame(InstalledMetadataReadStatus::Unavailable, $result->status);
        self::assertSame([], $result->document->installedMods());
    }

    public function test_resource_pack_metadata_clear_never_deletes_the_installed_archive_document(): void
    {
        $metadata = Mockery::mock(InstalledMetadataRepository::class);
        $metadata->shouldNotReceive('delete');
        $service = new InstalledProjectService(
            Mockery::mock(ProjectSourceRegistry::class),
            $metadata,
        );
        $server = new Server();
        $server->forceFill(['id' => 42]);

        $this->expectException(InvalidArgumentException::class);

        $service->clearInstalledModsMetadata(
            $server,
            Mockery::mock(DaemonFileRepository::class),
            ProjectType::ResourcePack,
        );
    }

    public function test_scan_rebase_preserves_concurrent_updates_additions_and_removals(): void
    {
        $service = new TestableInstalledProjectService();
        $originalA = $this->entry('a', 'a.jar', '1');
        $originalB = $this->entry('b', 'b.jar', '1');
        $updatedB = $this->entry('b', 'b.jar', '2');
        $newC = $this->entry('c', 'c.jar', '1');

        $original = $this->document([$originalA, $originalB]);
        $latest = $this->document([$updatedB, $newC]);
        $rebased = $service->exposeRebase($original, $latest, [$originalA, $originalB], []);
        $byProject = [];

        foreach ($rebased->installedMods() as $entry) {
            $byProject[$entry['project_id']] = $entry;
        }

        self::assertArrayNotHasKey('a', $byProject);
        self::assertSame('2', $byProject['b']['version_id']);
        self::assertSame('1', $byProject['c']['version_id']);
    }

    public function test_scan_rebase_applies_concurrent_filename_winners_in_one_batch(): void
    {
        $service = new TestableInstalledProjectService();
        $originalA = $this->entry('a', 'a.jar', '1');
        $originalB = $this->entry('b', 'b.jar', '1');
        $updatedB = $this->entry('b', 'shared.jar', '2');
        $newC = $this->entry('c', 'SHARED.JAR', '1');

        $rebased = $service->exposeRebase(
            $this->document([$originalA, $originalB]),
            $this->document([$originalA, $updatedB, $newC]),
            [$originalA, $originalB],
            [],
        );

        self::assertSame(['a', 'c'], array_column($rebased->installedMods(), 'project_id'));
        self::assertSame(['a.jar', 'SHARED.JAR'], array_column($rebased->installedMods(), 'filename'));
    }

    public function test_hash_lookup_sources_follow_the_registry_project_type_and_enablement_rules(): void
    {
        $server = new Server();
        $server->forceFill(['id' => 42]);
        $curseForge = Mockery::mock(ProjectSourceInterface::class);
        $modrinth = Mockery::mock(ProjectSourceInterface::class);
        $github = Mockery::mock(ProjectSourceInterface::class);
        $curseForge->shouldReceive('supportsHashLookup')->once()->andReturnTrue();
        $modrinth->shouldReceive('supportsHashLookup')->once()->andReturnTrue();
        $github->shouldReceive('supportsHashLookup')->once()->andReturnFalse();
        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('availableFor')
            ->once()
            ->with($server, ProjectType::Datapack)
            ->andReturn([$curseForge, $modrinth, $github]);

        $service = new TestableInstalledProjectService($registry);

        self::assertSame(
            [$curseForge, $modrinth],
            $service->exposeHashLookupSources($server, ProjectType::Datapack),
        );
    }

    public function test_scan_batch_upsert_removes_each_conflict_once_and_keeps_the_last_filename_winner(): void
    {
        $service = new TestableInstalledProjectService();
        $existing = [
            $this->entry('old', 'same.jar', '1'),
            $this->entry('keep', 'keep.jar', '1'),
            $this->entry('case', 'CASE.jar', '1'),
        ];
        $first = $this->entry('first', 'same.jar', '2');
        $last = $this->entry('last', 'SAME.JAR', '3');

        $result = $service->exposeBatchUpsert($existing, [$first, $last]);

        $expected = $service->exposeSingleUpsert($existing, $first);
        $expected = $service->exposeSingleUpsert($expected, $last);

        self::assertSame(['keep.jar', 'CASE.jar', 'SAME.JAR'], array_column($result, 'filename'));
        self::assertSame(['keep', 'case', 'last'], array_column($result, 'project_id'));
        self::assertSame($expected, $result);
    }

    public function test_unchanged_unresolved_files_keep_their_last_checked_timestamp(): void
    {
        $service = new TestableInstalledProjectService();
        $signature = ['size' => 123, 'modified_at' => '2026-07-30T00:00:00Z'];
        $hashes = ['murmur2' => '1', 'sha512' => 'two', 'sha256' => 'three'];
        $existing = [
            'filename' => 'unknown.jar',
            'file_signature' => $signature,
            'hashes' => $hashes,
            'last_checked_at' => '2026-08-01T00:00:00Z',
        ];
        $entry = [
            'filename' => 'unknown.jar',
            'file_signature' => $signature,
            'hashes' => $hashes,
        ];

        self::assertSame('2026-08-01T00:00:00Z', $service->exposeUnresolvedLastCheckedAt($existing, $entry));
        self::assertNotSame(
            '2026-08-01T00:00:00Z',
            $service->exposeUnresolvedLastCheckedAt($existing, [
                ...$entry,
                'file_signature' => ['size' => 124, 'modified_at' => $signature['modified_at']],
            ]),
        );
    }

    public function test_transient_hash_or_lookup_failure_keeps_known_installed_entries(): void
    {
        $service = new TestableInstalledProjectService();
        $known = $this->entry('sodium', 'sodium.jar', '1');
        $resolved = $service->exposeResolveUnmatched(
            remainingFilenames: ['sodium.jar', 'mystery.jar'],
            hashFailures: ['sodium.jar'],
            lookupFailures: [],
            filesToResolve: [
                'sodium.jar' => ['file_signature' => ['size' => 10, 'modified_at' => 't']],
                'mystery.jar' => ['file_signature' => ['size' => 11, 'modified_at' => 't']],
            ],
            hashesByFilename: [],
            installedByFilename: ['sodium.jar' => $known],
            unresolvedByFilename: [],
        );

        self::assertSame(['sodium'], array_column($resolved['installed'], 'project_id'));
        self::assertSame(['mystery.jar'], array_column($resolved['unresolved'], 'filename'));
    }

    public function test_authoritative_miss_without_lookup_failure_demotes_known_entry(): void
    {
        $service = new TestableInstalledProjectService();
        $known = $this->entry('sodium', 'sodium.jar', '1');
        $resolved = $service->exposeResolveUnmatched(
            remainingFilenames: ['sodium.jar'],
            hashFailures: [],
            lookupFailures: [],
            filesToResolve: [
                'sodium.jar' => ['file_signature' => ['size' => 10, 'modified_at' => 't']],
            ],
            hashesByFilename: [
                'sodium.jar' => ['murmur2' => '1', 'sha512' => '2', 'sha256' => '3'],
            ],
            installedByFilename: ['sodium.jar' => $known],
            unresolvedByFilename: [],
        );

        self::assertSame([], $resolved['installed']);
        self::assertSame(['sodium.jar'], array_column($resolved['unresolved'], 'filename'));
    }

    public function test_source_lookup_exception_keeps_known_entry_instead_of_unresolved(): void
    {
        $service = new TestableInstalledProjectService();
        $known = $this->entry('sodium', 'sodium.jar', '1');
        $resolved = $service->exposeResolveUnmatched(
            remainingFilenames: ['sodium.jar'],
            hashFailures: [],
            lookupFailures: ['modrinth'],
            filesToResolve: [
                'sodium.jar' => ['file_signature' => ['size' => 10, 'modified_at' => 't']],
            ],
            hashesByFilename: [
                'sodium.jar' => ['murmur2' => '1', 'sha512' => '2', 'sha256' => '3'],
            ],
            installedByFilename: ['sodium.jar' => $known],
            unresolvedByFilename: [],
        );

        self::assertSame(['sodium'], array_column($resolved['installed'], 'project_id'));
        self::assertSame([], $resolved['unresolved']);
    }

    /** @param array<int, array<string, mixed>> $entries */
    private function document(array $entries): InstalledMetadataDocument
    {
        $document = InstalledMetadataDocument::fromArray(['schema_version' => 2, 'installed_mods' => $entries]);
        self::assertNotNull($document);

        return $document;
    }

    /** @return array<string, mixed> */
    private function entry(string $projectId, string $filename, string $versionId): array
    {
        return [
            'source' => 'modrinth',
            'project_id' => $projectId,
            'project_slug' => $projectId,
            'project_title' => strtoupper($projectId),
            'version_id' => $versionId,
            'version_number' => $versionId,
            'filename' => $filename,
            'installed_at' => '2026-07-30T00:00:00Z',
        ];
    }
}

class TestableInstalledProjectService extends InstalledProjectService
{
    /** @var array<string, string> */
    private array $testServerProperties = [];

    public function __construct(?ProjectSourceRegistry $sourceRegistry = null)
    {
        if ($sourceRegistry !== null) {
            parent::__construct($sourceRegistry, Mockery::mock(InstalledMetadataRepository::class));
        }
    }

    /** @param array<string, mixed>|null $signature */
    public function exposeReusableHashes(array $entry, ?array $signature): ?array
    {
        return $this->reusableHashes($entry, $signature);
    }

    public function exposeDatapackWorldName(string $worldName): string
    {
        $this->testServerProperties = ['level-name' => $worldName];
        $this->serverPropertiesCache = [];
        $server = new Server();
        $server->forceFill(['id' => 42]);

        return $this->getDatapackWorldName($server, Mockery::mock(DaemonFileRepository::class));
    }

    /** @return array<string, string> */
    protected function getServerProperties(Server $server, DaemonFileRepository $fileRepository): ?array
    {
        return $this->testServerProperties;
    }

    /**
     * @param array<int, array<string, mixed>> $installed
     * @param array<int, array<string, mixed>> $unresolved
     */
    public function exposeRebase(InstalledMetadataDocument $original, InstalledMetadataDocument $latest, array $installed, array $unresolved): InstalledMetadataDocument
    {
        return $this->rebaseScanDocument($original, $latest, $installed, $unresolved);
    }

    /** @return array<int, ProjectSourceInterface> */
    public function exposeHashLookupSources(Server $server, ProjectType $type): array
    {
        return $this->getHashLookupSourcesInPriorityOrder($server, $type);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<int, array<string, mixed>> $incoming
     * @return array<int, array<string, mixed>>
     */
    public function exposeBatchUpsert(array $entries, array $incoming): array
    {
        return $this->upsertInstalledEntries($entries, $incoming);
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, mixed> $incoming
     * @return array<int, array<string, mixed>>
     */
    public function exposeSingleUpsert(array $entries, array $incoming): array
    {
        return $this->upsertInstalledEntry($entries, $incoming);
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $entry
     */
    public function exposeUnresolvedLastCheckedAt(?array $existing, array $entry): string
    {
        return $this->unresolvedLastCheckedAt($existing, $entry);
    }

    /**
     * @param  array<int, string>  $remainingFilenames
     * @param  array<int, string>  $hashFailures
     * @param  array<int, string>  $lookupFailures
     * @param  array<string, array<string, mixed>>  $filesToResolve
     * @param  array<string, array<string, string>>  $hashesByFilename
     * @param  array<string, array<string, mixed>>  $installedByFilename
     * @param  array<string, array<string, mixed>>  $unresolvedByFilename
     * @return array{installed: array<int, array<string, mixed>>, unresolved: array<int, array<string, mixed>>}
     */
    public function exposeResolveUnmatched(
        array $remainingFilenames,
        array $hashFailures,
        array $lookupFailures,
        array $filesToResolve,
        array $hashesByFilename,
        array $installedByFilename,
        array $unresolvedByFilename,
    ): array {
        return $this->resolveUnmatchedScanFiles(
            $remainingFilenames,
            $hashFailures,
            $lookupFailures,
            $filesToResolve,
            $hashesByFilename,
            $installedByFilename,
            $unresolvedByFilename,
        );
    }
}
