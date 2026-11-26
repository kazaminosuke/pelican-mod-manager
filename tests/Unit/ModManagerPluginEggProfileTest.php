<?php

namespace Kazaminosuke\ModManager\Tests\Unit;

use App\Models\Egg;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Models\ModManagerEggProfile;
use Kazaminosuke\ModManager\ModManagerPlugin;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Stage 8's admin settings screen ("Egg profiles" action) and the
 * server-side manual-setup form both funnel through these two static
 * methods (ModManagerPlugin::eggProfileDefaults()/saveEggProfile()) -
 * tested directly here rather than through Filament's schema/action
 * wiring, which the rest of this codebase's tests don't exercise either.
 */
class ModManagerPluginEggProfileTest extends TestCase
{
    private static ?Capsule $capsule = null;

    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();

        // Filament notifications use the session for both the legacy queue
        // and the newer notification mailbox.
        $translator = Mockery::mock(Translator::class);
        $translator->shouldReceive('get')->andReturnUsing(fn (string $key) => $key);
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $container = new Container();
        $container->instance('translator', $translator);
        $container->instance('session', $session);
        $container->instance('config', new Repository(['app' => ['key' => 'mod-manager-test']]));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        if (self::$capsule === null) {
            self::$capsule = new Capsule();
            self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
        }

        Capsule::schema()->dropIfExists('mod_manager_egg_profiles');
        Capsule::schema()->create('mod_manager_egg_profiles', function ($table) {
            $table->id();
            $table->unsignedBigInteger('egg_id')->unique();
            $table->string('egg_uuid')->nullable();
            $table->string('project_type')->nullable();
            $table->string('loader')->nullable();
            $table->string('minecraft_version')->nullable();
            $table->boolean('supports_datapacks')->default(false);
            $table->timestamps();
        });

        // ModManagerPlugin::saveEggProfile() looks the egg up via
        // Egg::query()->find() (not a pre-set relation, unlike the other
        // Stage 8 tests), so this - unlike those - needs a real, persisted
        // row. Deliberately minimal/nullable: this is a throwaway schema
        // for these tests, not the real eggs migration.
        Capsule::schema()->dropIfExists('eggs');
        Capsule::schema()->create('eggs', function ($table) {
            $table->id();
            $table->string('uuid')->nullable();
            $table->string('name')->nullable();
            $table->text('tags')->nullable();
            $table->text('features')->nullable();
            $table->string('update_url')->nullable();
            $table->unsignedBigInteger('config_from')->nullable();
            // Egg::$attributes defaults these to non-null placeholder
            // values, so a plain save() always includes them regardless of
            // what this test sets explicitly.
            $table->text('file_denylist')->nullable();
            $table->text('config_stop')->nullable();
            $table->text('config_startup')->nullable();
            $table->text('config_logs')->nullable();
            $table->text('config_files')->nullable();
            $table->timestamps();
        });

        EggProfileRegistry::seed([[
            'id' => 'fabric',
            'match' => ['uuid' => ['fabric-uuid'], 'name_aliases' => ['fabric'], 'variable_signatures' => []],
            'status' => 'resolved',
            'project_type' => 'mod',
            'loader' => 'fabric',
            'is_proxy' => false,
            'minecraft_version_variables' => ['MC_VERSION'],
        ]]);
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

    public function test_defaults_come_from_the_current_auto_resolution_when_nothing_is_saved(): void
    {
        $egg = $this->egg(1, 'fabric-uuid', 'Fabric');

        $defaults = ModManagerPlugin::eggProfileDefaults($egg);

        self::assertSame('mod', $defaults['project_type']);
        self::assertSame('fabric', $defaults['loader']);
        self::assertNull($defaults['minecraft_version']);
    }

    public function test_defaults_prefer_an_existing_manual_profile_over_auto_resolution(): void
    {
        $egg = $this->egg(2, 'fabric-uuid', 'Fabric');

        ModManagerEggProfile::query()->create([
            'egg_id' => 2,
            'egg_uuid' => 'fabric-uuid',
            'project_type' => 'plugin',
            'loader' => 'paper',
            'minecraft_version' => '1.20.4',
            'supports_datapacks' => true,
        ]);

        $defaults = ModManagerPlugin::eggProfileDefaults($egg);

        self::assertSame('plugin', $defaults['project_type']);
        self::assertSame('paper', $defaults['loader']);
        self::assertSame('1.20.4', $defaults['minecraft_version']);
        self::assertTrue($defaults['supports_datapacks']);
    }

    public function test_saving_with_project_type_auto_deletes_any_existing_manual_profile(): void
    {
        $this->egg(3, 'tekkit-uuid', 'Tekkit');

        ModManagerEggProfile::query()->create([
            'egg_id' => 3,
            'egg_uuid' => 'tekkit-uuid',
            'project_type' => 'mod',
            'loader' => 'forge',
            'minecraft_version' => null,
            'supports_datapacks' => false,
        ]);

        ModManagerPlugin::saveEggProfile(['egg_id' => 3, 'project_type' => 'auto']);

        self::assertNull(ModManagerEggProfile::query()->where('egg_id', 3)->first());
    }

    public function test_saving_a_concrete_project_type_creates_a_manual_profile(): void
    {
        $this->egg(4, null, 'Some Homebrew Egg');

        ModManagerPlugin::saveEggProfile([
            'egg_id' => 4,
            'project_type' => 'mod',
            'loader' => 'forge',
            'minecraft_version' => '1.12.2',
            'supports_datapacks' => true,
        ]);

        $saved = ModManagerEggProfile::query()->where('egg_id', 4)->first();

        self::assertNotNull($saved);
        self::assertSame('mod', $saved->project_type);
        self::assertSame('forge', $saved->loader);
        self::assertSame('1.12.2', $saved->minecraft_version);
        self::assertTrue($saved->supports_datapacks);
    }

    public function test_saving_again_updates_the_same_row_instead_of_creating_a_second_one(): void
    {
        $this->egg(5, null, 'Some Egg');

        ModManagerPlugin::saveEggProfile(['egg_id' => 5, 'project_type' => 'mod', 'loader' => 'forge']);
        ModManagerPlugin::saveEggProfile(['egg_id' => 5, 'project_type' => 'plugin', 'loader' => 'paper']);

        $saved = ModManagerEggProfile::query()->where('egg_id', 5)->get();

        self::assertCount(1, $saved);
        self::assertSame('plugin', $saved->first()->project_type);
        self::assertSame('paper', $saved->first()->loader);
    }

    /**
     * Persisted (unlike the other Stage 8 test files' egg fixtures):
     * ModManagerPlugin::saveEggProfile() looks the egg up via
     * Egg::query()->find(), not a pre-set relation, so it genuinely needs a
     * row to exist.
     */
    private function egg(int $id, ?string $uuid, string $name): Egg
    {
        $egg = new Egg();
        $egg->forceFill(['id' => $id, 'uuid' => $uuid, 'name' => $name, 'tags' => ['minecraft'], 'features' => []]);
        $egg->setRelation('variables', collect());
        $egg->save();

        return $egg;
    }
}
