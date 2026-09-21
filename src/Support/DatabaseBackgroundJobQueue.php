<?php

namespace Kazaminosuke\ModManager\Support;

use Kazaminosuke\ModManager\Contracts\BackgroundJobQueue;
use Kazaminosuke\ModManager\Models\ModManagerBackgroundJob;
use Throwable;

/**
 * Database-backed pending-work queue drained by `mod-manager:process-jobs`.
 */
final class DatabaseBackgroundJobQueue implements BackgroundJobQueue
{
    private const STALE_RESERVATION_MINUTES = 15;

    public function push(string $type, array $payload): bool
    {
        if ($type === '' || !$this->tableReady()) {
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

    public function claim(): ?array
    {
        if (!$this->tableReady()) {
            return null;
        }

        try {
            return ModManagerBackgroundJob::query()->getConnection()->transaction(function (): ?array {
                $job = ModManagerBackgroundJob::query()
                    ->where('available_at', '<=', now())
                    ->where(function ($query): void {
                        $query->whereNull('reserved_at')
                            ->orWhere('reserved_at', '<', now()->subMinutes(self::STALE_RESERVATION_MINUTES));
                    })
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

    public function ack(int|string $id): void
    {
        if (!$this->tableReady()) {
            return;
        }

        try {
            ModManagerBackgroundJob::query()->whereKey($id)->delete();
        } catch (Throwable) {
            // The row is retried after the reservation TTL if delete fails.
        }
    }

    public function retry(int|string $id, int $delaySeconds = 30): void
    {
        if (!$this->tableReady()) {
            return;
        }

        try {
            ModManagerBackgroundJob::query()->whereKey($id)->update([
                'reserved_at' => null,
                'available_at' => now()->addSeconds(max(0, $delaySeconds)),
            ]);
        } catch (Throwable) {
            // The stale-reservation reclaim path will pick the row up later.
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

    private function tableReady(): bool
    {
        try {
            ModManagerBackgroundJob::query()->limit(1)->get(['id']);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
