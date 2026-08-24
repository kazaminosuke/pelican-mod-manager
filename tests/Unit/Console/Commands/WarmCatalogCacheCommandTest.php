<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Console\Commands;

use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Console\Commands\WarmCatalogCacheCommand;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Repositories\ServerModManagerSettingRepository;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class WarmCatalogCacheCommandTest extends TestCase
{
    private static ?Capsule $capsule = null;

    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-mod-manager' => [
                'egg_autodetect_enabled' => true,
                'latest_minecraft_version' => '26.1.2',
            ],
        ]));
        Container::setInstance($container);

        if (self::$capsule === null) {
            self::$capsule = new Capsule();
            self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
        }

        $container->instance('db', self::$capsule->getDatabaseManager());
        Facade::setFacadeApplication($container);

        Capsule::schema()->dropIfExists('server_variables');
        Capsule::schema()->dropIfExists('egg_variables');
        Capsule::schema()->dropIfExists('mod_manager_server_settings');
        Capsule::schema()->dropIfExists('servers');
        Capsule::schema()->dropIfExists('eggs');
        Capsule::schema()->create('eggs', function ($table): void {
            $table->id();
            $table->string('uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('update_url')->nullable();
            $table->text('features')->nullable();
            $table->text('tags')->nullable();
        });
        Capsule::schema()->create('servers', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('egg_id');
        });
        Capsule::schema()->create('mod_manager_server_settings', function ($table): void {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
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
        Capsule::schema()->create('egg_variables', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('egg_id');
            $table->string('env_variable');
        });
        Capsule::schema()->create('server_variables', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('variable_id');
            $table->string('variable_value')->nullable();
        });

        EggProfileRegistry::seed([
            [
                'id' => 'spigot',
                'match' => [
                    'uuid' => ['spigot-uuid'],
                    'name_aliases' => ['spigot'],
                    'variable_signatures' => [['DL_PATH', 'DL_VERSION', 'SERVER_JARFILE']],
                ],
                'status' => 'resolved',
                'project_type' => 'plugin',
                'loader' => 'spigot',
                'is_proxy' => false,
                'minecraft_version_variables' => ['DL_VERSION'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        EggProfileRegistry::clear();
        EggProfileResolver::clear();
        Mockery::close();

        parent::tearDown();
    }

    public function test_skips_warm_jobs_when_search_cache_is_still_fresh(): void
    {
        Capsule::table('eggs')->insert([
            'id' => 1,
            'uuid' => 'spigot-uuid',
            'name' => 'Spigot',
            'features' => json_encode([]),
            'tags' => json_encode(['minecraft']),
        ]);
        Capsule::table('servers')->insert(['id' => 1, 'egg_id' => 1]);
        Capsule::table('egg_variables')->insert([
            ['id' => 1, 'egg_id' => 1, 'env_variable' => 'DL_PATH'],
            ['id' => 2, 'egg_id' => 1, 'env_variable' => 'DL_VERSION'],
            ['id' => 3, 'egg_id' => 1, 'env_variable' => 'SERVER_JARFILE'],
        ]);
        Capsule::table('server_variables')->insert([
            'server_id' => 1,
            'variable_id' => 2,
            'variable_value' => '1.20.4',
        ]);

        $container = Container::getInstance();
        $container->make('config')->set('pelican-mod-manager.warm_catalog_enabled', true);
        $container->make('config')->set('pelican-mod-manager.warm_max_targets', 50);

        $queueConfig = Mockery::mock(ConfigRepository::class);
        $queueConfig->shouldReceive('get')->with('queue.default', 'sync')->andReturn('database');
        $operations = new InstalledOperationManager(
            Mockery::mock(CacheRepository::class),
            $queueConfig,
        );

        $source = Mockery::mock(ProjectSourceInterface::class);
        $source->shouldReceive('isConfigured')->once()->andReturnTrue();
        $source->shouldReceive('supportsSearch')->once()->andReturnTrue();
        $source->shouldReceive('hasFreshCachedSearch')
            ->once()
            ->withArgs(function ($server, $type, int $page, $search, array $filters): bool {
                return (int) $server->id === 1
                    && $type === ProjectType::Plugin
                    && $page === 1
                    && $search === null
                    && $filters === ['sort' => 'downloads'];
            })
            ->andReturnTrue();
        $source->shouldNotReceive('getKey');

        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldReceive('availableFor')->once()->andReturn([$source]);

        $command = new WarmCatalogCacheCommand();
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput(),
        ));

        self::assertSame(0, $command->handle(
            $operations,
            $registry,
            new ServerModManagerSettings(new ServerModManagerSettingRepository()),
        ));
    }

    public function test_discovers_an_auto_detected_egg_and_its_profile_specific_minecraft_version(): void
    {
        Capsule::table('eggs')->insert([
            'id' => 1,
            'uuid' => 'spigot-uuid',
            'name' => 'Spigot',
            'features' => json_encode([]),
            'tags' => json_encode(['minecraft']),
        ]);
        Capsule::table('servers')->insert(['id' => 1, 'egg_id' => 1]);
        Capsule::table('egg_variables')->insert([
            ['id' => 1, 'egg_id' => 1, 'env_variable' => 'DL_PATH'],
            ['id' => 2, 'egg_id' => 1, 'env_variable' => 'DL_VERSION'],
            ['id' => 3, 'egg_id' => 1, 'env_variable' => 'SERVER_JARFILE'],
        ]);
        Capsule::table('server_variables')->insert([
            'server_id' => 1,
            'variable_id' => 2,
            'variable_value' => '1.20.4',
        ]);

        $method = new \ReflectionMethod(WarmCatalogCacheCommand::class, 'discoverCombos');
        $combos = $method->invoke(
            new WarmCatalogCacheCommand(),
            new ServerModManagerSettings(new ServerModManagerSettingRepository()),
        );

        self::assertSame([[
            'loader' => 'spigot',
            'mc_version' => '1.20.4',
            'project_type' => 'plugin',
            'server_id' => 1,
            'server_count' => 1,
        ]], $combos);
    }

    public function test_does_not_discover_servers_with_all_types_disabled(): void
    {
        Capsule::table('eggs')->insert([
            'id' => 1,
            'uuid' => 'spigot-uuid',
            'name' => 'Spigot',
            'features' => json_encode([]),
            'tags' => json_encode(['minecraft']),
        ]);
        Capsule::table('servers')->insert(['id' => 1, 'egg_id' => 1]);
        Capsule::table('mod_manager_server_settings')->insert([
            'server_id' => 1,
            'mod_enabled' => false,
            'plugin_enabled' => false,
            'datapack_enabled' => false,
        ]);

        $method = new \ReflectionMethod(WarmCatalogCacheCommand::class, 'discoverCombos');
        self::assertSame([], $method->invoke(
            new WarmCatalogCacheCommand(),
            new ServerModManagerSettings(new ServerModManagerSettingRepository()),
        ));
    }

    public function test_does_not_discover_a_server_when_its_detected_type_is_disabled(): void
    {
        Capsule::table('eggs')->insert([
            'id' => 1,
            'uuid' => 'spigot-uuid',
            'name' => 'Spigot',
            'features' => json_encode([]),
            'tags' => json_encode(['minecraft']),
        ]);
        Capsule::table('servers')->insert(['id' => 1, 'egg_id' => 1]);
        Capsule::table('mod_manager_server_settings')->insert([
            'server_id' => 1,
            'plugin_enabled' => false,
        ]);

        $method = new \ReflectionMethod(WarmCatalogCacheCommand::class, 'discoverCombos');

        self::assertSame([], $method->invoke(
            new WarmCatalogCacheCommand(),
            new ServerModManagerSettings(new ServerModManagerSettingRepository()),
        ));
    }
}
