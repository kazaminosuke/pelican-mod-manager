<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * One-time recycle of long-running `queue:work` processes that still hold
 * pre-Queue-exit Mod Manager job classes.
 *
 * Pelican runs this after the updated Plugin files are on disk:
 * UpdatePlugin → PluginService::updatePlugin() → downloadPluginFromUrl()
 * → installPlugin() → runPluginMigrations(). Laravel's worker then
 * finishes the current job (the Plugin update itself) and only then
 * honors `illuminate:queue:restart`. systemd/supervisor starts a fresh
 * worker. Later Plugin updates skip this file because it is already in
 * the migrations table.
 *
 * Ongoing Mod Manager work uses PluginBackgroundRunner /
 * `mod-manager:run-job`, not Laravel Queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('queue:restart');
    }

    public function down(): void
    {
        // Rolling back must not broadcast another restart signal.
    }
};
