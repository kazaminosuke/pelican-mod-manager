<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Enums;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use PHPUnit\Framework\TestCase;

class ProjectTypeTest extends TestCase
{
    private ?Container $previousContainer = null;

    private static ?Capsule $capsule = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();

        // Server::loadMissing('egg') (called by every method under test)
        // builds a query builder internally even when the relation is
        // already set via setRelation() below and nothing is actually
        // queried - Eloquent needs *some* resolvable connection to exist
        // for that alone, hence this, even though no table here is ever
        // touched. Mirrors Support\EggProfileResolverTest's setup.
        if (self::$capsule === null) {
            self::$capsule = new Capsule();
            self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
        }
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        EggProfileRegistry::clear();
        EggProfileResolver::clear();

        parent::tearDown();
    }

    public function test_explicit_plugin_manager_feature_wins_without_consulting_the_profile_database(): void
    {
        $this->bindAutodetect(true);

        $server = $this->server($this->egg(1, features: ['plugin_manager'], tags: []));

        // EggProfileRegistry is deliberately never seeded here. If
        // fromServer() wrongly fell through to the profile resolver
        // despite already finding an explicit match, EggProfileRegistry::
        // load() would call plugin_path()/base_path(), which this test's
        // bare Container (no basePath() method) cannot satisfy - so a
        // wrong short-circuit would fail loudly, not silently pass.
        self::assertSame(ProjectType::Plugin, ProjectType::fromServer($server));
    }

    public function test_explicit_mod_manager_feature_wins(): void
    {
        $this->bindAutodetect(true);

        $server = $this->server($this->egg(2, features: ['mod_manager'], tags: []));

        self::assertSame(ProjectType::Mod, ProjectType::fromServer($server));
    }

    public function test_no_explicit_signal_falls_back_to_the_profile_database(): void
    {
        $this->bindAutodetect(true);
        $this->seedFabricProfile();

        $server = $this->server($this->egg(3, uuid: 'fabric-uuid', name: 'Fabric', features: [], tags: []));

        self::assertSame(ProjectType::Mod, ProjectType::fromServer($server));
    }

    public function test_autodetect_disabled_returns_null_without_consulting_the_profile_database(): void
    {
        $this->bindAutodetect(false);

        $server = $this->server($this->egg(4, uuid: 'fabric-uuid', name: 'Fabric', features: [], tags: []));

        // Same "never seed the registry" trick as above - reaching it here
        // would fatally error rather than quietly return the right answer.
        self::assertNull(ProjectType::fromServer($server));
    }

    public function test_from_server_never_returns_datapack_even_for_a_datapack_only_profile(): void
    {
        $this->bindAutodetect(true);
        EggProfileRegistry::seed([[
            'id' => 'vanilla',
            'match' => ['uuid' => ['vanilla-uuid'], 'name_aliases' => ['vanilla'], 'variable_signatures' => []],
            'status' => 'resolved',
            'project_type' => 'datapack',
            'loader' => null,
            'is_proxy' => false,
            'minecraft_version_variables' => ['VANILLA_VERSION'],
        ]]);

        $server = $this->server($this->egg(5, uuid: 'vanilla-uuid', name: 'Vanilla', features: [], tags: []));

        self::assertNull(ProjectType::fromServer($server));
        self::assertTrue(ProjectType::supportsDatapacks($server));
    }

    public function test_resource_pack_is_archive_based_and_not_loader_specific(): void
    {
        $server = new Server();

        self::assertSame('resourcepacks', ProjectType::ResourcePack->getFolder());
        self::assertSame('.zip', ProjectType::ResourcePack->getFileExtension());
        self::assertNull(ProjectType::ResourcePack->getLoaderSlug($server));
    }

    public function test_datapack_manager_disabled_flag_wins_over_the_profile_default(): void
    {
        $this->bindAutodetect(true);
        $this->seedFabricProfile();

        $server = $this->server($this->egg(6, uuid: 'fabric-uuid', name: 'Fabric', features: ['datapack_manager_disabled'], tags: []));

        self::assertFalse(ProjectType::supportsDatapacks($server));
    }

    public function test_datapack_manager_flag_wins_over_the_profile_default(): void
    {
        $this->bindAutodetect(true);
        // Deliberately no matching profile at all - the explicit flag must
        // still work even for an egg the profile database doesn't cover.
        $server = $this->server($this->egg(7, uuid: null, name: 'Unknown', features: ['datapack_manager'], tags: []));

        self::assertTrue(ProjectType::supportsDatapacks($server));
    }

    public function test_datapack_support_defaults_to_true_for_a_resolved_java_server_profile(): void
    {
        $this->bindAutodetect(true);
        $this->seedFabricProfile();

        $server = $this->server($this->egg(8, uuid: 'fabric-uuid', name: 'Fabric', features: [], tags: []));

        self::assertTrue(ProjectType::supportsDatapacks($server));
    }

    public function test_datapack_support_defaults_to_false_for_a_proxy_profile(): void
    {
        $this->bindAutodetect(true);
        EggProfileRegistry::seed([[
            'id' => 'waterfall',
            'match' => ['uuid' => ['waterfall-uuid'], 'name_aliases' => ['waterfall'], 'variable_signatures' => []],
            'status' => 'resolved',
            'project_type' => 'plugin',
            'loader' => 'waterfall',
            'is_proxy' => true,
            'minecraft_version_variables' => ['MINECRAFT_VERSION'],
        ]]);

        $server = $this->server($this->egg(9, uuid: 'waterfall-uuid', name: 'Waterfall', features: [], tags: []));

        self::assertFalse(ProjectType::supportsDatapacks($server));
    }

    /**
     * Stage 8 bugfix: a child egg that inherits its features from a parent
     * (config_from) - rather than defining plugin_manager/mod_manager
     * itself - must still be detected explicitly, without ever reaching
     * the profile database.
     */
    public function test_explicit_detection_reads_inherited_features_from_a_parent_egg(): void
    {
        $this->bindAutodetect(true);

        $parent = new Egg();
        $parent->forceFill(['id' => 100, 'features' => ['mod_manager'], 'tags' => ['minecraft']]);

        $child = new Egg();
        $child->forceFill(['id' => 101, 'features' => null, 'tags' => ['minecraft'], 'config_from' => 100]);
        $child->setRelation('configFrom', $parent);

        $server = $this->server($child);

        self::assertSame(ProjectType::Mod, ProjectType::fromServer($server));
    }

    private function seedFabricProfile(): void
    {
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

    private function bindAutodetect(bool $enabled): void
    {
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-mod-manager' => ['egg_autodetect_enabled' => $enabled],
        ]));
        Container::setInstance($container);
    }

    private function egg(int $id, ?string $uuid = null, string $name = 'Egg', array $features = [], array $tags = ['minecraft']): Egg
    {
        $egg = new Egg();
        $egg->forceFill(['id' => $id, 'uuid' => $uuid, 'name' => $name, 'features' => $features, 'tags' => $tags]);
        $egg->setRelation('variables', collect());

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
