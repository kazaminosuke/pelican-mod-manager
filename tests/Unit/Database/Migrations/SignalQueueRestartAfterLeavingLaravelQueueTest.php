<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Database\Migrations;

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

class SignalQueueRestartAfterLeavingLaravelQueueTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_09_21_000003_signal_queue_restart_after_leaving_laravel_queue.php';

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

    public function test_up_broadcasts_queue_restart_and_down_does_not(): void
    {
        $kernel = Mockery::mock(ConsoleKernel::class);
        $kernel->shouldReceive('call')
            ->once()
            ->withArgs(fn (string $command): bool => $command === 'queue:restart')
            ->andReturn(0);

        $container = new Container();
        $container->instance(ConsoleKernel::class, $kernel);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        $migration = require dirname(__DIR__, 4).'/'.self::MIGRATION;

        $migration->up();
        $migration->down();
        $migration->down();
    }

    public function test_migration_does_not_kill_workers_or_requeue_mod_manager_jobs(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 4).'/'.self::MIGRATION);

        self::assertIsString($contents);
        self::assertStringContainsString("Artisan::call('queue:restart')", $contents);
        self::assertStringNotContainsString('posix_kill', $contents);
        self::assertStringNotContainsString('SIGTERM', $contents);
        self::assertStringNotContainsString('ShouldQueue', $contents);
        self::assertStringNotContainsString('ScanInstalledProjects::dispatch', $contents);
        self::assertStringNotContainsString('mod-manager:run-job', $contents);
    }
}
