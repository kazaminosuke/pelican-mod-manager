<?php

namespace Kazaminosuke\ModManager\Support;

use Kazaminosuke\ModManager\Contracts\BackgroundJobQueue;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Models\ModManagerBackgroundJob;
use Throwable;

/**
 * Database-backed pending-work queue drained by `mod-manager:process-jobs`.
 */
final class DatabaseBackgroundJobQueue implements BackgroundJobQueue
{
    private const STALE_RESERVATION_MINUTES = 15;

    /**
     * Interactive user-facing jobs (scans, installed metadata fills, resets)
     * must not wait behind speculative catalog warming or background cache
     * revalidation.
     *
     * @var array<string, int>
     */
    private const TYPE_PRIORITIES = [
        BackgroundJob::SCAN => 1,
        BackgroundJob::WARM_PROJECT_METADATA => 1,
        BackgroundJob::RESET_METADATA => 1,
        BackgroundJob::BULK_UPDATE => 2,
        BackgroundJob::MODPACK => 2,
        BackgroundJob::REVALIDATE => 3,
        BackgroundJob::WARM_SEARCH => 4,
    ];

    public function push(string $type, array $payload): bool
    {
        if ($type === '') {
            return false;
        }

        try {
            ModManagerBackgroundJob::query()->create([
                'type' => $type,
                'payload' => $payload,
                'unique_key' => $this->uniqueKeyFrom($payload),
                'available_at' => now(),
                'attempts' => 0,
            ]);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function hasPending(): bool
    {
        try {
            return ModManagerBackgroundJob::query()
                ->where('available_at', '<=', now())
                ->where(function ($query): void {
                    $query->whereNull('reserved_at')
                        ->orWhere('reserved_at', '<', now()->subMinutes(self::STALE_RESERVATION_MINUTES));
                })
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function claim(): ?array
    {
        try {
            return ModManagerBackgroundJob::query()->getConnection()->transaction(function (): ?array {
                $caseParts = [];
                $bindings = [];

                foreach (self::TYPE_PRIORITIES as $type => $priority) {
                    $caseParts[] = 'WHEN ? THEN ?';
                    $bindings[] = $type;
                    $bindings[] = $priority;
                }

                $prioritySql = 'CASE type '.implode(' ', $caseParts).' ELSE 5 END ASC';

                $job = ModManagerBackgroundJob::query()
                    ->where('available_at', '<=', now())
                    ->where(function ($query): void {
                        $query->whereNull('reserved_at')
                            ->orWhere('reserved_at', '<', now()->subMinutes(self::STALE_RESERVATION_MINUTES));
                    })
                    ->orderByRaw($prioritySql, $bindings)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (!$job instanceof ModManagerBackgroundJob) {
                    return null;
                }

                $job->reserved_at = now();
                $job->attempts = ((int) $job->attempts) + 1;
                $job->save();

                $payload = $job->payload;

                return [
                    'id' => $job->getKey(),
                    'type' => (string) $job->type,
                    'payload' => is_array($payload) ? $payload : [],
                    'attempts' => (int) $job->attempts,
                ];
            });
        } catch (Throwable) {
            return null;
        }
    }

    public function ack(int|string $id, ?int $expectedAttempts = null): void
    {
        try {
            $query = ModManagerBackgroundJob::query()->whereKey($id);
            if ($expectedAttempts !== null) {
                $query->where('attempts', $expectedAttempts);
            }
            $query->delete();
        } catch (Throwable) {
            // The row is retried after the reservation TTL if delete fails.
        }
    }

    public function retry(int|string $id, int $delaySeconds = 30, ?int $expectedAttempts = null): void
    {
        try {
            $query = ModManagerBackgroundJob::query()->whereKey($id);
            if ($expectedAttempts !== null) {
                $query->where('attempts', $expectedAttempts);
            }
            $query->update([
                'reserved_at' => null,
                'available_at' => now()->addSeconds(max(0, $delaySeconds)),
            ]);
        } catch (Throwable) {
            // The stale-reservation reclaim path will pick the row up later.
        }
    }

    public function release(int|string $id, int $delaySeconds, ?int $expectedAttempts = null): void
    {
        try {
            $query = ModManagerBackgroundJob::query()
                ->whereKey($id)
                ->where('attempts', '>', 0);
            if ($expectedAttempts !== null) {
                $query->where('attempts', $expectedAttempts);
            }
            $query->decrement('attempts', 1, [
                'reserved_at' => null,
                'available_at' => now()->addSeconds(max(0, $delaySeconds)),
            ]);
        } catch (Throwable) {
            // The stale-reservation reclaim path will pick the row up later.
        }
    }

    public function heartbeat(int|string $id, int $expectedAttempts): bool
    {
        try {
            return ModManagerBackgroundJob::query()
                ->whereKey($id)
                ->where('attempts', $expectedAttempts)
                ->whereNotNull('reserved_at')
                ->update(['reserved_at' => now()]) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function uniqueKeyFrom(array $payload): ?string
    {
        $uniqueKey = $payload['_unique_key'] ?? null;

        return is_string($uniqueKey) && $uniqueKey !== '' ? $uniqueKey : null;
    }
}
