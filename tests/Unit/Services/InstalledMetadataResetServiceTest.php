<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledMetadataResetService;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\ProjectOperationAuthorizer;
use Mockery;
use PHPUnit\Framework\TestCase;

class InstalledMetadataResetServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_clear_all_path_authorizes_before_any_destructive_delete(): void
    {
        $cache = new Repository(new ArrayStore());
        $leases = new InstalledOperationLease($cache);
        $projects = Mockery::mock(InstalledProjectService::class);
        $projects->shouldNotReceive('clearInstalledModsMetadata');
        $resets = new InstalledMetadataResetService(
            $projects,
            $leases,
            new ProjectOperationAuthorizer(),
        );
        $server = $this->server();

        $result = $resets->clearWithoutScan(
            $server,
            Mockery::mock(DaemonFileRepository::class),
            [ProjectType::Mod, ProjectType::Datapack],
            null,
        );

        self::assertSame(InstalledMetadataResetService::STATUS_UNAUTHORIZED, $result['status']);
        self::assertSame([], $result['cleared_types']);
        self::assertFalse($leases->isHeld(42, ProjectType::Mod));
        self::assertFalse($leases->isHeld(42, ProjectType::Datapack));
    }

    public function test_clear_all_path_skips_the_whole_server_when_one_type_is_busy(): void
    {
        $cache = new Repository(new ArrayStore());
        $leases = new InstalledOperationLease($cache);
        $existingToken = $leases->tryAcquire(
            42,
            ProjectType::Mod,
            InstalledOperationLease::OPERATION_INSTALL,
        );
        self::assertNotNull($existingToken);
        $projects = Mockery::mock(InstalledProjectService::class);
        $projects->shouldNotReceive('clearInstalledModsMetadata');
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('isRootAdmin')->once()->andReturnTrue();
        $resets = new InstalledMetadataResetService(
            $projects,
            $leases,
            new ProjectOperationAuthorizer(),
        );

        $result = $resets->clearWithoutScan(
            $this->server(),
            Mockery::mock(DaemonFileRepository::class),
            [ProjectType::Mod, ProjectType::Datapack],
            $actor,
        );

        self::assertSame(InstalledMetadataResetService::STATUS_BUSY, $result['status']);
        self::assertSame([], $result['cleared_types']);
        self::assertFalse($leases->isHeld(42, ProjectType::Datapack));
        self::assertTrue($leases->owns(42, ProjectType::Mod, $existingToken));
    }

    public function test_clear_path_preserves_the_plugin_settings_update_permission(): void
    {
        $cache = new Repository(new ArrayStore());
        $leases = new InstalledOperationLease($cache);
        $projects = Mockery::mock(InstalledProjectService::class);
        $projects->shouldReceive('clearInstalledModsMetadata')->once();
        $actor = Mockery::mock(User::class);
        $actor->shouldReceive('isRootAdmin')->once()->andReturnFalse();
        $actor->shouldReceive('can')->once()->with('update plugin')->andReturnTrue();
        $resets = new InstalledMetadataResetService(
            $projects,
            $leases,
            new ProjectOperationAuthorizer(),
        );

        $result = $resets->clearWithoutScan(
            $this->server(),
            Mockery::mock(DaemonFileRepository::class),
            [ProjectType::Mod],
            $actor,
        );

        self::assertSame(InstalledMetadataResetService::STATUS_CLEARED, $result['status']);
        self::assertSame(['mod'], $result['cleared_types']);
        self::assertFalse($leases->isHeld(42, ProjectType::Mod));
    }

    public function test_queued_reset_authorizes_before_delete_and_releases_its_exact_tokens(): void
    {
        $cache = new Repository(new ArrayStore());
        $leases = new InstalledOperationLease($cache);
        $tokens = $leases->tryAcquireMany(
            42,
            [ProjectType::Mod, ProjectType::Datapack],
            InstalledOperationLease::OPERATION_CLEAR,
        );
        self::assertNotNull($tokens);
        $projects = Mockery::mock(InstalledProjectService::class);
        $projects->shouldNotReceive('clearInstalledModsMetadata');
        $resets = new InstalledMetadataResetService(
            $projects,
            $leases,
            new ProjectOperationAuthorizer(),
        );
        $operations = new InstalledOperationManager($cache, new ConfigRepository());
        $server = $this->server();
        $operations->queue($server, ProjectType::Mod, InstalledOperationManager::OPERATION_SCAN);
        $operations->queue($server, ProjectType::Datapack, InstalledOperationManager::OPERATION_SCAN);

        $resets->resetAndScan(
            $server,
            Mockery::mock(DaemonFileRepository::class),
            [ProjectType::Mod, ProjectType::Datapack],
            $tokens,
            null,
            $operations,
        );

        self::assertFalse($leases->isHeld(42, ProjectType::Mod));
        self::assertFalse($leases->isHeld(42, ProjectType::Datapack));
        self::assertSame('scan_unauthorized', $operations->state(42, ProjectType::Mod, InstalledOperationManager::OPERATION_SCAN)?->error);
        self::assertSame('scan_unauthorized', $operations->state(42, ProjectType::Datapack, InstalledOperationManager::OPERATION_SCAN)?->error);
    }

    public function test_resource_pack_is_rejected_without_touching_archive_metadata(): void
    {
        $projects = Mockery::mock(InstalledProjectService::class);
        $projects->shouldNotReceive('clearInstalledModsMetadata');
        $resets = new InstalledMetadataResetService(
            $projects,
            new InstalledOperationLease(new Repository(new ArrayStore())),
            new ProjectOperationAuthorizer(),
        );

        $result = $resets->clearWithoutScan(
            $this->server(),
            Mockery::mock(DaemonFileRepository::class),
            [ProjectType::ResourcePack],
            null,
        );

        self::assertSame(InstalledMetadataResetService::STATUS_UNSUPPORTED, $result['status']);
        self::assertSame([], $result['cleared_types']);
    }

    private function server(): Server
    {
        $server = new Server();
        $server->forceFill(['id' => 42]);

        return $server;
    }
}
