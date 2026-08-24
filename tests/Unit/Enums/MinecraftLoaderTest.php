<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Enums;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use PHPUnit\Framework\TestCase;

class MinecraftLoaderTest extends TestCase
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

        // EggProfileResolver's manual-profile lookup (reached whenever no
        // JSON profile resolves the egg) needs this table to exist, even
        // in tests that never insert a row into it.
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
        EggProfileRegistry::clear();
        EggProfileResolver::clear();

        parent::tearDown();
    }

    public function test_explicit_loader_tag_wins_without_consulting_the_profile_database(): void
    {
        $this->bindAutodetect(true);

        $server = $this->server($this->egg(1, uuid: null, tags: ['minecraft', 'fabric']));

        // No profile seeded - a wrong fall-through to the resolver would
        // fatally error (plugin_path()/base_path() need a real
        // Application this bare Container doesn't provide), not silently
        // return the right answer.
        self::assertSame(MinecraftLoader::Fabric, MinecraftLoader::fromServer($server));
    }

    public function test_no_loader_tag_falls_back_to_the_profile_database(): void
    {
        $this->bindAutodetect(true);
        EggProfileRegistry::seed([[
            'id' => 'purpur',
            'match' => ['uuid' => ['purpur-uuid'], 'name_aliases' => ['purpur'], 'variable_signatures' => []],
            'status' => 'resolved',
            'project_type' => 'plugin',
            'loader' => 'purpur',
            'is_proxy' => false,
            'minecraft_version_variables' => ['MINECRAFT_VERSION'],
        ]]);

        $server = $this->server($this->egg(2, uuid: 'purpur-uuid', name: 'Purpur', tags: ['minecraft']));

        self::assertSame(MinecraftLoader::Purpur, MinecraftLoader::fromServer($server));
    }

    public function test_autodetect_disabled_returns_null_without_consulting_the_profile_database(): void
    {
        $this->bindAutodetect(false);

        $server = $this->server($this->egg(3, uuid: 'purpur-uuid', name: 'Purpur', tags: ['minecraft']));

        self::assertNull(MinecraftLoader::fromServer($server));
    }

    /**
     * The heuristic tier (Stage 8 依頼 B) never guesses a loader, only a
     * project type - so an egg that only resolves via heuristic must still
     * come back with no loader from this method.
     */
    public function test_heuristic_only_match_never_returns_a_loader(): void
    {
        $this->bindAutodetect(true);
        // No profile matches this uuid/name at all - only the 'minecraft'
        // tag plus a mod-loader-specific variable name can fire the
        // heuristic tier, and even then it must not produce a loader.
        EggProfileRegistry::seed([]);
        $server = $this->server($this->egg(4, uuid: null, name: 'Some Homebrew Fabric Egg', tags: ['minecraft'], variables: ['FABRIC_VERSION']));

        self::assertNull(MinecraftLoader::fromServer($server));
    }

    private function bindAutodetect(bool $enabled): void
    {
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-mod-manager' => ['egg_autodetect_enabled' => $enabled],
        ]));
        Container::setInstance($container);
    }

    private function egg(int $id, ?string $uuid, string $name = 'Egg', array $tags = [], array $variables = []): Egg
    {
        $egg = new Egg();
        $egg->forceFill(['id' => $id, 'uuid' => $uuid, 'name' => $name, 'tags' => $tags, 'features' => []]);
        $egg->setRelation('variables', collect($variables)->map(fn (string $name) => (object) ['env_variable' => $name]));

        return $egg;
    }

    private function server(Egg $egg): Server
    {
        $server = new Server();
        $server->forceFill(['id' => $egg->getKey()]);
        $server->setRelation('egg', $egg);

        return $server;
    }
}
