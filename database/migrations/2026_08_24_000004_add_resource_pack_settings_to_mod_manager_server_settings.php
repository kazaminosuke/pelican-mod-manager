<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mod_manager_server_settings', function (Blueprint $table): void {
            $table->boolean('resourcepack_enabled')->default(true);
            $table->integer('resourcepack_navigation_sort')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mod_manager_server_settings', function (Blueprint $table): void {
            $table->dropColumn(['resourcepack_enabled', 'resourcepack_navigation_sort']);
        });
    }
};
