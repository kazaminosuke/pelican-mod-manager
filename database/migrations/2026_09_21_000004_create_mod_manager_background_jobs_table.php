<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent pending-work table for Hub-safe background execution.
 *
 * HTTP requests enqueue JSON payloads here. Pelican's existing
 * `php artisan schedule:run` cron drains them in a short-lived process
 * that boots the current Plugin from disk. This replaces subprocess
 * spawning without returning that work to Laravel's long-running
 * `queue:work` worker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_manager_background_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 64);
            $table->json('payload');
            $table->string('unique_key')->nullable()->index();
            $table->timestamp('available_at')->index();
            $table->timestamp('reserved_at')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_manager_background_jobs');
    }
};
