<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledArchiveTransaction;
use Kazaminosuke\ModManager\Services\InstalledProjectMutationService;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Mockery;
use PHPUnit\Framework\TestCase;

class InstalledProjectMutationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_install_acquires_and_releases_the_operation_lease(): void
    {
        $archives = Mockery::mock(InstalledArchiveTransaction::class);
        $archives->shouldReceive('installOrUpdate')->once();
        $leases = new InstalledOperationLease(new Repository(new ArrayStore()));
        $service = new InstalledProjectMutationService($archives, $leases);
        $server = new Server();
        $server->forceFill(['id' => 9]);

        $filename = $service->installOrUpdate(
            $server,
            Mockery::mock(DaemonFileRepository::class),
            ProjectType::Mod,
            [
                'source' => ProjectSourceKey::Modrinth->value,
                'project_id' => 'sodium',
                'slug' => 'sodium',
                'title' => 'Sodium',
            ],
            [
                'id' => 'v2',
                'version_number' => '2.0.0',
                'files' => [[
                    'primary' => true,
                    'filename' => 'sodium.jar',
                    'url' => 'https://example.test/sodium.jar',
                ]],
            ],
        );

        self::assertSame('sodium.jar', $filename);
        self::assertFalse($leases->isHeld(9, ProjectType::Mod));
    }

    public function test_install_is_rejected_when_another_operation_holds_the_lease(): void
    {
        $archives = Mockery::mock(InstalledArchiveTransaction::class);
        $archives->shouldNotReceive('installOrUpdate');
        $leases = new InstalledOperationLease(new Repository(new ArrayStore()));
        $leases->tryAcquire(9, ProjectType::Mod, InstalledOperationLease::OPERATION_SCAN);
        $service = new InstalledProjectMutationService($archives, $leases);
        $server = new Server();
        $server->forceFill(['id' => 9]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('A managed file operation is already running.');

        $service->installOrUpdate(
            $server,
            Mockery::mock(DaemonFileRepository::class),
            ProjectType::Mod,
            [
                'source' => ProjectSourceKey::Modrinth->value,
                'project_id' => 'sodium',
                'slug' => 'sodium',
                'title' => 'Sodium',
            ],
            [
                'id' => 'v2',
                'version_number' => '2.0.0',
                'files' => [[
                    'primary' => true,
                    'filename' => 'sodium.jar',
                    'url' => 'https://example.test/sodium.jar',
                ]],
            ],
        );
    }

    public function test_resource_pack_cannot_enter_the_installed_archive_transaction(): void
    {
        $archives = Mockery::mock(InstalledArchiveTransaction::class);
        $archives->shouldNotReceive('installOrUpdate');
        $leases = new InstalledOperationLease(new Repository(new ArrayStore()));
        $service = new InstalledProjectMutationService($archives, $leases);
        $server = new Server();
        $server->forceFill(['id' => 9]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Resource packs must use the dedicated direct URL and SHA-1 transaction.');

        $service->installOrUpdate(
            $server,
            Mockery::mock(DaemonFileRepository::class),
            ProjectType::ResourcePack,
            [],
            [],
        );
    }
}
