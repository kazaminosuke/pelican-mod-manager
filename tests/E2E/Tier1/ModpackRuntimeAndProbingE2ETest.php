<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier1;

use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackRuntime;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackArchive;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\LoaderRuntimeFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\ModpackArchiveFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;

/**
 * Tier 1 — Isolated Feature Coverage for Modpack Features #13, #14, #15 (M3).
 */
final class ModpackRuntimeAndProbingE2ETest extends E2ETestCase
{
    public function test_runtime_accepts_unobfuscated_fabric_26_1_2_without_intermediary_and_reads_dynamic_server_jar(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(40));
        $repo = $this->app->make(DaemonFileRepository::class);
        $runtime = $this->app->make(ModpackRuntime::class);

        // 1. M3 (#13): Unobfuscated MC 26.1.2 Fabric server (NO intermediary JAR exists!)
        $server26 = $this->createServer($fabricEgg, overrides: [
            'startup' => 'java -Xms128M -XX:MaxRAMPercentage=95.0 -jar {{SERVER_JARFILE}}',
        ], variableValues: ['MC_VERSION' => '26.1.2', 'LOADER_VERSION' => '0.19.3', 'SERVER_JARFILE' => 'server.jar']);

        foreach (LoaderRuntimeFixtures::fabricUnobfuscated26('26.1.2', '0.19.3') as $path => $bytes) {
            $this->seedServerFile($server26, $path, $bytes);
        }

        try {
            $runtime->assertInstalled($server26, $repo, 'fabric', '26.1.2', '0.19.3', ['SERVER_JARFILE' => 'server.jar']);
        } catch (ModpackException $e) {
            self::fail('M3 (#13): ModpackRuntime::assertInstalled() must accept unobfuscated MC 26.1.2 Fabric without intermediary JAR: '.$e->getMessage());
        }
        self::assertFalse($this->hasServerFile($server26, 'libraries/net/fabricmc/intermediary/26.1.2/intermediary-26.1.2.jar'));

        // 2. M3 (#13): Dynamic serverJar= from fabric-server-launcher.properties (NO minecraft-server.jar!)
        $serverCustom = $this->createServer($fabricEgg, overrides: [
            'startup' => 'java -Xms128M -XX:MaxRAMPercentage=95.0 -jar {{SERVER_JARFILE}}',
        ], variableValues: ['MC_VERSION' => '1.21.1', 'LOADER_VERSION' => '0.16.10', 'SERVER_JARFILE' => 'server.jar']);

        foreach (LoaderRuntimeFixtures::fabricCustomServerJar('1.21.1', '0.16.10', 'custom-mc.jar') as $path => $bytes) {
            $this->seedServerFile($serverCustom, $path, $bytes);
        }

        try {
            $runtime->assertInstalled($serverCustom, $repo, 'fabric', '1.21.1', '0.16.10', ['SERVER_JARFILE' => 'server.jar']);
        } catch (ModpackException $e) {
            self::fail('M3 (#13): ModpackRuntime::assertInstalled() must read dynamic serverJar= from fabric-server-launcher.properties: '.$e->getMessage());
        }
        self::assertTrue($this->hasServerFile($serverCustom, 'custom-mc.jar'));
        self::assertFalse($this->hasServerFile($serverCustom, 'minecraft-server.jar'));
    }

    public function test_runtime_baseline_parity_across_fabric_1_21_1_forge_neoforge_and_quilt(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(41));
        $forgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::forge(42));
        $neoforgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::neoforge(43));
        $quiltEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::quilt(44));
        $repo = $this->app->make(DaemonFileRepository::class);
        $runtime = $this->app->make(ModpackRuntime::class);

        $cases = [
            [$fabricEgg, 'fabric', '1.21.1', '0.16.10', LoaderRuntimeFixtures::fabricObfuscated('1.21.1', '0.16.10')],
            [$fabricEgg, 'fabric', '1.16.5', '0.12.5', LoaderRuntimeFixtures::fabricShadedLegacy('0.12.5')],
            [$forgeEgg, 'forge', '1.20.1', '47.4.20', LoaderRuntimeFixtures::forgeModern('1.20.1', '47.4.20')],
            [$forgeEgg, 'forge', '1.16.5', '36.2.42', LoaderRuntimeFixtures::forge1165('36.2.42')],
            [$forgeEgg, 'forge', '1.12.2', '14.23.5.2860', LoaderRuntimeFixtures::forge1122('14.23.5.2860')],
            [$neoforgeEgg, 'neoforge', '1.20.1', '47.1.95', LoaderRuntimeFixtures::neoforge1201('47.1.95')],
            [$neoforgeEgg, 'neoforge', '26.1.2', '26.1.2.129', LoaderRuntimeFixtures::neoforgeModern('26.1.2', '26.1.2.129')],
            [$quiltEgg, 'quilt', '1.20.2', '0.26.4', LoaderRuntimeFixtures::quilt('0.26.4')],
        ];

        $verified = 0;
        foreach ($cases as [$egg, $loader, $mc, $build, $files]) {
            $server = $this->createServer($egg);
            foreach ($files as $path => $bytes) {
                $this->seedServerFile($server, $path, $bytes);
            }
            $runtime->assertInstalled($server, $repo, $loader, $mc, $build, ['SERVER_JARFILE' => 'server.jar']);
            $verified++;
        }

        self::assertSame(count($cases), $verified);
    }

    public function test_range_based_probing_extracts_pinned_loader_from_large_mrpack_and_follows_allowlisted_curseforge_redirect(): void
    {
        $fixtures = new ModpackArchiveFixtures();
        try {
            // 1. M3 (#14): 9.5 MiB .mrpack served with HTTP 206 Range (full GET rejected!)
            $mrpackPath = $fixtures->buildMrpack(
                dependencies: ['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'],
                files: [[
                    'path' => 'mods/sample.jar',
                    'hashes' => ['sha1' => sha1('jar'), 'sha512' => hash('sha512', 'jar')],
                    'downloads' => ['https://cdn.modrinth.com/data/sample/sample.jar'],
                    'fileSize' => 3,
                ]],
                options: ['pad_bytes' => 9_500_000, 'name' => 'Large Range Pack', 'version_id' => '2.0.0'],
            );
            $mrpackBytes = (string) file_get_contents($mrpackPath);
            self::assertGreaterThan(9_000_000, strlen($mrpackBytes));

            $mrpackUrl = 'https://cdn.modrinth.com/data/bigpack/versions/ver1/large-pack.mrpack';
            $mrpackMetrics = [];

            // 2. M3 (#14): CurseForge ZIP at edge.forgecdn.net returning 302 -> mediafilez.forgecdn.net (206 Range)
            $cfZipPath = $fixtures->buildCurseForgeClientPack(
                minecraft: '1.21.1',
                modLoaders: [['id' => 'neoforge-21.1.266', 'primary' => true]],
                manifestFiles: [['projectID' => 238222, 'fileID' => 500001, 'required' => true]],
            );
            $cfZipBytes = (string) file_get_contents($cfZipPath);
            $cfEdgeUrl = 'https://edge.forgecdn.net/files/9620/701/ATM10-8.6.zip';
            $cfMediaUrl = 'https://mediafilez.forgecdn.net/files/9620/701/ATM10-8.6.zip';
            $cfMetrics = [];

            $this->fakeUpstreamHttp([
                'api.modrinth.com/v2/project/bigpack' => Http::response([
                    'id' => 'bigpack',
                    'slug' => 'large-range-pack',
                    'title' => 'Large Range Pack',
                    'description' => 'Over 8 MiB mrpack',
                    'downloads' => 5000,
                    'updated' => '2026-10-01T00:00:00Z',
                ], 200),
                'api.modrinth.com/v2/version/ver1' => Http::response([
                    'id' => 'ver1',
                    'project_id' => 'bigpack',
                    'version_number' => '2.0.0',
                    'game_versions' => ['1.21.1'],
                    'loaders' => ['fabric'],
                    'files' => [[
                        'primary' => true,
                        'filename' => 'large-pack.mrpack',
                        'url' => $mrpackUrl,
                        'size' => strlen($mrpackBytes),
                        'hashes' => ['sha512' => hash('sha512', $mrpackBytes), 'sha1' => sha1($mrpackBytes)],
                    ]],
                ], 200),
                $mrpackUrl => ModpackArchiveFixtures::rangeHttpResponder($mrpackBytes, $mrpackMetrics, rejectFullGet: true),
                'api.curseforge.com/v1/mods/925200' => Http::response([
                    'data' => [
                        'id' => 925200,
                        'slug' => 'all-the-mods-10',
                        'name' => 'All the Mods 10',
                        'summary' => 'ATM10',
                        'downloadCount' => 1000000,
                    ],
                ], 200),
                'api.curseforge.com/v1/mods/925200/files/9620701' => Http::response([
                    'data' => ModpackArchiveFixtures::curseForgeApiFile(
                        projectId: 925200,
                        fileId: 9620701,
                        fileName: 'ATM10-8.6.zip',
                        contentBytes: $cfZipBytes,
                        downloadUrl: $cfEdgeUrl,
                        gameVersions: ['1.21.1', 'NeoForge'],
                    ),
                ], 200),
                $cfEdgeUrl => Http::response('', 302, ['Location' => $cfMediaUrl]),
                $cfMediaUrl => ModpackArchiveFixtures::rangeHttpResponder($cfZipBytes, $cfMetrics, rejectFullGet: true),
            ]);

            $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(45));
            $server = $this->createServer($fabricEgg);

            /** @var ModpackCatalog $catalog */
            $catalog = $this->app->make(ModpackCatalog::class);

            $mrDescriptor = $catalog->describe($server, 'modrinth', 'bigpack', 'ver1');
            self::assertNotNull($mrDescriptor);
            self::assertSame('1.21.1', $mrDescriptor->minecraftVersion);
            self::assertSame('fabric', $mrDescriptor->loader);
            self::assertSame('0.16.10', $mrDescriptor->loaderVersion, 'M3 (#14): Range tail probe must extract pinned loader build from >8 MiB .mrpack.');
            self::assertGreaterThan(0, $mrpackMetrics['requests'] ?? 0);
            self::assertLessThan(2 * 1024 * 1024, $mrpackMetrics['bytes_sent'] ?? PHP_INT_MAX, 'M3 (#14): Range probe must transfer < 2 MiB instead of full 9.5 MiB archive.');

            $cfDescriptor = $catalog->describe($server, 'curseforge', '925200', '9620701');
            self::assertNotNull($cfDescriptor);
            self::assertSame('neoforge', $cfDescriptor->loader);
            self::assertSame('21.1.266', $cfDescriptor->loaderVersion, 'M3 (#14): Allowlisted 302 redirect edge.forgecdn.net -> mediafilez.forgecdn.net must succeed via Range probe.');
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_modrinth_preserves_mirror_fallbacks_and_streams_overrides_over_2mib_without_truncation(): void
    {
        $fixtures = new ModpackArchiveFixtures();
        try {
            $modBytes = "PK\x03\x04mirror-mod-payload";
            $primaryUrl = 'https://cdn.modrinth.com/data/mir/primary.jar';
            $mirrorUrl = 'https://github.com/example/mod/releases/download/v1/primary.jar';
            $largeOverrideBytes = str_repeat('A', 2_600_000);
            $largeServerOverrideBytes = str_repeat('B', 2_600_000);

            $mrpackPath = $fixtures->buildMrpack(
                dependencies: ['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'],
                files: [[
                    'path' => 'mods/mirror-mod.jar',
                    'hashes' => ['sha1' => sha1($modBytes), 'sha512' => hash('sha512', $modBytes)],
                    'downloads' => [$primaryUrl, $mirrorUrl],
                    'fileSize' => strlen($modBytes),
                    'env' => ['server' => 'required', 'client' => 'required'],
                ]],
                overrides: ['config/large-base.dat' => $largeOverrideBytes],
                serverOverrides: ['datapacks/large-worldgen.zip' => $largeServerOverrideBytes],
            );

            // 1. M3 (#15): Secondary mirror preserved in ModpackRemoteFile::$fallbackUrls & >2 MiB overrides accepted
            try {
                $manifest = ModpackArchive::read($mrpackPath, strict: true);
            } catch (ModpackException $e) {
                self::fail('M3 (#15): ModpackArchive::read() must stream >2 MiB overrides/server-overrides without rejecting size: '.$e->getMessage());
            }
            self::assertCount(1, $manifest->remoteFiles);
            self::assertSame($primaryUrl, $manifest->remoteFiles[0]->url);
            self::assertSame([$mirrorUrl], $manifest->remoteFiles[0]->fallbackUrls, 'M3 (#15): downloads[1..n] must be preserved as fallbackUrls.');

            // 2. M3 (#15): Streaming >2 MiB overrides & server-overrides land intact on Wings with zero override-too-large skips
            $this->registerRemoteFile($primaryUrl, $modBytes);
            $this->registerRemoteFile($mirrorUrl, $modBytes);

            $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(46));
            $server = $this->createServer($fabricEgg);
            $installer = $this->app->make(ModpackInstaller::class);

            $release = [
                'provider' => ModpackManifest::FORMAT_MODRINTH,
                'pack_id' => 'stream-pack',
                'version_id' => 'v1',
                'version_name' => '1.0.0',
                'minecraft_version' => '1.21.1',
                'loader' => 'fabric',
                'loader_version' => '0.16.10',
                'server_pack' => false,
            ];

            $result = $installer->deploy(
                $server,
                $this->app->make(DaemonFileRepository::class),
                $mrpackPath,
                $release,
                'fabric',
                '1.21.1',
                false,
                null,
                null,
                static fn (): bool => true,
            );

            $tooLargeWarnings = array_values(array_filter(
                $result['warnings'] ?? [],
                static fn (string $w): bool => str_starts_with($w, 'override-too-large:'),
            ));
            self::assertSame([], $tooLargeWarnings, 'M3 (#15): >2 MiB overrides must stream instead of being skipped as override-too-large.');
            self::assertTrue($this->hasServerFile($server, 'config/large-base.dat'));
            self::assertSame(hash('sha256', $largeOverrideBytes), hash('sha256', $this->serverFile($server, 'config/large-base.dat')));
            self::assertTrue($this->hasServerFile($server, 'datapacks/large-worldgen.zip'));
            self::assertSame(hash('sha256', $largeServerOverrideBytes), hash('sha256', $this->serverFile($server, 'datapacks/large-worldgen.zip')));
        } finally {
            $fixtures->cleanup();
        }
    }
}
