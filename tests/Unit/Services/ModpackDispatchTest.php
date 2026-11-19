<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ModpackDispatchTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_modpack_install_is_queued_only_for_a_mod_server(): void
    {
        $cache = new Repository(new ArrayStore());
        $runner = PluginBackgroundRunner::fake();
        $manager = new InstalledOperationManager(
            $cache,
            Mockery::mock(ConfigRepository::class),
            new InstalledOperationLease($cache),
            $runner,
        );

        $plugin = $manager->dispatchModpackInstall(42, ProjectType::Plugin, 'modrinth', 'abc');
        self::assertFalse($plugin['dispatched']);
        self::assertSame('unsupported_type', $plugin['reason']);
        self::assertSame([], $runner->spawned);

        $mod = $manager->dispatchModpackInstall(42, ProjectType::Mod, 'modrinth', 'abc');

        self::assertTrue($mod['dispatched']);
        self::assertSame(BackgroundJob::MODPACK, $runner->spawned[0]['type']);
        self::assertSame('modrinth', $runner->spawned[0]['payload']['source']);
        self::assertSame('abc', $runner->spawned[0]['payload']['project_id']);
        self::assertSame(InstalledOperationLease::OPERATION_MODPACK, (new InstalledOperationLease($cache))->currentOperation(42, ProjectType::Mod));
    }

    public function test_waiting_pack_releases_lease_and_only_resumes_its_original_release(): void
    {
        $cache = new Repository(new ArrayStore());
        $runner = PluginBackgroundRunner::fake();
        $leases = new InstalledOperationLease($cache);
        $manager = new InstalledOperationManager($cache, Mockery::mock(ConfigRepository::class), $leases, $runner);
        $manager->dispatchModpackInstall(42, ProjectType::Mod, 'curseforge', '285109', '4612979');
        $lease = $runner->spawned[0]['payload']['lease_token'];
        $manager->start(42, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
        $manager->modpackPhase(42, 'verifying');
        self::assertSame('verifying', $manager->state(42, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK)->result['phase']);
        $files = array_fill(0, 101, ['project_id' => 264231, 'file_id' => 2693547]);
        $manager->pauseCurseForgeModpack(42, $lease, '285109', '4612979', [
            'egg_id' => '9', 'artifact_id' => '4612979', 'archive_sha256' => str_repeat('a', 64),
        ], $files);

        $plan = $manager->waitingCurseForgeModpack(42);
        self::assertCount(101, $plan['files']);
        self::assertNull($leases->currentOperation(42, ProjectType::Mod));
        self::assertSame(InstalledOperationState::STATUS_WAITING_FILES, $manager->state(42, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK)->status);
        self::assertSame('manual_pending', $manager->dispatchModpackInstall(42, ProjectType::Mod, 'curseforge', '285109', 'different')['reason']);
        self::assertFalse($manager->resumeCurseForgeModpack(43, $plan['token']));
        self::assertFalse($manager->resumeCurseForgeModpack(42, 'other'));
        self::assertTrue($manager->resumeCurseForgeModpack(42, $plan['token']));
        self::assertFalse($manager->resumeCurseForgeModpack(42, $plan['token']));
        self::assertFalse($manager->cancelCurseForgeModpack(42, $plan['token']));
        self::assertCount(2, $runner->spawned);
        $resumed = $runner->spawned[1]['payload'];
        self::assertSame('285109', $resumed['project_id']);
        self::assertSame('4612979', $resumed['version_id']);
        self::assertSame($plan['selection'], $resumed['selection']);
        self::assertFalse($leases->owns(42, ProjectType::Mod, $lease));
        $manager->clearCurseForgeModpack(42, $lease);
        self::assertSame($plan, $manager->waitingCurseForgeModpack(42));
        $manager->clearCurseForgeModpack(42, $resumed['lease_token']);
        $manager->complete(42, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK, leaseToken: $resumed['lease_token']);
        self::assertNull($manager->waitingCurseForgeModpack(42));
        self::assertFalse($manager->resumeCurseForgeModpack(42, $plan['token']));
    }

    public function test_waiting_pack_can_be_cancelled_without_touching_another_servers_plan(): void
    {
        $cache = new Repository(new ArrayStore());
        $runner = PluginBackgroundRunner::fake();
        $manager = new InstalledOperationManager($cache, Mockery::mock(ConfigRepository::class), runner: $runner);
        $manager->dispatchModpackInstall(42, ProjectType::Mod, 'curseforge', '285109', '4612979');
        $manager->pauseCurseForgeModpack(42, $runner->spawned[0]['payload']['lease_token'], '285109', '4612979', [], []);
        $token = $manager->waitingCurseForgeModpack(42)['token'];

        self::assertFalse($manager->cancelCurseForgeModpack(43, $token));
        self::assertFalse($manager->cancelCurseForgeModpack(42, 'other'));
        self::assertTrue($manager->cancelCurseForgeModpack(42, $token));
        self::assertNull($manager->state(42, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK));
        self::assertTrue($manager->dispatchModpackInstall(42, ProjectType::Mod, 'curseforge', '285109', 'a-new-release')['dispatched']);
    }
}
