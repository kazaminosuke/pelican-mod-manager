<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
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
}
