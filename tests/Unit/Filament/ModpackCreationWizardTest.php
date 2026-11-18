<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use App\Filament\Admin\Resources\Servers\Pages\CreateServer;
use App\Models\Server;
use App\Services\Servers\ServerCreationService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Filament\Admin\ModpackCreationStep;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Providers\ModManagerServiceProvider;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ZipArchive;

#[RunTestsInSeparateProcesses]
final class ModpackCreationWizardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'session.driver' => 'array',
            'pelican-mod-manager.curseforge_api_key' => 'test-curseforge-credential',
        ]);
        DB::purge('sqlite');
        $this->app->register(ModManagerServiceProvider::class);
        View::addNamespace('pelican-mod-manager', dirname(__DIR__, 3).'/resources/views');
        $this->app['translator']->addNamespace('pelican-mod-manager', dirname(__DIR__, 3).'/lang');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();

        Schema::create('eggs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('uuid');
            $table->json('tags');
            $table->json('startup_commands');
            $table->json('docker_images');
        });
        Schema::create('servers', function (Blueprint $table): void {
            $table->id();
            $table->integer('egg_id');
            $table->string('status')->nullable();
        });
        Schema::create('egg_variables', function (Blueprint $table): void {
            $table->id();
            $table->integer('egg_id');
            $table->integer('sort')->default(0);
            $table->string('env_variable');
            $table->string('default_value');
        });
    }

    /** @return array<string, array{bool}> */
    public static function curseForgeManifestAvailability(): array
    {
        return ['automatic build' => [true], 'manual build' => [false]];
    }

    #[DataProvider('curseForgeManifestAvailability')]
    public function test_wizard_submits_the_selected_release_and_queues_the_archive_after_creation(bool $manifestAvailable): void
    {
        $runner = PluginBackgroundRunner::fake();
        $this->app->instance(PluginBackgroundRunner::class, $runner);
        $this->addEgg(7, 'NeoForge', 'e23e092f-b803-4f34-82cf-2d6518c6351a', [
            'MC_VERSION' => 'latest',
            'NEOFORGE_VERSION' => 'latest',
        ]);
        $archive = tempnam(sys_get_temp_dir(), 'curseforge-fixture');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', json_encode([
            'manifestType' => 'minecraftModpack',
            'minecraft' => ['version' => '1.21.1', 'modLoaders' => [['id' => 'neoforge-21.1.266', 'primary' => true]]],
        ], JSON_THROW_ON_ERROR));
        $zip->close();
        $bytes = file_get_contents($archive);
        unlink($archive);
        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([['version' => '1.21.1', 'version_type' => 'release']]),
            'api.curseforge.com/v1/mods/search*' => Http::response([
                'data' => [['id' => 925200, 'name' => 'All the Mods 10', 'summary' => 'Example server pack']],
                'pagination' => ['totalCount' => 1],
            ]),
            'api.curseforge.com/v1/mods/925200/files?*' => Http::response(['data' => [[
                'id' => 9620701, 'fileName' => 'ATM10-8.6.zip', 'gameVersions' => ['1.21.1', 'NeoForge'],
            ]]]),
            'api.curseforge.com/v1/mods/925200/files/9620701' => Http::response(['data' => [
                'id' => 9620701, 'fileName' => 'ATM10-8.6.zip', 'serverPackFileId' => 9620727,
                'downloadUrl' => $manifestAvailable ? 'https://edge.forgecdn.net/client.zip' : null,
                'gameVersions' => ['1.21.1', 'NeoForge'],
            ]]),
            'edge.forgecdn.net/client.zip' => static function ($request) use ($bytes) {
                $total = strlen($bytes);
                preg_match('/^bytes=(?:(\d+)-(\d+)|-(\d+))$/', $request->header('Range')[0], $range);
                $first = isset($range[3]) ? max(0, $total - (int) $range[3]) : (int) $range[1];
                $last = isset($range[3]) ? $total - 1 : (int) $range[2];

                return Http::response(substr($bytes, $first, $last - $first + 1), 206, ['Content-Range' => "bytes {$first}-{$last}/{$total}"]);
            },
            'api.curseforge.com/v1/mods/925200/files/9620727' => Http::response(['data' => [
                'id' => 9620727, 'fileName' => 'ServerFiles-8.6.zip',
                'parentProjectFileId' => 9620701, 'isServerPack' => true,
                'downloadUrl' => 'https://edge.forgecdn.net/server.zip',
            ]]),
        ]);
        $service = Mockery::mock(ServerCreationService::class);
        $service->shouldReceive('handle')->once()->withArgs(function (array $data) use ($runner): bool {
            self::assertSame([], $runner->spawned);
            self::assertGreaterThan(0, DB::transactionLevel());
            self::assertSame(7, $data['egg_id']);
            self::assertSame('1.21.1', $data['environment']['MC_VERSION']);
            self::assertSame('21.1.266', $data['environment']['NEOFORGE_VERSION']);
            self::assertSame('java -jar server.jar', $data['startup']);
            self::assertSame('ghcr.io/pelican-eggs/yolks:java_21', $data['image']);
            self::assertFalse($data['start_on_completion']);
            self::assertSame('9620701', $data['mm_install_plan']['version_id']);

            return true;
        })->andReturn((new Server())->forceFill(['id' => 42]));
        $this->app->instance(ServerCreationService::class, $service);

        $wizard = Livewire::test(ModpackCreationWizardHarness::class)
            ->assertDontSee('All the Mods 10')
            ->set('data.mm_provider', 'curseforge')
            ->set('data.mm_modpack', true)
            ->set('data.mm_query', 'All the Mods')
            ->set('data.mm_minecraft', '1.21.1')
            ->set('data.mm_loader', 'neoforge')
            ->assertSee('All the Mods 10')
            ->set('data.mm_project', 'curseforge:925200')
            ->assertSee('ATM10-8.6.zip')
            ->set('data.mm_version', '9620701')
            ->assertSet('data.egg_id', 7)
            ->assertSet('data.environment.MC_VERSION', '1.21.1');
        if ($manifestAvailable) {
            $wizard->assertSet('data.environment.NEOFORGE_VERSION', '21.1.266')
                ->assertDontSee('could not determine the required loader build');
        } else {
            $wizard->assertSee('could not determine the required loader build')
                ->call('nextModpackStep')->assertHasErrors('data.mm_confirm_compatibility')
                ->set('data.mm_confirm_compatibility', true);
        }
        $wizard->call('nextModpackStep')
            ->assertHasNoErrors()
            ->set('data.mm_modpack', false)
            ->assertDontSee('All the Mods 10')
            ->assertSet('data.egg_id', null)
            ->set('data.mm_modpack', true)
            ->assertSet('data.mm_query', 'All the Mods')
            ->assertSet('data.mm_minecraft', '1.21.1')
            ->assertSet('data.mm_version', '9620701');

        if (!$manifestAvailable) {
            $wizard->set('data.mm_confirm_compatibility', true)
                ->set('data.environment.NEOFORGE_VERSION', '21.1.266');
        }
        $wizard->set('data.start_on_completion', true)->call('create')->assertHasErrors('data.mm_project');
        self::assertSame([], $runner->spawned);
        $wizard->set('data.start_on_completion', false)
            ->set('data.image', 'ghcr.io/pelican-eggs/yolks:java_17')->call('create')->assertHasErrors('data.image')
            ->set('data.image', 'ghcr.io/pelican-eggs/yolks:java_21')->call('create')->assertHasNoErrors();

        self::assertCount(1, $runner->spawned);
        self::assertSame(BackgroundJob::MODPACK, $runner->spawned[0]['type']);
        self::assertSame('9620701', $runner->spawned[0]['payload']['version_id']);
        self::assertSame('925200', $runner->spawned[0]['payload']['project_id']);
        self::assertSame([
            'egg_id' => '7', 'image' => 'ghcr.io/pelican-eggs/yolks:java_21', 'minecraft' => '1.21.1', 'loader' => 'neoforge', 'server_pack' => true,
            'artifact_id' => '9620727',
            'variables' => ['MC_VERSION' => '1.21.1', 'NEOFORGE_VERSION' => '21.1.266'],
        ], $runner->spawned[0]['payload']['selection']);
        self::assertGreaterThan(time(), $runner->spawned[0]['payload']['defer_until']);

        // The persisted job must reject a server whose Egg changed while waiting.
        DB::table('servers')->insert(['id' => 42, 'egg_id' => 99]);
        BackgroundJob::execute(BackgroundJob::MODPACK, $runner->spawned[0]['payload']);
        $state = app(InstalledOperationManager::class)->state(42, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
        self::assertSame('modpack_rejected', $state?->error);
        self::assertSame('The server Egg changed after the modpack was queued.', $state?->result['message']);
    }

    public function test_wizard_requires_confirmation_and_sends_the_generic_egg_the_exact_version(): void
    {
        $this->addEgg(8, 'CurseForge Generic', '019bbf16-a3f3-470a-9c0b-f3995b5e032a', [
            'PROJECT_ID' => '', 'VERSION_ID' => 'latest', 'API_KEY' => '',
        ]);
        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([]),
            'api.curseforge.com/v1/mods/search*' => Http::response([
                'data' => [['id' => 123, 'name' => 'Forge Pack']],
                'pagination' => ['totalCount' => 1],
            ]),
            'api.curseforge.com/v1/mods/123/files?*' => Http::response(['data' => [[
                'id' => 456, 'fileName' => 'selected.zip', 'gameVersions' => ['1.20.1', 'Forge'],
            ]]]),
            'api.curseforge.com/v1/mods/123/files/456' => Http::response(['data' => [
                'id' => 456, 'fileName' => 'selected.zip', 'gameVersions' => ['1.20.1', 'Forge'],
                'downloadUrl' => 'https://edge.forgecdn.net/selected.zip',
            ]]),
        ]);
        $service = Mockery::mock(ServerCreationService::class);
        $service->shouldReceive('handle')->once()->withArgs(function (array $data): bool {
            self::assertSame(8, $data['egg_id']);
            self::assertSame('123', $data['environment']['PROJECT_ID']);
            self::assertSame('456', $data['environment']['VERSION_ID']);
            self::assertSame('test-curseforge-credential', $data['environment']['API_KEY']);

            return true;
        })->andReturn((new Server())->forceFill(['id' => 43]));
        $this->app->instance(ServerCreationService::class, $service);

        Livewire::test(ModpackCreationWizardHarness::class)
            ->set('data.mm_provider', 'curseforge')
            ->set('data.mm_modpack', true)
            ->set('data.mm_project', 'curseforge:123')
            ->set('data.mm_version', '456')
            ->assertDontSee('test-curseforge-credential')
            ->assertSet('data.environment.API_KEY', '********')
            ->call('create')
            ->assertHasErrors('data.mm_confirm_compatibility')
            ->set('data.mm_confirm_compatibility', true)
            ->call('create')
            ->assertHasNoErrors();
    }

    /** @return array<string, array{string, string, string, string, string, array<string, string>}> */
    public static function eggInstallSelections(): array
    {
        return [
            'FTB Server' => [
                'ftb', '18', '12169', 'FTB Server', 'e60a9de8-a0b1-4d97-b4e2-6568f048668d',
                ['FTB_MODPACK_ID' => '18', 'FTB_MODPACK_VERSION_ID' => '12169', 'FTB_SEARCH_TERM' => '', 'FTB_VERSION_STRING' => ''],
            ],
            'Tekkit 2' => [
                'technic', 'tekkit-2', 'v1.2.6', 'Tekkit 2', '81bf842b-5647-4c83-9254-523b6f8308fb',
                ['MODPACK_VERSION' => 'v1.2.6'],
            ],
        ];
    }

    /** @param array<string, string> $variables */
    #[DataProvider('eggInstallSelections')]
    public function test_provider_release_variables_reach_the_server_creation_service(
        string $provider,
        string $project,
        string $version,
        string $eggName,
        string $eggUuid,
        array $variables,
    ): void {
        $this->addEgg(9, $eggName, $eggUuid, array_fill_keys(array_keys($variables), ''));
        Http::fake([
            'api.feed-the-beast.com/v1/modpacks/public/modpack/search/*' => Http::response(['packs' => [18]]),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/18' => Http::response([
                'id' => 18, 'name' => 'FTB Vanilla+',
                'versions' => [
                    ['id' => 12170, 'name' => 'Later release', 'released' => 300],
                    ['id' => 12169, 'name' => '1.1.1', 'released' => 200, 'targets' => [
                        ['name' => 'minecraft', 'version' => '1.7.10', 'type' => 'game'],
                        ['name' => 'forge', 'version' => '10.13.4.1448', 'type' => 'modloader'],
                    ]],
                ],
            ]),
            'api.technicpack.net/search?*' => Http::response(['modpacks' => [['slug' => 'tekkit-2', 'name' => 'Tekkit 2']]]),
            'api.technicpack.net/modpack/tekkit-2?*' => Http::response([
                'name' => 'tekkit-2', 'version' => '1.2.6', 'minecraft' => '1.12.2',
                'serverPackUrl' => 'https://servers.technicpack.net/Technic/servers/tekkit-2/Tekkit-2_Server_v1.2.6.zip',
            ]),
        ]);

        $service = Mockery::mock(ServerCreationService::class);
        $service->shouldReceive('handle')->once()->withArgs(function (array $data) use ($provider, $project, $version, $variables): bool {
            self::assertSame(9, $data['egg_id']);
            self::assertSame($variables, $data['environment']);
            self::assertSame('egg_install', $data['mm_install_plan']['mode']);
            self::assertSame($provider, $data['mm_install_plan']['source']);
            self::assertSame($project, $data['mm_install_plan']['project_id']);
            self::assertSame($version, $data['mm_install_plan']['version_id']);

            return true;
        })->andReturn((new Server())->forceFill(['id' => 49]));
        $this->app->instance(ServerCreationService::class, $service);

        $wizard = Livewire::test(ModpackCreationWizardHarness::class)
            ->set('data.mm_provider', $provider)
            ->set('data.mm_query', $provider === 'ftb' ? 'Vanilla' : 'Tekkit')
            ->set('data.mm_modpack', true)
            ->set('data.mm_project', $provider.':'.$project)
            ->set('data.mm_version', $version);
        if ($provider === 'ftb') {
            $wizard->assertSee('The FTB Egg can report completion after an installer failure.')
                ->assertSet('data.start_on_completion', false)
                ->call('nextModpackStep')->assertHasErrors('data.mm_confirm_compatibility')
                ->set('data.mm_confirm_compatibility', true)
                ->set('data.start_on_completion', true)
                ->call('create')->assertHasErrors('data.mm_project')
                ->set('data.start_on_completion', false);
        }
        $wizard->call('create')->assertHasNoErrors();
    }

    public function test_atlauncher_releases_are_browsable_but_do_not_create_a_server(): void
    {
        Http::fake([
            'api.atlauncher.com/v1/packs/simple' => Http::response(['data' => [
                ['type' => 'public', 'name' => 'SevTech: Ages', 'safeName' => 'SevTechAges'],
            ]]),
            'api.atlauncher.com/v1/pack/SevTechAges/' => Http::response(['data' => [
                'name' => 'SevTech: Ages', 'versions' => [['version' => '3.2.3', 'minecraft' => '1.12.2']],
            ]]),
        ]);
        $service = Mockery::mock(ServerCreationService::class);
        $service->shouldNotReceive('handle');
        $this->app->instance(ServerCreationService::class, $service);

        Livewire::test(ModpackCreationWizardHarness::class)
            ->set('data.mm_provider', 'atlauncher')
            ->set('data.mm_query', 'SevTech')
            ->set('data.mm_modpack', true)
            ->assertSee('SevTech: Ages')
            ->set('data.mm_project', 'atlauncher:SevTechAges')
            ->set('data.mm_version', '3.2.3')
            ->assertSee('ATLauncher has no Pelican egg')
            ->call('create')
            ->assertHasErrors('data.mm_guard');
    }

    public function test_modrinth_client_pack_is_blocked_before_downloading_an_archive(): void
    {
        $release = [
            'id' => 'N276l2ON', 'project_id' => '1KVo5zza', 'version_number' => '6.5.0',
            'game_versions' => ['1.21.1'], 'loaders' => ['fabric'],
            'files' => [['filename' => 'pack.mrpack', 'url' => 'https://cdn.modrinth.com/pack.mrpack', 'primary' => true]],
        ];
        Http::preventStrayRequests();
        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([]),
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [], 'total_hits' => 0]),
            'api.modrinth.com/v2/project/1KVo5zza/version*' => Http::response([$release]),
            'api.modrinth.com/v2/project/1KVo5zza' => Http::response(['id' => '1KVo5zza', 'server_side' => 'unsupported']),
            'api.modrinth.com/v2/version/N276l2ON' => Http::response($release),
        ]);
        $service = Mockery::mock(ServerCreationService::class);
        $service->shouldNotReceive('handle');
        $this->app->instance(ServerCreationService::class, $service);

        Livewire::test(ModpackCreationWizardHarness::class)
            ->set('data.mm_modpack', true)
            ->set('data.mm_project', 'modrinth:1KVo5zza')
            ->set('data.mm_version', 'N276l2ON')
            ->assertSee('Modrinth marks this modpack as unsupported on a dedicated server.')
            ->call('create')
            ->assertHasErrors('data.mm_guard');
        Http::assertNotSent(static fn ($request): bool => str_starts_with($request->url(), 'https://cdn.modrinth.com/'));
    }

    public function test_modrinth_manifest_pins_the_fabric_loader_for_archive_creation(): void
    {
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        $this->addEgg(9, 'Fabric', '78b02ebb-fec8-49c5-943c-ca4aa117b693', [
            'MC_VERSION' => 'latest', 'LOADER_VERSION' => 'latest', 'FABRIC_VERSION' => 'latest',
        ]);
        DB::table('eggs')->where('id', 9)->update(['tags' => '["minecraft","fabric"]']);
        $archive = tempnam(sys_get_temp_dir(), 'modrinth-fixture');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::OVERWRITE));
        $zip->addFromString('modrinth.index.json', json_encode([
            'formatVersion' => 1, 'game' => 'minecraft',
            'dependencies' => ['minecraft' => '1.19.3', 'fabric-loader' => '0.14.12'],
            'files' => [['path' => 'mods/krypton.jar', 'downloads' => ['https://cdn.modrinth.com/krypton.jar']]],
        ], JSON_THROW_ON_ERROR));
        $zip->close();
        $bytes = file_get_contents($archive);
        unlink($archive);
        $release = [
            'id' => 'vtGmZsbn', 'project_id' => 'YkG1DyHE', 'version_number' => '1.0',
            'game_versions' => ['1.19.3'], 'loaders' => ['fabric'],
            'files' => [['filename' => 'pack.mrpack', 'url' => 'https://cdn.modrinth.com/pack.mrpack', 'primary' => true]],
        ];
        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([]),
            'api.modrinth.com/v2/search*' => Http::response([
                'hits' => [['project_id' => 'YkG1DyHE', 'title' => 'Fabric Server Optimization']], 'total_hits' => 1,
            ]),
            'api.modrinth.com/v2/project/YkG1DyHE/version*' => Http::response([$release]),
            'api.modrinth.com/v2/version/vtGmZsbn' => Http::response($release),
            'api.modrinth.com/v2/project/YkG1DyHE' => Http::response(['id' => 'YkG1DyHE', 'server_side' => 'required']),
            'cdn.modrinth.com/pack.mrpack' => Http::response($bytes),
        ]);

        $service = Mockery::mock(ServerCreationService::class);
        $service->shouldReceive('handle')->once()->withArgs(function (array $data): bool {
            self::assertSame('1.19.3', $data['environment']['MC_VERSION']);
            self::assertSame('0.14.12', $data['environment']['LOADER_VERSION']);
            self::assertSame('latest', $data['environment']['FABRIC_VERSION']);
            self::assertSame('vtGmZsbn', $data['mm_install_plan']['version_id']);

            return true;
        })->andReturn((new Server())->forceFill(['id' => 50]));
        $this->app->instance(ServerCreationService::class, $service);

        Livewire::test(ModpackCreationWizardHarness::class)
            ->set('data.mm_modpack', true)
            ->set('data.mm_project', 'modrinth:YkG1DyHE')
            ->set('data.mm_version', 'vtGmZsbn')
            ->assertSet('data.egg_id', 9)
            ->call('create')
            ->assertHasNoErrors();
    }

    /** @param array<string, string> $variables */
    private function addEgg(int $id, string $name, string $uuid, array $variables): void
    {
        DB::table('eggs')->insert([
            'id' => $id, 'name' => $name, 'uuid' => $uuid, 'tags' => '[]',
            'startup_commands' => '["java -jar server.jar"]',
            'docker_images' => '{"Java 21":"ghcr.io/pelican-eggs/yolks:java_21"}',
        ]);
        foreach ($variables as $variable => $default) {
            DB::table('egg_variables')->insert(['egg_id' => $id, 'env_variable' => $variable, 'default_value' => $default]);
        }
    }
}

final class ModpackCreationWizardHarness extends CreateServer
{
    protected ?bool $hasDatabaseTransactions = true;

    /** @return list<Step> */
    protected function getSteps(): array
    {
        return [
            ModpackCreationStep::step(),
            Step::make('Test startup configuration')->schema([
                Hidden::make('node_id')->default(1),
                Hidden::make('egg_id'),
                Hidden::make('startup'),
                Hidden::make('select_startup'),
                Hidden::make('image'),
                Hidden::make('select_image'),
                Hidden::make('skip_scripts')->default(false),
                Hidden::make('start_on_completion')->default(true),
                Hidden::make('server_variables')->default([]),
                Hidden::make('environment')->default([]),
            ]),
        ];
    }

    public function nextModpackStep(): void
    {
        $wizard = $this->form->getComponents()[0];
        if ($wizard instanceof Wizard) {
            $wizard->nextStep(0);
        }
    }

    // The harness uses no users or real servers. The create service is mocked.
    protected function authorizeAccess(): void {}

    public static function authorizeResourceAccess(): void {}

    protected function getRedirectUrl(): string
    {
        return '/';
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return View::file(__DIR__.'/../../Fixtures/modpack-creation-wizard.blade.php');
    }
}
