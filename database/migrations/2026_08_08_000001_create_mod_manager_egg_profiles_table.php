<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stage 8: persists the GUI fallback's manual egg profiles (see
// Kazaminosuke\ModManager\Models\ModManagerEggProfile /
// Support\EggProfileResolver). One row per egg - not per server - since
// loader/project type/datapack support are properties of the egg itself.
// PluginService::installPlugin()/uninstallPlugin() run this automatically
// via runPluginMigrations()/rollbackPluginMigrations()
// (plugins/<id>/database/migrations/ is auto-discovered; confirmed against
// the panel's actual source before this stage, not assumed).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_manager_egg_profiles', function (Blueprint $table) {
            $table->id();
            // Pelican's eggs.id is the legacy unsigned INT primary key from
            // service_options. foreignId() would create an unsigned BIGINT
            // and MySQL rejects the mismatched foreign key at install time.
            $table->unsignedInteger('egg_id');
            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
            $table->unique('egg_id');
            // Kept for audit/debugging even though egg_id is the real key -
            // an egg's uuid can itself change on a future re-import (Paper
            // and Forge Minecraft both have historically), so this is a
            // snapshot of what it was when the profile was saved, not a
            // second lookup key.
            $table->string('egg_uuid')->nullable();
            $table->string('project_type')->nullable();
            $table->string('loader')->nullable();
            // Null means "keep resolving from the server's own
            // MINECRAFT_VERSION/MC_VERSION variable (or the plugin's global
            // default)" - only a non-null value here overrides that.
            $table->string('minecraft_version')->nullable();
            $table->boolean('supports_datapacks')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_manager_egg_profiles');
    }
};
