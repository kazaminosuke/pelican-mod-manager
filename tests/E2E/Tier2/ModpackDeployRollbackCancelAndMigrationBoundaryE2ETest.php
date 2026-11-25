<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier2;

use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\ModpackDeployment;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackInventory;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\ModpackArchiveFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use Kazaminosuke\ModManager\Tests\E2E\Support\StrictWingsFake;
use Throwable;
use ZipArchive;

/**
 * Tier 2 — Boundary, SSRF, Hostile Archive, Rollback & Clean-Switch Safety Cases for Features #19–#21 (M4).
 */
final class ModpackDeployRollbackCancelAndMigrationBoundaryE2ETest extends E2ETestCase
{
    public function test_safe_http_download_blocks_private_ips_plain_http_and_ssrf_redirects_with_zero_bytes_written(): void
    {
        $downloaderClass = 'Kazaminosuke\\ModManager\\Services\\SafeHttpDownload';
        self::assertTrue(
            class_exists($downloaderClass),
            'M4 (#19): SafeHttpDownload must exist to enforce DNS + redirect public-IP SSRF protection.',
        );

        $secretHit = false;
        $this->fakeUpstreamHttp([
            'cdn.modrinth.com/redirect-loopback.mrpack' => Http::response('', 302, ['Location' => 'https://127.0.0.1/secret']),
            'cdn.modrinth.com/redirect-imds.mrpack' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data/']),
            'cdn.modrinth.com/redirect-http-downgrade.mrpack' => Http::response('', 302, ['Location' => 'http://cdn.modrinth.com/downgraded.mrpack']),
            '127.0.0.1/*' => static function () use (&$secretHit) {
                $secretHit = true;

                return Http::response('LOCALHOST_SECRET', 200);
            },
            '169.254.169.254/*' => static function () use (&$secretHit) {
                $secretHit = true;

                return Http::response('AWS_METADATA_SECRET', 200);
            },
            'edge.forgecdn.net/files/valid/pack.jar' => Http::response('', 302, ['Location' => 'https://mediafilez.forgecdn.net/files/valid/pack.jar']),
            'mediafilez.forgecdn.net/files/valid/pack.jar' => Http::response("PK\x03\x04redirected-valid-jar", 200, ['Content-Length' => '24']),
        ]);

        $downloader = $this->app->make($downloaderClass);
        $blockedUrls = [
            'https://127.0.0.1/secret.jar',
            'https://169.254.169.254/latest/meta-data/',
            'https://10.0.0.1/internal.jar',
            'https://[::1]/ipv6-loopback.jar',
            'http://cdn.modrinth.com/plain-http.jar',
            'https://cdn.modrinth.com/redirect-loopback.mrpack',
            'https://cdn.modrinth.com/redirect-imds.mrpack',
            'https://cdn.modrinth.com/redirect-http-downgrade.mrpack',
        ];

        foreach ($blockedUrls as $idx => $url) {
            $dest = $this->trackTempPath($this->tempStoragePath."/app/private/ssrf-{$idx}.bin");
            $caught = null;
            try {
                $downloader->downloadToFile($url, $dest, []);
            } catch (Throwable $e) {
                $caught = $e;
            }
            self::assertNotNull($caught, "M4 (#19): SafeHttpDownload must reject SSRF/insecure URL [{$url}].");
            self::assertNotInstanceOf(\Error::class, $caught);
            if (is_file($dest)) {
                self::assertSame(0, filesize($dest), "Blocked URL [{$url}] must write zero bytes.");
            }
        }
        self::assertFalse($secretHit, 'Blocked SSRF redirect target must never be fetched.');

        // Valid public HTTPS 302 redirect (edge.forgecdn.net -> mediafilez.forgecdn.net) must succeed!
        $validDest = $this->trackTempPath($this->tempStoragePath.'/app/private/ssrf-valid-redir.bin');
        $downloader->downloadToFile('https://edge.forgecdn.net/files/valid/pack.jar', $validDest, []);
        self::assertFileExists($validDest);
        self::assertSame("PK\x03\x04redirected-valid-jar", (string) file_get_contents($validDest));
    }

    public function test_mode_zero_archive_normalizes_bundle_entries_to_0644_and_stages_in_private_storage_not_tmp(): void
    {
        self::assertTrue(
            class_exists('Kazaminosuke\\ModManager\\Services\\ModpackBundleUploader'),
            'M4 (#19): ModpackBundleUploader must exist for Panel-verified bounded ZIP staging.',
        );

        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(93));
        $server = $this->createServer($fabricEgg);
        /** @var DaemonFileRepository $repo */
        $repo = $this->app->make(DaemonFileRepository::class);
        $installer = $this->app->make(ModpackInstaller::class);
        $fixtures = new ModpackArchiveFixtures();

        try {
            $this->wings->setDisableRemoteDownload(true);
            $modeZeroZip = $fixtures->buildModeZeroArchive([
                'mods/zero-mode.jar' => "PK\x03\x04zero-mode-jar",
                'config/zero.toml' => "ok=1\n",
            ]);

            $sawStorageTmp = false;
            $this->wings->beforeEndpoint('putStream', static function () use (&$sawStorageTmp) {
                if (is_dir(storage_path('app/private/mod-manager-tmp'))) {
                    $sawStorageTmp = true;
                }

                return null;
            });

            $inspectedEntries = 0;
            $zeroModeEntries = [];
            $this->wings->beforeEndpoint('decompress', static function (Request $req, string $uuid, string $endpoint, StrictWingsFake $wings) use (&$inspectedEntries, &$zeroModeEntries) {
                $payload = $req->data();
                $root = trim((string) ($payload['root'] ?? ''), '/');
                $file = ltrim((string) ($payload['file'] ?? ''), '/');
                $rel = $root === '' ? $file : $root.'/'.$file;
                $zipAbs = $wings->serverRootPath($uuid).'/'.ltrim($rel, '/');
                if (is_file($zipAbs)) {
                    $z = new ZipArchive();
                    if ($z->open($zipAbs, ZipArchive::RDONLY) === true) {
                        try {
                            for ($i = 0; $i < $z->numFiles; $i++) {
                                $name = (string) $z->getNameIndex($i);
                                if (str_ends_with($name, '/')) {
                                    continue;
                                }
                                $inspectedEntries++;
                                $opsys = 0;
                                $attr = 0;
                                if (!$z->getExternalAttributesIndex($i, $opsys, $attr)
                                    || $opsys !== ZipArchive::OPSYS_UNIX
                                    || ((($attr >> 16) & 0777) === 0)) {
                                    $zeroModeEntries[] = $name;
                                }
                            }
                        } finally {
                            $z->close();
                        }
                    }
                }

                return null;
            });

            $installer->deploy(
                $server,
                $repo,
                $modeZeroZip,
                [
                    'provider' => ModpackManifest::FORMAT_CURSEFORGE,
                    'pack_id' => 'modezero',
                    'version_id' => 'v1',
                    'minecraft_version' => '1.21.1',
                    'loader' => 'fabric',
                    'loader_version' => '0.16.10',
                    'server_pack' => true,
                ],
                'fabric',
                '1.21.1',
                false,
                null,
                null,
                static fn (): bool => true,
            );

            self::assertTrue($sawStorageTmp, 'M4 (#19): Staging must use storage_path("app/private/mod-manager-tmp"), not raw /tmp.');
            self::assertGreaterThanOrEqual(2, $inspectedEntries, 'M4 (#19): Bundled ZIP must be uploaded and decompressed.');
            self::assertSame([], $zeroModeEntries, 'M4 (#19): Staging bundle ZIP entries must have explicit 0644/0755 Unix modes, never mode 0000.');

            $modes = $this->wings->extractedModes($server->uuid);
            self::assertNotEmpty($modes);
            foreach ($modes as $relPath => $mode) {
                self::assertContains($mode, [0644, 0755], "Extracted file [{$relPath}] must have mode 0644 or 0755.");
            }
            self::assertSame("PK\x03\x04zero-mode-jar", $this->serverFile($server, 'mods/zero-mode.jar'));
            self::assertSame("ok=1\n", $this->serverFile($server, 'config/zero.toml'));

            $tmpDir = storage_path('app/private/mod-manager-tmp');
            if (is_dir($tmpDir)) {
                $remaining = array_values(array_diff(scandir($tmpDir) ?: [], ['.', '..']));
                self::assertSame([], $remaining, 'M4 (#19): Private temp staging directory must be empty after deploy completes.');
            }
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_hostile_archives_hash_mismatch_html_as_jar_and_size_mismatch_abort_before_live_mutation(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(90));
        $server = $this->createServer($fabricEgg);
        $repo = $this->app->make(DaemonFileRepository::class);
        $installer = $this->app->make(ModpackInstaller::class);

        $liveMod = "PK\x03\x04live-v1-untouched";
        $this->seedServerFile($server, 'mods/live.jar', $liveMod);
        $fixtures = new ModpackArchiveFixtures();
        try {
            // 1. Zip-slip server pack rejected before touching Wings
            $slipZip = $fixtures->buildZipSlipArchive('../../server.jar');
            $caughtSlip = null;
            try {
                $installer->deploy($server, $repo, $slipZip, [
                    'provider' => ModpackManifest::FORMAT_CURSEFORGE, 'pack_id' => 'slip', 'version_id' => '1',
                    'minecraft_version' => '1.21.1', 'loader' => 'fabric', 'loader_version' => '0.16.10', 'server_pack' => true,
                ], 'fabric', '1.21.1', false, null, null, static fn (): bool => true);
            } catch (ModpackException $e) {
                $caughtSlip = $e;
            }
            self::assertInstanceOf(ModpackException::class, $caughtSlip);

            // 2. Unix symlink server pack rejected before touching Wings
            $symZip = $fixtures->buildSymlinkArchive('mods/link.jar', '/etc/passwd');
            $caughtSym = null;
            try {
                $installer->deploy($server, $repo, $symZip, [
                    'provider' => ModpackManifest::FORMAT_CURSEFORGE, 'pack_id' => 'sym', 'version_id' => '1',
                    'minecraft_version' => '1.21.1', 'loader' => 'fabric', 'loader_version' => '0.16.10', 'server_pack' => true,
                ], 'fabric', '1.21.1', false, null, null, static fn (): bool => true);
            } catch (ModpackException $e) {
                $caughtSym = $e;
            }
            self::assertInstanceOf(ModpackException::class, $caughtSym);

            // 3. HTML 403 masquerading as .jar & hash mismatch rejected before live mutation
            $htmlBytes = '<html><body>403 Forbidden</body></html>';
            $badUrl = 'https://cdn.modrinth.com/data/bad/fake.jar';
            $this->registerRemoteFile($badUrl, $htmlBytes);
            $badMrpack = $fixtures->buildMrpack(['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'], [[
                'path' => 'mods/fake.jar',
                'hashes' => ['sha1' => sha1("PK\x03\x04expected-real-jar"), 'sha512' => hash('sha512', "PK\x03\x04expected-real-jar")],
                'downloads' => [$badUrl],
                'fileSize' => strlen($htmlBytes),
                'env' => ['server' => 'required'],
            ]]);

            $caughtBad = null;
            try {
                $installer->deploy($server, $repo, $badMrpack, [
                    'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'bad', 'version_id' => '1',
                    'minecraft_version' => '1.21.1', 'loader' => 'fabric', 'loader_version' => '0.16.10', 'server_pack' => false,
                ], 'fabric', '1.21.1', false, null, null, static fn (): bool => true);
            } catch (ModpackException $e) {
                $caughtBad = $e;
            }
            self::assertInstanceOf(ModpackException::class, $caughtBad);
            self::assertSame($liveMod, $this->serverFile($server, 'mods/live.jar'));
            self::assertFalse($this->hasServerFile($server, 'mods/fake.jar'));
            $this->wings->assertNoTemporaryModManagerFiles($server->uuid);
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_replace_collision_under_strict_wings_400_mid_apply_rollback_and_failed_rollback_recovery_lock(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(91));
        $server = $this->createServer($fabricEgg);
        $repo = $this->app->make(DaemonFileRepository::class);
        $installer = $this->app->make(ModpackInstaller::class);
        $deployment = $this->app->make(ModpackDeployment::class);
        $inventory = $this->app->make(ModpackInventory::class);
        $fixtures = new ModpackArchiveFixtures();

        try {
            // Deploy v1 with 2 mods
            $v1a = "PK\x03\x04v1-mod-a";
            $v1b = "PK\x03\x04v1-mod-b";
            $this->registerRemoteFile('https://cdn.modrinth.com/data/rb/v1a.jar', $v1a);
            $this->registerRemoteFile('https://cdn.modrinth.com/data/rb/v1b.jar', $v1b);
            $zipV1 = $fixtures->buildMrpack(['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'], [
                ['path' => 'mods/a.jar', 'hashes' => ['sha1' => sha1($v1a)], 'downloads' => ['https://cdn.modrinth.com/data/rb/v1a.jar'], 'fileSize' => strlen($v1a), 'env' => ['server' => 'required']],
                ['path' => 'mods/b.jar', 'hashes' => ['sha1' => sha1($v1b)], 'downloads' => ['https://cdn.modrinth.com/data/rb/v1b.jar'], 'fileSize' => strlen($v1b), 'env' => ['server' => 'required']],
            ]);
            $installer->deploy($server, $repo, $zipV1, [
                'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'rb', 'version_id' => 'v1',
                'minecraft_version' => '1.21.1', 'loader' => 'fabric', 'loader_version' => '0.16.10', 'server_pack' => false,
            ], 'fabric', '1.21.1', false, null, null, static fn (): bool => true);

            // Replace v1 -> v2 under StrictWingsFake (proves backup-move-first works despite Wings 400 os.ErrExist!)
            $v2a = "PK\x03\x04v2-mod-a";
            $v2b = "PK\x03\x04v2-mod-b";
            $this->registerRemoteFile('https://cdn.modrinth.com/data/rb/v2a.jar', $v2a);
            $this->registerRemoteFile('https://cdn.modrinth.com/data/rb/v2b.jar', $v2b);
            $zipV2 = $fixtures->buildMrpack(['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'], [
                ['path' => 'mods/a.jar', 'hashes' => ['sha1' => sha1($v2a)], 'downloads' => ['https://cdn.modrinth.com/data/rb/v2a.jar'], 'fileSize' => strlen($v2a), 'env' => ['server' => 'required']],
                ['path' => 'mods/b.jar', 'hashes' => ['sha1' => sha1($v2b)], 'downloads' => ['https://cdn.modrinth.com/data/rb/v2b.jar'], 'fileSize' => strlen($v2b), 'env' => ['server' => 'required']],
            ]);
            $installer->deploy($server, $repo, $zipV2, [
                'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'rb', 'version_id' => 'v2',
                'minecraft_version' => '1.21.1', 'loader' => 'fabric', 'loader_version' => '0.16.10', 'server_pack' => false,
            ], 'fabric', '1.21.1', false, null, null, static fn (): bool => true);
            self::assertSame($v2a, $this->serverFile($server, 'mods/a.jar'));
            self::assertSame($v2b, $this->serverFile($server, 'mods/b.jar'));

            // Mid-apply failure during v3: payload-aware trigger injects genuine HTTP 500 on first live-activation rename
            // (works identically under serial rename() and M4 batched renameBatch()!) -> verified rollback restores v2 cleanly!
            $v3a = "PK\x03\x04v3-mod-a";
            $v3b = "PK\x03\x04v3-mod-b";
            $this->registerRemoteFile('https://cdn.modrinth.com/data/rb/v3a.jar', $v3a);
            $this->registerRemoteFile('https://cdn.modrinth.com/data/rb/v3b.jar', $v3b);
            $zipV3 = $fixtures->buildMrpack(['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'], [
                ['path' => 'mods/a.jar', 'hashes' => ['sha1' => sha1($v3a)], 'downloads' => ['https://cdn.modrinth.com/data/rb/v3a.jar'], 'fileSize' => strlen($v3a), 'env' => ['server' => 'required']],
                ['path' => 'mods/b.jar', 'hashes' => ['sha1' => sha1($v3b)], 'downloads' => ['https://cdn.modrinth.com/data/rb/v3b.jar'], 'fileSize' => strlen($v3b), 'env' => ['server' => 'required']],
            ]);

            $isLiveActivationRename = static function (Request $req): bool {
                $files = $req->data()['files'] ?? [];
                if (!is_array($files)) {
                    return false;
                }
                foreach ($files as $item) {
                    if (is_array($item) && isset($item['to']) && is_string($item['to']) && !str_contains($item['to'], '.mod-manager-')) {
                        return true;
                    }
                }

                return false;
            };

            $failedMidOnce = false;
            $this->wings->beforeEndpoint('rename', static function (Request $req, string $uuid, string $endpoint, StrictWingsFake $wings) use (&$failedMidOnce, $isLiveActivationRename) {
                if (!$failedMidOnce && $isLiveActivationRename($req)) {
                    $failedMidOnce = true;

                    return Http::response(json_encode(['error' => 'mid-apply failure'], JSON_THROW_ON_ERROR), 500, [
                        'User-Agent' => $wings->userAgentHeader(),
                        'Content-Type' => 'application/json',
                    ]);
                }

                return null;
            });

            $caughtMid = null;
            try {
                $installer->deploy($server, $repo, $zipV3, [
                    'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'rb', 'version_id' => 'v3',
                    'minecraft_version' => '1.21.1', 'loader' => 'fabric', 'loader_version' => '0.16.10', 'server_pack' => false,
                ], 'fabric', '1.21.1', false, null, null, static fn (): bool => true);
            } catch (Throwable $e) {
                $caughtMid = $e;
            }
            $this->wings->clearFaults();

            self::assertTrue($failedMidOnce, 'Expected mid-apply HTTP 500 fault hook to trigger on live-activation rename.');
            self::assertNotNull($caughtMid);
            self::assertNotInstanceOf(\Error::class, $caughtMid);
            self::assertTrue(
                $caughtMid instanceof RequestException || $caughtMid instanceof ModpackException,
                'Mid-apply failure must throw RequestException or ModpackException, got '.get_class($caughtMid).': '.$caughtMid->getMessage(),
            );
            self::assertSame($v2a, $this->serverFile($server, 'mods/a.jar'), 'Mid-apply rollback must restore v2 mods/a.jar.');
            self::assertSame($v2b, $this->serverFile($server, 'mods/b.jar'), 'Mid-apply rollback must restore v2 mods/b.jar.');
            self::assertNull($inventory->read($server)['recovery']);

            // Failed rollback locks further updates in recovery journal until confirmRestored() proves restoration!
            $seenApply = false;
            $this->wings->beforeEndpoint('rename', static function (Request $req, string $uuid, string $endpoint, StrictWingsFake $wings) use (&$seenApply, $isLiveActivationRename) {
                if (!$seenApply && $isLiveActivationRename($req)) {
                    $seenApply = true;
                }
                if ($seenApply) {
                    return Http::response(json_encode(['error' => 'disk read-only'], JSON_THROW_ON_ERROR), 500, [
                        'User-Agent' => $wings->userAgentHeader(),
                        'Content-Type' => 'application/json',
                    ]);
                }

                return null;
            });

            $caughtRecovery = null;
            try {
                $installer->deploy($server, $repo, $zipV3, [
                    'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'rb', 'version_id' => 'v3',
                    'minecraft_version' => '1.21.1', 'loader' => 'fabric', 'loader_version' => '0.16.10', 'server_pack' => false,
                ], 'fabric', '1.21.1', false, null, 'backup-uuid-1', static fn (): bool => true);
            } catch (ModpackException $e) {
                $caughtRecovery = $e;
            }
            $this->wings->clearFaults();
            self::assertTrue($seenApply, 'Expected recovery-lock HTTP 500 fault hook to trigger on live-activation rename.');
            self::assertInstanceOf(ModpackException::class, $caughtRecovery);
            self::assertNotNull($caughtRecovery->getPrevious());
            self::assertNotInstanceOf(\Error::class, $caughtRecovery->getPrevious());

            $recovery = $inventory->read($server)['recovery'];
            self::assertNotNull($recovery, 'Unverified rollback must persist recovery lock.');

            // Subsequent deploy is blocked while recovery lock is active
            $blocked = null;
            try {
                $installer->deploy($server, $repo, $zipV2, [
                    'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'rb', 'version_id' => 'v2',
                    'minecraft_version' => '1.21.1', 'loader' => 'fabric', 'loader_version' => '0.16.10', 'server_pack' => false,
                ], 'fabric', '1.21.1', false, null, null, static fn (): bool => true);
            } catch (ModpackException $e) {
                $blocked = $e;
            }
            self::assertInstanceOf(ModpackException::class, $blocked);
            self::assertStringContainsString('recovery', strtolower($blocked->getMessage()));

            // Restoring exact v2 files and calling confirmRestored() clears recovery lock cleanly
            $this->seedServerFile($server, 'mods/a.jar', $v2a);
            $this->seedServerFile($server, 'mods/b.jar', $v2b);
            $deployment->confirmRestored($server, $repo, (string) $recovery['transaction']);
            self::assertNull($inventory->read($server)['recovery']);
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_clean_pack_switch_preserves_level_dat_worlds_and_ops_files_and_does_not_delete_on_staging_failure(): void
    {
        $mergerClass = 'Kazaminosuke\\ModManager\\Services\\ServerPropertiesMerger';
        self::assertTrue(
            class_exists($mergerClass),
            'M4 (#21): ServerPropertiesMerger must exist for clean pack-switch operational allowlist preservation.',
        );

        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(92));
        $server = $this->createServer($fabricEgg);
        $repo = $this->app->make(DaemonFileRepository::class);
        $installer = $this->app->make(ModpackInstaller::class);
        $fixtures = new ModpackArchiveFixtures();

        try {
            // Seed existing server worlds (custom level-name + extra world containing level.dat) & protected operational files
            $this->seedServerFile($server, 'server.properties', "level-name=my_smp\nserver-port=25570\nmotd=KeepMe\nlevel-type=minecraft\\:normal\n");
            $this->seedServerFile($server, 'my_smp/level.dat', 'WORLD-NBT-MAIN');
            $this->seedServerFile($server, 'nether_backup/level.dat', 'WORLD-NBT-EXTRA');
            $this->seedServerFile($server, 'eula.txt', "eula=true\n");
            $this->seedServerFile($server, 'ops.json', '[{"name":"admin"}]');
            $this->seedServerFile($server, 'whitelist.json', '[{"name":"player1"}]');
            $this->seedServerFile($server, 'banned-players.json', '[]');
            $this->seedServerFile($server, 'banned-ips.json', '[]');
            $this->seedServerFile($server, 'usercache.json', '[]');
            $this->seedServerFile($server, 'mods/old-mod.jar', "PK\x03\x04old-mod");

            // Phase 1: Staging failure (broken download URL / hash mismatch) during clean switch MUST NOT delete existing files!
            $badUrl = 'https://cdn.modrinth.com/data/sw/broken.jar';
            $this->registerRemoteFile($badUrl, 'corrupt-not-a-jar');
            $brokenSwitchPack = $fixtures->buildMrpack(['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'], [[
                'path' => 'mods/new.jar',
                'hashes' => ['sha1' => sha1('real-jar')],
                'downloads' => [$badUrl],
                'fileSize' => 8,
                'env' => ['server' => 'required'],
            ]]);

            $caught = null;
            try {
                $installer->deploy($server, $repo, $brokenSwitchPack, [
                    'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'sw', 'version_id' => 'v1',
                    'minecraft_version' => '1.21.1', 'loader' => 'fabric', 'loader_version' => '0.16.10',
                    'server_pack' => false, 'switch' => true, 'clean_switch' => true,
                ], 'fabric', '1.21.1', false, null, null, static fn (): bool => true);
            } catch (Throwable $e) {
                $caught = $e;
            }

            self::assertNotNull($caught);
            self::assertNotInstanceOf(\Error::class, $caught);
            self::assertSame("PK\x03\x04old-mod", $this->serverFile($server, 'mods/old-mod.jar'), 'Staging failure during clean switch must NEVER delete pre-existing server files.');
            self::assertSame('WORLD-NBT-MAIN', $this->serverFile($server, 'my_smp/level.dat'));
            self::assertSame('WORLD-NBT-EXTRA', $this->serverFile($server, 'nether_backup/level.dat'));
            self::assertSame("eula=true\n", $this->serverFile($server, 'eula.txt'));
            self::assertSame('[{"name":"admin"}]', $this->serverFile($server, 'ops.json'));
            self::assertSame('[{"name":"player1"}]', $this->serverFile($server, 'whitelist.json'));

            // Phase 2: Successful clean pack switch wipes untracked legacy mods while preserving worlds, ops/whitelist/eula, and merged server.properties!
            $newModBytes = "PK\x03\x04new-pack-mod";
            $newModUrl = 'https://cdn.modrinth.com/data/sw/new-pack-mod.jar';
            $this->registerRemoteFile($newModUrl, $newModBytes);
            $goodSwitchPack = $fixtures->buildMrpack(
                dependencies: ['minecraft' => '1.21.1', 'fabric-loader' => '0.16.10'],
                files: [[
                    'path' => 'mods/new-pack-mod.jar',
                    'hashes' => ['sha1' => sha1($newModBytes), 'sha512' => hash('sha512', $newModBytes)],
                    'downloads' => [$newModUrl],
                    'fileSize' => strlen($newModBytes),
                    'env' => ['server' => 'required'],
                ]],
                overrides: [
                    'config/new-pack.toml' => "clean=true\n",
                    'server.properties' => "level-name=world\nserver-port=25565\nmotd=Pack Default\nlevel-type=skyblock\\:void\n",
                ],
            );

            $installer->deploy($server, $repo, $goodSwitchPack, [
                'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'sw-good', 'version_id' => 'v1',
                'minecraft_version' => '1.21.1', 'loader' => 'fabric', 'loader_version' => '0.16.10',
                'server_pack' => false, 'switch' => true, 'clean_switch' => true,
            ], 'fabric', '1.21.1', false, null, null, static fn (): bool => true);

            self::assertFalse($this->hasServerFile($server, 'mods/old-mod.jar'), 'M4 (#21): Clean pack switch must remove untracked legacy mods.');
            self::assertSame($newModBytes, $this->serverFile($server, 'mods/new-pack-mod.jar'));
            self::assertSame("clean=true\n", $this->serverFile($server, 'config/new-pack.toml'));
            self::assertSame('WORLD-NBT-MAIN', $this->serverFile($server, 'my_smp/level.dat'));
            self::assertSame('WORLD-NBT-EXTRA', $this->serverFile($server, 'nether_backup/level.dat'));
            self::assertSame("eula=true\n", $this->serverFile($server, 'eula.txt'));
            self::assertSame('[{"name":"admin"}]', $this->serverFile($server, 'ops.json'));
            self::assertSame('[{"name":"player1"}]', $this->serverFile($server, 'whitelist.json'));
            self::assertSame('[]', $this->serverFile($server, 'banned-players.json'));
            self::assertSame('[]', $this->serverFile($server, 'banned-ips.json'));
            self::assertSame('[]', $this->serverFile($server, 'usercache.json'));

            $mergedProps = $this->serverFile($server, 'server.properties');
            self::assertStringContainsString('server-port=25570', $mergedProps);
            self::assertStringContainsString('motd=KeepMe', $mergedProps);
            self::assertStringContainsString('level-name=my_smp', $mergedProps);
            self::assertStringContainsString('skyblock', $mergedProps);
        } finally {
            $fixtures->cleanup();
        }
    }
}
