<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\BulkUpdateInstalledProjects;
use Kazaminosuke\ModManager\Jobs\ScanInstalledProjects;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Mockery;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/src/Enums/ProjectType.php';
require_once dirname(__DIR__, 3).'/src/Support/InstalledOperationState.php';
require_once dirname(__DIR__, 3).'/src/Support/InstalledOperationLease.php';
require_once dirname(__DIR__, 3).'/src/Jobs/BulkUpdateInstalledProjects.php';
require_once dirname(__DIR__, 3).'/src/Jobs/ScanInstalledProjects.php';
require_once dirname(__DIR__, 3).'/src/Services/InstalledOperationManager.php';

class InstalledOperationManagerTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Mockery::close();

        parent::tearDown();
    }

    public function test_sync_queue_is_reported_without_dispatching_or_overwriting_state(): void
    {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->twice()->andReturnNull();
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->once()->with('queue.default', 'sync')->andReturn('sync');
        $result = (new InstalledOperationManager($cache, $config))
            ->dispatchScan(42, ProjectType::Mod, actorUserId: 7);

        self::assertFalse($result['dispatched']);
        self::assertSame('sync_queue', $result['reason']);
        self::assertNull($result['state']);
    }

    public function test_missing_scan_actor_is_reported_without_dispatching(): void
    {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->twice()->andReturnNull();
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldNotReceive('get');
        $result = (new InstalledOperationManager($cache, $config))
            ->dispatchScan(42, ProjectType::Mod);

        self::assertFalse($result['dispatched']);
        self::assertSame('missing_actor', $result['reason']);
        self::assertNull($result['state']);
    }

    public function test_async_queue_persists_queued_state_and_dispatches_job(): void
    {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->twice()->andReturnNull();
        $cache->shouldReceive('add')
            ->once()
            ->withArgs(fn (string $key, array $payload, int $ttl): bool => $key === 'mod_manager_op_lease:v1:42:mod'
                && $payload['operation'] === 'scan'
                && is_string($payload['token'] ?? null)
                && $ttl === 1200)
            ->andReturnTrue();
        $cache->shouldReceive('put')
            ->once()
            ->withArgs(fn (string $key, array $payload): bool => $key === 'mod_manager_operation:v1:42:mod:scan'
                && $payload['status'] === InstalledOperationState::STATUS_QUEUED
                && $payload['result']['force'] === true
                && $payload['result']['actor_user_id'] === 7);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->once()->with('queue.default', 'sync')->andReturn('database');
        $dispatcher = $this->bindDispatcher($cache);
        $this->expectUniqueLock($cache, 'mod-manager:scan:42:mod', 600);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (ScanInstalledProjects $job): bool => $job->serverId === 42
                && $job->projectType === ProjectType::Mod->value
                && $job->force
                && $job->actorUserId === 7)
            ->andReturn(1);

        $result = (new InstalledOperationManager($cache, $config))
            ->dispatchScan(42, ProjectType::Mod, force: true, actorUserId: 7);

        self::assertTrue($result['dispatched']);
        self::assertNull($result['reason']);
        self::assertSame(InstalledOperationState::STATUS_QUEUED, $result['state']->status);
    }

    public function test_async_bulk_update_persists_queued_state_and_dispatches_job(): void
    {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->twice()->andReturnNull();
        $cache->shouldReceive('add')
            ->once()
            ->withArgs(fn (string $key, array $payload, int $ttl): bool => $key === 'mod_manager_op_lease:v1:42:mod'
                && $payload['operation'] === 'bulk_update'
                && is_string($payload['token'] ?? null)
                && $ttl === 1200)
            ->andReturnTrue();
        $cache->shouldReceive('put')
            ->once()
            ->withArgs(fn (string $key, array $payload): bool => $key === 'mod_manager_operation:v1:42:mod:bulk_update'
                && $payload['operation'] === InstalledOperationManager::OPERATION_BULK_UPDATE
                && $payload['status'] === InstalledOperationState::STATUS_QUEUED);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->once()->with('queue.default', 'sync')->andReturn('database');
        $dispatcher = $this->bindDispatcher($cache);
        $this->expectUniqueLock($cache, 'mod-manager:bulk-update:42:mod', 1200);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (BulkUpdateInstalledProjects $job): bool => $job->serverId === 42
                && $job->projectType === ProjectType::Mod->value)
            ->andReturn(1);

        $result = (new InstalledOperationManager($cache, $config))
            ->dispatchBulkUpdate(42, ProjectType::Mod);

        self::assertTrue($result['dispatched']);
        self::assertNull($result['reason']);
        self::assertSame(InstalledOperationManager::OPERATION_BULK_UPDATE, $result['state']->operation);
        self::assertSame(InstalledOperationState::STATUS_QUEUED, $result['state']->status);
    }

    public function test_active_bulk_update_is_not_dispatched_twice(): void
    {
        $activeState = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_BULK_UPDATE,
            42,
            ProjectType::Mod,
        );
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->once()->andReturn($activeState->toCachePayload());
        $cache->shouldNotReceive('put');
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldNotReceive('get');
        $result = (new InstalledOperationManager($cache, $config))
            ->dispatchBulkUpdate(42, ProjectType::Mod);

        self::assertFalse($result['dispatched']);
        self::assertSame('already_active', $result['reason']);
        self::assertSame(InstalledOperationManager::OPERATION_BULK_UPDATE, $result['state']->operation);
        self::assertTrue($result['state']->isActive());
    }

    public function test_active_scan_prevents_a_bulk_update_from_being_dispatched(): void
    {
        $activeState = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Mod,
        );
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')
            ->twice()
            ->andReturn(null, $activeState->toCachePayload());
        $cache->shouldNotReceive('put');
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldNotReceive('get');
        $result = (new InstalledOperationManager($cache, $config))
            ->dispatchBulkUpdate(42, ProjectType::Mod);

        self::assertFalse($result['dispatched']);
        self::assertSame('already_active', $result['reason']);
        self::assertSame(InstalledOperationManager::OPERATION_SCAN, $result['state']->operation);
        self::assertTrue($result['state']->isActive());
    }

    public function test_held_operation_lease_blocks_dispatch_without_active_state(): void
    {
        $store = new ArrayStore();
        $cache = new Repository($store);
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->once()->with('queue.default', 'sync')->andReturn('database');
        $leases = new InstalledOperationLease($cache);
        self::assertNotNull($leases->tryAcquire(42, ProjectType::Mod, InstalledOperationLease::OPERATION_INSTALL));

        $result = (new InstalledOperationManager($cache, $config, $leases))
            ->dispatchBulkUpdate(42, ProjectType::Mod);

        self::assertFalse($result['dispatched']);
        self::assertSame('already_active', $result['reason']);
        self::assertTrue($leases->isHeld(42, ProjectType::Mod));
    }

    public function test_completed_operation_releases_the_lease(): void
    {
        $store = new ArrayStore();
        $cache = new Repository($store);
        $config = Mockery::mock(ConfigRepository::class);
        $leases = new InstalledOperationLease($cache);
        self::assertNotNull($leases->tryAcquire(42, ProjectType::Mod, InstalledOperationLease::OPERATION_SCAN));

        (new InstalledOperationManager($cache, $config, $leases))
            ->complete(42, ProjectType::Mod, InstalledOperationManager::OPERATION_SCAN);

        self::assertFalse($leases->isHeld(42, ProjectType::Mod));
    }

    public function test_progress_persists_the_running_state_in_one_cache_write(): void
    {
        $queued = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_BULK_UPDATE,
            42,
            ProjectType::Mod,
        );
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')
            ->once()
            ->with('mod_manager_operation:v1:42:mod:bulk_update')
            ->andReturn($queued->toCachePayload());
        $cache->shouldReceive('put')
            ->once()
            ->withArgs(function (string $key, array $payload): bool {
                self::assertSame('mod_manager_operation:v1:42:mod:bulk_update', $key);
                self::assertSame(InstalledOperationState::STATUS_RUNNING, $payload['status']);
                self::assertSame(3, $payload['progress']);
                self::assertSame(10, $payload['total']);
                self::assertNotNull($payload['started_at']);

                return true;
            });
        $config = Mockery::mock(ConfigRepository::class);

        $state = (new InstalledOperationManager($cache, $config))->progress(
            42,
            ProjectType::Mod,
            InstalledOperationManager::OPERATION_BULK_UPDATE,
            3,
            10,
        );

        self::assertSame(InstalledOperationState::STATUS_RUNNING, $state->status);
        self::assertSame(3, $state->progress);
        self::assertSame(10, $state->total);
    }

    public function test_repeated_progress_coalesces_cache_writes_and_flushes_the_final_state(): void
    {
        $store = new class extends ArrayStore
        {
            public int $getCalls = 0;

            public int $putCalls = 0;

            public function get($key)
            {
                $this->getCalls++;

                return parent::get($key);
            }

            public function put($key, $value, $seconds)
            {
                $this->putCalls++;

                return parent::put($key, $value, $seconds);
            }
        };
        $cache = new Repository($store);
        $config = Mockery::mock(ConfigRepository::class);
        $manager = new InstalledOperationManager($cache, $config);

        for ($progress = 1; $progress <= 500; $progress++) {
            $manager->progress(42, ProjectType::Mod, InstalledOperationManager::OPERATION_BULK_UPDATE, $progress, 500);
        }

        $completed = $manager->complete(42, ProjectType::Mod, InstalledOperationManager::OPERATION_BULK_UPDATE, [
            'updated' => 500,
        ]);

        self::assertSame(1, $store->getCalls);
        self::assertSame(2, $store->putCalls);
        self::assertSame(InstalledOperationState::STATUS_COMPLETED, $completed->status);
        self::assertSame(500, $completed->progress);
        self::assertSame(500, $completed->total);
        self::assertSame(['updated' => 500], $completed->result);
    }

    public function test_fail_uses_buffered_progress_without_an_extra_cache_read(): void
    {
        $store = new class extends ArrayStore
        {
            public int $getCalls = 0;

            public int $putCalls = 0;

            public function get($key)
            {
                $this->getCalls++;

                return parent::get($key);
            }

            public function put($key, $value, $seconds)
            {
                $this->putCalls++;

                return parent::put($key, $value, $seconds);
            }
        };
        $cache = new Repository($store);
        $config = Mockery::mock(ConfigRepository::class);
        $manager = new InstalledOperationManager($cache, $config);

        $manager->progress(42, ProjectType::Mod, InstalledOperationManager::OPERATION_BULK_UPDATE, 17, 100);
        $manager->progress(42, ProjectType::Mod, InstalledOperationManager::OPERATION_BULK_UPDATE, 18, 100);
        $failed = $manager->fail(
            42,
            ProjectType::Mod,
            InstalledOperationManager::OPERATION_BULK_UPDATE,
            'bulk_update_exception',
        );

        self::assertSame(1, $store->getCalls);
        self::assertSame(2, $store->putCalls);
        self::assertSame(InstalledOperationState::STATUS_FAILED, $failed->status);
        self::assertSame(18, $failed->progress);
        self::assertSame('bulk_update_exception', $failed->error);
    }

    public function test_installed_tab_snapshot_reads_scan_and_operation_keys_together(): void
    {
        $store = new class extends ArrayStore
        {
            public int $manyCalls = 0;

            public function many(array $keys)
            {
                $this->manyCalls++;

                return parent::many($keys);
            }
        };
        $cache = new Repository($store);
        $config = Mockery::mock(ConfigRepository::class);
        $scanState = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Mod,
        );
        $cache->put('mod_manager_operation:v1:42:mod:scan', $scanState->toCachePayload(), 60);
        $cache->put('mod_manager_hash_scan:42:mod', ['successful' => true], 60);

        $snapshot = (new InstalledOperationManager($cache, $config))->installedTabCacheSnapshot(
            42,
            ProjectType::Mod,
            'mod_manager_hash_scan:42:mod',
        );

        self::assertSame(1, $store->manyCalls);
        self::assertSame(['successful' => true], $snapshot['scan_result']);
        self::assertSame(InstalledOperationManager::OPERATION_SCAN, $snapshot['scan']?->operation);
        self::assertNull($snapshot['bulk']);
    }

    private function bindDispatcher(CacheRepository $cache): Dispatcher
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $context = Mockery::mock(ContextRepository::class);
        $exceptionHandler = Mockery::mock(ExceptionHandler::class);
        $context->shouldReceive('addHidden')->zeroOrMoreTimes();
        $context->shouldReceive('forgetHidden')->zeroOrMoreTimes();
        $exceptionHandler->shouldReceive('report')->zeroOrMoreTimes();
        $dispatchConfig = new LaravelConfigRepository([
            'cache' => ['default' => 'array'],
        ]);
        $container = new Container();
        $container->instance(CacheRepository::class, $cache);
        $container->instance(ConfigRepository::class, $dispatchConfig);
        $container->instance('config', $dispatchConfig);
        $container->instance(Dispatcher::class, $dispatcher);
        $container->instance(ContextRepository::class, $context);
        $container->instance(ExceptionHandler::class, $exceptionHandler);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        return $dispatcher;
    }

    private function expectUniqueLock(CacheRepository $cache, string $uniqueId, int $uniqueFor): void
    {
        $lock = Mockery::mock();
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $cache->shouldReceive('lock')
            ->once()
            ->withArgs(static fn (mixed $key, mixed $seconds): bool => is_string($key)
                && str_ends_with($key, ':'.$uniqueId)
                && $seconds === $uniqueFor)
            ->andReturn($lock);
        // Laravel 13 UniqueLock::acquire() calls getStore() after the lock
        // is taken so it can record uniqueLockOwner on LockProvider stores.
        // A Mockery repository is not a LockProvider; returning null keeps
        // dispatch on the mocked Dispatcher instead of throwing into the
        // manager's dispatch_failed path.
        $cache->shouldReceive('getStore')->zeroOrMoreTimes()->andReturnNull();
    }
}
