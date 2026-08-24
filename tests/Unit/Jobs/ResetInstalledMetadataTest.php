<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Jobs;

use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\ResetInstalledMetadata;
use Kazaminosuke\ModManager\Services\InstalledMetadataResetService;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\ProjectOperationAuthorizer;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ResetInstalledMetadataTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_refresh_failure_releases_every_later_still_owned_type(): void
    {
        $cache = new Repository(new ArrayStore());
        $leases = new InstalledOperationLease($cache);
        $tokens = $leases->tryAcquireMany(
            42,
            [ProjectType::Datapack, ProjectType::Plugin],
            InstalledOperationLease::OPERATION_CLEAR,
        );
        self::assertNotNull($tokens);

        $leaseTokens = [
            'datapack' => $tokens['datapack'],
            'mod' => 'lost-token',
            'plugin' => $tokens['plugin'],
        ];
        $operations = new InstalledOperationManager($cache, new ConfigRepository(), $leases);

        foreach ([ProjectType::Datapack, ProjectType::Mod, ProjectType::Plugin] as $type) {
            $operations->queue(42, $type, InstalledOperationManager::OPERATION_SCAN);
        }

        $job = new ResetInstalledMetadata(
            42,
            ['datapack', 'mod', 'plugin'],
            $leaseTokens,
            7,
        );
        $job->handle(
            Mockery::mock(DaemonFileRepository::class),
            new InstalledMetadataResetService(
                Mockery::mock(InstalledProjectService::class),
                $leases,
                new ProjectOperationAuthorizer(),
            ),
            $operations,
            $leases,
        );

        self::assertFalse($leases->isHeld(42, ProjectType::Datapack));
        self::assertFalse($leases->isHeld(42, ProjectType::Plugin));
        self::assertSame(
            'operation_lease_lost',
            $operations->state(42, ProjectType::Datapack, InstalledOperationManager::OPERATION_SCAN)?->error,
        );
        self::assertSame(
            'operation_lease_lost',
            $operations->state(42, ProjectType::Plugin, InstalledOperationManager::OPERATION_SCAN)?->error,
        );
    }
}
