<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Models\ModManagerBackgroundJob;
use Kazaminosuke\ModManager\Support\DatabaseBackgroundJobQueue;
use PHPUnit\Framework\TestCase;

class DatabaseBackgroundJobQueueTest extends TestCase
{
    private static ?Capsule $capsule = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$capsule === null) {
            self::$capsule = new Capsule();
            self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
        }

        Capsule::schema()->dropIfExists('mod_manager_background_jobs');
        Capsule::schema()->create('mod_manager_background_jobs', function ($table): void {
            $table->id();
            $table->string('type', 64);
            $table->json('payload');
            $table->string('unique_key')->nullable();
            $table->timestamp('available_at');
            $table->timestamp('reserved_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function test_push_claim_ack_round_trip_persists_json_payloads(): void
    {
        $queue = new DatabaseBackgroundJobQueue();

        self::assertTrue($queue->push(BackgroundJob::WARM_SEARCH, [
            'page' => 2,
            '_unique_key' => 'mod_manager_bg_unique:v1:warm',
        ]));
        self::assertSame(1, ModManagerBackgroundJob::query()->count());

        $claimed = $queue->claim();

        self::assertIsArray($claimed);
        self::assertSame(BackgroundJob::WARM_SEARCH, $claimed['type']);
        self::assertSame(2, $claimed['payload']['page']);
        self::assertSame(1, $claimed['attempts']);
        self::assertNull($queue->claim());

        $queue->ack($claimed['id']);

        self::assertSame(0, ModManagerBackgroundJob::query()->count());
    }

    public function test_retry_makes_the_job_claimable_again(): void
    {
        $queue = new DatabaseBackgroundJobQueue();
        $queue->push(BackgroundJob::REVALIDATE, ['source_key' => 'hangar']);
        $claimed = $queue->claim();

        self::assertIsArray($claimed);
        $queue->retry($claimed['id'], 0);

        $retried = $queue->claim();

        self::assertIsArray($retried);
        self::assertSame($claimed['id'], $retried['id']);
        self::assertSame(2, $retried['attempts']);
    }

    public function test_release_requeues_the_job_without_consuming_an_attempt(): void
    {
        $queue = new DatabaseBackgroundJobQueue();
        $queue->push(BackgroundJob::SCAN, ['server_id' => 1]);
        $claimed = $queue->claim();

        self::assertIsArray($claimed);
        self::assertSame(1, $claimed['attempts']);

        $queue->release($claimed['id'], 0);
        $released = $queue->claim();

        self::assertIsArray($released);
        self::assertSame($claimed['id'], $released['id']);
        self::assertSame(1, $released['attempts']);
    }

    public function test_released_job_waits_for_its_delay(): void
    {
        $queue = new DatabaseBackgroundJobQueue();
        $queue->push(BackgroundJob::SCAN, ['server_id' => 1]);
        $claimed = $queue->claim();

        self::assertIsArray($claimed);
        $queue->release($claimed['id'], 60);

        self::assertNull($queue->claim());
    }

    public function test_heartbeat_extends_reservation_and_stale_attempt_cannot_ack_retry_or_release(): void
    {
        $queue = new DatabaseBackgroundJobQueue();
        $queue->push(BackgroundJob::BULK_UPDATE, ['server_id' => 9]);

        $firstAttempt = $queue->claim();
        self::assertIsArray($firstAttempt);
        self::assertSame(1, $firstAttempt['attempts']);

        // Simulate a slow job keeping its reservation alive via heartbeat past the initial reclaim window.
        ModManagerBackgroundJob::query()
            ->whereKey($firstAttempt['id'])
            ->update(['reserved_at' => gmdate('Y-m-d H:i:s', time() - 240)]);

        self::assertTrue($queue->heartbeat($firstAttempt['id'], 1));
        self::assertNull($queue->claim(), 'A live job whose heartbeat renewed reserved_at must not be reclaimed.');

        // If the worker actually stalls without heartbeat past STALE_RESERVATION_MINUTES, a replacement worker reclaims it.
        ModManagerBackgroundJob::query()
            ->whereKey($firstAttempt['id'])
            ->update(['reserved_at' => gmdate('Y-m-d H:i:s', time() - 360)]);

        $secondAttempt = $queue->claim();
        self::assertIsArray($secondAttempt);
        self::assertSame(2, $secondAttempt['attempts']);

        // The fenced first attempt must no longer acknowledge, retry, release, or heartbeat the row.
        self::assertFalse($queue->heartbeat($firstAttempt['id'], 1));
        $queue->retry($firstAttempt['id'], 0, 1);
        $queue->release($firstAttempt['id'], 0, 1);
        $queue->ack($firstAttempt['id'], 1);

        $row = ModManagerBackgroundJob::query()->find($secondAttempt['id']);
        self::assertNotNull($row);
        self::assertSame(2, (int) $row->attempts);
        self::assertNotNull($row->reserved_at);

        $queue->ack($secondAttempt['id'], 2);
        self::assertSame(0, ModManagerBackgroundJob::query()->count());
    }

    public function test_claim_prioritizes_interactive_jobs_ahead_of_speculative_warm_search(): void
    {
        $queue = new DatabaseBackgroundJobQueue();

        $queue->push(BackgroundJob::WARM_SEARCH, ['page' => 1]);
        $queue->push(BackgroundJob::WARM_SEARCH, ['page' => 2]);
        $queue->push(BackgroundJob::REVALIDATE, ['source' => 'hangar']);
        $queue->push(BackgroundJob::BULK_UPDATE, ['server_id' => 1]);
        $queue->push(BackgroundJob::MODPACK, ['server_id' => 2]);
        $queue->push(BackgroundJob::WARM_PROJECT_METADATA, ['project' => 'via']);
        $queue->push(BackgroundJob::SCAN, ['server_id' => 3]);

        $order = [];
        while (($job = $queue->claim()) !== null) {
            $order[] = $job['type'];
            $queue->ack($job['id'], $job['attempts']);
        }

        self::assertSame([
            BackgroundJob::WARM_PROJECT_METADATA,
            BackgroundJob::SCAN,
            BackgroundJob::BULK_UPDATE,
            BackgroundJob::MODPACK,
            BackgroundJob::REVALIDATE,
            BackgroundJob::WARM_SEARCH,
            BackgroundJob::WARM_SEARCH,
        ], $order);
    }

    public function test_has_pending_tracks_claimable_jobs(): void
    {
        $queue = new DatabaseBackgroundJobQueue();

        self::assertFalse($queue->hasPending());

        $queue->push(BackgroundJob::SCAN, ['server_id' => 7]);
        self::assertTrue($queue->hasPending());

        $claimed = $queue->claim();
        self::assertIsArray($claimed);
        self::assertFalse($queue->hasPending());

        // A delayed release is not yet claimable.
        $queue->release($claimed['id'], 120, $claimed['attempts']);
        self::assertFalse($queue->hasPending());

        // A stale reservation becomes claimable again.
        ModManagerBackgroundJob::query()
            ->whereKey($claimed['id'])
            ->update([
                'available_at' => gmdate('Y-m-d H:i:s', time() - 10),
                'reserved_at' => gmdate('Y-m-d H:i:s', time() - 360),
            ]);
        self::assertTrue($queue->hasPending());
    }
}
