<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\FilamentManager;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\ModpackDeployment;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackRuntime;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackArchive;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDelta;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use PHPUnit\Framework\TestCase;
use ZipArchive;

require_once __DIR__.'/ModpackDeploymentTest.php';

final class ModpackRegressionTest extends TestCase
{
    public function test_missing_tracked_required_jar_can_be_reinstalled(): void
    {
        $path = 'mods/required.jar';
        $old = [$path => ['identity' => 'original', 'sha256' => hash('sha256', 'old')]];
        $new = [$path => ['identity' => 'new', 'sha256' => hash('sha256', 'new'), 'size' => 3]];
        $plan = ModpackDelta::plan($old, $new, static fn () => null);

        self::assertSame('add', $plan[$path]['action'], 'The absent path can be safely filled. No live file would be replaced.');
    }

    public function test_switch_does_not_silently_retain_old_executable_scripts(): void
    {
        $server = new Server();
        $repo = $this->createStub(DaemonFileRepository::class);
        $wings = new DeploymentWings();
        $store = new DeploymentInventory();
        $deployment = new ModpackDeployment($wings, $store);
        $guard = static fn () => null;
        $old = ['provider' => 'modrinth', 'pack_id' => 'pack-a', 'version_id' => '1'];
        $new = ['provider' => 'modrinth', 'pack_id' => 'pack-b', 'version_id' => '2', 'switch' => true];
        $oldScript = 'kubejs/server_scripts/recipes.js';
        $newScript = 'kubejs/server_scripts/new.js';
        $deployment->run($server, $repo, $old, [$oldScript => $this->file('old script')], null, false, null, $guard);
        $preview = $deployment->run($server, $repo, $new, [$newScript => $this->file('new script')], null, true, null, $guard);

        $deployment->run($server, $repo, $new, [$newScript => $this->file('new script')], $preview['digest'], false, 'backup', $guard);

        self::assertSame('pack-b', $store->data['inventory']['pack_id']);
        self::assertArrayHasKey($newScript, $wings->files);
        self::assertArrayNotHasKey($oldScript, $wings->files,
            'A pack-owned executable requires an explicit retention decision or removal on a switch.');
    }

    public function test_oversize_server_override_does_not_fall_back_to_shared_client_config(): void
    {
        $json = ['formatVersion' => 1, 'game' => 'minecraft', 'files' => []];
        $zip = $this->zip([
            'modrinth.index.json' => json_encode($json, JSON_THROW_ON_ERROR),
            'server-overrides/config/sides.toml' => str_repeat('s', 2_000_001),
            'overrides/config/sides.toml' => 'mode = "client"',
        ]);
        try {
            $this->expectException(ModpackException::class);
            $this->expectExceptionMessage('Server override [config/sides.toml] exceeds');
            ModpackArchive::read($zip, strict: true);
        } finally {
            unlink($zip);
        }
    }

    public function test_required_unmanaged_download_stops_archive_installation(): void
    {
        $old = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $wings = new DeploymentWings();
        $inventory = new DeploymentInventory();
        $container->instance(ModpackDeployment::class, new ModpackDeployment($wings, $inventory));
        $zip = $this->zip(['modrinth.index.json' => json_encode([
            'formatVersion' => 1, 'game' => 'minecraft',
            'dependencies' => ['minecraft' => '1.20.1', 'fabric-loader' => '0.15.0'],
            'files' => [['path' => 'libraries/required.bin', 'env' => ['server' => 'required'],
                'downloads' => ['https://cdn.modrinth.com/required.bin']]],
        ], JSON_THROW_ON_ERROR)]);
        try {
            $installer = new ModpackInstaller($wings, $this->createStub(CurseForgeSource::class));
            $this->expectException(ModpackException::class);
            $installer->deploy(new Server(), $this->createStub(DaemonFileRepository::class), $zip,
                ['provider' => 'modrinth', 'pack_id' => 'pack', 'version_id' => '1', 'server_pack' => false],
                'fabric', '1.20.1', false, null, null, static fn () => null);
        } finally {
            Container::setInstance($old);
            unlink($zip);
        }
    }

    public function test_dedicated_server_zip_requires_its_own_declared_loader_build(): void
    {
        $old = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $wings = new DeploymentWings();
        $container->instance(ModpackDeployment::class, new ModpackDeployment($wings, new DeploymentInventory()));
        $zip = $this->zip([
            'variables.txt' => "MINECRAFT_VERSION=1.20.1\nMODLOADER=Fabric\nMODLOADER_VERSION=0.16.1\n",
            'config/server.toml' => 'fixture',
        ]);
        try {
            $installer = new ModpackInstaller($wings, $this->createStub(CurseForgeSource::class));
            $this->expectException(ModpackException::class);
            $installer->deploy(new Server(), $this->createStub(DaemonFileRepository::class), $zip,
                ['provider' => 'curseforge', 'pack_id' => '123', 'version_id' => '2', 'server_pack' => true,
                    'loader' => 'fabric', 'minecraft_version' => '1.20.1', 'loader_version' => '0.15.0'],
                'fabric', '1.20.1', true, null, null, static fn () => null, ['LOADER_VERSION' => '0.15.0']);
        } finally {
            Container::setInstance($old);
            unlink($zip);
        }
    }

    public function test_waiting_for_modpack_approval_does_not_hide_an_active_installed_scan(): void
    {
        $oldContainer = Container::getInstance();
        $oldFacade = Facade::getFacadeApplication();
        $container = new Container();
        $server = new Server();
        $server->forceFill(['id' => 42]);
        $filament = new FilamentManager();
        $filament->setTenant($server, true);
        $container->instance('filament', $filament);
        $operations = new InstalledOperationManager(new Repository(new ArrayStore()), new Config(), runner: PluginBackgroundRunner::fake());
        $container->instance(InstalledOperationManager::class, $operations);
        $page = new class extends ModManagerPage
        {
            protected static function detectProjectType(Server $server): ?ProjectType
            {
                return ProjectType::Mod;
            }

            public function observe(InstalledOperationState $scan, InstalledOperationState $waiting): ?InstalledOperationState
            {
                $this->preloadedModpackOperationState = $waiting;

                return $this->refreshInstalledOperationState($scan, preloaded: true);
            }
        };
        $page->activeTab = 'installed';
        $scan = InstalledOperationState::queued(InstalledOperationManager::OPERATION_SCAN, 42, ProjectType::Mod)->running();
        $waiting = InstalledOperationState::queued(InstalledOperationManager::OPERATION_MODPACK, 42, ProjectType::Mod)
            ->waitingForFiles(InstalledOperationState::STATUS_WAITING_REVIEW);
        try {
            Container::setInstance($container);
            Facade::setFacadeApplication($container);
            Facade::clearResolvedInstances();
            $selected = $page->observe($scan, $waiting);
            self::assertTrue($page->pollInstalledOperations, 'The active scan still needs its polling cycle. Actual selected state: '.$selected?->status);
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($oldFacade);
            Container::setInstance($oldContainer);
        }
    }

    public function test_modern_loader_must_match_arguments_and_library_after_pelican_completes_installation(): void
    {
        $server = (new Server())->forceFill(['startup' => 'java @unix_args.txt']);
        $repo = $this->createStub(DaemonFileRepository::class);
        $wings = new DeploymentWings();
        $jar = 'libraries/net/neoforged/neoforge/21.1.266/neoforge-21.1.266-universal.jar';
        $wings->files = ['unix_args.txt' => "-cp $jar\n--fml.mcVersion 1.21.1\n", $jar => "PK\x03\x04"];
        $runtime = new ModpackRuntime($wings);

        $runtime->assertInstalled($server, $repo, 'neoforge', '1.21.1', '21.1.266', []);
        $this->expectException(ModpackException::class);
        $this->expectExceptionMessage('startup arguments do not match');
        $runtime->assertInstalled($server, $repo, 'neoforge', '1.21.1', '21.1.267', ['NEOFORGE_VERSION' => '21.1.267']);
    }

    public function test_wrapped_server_zip_can_declare_its_runtime_in_its_own_manifest(): void
    {
        $path = $this->zip([
            'server/config/example.toml' => 'fixture',
            'server/manifest.json' => json_encode([
                'manifestType' => 'minecraftModpack',
                'minecraft' => ['version' => '1.21.1', 'modLoaders' => [['id' => 'neoforge-21.1.266', 'primary' => true]]],
            ], JSON_THROW_ON_ERROR),
        ]);
        $archive = new ZipArchive();
        $archive->open($path, ZipArchive::RDONLY);
        try {
            $files = ModpackArchive::serverFiles($archive);
            self::assertSame(['minecraft' => '1.21.1', 'loader' => 'neoforge', 'build' => '21.1.266'], ModpackArchive::serverPackRuntime($archive, $files[0]));
        } finally {
            $archive->close();
            unlink($path);
        }
    }

    private function file(string $bytes): array
    {
        $hash = hash('sha256', $bytes);

        return ['identity' => $hash, 'sha256' => $hash, 'size' => strlen($bytes), 'hashes' => [],
            'file_id' => null, 'project_id' => null, 'open' => static fn () => Utils::streamFor($bytes), 'url' => null];
    }

    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'independent-review-');
        $archive = new ZipArchive();
        $archive->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $body) {
            $archive->addFromString($name, $body);
        }
        $archive->close();

        return $path;
    }
}
