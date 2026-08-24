<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Providers;

use Illuminate\Foundation\Application;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Providers\ModManagerServiceProvider;
use Kazaminosuke\ModManager\Repositories\ServerModManagerSettingRepository;
use Kazaminosuke\ModManager\Services\InstalledArchiveTransaction;
use Kazaminosuke\ModManager\Services\InstalledMetadataResetService;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectMutationService;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Services\VersionLookupCoordinator;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\GitHubReleasesSource;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\InstalledMetadataIndex;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\ProjectOperationAuthorizer;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\WarmRequestThrottle;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use PHPUnit\Framework\TestCase;

class ModManagerServiceProviderTest extends TestCase
{
    public function test_expensive_services_are_registered_as_singletons(): void
    {
        $application = new Application();
        (new ModManagerServiceProvider($application))->register();

        foreach ([
            SourceFetchExecutorInterface::class,
            SourceCache::class,
            ProjectSourceRegistry::class,
            ModrinthSource::class,
            CurseForgeSource::class,
            HangarSource::class,
            GitHubReleasesSource::class,
            VersionLookupCoordinator::class,
            InstalledProjectService::class,
            InstalledArchiveTransaction::class,
            InstalledMetadataIndex::class,
            InstalledMetadataResetService::class,
            InstalledProjectMutationService::class,
            InstalledOperationLease::class,
            WingsRemoteFilesystem::class,
            InstalledOperationManager::class,
            ProjectOperationAuthorizer::class,
            ServerModManagerSettingRepository::class,
            ServerModManagerSettings::class,
            WarmRequestThrottle::class,
        ] as $service) {
            self::assertTrue($application->isShared($service), "{$service} was not registered as a singleton.");
        }
    }
}
