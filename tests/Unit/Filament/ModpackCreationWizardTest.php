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
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

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

    public function test_wizard_submits_the_selected_release_and_queues_the_archive_after_creation(): void
    {
        $runner = PluginBackgroundRunner::fake();
        $this->app->instance(PluginBackgroundRunner::class, $runner);
        $this->addEgg(7, 'NeoForge', 'e23e092f-b803-4f34-82cf-2d6518c6351a', [
            'MC_VERSION' => 'latest',
            'NEOFORGE_VERSION' => 'latest',
        ]);
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
                'gameVersions' => ['1.21.1', 'NeoForge'],
            ]]),
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
            ->assertSet('data.environment.MC_VERSION', '1.21.1')
            ->assertSee('could not determine the required loader build')
            ->call('nextModpackStep')
            ->assertHasErrors('data.mm_confirm_compatibility')
            ->set('data.mm_confirm_compatibility', true)
            ->call('nextModpackStep')
            ->assertHasNoErrors()
            ->set('data.mm_modpack', false)
            ->assertDontSee('All the Mods 10')
            ->assertSet('data.egg_id', null)
            ->set('data.mm_modpack', true)
            ->assertSet('data.mm_query', 'All the Mods')
            ->assertSet('data.mm_minecraft', '1.21.1')
            ->assertSet('data.mm_version', '9620701');

        $wizard->set('data.mm_confirm_compatibility', true)
            ->set('data.environment.NEOFORGE_VERSION', '21.1.266')
            ->set('data.start_on_completion', true)->call('create')->assertHasErrors('data.mm_project');
        self::assertSame([], $runner->spawned);
        $wizard->set('data.start_on_completion', false)->call('create')->assertHasNoErrors();

        self::assertCount(1, $runner->spawned);
        self::assertSame(BackgroundJob::MODPACK, $runner->spawned[0]['type']);
        self::assertSame('9620701', $runner->spawned[0]['payload']['version_id']);
        self::assertSame('925200', $runner->spawned[0]['payload']['project_id']);
        self::assertSame([
            'egg_id' => '7', 'minecraft' => '1.21.1', 'loader' => 'neoforge', 'server_pack' => true,
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
