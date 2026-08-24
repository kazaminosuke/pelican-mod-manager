<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Repositories;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\Response;
use Kazaminosuke\ModManager\Repositories\InstalledMetadataRepository;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Mockery;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/src/Support/InstalledMetadataReadStatus.php';
require_once dirname(__DIR__, 3).'/src/Enums/ProjectSourceKey.php';
require_once dirname(__DIR__, 3).'/src/Support/InstalledMetadataDocument.php';
require_once dirname(__DIR__, 3).'/src/Support/InstalledMetadataReadResult.php';
require_once dirname(__DIR__, 3).'/src/Repositories/InstalledMetadataRepository.php';

class InstalledMetadataRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_valid_empty_current_document_is_authoritative_and_reads_no_other_filename(): void
    {
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $files->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $files->shouldReceive('getContent')
            ->once()
            ->with('mods/.pelican-mod-manager.json')
            ->andReturn('{"schema_version":2,"installed_mods":[]}');
        $files->shouldNotReceive('getContent')->with('mods/.modrinth-metadata.json');

        $result = (new InstalledMetadataRepository())->read($server, $files, 'mods');

        self::assertSame(InstalledMetadataReadStatus::Current, $result->status);
        self::assertTrue($result->isAuthoritative());
        self::assertSame([], $result->document->installedMods());
    }

    public function test_missing_current_document_does_not_fall_back_to_legacy_filename(): void
    {
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $files->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $files->shouldReceive('getContent')
            ->once()
            ->with('mods/.pelican-mod-manager.json')
            ->andThrow(new FileNotFoundException());
        $result = (new InstalledMetadataRepository())->read($server, $files, 'mods');

        self::assertSame(InstalledMetadataReadStatus::Missing, $result->status);
        self::assertFalse($result->isAuthoritative());
        self::assertSame([], $result->document->installedMods());
    }

    public function test_invalid_documents_are_not_authoritative(): void
    {
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $files->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $files->shouldReceive('getContent')->once()->andReturn('not-json');

        $result = (new InstalledMetadataRepository())->read($server, $files, 'mods');

        self::assertSame(InstalledMetadataReadStatus::Invalid, $result->status);
        self::assertFalse($result->isAuthoritative());
    }

    public function test_only_schema_v2_documents_are_accepted_and_missing_sources_are_not_defaulted(): void
    {
        $entry = $this->currentEntry();

        self::assertNull(InstalledMetadataDocument::fromArray([
            'schema_version' => 1,
            'installed_mods' => [$entry],
        ]));

        unset($entry['source']);
        $document = InstalledMetadataDocument::fromArray([
            'schema_version' => 2,
            'installed_mods' => [$entry],
        ]);

        self::assertNotNull($document);
        self::assertSame([], $document->installedMods());
    }

    public function test_v2_document_round_trip_preserves_signatures_hashes_and_unresolved_files(): void
    {
        $entry = $this->currentEntry();
        $entry['file_signature'] = ['size' => 1234, 'modified_at' => '2026-07-30T00:00:00Z'];
        $entry['hashes'] = ['murmur2' => '42', 'sha512' => 'sha512', 'sha256' => 'sha256'];

        $document = InstalledMetadataDocument::fromArray([
            'schema_version' => 2,
            'installed_mods' => [$entry],
            'unresolved_files' => [[
                'filename' => 'unknown.jar',
                'file_signature' => ['size' => 5678, 'modified_at' => '2026-07-30T01:00:00Z'],
                'hashes' => ['murmur2' => '84', 'sha512' => 'other512', 'sha256' => 'other256'],
            ]],
        ]);

        self::assertNotNull($document);
        $roundTrip = InstalledMetadataDocument::fromJson(json_encode($document->toArray(), JSON_THROW_ON_ERROR));

        self::assertNotNull($roundTrip);
        self::assertSame(2, $roundTrip->toArray()['schema_version']);
        self::assertSame($entry['file_signature'], $roundTrip->installedMods()[0]['file_signature']);
        self::assertSame('unknown.jar', $roundTrip->unresolvedFiles()[0]['filename']);
    }

    public function test_malformed_installed_entries_are_filtered_before_ui_consumers_read_them(): void
    {
        $valid = $this->currentEntry();
        $invalidTitle = $this->currentEntry('invalid-title.jar');
        $invalidTitle['project_title'] = [];
        $invalidSource = $this->currentEntry('invalid-source.jar');
        $invalidSource['source'] = [];
        $nonStringAuthor = $this->currentEntry('author.jar');
        $nonStringAuthor['author'] = ['unexpected'];

        $document = InstalledMetadataDocument::fromArray([
            'schema_version' => 2,
            'installed_mods' => [$valid, $invalidTitle, $invalidSource, $nonStringAuthor],
        ]);

        self::assertNotNull($document);
        self::assertCount(2, $document->installedMods());
        self::assertSame('Project', $document->installedMods()[0]['project_title']);
        self::assertArrayNotHasKey('author', $document->installedMods()[1]);
    }

    public function test_mutate_skips_wings_write_and_hydration_bump_when_current_document_is_unchanged(): void
    {
        $server = $this->server();
        $document = InstalledMetadataDocument::fromArray(['schema_version' => 2, 'installed_mods' => [$this->currentEntry()]]);
        self::assertNotNull($document);
        $files = Mockery::mock(DaemonFileRepository::class);
        $files->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $files->shouldReceive('getContent')
            ->once()
            ->with('mods/.pelican-mod-manager.json')
            ->andReturn(json_encode($document->toArray(), JSON_THROW_ON_ERROR));
        $files->shouldNotReceive('putContent');
        $repository = new SynchronousInstalledMetadataRepository();

        self::assertTrue($repository->mutate($server, $files, 'mods', static fn (): InstalledMetadataDocument => $document));
        self::assertSame(0, $repository->hydrationBumps);
        self::assertSame(1, $repository->lockCalls);
    }

    public function test_mutate_writes_current_document_when_no_metadata_exists(): void
    {
        $server = $this->server();
        $document = InstalledMetadataDocument::empty();
        $files = Mockery::mock(DaemonFileRepository::class);
        $files->shouldReceive('setServer')->twice()->with($server)->andReturnSelf();
        $files->shouldReceive('getContent')
            ->once()
            ->with('mods/.pelican-mod-manager.json')
            ->andThrow(new FileNotFoundException());
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('failed')->once()->andReturnFalse();
        $files->shouldReceive('putContent')
            ->once()
            ->with('mods/.pelican-mod-manager.json', Mockery::type('string'))
            ->andReturn($response);
        $repository = new SynchronousInstalledMetadataRepository();

        self::assertTrue($repository->mutate($server, $files, 'mods', static fn (): InstalledMetadataDocument => $document));
        self::assertSame(1, $repository->hydrationBumps);
        self::assertSame(1, $repository->lockCalls);
    }

    public function test_bulk_replace_writes_once_and_bumps_hydration_once(): void
    {
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('failed')->once()->andReturnFalse();
        $files->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $files->shouldReceive('putContent')
            ->once()
            ->with('mods/.pelican-mod-manager.json', Mockery::on(function (string $content): bool {
                $decoded = json_decode($content, true);

                return $decoded['schema_version'] === 2 && count($decoded['installed_mods']) === 2;
            }))
            ->andReturn($response);

        $repository = new SynchronousInstalledMetadataRepository();
        $document = InstalledMetadataDocument::fromArray([
            'schema_version' => 2,
            'installed_mods' => [$this->currentEntry('one.jar'), $this->currentEntry('two.jar')],
        ]);

        self::assertNotNull($document);
        self::assertTrue($repository->replace($server, $files, 'mods', $document));
        self::assertSame(1, $repository->hydrationBumps);
        self::assertSame(1, $repository->lockCalls);
    }

    public function test_failed_bulk_replace_does_not_bump_hydration(): void
    {
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('failed')->once()->andReturnTrue();
        $files->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $files->shouldReceive('putContent')->once()->andReturn($response);

        $repository = new SynchronousInstalledMetadataRepository();

        self::assertFalse($repository->replace($server, $files, 'mods', InstalledMetadataDocument::empty()));
        self::assertSame(0, $repository->hydrationBumps);
    }

    public function test_delete_runs_under_the_document_lock_and_bumps_hydration_after_success(): void
    {
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('failed')->once()->andReturnFalse();
        $files->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $files->shouldReceive('deleteFiles')
            ->once()
            ->with('mods', ['.pelican-mod-manager.json'])
            ->andReturn($response);
        $repository = new SynchronousInstalledMetadataRepository();

        self::assertTrue($repository->delete($server, $files, 'mods'));
        self::assertSame(1, $repository->lockCalls);
        self::assertSame(1, $repository->hydrationBumps);
    }

    public function test_failed_delete_does_not_bump_hydration(): void
    {
        $server = $this->server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('failed')->once()->andReturnTrue();
        $files->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $files->shouldReceive('deleteFiles')->once()->andReturn($response);
        $repository = new SynchronousInstalledMetadataRepository();

        self::assertFalse($repository->delete($server, $files, 'mods'));
        self::assertSame(1, $repository->lockCalls);
        self::assertSame(0, $repository->hydrationBumps);
    }

    public function test_metadata_lock_key_is_scoped_to_server_and_folder(): void
    {
        $mods = InstalledMetadataRepository::lockKey(42, 'mods');
        $plugins = InstalledMetadataRepository::lockKey(42, 'plugins');
        $normalized = InstalledMetadataRepository::lockKey(42, '/Mods/');

        self::assertSame($mods, $normalized);
        self::assertNotSame($mods, $plugins);
        self::assertStringStartsWith('mod_manager_metadata:v1:42:', $mods);
        self::assertGreaterThan(15 * 2, InstalledMetadataRepository::LOCK_SECONDS);
        self::assertSame(60, InstalledMetadataRepository::LOCK_SECONDS);
        self::assertSame(20, InstalledMetadataRepository::LOCK_WAIT_SECONDS);
    }

    protected function server(): Server
    {
        $server = new Server();
        $server->forceFill(['id' => 42]);

        return $server;
    }

    /** @return array<string, mixed> */
    protected function currentEntry(string $filename = 'example.jar'): array
    {
        return [
            'source' => 'modrinth',
            'project_id' => 'project',
            'project_slug' => 'project',
            'project_title' => 'Project',
            'version_id' => 'version',
            'version_number' => '1.0.0',
            'filename' => $filename,
            'installed_at' => '2026-07-30T00:00:00Z',
        ];
    }
}

class SynchronousInstalledMetadataRepository extends InstalledMetadataRepository
{
    public int $hydrationBumps = 0;

    public int $lockCalls = 0;

    protected function withinLock(Server $server, string $folder, \Closure $callback): mixed
    {
        $this->lockCalls++;

        return $callback();
    }

    protected function bumpHydration(Server $server): void
    {
        $this->hydrationBumps++;
    }
}
