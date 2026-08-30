<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Repositories\InstalledMetadataRepository;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledScanResult;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Mockery;
use PHPUnit\Framework\TestCase;

final class InstalledScanCountCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_successful_scan_count_is_reused_without_a_second_wings_scan(): void
    {
        $previousFacadeApplication = Facade::getFacadeApplication();
        $previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('cache', new CacheRepository(new ArrayStore()));
        $container->instance('config', new ConfigRepository([
            'pelican-mod-manager' => ['debug_timing' => false],
        ]));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        try {
            $service = new class(Mockery::mock(ProjectSourceRegistry::class), Mockery::mock(InstalledMetadataRepository::class)) extends InstalledProjectService
            {
                public int $scanExecutions = 0;

                protected function performScan(
                    Server $server,
                    DaemonFileRepository $fileRepository,
                    ?ProjectType $type = null,
                ): InstalledScanResult {
                    $this->scanExecutions++;

                    return InstalledScanResult::success([], 3);
                }
            };
            $server = new Server();
            $server->forceFill(['id' => 42]);
            $files = Mockery::mock(DaemonFileRepository::class);

            $first = $service->scanAndImportModsResult($server, $files, ProjectType::Mod);
            $second = $service->scanAndImportModsResult($server, $files, ProjectType::Mod);

            self::assertSame(
                'installed_scan:v2:42:mod',
                $service->getHashScanCacheKey($server, ProjectType::Mod),
            );
            self::assertSame(1, $service->scanExecutions);
            self::assertFalse($first->cacheHit);
            self::assertTrue($second->cacheHit);
            self::assertSame(3, $second->diskFileCount);
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacadeApplication);
            Container::setInstance($previousContainer);
        }
    }
}
