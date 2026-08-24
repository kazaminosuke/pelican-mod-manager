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
use Kazaminosuke\ModManager\Jobs\ResetInstalledMetadata;
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
require_once dirname(__DIR__, 3).'/src/Jobs/ResetInstalledMetadata.php';
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

    public function test_resource_pack_never_dispatches_an_installed_archive_scan(): void
    {
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldNotReceive('get', 'add', 'put');
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldNotReceive('get');

        $result = (new InstalledOperationManager($cache, $config))->dispatchScan(
            42,
            ProjectType::ResourcePack,
            actorUserId: 7,
        );

        self::assertFalse($result['dispatched']);
        self::assertSame('unsupported_type', $result['reason']);
        self::assertNull($result['state']);
    }

    public function test_async_queue_persists_queued_state_and_dispatches_job(): void
    {
        $leaseToken = null;
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->twice()->andReturnNull();
        $cache->shouldReceive('add')
            ->once()
            ->withArgs(function (string $key, array $payload, int $ttl) use (&$leaseToken): bool {
                $leaseToken = $payload['token'] ?? null;

                return $key === 'mod_manager_op_lease:v1:42:mod'
                    && $payload['operation'] === 'scan'
                    && is_string($leaseToken)
                    && $ttl === 1200;
            })
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
            ->withArgs(function (ScanInstalledProjects $job) use (&$leaseToken): bool {
                return $job->serverId === 42
                    && $job->projectType === ProjectType::Mod->value
                    && $job->leaseToken === $leaseToken
                    && $job->force
                    && $job->actorUserId === 7;
            })
            ->andReturn(1);

        $result = (new InstalledOperationManager($cache, $config))
            ->dispatchScan(42, ProjectType::Mod, force: true, actorUserId: 7);

        self::assertTrue($result['dispatched']);
        self::assertNull($result['reason']);
        self::assertSame(InstalledOperationState::STATUS_QUEUED, $result['state']->status);
    }

    public function test_async_bulk_update_persists_queued_state_and_dispatches_job(): void
    {
        $leaseToken = null;
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->twice()->andReturnNull();
        $cache->shouldReceive('add')
            ->once()
            ->withArgs(function (string $key, array $payload, int $ttl) use (&$leaseToken): bool {
                $leaseToken = $payload['token'] ?? null;

                return $key === 'mod_manager_op_lease:v1:42:mod'
                    && $payload['operation'] === 'bulk_update'
                    && is_string($leaseToken)
                    && $ttl === 1200;
            })
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
            ->withArgs(function (BulkUpdateInstalledProjects $job) use (&$leaseToken): bool {
                return $job->serverId === 42
                    && $job->projectType === ProjectType::Mod->value
                    && $job->leaseToken === $leaseToken;
            })
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
        $cache = new Repository(new ArrayStore());
        $leases = new InstalledOperationLease($cache);
        self::assertNotNull($leases->tryAcquire(
            42,
            ProjectType::Mod,
            InstalledOperationLease::OPERATION_BULK_UPDATE,
        ));
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldNotReceive('get');
        $manager = new InstalledOperationManager($cache, $config, $leases);
        $manager->queue(42, ProjectType::Mod, InstalledOperationManager::OPERATION_BULK_UPDATE);
        $result = $manager->dispatchBulkUpdate(42, ProjectType::Mod);

        self::assertFalse($result['dispatched']);
        self::assertSame('already_active', $result['reason']);
        self::assertSame(InstalledOperationManager::OPERATION_BULK_UPDATE, $result['state']->operation);
        self::assertTrue($result['state']->isActive());
    }

    public function test_active_scan_prevents_a_bulk_update_from_being_dispatched(): void
    {
        $cache = new Repository(new ArrayStore());
        $leases = new InstalledOperationLease($cache);
        self::assertNotNull($leases->tryAcquire(
            42,
            ProjectType::Mod,
            InstalledOperationLease::OPERATION_SCAN,
        ));
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldNotReceive('get');
        $manager = new InstalledOperationManager($cache, $config, $leases);
        $manager->queue(42, ProjectType::Mod, InstalledOperationManager::OPERATION_SCAN);
        $result = $manager->dispatchBulkUpdate(42, ProjectType::Mod);

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
        $leaseToken = $leases->tryAcquire(42, ProjectType::Mod, InstalledOperationLease::OPERATION_SCAN);
        self::assertNotNull($leaseToken);

        (new InstalledOperationManager($cache, $config, $leases))
            ->complete(
                42,
                ProjectType::Mod,
                InstalledOperationManager::OPERATION_SCAN,
                leaseToken: $leaseToken,
            );

        self::assertFalse($leases->isHeld(42, ProjectType::Mod));
    }

    public function test_stale_completion_cannot_release_a_replacement_owner_lease(): void
    {
        $cache = new Repository(new ArrayStore());
        $config = Mockery::mock(ConfigRepository::class);
        $leases = new InstalledOperationLease($cache);
        $staleToken = $leases->tryAcquire(42, ProjectType::Mod, InstalledOperationLease::OPERATION_SCAN);
        self::assertNotNull($staleToken);
        $cache->forget(InstalledOperationLease::key(42, ProjectType::Mod));
        $replacementToken = $leases->tryAcquire(42, ProjectType::Mod, InstalledOperationLease::OPERATION_UPDATE);
        self::assertNotNull($replacementToken);

        $manager = new InstalledOperationManager($cache, $config, $leases);
        $manager->complete(
            42,
            ProjectType::Mod,
            InstalledOperationManager::OPERATION_SCAN,
            leaseToken: $staleToken,
        );

        self::assertTrue($leases->owns(42, ProjectType::Mod, $replacementToken));
        self::assertSame(InstalledOperationLease::OPERATION_UPDATE, $leases->currentOperation(42, ProjectType::Mod));
        self::assertNull($manager->state(42, ProjectType::Mod, InstalledOperationManager::OPERATION_SCAN));
    }

    public function test_terminal_state_cache_failure_still_releases_the_exact_owner_lease(): void
    {
        $store = new class extends ArrayStore
        {
            public bool $rejectPuts = false;

            public function put($key, $value, $seconds)
            {
                if ($this->rejectPuts) {
                    throw new \RuntimeException('cache unavailable');
                }

                return parent::put($key, $value, $seconds);
            }
        };
        $cache = new Repository($store);
        $leases = new InstalledOperationLease($cache);
        $token = $leases->tryAcquire(42, ProjectType::Mod, InstalledOperationLease::OPERATION_SCAN);
        self::assertNotNull($token);
        $store->rejectPuts = true;

        try {
            (new InstalledOperationManager($cache, Mockery::mock(ConfigRepository::class), $leases))->complete(
                42,
                ProjectType::Mod,
                InstalledOperationManager::OPERATION_SCAN,
                leaseToken: $token,
            );
            self::fail('The state cache failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('cache unavailable', $exception->getMessage());
        }

        self::assertFalse($leases->isHeld(42, ProjectType::Mod));
    }

    public function test_metadata_reset_acquires_all_type_leases_or_rolls_back_every_new_claim(): void
    {
        $cache = new Repository(new ArrayStore());
        $config = new LaravelConfigRepository(['queue' => ['default' => 'database']]);
        $leases = new InstalledOperationLease($cache);
        $existingModToken = $leases->tryAcquire(42, ProjectType::Mod, InstalledOperationLease::OPERATION_INSTALL);
        self::assertNotNull($existingModToken);

        $result = (new InstalledOperationManager($cache, $config, $leases))->dispatchMetadataReset(
            42,
            [ProjectType::Mod, ProjectType::Datapack],
            actorUserId: 7,
        );

        self::assertFalse($result['dispatched']);
        self::assertSame('already_active', $result['reason']);
        self::assertSame([], $result['states']);
        self::assertFalse($leases->isHeld(42, ProjectType::Datapack));
        self::assertTrue($leases->owns(42, ProjectType::Mod, $existingModToken));
    }

    public function test_metadata_reset_dispatches_one_job_with_every_exact_lease_token(): void
    {
        $cache = new Repository(new ArrayStore());
        $config = new LaravelConfigRepository(['queue' => ['default' => 'database']]);
        $leases = new InstalledOperationLease($cache);
        $dispatcher = $this->bindDispatcher($cache);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (ResetInstalledMetadata $job) use ($leases): bool {
                return $job->serverId === 42
                    && $job->projectTypes === ['datapack', 'mod']
                    && $job->actorUserId === 7
                    && $leases->owns(42, ProjectType::Datapack, $job->leaseTokens['datapack'])
                    && $leases->owns(42, ProjectType::Mod, $job->leaseTokens['mod']);
            })
            ->andReturn(1);

        $result = (new InstalledOperationManager($cache, $config, $leases))->dispatchMetadataReset(
            42,
            [ProjectType::Mod, ProjectType::Datapack],
            actorUserId: 7,
        );

        self::assertTrue($result['dispatched']);
        self::assertNull($result['reason']);
        self::assertSame(['datapack', 'mod'], array_keys($result['states']));
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
        $leases = new InstalledOperationLease($cache);
        self::assertNotNull($leases->tryAcquire(
            42,
            ProjectType::Mod,
            InstalledOperationLease::OPERATION_SCAN,
        ));
        $scanState = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Mod,
        );
        $cache->put('mod_manager_operation:v1:42:mod:scan', $scanState->toCachePayload(), 60);
        $cache->put('mod_manager_hash_scan:42:mod', ['successful' => true], 60);

        $snapshot = (new InstalledOperationManager($cache, $config, $leases))->installedTabCacheSnapshot(
            42,
            ProjectType::Mod,
            'mod_manager_hash_scan:42:mod',
        );

        self::assertSame(1, $store->manyCalls);
        self::assertSame(['successful' => true], $snapshot['scan_result']);
        self::assertSame(InstalledOperationManager::OPERATION_SCAN, $snapshot['scan']?->operation);
        self::assertNull($snapshot['bulk']);
    }

    public function test_snapshot_does_not_display_an_orphan_scan_during_a_bulk_lease(): void
    {
        $cache = new Repository(new ArrayStore());
        $leases = new InstalledOperationLease($cache);
        $scanState = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Mod,
        );
        $bulkState = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_BULK_UPDATE,
            42,
            ProjectType::Mod,
        );
        $cache->put('mod_manager_operation:v1:42:mod:scan', $scanState->toCachePayload(), 60);
        $cache->put('mod_manager_operation:v1:42:mod:bulk_update', $bulkState->toCachePayload(), 60);
        self::assertNotNull($leases->tryAcquire(
            42,
            ProjectType::Mod,
            InstalledOperationLease::OPERATION_BULK_UPDATE,
        ));

        $snapshot = (new InstalledOperationManager(
            $cache,
            Mockery::mock(ConfigRepository::class),
            $leases,
        ))->installedTabCacheSnapshot(42, ProjectType::Mod, 'scan-result');

        self::assertNull($snapshot['scan']);
        self::assertSame(InstalledOperationManager::OPERATION_BULK_UPDATE, $snapshot['bulk']?->operation);
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
