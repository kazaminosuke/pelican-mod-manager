<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kazaminosuke\ModManager\Models\ModManagerEggProfile;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Mockery;
use PHPUnit\Framework\TestCase;

class MinecraftVersionResolverTest extends TestCase
{
    private ?Container $previousContainer = null;

    private static ?Capsule $capsule = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();

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
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        MinecraftVersionResolver::clear();
        EggProfileRegistry::clear();
        EggProfileResolver::clear();
        Mockery::close();

        parent::tearDown();
    }

    public function test_version_is_memoized_per_server_until_runtime_cache_is_cleared(): void
    {
        $this->bindConfig(['egg_autodetect_enabled' => false]);

        $variables = Mockery::mock(HasMany::class);
        $variables->shouldReceive('whereIn')
            ->twice()
            ->with('env_variable', ['MINECRAFT_VERSION', 'MC_VERSION'])
            ->andReturnSelf();
        $variables->shouldReceive('pluck')
            ->twice()
            ->with('server_value', 'env_variable')
            ->andReturn(collect(['MINECRAFT_VERSION' => '1.21.8']));

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getKey')->times(3)->andReturn(42);
        $server->shouldReceive('variables')->twice()->andReturn($variables);

        self::assertSame('1.21.8', MinecraftVersionResolver::resolve($server));
        self::assertSame('1.21.8', MinecraftVersionResolver::resolve($server));

        MinecraftVersionResolver::clear();

        self::assertSame('1.21.8', MinecraftVersionResolver::resolve($server));
    }

    public function test_falls_back_to_the_global_default_when_no_variable_matches(): void
    {
        $this->bindConfig(['egg_autodetect_enabled' => false, 'latest_minecraft_version' => '26.1.2']);

        $variables = Mockery::mock(HasMany::class);
        $variables->shouldReceive('whereIn')->with('env_variable', ['MINECRAFT_VERSION', 'MC_VERSION'])->andReturnSelf();
        $variables->shouldReceive('pluck')->with('server_value', 'env_variable')->andReturn(collect());

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getKey')->andReturn(1);
        $server->shouldReceive('variables')->andReturn($variables);

        self::assertSame('26.1.2', MinecraftVersionResolver::resolve($server));
    }

    /**
     * Stage 8: Spigot never defines MINECRAFT_VERSION/MC_VERSION at all (it
     * uses DL_VERSION) - before this stage the generic lookup below always
     * missed it and silently fell through to the global default. The
     * resolved profile's own variable name must be checked, and win over
     * the generic names when both happen to be present.
     */
    public function test_uses_the_resolved_profiles_own_variable_name_for_an_egg_family_like_spigot(): void
    {
        $this->bindConfig(['egg_autodetect_enabled' => true]);
        EggProfileRegistry::seed([[
            'id' => 'spigot',
            'match' => ['uuid' => ['spigot-uuid'], 'name_aliases' => ['spigot'], 'variable_signatures' => []],
            'status' => 'resolved',
            'project_type' => 'plugin',
            'loader' => 'spigot',
            'is_proxy' => false,
            'minecraft_version_variables' => ['DL_VERSION'],
        ]]);

        $egg = new Egg();
        $egg->forceFill(['id' => 5, 'uuid' => 'spigot-uuid', 'name' => 'Spigot', 'tags' => ['minecraft'], 'features' => []]);
        $egg->setRelation('variables', collect());

        $variables = Mockery::mock(HasMany::class);
        $variables->shouldReceive('whereIn')->with('env_variable', ['DL_VERSION', 'MINECRAFT_VERSION', 'MC_VERSION'])->andReturnSelf();
        $variables->shouldReceive('pluck')->with('server_value', 'env_variable')->andReturn(collect(['DL_VERSION' => '1.20.4']));

        $server = Mockery::mock(Server::class)->makePartial();
        $server->forceFill(['id' => 5]);
        $server->shouldReceive('loadMissing')->andReturnSelf();
        $server->setRelation('egg', $egg);
        $server->shouldReceive('variables')->andReturn($variables);

        self::assertSame('1.20.4', MinecraftVersionResolver::resolve($server));
    }

    public function test_a_manual_profile_override_wins_over_every_other_source(): void
    {
        $this->bindConfig(['egg_autodetect_enabled' => true]);
        EggProfileRegistry::seed([[
            'id' => 'tekkit',
            'match' => ['uuid' => ['tekkit-uuid'], 'name_aliases' => ['tekkit'], 'variable_signatures' => [['MODPACK_VERSION']]],
            'status' => 'manual_required',
            'project_type' => null,
            'loader' => null,
            'is_proxy' => false,
            'minecraft_version_variables' => [],
        ]]);

        $egg = new Egg();
        $egg->forceFill(['id' => 6, 'uuid' => 'tekkit-uuid', 'name' => 'Tekkit', 'tags' => ['minecraft'], 'features' => []]);
        $egg->setRelation('variables', collect([(object) ['env_variable' => 'MODPACK_VERSION']]));

        ModManagerEggProfile::query()->create([
            'egg_id' => 6,
            'egg_uuid' => 'tekkit-uuid',
            'project_type' => 'mod',
            'loader' => 'forge',
            'minecraft_version' => '1.12.2',
            'supports_datapacks' => true,
        ]);

        $server = Mockery::mock(Server::class)->makePartial();
        $server->forceFill(['id' => 6]);
        $server->shouldReceive('loadMissing')->andReturnSelf();
        $server->setRelation('egg', $egg);
        // The override short-circuits before any variables() query is made.
        $server->shouldNotReceive('variables');

        self::assertSame('1.12.2', MinecraftVersionResolver::resolve($server));
    }

    private function bindConfig(array $values): void
    {
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-mod-manager' => $values,
        ]));
        Container::setInstance($container);
    }
}
