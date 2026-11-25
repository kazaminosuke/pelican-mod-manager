<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier3;

use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Exceptions\CurseForgeDependenciesPending;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackInventory;
use Kazaminosuke\ModManager\Services\ModpackProvisioningPlanner;
use Kazaminosuke\ModManager\Sources\FtbModpackSource;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Kazaminosuke\ModManager\Support\Modpacks\FtbReleaseManifest;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\ModpackArchiveFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use Kazaminosuke\ModManager\Tests\E2E\Support\StrictWingsFake;

/**
 * Tier 3 — Cross-Feature Combinations for Modpack Features #13–#18 + #19 (M3 + M4).
 */
final class ModpackRangeMirrorClientSideAndRuntimeWorkflowE2ETest extends E2ETestCase
{
    public function test_curseforge_client_pack_redirect_probe_class_id_routing_client_filter_manual_zip_and_bundled_deploy(): void
    {
        self::assertTrue(
            class_exists('Kazaminosuke\\ModManager\\Support\\Modpacks\\JarClientSideInspector'),
            'M3 (#16): JarClientSideInspector must exist for CurseForge client-only JAR filtering.',
        );
        self::assertTrue(
            class_exists('Kazaminosuke\\ModManager\\Services\\ModpackBundleUploader'),
            'M4 (#19): ModpackBundleUploader must exist for bundled deploy under disable_remote_download: true.',
        );

        $fixtures = new ModpackArchiveFixtures();
        try {
            $universalJar = (string) file_get_contents($fixtures->buildFabricModJar('servercore', '*'));
            $clientJar = (string) file_get_contents($fixtures->buildFabricModJar('sodiumclient', 'client'));
            $datapackZip = (string) file_get_contents($fixtures->buildZip(['pack.mcmeta' => '{"pack":{"pack_format":48}}'], 'wf-dp-'));
            $resourceZip = (string) file_get_contents($fixtures->buildZip(['pack.mcmeta' => '{"pack":{"pack_format":34}}'], 'wf-rp-'));
            $shaderZip = (string) file_get_contents($fixtures->buildZip(['shaders/main.fsh' => '// glsl'], 'wf-sh-'));

            $clientPackPath = $fixtures->buildCurseForgeClientPack(
                minecraft: '1.21.1',
                modLoaders: [['id' => 'fabric-0.16.10', 'primary' => true]],
                manifestFiles: [
                    ['projectID' => 401, 'fileID' => 4001, 'required' => true],
                    ['projectID' => 402, 'fileID' => 4002, 'required' => true],
                    ['projectID' => 403, 'fileID' => 4003, 'required' => true],
                    ['projectID' => 404, 'fileID' => 4004, 'required' => true],
                    ['projectID' => 405, 'fileID' => 4005, 'required' => true],
                ],
                overrides: ['config/pack.toml' => "difficulty=hard\n"],
            );
            $clientPackBytes = (string) file_get_contents($clientPackPath);

            $edgeUrl = 'https://edge.forgecdn.net/files/40/00/pack.zip';
            $mediaUrl = 'https://mediafilez.forgecdn.net/files/40/00/pack.zip';
            $rangeMetrics = [];

            $this->registerRemoteFile('https://mediafilez.forgecdn.net/files/40/01/servercore.jar', $universalJar);
            $this->registerRemoteFile('https://mediafilez.forgecdn.net/files/40/02/sodiumclient.jar', $clientJar);
            $this->registerRemoteFile('https://mediafilez.forgecdn.net/files/40/04/pack-resources.zip', $resourceZip);
            $this->registerRemoteFile('https://mediafilez.forgecdn.net/files/40/05/shader.zip', $shaderZip);

            $this->fakeUpstreamHttp([
                'api.curseforge.com/v1/mods/400' => Http::response(['data' => ModpackArchiveFixtures::curseForgeApiMod(400, 'Mixed Pack', classId: 4471)], 200),
                'api.curseforge.com/v1/mods/400/files/4000' => Http::response([
                    'data' => ModpackArchiveFixtures::curseForgeApiFile(400, 4000, 'pack.zip', $clientPackBytes, $edgeUrl, gameVersions: ['1.21.1', 'Fabric']),
                ], 200),
                $edgeUrl => Http::response('', 302, ['Location' => $mediaUrl]),
                $mediaUrl => ModpackArchiveFixtures::rangeHttpResponder($clientPackBytes, $rangeMetrics, rejectFullGet: true),
                'api.curseforge.com/v1/mods/files' => Http::response([
                    'data' => [
                        ModpackArchiveFixtures::curseForgeApiFile(401, 4001, 'servercore.jar', $universalJar, 'https://mediafilez.forgecdn.net/files/40/01/servercore.jar'),
                        ModpackArchiveFixtures::curseForgeApiFile(402, 4002, 'sodiumclient.jar', $clientJar, 'https://mediafilez.forgecdn.net/files/40/02/sodiumclient.jar'),
                        ModpackArchiveFixtures::curseForgeApiFile(403, 4003, 'restricted-dp.zip', $datapackZip, downloadUrl: null),
                        ModpackArchiveFixtures::curseForgeApiFile(404, 4004, 'pack-resources.zip', $resourceZip, 'https://mediafilez.forgecdn.net/files/40/04/pack-resources.zip'),
                        ModpackArchiveFixtures::curseForgeApiFile(405, 4005, 'shader.zip', $shaderZip, 'https://mediafilez.forgecdn.net/files/40/05/shader.zip'),
                    ],
                ], 200),
                'api.curseforge.com/v1/mods' => Http::response([
                    'data' => [
                        ModpackArchiveFixtures::curseForgeApiMod(401, 'ServerCore', classId: 6),
                        ModpackArchiveFixtures::curseForgeApiMod(402, 'SodiumClient', classId: 6),
                        ModpackArchiveFixtures::curseForgeApiMod(403, 'Restricted DP', classId: 12, allowModDistribution: false, categoryIds: [5193]),
                        ModpackArchiveFixtures::curseForgeApiMod(404, 'Pack Resources', classId: 12),
                        ModpackArchiveFixtures::curseForgeApiMod(405, 'Shader Pack', classId: 6552),
                    ],
                ], 200),
            ]);

            $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(120));
            $server = $this->createServer($fabricEgg);
            $repo = $this->app->make(DaemonFileRepository::class);

            // Step 1: Describe via 302 redirect Range probe
            $descriptor = $this->app->make(ModpackCatalog::class)->describe($server, 'curseforge', '400', '4000');
            self::assertNotNull($descriptor);
            self::assertSame('0.16.10', $descriptor->loaderVersion);

            // Step 2: Deploy pauses in CurseForgeDependenciesPending for restricted .zip datapack
            $this->wings->setDisableRemoteDownload(true);
            $installer = $this->app->make(ModpackInstaller::class);
            $release = [
                'provider' => ModpackManifest::FORMAT_CURSEFORGE,
                'pack_id' => '400',
                'version_id' => '4000',
                'minecraft_version' => '1.21.1',
                'loader' => 'fabric',
                'loader_version' => '0.16.10',
                'server_pack' => false,
            ];

            $pending = null;
            try {
                $installer->deploy($server, $repo, $clientPackPath, $release, 'fabric', '1.21.1', false, null, null, static fn (): bool => true);
            } catch (CurseForgeDependenciesPending $e) {
                $pending = $e;
            }
            self::assertInstanceOf(CurseForgeDependenciesPending::class, $pending);

            // Step 3: Operator uploads verified .zip datapack -> resume deploy (surviving 1 transient cURL 7 failure!)
            $stagingPath = $pending->files[0]['staging_path'] ?? null;
            self::assertIsString($stagingPath, 'M3 (#16): Restricted .zip datapack must provide a non-null staging_path.');
            self::assertStringStartsWith('.mod-manager-curseforge/', $stagingPath);
            $this->seedServerFile($server, $stagingPath, $datapackZip);
            $this->wings->injectConnectionError('putStream', StrictWingsFake::CURL_ERR_7_REFUSED, 1);

            $installer->deploy($server, $repo, $clientPackPath, $release, 'fabric', '1.21.1', false, null, null, static fn (): bool => true);

            self::assertSame($universalJar, $this->serverFile($server, 'mods/servercore.jar'));
            self::assertSame($datapackZip, $this->serverFile($server, 'datapacks/restricted-dp.zip'));
            self::assertSame($resourceZip, $this->serverFile($server, 'resourcepacks/pack-resources.zip'));
            self::assertSame("difficulty=hard\n", $this->serverFile($server, 'config/pack.toml'));
            self::assertFalse($this->hasServerFile($server, 'mods/sodiumclient.jar'));
            self::assertFalse($this->hasServerFile($server, 'mods/shader.zip'));
            self::assertNotNull($this->app->make(ModpackInventory::class)->read($server)['inventory']);
            $this->wings->assertNoTemporaryModManagerFiles($server->uuid);
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_mrpack_range_probe_omitted_env_mirror_failover_large_server_override_and_bundled_deploy(): void
    {
        self::assertTrue(
            class_exists('Kazaminosuke\\ModManager\\Services\\ModpackBundleUploader'),
            'M4 (#19): ModpackBundleUploader must exist for bundled deployment under disable_remote_download: true.',
        );

        $fixtures = new ModpackArchiveFixtures();
        try {
            $modBytes = "PK\x03\x04mirror-failover-mod";
            $primaryUrl = 'https://cdn.modrinth.com/data/mf/broken-primary.jar';
            $mirrorUrl = 'https://github.com/example/repo/releases/download/v1/working-mirror.jar';
            $largeServerBytes = str_repeat('S', 2_500_000);
            $largeBaseBytes = str_repeat('B', 2_500_000);

            $mrpackPath = $fixtures->buildMrpack(
                dependencies: ['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'],
                files: [[
                    'path' => 'mods/failover.jar',
                    'hashes' => ['sha1' => sha1($modBytes), 'sha512' => hash('sha512', $modBytes)],
                    'downloads' => [$primaryUrl, $mirrorUrl],
                    'fileSize' => strlen($modBytes),
                ]],
                overrides: ['config/large.bin' => $largeBaseBytes],
                serverOverrides: ['config/large.bin' => $largeServerBytes],
                clientOverrides: ['config/client-only.txt' => 'client-only'],
                options: ['omit_env' => true, 'pad_bytes' => 9_000_000],
            );
            $mrpackBytes = (string) file_get_contents($mrpackPath);
            $mrpackUrl = 'https://cdn.modrinth.com/data/mfpack/v1/pack.mrpack';
            $rangeMetrics = [];

            // Primary returns 503; secondary mirror returns 200 with exact bytes
            $this->registerRemoteFile($mirrorUrl, $modBytes);
            $this->fakeUpstreamHttp([
                $primaryUrl => Http::response('Upstream 503', 503),
                $mrpackUrl => ModpackArchiveFixtures::rangeHttpResponder($mrpackBytes, $rangeMetrics, rejectFullGet: true),
                'api.modrinth.com/v2/project/mfpack' => Http::response([
                    'id' => 'mfpack', 'slug' => 'mf-pack', 'title' => 'MF Pack', 'description' => 'Mirror + Range + Large Override',
                ], 200),
                'api.modrinth.com/v2/version/v1' => Http::response([
                    'id' => 'v1', 'project_id' => 'mfpack', 'version_number' => '1.0.0',
                    'game_versions' => ['1.21.1'], 'loaders' => ['fabric'],
                    'files' => [['primary' => true, 'filename' => 'pack.mrpack', 'url' => $mrpackUrl, 'size' => strlen($mrpackBytes), 'hashes' => ['sha1' => sha1($mrpackBytes)]]],
                ], 200),
            ]);

            $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(121));
            $server = $this->createServer($fabricEgg);

            // Step 1: Range probe (< 2 MiB transferred for >9 MiB .mrpack)
            $desc = $this->app->make(ModpackCatalog::class)->describe($server, 'modrinth', 'mfpack', 'v1');
            self::assertNotNull($desc);
            self::assertSame('0.16.10', $desc->loaderVersion);
            self::assertLessThan(2 * 1024 * 1024, $rangeMetrics['bytes_sent'] ?? PHP_INT_MAX);

            // Step 2: Bundled deploy with disable_remote_download: true
            $this->wings->setDisableRemoteDownload(true);
            $this->wings->resetMetrics();

            $this->app->make(ModpackInstaller::class)->deploy(
                $server,
                $this->app->make(DaemonFileRepository::class),
                $mrpackPath,
                [
                    'provider' => ModpackManifest::FORMAT_MODRINTH,
                    'pack_id' => 'mfpack',
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

            self::assertSame($modBytes, $this->serverFile($server, 'mods/failover.jar'), 'Mirror fallback URL must supply mods/failover.jar.');
            self::assertSame(hash('sha256', $largeServerBytes), hash('sha256', $this->serverFile($server, 'config/large.bin')), 'server-overrides/ 2.5 MiB file must win over overrides/ without truncation.');
            self::assertFalse($this->hasServerFile($server, 'config/client-only.txt'));
            $this->wings->assertBytesWingsToPanelAtMost(0);
            $this->wings->assertNoTemporaryModManagerFiles($server->uuid);
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_ftb_mc26_java25_popular_browse_manifest_cf_fallback_planner_and_bundled_deploy(): void
    {
        $cfJarBytes = "PK\x03\x04ftb-cf-dep";
        $structBytes = 'BLUEPRINT-26';
        $this->registerRemoteFile('https://mediafilez.forgecdn.net/files/60/01/dep.jar', $cfJarBytes);
        $this->registerRemoteFile('https://files.feed-the-beast.com/26/keep.blueprint', $structBytes);
        $this->registerRemoteFile('https://files.feed-the-beast.com/26/empty.toml', '');

        $manifestJson = ModpackArchiveFixtures::buildFtbManifestJson(135, 100634, '2.0.0', [
            ['type' => 'game', 'name' => 'minecraft', 'version' => '26.1.2'],
            ['type' => 'modloader', 'name' => 'neoforge', 'version' => '26.1.2.129'],
            ['type' => 'runtime', 'name' => 'java', 'version' => '25.0.2+10-LTS'],
        ], [
            ['path' => './structurize/blueprints', 'name' => 'keep.blueprint', 'size' => strlen($structBytes), 'sha1' => sha1($structBytes), 'url' => 'https://files.feed-the-beast.com/26/keep.blueprint', 'clientonly' => false],
            ['path' => './config', 'name' => 'empty.toml', 'size' => 0, 'sha1' => sha1(''), 'url' => 'https://files.feed-the-beast.com/26/empty.toml', 'clientonly' => false],
            ['path' => './mods', 'name' => 'dep.jar', 'size' => strlen($cfJarBytes), 'sha1' => sha1($cfJarBytes), 'url' => '', 'curseforge' => ['project' => 601, 'file' => 6001], 'clientonly' => false],
            ['path' => './shaderpacks', 'name' => 'sh.zip', 'size' => 5, 'sha1' => sha1('12345'), 'url' => 'https://files.feed-the-beast.com/26/sh.zip', 'clientonly' => false],
        ]);

        $this->fakeUpstreamHttp([
            'api.feed-the-beast.com/v1/modpacks/public/modpack/popular/installs/*' => Http::response(['packs' => [135]], 200),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/135/100634' => Http::response($manifestJson, 200),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/135' => Http::response([
                'id' => 135, 'name' => 'FTB Evolution 26', 'synopsis' => 'MC 26.1.2 pack', 'type' => 'Release', 'installs' => 500000,
                'versions' => [[
                    'id' => 100634, 'name' => '2.0.0', 'type' => 'Release', 'released' => 1760000000,
                    'targets' => [
                        ['type' => 'game', 'name' => 'minecraft', 'version' => '26.1.2'],
                        ['type' => 'modloader', 'name' => 'neoforge', 'version' => '26.1.2.129'],
                        ['type' => 'runtime', 'name' => 'java', 'version' => '25.0.2+10-LTS'],
                    ],
                ]],
            ], 200),
            'api.curseforge.com/v1/mods/files' => Http::response([
                'data' => [ModpackArchiveFixtures::curseForgeApiFile(601, 6001, 'dep.jar', $cfJarBytes, 'https://mediafilez.forgecdn.net/files/60/01/dep.jar')],
            ], 200),
            'api.curseforge.com/v1/mods/601/files/6001' => Http::response([
                'data' => ModpackArchiveFixtures::curseForgeApiFile(601, 6001, 'dep.jar', $cfJarBytes, 'https://mediafilez.forgecdn.net/files/60/01/dep.jar'),
            ], 200),
            'api.curseforge.com/v1/mods' => Http::response([
                'data' => [ModpackArchiveFixtures::curseForgeApiMod(601, 'Dep Mod', classId: 6)],
            ], 200),
        ]);

        $neoforgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::neoforge(122));
        OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::forge(123));
        OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(124));

        // Step 1: Popular browse on empty query + inspect full release via describe()
        /** @var FtbModpackSource $ftb */
        $ftb = $this->app->make(FtbModpackSource::class);
        $popular = $ftb->search('');
        self::assertNotEmpty($popular, 'M3 (#17): Empty FTB query must browse /popular/installs/{limit}.');
        $described = $ftb->describe('135', '100634');
        self::assertNotNull($described);
        self::assertTrue($described->archiveAllowed, 'M3 (#17): Inspected FTB MC 26.1.2 release with structurize/ & 0-byte config must have archiveAllowed=true.');

        // Step 2: Planner selects official DB-backed NeoForge Egg + Java 25 yolk
        /** @var ModpackProvisioningPlanner $planner */
        $planner = $this->app->make(ModpackProvisioningPlanner::class);
        $plan = $planner->select($described, $this->app->make(InstalledEggCatalog::class)->all(), EggCompatibilityEngine::CONTEXT_CREATE);
        self::assertSame($neoforgeEgg->getKey(), $plan->selected?->id);
        self::assertSame(
            'ghcr.io/pelican-eggs/yolks:java_25',
            JavaRuntimeImage::fromEggImages($neoforgeEgg->docker_images, $described->minecraftVersion, $described->minimumJava, $described->loader),
        );

        // Step 3: Deploy FTB manifest cleanly
        $manifestFile = $this->trackTempPath($this->tempStoragePath.'/app/private/ftb-135.json');
        file_put_contents($manifestFile, $manifestJson);
        $parsed = FtbReleaseManifest::read($manifestJson, '135', '100634');

        $server = $this->createServer($neoforgeEgg, overrides: ['image' => 'ghcr.io/pelican-eggs/yolks:java_25']);
        $this->wings->setDisableRemoteDownload(true);

        $this->app->make(ModpackInstaller::class)->deploy(
            $server,
            $this->app->make(DaemonFileRepository::class),
            $manifestFile,
            [
                'provider' => ModpackManifest::FORMAT_FTB,
                'pack_id' => '135',
                'version_id' => '100634',
                'version_name' => '2.0.0',
                'minecraft_version' => '26.1.2',
                'loader' => 'neoforge',
                'loader_version' => '26.1.2.129',
                'manifest_fingerprint' => FtbReleaseManifest::fingerprint($parsed),
                'server_pack' => false,
            ],
            'neoforge',
            '26.1.2',
            false,
            null,
            null,
            static fn (): bool => true,
        );

        self::assertSame($structBytes, $this->serverFile($server, 'structurize/blueprints/keep.blueprint'));
        self::assertSame('', $this->serverFile($server, 'config/empty.toml'));
        self::assertSame($cfJarBytes, $this->serverFile($server, 'mods/dep.jar'));
        self::assertFalse($this->hasServerFile($server, 'shaderpacks/sh.zip'));
    }
}
