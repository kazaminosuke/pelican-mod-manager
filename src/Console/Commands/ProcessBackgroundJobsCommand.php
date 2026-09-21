<?php

namespace Kazaminosuke\ModManager\Console\Commands;

use Illuminate\Console\Command;
use Kazaminosuke\ModManager\Contracts\BackgroundJobQueue;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Support\BackgroundJobRuntime;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Throwable;

/**
 * Drains persisted Mod Manager jobs inside Pelican's short-lived
 * `schedule:run` process.
 *
 * HTTP never runs scans or cache warming inline. It only writes JSON
 * payloads. This command boots the current Plugin from disk each minute
 * and exits when the time budget is exhausted.
 */
final class ProcessBackgroundJobsCommand extends Command
{
    public const MAX_ATTEMPTS = 3;

    public const TIME_BUDGET_SECONDS = 50;

    public const MAX_JOBS_PER_RUN = 25;

    protected $signature = 'mod-manager:process-jobs';

    protected $description = 'Process persisted Minecraft Mod Manager background jobs in this short-lived scheduler process.';

    public function handle(?BackgroundJobQueue $queue = null, ?PluginBackgroundRunner $runner = null): int
    {
        $queue ??= $this->resolveQueue();

        if ($queue === null) {
            $this->error('The Mod Manager background job queue is unavailable.');

            return self::FAILURE;
        }

        $startedAt = microtime(true);
        $processed = 0;

        while ($processed < self::MAX_JOBS_PER_RUN
            && (microtime(true) - $startedAt) < self::TIME_BUDGET_SECONDS) {
            $job = $queue->claim();

            if ($job === null) {
                break;
            }

            $processed++;
            $payload = $job['payload'];
            $uniqueKey = $payload['_unique_key'] ?? null;
            $finished = false;

            try {
                BackgroundJob::execute($job['type'], $payload);
                $queue->ack($job['id']);
                $finished = true;
            } catch (Throwable $exception) {
                try {
                    report($exception);
                } catch (Throwable) {
                    // Unit tests and a missing log binding must not hide the job failure.
                }

                $this->error($exception->getMessage());

                if (($job['attempts'] ?? 1) < self::MAX_ATTEMPTS) {
                    $queue->retry($job['id']);
                } else {
                    $queue->ack($job['id']);
                    $finished = true;
                }
            } finally {
                if ($finished) {
                    $this->releaseUnique($runner, $uniqueKey);
                }

                BackgroundJobRuntime::forgetRequestState();
            }
        }

        if ($processed > 0) {
            $this->info("Processed {$processed} Mod Manager background job(s).");
        }

        return self::SUCCESS;
    }

    private function resolveQueue(): ?BackgroundJobQueue
    {
        try {
            $queue = app(BackgroundJobQueue::class);
        } catch (Throwable) {
            return null;
        }

        return $queue instanceof BackgroundJobQueue ? $queue : null;
    }

    private function releaseUnique(?PluginBackgroundRunner $runner, mixed $uniqueKey): void
    {
        if (!is_string($uniqueKey) || $uniqueKey === '') {
            return;
        }

        if ($runner instanceof PluginBackgroundRunner) {
            $runner->releaseUniqueKey($uniqueKey);

            return;
        }

        try {
            cache()->forget($uniqueKey);
        } catch (Throwable) {
            // The unique lock expires on its own TTL if cache is unavailable.
        }
    }
}
