<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use App\Models\Server;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Kazaminosuke\ModManager\Enums\ProjectOperation;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Models\ModManagerServerSetting;
use Kazaminosuke\ModManager\Repositories\ServerModManagerSettingRepository;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;
use PHPUnit\Framework\TestCase;

final class ServerModManagerSettingsTest extends TestCase
{
    private static ?Capsule $capsule = null;

    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-minecraft-modrinth' => [
                'allow_user_egg_profile_edit' => true,
                'allow_user_project_install' => true,
                'allow_user_project_update' => false,
                'allow_user_project_delete' => false,
            ],
        ]));
        Container::setInstance($container);

        if (self::$capsule === null) {
            self::$capsule = new Capsule();
            self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
        }

        Capsule::schema()->dropIfExists('mod_manager_server_settings');
        Capsule::schema()->create('mod_manager_server_settings', function ($table): void {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->boolean('enabled')->default(true);
            $table->boolean('mod_enabled')->default(true);
            $table->boolean('plugin_enabled')->default(true);
            $table->boolean('datapack_enabled')->default(true);
            $table->integer('mod_navigation_sort')->nullable();
            $table->integer('plugin_navigation_sort')->nullable();
            $table->integer('datapack_navigation_sort')->nullable();
            $table->boolean('allow_user_egg_profile_edit')->nullable();
            $table->boolean('allow_user_project_install')->nullable();
            $table->boolean('allow_user_project_update')->nullable();
            $table->boolean('allow_user_project_delete')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function test_missing_row_is_fully_backward_compatible(): void
    {
        $settings = $this->settings();
        $server = $this->server(1);

        self::assertTrue($settings->isTypeEnabled($server, ProjectType::Mod));
        self::assertTrue($settings->isTypeEnabled($server, ProjectType::Plugin));
        self::assertTrue($settings->isTypeEnabled($server, ProjectType::Datapack));
        self::assertTrue($settings->allowsEggProfileEdit($server));
        self::assertTrue($settings->allowsProjectOperation($server, ProjectOperation::Install));
        self::assertFalse($settings->allowsProjectOperation($server, ProjectOperation::Update));
    }

    public function test_null_inherits_and_false_stays_false_when_global_is_true(): void
    {
        $server = $this->server(1);
        ModManagerServerSetting::query()->create([
            'server_id' => 1,
            'enabled' => true,
            'allow_user_project_install' => null,
            'allow_user_project_update' => false,
        ]);

        $settings = $this->settings();

        self::assertTrue($settings->allowsProjectOperation($server, ProjectOperation::Install));
        self::assertFalse($settings->allowsProjectOperation($server, ProjectOperation::Update));
        self::assertNull($settings->override($server, 'allow_user_project_install'));
        self::assertFalse($settings->override($server, 'allow_user_project_update'));
    }

    public function test_type_override_wins_and_save_updates_request_memo(): void
    {
        $server = $this->server(1);
        $repository = new ServerModManagerSettingRepository();
        $settings = new ServerModManagerSettings($repository);

        $saved = $repository->save($server, [
            'enabled' => false,
            'mod_enabled' => false,
            'allow_user_project_update' => true,
        ]);

        self::assertFalse($settings->isTypeEnabled($server, ProjectType::Mod));
        self::assertTrue($settings->isTypeEnabled($server, ProjectType::Plugin));
        self::assertTrue($settings->allowsProjectOperation($server, ProjectOperation::Update));
        self::assertSame($saved, $repository->forServer($server));

        $repository->clear();
        self::assertFalse($repository->forServer($server)->enabled);
    }

    public function test_type_switches_are_independent_and_navigation_null_inherits_global(): void
    {
        $server = $this->server(1);
        ModManagerServerSetting::query()->create([
            'server_id' => 1,
            'enabled' => true,
            'mod_enabled' => false,
            'plugin_enabled' => true,
            'datapack_enabled' => true,
            'mod_navigation_sort' => null,
            'plugin_navigation_sort' => 4,
            'datapack_navigation_sort' => 0,
        ]);

        Container::getInstance()->make('config')->set('pelican-minecraft-modrinth.navigation_sort', [
            'mod' => 10,
            'plugin' => 11,
            'datapack' => 12,
        ]);

        $settings = $this->settings();

        self::assertFalse($settings->isTypeEnabled($server, ProjectType::Mod));
        self::assertTrue($settings->isTypeEnabled($server, ProjectType::Plugin));
        self::assertTrue($settings->isTypeEnabled($server, ProjectType::Datapack));
        self::assertSame(10, $settings->navigationSort($server, ProjectType::Mod));
        self::assertSame(4, $settings->navigationSort($server, ProjectType::Plugin));
        self::assertSame(0, $settings->navigationSort($server, ProjectType::Datapack));
    }

    public function test_legacy_master_column_is_ignored_when_type_columns_are_enabled(): void
    {
        $server = $this->server(1);
        ModManagerServerSetting::query()->create([
            'server_id' => 1,
            'enabled' => false,
            'mod_enabled' => true,
            'plugin_enabled' => true,
            'datapack_enabled' => true,
        ]);

        $settings = $this->settings();

        self::assertTrue($settings->isTypeEnabled($server, ProjectType::Mod));
        self::assertTrue($settings->isTypeEnabled($server, ProjectType::Plugin));
        self::assertTrue($settings->isTypeEnabled($server, ProjectType::Datapack));
        self::assertTrue($settings->configuredTypeEnabled($server, ProjectType::Mod));
        self::assertTrue($settings->configuredTypeEnabled($server, ProjectType::Plugin));
        self::assertTrue($settings->configuredTypeEnabled($server, ProjectType::Datapack));
    }

    public function test_migration_copied_legacy_disable_is_respected_by_type_columns(): void
    {
        $server = $this->server(1);
        ModManagerServerSetting::query()->create([
            'server_id' => 1,
            'enabled' => false,
            'mod_enabled' => false,
            'plugin_enabled' => false,
            'datapack_enabled' => false,
        ]);

        $settings = $this->settings();

        self::assertFalse($settings->isTypeEnabled($server, ProjectType::Mod));
        self::assertFalse($settings->isTypeEnabled($server, ProjectType::Plugin));
        self::assertFalse($settings->isTypeEnabled($server, ProjectType::Datapack));
    }

    private function settings(): ServerModManagerSettings
    {
        return new ServerModManagerSettings(new ServerModManagerSettingRepository());
    }

    private function server(int $id): Server
    {
        $server = new Server();
        $server->id = $id;

        return $server;
    }
}
