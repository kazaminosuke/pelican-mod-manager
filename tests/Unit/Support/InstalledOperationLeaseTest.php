<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Exception;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use PHPUnit\Framework\TestCase;

class InstalledOperationLeaseTest extends TestCase
{
    public function test_second_acquire_fails_until_the_owner_releases(): void
    {
        $leases = new InstalledOperationLease(new Repository(new ArrayStore()));
        $token = $leases->tryAcquire(7, ProjectType::Mod, InstalledOperationLease::OPERATION_INSTALL);

        self::assertNotNull($token);
        self::assertNull($leases->tryAcquire(7, ProjectType::Mod, InstalledOperationLease::OPERATION_SCAN));
        self::assertSame(InstalledOperationLease::OPERATION_INSTALL, $leases->currentOperation(7, ProjectType::Mod));

        $leases->release(7, ProjectType::Mod, 'wrong-token');
        self::assertTrue($leases->isHeld(7, ProjectType::Mod));

        $leases->release(7, ProjectType::Mod, $token);
        self::assertFalse($leases->isHeld(7, ProjectType::Mod));
        self::assertNotNull($leases->tryAcquire(7, ProjectType::Plugin, InstalledOperationLease::OPERATION_SCAN));
    }

    public function test_run_releases_the_lease_after_an_exception(): void
    {
        $leases = new InstalledOperationLease(new Repository(new ArrayStore()));

        try {
            $leases->run(7, ProjectType::Mod, InstalledOperationLease::OPERATION_UPDATE, function (): void {
                throw new Exception('boom');
            });
            self::fail('Callback exception must propagate.');
        } catch (Exception $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertFalse($leases->isHeld(7, ProjectType::Mod));
    }

    public function test_multi_type_acquire_rolls_back_earlier_claims_when_a_later_type_is_busy(): void
    {
        $leases = new InstalledOperationLease(new Repository(new ArrayStore()));
        $modOwner = $leases->tryAcquire(7, ProjectType::Mod, InstalledOperationLease::OPERATION_INSTALL);

        self::assertNotNull($modOwner);
        self::assertNull($leases->tryAcquireMany(
            7,
            [ProjectType::Mod, ProjectType::Datapack],
            InstalledOperationLease::OPERATION_CLEAR,
        ));
        self::assertFalse($leases->isHeld(7, ProjectType::Datapack));
        self::assertTrue($leases->owns(7, ProjectType::Mod, $modOwner));
    }

    public function test_stale_owner_token_cannot_release_a_replacement_lease(): void
    {
        $cache = new Repository(new ArrayStore());
        $leases = new InstalledOperationLease($cache);
        $staleToken = $leases->tryAcquire(7, ProjectType::Mod, InstalledOperationLease::OPERATION_SCAN);

        self::assertNotNull($staleToken);
        $cache->forget(InstalledOperationLease::key(7, ProjectType::Mod));
        $replacementToken = $leases->tryAcquire(7, ProjectType::Mod, InstalledOperationLease::OPERATION_UPDATE);
        self::assertNotNull($replacementToken);

        $leases->release(7, ProjectType::Mod, $staleToken);

        self::assertTrue($leases->owns(7, ProjectType::Mod, $replacementToken));
        self::assertSame(InstalledOperationLease::OPERATION_UPDATE, $leases->currentOperation(7, ProjectType::Mod));
    }

    public function test_refresh_renews_the_dispatch_time_lease_ttl(): void
    {
        Carbon::setTestNow('2026-08-25 00:00:00');

        try {
            $leases = new InstalledOperationLease(new Repository(new ArrayStore()));
            $token = $leases->tryAcquire(
                7,
                ProjectType::Mod,
                InstalledOperationLease::OPERATION_SCAN,
                10,
            );
            self::assertNotNull($token);

            Carbon::setTestNow('2026-08-25 00:00:09');
            self::assertTrue($leases->refresh(7, ProjectType::Mod, $token, 10));

            Carbon::setTestNow('2026-08-25 00:00:11');
            self::assertTrue($leases->owns(7, ProjectType::Mod, $token));

            Carbon::setTestNow('2026-08-25 00:00:20');
            self::assertFalse($leases->owns(7, ProjectType::Mod, $token));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_acquire_refresh_and_release_use_the_owner_guard(): void
    {
        $cache = new class(new ArrayStore()) extends Repository
        {
            public int $guardCalls = 0;

            public function withoutOverlapping($key, callable $callback, $lockFor = 0, $waitFor = 10, $owner = null)
            {
                $this->guardCalls++;

                return parent::withoutOverlapping($key, $callback, $lockFor, $waitFor, $owner);
            }
        };
        $leases = new InstalledOperationLease($cache);
        $token = $leases->tryAcquire(7, ProjectType::Mod, InstalledOperationLease::OPERATION_SCAN);

        self::assertNotNull($token);
        self::assertTrue($leases->refresh(7, ProjectType::Mod, $token));
        $leases->release(7, ProjectType::Mod, $token);

        self::assertSame(3, $cache->guardCalls);
    }
}
