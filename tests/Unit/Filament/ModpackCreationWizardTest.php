<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use App\Enums\ContainerStatus;
use App\Enums\ServerState;
use App\Events\Server\Installed;
use App\Filament\Admin\Resources\Servers\Pages\CreateServer;
use App\Http\Controllers\Api\Remote\Servers\ServerDetailsController;
use App\Http\Controllers\Api\Remote\Servers\ServerInstallController;
use App\Http\Requests\Api\Remote\InstallationDataRequest;
use App\Models\Backup;
use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Backups\InitiateBackupService;
use App\Services\Eggs\EggConfigurationService;
use App\Services\Servers\ServerConfigurationStructureService;
use App\Services\Servers\ServerCreationService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Filament\Admin\ModpackCreationStep;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Providers\ModManagerServiceProvider;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackInstallCoordinator;
use Kazaminosuke\ModManager\Services\ModpackInventory;
use Kazaminosuke\ModManager\Services\ModpackLoaderInstaller;
use Kazaminosuke\ModManager\Services\ModpackProvisioning;
use Kazaminosuke\ModManager\Services\ModpackUpdateWorkflow;
use Kazaminosuke\ModManager\Support\Modpacks\FtbReleaseManifest;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Kazaminosuke\ModManager\Tests\Unit\Services\DeploymentWings;
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
        ModpackCreationStep::configureStartupFields();
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
            self::assertSame('java -Xms128M -XX:MaxRAMPercentage=95.0 @unix_args.txt nogui', $data['startup']);
            self::assertSame('ghcr.io/pelican-eggs/yolks:java_21', $data['image']);
            self::assertSame(0, $data['start_on_completion']);
            self::assertSame('9620701', $data['mm_install_plan']['version_id']);

            return true;
        })->andReturn((new Server())->forceFill(['id' => 42]));
        $this->app->instance(ServerCreationService::class, $service);

        $wizard = Livewire::test(ModpackCreationWizardHarness::class)
            ->assertDontSee('All the Mods 10')
            ->assertFormFieldIsVisible('skip_scripts')
            ->assertFormFieldIsVisible('start_on_completion')
            ->set('data.mm_provider', 'curseforge')
            ->set('data.mm_modpack', true)
            ->set('data.mm_query', 'All the Mods')
            ->set('data.mm_minecraft', '1.21.1')
            ->set('data.mm_loader', 'neoforge')
            ->assertSee('All the Mods 10')
            ->set('data.mm_project', 'curseforge:925200')
            ->set('data.mm_version', '9620701')
            ->assertSet('data.egg_id', 7)
            ->assertSet('data.environment.MC_VERSION', '1.21.1');
        if ($manifestAvailable) {
            $wizard->assertSet('data.skip_scripts', '1')->assertSet('data.start_on_completion', '0')
                ->assertSet('data.environment.NEOFORGE_VERSION', '21.1.266')
                ->assertDontSee('could not determine the required loader build');
        } else {
            $wizard->call('nextModpackStep')->assertHasErrors('data.mm_confirm_compatibility')
                ->set('data.mm_confirm_compatibility', true);
        }
        $wizard->call('nextModpackStep')
            ->assertHasNoErrors()
            ->assertFormFieldIsHidden('skip_scripts')
            ->assertFormFieldIsHidden('start_on_completion')
            ->set('data.mm_modpack', false)
            ->assertDontSee('All the Mods 10')
            ->assertFormFieldIsVisible('skip_scripts')
            ->assertSet('data.egg_id', null)
            ->set('data.mm_modpack', true)
            ->assertSet('data.mm_query', 'All the Mods')
            ->assertSet('data.mm_minecraft', '1.21.1')
            ->assertSet('data.mm_version', '9620701');

        if (!$manifestAvailable) {
            $wizard->set('data.mm_confirm_compatibility', true)
                ->set('data.environment.NEOFORGE_VERSION', '21.1.266');
        }
        $wizard->set('data.start_on_completion', '1')->call('create')->assertHasErrors('data.mm_project');
        self::assertSame([], $runner->spawned);
        $wizard->set('data.start_on_completion', '0')
            ->set('data.image', 'ghcr.io/pelican-eggs/yolks:java_17')->call('create')->assertHasErrors('data.image')
            ->set('data.image', 'ghcr.io/pelican-eggs/yolks:java_21')->call('create')->assertHasNoErrors();

        self::assertCount(1, $runner->spawned);
        self::assertSame(BackgroundJob::MODPACK, $runner->spawned[0]['type']);
        self::assertSame('9620701', $runner->spawned[0]['payload']['version_id']);
        self::assertSame('925200', $runner->spawned[0]['payload']['project_id']);
        self::assertSame([
            'egg_id' => '7', 'image' => 'ghcr.io/pelican-eggs/yolks:java_21', 'startup' => 'java -Xms128M -XX:MaxRAMPercentage=95.0 @unix_args.txt nogui',
            'minecraft' => '1.21.1', 'loader' => 'neoforge', 'managed_initial' => true, 'loader_version' => '21.1.266', 'server_pack' => true,
            'artifact_id' => '9620727', 'title' => 'All the Mods 10',
            'variables' => ['MC_VERSION' => '1.21.1', 'NEOFORGE_VERSION' => '21.1.266'],
        ], $runner->spawned[0]['payload']['selection']);
        self::assertArrayNotHasKey('defer_until', $runner->spawned[0]['payload']);
    }

    public function test_resuming_a_curseforge_modpack_rejects_different_archive_bytes_before_wings(): void
    {
        Http::fake([
            'https://api.curseforge.com/v1/mods/285109/files/4612979' => Http::response(['data' => [
                'id' => 4612979, 'modId' => 285109, 'fileName' => 'RLCraft.zip', 'downloadUrl' => 'https://edge.forgecdn.net/selected-pack.zip',
            ]]),
            'https://api.curseforge.com/v1/mods/285109/files?*' => Http::response(['data' => []]),
            'https://edge.forgecdn.net/selected-pack.zip' => Http::response('changed archive bytes'),
        ]);
        $repository = Mockery::mock(DaemonFileRepository::class);
        $repository->shouldNotReceive('setServer');
        $this->app->instance(DaemonFileRepository::class, $repository);
        $runner = PluginBackgroundRunner::fake();
        $this->app->instance(PluginBackgroundRunner::class, $runner);
        $this->addEgg(7, 'Forge', '5cdecd89-31e8-4ebe-93e7-89272c76aebd', []);
        DB::table('servers')->insert(['id' => 42, 'egg_id' => 7]);
        $manager = app(InstalledOperationManager::class);
        $manager->dispatchModpackInstall(42, ProjectType::Mod, 'curseforge', '285109', '4612979');
        $manager->pauseCurseForgeModpack(42, $runner->spawned[0]['payload']['lease_token'], '285109', '4612979', [
            'egg_id' => '7', 'server_pack' => false, 'artifact_id' => '4612979',
            'archive_sha256' => hash('sha256', 'approved archive bytes'),
        ], []);
        $plan = $manager->waitingCurseForgeModpack(42);
        self::assertTrue($manager->resumeCurseForgeModpack(42, $plan['token']));

        BackgroundJob::execute(BackgroundJob::MODPACK, $runner->spawned[1]['payload']);

        $state = $manager->state(42, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
        self::assertSame('modpack_rejected', $state?->error);
        self::assertSame('The downloaded CurseForge archive no longer matches the selected file.', $state?->result['message']);
        self::assertSame($plan, $manager->waitingCurseForgeModpack(42));
        Http::assertSentCount(3);
    }

    public static function existingUpdateDependencies(): array
    {
        return [
            'update without manual files' => [false],
            'update after manual upload' => [true],
            'new server with loader' => [false, true],
            'new server with incomplete loader' => [false, true, true],
        ];
    }

    #[DataProvider('existingUpdateDependencies')]
    public function test_archive_job_verifies_runtime_and_updates_through_the_background_entry_point(bool $manual, bool $initial = false, bool $missingRuntime = false): void
    {
        [$server, $wings, $runner] = $this->archiveJobFixtures();
        $inventory = app(ModpackInventory::class);
        if ($missingRuntime) {
            unset($wings->files['libraries/net/fabricmc/fabric-loader/0.15.0/fabric-loader-0.15.0.jar']);
        }

        if (!$initial) {
            $wings->files += ['config/old.toml' => 'default-v1', 'mods/old.bin' => 'original'];
            $inventory->begin($server, null, 'initial', null);
            $inventory->finish($server, 'initial', ['provider' => 'curseforge', 'pack_id' => '285109', 'version_id' => 'old', 'files' => [
                'config/old.toml' => ['identity' => hash('sha256', 'default-v1'), 'sha256' => hash('sha256', 'default-v1')],
                'mods/old.bin' => ['identity' => 'old', 'sha256' => hash('sha256', 'original')],
            ]]);
        }

        $zip = new ZipArchive();
        $jar = tempnam(sys_get_temp_dir(), 'managed-update-jar');
        $zip->open($jar, ZipArchive::OVERWRITE);
        $zip->addFromString('fixture/Main.class', 'test bytes');
        $zip->close();
        $jarBytes = file_get_contents($jar);
        unlink($jar);
        $archive = tempnam(sys_get_temp_dir(), 'managed-update-job');
        $zip->open($archive, ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode([
            'manifestType' => 'minecraftModpack', 'files' => $manual ? [['projectID' => 238222, 'fileID' => 3043174, 'required' => true]] : [],
            'minecraft' => ['version' => '1.20.1', 'modLoaders' => [['id' => 'fabric-0.15.0', 'primary' => true]]],
        ], JSON_THROW_ON_ERROR));
        $zip->addFromString('overrides/config/old.toml', 'default-v2');
        $zip->close();
        $archiveBytes = file_get_contents($archive);
        unlink($archive);
        Http::fake([
            'https://api.curseforge.com/v1/mods/285109/files/4612979' => Http::response(['data' => [
                'id' => 4612979, 'modId' => 285109, 'fileName' => 'RLCraft.zip', 'downloadUrl' => 'https://edge.forgecdn.net/job-pack.zip',
                'gameVersions' => ['1.20.1', 'Fabric'],
            ]]),
            'https://api.curseforge.com/v1/mods/285109/files?*' => Http::response(['data' => []]),
            'https://api.curseforge.com/v1/mods/238222/files/3043174' => Http::response(['data' => [
                'id' => 3043174, 'modId' => 238222, 'fileName' => 'manual.jar', 'downloadUrl' => null,
                'fileLength' => strlen($jarBytes), 'hashes' => [['algo' => 1, 'value' => sha1($jarBytes)]],
            ]]),
            'https://api.curseforge.com/v1/mods' => Http::response(['data' => []]),
            'https://edge.forgecdn.net/job-pack.zip' => static fn () => Http::response($archiveBytes),
        ]);

        $operations = app(InstalledOperationManager::class);
        $workflow = app(ModpackUpdateWorkflow::class);
        $selection = ['managed_phase' => $initial ? 'initial' : 'preview', 'egg_id' => '7', 'image' => $server->image, 'variables' => app(InstalledEggCatalog::class)->variableValues($server),
            'minecraft' => '1.20.1', 'loader' => 'fabric', 'loader_version' => '0.15.0', 'server_pack' => false, 'artifact_id' => '4612979'];
        self::assertTrue($operations->dispatchModpackInstall($server, ProjectType::Mod, 'curseforge', '285109', '4612979', selection: $selection)['dispatched']);
        BackgroundJob::execute(BackgroundJob::MODPACK, $runner->spawned[0]['payload']);
        if ($initial) {
            $state = $operations->state($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
            self::assertSame($missingRuntime ? 'failed' : 'completed', $state?->status, (string) ($state?->result['message'] ?? ''));
            if ($missingRuntime) {
                self::assertNull($inventory->read($server)['inventory']);
                self::assertArrayNotHasKey('config/old.toml', $wings->files);
            } else {
                self::assertSame('4612979', $inventory->read($server)['inventory']['version_id']);
                self::assertSame('default-v2', $wings->files['config/old.toml']);
            }

            return;
        }
        if ($manual) {
            $pending = $operations->waitingCurseForgeModpack($server);
            self::assertSame('4612979', $pending['version_id']);
            self::assertNull($workflow->review($server));
            self::assertArrayNotHasKey('mods/manual.jar', $wings->files);
            $wings->files['.mod-manager-curseforge/238222-3043174.jar'] = $jarBytes;
            self::assertTrue($operations->resumeCurseForgeModpack($server, $pending['token']));
            BackgroundJob::execute(BackgroundJob::MODPACK, $runner->spawned[1]['payload']);
            self::assertNull($operations->waitingCurseForgeModpack($server));
        }
        $preview = $workflow->review($server);
        $state = $operations->state($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
        self::assertSame('waiting_review', $state?->status, (string) ($state?->result['message'] ?? ''));
        self::assertSame(1, $preview['summary']['mods']['delete']);
        self::assertSame('original', $wings->files['mods/old.bin']);
        $this->createUpdateBackup($server, $preview['token']);
        $workflow->approve($server, $preview['token'], false, null);
        BackgroundJob::execute(BackgroundJob::MODPACK, $runner->spawned[$manual ? 2 : 1]['payload']);
        $state = $operations->state($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
        self::assertSame('completed', $state?->status, (string) ($state?->result['message'] ?? ''));
        self::assertSame('backup-42', $state->result['backup']);
        self::assertFalse(Backup::query()->where('uuid', 'backup-42')->firstOrFail()->is_locked);
        self::assertCount($manual ? 2 : 1, Http::recorded(fn ($request) => $request->url() === 'https://edge.forgecdn.net/job-pack.zip'));
        self::assertSame('default-v2', $wings->files['config/old.toml']);
        self::assertArrayNotHasKey('mods/old.bin', $wings->files);
        self::assertNull($workflow->review($server));
        self::assertSame('4612979', $inventory->read($server)['inventory']['version_id']);
        if ($manual) {
            self::assertSame($jarBytes, $wings->files['mods/manual.jar']);
            self::assertSame('3043174', $inventory->read($server)['inventory']['files']['mods/manual.jar']['file_id']);
        }
    }

    /** @return array<string, array{string, ?string}> */
    public static function ftbJobOutcomes(): array
    {
        return [
            'fixed release installed' => ['success', null],
            'manifest HTTP failure' => ['http_error', 'could not be downloaded'],
            'successful HTTP response without files' => ['no_files', 'complete manifest'],
            'release does not belong to selected pack' => ['wrong_pack', 'complete manifest'],
            'provider returns a different release' => ['wrong_release', 'complete manifest'],
            'provider reports an error within HTTP 200' => ['logical_error', 'complete manifest'],
            'required file has no checksum' => ['missing_hash', 'SHA-1'],
            'required file would replace loader files' => ['unmanaged_path', 'no supported server path'],
            'contents changed after selecting' => ['changed', 'changed the contents'],
            'startup JAR does not exist' => ['no_launcher', '[server.jar] could not be read'],
            'installed loader has the wrong version' => ['wrong_loader', 'does not reference this Minecraft and loader build'],
            'file pull returns success without a file' => ['missing_file', 'Missing file'],
            'file has the right size but the wrong hash' => ['bad_hash', 'published hash'],
            'empty file has the wrong hash' => ['bad_empty_hash', 'published hash'],
            'partial download then retry' => ['interrupted', 'Could not download and verify'],
            'configured Java too old' => ['wrong_java', 'Java 25'],
            'managed loader then pack completes Panel installation' => ['managed_success', null],
            'managed loader fails before pack download' => ['managed_loader_failed', 'managed loader installation did not finish'],
            'managed pack fails after loader completion' => ['managed_pack_failed', 'could not be downloaded'],
        ];
    }

    #[DataProvider('ftbJobOutcomes')]
    public function test_ftb_job_verifies_the_loader_and_every_file_before_recording_success(string $scenario, ?string $failure): void
    {
        [$server, $wings, $runner] = $this->archiveJobFixtures($scenario === 'wrong_loader' ? '0.15.1' : '0.15.0');
        $mod = $wings->files['server.jar']; // Any real ZIP is sufficient for the staging signature check.
        if ($scenario === 'no_launcher') {
            unset($wings->files['server.jar']);
        }
        $wings->files += ['server.properties' => 'level-name=world', 'world/level.dat' => 'saved world'];
        $runtimeFiles = $wings->files;
        $release = $this->ftbRelease('89', 'initial', $mod, $scenario === 'wrong_java' ? '25.0.1' : '17.0.6');
        $release['files'][] = [
            'path' => './config', 'name' => 'empty.toml', 'size' => 0,
            'sha1' => sha1($scenario === 'bad_empty_hash' ? 'not empty' : ''),
            'url' => 'https://ftb-files.example.test/empty.toml',
        ];
        $fingerprint = FtbReleaseManifest::fingerprint(FtbReleaseManifest::read(json_encode($release, JSON_THROW_ON_ERROR), '5', '89'));
        switch ($scenario) {
            case 'no_files':
                unset($release['files']);
                break;
            case 'wrong_pack':
                $release['parent'] = 42;
                break;
            case 'wrong_release':
                $release['id'] = 90;
                break;
            case 'logical_error':
                $release['status'] = 'error';
                break;
            case 'missing_hash':
                unset($release['files'][0]['sha1']);
                break;
            case 'unmanaged_path':
                $release['files'][0]['path'] = './libraries';
                break;
            case 'changed':
                $release['files'][0]['sha1'] = sha1('changed');
                break;
            case 'missing_file':
                $wings->missingDownload = 'https://ftb-files.example.test/example.jar';
                break;
            case 'bad_hash':
                $mod = substr($mod, 0, -1).(substr($mod, -1) === 'x' ? 'y' : 'x');
                break;
            case 'interrupted':
                $wings->interruptedDownload = 'https://ftb-files.example.test/example.jar';
                break;
        }
        $wings->downloadFiles = ['https://ftb-files.example.test/pack.cfg' => 'initial', 'https://ftb-files.example.test/example.jar' => $mod];
        $url = 'https://api.feed-the-beast.com/v1/modpacks/public/modpack/5/89';
        Http::fake([$url => static fn () => in_array($scenario, ['http_error', 'managed_pack_failed'], true)
            ? Http::response([], 503) : Http::response($release)]);
        $selection = ['managed_phase' => 'initial', 'egg_id' => '7', 'image' => $server->image,
            'variables' => app(InstalledEggCatalog::class)->variableValues($server), 'minecraft' => '1.20.1', 'loader' => 'fabric',
            'server_pack' => false, 'artifact_id' => '89', 'manifest_fingerprint' => $fingerprint];
        $managed = str_starts_with($scenario, 'managed_');
        if ($managed) {
            Event::fake();
            Schema::table('servers', function (Blueprint $table): void {
                $table->integer('node_id')->default(8);
                $table->boolean('skip_scripts')->default(true);
                $table->timestamp('installed_at')->nullable();
                $table->timestamps();
            });
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_25_000007_create_mod_manager_provisioning_table.php';
            $migration->up();
            DB::table('servers')->where('id', 42)->update(['status' => ServerState::Installing->value]);
            $server->refresh();
            $selection += ['managed_initial' => true, 'loader_version' => '0.15.0'];
            $provisioning = app(ModpackProvisioning::class);
            $provisioning->stage($server, ModpackLoaderInstaller::forRelease('fabric', '1.20.1', '0.15.0', 'server.jar'));
        }
        $operations = app(InstalledOperationManager::class);
        $inventory = app(ModpackInventory::class);
        $dispatch = fn () => $operations->dispatchModpackInstall($server, ProjectType::Mod, 'ftb', '5', '89', selection: $selection);
        self::assertTrue($dispatch()['dispatched']);
        if ($managed) {
            self::assertSame(15, BackgroundJob::execute(BackgroundJob::MODPACK, $runner->spawned[0]['payload']));
            Http::assertNothingSent();
            $server->setRelation('node', $node = (new Node())->forceFill(['id' => 8]));
            $controller = app(ServerInstallController::class);
            self::assertTrue($provisioning->needsInstaller($server));
            $request = Request::create('/install');
            $request->attributes->set('node', $node);
            $server->setRelation('transfer', null);
            $this->mock(ServerConfigurationStructureService::class)->shouldReceive('handle')->andReturn(['skip_egg_scripts' => true]);
            $this->mock(EggConfigurationService::class)->shouldReceive('handle')->andReturn([]);
            $details = app(ServerDetailsController::class);
            self::assertFalse($details($request, $server)->getData()->settings->skip_egg_scripts);
            self::assertTrue($server->fresh()->skip_scripts);
            self::assertStringContainsString('maven.fabricmc.net', $controller->index($request, $server)->getData()->script);
            $callback = InstallationDataRequest::create('/install', 'POST', ['successful' => $scenario !== 'managed_loader_failed']);
            $callback->attributes->set('node', $node);
            self::assertSame(204, $controller->store($callback, $server)->getStatusCode());
            self::assertTrue($details($request, $server)->getData()->settings->skip_egg_scripts);
            if ($scenario !== 'managed_loader_failed') {
                self::assertSame(ServerState::Installing, $server->fresh()->status);
                self::assertNull($server->fresh()->installed_at);
                self::assertFalse($provisioning->needsInstaller($server));
                Event::assertNotDispatched(Installed::class);
                $provisioning->duringNodeReset(8, static fn () => DB::table('servers')->where('node_id', 8)->update(['status' => null]));
                self::assertSame(ServerState::Installing, $server->fresh()->status);
            }
        }
        BackgroundJob::execute(BackgroundJob::MODPACK, $runner->spawned[0]['payload']);
        if ($managed) {
            self::assertSame($failure === null ? null : ServerState::InstallFailed, $server->fresh()->status);
            self::assertSame($failure === null, $server->fresh()->installed_at !== null);
            self::assertSame($failure === null ? ModpackProvisioning::COMPLETE : ModpackProvisioning::FAILED, $provisioning->get($server)->state);
            Event::assertDispatched(Installed::class);
            if ($scenario === 'managed_loader_failed') {
                $server->unsetRelation('node')->unsetRelation('transfer')->refresh();
                $server->status = ServerState::Installing;
                $server->saveOrFail();
                $reinstall = InstallationDataRequest::create('/install', 'POST', ['successful' => true, 'reinstall' => true]);
                $reinstall->attributes->set('node', $node);
                $controller->store($reinstall, $server->setRelation('node', $node));
                self::assertSame(ServerState::InstallFailed, $server->fresh()->status);
                self::assertNull($server->fresh()->installed_at);
            }
        }
        if ($failure !== null) {
            $state = $operations->state($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
            self::assertSame('failed', $state?->status);
            self::assertStringContainsStringIgnoringCase($failure, (string) $state->result['message']);
            self::assertNull($inventory->read($server)['inventory']);
            self::assertNull($inventory->read($server)['recovery']);
            self::assertSame($runtimeFiles, $wings->files);
            if ($scenario !== 'interrupted') {
                Http::assertSentCount($scenario === 'managed_loader_failed' ? 0 : 1);

                return;
            }
            $wings->interruptedDownload = null;
            self::assertTrue($dispatch()['dispatched']);
            BackgroundJob::execute(BackgroundJob::MODPACK, $runner->spawned[1]['payload']);
        }

        $state = $operations->state($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
        self::assertSame('completed', $state?->status, (string) ($state?->result['message'] ?? ''));
        $installed = $inventory->read($server)['inventory'];
        self::assertSame('89', $installed['version_id']);
        self::assertSame('0.15.0', $installed['loader_version']);
        self::assertSame('fixture-89', $installed['version_name']);
        self::assertSame('initial', $wings->files['config/pack.cfg']);
        self::assertSame('', $wings->files['config/empty.toml']);
        self::assertSame(hash('sha256', ''), $installed['files']['config/empty.toml']['sha256']);
        self::assertSame(hash('sha256', $mod), $installed['files']['mods/example.jar']['sha256']);
        self::assertSame('level-name=world', $wings->files['server.properties']);
        self::assertSame('saved world', $wings->files['world/level.dat']);
        self::assertArrayNotHasKey('mods/client.jar', $wings->files);
        Http::assertSentCount($scenario === 'interrupted' ? 2 : 1);
    }

    public function test_existing_ftb_server_requires_the_same_runtime_and_updates_through_review_and_backup(): void
    {
        [$server, $wings, $runner] = $this->archiveJobFixtures();
        $wings->files += ['config/pack.cfg' => 'old', 'kubejs/server_scripts/old.js' => 'old script',
            'config/custom.cfg' => 'operator configuration', 'world/level.dat' => 'saved world', 'mods/operator.jar' => 'operator mod'];
        $inventory = app(ModpackInventory::class);
        $inventory->begin($server, null, 'initial', null);
        $inventory->finish($server, 'initial', ['provider' => 'ftb', 'pack_id' => '5', 'version_id' => '88', 'files' => [
            'config/pack.cfg' => ['identity' => 'old', 'sha256' => hash('sha256', 'old')],
            'kubejs/server_scripts/old.js' => ['identity' => 'old script', 'sha256' => hash('sha256', 'old script')],
        ]]);
        $release = $this->ftbRelease('89', 'updated', java: '17.0.6');
        $incompatible = $this->ftbRelease('90', 'updated', java: '17.0.6');
        $incompatible['targets'][1]['version'] = '0.15.1';
        $url = 'https://api.feed-the-beast.com/v1/modpacks/public/modpack/5';
        Http::fake([
            $url => Http::response($this->ftbPack()),
            $url.'/89' => static fn () => Http::response($release),
            $url.'/90' => static fn () => Http::response($incompatible),
        ]);
        $wings->downloadFiles = ['https://ftb-files.example.test/pack.cfg' => 'updated'];
        $coordinator = app(ModpackInstallCoordinator::class);
        try {
            $coordinator->queuePreview($server, 'ftb:5', '90');
            self::fail('A release that requires another loader build should be rejected before dispatch.');
        } catch (ModpackException $error) {
            self::assertStringContainsString('0.15.1', $error->getMessage());
        }
        self::assertSame([], $runner->spawned);

        $coordinator->queuePreview($server, 'ftb:5', '89');
        BackgroundJob::execute(BackgroundJob::MODPACK, $runner->spawned[0]['payload']);
        $workflow = app(ModpackUpdateWorkflow::class);
        $preview = $workflow->review($server);
        $state = app(InstalledOperationManager::class)->state($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
        self::assertNotNull($preview, (string) ($state?->result['message'] ?? ''));
        self::assertSame(['config/pack.cfg' => 'replace', 'kubejs/server_scripts/old.js' => 'delete'], $preview['summary']['paths']);
        self::assertSame('old', $wings->files['config/pack.cfg']);

        $this->createUpdateBackup($server, $preview['token']);
        $workflow->approve($server, $preview['token'], false, null);
        BackgroundJob::execute(BackgroundJob::MODPACK, $runner->spawned[1]['payload']);
        $state = app(InstalledOperationManager::class)->state($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
        self::assertSame('completed', $state?->status, (string) ($state?->result['message'] ?? ''));
        self::assertSame('backup-42', $state->result['backup']);
        self::assertSame('89', $inventory->read($server)['inventory']['version_id']);
        self::assertSame('updated', $wings->files['config/pack.cfg']);
        self::assertSame('operator configuration', $wings->files['config/custom.cfg']);
        self::assertSame('operator mod', $wings->files['mods/operator.jar']);
        self::assertSame('saved world', $wings->files['world/level.dat']);
        self::assertArrayNotHasKey('kubejs/server_scripts/old.js', $wings->files);
        self::assertCount(2, Http::recorded(static fn ($request): bool => str_ends_with($request->url(), '/5/89')));
    }

    public function test_wizard_requires_confirmation_and_uses_archive_even_with_curseforge_generic_installed(): void
    {
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        $this->addEgg(8, 'CurseForge Generic', '019bbf16-a3f3-470a-9c0b-f3995b5e032a', [
            'PROJECT_ID' => '', 'VERSION_ID' => 'latest', 'API_KEY' => '',
        ]);
        $this->addEgg(7, 'Forge', 'ed072427-f209-4603-875c-f540c6dd5a65', ['MC_VERSION' => 'latest', 'FORGE_VERSION' => 'latest']);
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
            self::assertSame(7, $data['egg_id']);
            self::assertSame('1.20.1', $data['environment']['MC_VERSION']);
            self::assertSame('archive', $data['mm_install_plan']['mode']);
            self::assertSame('456', $data['mm_install_plan']['version_id']);
            self::assertArrayNotHasKey('API_KEY', $data['environment']);

            return true;
        })->andReturn((new Server())->forceFill(['id' => 43]));
        $this->app->instance(ServerCreationService::class, $service);

        Livewire::test(ModpackCreationWizardHarness::class)
            ->set('data.mm_provider', 'curseforge')
            ->set('data.mm_modpack', true)
            ->set('data.mm_project', 'curseforge:123')
            ->set('data.mm_version', '456')
            ->assertDontSee('test-curseforge-credential')
            ->assertSet('data.egg_id', 7)
            ->call('create')
            ->assertHasErrors('data.mm_confirm_compatibility')
            ->set('data.mm_confirm_compatibility', true)
            ->call('create')
            ->assertHasErrors('data.mm_project')
            ->set('data.environment.FORGE_VERSION', '1.20.1-47.4.10')
            ->call('create')
            ->assertHasNoErrors();
    }

    public function test_technic_release_variables_reach_the_server_creation_service(): void
    {
        $this->addEgg(9, 'Tekkit 2', '81bf842b-5647-4c83-9254-523b6f8308fb', ['MODPACK_VERSION' => '']);
        Http::fake([
            'api.technicpack.net/search?*' => Http::response(['modpacks' => [['slug' => 'tekkit-2', 'name' => 'Tekkit 2']]]),
            'api.technicpack.net/modpack/tekkit-2?*' => Http::response([
                'name' => 'tekkit-2', 'version' => '1.2.6', 'minecraft' => '1.4.7',
                'solder' => 'https://solder.technicpack.net/api/',
                'serverPackUrl' => 'https://servers.technicpack.net/Technic/servers/tekkit-2/Tekkit-2_Server_v1.2.6.zip',
            ]),
            'solder.technicpack.net/api/modpack/tekkit-2/1.2.6' => Http::response(['minecraft' => '1.12.2']),
        ]);

        $service = Mockery::mock(ServerCreationService::class);
        $service->shouldReceive('handle')->once()->withArgs(function (array $data): bool {
            self::assertSame(9, $data['egg_id']);
            self::assertSame(['MODPACK_VERSION' => 'v1.2.6'], $data['environment']);
            self::assertSame('egg_install', $data['mm_install_plan']['mode']);
            self::assertSame('technic', $data['mm_install_plan']['source']);
            self::assertSame('tekkit-2', $data['mm_install_plan']['project_id']);
            self::assertSame('v1.2.6', $data['mm_install_plan']['version_id']);

            return true;
        })->andReturn((new Server())->forceFill(['id' => 49]));
        $this->app->instance(ServerCreationService::class, $service);

        Livewire::test(ModpackCreationWizardHarness::class)
            ->set('data.mm_provider', 'technic')
            ->set('data.mm_query', 'Tekkit')
            ->set('data.mm_modpack', true)
            ->set('data.mm_project', 'technic:tekkit-2')
            ->set('data.mm_version', 'v1.2.6')
            ->call('create')
            ->assertHasNoErrors();
    }

    /** @return array<string, array{bool}> */
    public static function ftbWizardPlans(): array
    {
        return ['verified manifest' => [true], 'missing file list' => [false]];
    }

    #[DataProvider('ftbWizardPlans')]
    public function test_ftb_wizard_uses_a_loader_egg_only_for_a_verifiable_fixed_release(bool $complete): void
    {
        $runner = PluginBackgroundRunner::fake();
        $this->app->instance(PluginBackgroundRunner::class, $runner);
        $this->addEgg(8, 'FTB Server', 'e60a9de8-a0b1-4d97-b4e2-6568f048668d',
            ['FTB_MODPACK_ID' => '', 'FTB_MODPACK_VERSION_ID' => '', 'FTB_SEARCH_TERM' => '', 'FTB_VERSION_STRING' => '']);
        $this->addEgg(9, 'Fabric', '78b02ebb-fec8-49c5-943c-ca4aa117b693', ['MC_VERSION' => 'latest', 'LOADER_VERSION' => 'latest']);
        DB::table('eggs')->where('id', 9)->update(['tags' => '["minecraft","fabric"]']);
        $release = $this->ftbRelease('89', 'initial');
        if (!$complete) {
            unset($release['files']);
        }
        Http::fake([
            'api.feed-the-beast.com/v1/modpacks/public/modpack/search/*' => Http::response(['packs' => [5]]),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/5' => Http::response($this->ftbPack()),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/5/89' => Http::response($release),
        ]);

        $service = Mockery::mock(ServerCreationService::class);
        if ($complete) {
            $service->shouldReceive('handle')->once()->withArgs(function (array $data): bool {
                self::assertSame(9, $data['egg_id']);
                self::assertSame(['MC_VERSION' => '1.20.1', 'LOADER_VERSION' => '0.15.0'], $data['environment']);
                self::assertSame('ghcr.io/pelican-eggs/yolks:java_21', $data['image']);
                self::assertSame(0, (int) $data['start_on_completion']);
                $plan = $data['mm_install_plan'];
                self::assertSame('archive', $plan['mode']);
                self::assertSame('ftb', $plan['source']);
                self::assertSame('5', $plan['project_id']);
                self::assertSame('89', $plan['version_id']);
                self::assertSame('89', $plan['selection']['artifact_id']);
                self::assertSame('Example FTB Pack', $plan['selection']['title']);
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $plan['selection']['manifest_fingerprint']);

                return true;
            })->andReturn((new Server())->forceFill(['id' => 49]));
        } else {
            $service->shouldNotReceive('handle');
        }
        $this->app->instance(ServerCreationService::class, $service);
        $wizard = Livewire::test(ModpackCreationWizardHarness::class)
            ->set('data.mm_provider', 'ftb')
            ->set('data.mm_query', 'Example')
            ->set('data.mm_modpack', true)
            ->set('data.mm_project', 'ftb:5')
            ->set('data.mm_version', '89');
        if ($complete) {
            $wizard->assertSet('data.egg_id', 9)->call('create')->assertHasNoErrors();
            self::assertCount(1, $runner->spawned);
            self::assertSame('89', $runner->spawned[0]['payload']['version_id']);
            self::assertSame('ftb', $runner->spawned[0]['payload']['source']);
        } else {
            self::assertStringContainsString(
                'FTB did not provide a complete manifest',
                (string) ModpackCreationStep::evaluateSelection('ftb:5', '89')?->reasonDetail,
            );
            $wizard->call('create')->assertHasErrors();
            self::assertSame([], $runner->spawned);
        }
        Http::assertNotSent(static fn ($request): bool => str_ends_with($request->url(), '/5/90'));
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
            ->call('create')
            ->assertHasErrors('data.mm_guard');
        self::assertStringContainsString(
            'ATLauncher has no Pelican egg',
            (string) ModpackCreationStep::evaluateSelection('atlauncher:SevTechAges', '3.2.3')?->reasonDetail,
        );
    }

    public function test_modrinth_client_pack_can_only_continue_with_a_loader_egg_and_confirmation(): void
    {
        $runner = PluginBackgroundRunner::fake();
        $this->app->instance(PluginBackgroundRunner::class, $runner);
        $this->addEgg(8, 'Modrinth Generic', '04befa18-37cf-41e9-b04d-89b3578bba6d', ['PROJECT_ID' => '', 'VERSION_ID' => '']);
        $this->addEgg(9, 'Fabric', '78b02ebb-fec8-49c5-943c-ca4aa117b693', [
            'MC_VERSION' => 'latest', 'LOADER_VERSION' => 'latest', 'FABRIC_VERSION' => 'latest',
        ]);
        $archive = tempnam(sys_get_temp_dir(), 'modrinth-fixture');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::OVERWRITE));
        $zip->addFromString('modrinth.index.json', json_encode([
            'formatVersion' => 1, 'game' => 'minecraft',
            'dependencies' => ['minecraft' => '1.20.1', 'fabric-loader' => '0.15.11'],
            'files' => [['path' => 'mods/known.jar', 'env' => ['server' => 'required'], 'downloads' => ['https://cdn.modrinth.com/known.jar']]],
        ], JSON_THROW_ON_ERROR));
        $zip->close();
        $bytes = file_get_contents($archive);
        unlink($archive);
        $release = [
            'id' => 'test1234', 'project_id' => 'pack1234', 'version_number' => '6.5.0',
            'game_versions' => ['1.20.1'], 'loaders' => ['fabric'],
            'files' => [['filename' => 'pack.mrpack', 'url' => 'https://cdn.modrinth.com/pack.mrpack', 'primary' => true]],
        ];
        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([]),
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [], 'total_hits' => 0]),
            'api.modrinth.com/v2/project/pack1234/version*' => Http::response([$release]),
            'api.modrinth.com/v2/project/pack1234' => Http::response(['id' => 'pack1234', 'server_side' => 'unsupported']),
            'api.modrinth.com/v2/version/test1234' => Http::response($release),
            'cdn.modrinth.com/pack.mrpack' => Http::response($bytes),
        ]);
        $service = Mockery::mock(ServerCreationService::class);
        $service->shouldReceive('handle')->once()->withArgs(static function (array $data): bool {
            self::assertSame(9, $data['egg_id']);
            self::assertSame('0.15.11', $data['environment']['LOADER_VERSION']);
            self::assertSame(0, $data['start_on_completion']);
            self::assertSame('archive', $data['mm_install_plan']['mode']);
            self::assertSame('test1234', $data['mm_install_plan']['version_id']);

            return true;
        })->andReturn((new Server())->forceFill(['id' => 48]));
        $this->app->instance(ServerCreationService::class, $service);

        Livewire::test(ModpackCreationWizardHarness::class)
            ->set('data.mm_modpack', true)
            ->set('data.mm_project', 'modrinth:pack1234')
            ->set('data.mm_version', 'test1234')
            ->assertSet('data.egg_id', 9)
            ->assertSet('data.skip_scripts', '1')->assertSet('data.start_on_completion', '0')
            ->call('nextModpackStep')->assertHasErrors('data.mm_confirm_compatibility')
            ->set('data.mm_confirm_compatibility', true)
            ->set('data.start_on_completion', '1')
            ->call('create')->assertHasErrors('data.mm_project')
            ->set('data.start_on_completion', '0')
            ->call('create')->assertHasNoErrors();
        self::assertSame('test1234', $runner->spawned[0]['payload']['version_id']);

        $existing = new Server();
        $existing->exists = true;
        $pack = app(ModpackCatalog::class)->describe($existing, 'modrinth', 'pack1234', 'test1234');
        self::assertNotNull($pack);
        self::assertFalse($pack->archiveAllowed);
        self::assertNotNull($pack->unsupportedReason);
    }

    public function test_modrinth_manifest_pins_the_fabric_loader_for_archive_creation(): void
    {
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        $this->addEgg(8, 'Modrinth Generic', '04befa18-37cf-41e9-b04d-89b3578bba6d', ['PROJECT_ID' => '', 'VERSION_ID' => '']);
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
            'files' => [['path' => 'mods/krypton.jar', 'downloads' => ['https://cdn.modrinth.com/krypton.jar'], 'env' => ['server' => 'required']]],
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
            self::assertSame('Fabric Server Optimization', $data['mm_install_plan']['selection']['title']);

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

    public function test_switching_provider_clears_selected_modpack_state_and_restores_startup(): void
    {
        $this->addEgg(9, 'Fabric', '78b02ebb-fec8-49c5-943c-ca4aa117b693', ['MC_VERSION' => 'latest', 'LOADER_VERSION' => 'latest']);
        DB::table('eggs')->where('id', 9)->update(['tags' => '["minecraft","fabric"]']);
        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([]),
            'api.modrinth.com/v2/search*' => Http::response([
                'hits' => [['project_id' => 'YkG1DyHE', 'title' => 'Fabric Pack']], 'total_hits' => 1,
            ]),
            'api.modrinth.com/v2/project/YkG1DyHE/version*' => Http::response([[
                'id' => 'vtGmZsbn', 'project_id' => 'YkG1DyHE', 'version_number' => '1.0',
                'game_versions' => ['1.20.1'], 'loaders' => ['fabric'],
                'files' => [['filename' => 'pack.mrpack', 'url' => 'https://cdn.modrinth.com/pack.mrpack', 'primary' => true]],
            ]]),
            'api.modrinth.com/v2/version/vtGmZsbn' => Http::response([
                'id' => 'vtGmZsbn', 'project_id' => 'YkG1DyHE', 'version_number' => '1.0',
                'game_versions' => ['1.20.1'], 'loaders' => ['fabric'],
                'files' => [['filename' => 'pack.mrpack', 'url' => 'https://cdn.modrinth.com/pack.mrpack', 'primary' => true]],
            ]),
            'api.modrinth.com/v2/project/YkG1DyHE' => Http::response(['id' => 'YkG1DyHE', 'server_side' => 'required']),
            'api.modpacks.ch/public/modpack/popular/installs/20' => Http::response(['packs' => []]),
        ]);

        Livewire::test(ModpackCreationWizardHarness::class)
            ->set('data.mm_modpack', true)
            ->set('data.mm_project', 'modrinth:YkG1DyHE')
            ->set('data.mm_version', 'vtGmZsbn')
            ->assertSet('data.egg_id', 9)
            ->set('data.mm_provider', 'ftb')
            ->assertSet('data.mm_project', null)
            ->assertSet('data.mm_project_title', null)
            ->assertSet('data.mm_version', null)
            ->assertSet('data.mm_confirm_compatibility', false)
            ->assertSet('data.egg_id', null);
    }

    private function ftbPack(): array
    {
        return ['id' => 5, 'name' => 'Example FTB Pack', 'versions' => [
            ['id' => 89, 'name' => '1.0.0', 'updated' => 200],
            ['id' => 90, 'name' => '2.0.0', 'updated' => 300],
        ]];
    }

    private function ftbRelease(string $version, string $config, ?string $mod = null, string $java = '21.0.6'): array
    {
        $files = [
            ['path' => './config/', 'name' => 'pack.cfg', 'size' => strlen($config), 'sha1' => sha1($config),
                'url' => 'https://ftb-files.example.test/pack.cfg'],
            ['path' => './mods', 'name' => 'client.jar', 'clientonly' => true],
            ['path' => './', 'name' => 'server.properties'],
        ];
        if ($mod !== null) {
            $files[] = ['path' => './mods', 'name' => 'example.jar', 'optional' => true, 'size' => strlen($mod),
                'sha1' => sha1($mod), 'mirrors' => ['https://ftb-files.example.test/example.jar']];
        }

        return ['parent' => 5, 'id' => (int) $version, 'name' => 'fixture-'.$version, 'status' => 'success', 'files' => $files, 'targets' => [
            ['type' => 'game', 'name' => 'minecraft', 'version' => '1.20.1'],
            ['type' => 'modloader', 'name' => 'fabric', 'version' => '0.15.0'],
            ['type' => 'runtime', 'name' => 'java', 'version' => $java],
        ]];
    }

    /** @return array{Server, DeploymentWings, PluginBackgroundRunner} */
    private function archiveJobFixtures(string $launcherBuild = '0.15.0'): array
    {
        require_once __DIR__.'/../Services/ModpackDeploymentTest.php';
        $this->addEgg(7, 'Fabric', '10000000-b803-4f34-82cf-2d6518c6351a', ['MC_VERSION' => '1.20.1', 'LOADER_VERSION' => '0.15.0']);
        DB::table('eggs')->where('id', 7)->update(['tags' => '["minecraft","fabric"]']);
        Schema::table('servers', function (Blueprint $table): void {
            $table->string('uuid');
            $table->string('image')->nullable();
            $table->text('startup')->nullable();
        });
        Schema::create('server_variables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('variable_id');
            $table->string('variable_value');
        });
        Schema::create('backups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->string('uuid');
            $table->json('ignored_files');
            $table->boolean('is_successful');
            $table->boolean('is_locked')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_19_000006_create_mod_manager_modpacks_table.php';
        $migration->up();
        DB::table('servers')->insert(['id' => 42, 'egg_id' => 7, 'uuid' => 'server-42', 'image' => 'ghcr.io/pelican-eggs/yolks:java_17']);
        foreach (DB::table('egg_variables')->get() as $variable) {
            DB::table('server_variables')->insert(['server_id' => 42, 'variable_id' => $variable->id, 'variable_value' => $variable->default_value]);
        }
        $server = Server::findOrFail(42);
        $runner = PluginBackgroundRunner::fake();
        $this->app->instance(PluginBackgroundRunner::class, $runner);
        $repo = Mockery::mock(DaemonFileRepository::class);
        $daemon = Mockery::mock(DaemonServerRepository::class);
        $daemon->shouldReceive('setServer')->andReturnSelf();
        $daemon->shouldReceive('getState')->with(5)->andReturn(ContainerStatus::Offline);
        $daemon->shouldNotReceive('power');
        $this->app->instance(DaemonFileRepository::class, $repo);
        $this->app->instance(DaemonServerRepository::class, $daemon);
        $wings = new DeploymentWings();
        $runtimeJar = tempnam(sys_get_temp_dir(), 'fabric-launcher');
        $launcher = new ZipArchive();
        $launcher->open($runtimeJar, ZipArchive::OVERWRITE);
        $fabricLoader = "libraries/net/fabricmc/fabric-loader/$launcherBuild/fabric-loader-$launcherBuild.jar";
        $intermediary = 'libraries/net/fabricmc/intermediary/1.20.1/intermediary-1.20.1.jar';
        $launcher->addFromString('META-INF/MANIFEST.MF',
            "Manifest-Version: 1.0\r\nMain-Class: net.fabricmc.loader.impl.launch.server.FabricServerLauncher\r\nClass-Path: $fabricLoader\r\n  $intermediary\r\n");
        $launcher->close();
        $wings->files = ['server.jar' => file_get_contents($runtimeJar), $fabricLoader => "PK\x03\x04", $intermediary => "PK\x03\x04",
            'minecraft-server.jar' => "PK\x03\x04"];
        unlink($runtimeJar);
        $this->app->instance(WingsRemoteFilesystem::class, $wings);

        return [$server, $wings, $runner];
    }

    private function createUpdateBackup(Server $server, string $token): void
    {
        $workflow = app(ModpackUpdateWorkflow::class);
        $backup = Mockery::mock(InitiateBackupService::class);
        $backup->shouldReceive('setIgnoredFiles')->with(['# Full modpack update backup'])->andReturnSelf();
        $backup->shouldReceive('setIsLocked')->with(false)->andReturnSelf();
        $backup->shouldReceive('handle')->once()->andReturnUsing(function (): Backup {
            $id = DB::table('backups')->insertGetId(['server_id' => 42, 'uuid' => 'backup-42', 'is_successful' => true,
                'ignored_files' => json_encode(['# Full modpack update backup'], JSON_THROW_ON_ERROR), 'completed_at' => now(), 'created_at' => now()]);

            return Backup::findOrFail($id);
        });
        $workflow->createBackup($server, $token, $backup);
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
                ToggleButtons::make('skip_scripts')->options([0 => 'Yes', 1 => 'Skip'])->default(0)->required(),
                ToggleButtons::make('start_on_completion')->options([1 => 'Yes', 0 => 'No'])->default(1)->required(),
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
