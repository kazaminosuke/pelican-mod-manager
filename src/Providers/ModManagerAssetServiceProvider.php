<?php

namespace Kazaminosuke\ModManager\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Kazaminosuke\ModManager\Http\Controllers\ModManagerAssetController;

final class ModManagerAssetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::get(
            '/plugins/pelican-mod-manager/assets/{version}/{asset}',
            ModManagerAssetController::class,
        )
            ->where('version', '[a-f0-9]{64}')
            ->where('asset', '[a-z0-9.-]+')
            ->name('pelican-mod-manager.asset');
    }
}
