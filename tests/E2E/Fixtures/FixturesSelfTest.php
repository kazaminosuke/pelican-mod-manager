<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Fixtures;

use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use ZipArchive;

/**
 * Self-tests proving every fixture builder under tests/E2E/Fixtures/ produces
 * valid archives, authentic official Pelican Egg rules, RFC 9110 Range HTTP
 * responses, valid JAR descriptors, and cleans up all temporary files.
 *
 * MUST pass 100% on baseline 4e5f839 and every subsequent milestone.
 */
final class FixturesSelfTest extends E2ETestCase
{
    public function test_official_pelican_egg_fixtures_enforce_authentic_pinned_rules_and_seed_db(): void
    {
        $engine = new EggCompatibilityEngine();
        $classified = OfficialPelicanEggFixtures::classifyAll($engine);
        self::assertCount(8, $classified);

        // NeoForge official rule regex:/^[0-9\.\-]+$/ rejects -beta builds
        $neoforge = OfficialPelicanEggFixtures::neoforge();
        self::assertSame(['NEOFORGE_VERSION'], OfficialPelicanEggFixtures::validateVariables($neoforge, [
            'MC_VERSION' => '1.20.4',
            'NEOFORGE_VERSION' => '20.4.190-beta',
        ]));
        self::assertSame([], OfficialPelicanEggFixtures::validateVariables($neoforge, [
            'MC_VERSION' => '1.21.1',
            'NEOFORGE_VERSION' => '21.1.266',
        ]));

        // Forge & NeoForge MC_VERSION max:9 rejects 11-char snapshot strings
        $forge = OfficialPelicanEggFixtures::forge();
        self::assertSame(['MC_VERSION'], OfficialPelicanEggFixtures::validateVariables($forge, [
            'MC_VERSION' => '1.21.1-pre1',
            'FORGE_VERSION' => '47.4.20',
            'BUILD_TYPE' => 'recommended',
            'SERVER_JARFILE' => 'server.jar',
        ]));

        // Quilt official Egg has NO QUILT_VERSION variable
        $quiltVars = array_column(OfficialPelicanEggFixtures::quilt()['variables'], 'env_variable');
        self::assertNotContains('QUILT_VERSION', $quiltVars);

        // Tekkit 2 max:10 vs Tekkit Legends max:20
        self::assertSame(['MODPACK_VERSION'], OfficialPelicanEggFixtures::validateVariables(
            OfficialPelicanEggFixtures::tekkit2(),
            ['MODPACK_VERSION' => '12345678901'],
        ));
        self::assertSame([], OfficialPelicanEggFixtures::validateVariables(
            OfficialPelicanEggFixtures::tekkitLegends(),
            ['MODPACK_VERSION' => '12345678901'],
        ));

        // Fabric startup exposes -Xmx{{SERVER_MEMORY}}M
        self::assertStringContainsString(
            '-Xmx{{SERVER_MEMORY}}M',
            OfficialPelicanEggFixtures::fabric()['startup_commands']['Default'],
        );

        // DB seeding inserts Egg + EggVariables with JSON array rules
        $seeded = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::neoforge(22));
        self::assertSame('e23e092f-b803-4f34-82cf-2d6518c6351a', $seeded->uuid);
        self::assertCount(2, $seeded->variables);
        $nfVar = $seeded->variables->firstWhere('env_variable', 'NEOFORGE_VERSION');
        self::assertNotNull($nfVar);
        self::assertIsArray($nfVar->rules);
        self::assertContains('regex:/^[0-9\.\-]+$/', $nfVar->rules);

        // Paper official egg-paper.yaml (c6f2262) verbatim rules & user_viewable/user_editable persistence
        $paper = OfficialPelicanEggFixtures::paper(27);
        self::assertSame(['minecraft'], $paper['tags']);
        self::assertSame(['USER_AGENT'], OfficialPelicanEggFixtures::validateVariables($paper, []));
        self::assertSame([], OfficialPelicanEggFixtures::validateVariables($paper, ['USER_AGENT' => 'Pelican/1.0']));

        $paperVarsByEnv = [];
        foreach ($paper['variables'] as $var) {
            $paperVarsByEnv[(string) $var['env_variable']] = $var;
        }
        self::assertSame('', $paperVarsByEnv['USER_AGENT']['default_value'] ?? null);
        self::assertSame(['required'], $paperVarsByEnv['USER_AGENT']['rules'] ?? null);
        self::assertFalse($paperVarsByEnv['USER_AGENT']['user_viewable'] ?? true);
        self::assertFalse($paperVarsByEnv['USER_AGENT']['user_editable'] ?? true);
        self::assertSame([], $paperVarsByEnv['PROJECT']['rules'] ?? null);
        self::assertFalse($paperVarsByEnv['PROJECT']['user_viewable'] ?? true);
        self::assertFalse($paperVarsByEnv['PROJECT']['user_editable'] ?? true);
        self::assertFalse($paperVarsByEnv['DL_PATH']['user_viewable'] ?? true);
        self::assertFalse($paperVarsByEnv['DL_PATH']['user_editable'] ?? true);

        $seededPaper = OfficialPelicanEggFixtures::seedIntoDatabase($paper);
        foreach (['DL_PATH', 'PROJECT', 'USER_AGENT'] as $hiddenEnv) {
            $row = $seededPaper->variables->firstWhere('env_variable', $hiddenEnv);
            self::assertNotNull($row);
            self::assertFalse((bool) $row->user_viewable, "{$hiddenEnv} must persist user_viewable=false.");
            self::assertFalse((bool) $row->user_editable, "{$hiddenEnv} must persist user_editable=false.");
        }
        foreach (['MINECRAFT_VERSION', 'BUILD_NUMBER', 'SERVER_JARFILE'] as $visibleEnv) {
            $row = $seededPaper->variables->firstWhere('env_variable', $visibleEnv);
            self::assertNotNull($row);
            self::assertTrue((bool) $row->user_viewable, "{$visibleEnv} must persist user_viewable=true.");
            self::assertTrue((bool) $row->user_editable, "{$visibleEnv} must persist user_editable=true.");
        }
    }

    public function test_modpack_archive_fixtures_build_valid_zips_range_responses_jars_ftb_scale_and_cleanup(): void
    {
        $fixtures = new ModpackArchiveFixtures();

        // 1. >8 MiB CM_STORE-padded .mrpack + RFC 9110 Range responder
        $mrpackPath = $fixtures->buildMrpack(
            dependencies: ['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'],
            files: [[
                'path' => 'mods/a.jar',
                'hashes' => ['sha1' => sha1('abc'), 'sha512' => hash('sha512', 'abc')],
                'downloads' => ['https://cdn.modrinth.com/a.jar', 'https://github.com/example/a/releases/download/v1/a.jar'],
                'fileSize' => 3,
            ]],
            overrides: ['config/base.toml' => 'a=1'],
            serverOverrides: ['config/base.toml' => 'a=2'],
            clientOverrides: ['options.txt' => 'fov:90'],
            options: ['omit_env' => true, 'pad_bytes' => 8_500_000],
        );
        self::assertFileExists($mrpackPath);
        self::assertGreaterThan(8_500_000, filesize($mrpackPath));

        $archiveBytes = (string) file_get_contents($mrpackPath);
        $metrics = [];
        $this->fakeUpstreamHttp([
            'https://cdn.modrinth.com/data/range/pack.mrpack' => ModpackArchiveFixtures::rangeHttpResponder($archiveBytes, $metrics, rejectFullGet: true),
        ]);

        $tailResp = Http::withHeaders(['Range' => 'bytes=-65536'])->get('https://cdn.modrinth.com/data/range/pack.mrpack');
        self::assertSame(206, $tailResp->status());
        self::assertSame(65536, strlen($tailResp->body()));
        self::assertSame(1, $metrics['requests']);
        self::assertSame(65536, $metrics['bytes_sent']);

        $headResp = Http::withHeaders(['Range' => 'bytes=0-3'])->get('https://cdn.modrinth.com/data/range/pack.mrpack');
        self::assertSame(206, $headResp->status());
        self::assertSame("PK\x03\x04", $headResp->body());

        // 2. 3-level CurseForge server pack & CF-hosted FTB installer pack
        $cfServerZip = $fixtures->buildCurseForgeServerPack(
            managedFiles: ['mods/sample.jar' => "PK\x03\x04jar"],
            wrapperDepth: 3,
            variablesTxt: "MINECRAFT_VERSION=1.21.1\nMODLOADER=neoforge\nMODLOADER_VERSION=21.1.266\nRECOMMENDED_JAVA_VERSION=21\n",
        );
        $zip = new ZipArchive();
        self::assertTrue($zip->open($cfServerZip, ZipArchive::RDONLY));
        self::assertNotFalse($zip->locateName('WrapperLevel1/WrapperLevel2/WrapperLevel3/mods/sample.jar'));
        $zip->close();

        $ftbInstallerZip = $fixtures->buildCurseForgeFtbInstallerPack(125, 100621);
        self::assertFileExists($ftbInstallerZip);

        // 3. Authoritative client-only & universal mod JARs
        $fabricClient = $fixtures->buildFabricModJar('sodiumlike', 'client');
        $quiltClient = $fixtures->buildQuiltModJar('qclient', 'client');
        $forgeClient = $fixtures->buildForgeModJar('fclient', true, 'CLIENT');
        $neoforgeUniversal = $fixtures->buildNeoForgeModJar('nfserver', false);
        foreach ([$fabricClient, $quiltClient, $forgeClient, $neoforgeUniversal] as $jar) {
            self::assertStringStartsWith("PK\x03\x04", (string) file_get_contents($jar));
        }

        // 4. Hostile archives
        $slipPath = $fixtures->buildZipSlipArchive();
        $symPath = $fixtures->buildSymlinkArchive();
        $modeZeroPath = $fixtures->buildModeZeroArchive(['config/locked.toml' => 'x=1']);
        self::assertFileExists($slipPath);
        self::assertFileExists($symPath);
        self::assertFileExists($modeZeroPath);

        // 5. Large-scale 600-file FTB manifest generator
        $scale = ModpackArchiveFixtures::buildFtbLargeScaleFixture(600);
        self::assertCount(600, $scale['expected_files']);
        self::assertCount(600, $scale['files_by_url']);
        self::assertSame('', $scale['expected_files']['config/ironfurnaces.toml']);
        self::assertArrayHasKey('structurize/blueprints/keep.blueprint', $scale['expected_files']);

        // 6. Cleanup removes all generated temp files
        $fixtures->cleanup();
        self::assertFileDoesNotExist($mrpackPath);
        self::assertFileDoesNotExist($cfServerZip);
        self::assertFileDoesNotExist($fabricClient);
    }

    public function test_loader_runtime_fixtures_produce_authentic_folded_manifests_and_valid_jars(): void
    {
        $obf = LoaderRuntimeFixtures::fabricObfuscated('1.21.1', '0.16.10');
        self::assertArrayHasKey('libraries/net/fabricmc/intermediary/1.21.1/intermediary-1.21.1.jar', $obf);
        self::assertArrayHasKey('minecraft-server.jar', $obf);

        $unobf = LoaderRuntimeFixtures::fabricUnobfuscated26('26.1.2', '0.19.3');
        self::assertArrayNotHasKey('libraries/net/fabricmc/intermediary/26.1.2/intermediary-26.1.2.jar', $unobf);
        self::assertArrayHasKey('libraries/net/fabricmc/fabric-loader/0.19.3/fabric-loader-0.19.3.jar', $unobf);

        // Verify MANIFEST.MF contains folded line continuation ("\r\n ") and unfolds cleanly
        $tmp = $this->trackTempPath(sys_get_temp_dir().'/e2e-rt-check-'.bin2hex(random_bytes(4)).'.jar');
        file_put_contents($tmp, $obf['server.jar']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp, ZipArchive::RDONLY));
        $rawManifest = (string) $zip->getFromName('META-INF/MANIFEST.MF');
        $zip->close();
        self::assertStringContainsString("\r\n ", $rawManifest);
        $unfolded = (string) preg_replace('/\r?\n /', '', $rawManifest);
        self::assertStringContainsString('libraries/net/fabricmc/fabric-loader/0.16.10/fabric-loader-0.16.10.jar', $unfolded);

        $custom = LoaderRuntimeFixtures::fabricCustomServerJar('1.21.1', '0.16.10', 'custom-mc.jar');
        self::assertArrayHasKey('custom-mc.jar', $custom);
        self::assertArrayNotHasKey('minecraft-server.jar', $custom);
        self::assertStringContainsString('serverJar=custom-mc.jar', $custom['fabric-server-launcher.properties']);

        self::assertArrayHasKey('unix_args.txt', LoaderRuntimeFixtures::forgeModern());
        self::assertArrayHasKey('server.jar', LoaderRuntimeFixtures::forge1165());
        self::assertArrayHasKey('server.jar', LoaderRuntimeFixtures::forge1122());
        self::assertArrayHasKey('unix_args.txt', LoaderRuntimeFixtures::neoforge1201());
        self::assertArrayHasKey('unix_args.txt', LoaderRuntimeFixtures::neoforgeModern('26.1.2', '26.1.2.129'));
        self::assertArrayHasKey('quilt-server-launcher.properties', LoaderRuntimeFixtures::quilt());
    }
}
