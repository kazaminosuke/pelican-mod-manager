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
}
