<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mod_manager_server_settings', function (Blueprint $table): void {
            // These defaults preserve the existing source availability for
            // servers that never open the per-server settings form. GitHub
            // Releases was previously opt-in through an egg flag, so it stays
            // disabled until an administrator explicitly enables it here.
            $table->boolean('modrinth_enabled')->default(true);
            $table->boolean('curseforge_enabled')->default(true);
            $table->boolean('hangar_enabled')->default(true);
            $table->boolean('github_releases_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mod_manager_server_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'modrinth_enabled',
                'curseforge_enabled',
                'hangar_enabled',
                'github_releases_enabled',
            ]);
        });
    }
};
