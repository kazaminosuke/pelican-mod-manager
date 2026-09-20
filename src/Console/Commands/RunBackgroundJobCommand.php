<?php

namespace Kazaminosuke\ModManager\Console\Commands;

use Illuminate\Console\Command;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Throwable;

/**
 * Entry point for one short-lived Mod Manager background process.
 *
 * HTTP / scheduler never run heavy work inline. They spawn
 * `php artisan mod-manager:run-job {type} {payload}`, which boots a
 * fresh Panel + Plugin from disk and exits.
 */
final class RunBackgroundJobCommand extends Command
{
    protected $signature = 'mod-manager:run-job {type} {payload}';

    protected $description = 'Run one Minecraft Mod Manager background job in a short-lived process.';

    public function handle(?PluginBackgroundRunner $runner = null): int
    {
        $type = (string) $this->argument('type');
        $encoded = (string) $this->argument('payload');

        try {
            $payload = PluginBackgroundRunner::decodePayload($encoded);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $uniqueKey = $payload['_unique_key'] ?? null;

        try {
            BackgroundJob::execute($type, $payload);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (is_string($uniqueKey) && $uniqueKey !== '' && $runner?->canSpawn()) {
                cache()->forget($uniqueKey);
            } elseif (is_string($uniqueKey) && $uniqueKey !== '' && function_exists('cache')) {
                try {
                    cache()->forget($uniqueKey);
                } catch (Throwable) {
                    // The unique lock expires on its own TTL if cache is unavailable.
                }
            }
        }
    }
}
