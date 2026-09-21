<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spigot is a Plugin catalog source. Existing servers inherit the same
 * default-on behaviour as Hangar. The file index stores high-confidence
 * JAR hash → resource/version mappings because Spigot has no complete
 * upstream hash reverse lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mod_manager_server_settings', function (Blueprint $table): void {
            $table->boolean('spigot_enabled')->default(true)->after('hangar_enabled');
        });

        Schema::create('mod_manager_spigot_file_index', function (Blueprint $table): void {
            $table->id();
            $table->string('sha256', 64)->unique();
            $table->string('resource_id', 32);
            $table->string('version_id', 32);
            $table->string('version_number', 128);
            $table->string('plugin_name', 191)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_manager_spigot_file_index');

        Schema::table('mod_manager_server_settings', function (Blueprint $table): void {
            $table->dropColumn('spigot_enabled');
        });
    }
};
