<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier1;

use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Jobs\InstallModpack;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackInventory;
use Kazaminosuke\ModManager\Services\ServerEggMigration;
use Kazaminosuke\ModManager\Support\DatabaseBackgroundJobQueue;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\LoaderRuntimeFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\ModpackArchiveFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use ReflectionMethod;

/**
 * Tier 1 — Isolated Feature Coverage for Modpack Features #19, #20, #21 (M4).
 */
final class ModpackBundledDeployAndMigrationE2ETest extends E2ETestCase
{
    public function test_bundled_panel_verified_deployment_succeeds_with_disable_remote_download_zero_readback_and_incremental_fast_path(): void
    {
        $fixtures = new ModpackArchiveFixtures();
        try {
            // M4 (#19): Required contract classes exist
            self::assertTrue(
                class_exists('Kazaminosuke\\ModManager\\Services\\SafeHttpDownload'),
                'M4 (#19): SafeHttpDownload must exist for SSRF-guarded Panel-side downloads.',
            );
            self::assertTrue(
                class_exists('Kazaminosuke\\ModManager\\Services\\ModpackBundleUploader'),
                'M4 (#19): ModpackBundleUploader must exist for Panel-verified bounded ZIP staging.',
            );

            // Real Wings with disable_remote_download: true MUST succeed via Panel -> /files/write -> /files/decompress!
            $this->wings->setDisableRemoteDownload(true);

            $filesV1 = [];
            $expectedV1 = [];
            for ($i = 0; $i < 40; $i++) {
                $bytes = "PK\x03\x04mod-v1-content-".str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                $url = "https://cdn.modrinth.com/data/bundle40/v1/mod-{$i}.jar";
                $this->registerRemoteFile($url, $bytes);
                $filesV1[] = [
                    'path' => "mods/mod-{$i}.jar",
                    'hashes' => ['sha1' => sha1($bytes), 'sha512' => hash('sha512', $bytes)],
                    'downloads' => [$url],
                    'fileSize' => strlen($bytes),
                    'env' => ['server' => 'required'],
                ];
                $expectedV1["mods/mod-{$i}.jar"] = $bytes;
            }
            $zipV1 = $fixtures->buildMrpack(['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'], $filesV1);

            $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(60));
            $server = $this->createServer($fabricEgg);
            $repo = $this->app->make(DaemonFileRepository::class);
            $installer = $this->app->make(ModpackInstaller::class);

            $this->wings->resetMetrics();
            $installer->deploy(
                $server,
                $repo,
                $zipV1,
                [
                    'provider' => ModpackManifest::FORMAT_MODRINTH,
                    'pack_id' => 'bundle40',
                    'version_id' => 'v1',
                    'minecraft_version' => '1.21.1',
                    'loader' => 'fabric',
                    'loader_version' => '0.16.10',
                    'server_pack' => false,
                ],
                'fabric',
                '1.21.1',
                false,
                null,
                null,
                static fn (): bool => true,
            );

            self::assertSame(0, $this->wings->count('pull'), 'M4 (#19): Bundled deployment must not call Wings /files/pull when disable_remote_download is true.');
            self::assertGreaterThanOrEqual(1, $this->wings->count('decompress'), 'M4 (#19): Bundled deployment must extract staged bundle via Wings /files/decompress.');
            $this->wings->assertBytesWingsToPanelAtMost(0, 'M4 (#19): Panel-verified bundled deploy must perform 0 bytes Wings->Panel read-back.');
            $this->wings->assertTotalCallsAtMost(25, 'M4 (#19): 40-file bundled deploy should use far fewer than 25 Wings HTTP calls.');
            $this->wings->assertNoTemporaryModManagerFiles($server->uuid);

            foreach ($expectedV1 as $path => $bytes) {
                self::assertSame($bytes, $this->serverFile($server, $path));
            }

            // v2 incremental update modifying only 2 of 40 files -> (size, modified_at) fast-path avoids full read-back!
            $filesV2 = $filesV1;
            foreach ([0, 1] as $changedIdx) {
                $newBytes = "PK\x03\x04mod-v2-updated-{$changedIdx}";
                $newUrl = "https://cdn.modrinth.com/data/bundle40/v2/mod-{$changedIdx}.jar";
                $this->registerRemoteFile($newUrl, $newBytes);
                $filesV2[$changedIdx] = [
                    'path' => "mods/mod-{$changedIdx}.jar",
                    'hashes' => ['sha1' => sha1($newBytes), 'sha512' => hash('sha512', $newBytes)],
                    'downloads' => [$newUrl],
                    'fileSize' => strlen($newBytes),
                    'env' => ['server' => 'required'],
                ];
            }
            $zipV2 = $fixtures->buildMrpack(['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'], $filesV2);

            $this->wings->resetMetrics();
            $installer->deploy(
                $server,
                $repo,
                $zipV2,
                [
                    'provider' => ModpackManifest::FORMAT_MODRINTH,
                    'pack_id' => 'bundle40',
                    'version_id' => 'v2',
                    'minecraft_version' => '1.21.1',
                    'loader' => 'fabric',
                    'loader_version' => '0.16.10',
                    'server_pack' => false,
                ],
                'fabric',
                '1.21.1',
                false,
                null,
                null,
                static fn (): bool => true,
            );

            self::assertLessThanOrEqual(
                6,
                $this->wings->count('readStream'),
                'M4 (#19): Incremental (size, modified_at) fast-path must avoid re-reading all 38 unchanged files.',
            );
            self::assertSame("PK\x03\x04mod-v2-updated-0", $this->serverFile($server, 'mods/mod-0.jar'));
            self::assertSame("PK\x03\x04mod-v2-updated-1", $this->serverFile($server, 'mods/mod-1.jar'));
            self::assertSame($expectedV1['mods/mod-39.jar'], $this->serverFile($server, 'mods/mod-39.jar'));
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_cooperative_cancellation_throws_modpack_cancelled_exception_cleans_staging_and_preserves_v1_files(): void
    {
        $cancelClass = 'Kazaminosuke\\ModManager\\Exceptions\\ModpackCancelledException';
        self::assertTrue(
            class_exists($cancelClass),
            'M4 (#20): ModpackCancelledException must exist for cooperative in-flight cancellation.',
        );

        $fixtures = new ModpackArchiveFixtures();
        try {
            $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(61));
            $server = $this->createServer($fabricEgg);
            $this->seedServerFile($server, 'mods/existing-v1.jar', "PK\x03\x04keep-me-intact");

            $files = [];
            for ($i = 0; $i < 15; $i++) {
                $bytes = "PK\x03\x04cancel-item-{$i}";
                $url = "https://cdn.modrinth.com/data/cancel15/mod-{$i}.jar";
                $this->registerRemoteFile($url, $bytes);
                $files[] = [
                    'path' => "mods/new-{$i}.jar",
                    'hashes' => ['sha1' => sha1($bytes), 'sha512' => hash('sha512', $bytes)],
                    'downloads' => [$url],
                    'fileSize' => strlen($bytes),
                    'env' => ['server' => 'required'],
                ];
            }
            $mrpack = $fixtures->buildMrpack(['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'], $files);

            $guardCalls = 0;
            $caught = null;
            try {
                $this->app->make(ModpackInstaller::class)->deploy(
                    $server,
                    $this->app->make(DaemonFileRepository::class),
                    $mrpack,
                    [
                        'provider' => ModpackManifest::FORMAT_MODRINTH,
                        'pack_id' => 'cancel15',
                        'version_id' => 'v1',
                        'minecraft_version' => '1.21.1',
                        'loader' => 'fabric',
                        'loader_version' => '0.16.10',
                        'server_pack' => false,
                    ],
                    'fabric',
                    '1.21.1',
                    false,
                    null,
                    null,
                    static function () use (&$guardCalls, $cancelClass): void {
                        $guardCalls++;
                        if ($guardCalls >= 2) {
                            throw new $cancelClass('Operator requested cancellation.');
                        }
                    },
                );
            } catch (\Throwable $e) {
                $caught = $e;
            }

            self::assertInstanceOf($cancelClass, $caught, 'M4 (#20): Cooperative cancellation must propagate ModpackCancelledException.');
            self::assertSame("PK\x03\x04keep-me-intact", $this->serverFile($server, 'mods/existing-v1.jar'));
            $this->wings->assertNoTemporaryModManagerFiles($server->uuid);
            $inventoryState = $this->app->make(ModpackInventory::class)->read($server);
            self::assertNull($inventoryState['recovery']);
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_install_modpack_emits_progress_renews_queue_heartbeat_and_records_terminal_cancelled_state(): void
    {
        $cancelClass = 'Kazaminosuke\\ModManager\\Exceptions\\ModpackCancelledException';
        self::assertTrue(
            class_exists($cancelClass),
            'M4 (#20): ModpackCancelledException must exist for cooperative in-flight cancellation.',
        );
        self::assertTrue(
            class_exists('Kazaminosuke\\ModManager\\Services\\ModpackBundleUploader'),
            'M4 (#19): ModpackBundleUploader must exist for bundled deployment.',
        );

        $fixtures = new ModpackArchiveFixtures();
        try {
            $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(65));
            $server = $this->createServer(
                $fabricEgg,
                overrides: ['image' => 'ghcr.io/pelican-eggs/yolks:java_21'],
                variableValues: [
                    'MC_VERSION' => '1.21.1',
                    'LOADER_VERSION' => '0.16.10',
                    'SERVER_JARFILE' => 'server.jar',
                ],
            );
            foreach (LoaderRuntimeFixtures::fabricObfuscated('1.21.1', '0.16.10') as $path => $bytes) {
                $this->seedServerFile($server, $path, $bytes);
            }

            $files = [];
            for ($i = 0; $i < 10; $i++) {
                $bytes = "PK\x03\x04prog10-mod-{$i}";
                $url = "https://cdn.modrinth.com/data/prog10/v1/mod-{$i}.jar";
                $this->registerRemoteFile($url, $bytes);
                $files[] = [
                    'path' => "mods/mod-{$i}.jar",
                    'hashes' => ['sha1' => sha1($bytes), 'sha512' => hash('sha512', $bytes)],
                    'downloads' => [$url],
                    'fileSize' => strlen($bytes),
                    'env' => ['server' => 'required'],
                ];
            }
            $mrpackPath = $fixtures->buildMrpack(['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'], $files);
            $mrpackBytes = (string) file_get_contents($mrpackPath);
            $mrpackUrl = 'https://cdn.modrinth.com/data/prog10/v1/pack.mrpack';
            $this->registerRemoteFile($mrpackUrl, $mrpackBytes);

            $this->fakeUpstreamHttp([
                'api.modrinth.com/v2/version/v1' => Http::response([
                    'id' => 'v1',
                    'project_id' => 'prog10',
                    'version_number' => '1.0.0',
                    'game_versions' => ['1.21.1'],
                    'loaders' => ['fabric'],
                    'files' => [[
                        'primary' => true,
                        'filename' => 'pack.mrpack',
                        'url' => $mrpackUrl,
                        'size' => strlen($mrpackBytes),
                        'hashes' => ['sha1' => sha1($mrpackBytes), 'sha512' => hash('sha512', $mrpackBytes)],
                    ]],
                ], 200),
            ]);

            /** @var InstalledOperationManager $operations */
            $operations = $this->app->make(InstalledOperationManager::class);
            /** @var InstalledOperationLease $leases */
            $leases = $this->app->make(InstalledOperationLease::class);
            /** @var DatabaseBackgroundJobQueue $queue */
            $queue = $this->app->make(DatabaseBackgroundJobQueue::class);

            // Part A: Progress + DatabaseBackgroundJobQueue::heartbeat() renewal
            $leaseToken = $leases->tryAcquire((int) $server->getKey(), ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK);
            self::assertNotNull($leaseToken);

            $selection = [
                'managed_phase' => 'initial',
                'egg_id' => (string) $fabricEgg->getKey(),
                'image' => $server->image,
                'startup' => (string) $server->startup,
                'minecraft' => '1.21.1',
                'loader' => 'fabric',
                'loader_version' => '0.16.10',
                'variables' => [
                    'MC_VERSION' => '1.21.1',
                    'LOADER_VERSION' => '0.16.10',
                    'SERVER_JARFILE' => 'server.jar',
                ],
            ];
            self::assertTrue($queue->push(BackgroundJob::MODPACK, [
                'server_id' => (int) $server->getKey(),
                'project_type' => ProjectType::Mod->value,
                'source' => 'modrinth',
                'project_id' => 'prog10',
                'version_id' => 'v1',
                'lease_token' => $leaseToken,
                'selection' => $selection,
            ]));

            $claimed = $queue->claim();
            self::assertNotNull($claimed);
            DB::table('mod_manager_background_jobs')
                ->where('id', $claimed['id'])
                ->update(['reserved_at' => now()->subMinutes(4)]);

            $heartbeatCount = 0;
            $heartbeatWrapper = static function () use ($queue, $claimed, &$heartbeatCount): bool {
                $heartbeatCount++;

                return $queue->heartbeat($claimed['id'], $claimed['attempts']);
            };

            $defer = BackgroundJob::execute(BackgroundJob::MODPACK, $claimed['payload'], $claimed['attempts'], $heartbeatWrapper);
            self::assertNull($defer);
            self::assertGreaterThanOrEqual(1, $heartbeatCount, 'M4 (#20): InstallModpack must renew queue heartbeat during transfer.');

            $reservedAt = DB::table('mod_manager_background_jobs')->where('id', $claimed['id'])->value('reserved_at');
            self::assertNotNull($reservedAt);
            self::assertGreaterThan(now()->subMinutes(2)->getTimestamp(), strtotime((string) $reservedAt));

            $opState = $operations->state($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
            self::assertInstanceOf(InstalledOperationState::class, $opState);
            self::assertSame(InstalledOperationState::STATUS_COMPLETED, $opState->status);
            self::assertNotNull($opState->total);
            self::assertGreaterThanOrEqual(10, $opState->total, 'M4 (#20): Deployment must emit InstalledOperationManager::progress($done, $total).');
            self::assertSame($opState->total, $opState->progress);
            self::assertFalse($leases->isHeld((int) $server->getKey(), ProjectType::Mod));

            // Part B: InstallModpack::handle() catching ModpackCancelledException -> terminal cancelled state + lease released
            $server2 = $this->createServer(
                $fabricEgg,
                overrides: ['image' => 'ghcr.io/pelican-eggs/yolks:java_21'],
                variableValues: [
                    'MC_VERSION' => '1.21.1',
                    'LOADER_VERSION' => '0.16.10',
                    'SERVER_JARFILE' => 'server.jar',
                ],
            );
            foreach (LoaderRuntimeFixtures::fabricObfuscated('1.21.1', '0.16.10') as $path => $bytes) {
                $this->seedServerFile($server2, $path, $bytes);
            }
            $this->seedServerFile($server2, 'mods/keep-existing.jar', "PK\x03\x04keep");

            $cancelLease = $leases->tryAcquire((int) $server2->getKey(), ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK);
            self::assertNotNull($cancelLease);

            $cancelJob = new InstallModpack(
                serverId: (int) $server2->getKey(),
                projectType: ProjectType::Mod->value,
                source: 'modrinth',
                projectId: 'prog10',
                leaseToken: $cancelLease,
                versionId: 'v1',
                selection: $selection,
                heartbeat: static function () use ($cancelClass): bool {
                    throw new $cancelClass('Operator cancelled.');
                },
            );

            $cancelResult = $cancelJob->handle(
                $this->app->make(DaemonFileRepository::class),
                $this->app->make(ModpackCatalog::class),
                $this->app->make(ModpackInstaller::class),
                $operations,
                $leases,
            );
            self::assertNull($cancelResult, 'Cancelled InstallModpack job must return null (no retry/deferral).');
            self::assertFalse($leases->isHeld((int) $server2->getKey(), ProjectType::Mod), 'M4 (#20): InstallModpack catching ModpackCancelledException must release lease.');

            $cancelState = $operations->state($server2, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
            self::assertNotNull($cancelState);
            self::assertFalse($cancelState->isActive(), 'Cancelled state must be terminal.');
            self::assertTrue(
                $cancelState->status === 'cancelled'
                || ($cancelState->status === InstalledOperationState::STATUS_FAILED && str_contains(strtolower((string) $cancelState->error), 'cancel')),
                'M4 (#20): InstallModpack catching ModpackCancelledException must record terminal cancelled state.',
            );
            $this->wings->assertNoTemporaryModManagerFiles($server2->uuid);
            self::assertSame("PK\x03\x04keep", $this->serverFile($server2, 'mods/keep-existing.jar'));
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_server_egg_migration_resets_skip_scripts_fixes_unlimited_memory_verifies_runtime_and_merges_server_properties(): void
    {
        // 1. M4 (#21): ServerPropertiesMerger contract + allowlist verification
        $mergerClass = 'Kazaminosuke\\ModManager\\Services\\ServerPropertiesMerger';
        self::assertTrue(
            class_exists($mergerClass),
            'M4 (#21): ServerPropertiesMerger must exist to merge safe operational server.properties allowlist.',
        );

        $existingProps = implode("\n", [
            'motd=My Production SMP',
            'server-port=25566',
            'max-players=40',
            'white-list=true',
            'rcon.password=s3cr3t',
            'level-name=my_survival_world',
            'level-type=minecraft\:normal',
            'generator-settings={}',
        ])."\n";
        $packProps = implode("\n", [
            'motd=Pack Default MOTD',
            'server-port=25565',
            'max-players=20',
            'white-list=false',
            'level-name=world',
            'level-type=skyblock\:void',
            'generator-settings={"preset":"skyblock"}',
        ])."\n";

        $mergedKeepWorld = $mergerClass::merge($existingProps, $packProps, true);
        self::assertStringContainsString('server-port=25566', $mergedKeepWorld);
        self::assertStringContainsString('motd=My Production SMP', $mergedKeepWorld);
        self::assertStringContainsString('max-players=40', $mergedKeepWorld);
        self::assertStringContainsString('white-list=true', $mergedKeepWorld);
        self::assertStringContainsString('rcon.password=s3cr3t', $mergedKeepWorld);
        self::assertStringContainsString('level-name=my_survival_world', $mergedKeepWorld);
        self::assertStringContainsString('skyblock', $mergedKeepWorld);

        $mergedNewWorld = $mergerClass::merge($existingProps, $packProps, false);
        self::assertStringContainsString('level-name=world', $mergedNewWorld);
        self::assertStringContainsString('server-port=25566', $mergedNewWorld);

        // 2. M4 (#21): ServerEggMigration evolved contract check (reflection-guarded so pre-M4 fails cleanly, never TypeError)
        $migration = $this->app->make(ServerEggMigration::class);
        $method = new ReflectionMethod($migration, 'apply');
        $params = $method->getParameters();
        self::assertGreaterThanOrEqual(
            6,
            count($params),
            'M4 (#21): ServerEggMigration::apply() must implement contract (Server $server, Egg $targetEgg, array $variables, ?string $dockerImage, ?string $startup, callable $guard).',
        );

        $forgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::forge(62));
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(63));
        $neoforgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::neoforge(64));
        $eggsCountBefore = DB::table('eggs')->count();
        $eggVarsCountBefore = DB::table('egg_variables')->count();

        // 2a. Migrate Forge -> Fabric Egg with memory=0: proves REAL -Xmx{{SERVER_MEMORY}}M -> -XX:MaxRAMPercentage=95.0 rewrite
        // and keepOldVariables:false removing stale FORGE_VERSION / BUILD_TYPE!
        $server = $this->createServer(
            $forgeEgg,
            overrides: [
                'skip_scripts' => true,
                'memory' => 0,
            ],
            variableValues: [
                'MC_VERSION' => '1.20.1',
                'FORGE_VERSION' => '1.20.1-47.4.20',
                'BUILD_TYPE' => 'recommended',
                'SERVER_JARFILE' => 'server.jar',
            ],
        );

        $this->wings->onReinstall(function (string $uuid) use ($server): void {
            foreach (LoaderRuntimeFixtures::fabricObfuscated('1.21.1', '0.16.10') as $path => $bytes) {
                $this->wings->seedFile($uuid, $path, $bytes);
            }
            DB::table('servers')->where('id', $server->getKey())->update(['status' => null]);
        });

        $guardInvocations = 0;
        $method->invoke(
            $migration,
            $server,
            $fabricEgg,
            ['MC_VERSION' => '1.21.1', 'LOADER_VERSION' => '0.16.10', 'SERVER_JARFILE' => 'server.jar'],
            'ghcr.io/pelican-eggs/yolks:java_21',
            null,
            static function () use (&$guardInvocations): void {
                $guardInvocations++;
            },
        );

        $server->refresh();
        self::assertSame($fabricEgg->getKey(), $server->egg_id);
        self::assertFalse((bool) $server->skip_scripts, 'M4 (#21): ServerEggMigration must reset skip_scripts=false before reinstall.');
        self::assertSame('ghcr.io/pelican-eggs/yolks:java_21', $server->image);
        self::assertStringNotContainsString('-Xmx{{SERVER_MEMORY}}M', (string) $server->startup, 'M4 (#21): Fabric startup with memory=0 must rewrite -Xmx{{SERVER_MEMORY}}M.');
        self::assertStringContainsString('-XX:MaxRAMPercentage=95.0', (string) $server->startup, 'M4 (#21): Fabric startup with memory=0 must apply -XX:MaxRAMPercentage=95.0.');

        /** @var InstalledEggCatalog $eggCatalog */
        $eggCatalog = $this->app->make(InstalledEggCatalog::class);
        $fabricVars = $eggCatalog->variableValues($server);
        self::assertArrayNotHasKey('FORGE_VERSION', $fabricVars, 'M4 (#21): keepOldVariables:false must remove stale Forge variables.');
        self::assertArrayNotHasKey('BUILD_TYPE', $fabricVars);
        self::assertSame('1.21.1', $fabricVars['MC_VERSION'] ?? null);
        self::assertSame('0.16.10', $fabricVars['LOADER_VERSION'] ?? null);
        self::assertGreaterThanOrEqual(1, $guardInvocations, 'M4 (#21): Reinstall wait loop must invoke $guard for heartbeat/cancellation.');
        self::assertSame($eggsCountBefore, DB::table('eggs')->count(), 'ServerEggMigration must NEVER auto-import Eggs.');
        self::assertSame($eggVarsCountBefore, DB::table('egg_variables')->count(), 'ServerEggMigration must NEVER mutate Egg variables.');
        $this->wings->assertEndpointCalled('reinstall', 1);

        // 2b. Switch Fabric -> NeoForge Egg: keepOldVariables:false must remove stale LOADER_VERSION
        $this->wings->onReinstall(function (string $uuid) use ($server): void {
            foreach (LoaderRuntimeFixtures::neoforgeModern('1.21.1', '21.1.266') as $path => $bytes) {
                $this->wings->seedFile($uuid, $path, $bytes);
            }
            DB::table('servers')->where('id', $server->getKey())->update(['status' => null]);
        });
        $method->invoke(
            $migration,
            $server,
            $neoforgeEgg,
            ['MC_VERSION' => '1.21.1', 'NEOFORGE_VERSION' => '21.1.266'],
            'ghcr.io/pelican-eggs/yolks:java_21',
            null,
            static function (): void {},
        );
        $server->refresh();
        self::assertSame($neoforgeEgg->getKey(), $server->egg_id);
        self::assertArrayNotHasKey('LOADER_VERSION', $eggCatalog->variableValues($server), 'M4 (#21): keepOldVariables:false must remove stale Fabric LOADER_VERSION.');

        // 2c. Unknown target build on same NeoForge Egg must clear stale NEOFORGE_VERSION back to ''
        $method->invoke(
            $migration,
            $server,
            $neoforgeEgg,
            ['MC_VERSION' => '1.21.1'],
            'ghcr.io/pelican-eggs/yolks:java_21',
            null,
            static function (): void {},
        );
        $server->refresh();
        self::assertSame('', $eggCatalog->variableValues($server)['NEOFORGE_VERSION'] ?? null, 'M4 (#21): Unknown target build must clear stale NEOFORGE_VERSION.');

        // 2d. Unknown target build on same Forge Egg must clear stale FORGE_VERSION back to ''
        $forgeServer = $this->createServer(
            $forgeEgg,
            variableValues: [
                'MC_VERSION' => '1.20.1',
                'FORGE_VERSION' => '1.20.1-47.4.20',
                'BUILD_TYPE' => 'recommended',
                'SERVER_JARFILE' => 'server.jar',
            ],
        );
        $this->wings->onReinstall(function (string $uuid) use ($forgeServer): void {
            foreach (LoaderRuntimeFixtures::forgeModern('1.20.1', '47.4.20') as $path => $bytes) {
                $this->wings->seedFile($uuid, $path, $bytes);
            }
            DB::table('servers')->where('id', $forgeServer->getKey())->update(['status' => null]);
        });
        $method->invoke(
            $migration,
            $forgeServer,
            $forgeEgg,
            ['MC_VERSION' => '1.20.1'],
            'ghcr.io/pelican-eggs/yolks:java_17',
            null,
            static function (): void {},
        );
        $forgeServer->refresh();
        self::assertSame('', $eggCatalog->variableValues($forgeServer)['FORGE_VERSION'] ?? null, 'M4 (#21): Unknown target build must clear stale FORGE_VERSION.');
    }
}
