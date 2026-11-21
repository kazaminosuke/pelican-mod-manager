<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Enums\ContainerStatus;
use App\Models\Backup;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Backups\InitiateBackupService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\ModpackUpdateWorkflow;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ModpackUpdateWorkflowTest extends TestCase
{
    private const FULL_BACKUP = ['# Full modpack update backup'];

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_failed_backup_and_unconfirmed_switch_block_apply_and_retry_keeps_release(): void
    {
        [$workflow, $operations, $leases, $runner] = $this->setupWorkflow(ContainerStatus::Offline);
        $server = $this->server();
        $lease = $leases->tryAcquire(43, ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK);
        $release = ['provider' => 'curseforge', 'pack_id' => '50', 'version_id' => '77', 'switch' => true];
        $workflow->publish($server, $lease, $release, ['egg_id' => '8'], ['digest' => 'preview', 'mod_conflicts' => []]);
        $token = $workflow->review($server)['token'];
        self::assertNull($leases->currentOperation(43, ProjectType::Mod));
        $backup = (new Backup())->setDateFormat('Y-m-d H:i:s');
        $backup->forceFill(['id' => 9, 'created_at' => date('Y-m-d H:i:s'), 'completed_at' => date('Y-m-d H:i:s'), 'is_successful' => false, 'ignored_files' => self::FULL_BACKUP]);
        $replacement = (new Backup())->setDateFormat('Y-m-d H:i:s');
        $replacement->forceFill(['id' => 10, 'created_at' => date('Y-m-d H:i:s'), 'completed_at' => date('Y-m-d H:i:s'), 'is_successful' => true, 'ignored_files' => self::FULL_BACKUP]);
        $server->backups = [9 => $backup, 10 => $replacement];
        $panel = Mockery::mock(InitiateBackupService::class);
        $panel->shouldReceive('setIgnoredFiles')->with(self::FULL_BACKUP)->twice()->andReturnSelf();
        $panel->shouldReceive('setIsLocked')->with(false)->twice()->andReturnSelf();
        $panel->shouldReceive('handle')->with($server, 'Modpack update')->twice()->andReturn($backup, $replacement);
        $workflow->createBackup($server, $token, $panel);

        foreach ([[true, 'full Pelican backup'], [false, 'Explicit confirmation']] as $index => [$switch, $reason]) {
            if ($index === 1) {
                $workflow->createBackup($server, $token, $panel);
            }
            try {
                $workflow->approve($server, $token, $switch, null);
                self::fail('An unchecked update must not be queued.');
            } catch (ModpackException $error) {
                self::assertStringContainsString($reason, $error->getMessage());
            }
            self::assertSame([], $runner->spawned);
        }

        $workflow->approve($server, $token, true, null);
        $payload = $runner->spawned[0]['payload'];
        self::assertSame('77', $payload['version_id']);
        self::assertSame('50', $payload['project_id']);
        self::assertSame('apply', $payload['selection']['managed_phase']);
        self::assertNull($payload['selection']['start_user']);
        $operations->fail($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK, 'temporary', leaseToken: $payload['lease_token']);
        $workflow->approve($server, $token, true, null);
        self::assertSame($payload['version_id'], $runner->spawned[1]['payload']['version_id']);
    }

    public function test_cancelled_preview_cannot_be_applied(): void
    {
        [$workflow, , $leases, $runner] = $this->setupWorkflow(ContainerStatus::Offline);
        $server = $this->server();
        $lease = $leases->tryAcquire(43, ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK);
        $workflow->publish($server, $lease, [], [], []);
        $token = $workflow->review($server)['token'];
        $workflow->cancel($server, $token);
        self::assertNull($workflow->review($server));
        self::assertSame([], $runner->spawned);
        $this->expectExceptionMessage('preview expired');
        $workflow->approve($server, $token, false, null);
    }

    public function test_successful_backup_using_wings_default_exclusions_is_not_accepted(): void
    {
        [$workflow, , $leases, $runner] = $this->setupWorkflow(ContainerStatus::Offline);
        $server = $this->server();
        $lease = $leases->tryAcquire(43, ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK);
        $workflow->publish($server, $lease, ['switch' => false], [], ['mod_conflicts' => []]);
        $token = $workflow->review($server)['token'];
        $backup = (new Backup())->setDateFormat('Y-m-d H:i:s');
        $backup->forceFill(['id' => 9, 'created_at' => date('Y-m-d H:i:s'), 'completed_at' => date('Y-m-d H:i:s'), 'is_successful' => true, 'ignored_files' => self::FULL_BACKUP]);
        $server->backups = [9 => $backup];
        $panel = Mockery::mock(InitiateBackupService::class);
        $panel->shouldReceive('setIgnoredFiles')->with(self::FULL_BACKUP)->once()->andReturnSelf();
        $panel->shouldReceive('setIsLocked')->with(false)->once()->andReturnSelf();
        $panel->shouldReceive('handle')->with($server, 'Modpack update')->once()->andReturn($backup);
        $workflow->createBackup($server, $token, $panel);
        $backup->ignored_files = [];

        try {
            $workflow->approve($server, $token, false, null);
            self::fail('A backup that can inherit Wings exclusions must not be used.');
        } catch (ModpackException $error) {
            self::assertStringContainsString('full Pelican backup', $error->getMessage());
        }
        self::assertSame([], $runner->spawned);
    }

    public function test_running_server_cannot_begin_a_backup(): void
    {
        [$workflow, , $leases] = $this->setupWorkflow(ContainerStatus::Starting);
        $server = $this->server();
        $lease = $leases->tryAcquire(43, ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK);
        $workflow->publish($server, $lease, [], [], []);
        $panel = Mockery::mock(InitiateBackupService::class);
        $panel->shouldNotReceive('handle');
        $this->expectExceptionMessage('wait for Wings to confirm');
        $workflow->createBackup($server, $workflow->review($server)['token'], $panel);
    }

    public function test_extend_ttl_keeps_approved_review_alive_through_seven_day_manual_wait(): void
    {
        Carbon::setTestNow('2026-11-20 00:00:00');
        try {
            [$workflow, , $leases] = $this->setupWorkflow(ContainerStatus::Offline);
            $server = $this->server();
            $lease = $leases->tryAcquire(43, ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK);
            $workflow->publish($server, $lease, [], [], []);
            $token = $workflow->review($server)['token'];

            $workflow->extendTtl($server, $token, 7 * 86400);
            Carbon::setTestNow('2026-11-23 00:00:00');

            $review = $workflow->review($server);
            self::assertIsArray($review);
            self::assertSame($token, $review['token']);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function setupWorkflow(ContainerStatus $status): array
    {
        $cache = new Repository(new ArrayStore());
        $leases = new InstalledOperationLease($cache);
        $runner = PluginBackgroundRunner::fake();
        $operations = new InstalledOperationManager($cache, Mockery::mock(ConfigRepository::class), $leases, $runner);
        $daemon = Mockery::mock(DaemonServerRepository::class);
        $daemon->shouldReceive('setServer')->andReturnSelf();
        $daemon->shouldReceive('getState')->with(5)->andReturn($status);

        return [new ModpackUpdateWorkflow($cache, $daemon, $operations, $leases), $operations, $leases, $runner];
    }

    private function server(): Server
    {
        return (new class extends Server
        {
            public array $backups = [];

            public function fresh($with = [])
            {
                return $this;
            }

            public function backups(): HasMany
            {
                $relation = Mockery::mock(HasMany::class);
                $relation->shouldReceive('find')->andReturnUsing(fn (int $id): ?Backup => $this->backups[$id] ?? null);

                return $relation;
            }
        })->forceFill(['id' => 43, 'uuid' => 'server-43', 'status' => null]);
    }
}
