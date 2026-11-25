<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier4;

use App\Models\Egg;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Filament\Components\ModpackCatalogTable;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftDatapackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftResourcePackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackInventory;
use Kazaminosuke\ModManager\Services\ModpackProvisioningPlanner;
use Kazaminosuke\ModManager\Services\ModpackRuntime;
use Kazaminosuke\ModManager\Support\CatalogTileColumns;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\LoaderRuntimeFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\ModpackArchiveFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\CreateServerWizardHarness;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use Kazaminosuke\ModManager\Tests\E2E\Support\Html5DomAssert;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tier 4 — End-to-End Cobblemon-Style MC 26.1.2 Fabric Lifecycle (9.5 MiB .mrpack Range Probe,
 * 2.5 MiB server-overrides/, Mirror Fallback, Unobfuscated Fabric 26.1.2, -Xmx0M Protection,
 * InstallModpack Defer/Resume, and Full Cross-Catalog UI Flow).
 */
#[RunTestsInSeparateProcesses]
final class CobblemonMc26FabricLifecycleAndUiFlowE2ETest extends E2ETestCase
{
    public function test_full_mc_26_1_2_fabric_mrpack_lifecycle_and_cross_catalog_ui_flow(): void
    {
        $runner = PluginBackgroundRunner::fake();
        $this->app->instance(PluginBackgroundRunner::class, $runner);
        $user = $this->createUser(rootAdmin: true);
        $this->actingAs($user);

        $fixtures = new ModpackArchiveFixtures();
        try {
            $modBytes = "PK\x03\x04cobblemon-mc26-mod-jar";
            $primaryModUrl = 'https://cdn.modrinth.com/data/cobb/broken-primary.jar';
            $mirrorModUrl = 'https://github.com/example/cobblemon/releases/download/v1/cobblemon.jar';
            $largeServerOverride = str_repeat('C', 2_500_000);

            $mrpackPath = $fixtures->buildMrpack(
                dependencies: ['minecraft' => '26.1.2', 'fabric-loader' => '0.19.3'],
                files: [[
                    'path' => 'mods/cobblemon.jar',
                    'hashes' => ['sha1' => sha1($modBytes), 'sha512' => hash('sha512', $modBytes)],
                    'downloads' => [$primaryModUrl, $mirrorModUrl],
                    'fileSize' => strlen($modBytes),
                ]],
                serverOverrides: ['datapacks/cobblemon-worldgen.zip' => $largeServerOverride],
                clientOverrides: ['options.txt' => 'client-only'],
                options: ['omit_env' => true, 'pad_bytes' => 9_500_000, 'name' => 'Cobblemon 26', 'version_id' => '2.6.0'],
            );
            $mrpackBytes = (string) file_get_contents($mrpackPath);
            self::assertGreaterThan(9_500_000, strlen($mrpackBytes));

            $mrpackUrl = 'https://cdn.modrinth.com/data/cobbpack/v26/cobblemon-26.mrpack';
            $rangeMetrics = [];

            $versionPayload = [
                'id' => 'v26',
                'project_id' => 'cobbpack',
                'version_number' => '2.6.0',
                'game_versions' => ['26.1.2'],
                'loaders' => ['fabric'],
                'files' => [[
                    'primary' => true,
                    'filename' => 'cobblemon-26.mrpack',
                    'url' => $mrpackUrl,
                    'size' => strlen($mrpackBytes),
                    'hashes' => ['sha1' => sha1($mrpackBytes), 'sha512' => hash('sha512', $mrpackBytes)],
                ]],
            ];

            $this->registerRemoteFile($mirrorModUrl, $modBytes);
            $this->fakeUpstreamHttp([
                'api.modrinth.com/v2/tag/*' => Http::response([
                    ['version' => '26.1.2', 'version_type' => 'release'],
                    ['version' => '1.21.1', 'version_type' => 'release'],
                ], 200),
                'api.modrinth.com/v2/search*' => Http::response([
                    'hits' => [[
                        'project_id' => 'cobbpack',
                        'slug' => 'cobblemon-26',
                        'title' => 'Cobblemon 26',
                        'description' => 'MC 26.1.2 Fabric adventure pack.',
                        'icon_url' => null,
                        'author' => 'CobblemonTeam',
                        'downloads' => 2500000,
                        'date_modified' => '2026-11-20T12:00:00Z',
                        'versions' => ['26.1.2', '1.21.1'],
                        'categories' => ['fabric'],
                    ]],
                    'total_hits' => 1,
                ], 200),
                'api.modrinth.com/v2/project/cobbpack/version*' => Http::response([$versionPayload], 200),
                'api.modrinth.com/v2/project/cobbpack' => Http::response([
                    'id' => 'cobbpack',
                    'slug' => 'cobblemon-26',
                    'title' => 'Cobblemon 26',
                    'description' => 'MC 26.1.2 pack',
                    'server_side' => 'required',
                ], 200),
                'api.modrinth.com/v2/version/v26' => Http::response($versionPayload, 200),
                $primaryModUrl => Http::response('Primary mirror unavailable', 503),
                $mrpackUrl => ModpackArchiveFixtures::rangeHttpResponder($mrpackBytes, $rangeMetrics, rejectFullGet: false),
            ]);

            // Part A: Full Cross-Catalog UI Flow (#1-#4)
            $this->useAdminPanel();
            session()->forget(CatalogTileColumns::SESSION_KEY);
            $wizardTable = Livewire::test(ModpackCatalogTable::class, [
                'mode' => ModpackCatalogTable::MODE_CREATE,
                'provider' => ModpackProvider::Modrinth->value,
                'query' => 'cobblemon',
            ]);
            self::assertSame(CatalogTileColumns::VIEW_LIST, $wizardTable->get('catalogView'));
            Html5DomAssert::assertNoModrinthAuthorLinks($wizardTable->html(), 'Tier 4 Wizard list:');
            Html5DomAssert::assertExternalProjectLinkPresent($wizardTable->html(), 'https://modrinth.com/modpack/cobblemon-26', 'Tier 4 Wizard list:');

            $wizardTable->call('toggleCatalogView');
            $wizardTileHtml = $wizardTable->html();
            Html5DomAssert::assertNoModrinthAuthorLinks($wizardTileHtml, 'Tier 4 Wizard tile:');
            Html5DomAssert::assertSingleAnchorPerTileCard($wizardTileHtml, 1, 'Tier 4 Wizard tile:');
            Html5DomAssert::assertPureFilamentTileMetadataLayout($wizardTileHtml, 1, 'Tier 4 Wizard tile:');

            $wizardTable->call('toggleCatalogView');
            $wizardInstance = $wizardTable->instance();
            self::assertInstanceOf(ModpackCatalogTable::class, $wizardInstance);
            $wizardCols = array_keys($wizardInstance->getTable()->getVisibleColumns());
            self::assertContains('minecraft_versions', $wizardCols);
            self::assertContains('loaders', $wizardCols);
            self::assertContains('downloads', $wizardCols);

            // Part B: 9.5 MiB .mrpack Range Probe (< 2 MiB transferred!) & DB-backed Egg Planner (#14, #18)
            OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::forge(1));
            OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::neoforge(2));
            $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(3));
            OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::quilt(4));

            $descriptor = $this->app->make(ModpackCatalog::class)->describe(new Server(), 'modrinth', 'cobbpack', 'v26');
            self::assertNotNull($descriptor);
            self::assertSame('26.1.2', $descriptor->minecraftVersion);
            self::assertSame('fabric', $descriptor->loader);
            self::assertSame('0.19.3', $descriptor->loaderVersion, 'Tier 4 (#14): Range tail probe must pin Fabric 0.19.3 from 9.5 MiB .mrpack.');
            self::assertLessThan(2 * 1024 * 1024, $rangeMetrics['bytes_sent'] ?? PHP_INT_MAX, 'Tier 4 bound: Range probe must transfer < 2 MiB for 9.5 MiB .mrpack.');

            /** @var ModpackProvisioningPlanner $planner */
            $planner = $this->app->make(ModpackProvisioningPlanner::class);
            $plan = $planner->select($descriptor, $this->app->make(InstalledEggCatalog::class)->all(), EggCompatibilityEngine::CONTEXT_CREATE);
            self::assertSame($fabricEgg->getKey(), $plan->selected?->id);

            // Part C: REAL CreateServer Wizard (memory=0) -> RecordCreated -> QueueCreatedServerModpack -> InstallModpack!
            self::assertTrue(
                class_exists('Kazaminosuke\\ModManager\\Services\\ModpackBundleUploader'),
                'M4 (#19): ModpackBundleUploader must exist for bundled deployment.',
            );

            Filament::getPanel('server')->pages([
                ModManagerPage::class,
                MinecraftDatapackPage::class,
                MinecraftResourcePackPage::class,
            ]);
            $this->useAdminPanel();

            $createdServer = null;
            CreateServerWizardHarness::$createServerCallback = function (array $data) use (&$createdServer): Server {
                /** @var Egg $egg */
                $egg = Egg::query()->with('variables')->findOrFail((int) $data['egg_id']);
                $envVars = [];
                foreach (is_array($data['environment'] ?? null) ? $data['environment'] : [] as $k => $v) {
                    $envVars[(string) $k] = (string) $v;
                }
                $createdServer = $this->createServer(
                    $egg,
                    overrides: [
                        'status' => 'installing',
                        'memory' => (int) ($data['memory'] ?? 0),
                        'image' => (string) ($data['image'] ?? ''),
                        'startup' => (string) ($data['startup'] ?? ''),
                        'skip_scripts' => (bool) ($data['skip_scripts'] ?? false),
                    ],
                    variableValues: $envVars,
                );

                return $createdServer;
            };

            Livewire::test(CreateServerWizardHarness::class)
                ->set('data.memory', 0)
                ->set('data.mm_provider', ModpackProvider::Modrinth->value)
                ->set('data.mm_modpack', true)
                ->set('data.mm_project', 'modrinth:cobbpack')
                ->set('data.mm_version', 'v26')
                ->assertSet('data.egg_id', $fabricEgg->getKey())
                ->assertSet('data.image', 'ghcr.io/pelican-eggs/yolks:java_25')
                ->assertSet('data.environment.MC_VERSION', '26.1.2')
                ->assertSet('data.environment.LOADER_VERSION', '0.19.3')
                ->call('create')
                ->assertHasNoErrors();

            self::assertInstanceOf(Server::class, $createdServer);
            self::assertCount(1, $runner->spawned, 'CreateServer wizard with modpack selection must queue exactly 1 BackgroundJob::MODPACK.');
            self::assertSame(BackgroundJob::MODPACK, $runner->spawned[0]['type']);
            $payload = $runner->spawned[0]['payload'];
            self::assertSame((string) $fabricEgg->getKey(), $payload['selection']['egg_id'] ?? null);
            self::assertSame('26.1.2', $payload['selection']['minecraft'] ?? null);

            // Check ModManagerPage on server panel renders cleanly in default 'list' view too
            $this->seedServerFile($createdServer, 'server.properties', "level-name=world\n");
            $this->useServerPanel($createdServer);
            session()->forget(CatalogTileColumns::SESSION_KEY);
            $serverPage = Livewire::test(ModManagerPage::class)->set('activeTab', 'modrinth')->call('loadTable');
            self::assertSame(CatalogTileColumns::VIEW_LIST, $serverPage->get('catalogView'));
            Html5DomAssert::assertNoModrinthAuthorLinks($serverPage->html(), 'Tier 4 Server Page list:');

            // Tick 1: server status === 'installing' -> defers cleanly returning 30s
            self::assertSame(30, BackgroundJob::execute(BackgroundJob::MODPACK, $payload));

            // Egg completes installing unobfuscated Fabric 26.1.2 (ONLY clear status; NEVER touch startup!)
            foreach (LoaderRuntimeFixtures::fabricUnobfuscated26('26.1.2', '0.19.3') as $path => $bytes) {
                $this->seedServerFile($createdServer, $path, $bytes);
            }
            DB::table('servers')->where('id', $createdServer->getKey())->update(['status' => null]);

            // Tick 2: verifies unobfuscated Fabric 26.1.2 runtime, falls back to mirror, streams 2.5 MiB server override
            $this->wings->setDisableRemoteDownload(true);
            $this->wings->resetMetrics();

            self::assertNull(BackgroundJob::execute(BackgroundJob::MODPACK, $payload));

            // Real production startup protection assertion on memory=0 Fabric server (ZERO tautology!)
            $createdServer->refresh();
            self::assertSame(0, (int) $createdServer->memory);
            self::assertStringNotContainsString(
                '-Xmx{{SERVER_MEMORY}}M',
                (string) $createdServer->startup,
                'M3 (#18): Production code must replace -Xmx{{SERVER_MEMORY}}M on unlimited-memory (memory=0) Fabric server.',
            );
            self::assertStringContainsString(
                '-XX:MaxRAMPercentage=95.0',
                (string) $createdServer->startup,
                'M3 (#18): Unlimited-memory Fabric server must use -XX:MaxRAMPercentage=95.0.',
            );

            $this->app->make(ModpackRuntime::class)->assertInstalled(
                $createdServer,
                $this->app->make(DaemonFileRepository::class),
                'fabric',
                '26.1.2',
                '0.19.3',
                ['SERVER_JARFILE' => 'server.jar'],
            );
            self::assertSame($modBytes, $this->serverFile($createdServer, 'mods/cobblemon.jar'));
            self::assertSame(hash('sha256', $largeServerOverride), hash('sha256', $this->serverFile($createdServer, 'datapacks/cobblemon-worldgen.zip')));
            self::assertFalse($this->hasServerFile($createdServer, 'options.txt'));
            self::assertNotNull($this->app->make(ModpackInventory::class)->read($createdServer)['inventory']);
            $opState = $this->app->make(InstalledOperationManager::class)->state($createdServer, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
            self::assertInstanceOf(InstalledOperationState::class, $opState);
            self::assertSame(InstalledOperationState::STATUS_COMPLETED, $opState->status);
            self::assertNotNull($opState->total);
            self::assertGreaterThan(0, $opState->total);
            self::assertSame($opState->total, $opState->progress);
            $this->wings->assertNoTemporaryModManagerFiles($createdServer->uuid);
        } finally {
            CreateServerWizardHarness::$createServerCallback = null;
            $fixtures->cleanup();
        }
    }
}
