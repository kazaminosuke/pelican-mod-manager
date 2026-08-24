<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledArchiveTransaction;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Services\InstalledProjectUpdateService;
use Kazaminosuke\ModManager\Services\VersionLookupCoordinator;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadResult;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Kazaminosuke\ModManager\Support\InstalledMetadataWriteSession;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Mockery;
use PHPUnit\Framework\TestCase;

class InstalledProjectUpdateServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_bulk_update_commits_metadata_from_memory_without_rereading(): void
    {
        $installed = [
            'source' => ProjectSourceKey::Modrinth->value,
            'project_id' => 'sodium',
            'project_slug' => 'sodium',
            'project_title' => 'Sodium',
            'version_id' => 'v1',
            'version_number' => '1.0.0',
            'filename' => 'sodium.jar',
            'installed_at' => '2026-07-30T00:00:00Z',
        ];
        $document = InstalledMetadataDocument::empty()->withInstalledMods([$installed]);
        $projects = Mockery::mock(InstalledProjectService::class);
        $projects->shouldReceive('getInstalledMetadataReadResult')->once()->andReturn(
            new InstalledMetadataReadResult($document, InstalledMetadataReadStatus::Current),
        );
        $projects->shouldReceive('saveModMetadata')->never();
        $projects->shouldReceive('saveInstalledMetadataDocument')
            ->once()
            ->andReturnTrue();
        $projects->shouldReceive('getHashScanCacheKey')->once()->andReturn('scan-key');

        $archives = Mockery::mock(InstalledArchiveTransaction::class);
        $archives->shouldReceive('installOrUpdate')
            ->once()
            ->andReturnUsing(function () {
                $session = func_get_arg(7);
                self::assertInstanceOf(InstalledMetadataWriteSession::class, $session);

                return $session->upsert([
                    'source' => ProjectSourceKey::Modrinth->value,
                    'project_id' => 'sodium',
                    'project_slug' => 'sodium',
                    'project_title' => 'Sodium',
                    'version_id' => 'v2',
                    'version_number' => '2.0.0',
                    'filename' => 'sodium.jar',
                    'installed_at' => '2026-08-24T00:00:00Z',
                ]);
            });

        $versions = Mockery::mock(VersionLookupCoordinator::class);
        $versions->shouldReceive('lookupInstalled')->once()->andReturn(new LatestVersionLookupResult(
            versionsByKey: [
                'modrinth:sodium' => [
                    'id' => 'v2',
                    'version_number' => '2.0.0',
                    'files' => [[
                        'primary' => true,
                        'filename' => 'sodium.jar',
                        'url' => 'https://example.test/sodium.jar',
                    ]],
                ],
            ],
        ));

        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('forget')->once()->with('scan-key');

        $server = new Server();
        $server->forceFill(['id' => 4]);
        $result = (new InstalledProjectUpdateService($projects, $archives, $versions, $cache))
            ->updateAll($server, Mockery::mock(DaemonFileRepository::class), ProjectType::Mod);

        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['failed']);
        self::assertSame(0, $result['skipped']);
    }
}
