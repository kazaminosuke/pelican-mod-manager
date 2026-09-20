<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use InvalidArgumentException;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Jobs\BulkUpdateInstalledProjects;
use Kazaminosuke\ModManager\Jobs\ResetInstalledMetadata;
use Kazaminosuke\ModManager\Jobs\RevalidateSourceCache;
use Kazaminosuke\ModManager\Jobs\ScanInstalledProjects;
use Kazaminosuke\ModManager\Jobs\WarmCatalogSearch;
use Kazaminosuke\ModManager\Jobs\WarmProjectMetadata;
use PHPUnit\Framework\TestCase;

class BackgroundJobTest extends TestCase
{
    public function test_plugin_job_classes_are_not_laravel_queue_jobs(): void
    {
        foreach ([
            ScanInstalledProjects::class,
            BulkUpdateInstalledProjects::class,
            ResetInstalledMetadata::class,
            WarmCatalogSearch::class,
            WarmProjectMetadata::class,
            RevalidateSourceCache::class,
            BackgroundJob::class,
        ] as $class) {
            self::assertNotContains(ShouldQueue::class, class_implements($class) ?: [], $class);
        }
    }

    public function test_unknown_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Mod Manager background job [not_a_job].');

        BackgroundJob::execute('not_a_job', []);
    }

    public function test_scan_payload_requires_typed_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Background job payload missing [server_id].');

        BackgroundJob::execute(BackgroundJob::SCAN, [
            'project_type' => 'mod',
            'lease_token' => 'token',
        ]);
    }
}
