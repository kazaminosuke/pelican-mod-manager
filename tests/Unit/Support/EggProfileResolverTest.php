<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Database\Capsule\Manager as Capsule;
use Kazaminosuke\ModManager\Models\ModManagerEggProfile;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use PHPUnit\Framework\TestCase;

class EggProfileResolverTest extends TestCase
{
    private static ?Capsule $capsule = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$capsule === null) {
            // A real (in-memory) database is used only for
            // Models\ModManagerEggProfile's manual-profile lookup - the one
            // part of the cascade that is a genuine Eloquent query rather
            // than a pure match against the seeded profile set below. Egg
            // fixtures themselves stay unpersisted (see server()/egg()) -
            // getKey()/attribute reads don't need a row to exist.
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

        EggProfileRegistry::seed([
            [
                'id' => 'paper',
                'match' => [
                    'uuid' => ['paper-uuid'],
                    'name_aliases' => ['paper'],
                    'variable_signatures' => [['BUILD_NUMBER', 'DL_PATH', 'MINECRAFT_VERSION', 'SERVER_JARFILE']],
                ],
                'status' => 'resolved',
                'project_type' => 'plugin',
                'loader' => 'paper',
                'is_proxy' => false,
                'minecraft_version_variables' => ['MINECRAFT_VERSION'],
            ],
            [
                'id' => 'folia',
                'match' => [
                    'uuid' => ['folia-uuid'],
                    'name_aliases' => ['folia'],
                    // Deliberately identical to paper's signature above.
                    'variable_signatures' => [['BUILD_NUMBER', 'DL_PATH', 'MINECRAFT_VERSION', 'SERVER_JARFILE']],
                ],
                'status' => 'resolved',
                'project_type' => 'plugin',
                'loader' => 'folia',
                'is_proxy' => false,
                'minecraft_version_variables' => ['MINECRAFT_VERSION'],
            ],
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
            [
                'id' => 'vanilla-bedrock',
                'match' => ['uuid' => ['bedrock-uuid'], 'name_aliases' => ['vanillabedrock'], 'variable_signatures' => []],
                'status' => 'none',
                'project_type' => null,
                'loader' => null,
                'is_proxy' => false,
                'minecraft_version_variables' => [],
            ],
            [
                'id' => 'tekkit',
                'match' => ['uuid' => ['tekkit-uuid'], 'name_aliases' => ['tekkit'], 'variable_signatures' => [['MODPACK_VERSION']]],
                'status' => 'manual_required',
                'project_type' => null,
                'loader' => null,
                'is_proxy' => false,
                'minecraft_version_variables' => [],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        EggProfileRegistry::clear();
        EggProfileResolver::clear();

        parent::tearDown();
    }

    public function test_resolves_paper_by_uuid(): void
    {
        $server = $this->server($this->egg(1, uuid: 'paper-uuid', name: 'Paper', variables: ['BUILD_NUMBER', 'DL_PATH', 'MINECRAFT_VERSION', 'SERVER_JARFILE']));

        $resolved = EggProfileResolver::resolve($server);

        self::assertSame('resolved', $resolved->status);
        self::assertSame('plugin', $resolved->projectType);
        self::assertSame('paper', $resolved->loader);
        self::assertSame('uuid', $resolved->source);
        self::assertSame(['MINECRAFT_VERSION'], $resolved->minecraftVersionVariables);
        self::assertTrue($resolved->supportsDatapacks);
    }

    /**
     * The exact scenario Stage 8's research measured: a Pterodactyl-style
     * Folia egg (no uuid at all) must resolve to folia, not paper, even
     * though the two profiles share an identical variable signature.
     */
    public function test_pterodactyl_folia_is_not_misidentified_as_paper(): void
    {
        $server = $this->server($this->egg(2, uuid: null, name: 'Folia', variables: ['BUILD_NUMBER', 'DL_PATH', 'MINECRAFT_VERSION', 'SERVER_JARFILE']));

        $resolved = EggProfileResolver::resolve($server);

        self::assertSame('resolved', $resolved->status);
        self::assertSame('folia', $resolved->loader);
        self::assertSame('name_signature', $resolved->source);
    }

    public function test_current_paper_variable_set_is_not_identified_as_folia(): void
    {
        $path = dirname(__DIR__, 3).'/resources/egg-profiles.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        EggProfileRegistry::seed($data['profiles']);
        EggProfileResolver::clear();

        $variables = ['BUILD_NUMBER', 'DL_PATH', 'MINECRAFT_VERSION', 'PROJECT', 'SERVER_JARFILE', 'USER_AGENT'];
        $paper = EggProfileResolver::resolve($this->server($this->egg(90, uuid: null, name: 'PaperMC', variables: $variables)));
        $folia = EggProfileResolver::resolve($this->server($this->egg(91, uuid: null, name: 'Folia', variables: $variables)));

        self::assertNotSame('folia', $paper->loader);
        self::assertNotSame('signature', $paper->source);
        self::assertSame('folia', $folia->loader);
        self::assertSame('name_signature', $folia->source);
    }

    public function test_current_velocity_variable_set_resolves_a_renamed_egg(): void
    {
        $path = dirname(__DIR__, 3).'/resources/egg-profiles.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        EggProfileRegistry::seed($data['profiles']);
        EggProfileResolver::clear();

        $resolved = EggProfileResolver::resolve($this->server($this->egg(
            92,
            uuid: null,
            name: 'Custom Proxy',
            tags: [],
            variables: ['BUILD_NUMBER', 'DL_PATH', 'PROJECT', 'SERVER_JARFILE', 'USER_AGENT', 'VELOCITY_VERSION'],
        )));

        self::assertSame('velocity', $resolved->loader);
        self::assertSame('signature', $resolved->source);
        self::assertFalse($resolved->supportsDatapacks);
    }

    public function test_colliding_signature_alone_is_never_adopted(): void
    {
        // A name that matches nothing, carrying the paper/folia-colliding
        // signature with no uuid: the signature-alone tier must refuse,
        // and nothing else can place it either.
        $server = $this->server($this->egg(3, uuid: null, name: 'Some Renamed Egg', tags: [], variables: ['BUILD_NUMBER', 'DL_PATH', 'MINECRAFT_VERSION', 'SERVER_JARFILE']));

        $resolved = EggProfileResolver::resolve($server);

        self::assertNotSame('paper', $resolved->loader);
        self::assertNotSame('folia', $resolved->loader);
        self::assertSame('none', $resolved->status);
    }

    public function test_non_colliding_signature_rescues_a_renamed_spigot_egg(): void
    {
        $server = $this->server($this->egg(4, uuid: null, name: 'My Custom Server', tags: ['minecraft'], variables: ['DL_PATH', 'DL_VERSION', 'SERVER_JARFILE']));

        $resolved = EggProfileResolver::resolve($server);

        self::assertSame('spigot', $resolved->loader);
        self::assertSame('signature', $resolved->source);
        self::assertSame(['DL_VERSION'], $resolved->minecraftVersionVariables);
    }

    public function test_bedrock_style_none_profile_never_prompts_for_manual_setup(): void
    {
        $server = $this->server($this->egg(5, uuid: 'bedrock-uuid', name: 'Vanilla Bedrock', variables: []));

        $resolved = EggProfileResolver::resolve($server);

        self::assertSame('none', $resolved->status);
        self::assertFalse($resolved->needsManualSetup());
        self::assertFalse($resolved->supportsDatapacks);
    }

    public function test_manual_required_profile_without_a_saved_override_prompts_for_setup(): void
    {
        $server = $this->server($this->egg(6, uuid: 'tekkit-uuid', name: 'Tekkit', variables: ['MODPACK_VERSION']));

        $resolved = EggProfileResolver::resolve($server);

        self::assertTrue($resolved->needsManualSetup());
        self::assertNull($resolved->projectType);
    }

    public function test_unknown_egg_with_no_minecraft_signal_resolves_to_none(): void
    {
        $server = $this->server($this->egg(7, uuid: null, name: 'Totally Unrelated Game', tags: [], variables: ['SOME_OTHER_VAR']));

        $resolved = EggProfileResolver::resolve($server);

        self::assertSame('none', $resolved->status);
        self::assertFalse($resolved->needsManualSetup());
    }

    public function test_unknown_egg_with_minecraft_tag_but_no_marker_still_prompts_for_setup(): void
    {
        $server = $this->server($this->egg(8, uuid: null, name: 'Some Homebrew Egg', tags: ['minecraft'], variables: ['CUSTOM_VAR']));

        $resolved = EggProfileResolver::resolve($server);

        self::assertTrue($resolved->needsManualSetup());
        self::assertNull($resolved->projectType);
    }

    public function test_unknown_egg_with_mod_loader_marker_heuristically_guesses_mod_type_only(): void
    {
        $server = $this->server($this->egg(9, uuid: null, name: 'Some Homebrew Fabric Egg', tags: ['minecraft'], variables: ['FABRIC_VERSION', 'MC_VERSION']));

        $resolved = EggProfileResolver::resolve($server);

        self::assertTrue($resolved->needsManualSetup());
        self::assertSame('mod', $resolved->projectType);
        self::assertNull($resolved->loader);
        self::assertSame('heuristic', $resolved->source);
    }

    public function test_a_saved_manual_profile_takes_priority_over_manual_required(): void
    {
        $egg = $this->egg(10, uuid: 'tekkit-uuid', name: 'Tekkit', variables: ['MODPACK_VERSION']);

        ModManagerEggProfile::query()->create([
            'egg_id' => 10,
            'egg_uuid' => 'tekkit-uuid',
            'project_type' => 'mod',
            'loader' => 'forge',
            'minecraft_version' => '1.12.2',
            'supports_datapacks' => true,
        ]);

        $resolved = EggProfileResolver::resolve($this->server($egg));

        self::assertSame('resolved', $resolved->status);
        self::assertSame('manual', $resolved->source);
        self::assertSame('mod', $resolved->projectType);
        self::assertSame('forge', $resolved->loader);
        self::assertSame('1.12.2', $resolved->minecraftVersionOverride);
        self::assertTrue($resolved->supportsDatapacks);
    }

    public function test_resolution_is_memoized_per_server_for_the_life_of_the_request(): void
    {
        $server = $this->server($this->egg(11, uuid: 'spigot-uuid', name: 'Spigot', variables: ['DL_PATH', 'DL_VERSION', 'SERVER_JARFILE']));

        $first = EggProfileResolver::resolve($server);
        $second = EggProfileResolver::resolve($server);

        self::assertSame($first, $second);
    }

    private function egg(int $id, ?string $uuid, string $name, ?string $updateUrl = null, array $tags = ['minecraft'], array $features = [], array $variables = []): Egg
    {
        $egg = new Egg();
        $egg->forceFill([
            'id' => $id,
            'uuid' => $uuid,
            'name' => $name,
            'update_url' => $updateUrl,
            'tags' => $tags,
            'features' => $features,
        ]);
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
