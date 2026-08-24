<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledArchiveTransaction;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadResult;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Mockery;
use PHPUnit\Framework\TestCase;

class InstalledArchiveTransactionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_install_pulls_in_foreground_then_renames_before_metadata_commit(): void
    {
        $wings = new RecordingWingsRemoteFilesystem();
        $projects = $this->projects($wings, saved: true);
        $transaction = new InstalledArchiveTransaction($projects, $wings);

        $transaction->installOrUpdate(
            $this->server(),
            Mockery::mock(DaemonFileRepository::class),
            ProjectType::Mod,
            $this->record(),
            $this->version(),
            $this->primaryFile(),
        );

        self::assertSame(['list', 'foreground_pull', 'list', 'rename', 'metadata'], $wings->events);
        self::assertTrue($wings->pulledInForeground);
        self::assertSame('sodium.jar', $wings->activatedFilename);
        self::assertSame(['sodium.jar'], $wings->committedFilenames);
        self::assertSame([], $wings->deletedFilenames);
    }

    public function test_update_deletes_old_archive_only_after_metadata_commit(): void
    {
        $wings = new RecordingWingsRemoteFilesystem();
        $wings->existingFilenames = ['sodium-old.jar'];
        $projects = $this->projects($wings, saved: true);
        $transaction = new InstalledArchiveTransaction($projects, $wings);

        $transaction->installOrUpdate(
            $this->server(),
            Mockery::mock(DaemonFileRepository::class),
            ProjectType::Mod,
            $this->record(),
            $this->version(),
            $this->primaryFile(),
            $this->installed('sodium-old.jar'),
        );

        self::assertSame(
            ['list', 'foreground_pull', 'list', 'rename', 'metadata', 'delete'],
            $wings->events,
        );
        self::assertSame(['sodium-old.jar'], $wings->deletedFilenames);
        self::assertSame(['sodium.jar'], $wings->committedFilenames);
    }

    public function test_same_name_update_swaps_via_backup_before_metadata_commit(): void
    {
        $wings = new RecordingWingsRemoteFilesystem();
        $wings->existingFilenames = ['sodium.jar'];
        $projects = $this->projects($wings, saved: true, installedMods: [$this->installed('sodium.jar')]);
        $transaction = new InstalledArchiveTransaction($projects, $wings);

        $transaction->installOrUpdate(
            $this->server(),
            Mockery::mock(DaemonFileRepository::class),
            ProjectType::Mod,
            $this->record(),
            $this->version(),
            $this->primaryFile(),
            $this->installed('sodium.jar'),
        );

        self::assertSame(
            ['list', 'foreground_pull', 'list', 'rename', 'rename', 'metadata'],
            $wings->events,
        );
        self::assertCount(2, $wings->renames);
        self::assertSame('sodium.jar', $wings->renames[0]['from']);
        self::assertStringStartsWith('.mod-manager-prev-', $wings->renames[0]['to']);
        self::assertStringStartsWith('.mod-manager-pull-', $wings->renames[1]['from']);
        self::assertSame('sodium.jar', $wings->renames[1]['to']);
        self::assertSame([$wings->renames[0]['to']], $wings->quietlyDeletedFilenames);
        self::assertSame([], $wings->deletedFilenames);
    }

    public function test_background_pull_does_not_commit_metadata_or_delete_old_archive(): void
    {
        $wings = new RecordingWingsRemoteFilesystem();
        $wings->backgroundPull = true;
        $projects = $this->projects($wings, saved: true);
        $transaction = new InstalledArchiveTransaction($projects, $wings);

        try {
            $transaction->installOrUpdate(
                $this->server(),
                Mockery::mock(DaemonFileRepository::class),
                ProjectType::Mod,
                $this->record(),
                $this->version(),
                $this->primaryFile(),
                $this->installed('sodium-old.jar'),
            );
            self::fail('Background pull must fail the transaction.');
        } catch (Exception $exception) {
            self::assertStringContainsString('background pull', $exception->getMessage());
        }

        self::assertSame([], $wings->committedFilenames);
        self::assertSame([], $wings->deletedFilenames);
        self::assertNotEmpty($wings->quietlyDeletedFilenames);
    }

    public function test_empty_pull_does_not_commit_metadata(): void
    {
        $wings = new RecordingWingsRemoteFilesystem();
        $wings->pulledSize = 0;
        $projects = $this->projects($wings, saved: true);
        $transaction = new InstalledArchiveTransaction($projects, $wings);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Pulled archive is empty.');

        try {
            $transaction->installOrUpdate(
                $this->server(),
                Mockery::mock(DaemonFileRepository::class),
                ProjectType::Mod,
                $this->record(),
                $this->version(),
                $this->primaryFile(),
            );
        } finally {
            self::assertSame([], $wings->committedFilenames);
        }
    }

    public function test_metadata_failure_rolls_back_activated_archive(): void
    {
        $wings = new RecordingWingsRemoteFilesystem();
        $projects = $this->projects($wings, saved: false);
        $transaction = new InstalledArchiveTransaction($projects, $wings);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to save mod metadata');

        try {
            $transaction->installOrUpdate(
                $this->server(),
                Mockery::mock(DaemonFileRepository::class),
                ProjectType::Mod,
                $this->record(),
                $this->version(),
                $this->primaryFile(),
            );
        } finally {
            self::assertContains('sodium.jar', $wings->quietlyDeletedFilenames);
            self::assertSame([], $wings->deletedFilenames);
        }
    }

    public function test_old_archive_delete_failure_restores_previous_metadata(): void
    {
        $wings = new RecordingWingsRemoteFilesystem();
        $wings->existingFilenames = ['sodium-old.jar'];
        $wings->failDeletes = ['sodium-old.jar'];
        $projects = $this->projects($wings, saved: true);
        $transaction = new InstalledArchiveTransaction($projects, $wings);

        try {
            $transaction->installOrUpdate(
                $this->server(),
                Mockery::mock(DaemonFileRepository::class),
                ProjectType::Mod,
                $this->record(),
                $this->version(),
                $this->primaryFile(),
                $this->installed('sodium-old.jar'),
            );
            self::fail('Old archive delete failure must fail the transaction.');
        } catch (Exception $exception) {
            self::assertSame('cannot delete old archive', $exception->getMessage());
        }

        self::assertSame(['sodium.jar', 'sodium-old.jar'], $wings->committedFilenames);
        self::assertContains('sodium.jar', $wings->quietlyDeletedFilenames);
    }

    public function test_other_project_filename_collision_is_rejected_before_pull(): void
    {
        $wings = new RecordingWingsRemoteFilesystem();
        $other = $this->installed('sodium.jar');
        $other['project_id'] = 'iris';
        $other['project_slug'] = 'iris';
        $other['project_title'] = 'Iris';
        $projects = $this->projects($wings, saved: true, installedMods: [$other]);
        $transaction = new InstalledArchiveTransaction($projects, $wings);

        try {
            $transaction->installOrUpdate(
                $this->server(),
                Mockery::mock(DaemonFileRepository::class),
                ProjectType::Mod,
                $this->record(),
                $this->version(),
                $this->primaryFile(),
            );
            self::fail('Filename collision must reject the transaction.');
        } catch (Exception $exception) {
            self::assertStringContainsString('already used by another installed project', $exception->getMessage());
        }

        self::assertSame([], $wings->events);
        self::assertSame([], $wings->committedFilenames);
    }

    public function test_orphan_destination_file_is_rejected_before_pull(): void
    {
        $wings = new RecordingWingsRemoteFilesystem();
        $wings->existingFilenames = ['sodium.jar'];
        $projects = $this->projects($wings, saved: true);
        $transaction = new InstalledArchiveTransaction($projects, $wings);

        try {
            $transaction->installOrUpdate(
                $this->server(),
                Mockery::mock(DaemonFileRepository::class),
                ProjectType::Mod,
                $this->record(),
                $this->version(),
                $this->primaryFile(),
            );
            self::fail('Orphan destination must reject the transaction.');
        } catch (Exception $exception) {
            self::assertStringContainsString('already exists on disk', $exception->getMessage());
        }

        self::assertSame(['list'], $wings->events);
        self::assertSame([], $wings->committedFilenames);
    }

    /** @return Mockery\MockInterface&InstalledProjectService */
    private function projects(
        RecordingWingsRemoteFilesystem $wings,
        bool $saved,
        array $installedMods = [],
    ): InstalledProjectService
    {
        $document = InstalledMetadataDocument::empty();
        $status = InstalledMetadataReadStatus::Missing;

        if ($installedMods !== []) {
            $document = InstalledMetadataDocument::fromArray([
                'schema_version' => 2,
                'installed_mods' => $installedMods,
            ]) ?? InstalledMetadataDocument::empty();
            $status = InstalledMetadataReadStatus::Current;
        }

        $projects = Mockery::mock(InstalledProjectService::class);
        $projects->shouldReceive('getProjectFolder')->andReturn('mods');
        $projects->shouldReceive('getInstalledMetadataReadResult')->andReturn(
            new InstalledMetadataReadResult($document, $status),
        );
        $projects->shouldReceive('saveModMetadata')
            ->andReturnUsing(function () use ($wings, $saved): bool {
                $wings->events[] = 'metadata';
                $wings->committedFilenames[] = func_get_arg(7);

                return $saved;
            });

        return $projects;
    }

    private function server(): Server
    {
        $server = new Server();
        $server->forceFill([
            'id' => 7,
            'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

        return $server;
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        return [
            'source' => ProjectSourceKey::Modrinth->value,
            'project_id' => 'sodium',
            'slug' => 'sodium',
            'title' => 'Sodium',
            'author' => 'jelly',
        ];
    }

    /** @return array<string, mixed> */
    private function version(): array
    {
        return [
            'id' => 'v2',
            'version_number' => '2.0.0',
        ];
    }

    /** @return array<string, mixed> */
    private function primaryFile(): array
    {
        return [
            'url' => 'https://example.test/sodium.jar',
            'filename' => 'sodium.jar',
            'size' => 2048,
        ];
    }

    /** @return array<string, mixed> */
    private function installed(string $filename): array
    {
        return [
            'source' => ProjectSourceKey::Modrinth->value,
            'project_id' => 'sodium',
            'project_slug' => 'sodium',
            'project_title' => 'Sodium',
            'version_id' => 'v1',
            'version_number' => '1.0.0',
            'filename' => $filename,
            'author' => 'jelly',
            'installed_at' => '2026-07-30T00:00:00Z',
        ];
    }
}

class RecordingWingsRemoteFilesystem extends WingsRemoteFilesystem
{
    /** @var array<int, string> */
    public array $events = [];

    /** @var array<int, string> */
    public array $existingFilenames = [];

    /** @var array<int, array{from: string, to: string}> */
    public array $renames = [];

    /** @var array<int, string> */
    public array $deletedFilenames = [];

    /** @var array<int, string> */
    public array $quietlyDeletedFilenames = [];

    /** @var array<int, string> */
    public array $committedFilenames = [];

    public bool $pulledInForeground = false;

    public bool $backgroundPull = false;

    public int $pulledSize = 2048;

    public ?string $activatedFilename = null;

    /** @var array<int, string> */
    public array $failDeletes = [];

    /** @var array<string, array{name: string, size: int}> */
    private array $listed = [];

    public function pullForeground(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $url,
        string $directory,
        string $filename,
    ): array {
        $this->events[] = 'foreground_pull';
        $this->pulledInForeground = true;

        if ($this->backgroundPull) {
            throw new Exception('Wings accepted a background pull; foreground completion is required.');
        }

        $this->listed[$filename] = ['name' => $filename, 'size' => $this->pulledSize];

        return ['name' => $filename, 'size' => $this->pulledSize];
    }

    public function rename(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $from,
        string $to,
    ): void {
        $this->events[] = 'rename';
        $this->renames[] = ['from' => $from, 'to' => $to];

        if (!isset($this->listed[$from]) && !in_array($from, $this->existingFilenames, true)) {
            throw new Exception("missing source [{$from}]");
        }

        $size = $this->listed[$from]['size'] ?? 2048;
        unset($this->listed[$from]);
        $this->existingFilenames = array_values(array_filter(
            $this->existingFilenames,
            fn (string $name): bool => $name !== $from,
        ));
        $this->listed[$to] = ['name' => $to, 'size' => $size];
        $this->activatedFilename = $to;
    }

    public function delete(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $filename,
    ): void {
        $this->events[] = 'delete';

        if (in_array($filename, $this->failDeletes, true)) {
            throw new Exception('cannot delete old archive');
        }

        $this->deletedFilenames[] = $filename;
        unset($this->listed[$filename]);
    }

    public function deleteQuietly(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $filename,
    ): void {
        $this->quietlyDeletedFilenames[] = $filename;
        unset($this->listed[$filename]);
    }

    public function findListedFile(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $filename,
    ): ?array {
        $this->events[] = 'list';

        if (isset($this->listed[$filename])) {
            return $this->listed[$filename];
        }

        if (in_array($filename, $this->existingFilenames, true)) {
            return ['name' => $filename, 'size' => 1024];
        }

        return null;
    }
}
