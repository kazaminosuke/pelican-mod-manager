<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier4;

use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackInventory;
use Kazaminosuke\ModManager\Support\Modpacks\FtbReleaseManifest;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\ModpackArchiveFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;

/**
 * Tier 4 — Real-World Large-Scale FTB Bundled Deployment & Quantitative Efficiency Bounds
 * (Features #10, #17, #19, #20).
 */
final class LargeScaleFtbBundledDeploymentE2ETest extends E2ETestCase
{
    public function test_600_file_ftb_evolution_style_bundled_deployment_meets_strict_http_and_zero_readback_bounds(): void
    {
        self::assertTrue(
            class_exists('Kazaminosuke\\ModManager\\Services\\ModpackBundleUploader'),
            'M4 (#19): ModpackBundleUploader must exist for bounded ZIP staging at 600-file scale.',
        );

        $scale = ModpackArchiveFixtures::buildFtbLargeScaleFixture(
            fileCount: 600,
            packId: 135,
            versionId: 100634,
            minecraft: '26.1.2',
            loader: 'neoforge',
            loaderVersion: '26.1.2.129',
            javaVersion: '25.0.2+10-LTS',
        );

        foreach ($scale['files_by_url'] as $url => $bytes) {
            $this->registerRemoteFile($url, $bytes);
        }

        $manifestPath = $this->trackTempPath($this->tempStoragePath.'/app/private/ftb-600.json');
        file_put_contents($manifestPath, $scale['manifest_json']);
        $parsed = FtbReleaseManifest::read($scale['manifest_json'], '135', '100634');
        self::assertCount(600, $parsed->remoteFiles);

        $neoforgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::neoforge(140));
        $server = $this->createServer($neoforgeEgg, overrides: [
            'image' => 'ghcr.io/pelican-eggs/yolks:java_25',
        ], variableValues: [
            'MC_VERSION' => '26.1.2',
            'NEOFORGE_VERSION' => '26.1.2.129',
        ]);

        // Must succeed even when Wings disables remote downloads completely!
        $this->wings->setDisableRemoteDownload(true);
        $this->wings->resetMetrics();

        $guardCalls = 0;
        $this->app->make(ModpackInstaller::class)->deploy(
            $server,
            $this->app->make(DaemonFileRepository::class),
            $manifestPath,
            [
                'provider' => ModpackManifest::FORMAT_FTB,
                'pack_id' => '135',
                'version_id' => '100634',
                'version_name' => '1.0.0',
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
            static function () use (&$guardCalls): void {
                $guardCalls++;
            },
        );

        // Hard Quantitative Efficiency Bounds (TEST_INFRA.md §6)
        $this->wings->assertTotalCallsAtMost(59, 'Tier 4 bound: 600-file FTB bundled deploy must use < 60 total Wings HTTP calls (vs ~1,800-3,000 serial baseline).');
        $this->wings->assertEndpointCalled('pull', 0, 'Tier 4 bound: pull === 0 under disable_remote_download: true.');
        self::assertGreaterThanOrEqual(1, $this->wings->count('putStream'));
        $this->wings->assertEndpointCalledAtMost('putStream', 10, 'Tier 4 bound: putStream <= 10 bundle parts.');
        self::assertGreaterThanOrEqual(1, $this->wings->count('decompress'));
        $this->wings->assertEndpointCalledAtMost('decompress', 10, 'Tier 4 bound: decompress >= 1 && <= 10.');
        $this->wings->assertEndpointCalledAtMost('rename', 15, 'Tier 4 bound: rename <= 15 batched calls (up to 200/call).');
        $this->wings->assertBytesWingsToPanelAtMost(0, 'Tier 4 bound: 0 bytes Wings->Panel read-back during Panel-verified bundled staging.');
        $this->wings->assertNoTemporaryModManagerFiles($server->uuid);
        self::assertGreaterThanOrEqual(1, $guardCalls, 'Long deployment must invoke heartbeat/cancel guard.');

        // 100% SHA-256 content verification across all 600 files including 0-byte config & structurize/
        $actualFiles = $this->wings->listAllFiles($server->uuid);
        foreach ($scale['expected_files'] as $relPath => $expectedBytes) {
            self::assertArrayHasKey($relPath, $actualFiles, "Missing deployed file [{$relPath}].");
            self::assertSame(
                hash('sha256', $expectedBytes),
                hash('sha256', $actualFiles[$relPath]),
                "SHA-256 mismatch for [{$relPath}].",
            );
        }

        // DB ownership ledger committed cleanly with all 600 files and null recovery
        $state = $this->app->make(ModpackInventory::class)->read($server);
        self::assertNull($state['recovery']);
        self::assertCount(600, $state['inventory']['files'] ?? []);
    }

    public function test_long_multi_batch_install_cancellation_aborts_promptly_invokes_heartbeat_and_leaves_zero_residue(): void
    {
        $cancelClass = 'Kazaminosuke\\ModManager\\Exceptions\\ModpackCancelledException';
        self::assertTrue(class_exists($cancelClass), 'M4 (#20): ModpackCancelledException must exist.');
        self::assertTrue(class_exists('Kazaminosuke\\ModManager\\Services\\ModpackBundleUploader'), 'M4 (#19): ModpackBundleUploader must exist.');

        $fixtures = new ModpackArchiveFixtures();
        try {
            $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(141));
            $server = $this->createServer($fabricEgg);
            $this->seedServerFile($server, 'mods/pre-existing-v1.jar', "PK\x03\x04v1-safe");

            $downloadCount = 0;
            $files = [];
            $bytesByUrl = [];
            for ($i = 0; $i < 200; $i++) {
                $bytes = "PK\x03\x04batch-cancel-{$i}";
                $url = "https://cdn.modrinth.com/data/cancel200/mod-{$i}.jar";
                $bytesByUrl[$url] = $bytes;
                $files[] = [
                    'path' => "mods/mod-{$i}.jar",
                    'hashes' => ['sha1' => sha1($bytes), 'sha512' => hash('sha512', $bytes)],
                    'downloads' => [$url],
                    'fileSize' => strlen($bytes),
                    'env' => ['server' => 'required'],
                ];
            }

            $this->fakeUpstreamHttp([
                'cdn.modrinth.com/data/cancel200/*' => static function (Request $req) use (&$downloadCount, $bytesByUrl) {
                    $downloadCount++;
                    $url = $req->url();

                    return Http::response($bytesByUrl[$url] ?? '', 200);
                },
            ]);

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
                        'pack_id' => 'cancel200',
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
                        if ($guardCalls >= 3) {
                            throw new $cancelClass('Operator cancelled 200-file install.');
                        }
                    },
                );
            } catch (\Throwable $e) {
                $caught = $e;
            }

            self::assertInstanceOf($cancelClass, $caught);
            self::assertLessThan(200, $downloadCount, 'Cooperative cancellation must abort promptly before downloading all 200 files.');
            self::assertGreaterThanOrEqual(1, $guardCalls, 'Heartbeat/cancel guard must be invoked during long loops.');
            self::assertSame("PK\x03\x04v1-safe", $this->serverFile($server, 'mods/pre-existing-v1.jar'));
            $this->wings->assertNoTemporaryModManagerFiles($server->uuid);
            self::assertNull($this->app->make(ModpackInventory::class)->read($server)['recovery']);
        } finally {
            $fixtures->cleanup();
        }
    }
}
