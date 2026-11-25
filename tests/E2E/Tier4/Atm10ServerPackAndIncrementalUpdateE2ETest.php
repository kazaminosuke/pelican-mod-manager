<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier4;

use App\Repositories\Daemon\DaemonFileRepository;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackInventory;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\ModpackArchiveFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;

/**
 * Tier 4 — ATM10-Style 300-File 2-Level Server Pack Cold Deploy + 5-File v2 Incremental Update
 * & Quantitative Efficiency Bounds (Features #10, #18, #19).
 */
final class Atm10ServerPackAndIncrementalUpdateE2ETest extends E2ETestCase
{
    public function test_300_file_atm10_server_pack_cold_deploy_and_5_file_incremental_v2_update_meet_efficiency_bounds(): void
    {
        self::assertTrue(
            class_exists('Kazaminosuke\\ModManager\\Services\\ModpackBundleUploader'),
            'M4 (#19): ModpackBundleUploader must exist for bundled server pack staging and incremental updates.',
        );

        $fixtures = new ModpackArchiveFixtures();
        try {
            $neoforgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::neoforge(150));
            $server = $this->createServer($neoforgeEgg, variableValues: [
                'MC_VERSION' => '1.21.1',
                'NEOFORGE_VERSION' => '21.1.266',
            ]);
            $repo = $this->app->make(DaemonFileRepository::class);
            $installer = $this->app->make(ModpackInstaller::class);

            // Pre-existing individually catalog-installed mod MUST be preserved across pack install & v2 update
            $catalogModBytes = "PK\x03\x04catalog-spark-mod";
            $this->seedServerFile($server, 'mods/spark-catalog.jar', $catalogModBytes);
            $this->seedServerFile($server, 'mods/.pelican-mod-manager.json', json_encode([
                'schema_version' => 2,
                'installed_mods' => [[
                    'source' => ProjectSourceKey::Modrinth->value,
                    'project_id' => 'spark1',
                    'project_slug' => 'spark',
                    'project_title' => 'Spark',
                    'version_id' => 'v1',
                    'version_number' => '1.10',
                    'filename' => 'spark-catalog.jar',
                    'managed_by' => 'catalog',
                    'installed_at' => gmdate('c'),
                ]],
                'unresolved_files' => [],
            ], JSON_THROW_ON_ERROR));

            // Build 300-file ATM10-style 2-level nested server pack (mods/ + config/ + kubejs/ + datapacks/)
            $v1Managed = [];
            for ($i = 0; $i < 200; $i++) {
                $v1Managed[sprintf('mods/atm-mod-%03d.jar', $i)] = "PK\x03\x04ATM10-V1-MOD-{$i}";
            }
            for ($i = 0; $i < 60; $i++) {
                $v1Managed[sprintf('config/atm-cfg-%03d.toml', $i)] = "setting_{$i} = 1\n";
            }
            for ($i = 0; $i < 30; $i++) {
                $v1Managed[sprintf('kubejs/server_scripts/recipe-%03d.js', $i)] = "// atm10 v1 recipe {$i}\n";
            }
            for ($i = 0; $i < 10; $i++) {
                $v1Managed[sprintf('datapacks/atm-dp-%02d.zip', $i)] = "PK\x03\x04ATM10-V1-DP-{$i}";
            }
            self::assertCount(300, $v1Managed);

            $variablesTxt = "MINECRAFT_VERSION=1.21.1\nMODLOADER=neoforge\nMODLOADER_VERSION=21.1.266\nRECOMMENDED_JAVA_VERSION=21\n";
            $v1Zip = $fixtures->buildCurseForgeServerPack(
                managedFiles: $v1Managed,
                wrapperDepth: 2,
                variablesTxt: $variablesTxt,
                rootAuxiliaryFiles: [
                    'neoforge-21.1.266-installer.jar' => "PK\x03\x04installer",
                    'startserver.sh' => "#!/usr/bin/env bash\njava -jar server.jar\n",
                ],
            );

            // Cold Deploy (300 files) onto StrictWingsFake with disable_remote_download: true
            $this->wings->setDisableRemoteDownload(true);
            $this->wings->resetMetrics();

            $installer->deploy(
                $server,
                $repo,
                $v1Zip,
                [
                    'provider' => ModpackManifest::FORMAT_CURSEFORGE,
                    'pack_id' => '925200',
                    'version_id' => '8600',
                    'version_name' => '8.6',
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

            // Cold deploy quantitative bounds (TEST_INFRA.md §6)
            $this->wings->assertTotalCallsAtMost(44, 'Tier 4 ATM10 cold deploy (300 files): totalWingsHttpCalls must be < 45.');
            $this->wings->assertBytesWingsToPanelAtMost(
                4096,
                'Tier 4 ATM10 cold deploy: Wings->Panel reads must be limited to tiny metadata (mods/.pelican-mod-manager.json / server.properties), never staged or deployed modpack files.',
            );
            foreach ($this->wings->recordedRequests('readStream') as $readReq) {
                $readFile = ltrim((string) ($readReq['query']['file'] ?? ''), '/');
                self::assertContains(
                    $readFile,
                    ['mods/.pelican-mod-manager.json', 'server.properties'],
                    "Tier 4 ATM10 cold deploy: Unexpected Wings->Panel readStream of [{$readFile}] (must never read back .mod-manager-* or pack files).",
                );
            }
            $this->wings->assertNoTemporaryModManagerFiles($server->uuid);
            self::assertFalse($this->hasServerFile($server, 'startserver.sh'), 'Pack shell scripts must NEVER be deployed to Wings.');

            foreach ($v1Managed as $rel => $bytes) {
                self::assertSame($bytes, $this->serverFile($server, $rel));
            }
            self::assertSame($catalogModBytes, $this->serverFile($server, 'mods/spark-catalog.jar'));

            // Simulate operator editing 2 config files between v1 and v2
            $this->seedServerFile($server, 'config/atm-cfg-010.toml', "# user customized\nsetting_10 = 999\n", time() + 60);
            $this->seedServerFile($server, 'config/atm-cfg-011.toml', "# user customized\nsetting_11 = 888\n", time() + 60);

            // Build v2 pack: 5 modified files, 3 retired mods deleted, 292 unchanged files
            $v2Managed = $v1Managed;
            foreach ([0, 1, 2, 3, 4] as $changed) {
                $v2Managed[sprintf('mods/atm-mod-%03d.jar', $changed)] = "PK\x03\x04ATM10-V2-UPDATED-{$changed}";
            }
            unset(
                $v2Managed['mods/atm-mod-197.jar'],
                $v2Managed['mods/atm-mod-198.jar'],
                $v2Managed['mods/atm-mod-199.jar'],
            );
            // Also update the 2 configs in pack v2 (user-edited copies must be preserved!)
            $v2Managed['config/atm-cfg-010.toml'] = "setting_10 = 2\n";
            $v2Managed['config/atm-cfg-011.toml'] = "setting_11 = 2\n";

            $v2Zip = $fixtures->buildCurseForgeServerPack(
                managedFiles: $v2Managed,
                wrapperDepth: 2,
                variablesTxt: $variablesTxt,
            );

            // Incremental v2 update!
            $this->wings->resetMetrics();
            $installer->deploy(
                $server,
                $repo,
                $v2Zip,
                [
                    'provider' => ModpackManifest::FORMAT_CURSEFORGE,
                    'pack_id' => '925200',
                    'version_id' => '8700',
                    'version_name' => '8.7',
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

            // Incremental v2 quantitative bounds (TEST_INFRA.md §6)
            $this->wings->assertEndpointCalledAtMost(
                'readStream',
                20,
                'Tier 4 ATM10 v2 incremental update: readStream must be <= 20 (proving (size, modified_at) fast-path avoids 3x300 full read-backs).',
            );
            $this->wings->assertTotalCallsAtMost(39, 'Tier 4 ATM10 v2 incremental update: totalWingsHttpCalls must be < 40.');
            $this->wings->assertNoTemporaryModManagerFiles($server->uuid);

            // Functional oracles
            foreach ([0, 1, 2, 3, 4] as $changed) {
                self::assertSame("PK\x03\x04ATM10-V2-UPDATED-{$changed}", $this->serverFile($server, sprintf('mods/atm-mod-%03d.jar', $changed)));
            }
            self::assertFalse($this->hasServerFile($server, 'mods/atm-mod-197.jar'));
            self::assertFalse($this->hasServerFile($server, 'mods/atm-mod-198.jar'));
            self::assertFalse($this->hasServerFile($server, 'mods/atm-mod-199.jar'));
            self::assertSame("# user customized\nsetting_10 = 999\n", $this->serverFile($server, 'config/atm-cfg-010.toml'));
            self::assertSame("# user customized\nsetting_11 = 888\n", $this->serverFile($server, 'config/atm-cfg-011.toml'));
            self::assertSame($catalogModBytes, $this->serverFile($server, 'mods/spark-catalog.jar'));
            self::assertSame("PK\x03\x04ATM10-V1-MOD-150", $this->serverFile($server, 'mods/atm-mod-150.jar'));
            self::assertNull($this->app->make(ModpackInventory::class)->read($server)['recovery']);
        } finally {
            $fixtures->cleanup();
        }
    }
}
