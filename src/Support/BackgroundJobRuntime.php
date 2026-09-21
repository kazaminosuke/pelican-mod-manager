<?php

namespace Kazaminosuke\ModManager\Support;

use Illuminate\Container\Container;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Throwable;

/**
 * Clears request-local Plugin memos between independent background jobs
 * that share one short-lived `schedule:run` process.
 */
final class BackgroundJobRuntime
{
    public static function forgetRequestState(): void
    {
        EggProfileResolver::clear();
        MinecraftVersionResolver::clear();
        CatalogCompatibilityOverride::clear();

        $app = Container::getInstance();

        if (!is_object($app) || !method_exists($app, 'bound')) {
            return;
        }

        foreach ([SourceCache::class, InstalledProjectService::class, ServerModManagerSettings::class] as $service) {
            if (!$app->bound($service)) {
                continue;
            }

            try {
                $resolved = $app->make($service);
            } catch (Throwable) {
                continue;
            }

            if ($resolved instanceof SourceCache || $resolved instanceof InstalledProjectService) {
                $resolved->clearRuntimeCaches();
            }

            if ($resolved instanceof ServerModManagerSettings) {
                $resolved->clearRuntimeCache();
            }
        }
    }
}
