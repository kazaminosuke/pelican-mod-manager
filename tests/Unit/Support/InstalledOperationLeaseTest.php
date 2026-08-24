<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Exception;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
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
}
