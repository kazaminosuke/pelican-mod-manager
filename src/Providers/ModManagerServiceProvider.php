<?php

namespace Kazaminosuke\ModManager\Providers;

use App\Models\Role;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Kazaminosuke\ModManager\Console\Commands\RunBackgroundJobCommand;
use Kazaminosuke\ModManager\Console\Commands\WarmCatalogCacheCommand;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
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
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Support\ProjectOperationAuthorizer;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchExecutor;
use Kazaminosuke\ModManager\Support\WarmRequestThrottle;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;

class ModManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SourceFetchExecutorInterface::class, SourceFetchExecutor::class);

        $this->app->singleton(PluginBackgroundRunner::class, function ($app) {
            $cache = null;

            try {
                if ($app->bound(CacheRepository::class)) {
                    $resolved = $app->make(CacheRepository::class);
                    $cache = $resolved instanceof CacheRepository ? $resolved : null;
                }
            } catch (\Throwable) {
                $cache = null;
            }

            return PluginBackgroundRunner::forRuntime($cache);
        });

        foreach ([
            SourceCache::class,
            ModrinthSource::class,
            CurseForgeSource::class,
            HangarSource::class,
            GitHubReleasesSource::class,
            ProjectSourceRegistry::class,
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
            $this->app->singleton($service);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                WarmCatalogCacheCommand::class,
                RunBackgroundJobCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        // Pelican's Admin > Roles screen discovers third-party permissions
        // from Role::getPermissionList(). These three are deliberately
        // separate so a role can be allowed to install, update, or delete
        // managed files independently of the general-user toggles.
        Role::registerCustomPermissions([
            'minecraftModManager' => ['create', 'update', 'delete'],
        ]);

        // Hooks into the panel's own scheduler (Pelican already depends on
        // `php artisan schedule:run` being cron'd every minute for its own
        // per-server scheduled-task feature - see
        // App\Console\Commands\Schedule\ProcessRunnableCommand - so every
        // functioning install already has this covered). Every 10 minutes
        // matches CacheProfile::Search's fresh TTL: running more often
        // than that can't keep an entry any fresher, and running less
        // often lets it go stale between warms.
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command(WarmCatalogCacheCommand::class)
                ->everyTenMinutes()
                ->withoutOverlapping()
                ->name('mod-manager:warm-catalog');
        });
    }
}
