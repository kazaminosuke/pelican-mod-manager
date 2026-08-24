<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_manager_server_settings', function (Blueprint $table): void {
            $table->id();
            // Pelican's servers.id is increments('id'), so foreignId() would
            // create the wrong unsigned bigint type here.
            $table->unsignedInteger('server_id')->unique();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->boolean('mod_enabled')->default(true);
            $table->boolean('plugin_enabled')->default(true);
            $table->boolean('datapack_enabled')->default(true);
            $table->integer('mod_navigation_sort')->nullable();
            $table->integer('plugin_navigation_sort')->nullable();
            $table->integer('datapack_navigation_sort')->nullable();
            $table->boolean('allow_user_egg_profile_edit')->nullable();
            $table->boolean('allow_user_project_install')->nullable();
            $table->boolean('allow_user_project_update')->nullable();
            $table->boolean('allow_user_project_delete')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_manager_server_settings');
    }
};
