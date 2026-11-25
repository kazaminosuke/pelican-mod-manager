<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier2;

use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Exceptions\CurseForgeDependenciesPending;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackProvisioningPlanner;
use Kazaminosuke\ModManager\Services\ModpackRuntime;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\TechnicModpackSource;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityDisposition;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Kazaminosuke\ModManager\Support\Modpacks\FtbReleaseManifest;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackArchive;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDescriptor;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\LoaderRuntimeFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\ModpackArchiveFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use ZipArchive;

/**
 * Tier 2 — Boundary, Security & Egg Validation Cases for Modpack Features #13–#18 (M3).
 */
final class ModpackMetadataEggRulesAndSsrfBoundaryE2ETest extends E2ETestCase
{
    public function test_fabric_runtime_still_enforces_intermediary_on_1_21_1_and_loader_version_on_26_1_2(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(80));
        $repo = $this->app->make(DaemonFileRepository::class);
        $runtime = $this->app->make(ModpackRuntime::class);

        // 1. Fabric 1.21.1 MISSING intermediary MUST still throw ModpackException!
        $s121 = $this->createServer($fabricEgg);
        $obfFiles = LoaderRuntimeFixtures::fabricObfuscated('1.21.1', '0.16.10');
        unset($obfFiles['libraries/net/fabricmc/intermediary/1.21.1/intermediary-1.21.1.jar']);
        foreach ($obfFiles as $path => $bytes) {
            $this->seedServerFile($s121, $path, $bytes);
        }
        $caught121 = null;
        try {
            $runtime->assertInstalled($s121, $repo, 'fabric', '1.21.1', '0.16.10', ['SERVER_JARFILE' => 'server.jar']);
        } catch (ModpackException $e) {
            $caught121 = $e;
        }
        self::assertInstanceOf(ModpackException::class, $caught121, 'Fabric 1.21.1 missing intermediary JAR must fail verification.');

        // 2. Fabric 26.1.2 with WRONG fabric-loader build (0.18.0 vs expected 0.19.3) MUST fail!
        $s26Wrong = $this->createServer($fabricEgg);
        foreach (LoaderRuntimeFixtures::fabricUnobfuscated26('26.1.2', '0.18.0') as $path => $bytes) {
            $this->seedServerFile($s26Wrong, $path, $bytes);
        }
        $caughtWrong = null;
        try {
            $runtime->assertInstalled($s26Wrong, $repo, 'fabric', '26.1.2', '0.19.3', ['SERVER_JARFILE' => 'server.jar']);
        } catch (ModpackException $e) {
            $caughtWrong = $e;
        }
        self::assertInstanceOf(ModpackException::class, $caughtWrong, 'Fabric 26.1.2 with wrong loader build must fail verification.');

        // 3. Fabric with custom serverJar=custom-mc.jar where custom-mc.jar is MISSING MUST fail!
        $sCustomMissing = $this->createServer($fabricEgg);
        $customFiles = LoaderRuntimeFixtures::fabricCustomServerJar('1.21.1', '0.16.10', 'custom-mc.jar');
        unset($customFiles['custom-mc.jar']);
        foreach ($customFiles as $path => $bytes) {
            $this->seedServerFile($sCustomMissing, $path, $bytes);
        }
        $caughtCustom = null;
        try {
            $runtime->assertInstalled($sCustomMissing, $repo, 'fabric', '1.21.1', '0.16.10', ['SERVER_JARFILE' => 'server.jar']);
        } catch (ModpackException $e) {
            $caughtCustom = $e;
        }
        self::assertInstanceOf(ModpackException::class, $caughtCustom, 'Fabric missing custom serverJar target must fail verification.');
    }

    public function test_range_zip_reader_blocks_non_allowlisted_and_private_ip_redirects_and_mrpack_omitted_env_and_traversal(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(82));
        $server = $this->createServer($fabricEgg);

        $evilHit = false;
        $privateHit = false;
        $imdsHit = false;
        $this->fakeUpstreamHttp([
            'api.curseforge.com/v1/mods/9001' => Http::response(['data' => ['id' => 9001, 'slug' => 'evil-redir', 'name' => 'Evil Redir', 'summary' => 'SSRF test', 'downloadCount' => 1]], 200),
            'api.curseforge.com/v1/mods/9001/files/1001' => Http::response([
                'data' => ModpackArchiveFixtures::curseForgeApiFile(9001, 1001, 'evil-redir.zip', 'zip', 'https://edge.forgecdn.net/files/1/1/evil-redir.zip', gameVersions: ['1.21.1', 'Fabric']),
            ], 200),
            'api.curseforge.com/v1/mods/9001/files?*' => Http::response(['data' => []], 200),
            'api.curseforge.com/v1/mods/9002' => Http::response(['data' => ['id' => 9002, 'slug' => 'private-redir', 'name' => 'Private Redir', 'summary' => 'SSRF test', 'downloadCount' => 1]], 200),
            'api.curseforge.com/v1/mods/9002/files/1002' => Http::response([
                'data' => ModpackArchiveFixtures::curseForgeApiFile(9002, 1002, 'private-redir.zip', 'zip', 'https://edge.forgecdn.net/files/1/1/private-redir.zip', gameVersions: ['1.21.1', 'Fabric']),
            ], 200),
            'api.curseforge.com/v1/mods/9002/files?*' => Http::response(['data' => []], 200),
            'api.modrinth.com/v2/project/evilmr' => Http::response([
                'id' => 'evilmr',
                'slug' => 'evil-mr',
                'title' => 'Evil MR',
                'description' => 'SSRF test',
                'downloads' => 1,
                'updated' => '2026-10-01T00:00:00Z',
            ], 200),
            'api.modrinth.com/v2/version/v1' => Http::response([
                'id' => 'v1',
                'project_id' => 'evilmr',
                'version_number' => '1.0.0',
                'game_versions' => ['1.21.1'],
                'loaders' => ['fabric'],
                'files' => [[
                    'primary' => true,
                    'filename' => 'evil.mrpack',
                    'url' => 'https://cdn.modrinth.com/data/evil/v1/evil.mrpack',
                    'size' => 10_000_000,
                    'hashes' => ['sha1' => sha1('evil')],
                ]],
            ], 200),
            'edge.forgecdn.net/files/1/1/evil-redir.zip' => Http::response('', 302, ['Location' => 'https://evil.example/payload.zip']),
            'edge.forgecdn.net/files/1/1/private-redir.zip' => Http::response('', 302, ['Location' => 'https://127.0.0.1/secret.zip']),
            'cdn.modrinth.com/data/evil/v1/evil.mrpack' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data/']),
            'evil.example/*' => static function () use (&$evilHit) {
                $evilHit = true;

                return Http::response('SECRET', 200);
            },
            '127.0.0.1/*' => static function () use (&$privateHit) {
                $privateHit = true;

                return Http::response('SECRET', 200);
            },
            '169.254.169.254/*' => static function () use (&$imdsHit) {
                $imdsHit = true;

                return Http::response('AWS_METADATA', 200);
            },
        ]);

        /** @var ModpackCatalog $catalog */
        $catalog = $this->app->make(ModpackCatalog::class);
        $descEvil = $catalog->describe($server, 'curseforge', '9001', '1001');
        $descPriv = $catalog->describe($server, 'curseforge', '9002', '1002');
        $descImds = $catalog->describe($server, 'modrinth', 'evilmr', 'v1');

        self::assertFalse($evilHit, 'Non-allowlisted redirect target must NEVER be requested.');
        self::assertFalse($privateHit, 'Private IP redirect target must NEVER be requested.');
        self::assertFalse($imdsHit, 'IMDS 169.254.169.254 redirect target must NEVER be requested.');
        self::assertNull($descEvil?->loaderVersion);
        self::assertNull($descPriv?->loaderVersion);
        self::assertNull($descImds?->loaderVersion);

        // .mrpack edge cases: omitted env = required, server-overrides wins over overrides, client-overrides ignored, zip-slip rejected
        $fixtures = new ModpackArchiveFixtures();
        try {
            $abcJar = "PK\x03\x04abc";
            $abcUrl = 'https://cdn.modrinth.com/abc.jar';
            $this->registerRemoteFile($abcUrl, $abcJar);

            $mrpack = $fixtures->buildMrpack(
                dependencies: ['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'],
                files: [[
                    'path' => 'mods/required-by-omission.jar',
                    'hashes' => ['sha1' => sha1($abcJar)],
                    'downloads' => [$abcUrl],
                    'fileSize' => strlen($abcJar),
                ]],
                overrides: ['config/shared.toml' => 'from-base'],
                serverOverrides: ['config/shared.toml' => 'from-server-overrides'],
                clientOverrides: ['config/client-only.toml' => 'never-on-server'],
                options: ['omit_env' => true],
            );

            $manifest = ModpackArchive::read($mrpack, strict: true);
            self::assertCount(1, $manifest->remoteFiles);
            self::assertTrue($manifest->remoteFiles[0]->required, 'Omitted env in .mrpack must default to server-required.');

            // Deploy opaquely so both buffered and streaming ModpackOverrideFile implementations work identically
            $this->app->make(ModpackInstaller::class)->deploy(
                $server,
                $this->app->make(DaemonFileRepository::class),
                $mrpack,
                [
                    'provider' => ModpackManifest::FORMAT_MODRINTH,
                    'pack_id' => 'omitted-env',
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
            self::assertSame('from-server-overrides', $this->serverFile($server, 'config/shared.toml'));
            self::assertFalse($this->hasServerFile($server, 'config/client-only.toml'));
            self::assertSame($abcJar, $this->serverFile($server, 'mods/required-by-omission.jar'));

            // Zip-slip inside overrides/ MUST throw ModpackException
            $evilMrpack = $fixtures->buildZip([
                'modrinth.index.json' => json_encode([
                    'formatVersion' => 1,
                    'game' => 'minecraft',
                    'dependencies' => ['minecraft' => '1.21.1'],
                    'files' => [],
                ], JSON_THROW_ON_ERROR),
                'overrides/../../server.jar' => 'evil',
            ], 'evil-mrpack-', '.mrpack');
            $caughtSlip = null;
            try {
                ModpackArchive::read($evilMrpack, strict: true);
            } catch (ModpackException $e) {
                $caughtSlip = $e;
            }
            self::assertInstanceOf(ModpackException::class, $caughtSlip);
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_curseforge_restricted_mod_no_edge_bypass_zip_manual_upload_chunk_retry_pagination_and_primary_loader(): void
    {
        $fixtures = new ModpackArchiveFixtures();
        try {
            // 1. manifest.json where modLoaders[0].primary=false and modLoaders[1].primary=true -> picks modLoaders[1]!
            $primarySecondZip = $fixtures->buildCurseForgeClientPack(
                minecraft: '1.21.1',
                modLoaders: [
                    ['id' => 'forge-47.0.0', 'primary' => false],
                    ['id' => 'neoforge-21.1.266', 'primary' => true],
                ],
                manifestFiles: [],
            );
            $primaryManifest = ModpackArchive::read($primarySecondZip);
            self::assertSame('neoforge', $primaryManifest->loader);
            self::assertSame('21.1.266', $primaryManifest->loaderVersion);

            // 2. Restricted .zip datapack (allowModDistribution=false, downloadUrl=null) + 2nd allowed mod so POST /v1/mods/files
            // bulk chunk genuinely executes and retries transient 503; NEVER fabricates edge.forgecdn.net; preserves non-/mc-mods/ page URL;
            // rejects same-length wrong-hash upload with reason=hash_mismatch; accepts verified .zip upload!
            $dpBytes = (string) file_get_contents($fixtures->buildZip(['pack.mcmeta' => '{"pack":{"pack_format":48}}'], 'restricted-dp-'));
            $helperJarBytes = (string) file_get_contents($fixtures->buildFabricModJar('helperlib', '*'));
            $helperUrl = 'https://mediafilez.forgecdn.net/files/30/02/helper.jar';
            $this->registerRemoteFile($helperUrl, $helperJarBytes);

            $clientZip = $fixtures->buildCurseForgeClientPack(
                minecraft: '1.21.1',
                modLoaders: [['id' => 'fabric-0.16.10', 'primary' => true]],
                manifestFiles: [
                    ['projectID' => 301, 'fileID' => 3001, 'required' => true],
                    ['projectID' => 302, 'fileID' => 3002, 'required' => true],
                ],
            );

            $edgeBypassAttempted = false;
            $bulkAttempts = 0;
            $this->fakeUpstreamHttp([
                'edge.forgecdn.net/*' => static function () use (&$edgeBypassAttempted) {
                    $edgeBypassAttempted = true;

                    return Http::response('Forbidden', 403);
                },
                'api.curseforge.com/v1/mods/files' => static function () use (&$bulkAttempts, $dpBytes, $helperJarBytes, $helperUrl) {
                    $bulkAttempts++;
                    if ($bulkAttempts === 1) {
                        return Http::response(['error' => 'transient 503'], 503);
                    }

                    return Http::response([
                        'data' => [
                            ModpackArchiveFixtures::curseForgeApiFile(301, 3001, 'restricted-dp.zip', $dpBytes, downloadUrl: null),
                            ModpackArchiveFixtures::curseForgeApiFile(302, 3002, 'helper.jar', $helperJarBytes, downloadUrl: $helperUrl),
                        ],
                    ], 200);
                },
                'api.curseforge.com/v1/mods/301/files/3001' => Http::response([
                    'data' => ModpackArchiveFixtures::curseForgeApiFile(301, 3001, 'restricted-dp.zip', $dpBytes, downloadUrl: null),
                ], 200),
                'api.curseforge.com/v1/mods/302/files/3002' => Http::response([
                    'data' => ModpackArchiveFixtures::curseForgeApiFile(302, 3002, 'helper.jar', $helperJarBytes, downloadUrl: $helperUrl),
                ], 200),
                'api.curseforge.com/v1/mods' => Http::response([
                    'data' => [
                        ModpackArchiveFixtures::curseForgeApiMod(301, 'Restricted Datapack', classId: 12, allowModDistribution: false, categoryIds: [5193]),
                        ModpackArchiveFixtures::curseForgeApiMod(302, 'Helper Lib', classId: 6, allowModDistribution: true),
                    ],
                ], 200),
                'api.curseforge.com/v1/mods/888/files*' => static function (Request $req) {
                    parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
                    $index = (int) ($q['index'] ?? 0);
                    $pageSize = 50;
                    $batch = [];
                    if ($index === 0) {
                        for ($i = 1; $i <= 50; $i++) {
                            $batch[] = ModpackArchiveFixtures::curseForgeApiFile(888, 10000 + $i, "pack-{$i}.zip", 'zip');
                        }
                    } elseif ($index === 50) {
                        for ($i = 51; $i <= 65; $i++) {
                            $batch[] = ModpackArchiveFixtures::curseForgeApiFile(888, 10000 + $i, "pack-{$i}.zip", 'zip');
                        }
                    }

                    return Http::response([
                        'data' => $batch,
                        'pagination' => ['index' => $index, 'pageSize' => $pageSize, 'resultCount' => count($batch), 'totalCount' => 65],
                    ], 200);
                },
            ]);

            $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(81));
            $server = $this->createServer($fabricEgg);
            /** @var DaemonFileRepository $repo */
            $repo = $this->app->make(DaemonFileRepository::class);
            $installer = $this->app->make(ModpackInstaller::class);
            $release = [
                'provider' => ModpackManifest::FORMAT_CURSEFORGE,
                'pack_id' => 'restricted-pack',
                'version_id' => 'v1',
                'minecraft_version' => '1.21.1',
                'loader' => 'fabric',
                'loader_version' => '0.16.10',
                'server_pack' => false,
            ];

            // Without manual upload -> pauses in CurseForgeDependenciesPending, retried bulk 503, zero edge.forgecdn.net fabrication!
            $pending = null;
            try {
                $installer->deploy($server, $repo, $clientZip, $release, 'fabric', '1.21.1', false, null, null, static fn (): bool => true);
            } catch (CurseForgeDependenciesPending $e) {
                $pending = $e;
            }
            self::assertInstanceOf(CurseForgeDependenciesPending::class, $pending);
            self::assertFalse($edgeBypassAttempted, 'Must NEVER reconstruct edge.forgecdn.net URLs for allowModDistribution=false.');
            self::assertGreaterThanOrEqual(2, $bulkAttempts, 'M3 (#16): Transient 503 on CurseForge POST /v1/mods/files bulk chunk must be retried.');

            $pendingEntry = $pending->files[0] ?? [];
            $stagingPath = $pendingEntry['staging_path'] ?? null;
            self::assertIsString($stagingPath);
            self::assertStringStartsWith('.mod-manager-curseforge/', $stagingPath);
            self::assertSame(
                'https://www.curseforge.com/minecraft/texture-packs/restricted-datapack/files/3001',
                $pendingEntry['page'] ?? null,
                'M3 (#16): Non-/mc-mods/ CurseForge page URL must be preserved.',
            );

            // Upload WRONG bytes of the EXACT same length -> passes size check and fails specifically with reason=hash_mismatch!
            $wrongHashBytes = $dpBytes;
            $lastIdx = strlen($wrongHashBytes) - 1;
            $wrongHashBytes[$lastIdx] = $wrongHashBytes[$lastIdx] === 'A' ? 'B' : 'A';
            $this->seedServerFile($server, $stagingPath, $wrongHashBytes);
            $stillPending = null;
            try {
                $installer->deploy($server, $repo, $clientZip, $release, 'fabric', '1.21.1', false, null, null, static fn (): bool => true);
            } catch (CurseForgeDependenciesPending $e) {
                $stillPending = $e;
            }
            self::assertInstanceOf(CurseForgeDependenciesPending::class, $stillPending, 'Manual upload with wrong hash must be rejected.');
            self::assertSame(
                'hash_mismatch',
                $stillPending->files[0]['reason'] ?? null,
                'Manual upload with same length but wrong hash must fail with reason=hash_mismatch.',
            );

            // Upload VERIFIED .zip datapack -> M3 (#16) must allow .zip manual upload and install to datapacks/ + helper to mods/!
            $this->seedServerFile($server, $stagingPath, $dpBytes);
            try {
                $installer->deploy($server, $repo, $clientZip, $release, 'fabric', '1.21.1', false, null, null, static fn (): bool => true);
            } catch (CurseForgeDependenciesPending $e) {
                self::fail('M3 (#16): Verified restricted .zip datapack manual upload must succeed instead of staying pending: '.json_encode($e->files));
            }
            self::assertTrue($this->hasServerFile($server, 'datapacks/restricted-dp.zip'));
            self::assertTrue($this->hasServerFile($server, 'mods/helper.jar'));

            // 3. CurseForge version list pagination (>30 versions)
            $versions = $this->app->make(CurseForgeSource::class)->listModpackVersions('888');
            self::assertGreaterThan(30, count($versions), 'M3 (#16): listModpackVersions() must paginate past first 30/50 results.');
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_ftb_skips_clientonly_and_shaderpacks_rejects_unsafe_paths_and_server_pack_rejects_4_level_wrappers_and_scripts(): void
    {
        // 1. FTB unsafe path rejected; clientonly & shaderpacks skipped cleanly
        $okBytes = "PK\x03\x04mod";
        $safeJson = ModpackArchiveFixtures::buildFtbManifestJson(140, 1, '1.0', [
            ['type' => 'game', 'name' => 'minecraft', 'version' => '1.21.1'],
            ['type' => 'modloader', 'name' => 'neoforge', 'version' => '21.1.266'],
        ], [
            ['path' => './mods', 'name' => 'good.jar', 'size' => strlen($okBytes), 'sha1' => sha1($okBytes), 'url' => 'https://files.feed-the-beast.com/good.jar', 'clientonly' => false],
            ['path' => './mods', 'name' => 'client-only.jar', 'size' => 10, 'sha1' => sha1('1234567890'), 'url' => 'https://files.feed-the-beast.com/c.jar', 'clientonly' => true],
            ['path' => './shaderpacks', 'name' => 'bsl.zip', 'size' => 10, 'sha1' => sha1('1234567890'), 'url' => 'https://files.feed-the-beast.com/s.zip', 'clientonly' => false],
        ]);
        $manifest = FtbReleaseManifest::read($safeJson, '140', '1');
        self::assertCount(1, $manifest->remoteFiles);
        self::assertSame('mods/good.jar', $manifest->remoteFiles[0]->path);

        $unsafeJson = ModpackArchiveFixtures::buildFtbManifestJson(140, 1, '1.0', [
            ['type' => 'game', 'name' => 'minecraft', 'version' => '1.21.1'],
            ['type' => 'modloader', 'name' => 'neoforge', 'version' => '21.1.266'],
        ], [
            ['path' => '../../etc', 'name' => 'evil.sh', 'size' => 4, 'sha1' => sha1('evil'), 'url' => 'https://files.feed-the-beast.com/evil.sh', 'clientonly' => false],
        ]);
        $caughtUnsafe = null;
        try {
            FtbReleaseManifest::read($unsafeJson, '140', '1');
        } catch (ModpackException $e) {
            $caughtUnsafe = $e;
        }
        self::assertInstanceOf(ModpackException::class, $caughtUnsafe);

        // 2. Server pack: 4-level wrapper rejected; pack scripts (startserver.sh, run.sh) NEVER placed on Wings
        $fixtures = new ModpackArchiveFixtures();
        try {
            $wrapped4 = $fixtures->buildCurseForgeServerPack(
                managedFiles: ['mods/deep.jar' => "PK\x03\x04deep"],
                wrapperDepth: 4,
            );
            $z4 = new ZipArchive();
            self::assertTrue($z4->open($wrapped4, ZipArchive::RDONLY));
            $caught4 = null;
            try {
                ModpackArchive::serverFiles($z4);
            } catch (ModpackException $e) {
                $caught4 = $e;
            } finally {
                $z4->close();
            }
            self::assertInstanceOf(ModpackException::class, $caught4, '4-level nested server pack must be rejected.');

            $withScripts = $fixtures->buildCurseForgeServerPack(
                managedFiles: ['mods/clean.jar' => "PK\x03\x04clean"],
                wrapperDepth: 1,
                rootAuxiliaryFiles: ['startserver.sh' => "#!/bin/bash\nrm -rf /\n", 'run.sh' => "#!/bin/sh\necho bad\n"],
            );
            $zs = new ZipArchive();
            self::assertTrue($zs->open($withScripts, ZipArchive::RDONLY));
            try {
                $entries = ModpackArchive::serverFiles($zs);
                $paths = array_column($entries, 'path');
                self::assertContains('mods/clean.jar', $paths);
                self::assertNotContains('startserver.sh', $paths, 'Pack shell scripts must NEVER be included in serverFiles.');
                self::assertNotContains('run.sh', $paths);
            } finally {
                $zs->close();
            }

            // 2b. M3 (#18): CurseForge-hosted FTB installer pack detection (NEVER installs install.sh / serverinstall_*)
            $seededEggs = [];
            foreach (OfficialPelicanEggFixtures::allStandard() as $def) {
                $seeded = OfficialPelicanEggFixtures::seedIntoDatabase($def);
                $seededEggs[(int) $seeded->getKey()] = $seeded;
            }
            $nfServer = $this->createServer($seededEggs[2]);
            $ftbReroutedBytes = "PK\x03\x04ftb-rerouted";
            $ftbReroutedUrl = 'https://files.feed-the-beast.com/blob/ftb-rerouted.jar';
            $this->registerRemoteFile($ftbReroutedUrl, $ftbReroutedBytes);
            $ftbReroutedManifest = ModpackArchiveFixtures::buildFtbManifestJson(135, 100634, '1.0.0', [
                ['type' => 'game', 'name' => 'minecraft', 'version' => '1.21.1'],
                ['type' => 'modloader', 'name' => 'neoforge', 'version' => '21.1.266'],
                ['type' => 'runtime', 'name' => 'java', 'version' => '21.0.4+7-LTS'],
            ], [[
                'path' => './mods',
                'name' => 'ftb-rerouted.jar',
                'size' => strlen($ftbReroutedBytes),
                'sha1' => sha1($ftbReroutedBytes),
                'hashes' => ['sha256' => hash('sha256', $ftbReroutedBytes)],
                'url' => $ftbReroutedUrl,
                'clientonly' => false,
                'optional' => false,
            ]]);

            $pack1122Path = $fixtures->buildCurseForgeServerPack(
                managedFiles: ['mods/legacy.jar' => "PK\x03\x04legacy"],
                wrapperDepth: 1,
                variablesTxt: "MINECRAFT_VERSION=1.12.2\nMODLOADER=forge\nMODLOADER_VERSION=14.23.5.2860\nRECOMMENDED_JAVA_VERSION=21\n",
            );
            $pack1122Bytes = (string) file_get_contents($pack1122Path);
            $pack1122Url = 'https://mediafilez.forgecdn.net/files/91/22/pack-1122.zip';
            $pack1122Metrics = [];

            $this->fakeUpstreamHttp([
                'api.feed-the-beast.com/v1/modpacks/public/modpack/135/100634' => Http::response($ftbReroutedManifest, 200),
                'api.feed-the-beast.com/v1/modpacks/public/modpack/135' => Http::response([
                    'id' => 135,
                    'name' => 'FTB Evolution',
                    'synopsis' => 'FTB pack',
                    'type' => 'Release',
                    'versions' => [['id' => 100634, 'name' => '1.0.0', 'type' => 'Release']],
                ], 200),
                'api.curseforge.com/v1/mods/9122' => Http::response(['data' => ['id' => 9122, 'slug' => 'legacy-1122', 'name' => 'Legacy 1.12.2', 'summary' => 'Java clamp test', 'downloadCount' => 10]], 200),
                'api.curseforge.com/v1/mods/9122/files/91221' => Http::response([
                    'data' => ModpackArchiveFixtures::curseForgeApiFile(9122, 91221, 'pack-1122.zip', $pack1122Bytes, $pack1122Url, isServerPack: true, gameVersions: ['1.12.2', 'Forge']),
                ], 200),
                $pack1122Url => ModpackArchiveFixtures::rangeHttpResponder($pack1122Bytes, $pack1122Metrics),
                'api.technicpack.net/modpack/tekkit-legends*' => Http::response([
                    'name' => 'tekkit-legends',
                    'displayName' => 'Tekkit Legends',
                    'version' => '1.1.1',
                    'minecraft' => '1.4.7',
                    'solder' => 'https://solder.technicpack.net/api/',
                    'serverPackUrl' => 'https://servers.technicpack.net/Technic/servers/tekkit-legends/TekkitLegends_Server_v1.1.1.zip',
                ], 200),
                'solder.technicpack.net/api/modpack/tekkit-legends' => Http::response([
                    'recommended' => '1.1.1',
                    'builds' => ['1.1.1'],
                ], 200),
                'solder.technicpack.net/api/modpack/tekkit-legends/1.1.1' => Http::response([
                    'minecraft' => '1.7.10',
                    'java' => '1.8',
                ], 200),
            ]);

            $ftbInstallerZip = $fixtures->buildCurseForgeFtbInstallerPack(135, 100634, wrapperDepth: 1);
            $caughtFtbStub = null;
            try {
                $this->app->make(ModpackInstaller::class)->deploy(
                    $nfServer,
                    $this->app->make(DaemonFileRepository::class),
                    $ftbInstallerZip,
                    [
                        'provider' => ModpackManifest::FORMAT_CURSEFORGE,
                        'pack_id' => '999',
                        'version_id' => '9991',
                        'minecraft_version' => '1.21.1',
                        'loader' => 'neoforge',
                        'loader_version' => '21.1.266',
                        'server_pack' => true,
                    ],
                    'neoforge',
                    '1.21.1',
                    false,
                    null,
                    null,
                    static fn (): bool => true,
                );
            } catch (ModpackException $e) {
                $caughtFtbStub = $e;
            }
            self::assertFalse($this->hasServerFile($nfServer, 'install.sh'));
            self::assertFalse($this->hasServerFile($nfServer, 'serverinstall_135_100634'));
            if ($caughtFtbStub === null) {
                self::assertTrue($this->hasServerFile($nfServer, 'mods/ftb-rerouted.jar'), 'M3 (#18): Rerouted CF-hosted FTB installer pack must deploy real FTB files.');
            } else {
                self::assertStringContainsString(
                    'ftb',
                    strtolower($caughtFtbStub->getMessage()),
                    'M3 (#18): CurseForge-hosted FTB installer stub must either reroute to FTB or report a dedicated FTB detection error (got: '.$caughtFtbStub->getMessage().').',
                );
            }

            // 3. Official DB-backed Egg rules: MC_VERSION > 9 chars, Forge 26.1.2 gap, Quilt pinned build
            /** @var ModpackProvisioningPlanner $planner */
            $planner = $this->app->make(ModpackProvisioningPlanner::class);
            $eggs = $this->app->make(InstalledEggCatalog::class)->all();

            $snapshotPack = new ModpackDescriptor(
                provider: ModpackProvider::Modrinth->value,
                id: 'snap',
                title: 'Snapshot Forge Pack',
                versionId: 'v1',
                versionName: '1.0',
                minecraftVersion: '1.21.1-pre1',
                loader: 'forge',
                loaderVersion: '52.0.1',
                iconUrl: null,
                provisioningProfileId: null,
                variables: [],
                archiveAllowed: true,
                unsupportedReason: null,
            );
            $snapPlan = $planner->select($snapshotPack, $eggs, EggCompatibilityEngine::CONTEXT_CREATE);
            self::assertSame(
                [],
                OfficialPelicanEggFixtures::validateVariables(OfficialPelicanEggFixtures::forge(), $snapPlan->variables),
                'M3 (#18): Planner must not emit MC_VERSION > 9 chars violating official Forge Egg rule max:9.',
            );

            $forge26Pack = new ModpackDescriptor(
                provider: ModpackProvider::Modrinth->value,
                id: 'forge26',
                title: 'Forge 26.1.2 Pack',
                versionId: 'v1',
                versionName: '1.0',
                minecraftVersion: '26.1.2',
                loader: 'forge',
                loaderVersion: '61.0.1',
                iconUrl: null,
                provisioningProfileId: null,
                variables: [],
                archiveAllowed: true,
                unsupportedReason: null,
            );
            $forge26Plan = $planner->select($forge26Pack, $eggs, EggCompatibilityEngine::CONTEXT_CREATE);
            self::assertTrue(
                $forge26Plan->disposition === CompatibilityDisposition::Unsupported || $forge26Plan->compatibilityWarning !== null,
                'M3 (#18): Forge MC 26.x official Egg unix_args.txt gap must produce Unsupported or an explicit compatibility warning.',
            );

            $quiltPack = new ModpackDescriptor(
                provider: ModpackProvider::Modrinth->value,
                id: 'quilt-pinned',
                title: 'Quilt Pinned Pack',
                versionId: 'v1',
                versionName: '1.0',
                minecraftVersion: '1.20.2',
                loader: 'quilt',
                loaderVersion: '0.26.4',
                iconUrl: null,
                provisioningProfileId: null,
                variables: [],
                archiveAllowed: true,
                unsupportedReason: null,
            );
            $quiltPlan = $planner->select($quiltPack, $eggs, EggCompatibilityEngine::CONTEXT_CREATE);
            self::assertSame(CompatibilityDisposition::Unsupported, $quiltPlan->disposition, 'M3 (#18): Official Quilt Egg lacking QUILT_VERSION cannot pin build 0.26.4.');
            self::assertNull($quiltPlan->selected);
            self::assertSame(2, DB::table('egg_variables')->where('egg_id', 4)->count(), 'Quilt Egg variables in DB must NEVER be mutated.');

            // Java clamping: hard minimum 21 on 1.12.2 returns null; RECOMMENDED_JAVA_VERSION=21 parsed via ModpackCatalog::describe() clamps to <=8 / java_8
            self::assertSame(8, JavaRuntimeImage::maximumJava('1.12.2', 'forge'));
            self::assertNull(JavaRuntimeImage::fromEggImages(OfficialPelicanEggFixtures::YOLKS_ALL, '1.12.2', 21, 'forge'));
            $desc1122 = $this->app->make(ModpackCatalog::class)->describe($nfServer, 'curseforge', '9122', '91221');
            self::assertNotNull($desc1122);
            self::assertTrue(
                $desc1122->minimumJava === null || $desc1122->minimumJava <= 8,
                'M3 (#18): RECOMMENDED_JAVA_VERSION=21 on 1.12.2 Forge must be clamped to <= 8.',
            );
            self::assertSame(
                'ghcr.io/pelican-eggs/yolks:java_8',
                JavaRuntimeImage::fromEggImages(OfficialPelicanEggFixtures::YOLKS_ALL, $desc1122->minecraftVersion, $desc1122->minimumJava, $desc1122->loader),
            );

            // Technic Legends: Solder MC 1.7.10 wins over stale Platform 1.4.7
            $technicDesc = $this->app->make(TechnicModpackSource::class)->describe('tekkit-legends', '1.1.1');
            self::assertNotNull($technicDesc);
            self::assertSame('1.7.10', $technicDesc->minecraftVersion);
        } finally {
            $fixtures->cleanup();
        }
    }
}
