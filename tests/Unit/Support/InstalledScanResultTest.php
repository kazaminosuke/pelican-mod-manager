<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\InstalledScanResult;
use PHPUnit\Framework\TestCase;

class InstalledScanResultTest extends TestCase
{
    public function test_install_of_a_new_file_adds_it_to_the_count(): void
    {
        $result = InstalledScanResult::success(['mystery.jar'], 3, checkedAt: 100)
            ->withFileChange('sodium.jar', null, addedExisted: false);

        self::assertSame(4, $result->diskFileCount);
        self::assertSame(['mystery.jar'], $result->unknownFiles);
        self::assertSame(100, $result->checkedAt);
    }

    public function test_installing_over_an_unknown_file_tracks_it_without_changing_the_count(): void
    {
        $result = InstalledScanResult::success(['Sodium.jar', 'mystery.jar'], 3)
            ->withFileChange('sodium.jar', null, addedExisted: true);

        self::assertSame(3, $result->diskFileCount);
        self::assertSame(['mystery.jar'], $result->unknownFiles);
    }

    public function test_update_to_a_new_filename_replaces_the_old_file(): void
    {
        $result = InstalledScanResult::success([], 3)
            ->withFileChange('sodium-2.jar', 'sodium-1.jar', addedExisted: false);

        self::assertSame(3, $result->diskFileCount);
    }

    public function test_same_name_update_keeps_the_count(): void
    {
        $result = InstalledScanResult::success([], 3)
            ->withFileChange('sodium.jar', 'sodium.jar', addedExisted: true);

        self::assertSame(3, $result->diskFileCount);
    }

    public function test_removal_drops_the_file(): void
    {
        $result = InstalledScanResult::success(['mystery.jar'], 3)
            ->withFileChange(null, 'mystery.jar', addedExisted: false);

        self::assertSame(2, $result->diskFileCount);
        self::assertSame([], $result->unknownFiles);
    }

    public function test_freshness_is_carried_through_the_cache_payload(): void
    {
        $fresh = InstalledScanResult::fromCache(InstalledScanResult::success([], 1)->toCachePayload());
        $stale = InstalledScanResult::fromCache(InstalledScanResult::success([], 1)->asStale()->toCachePayload());

        self::assertTrue($fresh?->isFresh());
        self::assertTrue($fresh?->cacheHit);
        self::assertFalse($stale?->isFresh());
        self::assertFalse(InstalledScanResult::success([], 1, checkedAt: time() - InstalledScanResult::FRESH_SECONDS)->isFresh());
    }
}
