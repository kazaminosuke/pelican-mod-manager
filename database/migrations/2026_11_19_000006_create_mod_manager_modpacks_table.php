<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_manager_modpacks', function (Blueprint $table): void {
            $table->unsignedInteger('server_id')->primary();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->string('revision', 32)->nullable();
            $table->longText('inventory')->nullable();
            $table->string('mutation', 32)->nullable();
            $table->longText('recovery')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_manager_modpacks');
    }
};
