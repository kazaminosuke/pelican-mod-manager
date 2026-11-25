<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier3;

use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Backups\InitiateBackupService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Jobs\InstallModpack;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackInstallCoordinator;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackInventory;
use Kazaminosuke\ModManager\Services\ModpackRuntime;
use Kazaminosuke\ModManager\Services\ModpackUpdateWorkflow;
use Kazaminosuke\ModManager\Services\ServerEggMigration;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityDisposition;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\LoaderRuntimeFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\ModpackArchiveFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use Kazaminosuke\ModManager\Tests\E2E\Support\StrictWingsFake;
use ReflectionMethod;

/**
 * Tier 3 — Cross-Feature Workflow combining Features #10, #13, #18, #19, #20, #21 (M2–M4).
 */
final class ModpackBundledCancelAndEggMigrationWorkflowE2ETest extends E2ETestCase
{
    public function test_existing_server_fabric_1_21_to_26_1_2_update_with_skip_scripts_reset_unlimited_memory_fix_and_incremental_deploy(): void
    {
        self::assertTrue(
            class_exists('Kazaminosuke\\ModManager\\Services\\ModpackBundleUploader'),
            'M4 (#19): ModpackBundleUploader must exist for bundled incremental deploy.',
        );
        $migration = $this->app->make(ServerEggMigration::class);
        $params = (new ReflectionMethod($migration, 'apply'))->getParameters();
        self::assertGreaterThanOrEqual(
            6,
            count($params),
            'M4 (#21): ServerEggMigration::apply() must implement evolved 6-arg contract with $startup and $guard.',
        );

        $fixtures = new ModpackArchiveFixtures();
        try {
            $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(130));
            $server = $this->createServer($fabricEgg, overrides: [
                'skip_scripts' => true,
                'memory' => 0,
                'startup' => 'java -Xms128M -Xmx{{SERVER_MEMORY}}M -jar {{SERVER_JARFILE}}',
                'image' => 'ghcr.io/pelican-eggs/yolks:java_21',
            ], variableValues: [
                'MC_VERSION' => '1.21.1',
                'LOADER_VERSION' => '0.15.11',
                'SERVER_JARFILE' => 'server.jar',
            ]);

            $repo = $this->app->make(DaemonFileRepository::class);
            $installer = $this->app->make(ModpackInstaller::class);

            // Seed v1 pack (20 mods), user-edited config, catalog mod, custom world
            $this->seedServerFile($server, 'server.properties', "level-name=survival_world\nserver-port=25565\n");
            $this->seedServerFile($server, 'survival_world/level.dat', 'SURVIVAL-NBT-DATA');
            $this->seedServerFile($server, 'mods/personal.jar', "PK\x03\x04personal-catalog-mod");
            $this->seedServerFile($server, 'mods/.pelican-mod-manager.json', json_encode([
                'schema_version' => 2,
                'installed_mods' => [[
                    'source' => ProjectSourceKey::Modrinth->value,
                    'project_id' => 'personal1',
                    'project_slug' => 'personal',
                    'project_title' => 'Personal Mod',
                    'version_id' => 'v1',
                    'version_number' => '1.0',
                    'filename' => 'personal.jar',
                    'managed_by' => 'catalog',
                    'installed_at' => gmdate('c'),
                ]],
                'unresolved_files' => [],
            ], JSON_THROW_ON_ERROR));

            $filesV1 = [];
            for ($i = 0; $i < 20; $i++) {
                $bytes = "PK\x03\x04v1-fabric-mod-{$i}";
                $url = "https://cdn.modrinth.com/data/fabup/v1/m-{$i}.jar";
                $this->registerRemoteFile($url, $bytes);
                $filesV1[] = [
                    'path' => "mods/pack-{$i}.jar",
                    'hashes' => ['sha1' => sha1($bytes), 'sha512' => hash('sha512', $bytes)],
                    'downloads' => [$url],
                    'fileSize' => strlen($bytes),
                    'env' => ['server' => 'required'],
                ];
            }
            $zipV1 = $fixtures->buildMrpack(['minecraft' => '1.21.1', 'fabric-loader' => '0.15.11'], $filesV1, overrides: ['config/tweaks.toml' => "pack_default=true\n"]);

            $this->wings->setDisableRemoteDownload(true);
            $installer->deploy($server, $repo, $zipV1, [
                'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'fabup', 'version_id' => 'v1',
                'minecraft_version' => '1.21.1', 'loader' => 'fabric', 'loader_version' => '0.15.11', 'server_pack' => false,
            ], 'fabric', '1.21.1', false, null, null, static fn (): bool => true);

            // Simulate user editing config/tweaks.toml after v1 install
            $this->seedServerFile($server, 'config/tweaks.toml', "user_custom=true\n");

            // Step 1: Migrate server runtime to Fabric 26.1.2 (0.19.3) + Java 25 + unlimited memory fix
            $this->wings->onReinstall(function (string $uuid) use ($server): void {
                foreach (LoaderRuntimeFixtures::fabricUnobfuscated26('26.1.2', '0.19.3') as $path => $bytes) {
                    $this->wings->seedFile($uuid, $path, $bytes);
                }
                DB::table('servers')->where('id', $server->getKey())->update(['status' => null]);
            });

            (new ReflectionMethod($migration, 'apply'))->invoke(
                $migration,
                $server,
                $fabricEgg,
                ['MC_VERSION' => '26.1.2', 'LOADER_VERSION' => '0.19.3', 'SERVER_JARFILE' => 'server.jar'],
                'ghcr.io/pelican-eggs/yolks:java_25',
                null,
                static fn (): bool => true,
            );

            $server->refresh();
            self::assertFalse((bool) $server->skip_scripts);
            self::assertStringContainsString('-XX:MaxRAMPercentage=95.0', (string) $server->startup);
            self::assertStringNotContainsString('-Xmx{{SERVER_MEMORY}}M', (string) $server->startup);

            // Verify unobfuscated Fabric 26.1.2 runtime passes assertInstalled() without intermediary
            $this->app->make(ModpackRuntime::class)->assertInstalled(
                $server,
                $repo,
                'fabric',
                '26.1.2',
                '0.19.3',
                ['SERVER_JARFILE' => 'server.jar'],
            );

            // Step 2: Deploy v2 bumping 3 of 20 mods -> preserves user-edited config, catalog mod, and survival_world
            $filesV2 = $filesV1;
            foreach ([0, 1, 2] as $idx) {
                $newBytes = "PK\x03\x04v2-fabric-26-mod-{$idx}";
                $newUrl = "https://cdn.modrinth.com/data/fabup/v2/m-{$idx}.jar";
                $this->registerRemoteFile($newUrl, $newBytes);
                $filesV2[$idx] = [
                    'path' => "mods/pack-{$idx}.jar",
                    'hashes' => ['sha1' => sha1($newBytes), 'sha512' => hash('sha512', $newBytes)],
                    'downloads' => [$newUrl],
                    'fileSize' => strlen($newBytes),
                    'env' => ['server' => 'required'],
                ];
            }
            $zipV2 = $fixtures->buildMrpack(['minecraft' => '26.1.2', 'fabric-loader' => '0.19.3'], $filesV2, overrides: ['config/tweaks.toml' => "pack_v2=true\n"]);

            $this->wings->resetMetrics();
            $installer->deploy($server, $repo, $zipV2, [
                'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'fabup', 'version_id' => 'v2',
                'minecraft_version' => '26.1.2', 'loader' => 'fabric', 'loader_version' => '0.19.3', 'server_pack' => false,
            ], 'fabric', '26.1.2', false, null, null, static fn (): bool => true);

            self::assertSame("PK\x03\x04v2-fabric-26-mod-0", $this->serverFile($server, 'mods/pack-0.jar'));
            self::assertSame("user_custom=true\n", $this->serverFile($server, 'config/tweaks.toml'));
            self::assertSame("PK\x03\x04personal-catalog-mod", $this->serverFile($server, 'mods/personal.jar'));
            self::assertSame('SURVIVAL-NBT-DATA', $this->serverFile($server, 'survival_world/level.dat'));
            self::assertLessThanOrEqual(8, $this->wings->count('readStream'));
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_cancel_mid_bundled_deploy_releases_lease_and_cross_loader_switch_survives_transient_wings_curl_retry(): void
    {
        $cancelClass = 'Kazaminosuke\\ModManager\\Exceptions\\ModpackCancelledException';
        self::assertTrue(class_exists($cancelClass), 'M4 (#20): ModpackCancelledException must exist.');
        self::assertTrue(class_exists('Kazaminosuke\\ModManager\\Services\\ModpackBundleUploader'), 'M4 (#19): ModpackBundleUploader must exist.');
        $migration = $this->app->make(ServerEggMigration::class);
        $applyMethod = new ReflectionMethod($migration, 'apply');
        self::assertGreaterThanOrEqual(
            6,
            count($applyMethod->getParameters()),
            'M4 (#21): ServerEggMigration::apply() must implement evolved 6-arg contract with $startup and $guard.',
        );

        $runner = PluginBackgroundRunner::fake();
        $this->app->instance(PluginBackgroundRunner::class, $runner);

        $forgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::forge(131));
        $neoforgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::neoforge(132));
        $server = $this->createServer(
            $forgeEgg,
            overrides: ['skip_scripts' => true, 'image' => 'ghcr.io/pelican-eggs/yolks:java_17'],
            variableValues: [
                'MC_VERSION' => '1.20.1',
                'FORGE_VERSION' => '1.20.1-47.4.20',
                'BUILD_TYPE' => 'recommended',
                'SERVER_JARFILE' => 'server.jar',
            ],
        );
        /** @var DaemonFileRepository $repo */
        $repo = $this->app->make(DaemonFileRepository::class);
        $installer = $this->app->make(ModpackInstaller::class);
        $fixtures = new ModpackArchiveFixtures();

        try {
            $this->seedServerFile($server, 'world/level.dat', 'FORGE-WORLD-NBT');
            $this->seedServerFile($server, 'ops.json', '[{"name":"op"}]');
            foreach (LoaderRuntimeFixtures::forgeModern('1.20.1', '47.4.20') as $path => $bytes) {
                $this->seedServerFile($server, $path, $bytes);
            }

            $files = [];
            for ($i = 0; $i < 10; $i++) {
                $bytes = "PK\x03\x04nf-mod-{$i}";
                $url = "https://cdn.modrinth.com/data/switch10/m-{$i}.jar";
                $this->registerRemoteFile($url, $bytes);
                $files[] = [
                    'path' => "mods/nf-{$i}.jar",
                    'hashes' => ['sha1' => sha1($bytes), 'sha512' => hash('sha512', $bytes)],
                    'downloads' => [$url],
                    'fileSize' => strlen($bytes),
                    'env' => ['server' => 'required'],
                ];
            }
            $nfZip = $fixtures->buildMrpack(['minecraft' => '1.21.1', 'neoforge' => '21.1.266'], $files);
            $forgeCancelZip = $fixtures->buildMrpack(['minecraft' => '1.20.1', 'forge' => '47.4.20'], $files);
            $forgeCancelBytes = (string) file_get_contents($forgeCancelZip);
            $cancelPackUrl = 'https://cdn.modrinth.com/data/switch10/pack.mrpack';
            $this->registerRemoteFile($cancelPackUrl, $forgeCancelBytes);

            $this->fakeUpstreamHttp([
                'api.modrinth.com/v2/version/v1' => Http::response([
                    'id' => 'v1',
                    'project_id' => 'switch10',
                    'version_number' => '1.0.0',
                    'game_versions' => ['1.20.1'],
                    'loaders' => ['forge'],
                    'files' => [[
                        'primary' => true,
                        'filename' => 'pack.mrpack',
                        'url' => $cancelPackUrl,
                        'size' => strlen($forgeCancelBytes),
                        'hashes' => ['sha1' => sha1($forgeCancelBytes), 'sha512' => hash('sha512', $forgeCancelBytes)],
                    ]],
                ], 200),
            ]);

            // Part A: Real InstallModpack::handle() cancellation releases lease on its own so a scan dispatches cleanly!
            $leases = $this->app->make(InstalledOperationLease::class);
            $ops = $this->app->make(InstalledOperationManager::class);
            $leaseToken = $leases->tryAcquire((int) $server->getKey(), ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK);
            self::assertNotNull($leaseToken);

            $cancelJob = new InstallModpack(
                serverId: (int) $server->getKey(),
                projectType: ProjectType::Mod->value,
                source: 'modrinth',
                projectId: 'switch10',
                leaseToken: $leaseToken,
                versionId: 'v1',
                selection: [
                    'managed_phase' => 'initial',
                    'egg_id' => (string) $forgeEgg->getKey(),
                    'image' => $server->image,
                    'startup' => (string) $server->startup,
                    'minecraft' => '1.20.1',
                    'loader' => 'forge',
                    'loader_version' => '47.4.20',
                    'variables' => [
                        'MC_VERSION' => '1.20.1',
                        'FORGE_VERSION' => '1.20.1-47.4.20',
                        'BUILD_TYPE' => 'recommended',
                        'SERVER_JARFILE' => 'server.jar',
                    ],
                ],
                heartbeat: static function () use ($cancelClass): bool {
                    throw new $cancelClass('Cancelled mid-workflow.');
                },
            );

            $cancelReturn = $cancelJob->handle(
                $repo,
                $this->app->make(ModpackCatalog::class),
                $installer,
                $ops,
                $leases,
            );
            self::assertNull($cancelReturn);
            self::assertFalse($leases->isHeld((int) $server->getKey(), ProjectType::Mod), 'M4 (#20): InstallModpack must release lease when cancelled.');
            $cancelState = $ops->state($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
            self::assertNotNull($cancelState);
            self::assertFalse($cancelState->isActive());
            $this->wings->assertNoTemporaryModManagerFiles($server->uuid);
            $scanAfterCancel = $ops->dispatchScan($server, ProjectType::Mod, actorUserId: 1);
            self::assertTrue($scanAfterCancel['dispatched'] ?? false, 'After cancel & automatic lease release, subsequent scan must dispatch cleanly.');

            // Part B: Migrate Forge -> already-installed NeoForge Egg + bundled deploy surviving cURL error 6 & 7
            $this->wings->onReinstall(function (string $uuid) use ($server): void {
                foreach (LoaderRuntimeFixtures::neoforgeModern('1.21.1', '21.1.266') as $path => $bytes) {
                    $this->wings->seedFile($uuid, $path, $bytes);
                }
                DB::table('servers')->where('id', $server->getKey())->update(['status' => null]);
            });

            $applyMethod->invoke(
                $migration,
                $server,
                $neoforgeEgg,
                ['MC_VERSION' => '1.21.1', 'NEOFORGE_VERSION' => '21.1.266'],
                'ghcr.io/pelican-eggs/yolks:java_21',
                null,
                static fn (): bool => true,
            );

            $server->refresh();
            self::assertSame($neoforgeEgg->getKey(), $server->egg_id);
            self::assertArrayNotHasKey(
                'FORGE_VERSION',
                $this->app->make(InstalledEggCatalog::class)->variableValues($server),
                'M4 (#21): keepOldVariables:false must remove stale FORGE_VERSION when switching to NeoForge Egg.',
            );

            $this->wings->setDisableRemoteDownload(true);
            $this->wings->injectConnectionError('putStream', StrictWingsFake::CURL_ERR_6_DNS, 1);
            $this->wings->injectConnectionError('decompress', StrictWingsFake::CURL_ERR_7_REFUSED, 1);

            $installer->deploy($server, $repo, $nfZip, [
                'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'sw10', 'version_id' => 'v1',
                'minecraft_version' => '1.21.1', 'loader' => 'neoforge', 'loader_version' => '21.1.266', 'server_pack' => false,
            ], 'neoforge', '1.21.1', false, null, null, static fn (): bool => true);

            self::assertSame('FORGE-WORLD-NBT', $this->serverFile($server, 'world/level.dat'));
            self::assertSame('[{"name":"op"}]', $this->serverFile($server, 'ops.json'));
            self::assertSame("PK\x03\x04nf-mod-9", $this->serverFile($server, 'mods/nf-9.jar'));
            self::assertNull($this->app->make(ModpackInventory::class)->read($server)['recovery']);
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_existing_server_migration_flows_through_coordinator_update_workflow_and_install_modpack(): void
    {
        self::assertTrue(
            class_exists('Kazaminosuke\\ModManager\\Services\\ModpackBundleUploader'),
            'M4 (#19): ModpackBundleUploader must exist.',
        );
        self::assertGreaterThanOrEqual(
            6,
            count((new ReflectionMethod(ServerEggMigration::class, 'apply'))->getParameters()),
            'M4 (#21): ServerEggMigration::apply() must implement evolved 6-arg contract.',
        );

        $runner = PluginBackgroundRunner::fake();
        $this->app->instance(PluginBackgroundRunner::class, $runner);

        $forgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::forge(133));
        $neoforgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::neoforge(134));
        $server = $this->createServer(
            $forgeEgg,
            overrides: ['skip_scripts' => true, 'image' => 'ghcr.io/pelican-eggs/yolks:java_17'],
            variableValues: [
                'MC_VERSION' => '1.20.1',
                'FORGE_VERSION' => '1.20.1-47.4.20',
                'BUILD_TYPE' => 'recommended',
                'SERVER_JARFILE' => 'server.jar',
            ],
        );

        /** @var DaemonFileRepository $repo */
        $repo = $this->app->make(DaemonFileRepository::class);
        $installer = $this->app->make(ModpackInstaller::class);
        $fixtures = new ModpackArchiveFixtures();

        try {
            foreach (LoaderRuntimeFixtures::forgeModern('1.20.1', '47.4.20') as $path => $bytes) {
                $this->seedServerFile($server, $path, $bytes);
            }
            $this->seedServerFile($server, 'world/level.dat', 'MIGRATION-WORLD-NBT');

            // Deploy initial 1-mod Forge v1 pack so ModpackInventory tracks v1
            $oldForgeBytes = "PK\x03\x04old-forge-mod";
            $oldForgeUrl = 'https://cdn.modrinth.com/data/oldforge/v1/old-forge.jar';
            $this->registerRemoteFile($oldForgeUrl, $oldForgeBytes);
            $v1ForgeZip = $fixtures->buildMrpack(['minecraft' => '1.20.1', 'forge' => '47.4.20'], [[
                'path' => 'mods/old-forge.jar',
                'hashes' => ['sha1' => sha1($oldForgeBytes), 'sha512' => hash('sha512', $oldForgeBytes)],
                'downloads' => [$oldForgeUrl],
                'fileSize' => strlen($oldForgeBytes),
                'env' => ['server' => 'required'],
            ]]);
            $installer->deploy($server, $repo, $v1ForgeZip, [
                'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'oldforge', 'version_id' => 'v1',
                'minecraft_version' => '1.20.1', 'loader' => 'forge', 'loader_version' => '47.4.20', 'server_pack' => false,
            ], 'forge', '1.20.1', false, null, null, static fn (): bool => true);
            self::assertTrue($this->hasServerFile($server, 'mods/old-forge.jar'));

            // Build NeoForge 21.1.266 / MC 1.21.1 .mrpack
            $nfBytes = "PK\x03\x04nf-migrated-mod";
            $nfModUrl = 'https://cdn.modrinth.com/data/nfmig/v1/nf-migrated.jar';
            $this->registerRemoteFile($nfModUrl, $nfBytes);
            $nfPackPath = $fixtures->buildMrpack(['minecraft' => '1.21.1', 'neoforge' => '21.1.266'], [[
                'path' => 'mods/nf-migrated.jar',
                'hashes' => ['sha1' => sha1($nfBytes), 'sha512' => hash('sha512', $nfBytes)],
                'downloads' => [$nfModUrl],
                'fileSize' => strlen($nfBytes),
                'env' => ['server' => 'required'],
            ]]);
            $nfPackBytes = (string) file_get_contents($nfPackPath);
            $nfPackUrl = 'https://cdn.modrinth.com/data/nfmig/v1/pack.mrpack';
            $this->registerRemoteFile($nfPackUrl, $nfPackBytes);

            $this->fakeUpstreamHttp([
                'api.modrinth.com/v2/project/nfmig' => Http::response([
                    'id' => 'nfmig', 'slug' => 'nf-mig', 'title' => 'NeoForge Migrated Pack', 'description' => 'Migration E2E',
                ], 200),
                'api.modrinth.com/v2/version/v1' => Http::response([
                    'id' => 'v1',
                    'project_id' => 'nfmig',
                    'version_number' => '1.0.0',
                    'game_versions' => ['1.21.1'],
                    'loaders' => ['neoforge'],
                    'files' => [[
                        'primary' => true,
                        'filename' => 'pack.mrpack',
                        'url' => $nfPackUrl,
                        'size' => strlen($nfPackBytes),
                        'hashes' => ['sha1' => sha1($nfPackBytes), 'sha512' => hash('sha512', $nfPackBytes)],
                    ]],
                ], 200),
            ]);

            /** @var ModpackInstallCoordinator $coordinator */
            $coordinator = $this->app->make(ModpackInstallCoordinator::class);
            /** @var ModpackUpdateWorkflow $workflow */
            $workflow = $this->app->make(ModpackUpdateWorkflow::class);

            // Step 1: Preview detects EggChange to already-installed NeoForge Egg with reinstall=true
            $preview = $coordinator->preview($server, 'modrinth:nfmig', 'v1');
            self::assertSame(CompatibilityDisposition::EggChange, $preview['result']->disposition);
            self::assertSame($neoforgeEgg->getKey(), $preview['result']->selected?->id);
            self::assertTrue($preview['result']->reinstall);

            // Step 2: Unconfirmed queuePreview() must still throw ModpackException (safety preserved!)
            $unconfirmedCaught = null;
            try {
                $coordinator->queuePreview($server, 'modrinth:nfmig', 'v1');
            } catch (ModpackException $e) {
                $unconfirmedCaught = $e;
            }
            self::assertInstanceOf(ModpackException::class, $unconfirmedCaught);

            // Step 3: Confirmed queuePreview() queues preview job (adapting to optional migration params beyond arg 4)
            $qpMethod = new ReflectionMethod($coordinator, 'queuePreview');
            $qpArgs = [$server, 'modrinth:nfmig', 'v1', true];
            foreach (array_slice($qpMethod->getParameters(), 4) as $extraParam) {
                $pName = strtolower($extraParam->getName());
                if (str_contains($pName, 'egg')) {
                    $qpArgs[] = $neoforgeEgg->getKey();
                } elseif (str_contains($pName, 'migrat') || str_contains($pName, 'reinstall') || str_contains($pName, 'allow')) {
                    $qpArgs[] = true;
                } elseif ($extraParam->isDefaultValueAvailable()) {
                    $qpArgs[] = $extraParam->getDefaultValue();
                } else {
                    $qpArgs[] = null;
                }
            }
            $qpMethod->invokeArgs($coordinator, $qpArgs);
            self::assertCount(1, $runner->spawned, 'M4 (#21): Confirmed existing-server migration must queue preview job.');

            // Step 4: Execute preview job -> publishes review
            BackgroundJob::execute(BackgroundJob::MODPACK, $runner->spawned[0]['payload']);
            $review = $workflow->review($server);
            self::assertNotNull($review, 'M4 (#21): Preview job must publish review payload.');

            // Step 5: Handle Wings backup endpoint, create backup, mark completed, approve switch
            $this->wings->beforeEndpoint('*', static function (Request $req, string $uuid, string $ep, StrictWingsFake $wings) {
                if (strtoupper($req->method()) === 'POST' && str_contains($req->url(), '/backup')) {
                    return Http::response('', 204, ['User-Agent' => $wings->userAgentHeader()]);
                }

                return null;
            });
            $workflow->createBackup($server, (string) $review['token'], $this->app->make(InitiateBackupService::class));
            $reviewAfterBackup = $workflow->review($server);
            self::assertNotNull($reviewAfterBackup);
            DB::table('backups')
                ->where('id', $reviewAfterBackup['backup_id'])
                ->update(['is_successful' => true, 'completed_at' => now()]);
            $workflow->approve($server, (string) $review['token'], true, null);
            self::assertCount(2, $runner->spawned, 'M4 (#21): Approval must queue apply job.');

            // Step 6: Simulate Wings reinstall producing NeoForge runtime, execute apply job
            $this->wings->onReinstall(function (string $uuid) use ($server): void {
                foreach (LoaderRuntimeFixtures::neoforgeModern('1.21.1', '21.1.266') as $path => $bytes) {
                    $this->wings->seedFile($uuid, $path, $bytes);
                }
                DB::table('servers')->where('id', $server->getKey())->update(['status' => null]);
            });
            BackgroundJob::execute(BackgroundJob::MODPACK, $runner->spawned[1]['payload']);

            // Step 7: Verify complete end-to-end Egg migration + deployment + preservation!
            $server->refresh();
            self::assertSame($neoforgeEgg->getKey(), $server->egg_id);
            self::assertFalse((bool) $server->skip_scripts);
            self::assertGreaterThanOrEqual(1, $this->wings->count('reinstall'));
            self::assertArrayNotHasKey('FORGE_VERSION', $this->app->make(InstalledEggCatalog::class)->variableValues($server));
            self::assertSame($nfBytes, $this->serverFile($server, 'mods/nf-migrated.jar'));
            self::assertFalse($this->hasServerFile($server, 'mods/old-forge.jar'));
            self::assertSame('MIGRATION-WORLD-NBT', $this->serverFile($server, 'world/level.dat'));
            $opState = $this->app->make(InstalledOperationManager::class)->state($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
            self::assertSame(InstalledOperationState::STATUS_COMPLETED, $opState?->status);
        } finally {
            $fixtures->cleanup();
        }
    }
}
