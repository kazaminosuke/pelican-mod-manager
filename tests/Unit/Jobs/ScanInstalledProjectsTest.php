<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Jobs;

use App\Repositories\Daemon\DaemonFileRepository;
use DateTimeImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\ScanInstalledProjects;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Support\ProjectOperationAuthorizer;
use Mockery;
use PHPUnit\Framework\TestCase;

class ScanInstalledProjectsTest extends TestCase
{
    private Repository $cache;

    private InstalledOperationLease $leases;

    private PluginBackgroundRunner $runner;

    private InstalledOperationManager $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new Repository(new ArrayStore());
        $this->leases = new InstalledOperationLease($this->cache);
        $this->runner = PluginBackgroundRunner::fake();
        $this->operations = new InstalledOperationManager(
            $this->cache,
            Mockery::mock(ConfigRepository::class),
            $this->leases,
            $this->runner,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_busy_lease_requeues_the_scan_without_sleeping_or_scanning(): void
    {
        $job = $this->dispatchedJob();
        $installToken = $this->leases->tryAcquire(42, ProjectType::Mod, InstalledOperationLease::OPERATION_INSTALL);
        self::assertNotNull($installToken);

        $this->handle($job);

        self::assertSame(ScanInstalledProjects::BUSY_RETRY_SECONDS, $job->releaseAfterSeconds());
        $state = $this->operations->state(42, ProjectType::Mod, InstalledOperationManager::OPERATION_SCAN);
        self::assertSame(InstalledOperationState::STATUS_QUEUED, $state?->status);
        self::assertSame(7, $state?->result['actor_user_id']);
        // Still pending: another page load must not queue a duplicate scan.
        self::assertSame('already_active', $this->operations->dispatchScan(42, ProjectType::Mod, actorUserId: 7)['reason']);
        self::assertSame(InstalledOperationLease::OPERATION_INSTALL, $this->leases->currentOperation(42, ProjectType::Mod));
    }

    public function test_scan_that_waited_too_long_fails_and_frees_the_dispatch(): void
    {
        $job = $this->dispatchedJob();
        $this->cache->put(
            'mod_manager_operation:v1:42:mod:scan',
            InstalledOperationState::queued(
                InstalledOperationManager::OPERATION_SCAN,
                42,
                ProjectType::Mod,
                ['actor_user_id' => 7],
                new DateTimeImmutable('-'.(ScanInstalledProjects::BUSY_TIMEOUT_SECONDS + 60).' seconds'),
            )->toCachePayload(),
            600,
        );
        $installToken = $this->leases->tryAcquire(42, ProjectType::Mod, InstalledOperationLease::OPERATION_INSTALL);
        self::assertNotNull($installToken);

        $this->handle($job);

        self::assertNull($job->releaseAfterSeconds());
        $state = $this->operations->state(42, ProjectType::Mod, InstalledOperationManager::OPERATION_SCAN);
        self::assertSame(InstalledOperationState::STATUS_FAILED, $state?->status);
        self::assertSame('scan_busy_timeout', $state?->error);
        // The install keeps its lease; the scan never took it.
        self::assertTrue($this->leases->owns(42, ProjectType::Mod, $installToken));

        $this->leases->release(42, ProjectType::Mod, $installToken);
        self::assertTrue($this->operations->dispatchScan(42, ProjectType::Mod, actorUserId: 7)['dispatched']);
    }

    public function test_superseded_dispatch_does_no_work(): void
    {
        $job = new ScanInstalledProjects(
            serverId: 42,
            projectType: ProjectType::Mod->value,
            actorUserId: 7,
            dispatchToken: 'not-the-current-dispatch',
        );

        $this->handle($job);

        self::assertNull($job->releaseAfterSeconds());
        self::assertFalse($this->leases->isHeld(42, ProjectType::Mod));
        self::assertNull($this->operations->state(42, ProjectType::Mod, InstalledOperationManager::OPERATION_SCAN));
    }

    private function dispatchedJob(): ScanInstalledProjects
    {
        $dispatch = $this->operations->dispatchScan(42, ProjectType::Mod, actorUserId: 7);
        self::assertTrue($dispatch['dispatched']);
        $payload = $this->runner->spawned[array_key_last($this->runner->spawned)]['payload'];

        return new ScanInstalledProjects(
            serverId: $payload['server_id'],
            projectType: $payload['project_type'],
            force: $payload['force'],
            actorUserId: $payload['actor_user_id'],
            dispatchToken: $payload['dispatch_token'],
        );
    }

    private function handle(ScanInstalledProjects $job): void
    {
        $service = Mockery::mock(InstalledProjectService::class);
        $service->shouldNotReceive('scanAndImportModsResult');

        $job->handle(
            Mockery::mock(DaemonFileRepository::class),
            $service,
            $this->operations,
            $this->leases,
            new ProjectOperationAuthorizer(),
        );
    }
}
