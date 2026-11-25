<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Support;

use App\Exceptions\Repository\FileExistsException;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use ZipArchive;

/**
 * Self-tests proving StrictWingsFake enforces every authentic Pelican Wings
 * contract (pelican-dev/wings@27f67c8) and Html5DomAssert catches HTML5
 * Adoption Agency anchor fragmentation.
 *
 * MUST pass 100% on baseline 4e5f839 and every subsequent milestone.
 */
final class StrictWingsFakeSelfTest extends E2ETestCase
{
    public function test_rename_returns_http_400_on_file_dir_or_dangling_symlink_collision_and_ignores_missing_source(): void
    {
        $egg = $this->createEgg(['name' => 'Fabric']);
        $server = $this->createServer($egg);
        $repo = $this->app->make(DaemonFileRepository::class)->setServer($server);

        $this->seedServerFile($server, 'mods/a.jar', 'new-a');
        $this->seedServerFile($server, 'mods/existing.jar', 'old-existing');
        $this->wings->seedDirectory($server->uuid, 'mods/existing-dir');
        $this->wings->seedSymlink($server->uuid, 'mods/dangling.jar', '/nonexistent/target');

        // Collision with regular file -> 400
        try {
            $repo->renameFiles('mods', [['from' => 'a.jar', 'to' => 'existing.jar']])->throw();
            self::fail('Expected HTTP 400 os.ErrExist when renaming onto an existing regular file.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
            self::assertStringContainsString('already exists', (string) $e->response->json('error'));
        }

        // Collision with existing directory -> 400
        try {
            $repo->renameFiles('mods', [['from' => 'a.jar', 'to' => 'existing-dir']])->throw();
            self::fail('Expected HTTP 400 when renaming onto an existing directory.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }

        // Collision with dangling symlink (checked via lstat) -> 400
        try {
            $repo->renameFiles('mods', [['from' => 'a.jar', 'to' => 'dangling.jar']])->throw();
            self::fail('Expected HTTP 400 when renaming onto a dangling symlink.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }

        // Missing source is silently ignored (real Wings os.ErrNotExist behavior)
        $response = $repo->renameFiles('mods', [['from' => 'missing.jar', 'to' => 'never-created.jar']]);
        self::assertSame(204, $response->status());
        self::assertFalse($this->hasServerFile($server, 'mods/never-created.jar'));

        // Valid batch rename works and increments renameItems
        $this->seedServerFile($server, 'mods/b.jar', 'new-b');
        $response = $repo->renameFiles('mods', [
            ['from' => 'a.jar', 'to' => 'a-moved.jar'],
            ['from' => 'b.jar', 'to' => 'b-moved.jar'],
        ]);
        self::assertSame(204, $response->status());
        self::assertSame('new-a', $this->serverFile($server, 'mods/a-moved.jar'));
        self::assertSame('new-b', $this->serverFile($server, 'mods/b-moved.jar'));
        self::assertGreaterThanOrEqual(2, $this->wings->count('renameItems'));
    }

    public function test_write_rejects_unknown_size_stream_without_content_length_and_accepts_zero_byte_and_explicit_length(): void
    {
        $egg = $this->createEgg(['name' => 'Fabric']);
        $server = $this->createServer($egg);
        $repo = $this->app->make(DaemonFileRepository::class)->setServer($server);
        $this->wings->seedDirectory($server->uuid, 'config/dir-conflict');

        // 1. Unknown-size non-seekable stream without Content-Length -> Guzzle sends Transfer-Encoding: chunked -> 400!
        $unknownSizeStream = $this->makeUnknownSizeStream('chunked-payload');
        try {
            $repo->getHttpClient()
                ->withQueryParameters(['file' => 'mods/chunked.jar'])
                ->withBody($unknownSizeStream, 'application/octet-stream')
                ->post("/api/servers/{$server->uuid}/files/write")
                ->throw();
            self::fail('Expected StrictWingsFake to reject chunked / missing Content-Length stream with HTTP 400.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
            self::assertStringContainsString('Missing Content-Length', (string) $e->response->json('error'));
        }

        // 2. Explicit negative Content-Length: -1 -> 400!
        try {
            $repo->getHttpClient()
                ->withHeaders(['Content-Length' => '-1'])
                ->withQueryParameters(['file' => 'mods/neg.jar'])
                ->withBody('abc', 'application/octet-stream')
                ->post("/api/servers/{$server->uuid}/files/write")
                ->throw();
            self::fail('Expected HTTP 400 for Content-Length: -1.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }

        // 3. Writing over an existing directory -> 400 (mapped by DaemonFileRepository::putContent to FileExistsException)!
        $dirConflict = null;
        try {
            $repo->putContent('config/dir-conflict', 'nope');
        } catch (FileExistsException $e) {
            $dirConflict = $e;
        }
        self::assertInstanceOf(FileExistsException::class, $dirConflict);

        // 4. Known 0-byte body accepted cleanly (handles Guzzle PrepareBodyMiddleware empty-body quirk)
        $emptyResponse = $repo->putContent('config/empty.toml', '');
        self::assertSame(204, $emptyResponse->status());
        self::assertTrue($this->hasServerFile($server, 'config/empty.toml'));
        self::assertSame('', $this->serverFile($server, 'config/empty.toml'));

        // 5. Explicit Content-Length with unknown-size stream succeeds and updates byte counters
        $this->wings->resetMetrics();
        $payload = 'explicit-length-body';
        $streamWithHeader = $this->makeUnknownSizeStream($payload);
        $ok = $repo->getHttpClient()
            ->withHeaders(['Content-Length' => (string) strlen($payload)])
            ->withQueryParameters(['file' => 'mods/explicit.jar'])
            ->withBody($streamWithHeader, 'application/octet-stream')
            ->post("/api/servers/{$server->uuid}/files/write");
        self::assertSame(204, $ok->status());
        self::assertSame($payload, $this->serverFile($server, 'mods/explicit.jar'));
        self::assertSame(0644, $this->wings->fileMode($server->uuid, 'mods/explicit.jar'));
        self::assertSame(strlen($payload), $this->wings->count('bytesPanelToWings'));
    }

    public function test_decompress_extracts_valid_zip_with_modes_preserves_archive_and_rejects_hostile_zips(): void
    {
        $egg = $this->createEgg(['name' => 'NeoForge']);
        $server = $this->createServer($egg);
        $repo = $this->app->make(DaemonFileRepository::class)->setServer($server);

        // Valid ZIP with 0-byte file, regular 0644 file, executable 0755 file
        $validZipPath = $this->trackTempPath(sys_get_temp_dir().'/e2e-selftest-valid-'.bin2hex(random_bytes(4)).'.zip');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($validZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('mods/hello.jar', "PK\x03\x04hello");
        $zip->setExternalAttributesName('mods/hello.jar', ZipArchive::OPSYS_UNIX, (0100644 << 16));
        $zip->addFromString('config/zero.cfg', '');
        $zip->setExternalAttributesName('config/zero.cfg', ZipArchive::OPSYS_UNIX, (0100644 << 16));
        $zip->addFromString('scripts/tool.sh', "#!/bin/sh\necho hi\n");
        $zip->setExternalAttributesName('scripts/tool.sh', ZipArchive::OPSYS_UNIX, (0100755 << 16));
        $zip->close();

        $this->seedServerFile($server, '.stage/bundle.zip', (string) file_get_contents($validZipPath));
        $repo->decompressFile('.stage', 'bundle.zip')->throw();

        self::assertSame("PK\x03\x04hello", $this->serverFile($server, '.stage/mods/hello.jar'));
        self::assertSame('', $this->serverFile($server, '.stage/config/zero.cfg'));
        self::assertSame("#!/bin/sh\necho hi\n", $this->serverFile($server, '.stage/scripts/tool.sh'));
        // Archive preserved on disk (real Wings behavior)
        self::assertTrue($this->hasServerFile($server, '.stage/bundle.zip'));
        $modes = $this->wings->extractedModes($server->uuid);
        self::assertSame(0644, $modes['.stage/mods/hello.jar'] ?? null);
        self::assertSame(0755, $modes['.stage/scripts/tool.sh'] ?? null);
        $this->wings->assertEndpointCalled('decompress', 1);

        // Hostile zip-slip archive rejected with 400 BEFORE extracting anything
        $slipZipPath = $this->trackTempPath(sys_get_temp_dir().'/e2e-selftest-slip-'.bin2hex(random_bytes(4)).'.zip');
        $slipZip = new ZipArchive();
        self::assertTrue($slipZip->open($slipZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $slipZip->addFromString('safe.txt', 'must-not-extract');
        $slipZip->addFromString('../../escaped.txt', 'evil');
        $slipZip->close();

        $this->seedServerFile($server, '.bad/slip.zip', (string) file_get_contents($slipZipPath));
        try {
            $repo->decompressFile('.bad', 'slip.zip')->throw();
            self::fail('Expected HTTP 400 on zip-slip archive.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }
        self::assertFalse($this->hasServerFile($server, '.bad/safe.txt'));
        self::assertFalse($this->hasServerFile($server, 'escaped.txt'));

        // Hostile symlink archive rejected with 400
        $symZipPath = $this->trackTempPath(sys_get_temp_dir().'/e2e-selftest-sym-'.bin2hex(random_bytes(4)).'.zip');
        $symZip = new ZipArchive();
        self::assertTrue($symZip->open($symZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $symZip->addFromString('mods/link.jar', '/etc/passwd');
        $symZip->setExternalAttributesName('mods/link.jar', ZipArchive::OPSYS_UNIX, (0120777 << 16));
        $symZip->close();

        $this->seedServerFile($server, '.bad/sym.zip', (string) file_get_contents($symZipPath));
        try {
            $repo->decompressFile('.bad', 'sym.zip')->throw();
            self::fail('Expected HTTP 400 on symlink archive.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }
    }

    public function test_pull_enforces_disable_remote_download_concurrency_cap_ssrf_and_async_vs_foreground(): void
    {
        $egg = $this->createEgg(['name' => 'Fabric']);
        $server = $this->createServer($egg);
        $repo = $this->app->make(DaemonFileRepository::class)->setServer($server);
        $wingsFs = new WingsRemoteFilesystem();

        $jarBytes = "PK\x03\x04remote-jar-bytes";
        $url = 'https://cdn.modrinth.com/data/test/mod.jar';
        $this->registerRemoteFile($url, $jarBytes);

        // Foreground pull succeeds and returns authentic Stat JSON
        $stat = $wingsFs->pullForeground($repo, $server, $url, 'mods', 'mod.jar');
        self::assertSame('mod.jar', $stat['name'] ?? null);
        self::assertSame(strlen($jarBytes), $stat['size'] ?? null);
        self::assertNotEmpty($stat['modified'] ?? null);
        self::assertSame($jarBytes, $this->serverFile($server, 'mods/mod.jar'));

        // disable_remote_download: true -> HTTP 400
        $this->wings->setDisableRemoteDownload(true);
        try {
            $wingsFs->pullForeground($repo, $server, $url, 'mods', 'mod2.jar');
            self::fail('Expected HTTP 400 when disable_remote_download is true.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }
        $this->wings->setDisableRemoteDownload(false);

        // Max 3 concurrent pulls cap -> HTTP 400
        $this->wings->setActivePullCount($server->uuid, 3);
        try {
            $wingsFs->pullForeground($repo, $server, $url, 'mods', 'mod3.jar');
            self::fail('Expected HTTP 400 when 3 concurrent pulls are active.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
            self::assertStringContainsString('limit of 3', (string) $e->response->json('error'));
        }
        $this->wings->setActivePullCount($server->uuid, 0);

        // Private IP URL rejected with 400
        try {
            $wingsFs->pullForeground($repo, $server, 'https://127.0.0.1/secret.jar', 'mods', 'secret.jar');
            self::fail('Expected HTTP 400 on private IP pull URL.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }

        // Async pull returns 202 and WingsRemoteFilesystem::pullForeground rejects 202
        $this->wings->setForceAsyncPull(true);
        try {
            $wingsFs->pullForeground($repo, $server, $url, 'mods', 'async.jar');
            self::fail('Expected foreground pull to reject 202 async response.');
        } catch (\Exception $e) {
            self::assertStringContainsString('background pull', $e->getMessage());
        }
        $this->wings->setForceAsyncPull(false);
    }

    public function test_node_token_user_agent_fault_injection_range_sink_and_server_lifecycle(): void
    {
        $egg = $this->createEgg(['name' => 'Fabric']);
        $server = $this->createServer($egg);
        $fileRepo = $this->app->make(DaemonFileRepository::class)->setServer($server);
        $serverRepo = $this->app->make(DaemonServerRepository::class)->setServer($server);

        // Missing file returns authentic 404 -> Panel maps to FileNotFoundException (NOT ConnectionException!)
        $caught = null;
        try {
            $fileRepo->getContent('does-not-exist.txt');
        } catch (FileNotFoundException $e) {
            $caught = $e;
        }
        self::assertInstanceOf(FileNotFoundException::class, $caught);

        // Deterministic cURL ConnectionException injection
        $this->wings->injectConnectionError('listDirectory', StrictWingsFake::CURL_ERR_7_REFUSED, 1);
        try {
            $fileRepo->getDirectory('/');
            self::fail('Expected ConnectionException on injected cURL error 7.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('cURL error 7', $e->getMessage());
        }
        // Subsequent call succeeds once injected count is exhausted
        self::assertIsArray($fileRepo->getDirectory('/'));

        // Range 206 vs 200 & byte transfer counters
        $this->seedServerFile($server, 'mods/sample.jar', "PK\x03\x04".str_repeat('X', 2000));
        $this->wings->resetMetrics();
        $this->wings->setRangeResponseEnabled(true);
        $rangeResp = $fileRepo->getHttpClient()
            ->withHeaders(['Range' => 'bytes=0-3', 'Accept-Encoding' => 'identity'])
            ->get("/api/servers/{$server->uuid}/files/contents", ['file' => 'mods/sample.jar']);
        self::assertSame(206, $rangeResp->status());
        self::assertSame("PK\x03\x04", $rangeResp->body());
        self::assertSame(1, $this->wings->count('readPrefix'));
        self::assertSame(4, $this->wings->count('bytesWingsToPanel'));

        // Async + sink compatibility (used by InstalledProjectService parallel scan)
        $sinkFile = $this->trackTempPath(sys_get_temp_dir().'/e2e-sink-'.bin2hex(random_bytes(4)).'.jar');
        $promise = $fileRepo->getHttpClient()
            ->withOptions(['sink' => $sinkFile])
            ->async()
            ->get("/api/servers/{$server->uuid}/files/contents", ['file' => 'mods/sample.jar']);
        $settled = PromiseUtils::settle(['sample' => $promise])->wait();
        self::assertSame('fulfilled', $settled['sample']['state'] ?? null);
        self::assertSame("PK\x03\x04".str_repeat('X', 2000), (string) file_get_contents($sinkFile));

        // Server lifecycle endpoints: details, power, sync, reinstall hook
        $reinstalledUuids = [];
        $this->wings->onReinstall(static function (string $uuid) use (&$reinstalledUuids): void {
            $reinstalledUuids[] = $uuid;
        });
        $this->wings->setServerState($server->uuid, 'running');
        self::assertSame('running', $serverRepo->getDetails()['state'] ?? null);
        $serverRepo->power('stop');
        self::assertSame('offline', $serverRepo->getDetails()['state'] ?? null);
        $serverRepo->sync();
        $serverRepo->reinstall();
        self::assertSame([$server->uuid], $reinstalledUuids);
        $this->wings->assertEndpointCalled('sync', 1);
        $this->wings->assertEndpointCalled('reinstall', 1);
    }

    public function test_final_segment_symlink_sandbox_escapes_are_blocked_while_rename_and_delete_preserve_link_semantics(): void
    {
        $egg = $this->createEgg(['name' => 'Fabric']);
        $server = $this->createServer($egg);
        $repo = $this->app->make(DaemonFileRepository::class)->setServer($server);
        $wingsFs = new WingsRemoteFilesystem();

        $outsideDir = $this->trackTempPath(sys_get_temp_dir().'/e2e-outside-'.bin2hex(random_bytes(6)));
        self::assertTrue(mkdir($outsideDir, 0755, true));
        file_put_contents($outsideDir.'/sentinel.txt', 'ORIGINAL_SECRET');
        file_put_contents($outsideDir.'/dir-secret.txt', 'DIR_SECRET');
        file_put_contents($outsideDir.'/decompress-target.txt', 'ORIG_DECOMPRESS');
        file_put_contents($outsideDir.'/pull-target.txt', 'ORIG_PULL');

        $this->wings->seedSymlink($server->uuid, 'mods/escape-file.txt', $outsideDir.'/sentinel.txt');
        $this->wings->seedSymlink($server->uuid, 'mods/dangling-escape.txt', $outsideDir.'/never-created.txt');
        $this->wings->seedSymlink($server->uuid, 'mods/escape-dir', $outsideDir);
        $this->wings->seedSymlink($server->uuid, '.stage/mods/evil.txt', $outsideDir.'/decompress-target.txt');
        $this->wings->seedSymlink($server->uuid, 'mods/pull-escape.jar', $outsideDir.'/pull-target.txt');

        // 1. POST /files/write to existing external symlink -> HTTP 400, external file untouched
        try {
            $repo->getHttpClient()
                ->withQueryParameters(['file' => 'mods/escape-file.txt'])
                ->withBody('OVERWRITTEN', 'application/octet-stream')
                ->post("/api/servers/{$server->uuid}/files/write");
            self::fail('Expected HTTP 400 when writing to existing external symlink.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }
        self::assertSame('ORIGINAL_SECRET', file_get_contents($outsideDir.'/sentinel.txt'));

        // 2. POST /files/write to dangling external symlink -> HTTP 400, external file never created
        try {
            $repo->getHttpClient()
                ->withQueryParameters(['file' => 'mods/dangling-escape.txt'])
                ->withBody('CREATED_OUTSIDE', 'application/octet-stream')
                ->post("/api/servers/{$server->uuid}/files/write");
            self::fail('Expected HTTP 400 when writing to dangling external symlink.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }
        self::assertFileDoesNotExist($outsideDir.'/never-created.txt');

        // 3. GET /files/list-directory on escaping directory symlink -> HTTP 400 (no leak)
        try {
            $repo->getHttpClient()
                ->get("/api/servers/{$server->uuid}/files/list-directory", ['directory' => 'mods/escape-dir']);
            self::fail('Expected HTTP 400 when listing an escaping directory symlink.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
            self::assertStringNotContainsString('dir-secret.txt', $e->response->body());
        }

        // 4. POST /files/decompress onto pre-existing escaping symlink -> HTTP 400, atomic (no good.txt extracted!)
        $zipPath = $this->trackTempPath(sys_get_temp_dir().'/e2e-sym-decomp-'.bin2hex(random_bytes(4)).'.zip');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('mods/good.txt', 'must-not-be-extracted');
        $zip->addFromString('mods/evil.txt', 'overwrite-outside');
        $zip->close();
        $this->seedServerFile($server, '.stage/attack.zip', (string) file_get_contents($zipPath));

        try {
            $repo->decompressFile('.stage', 'attack.zip')->throw();
            self::fail('Expected HTTP 400 when decompressing over an escaping final-segment symlink.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }
        self::assertSame('ORIG_DECOMPRESS', file_get_contents($outsideDir.'/decompress-target.txt'));
        self::assertFalse($this->hasServerFile($server, '.stage/mods/good.txt'));

        // 5. POST /files/pull onto escaping final-segment symlink -> HTTP 400, external file untouched
        $pullUrl = 'https://cdn.modrinth.com/data/symtest/pull-escape.jar';
        $this->registerRemoteFile($pullUrl, "PK\x03\x04pull-overwrite");
        try {
            $wingsFs->pullForeground($repo, $server, $pullUrl, 'mods', 'pull-escape.jar');
            self::fail('Expected HTTP 400 when pulling onto an escaping final-segment symlink.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }
        self::assertSame('ORIG_PULL', file_get_contents($outsideDir.'/pull-target.txt'));

        // 6. PUT /files/rename onto dangling symlink preserves 400 os.ErrExist
        $this->seedServerFile($server, 'mods/source.txt', 'src');
        try {
            $repo->renameFiles('mods', [['from' => 'source.txt', 'to' => 'dangling-escape.txt']])->throw();
            self::fail('Expected HTTP 400 os.ErrExist when renaming onto a dangling symlink.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
            self::assertStringContainsString('already exists', (string) $e->response->json('error'));
        }

        // 7. POST /files/delete unlinks the symlink itself (204) without touching external target
        $deleteResp = $repo->deleteFiles('mods', ['escape-file.txt']);
        self::assertSame(204, $deleteResp->status());
        self::assertFalse($this->wings->hasPath($server->uuid, 'mods/escape-file.txt'));
        self::assertFileExists($outsideDir.'/sentinel.txt');
        self::assertSame('ORIGINAL_SECRET', file_get_contents($outsideDir.'/sentinel.txt'));
    }

    public function test_decompress_rejects_raw_nul_bytes_trailing_dotdot_and_corrupt_deflate_or_crc_atomically(): void
    {
        $egg = $this->createEgg(['name' => 'NeoForge']);
        $server = $this->createServer($egg);
        $repo = $this->app->make(DaemonFileRepository::class)->setServer($server);

        // 1A. Genuine NUL byte in both Local File Header and Central Directory -> HTTP 400
        $nulBasePath = $this->trackTempPath(sys_get_temp_dir().'/e2e-nul-base-'.bin2hex(random_bytes(4)).'.zip');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($nulBasePath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('mods/goodXevil.jar', "PK\x03\x04payload");
        $zip->close();
        $baseZipBytes = (string) file_get_contents($nulBasePath);

        $bothNulBytes = str_replace('goodXevil', "good\0evil", $baseZipBytes);
        $this->seedServerFile($server, '.bad/nul-both.zip', $bothNulBytes);
        try {
            $repo->decompressFile('.bad', 'nul-both.zip')->throw();
            self::fail('Expected HTTP 400 on ZIP containing raw NUL byte in entry name.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }
        self::assertFalse($this->hasServerFile($server, '.bad/mods/good evil.jar'));

        // 1B. NUL byte in Central Directory only -> HTTP 400
        $cdMarkerPos = strrpos($baseZipBytes, 'goodXevil');
        self::assertIsInt($cdMarkerPos);
        $cdOnlyNulBytes = substr($baseZipBytes, 0, $cdMarkerPos)."good\0evil".substr($baseZipBytes, $cdMarkerPos + 9);
        $this->seedServerFile($server, '.bad/nul-cd.zip', $cdOnlyNulBytes);
        try {
            $repo->decompressFile('.bad', 'nul-cd.zip')->throw();
            self::fail('Expected HTTP 400 on ZIP containing raw NUL byte in Central Directory entry name.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }

        // 2. Trailing "/.." and "\.." traversal segments -> HTTP 400, atomic (safe.txt NOT extracted)
        foreach (['a/b/..', 'a\\b\\..'] as $idx => $badTrailingName) {
            $trailPath = $this->trackTempPath(sys_get_temp_dir()."/e2e-trail-{$idx}-".bin2hex(random_bytes(4)).'.zip');
            $trailZip = new ZipArchive();
            self::assertTrue($trailZip->open($trailPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $trailZip->addFromString("safe-{$idx}.txt", 'must-not-extract');
            $trailZip->addFromString($badTrailingName, 'traversal');
            $trailZip->close();

            $this->seedServerFile($server, ".bad/trail-{$idx}.zip", (string) file_get_contents($trailPath));
            try {
                $repo->decompressFile('.bad', "trail-{$idx}.zip")->throw();
                self::fail("Expected HTTP 400 on trailing dotdot ZIP entry [{$badTrailingName}].");
            } catch (RequestException $e) {
                self::assertSame(400, $e->response->status());
            }
            self::assertFalse($this->hasServerFile($server, ".bad/safe-{$idx}.txt"));
            self::assertFalse($this->wings->hasPath($server->uuid, '.bad/a'));
        }

        // 3. Corrupt DEFLATE stream AND CRC32 mismatch on entry #2 -> HTTP 400 with ZERO partial extraction of entry #1
        $deflateBasePath = $this->trackTempPath(sys_get_temp_dir().'/e2e-deflate-'.bin2hex(random_bytes(4)).'.zip');
        $dZip = new ZipArchive();
        self::assertTrue($dZip->open($deflateBasePath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $dZip->addFromString('mods/entry1.txt', str_repeat('A', 2048));
        $dZip->addFromString('mods/entry2.txt', str_repeat('B', 2048));
        $dZip->close();
        $deflateBytes = (string) file_get_contents($deflateBasePath);

        $lfh2 = strrpos($deflateBytes, "PK\x03\x04");
        $cd2 = strrpos($deflateBytes, "PK\x01\x02");
        self::assertIsInt($lfh2);
        self::assertIsInt($cd2);
        $lfhLens = unpack('vnameLen/vextraLen', substr($deflateBytes, $lfh2 + 26, 4));
        self::assertIsArray($lfhLens);
        $entry2DataOffset = $lfh2 + 30 + (int) $lfhLens['nameLen'] + (int) $lfhLens['extraLen'];

        // Case A: Invalid DEFLATE stream header bytes (0xFF 0xFF)
        $corruptDeflate = $deflateBytes;
        $corruptDeflate[$entry2DataOffset] = "\xFF";
        $corruptDeflate[$entry2DataOffset + 1] = "\xFF";
        $this->seedServerFile($server, '.bad/corrupt-deflate.zip', $corruptDeflate);
        try {
            $repo->decompressFile('.bad', 'corrupt-deflate.zip')->throw();
            self::fail('Expected HTTP 400 on corrupt DEFLATE stream.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }
        self::assertFalse($this->hasServerFile($server, '.bad/mods/entry1.txt'));

        // Case B: Decodable DEFLATE payload with mismatched CRC32 in both LFH and CD
        $badCrc = $deflateBytes;
        $flippedByte = chr(ord($badCrc[$lfh2 + 14]) ^ 0xFF);
        $badCrc[$lfh2 + 14] = $flippedByte;
        $badCrc[$cd2 + 16] = $flippedByte;
        $this->seedServerFile($server, '.bad/bad-crc.zip', $badCrc);
        try {
            $repo->decompressFile('.bad', 'bad-crc.zip')->throw();
            self::fail('Expected HTTP 400 on CRC32 mismatch.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }
        self::assertFalse($this->hasServerFile($server, '.bad/mods/entry1.txt'));
    }

    public function test_fault_injection_path_contains_matches_url_encoded_slash_queries(): void
    {
        $egg = $this->createEgg(['name' => 'Fabric']);
        $server = $this->createServer($egg);
        $repo = $this->app->make(DaemonFileRepository::class)->setServer($server);

        $this->seedServerFile($server, 'mods/target.jar', 'target-bytes');
        $this->seedServerFile($server, 'mods/other.jar', 'other-bytes');

        $this->wings->injectHttpError('readStream', 500, ['error' => 'target fault'], 1, 'mods/target.jar');

        // Unrelated path succeeds
        self::assertSame('other-bytes', $repo->getContent('mods/other.jar'));

        // URL-encoded query (?file=mods%2Ftarget.jar) triggers the injected HTTP 500
        try {
            $repo->getHttpClient()
                ->get("/api/servers/{$server->uuid}/files/contents", ['file' => 'mods/target.jar'])
                ->throw();
            self::fail('Expected HTTP 500 on URL-encoded mods/target.jar readStream.');
        } catch (RequestException $e) {
            self::assertSame(500, $e->response->status());
        }

        // Also works for putStream ConnectionException with nested path
        $this->wings->injectConnectionError('putStream', StrictWingsFake::CURL_ERR_7_REFUSED, 1, 'mods/nested/fail.jar');
        try {
            $repo->putContent('mods/nested/fail.jar', 'payload');
            self::fail('Expected ConnectionException on URL-encoded mods/nested/fail.jar putStream.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('cURL error 7', $e->getMessage());
        }
    }

    public function test_pull_rejects_directory_conflict_extended_wings_ssrf_prefixes_and_non_canonical_ipv4_and_unregister(): void
    {
        $egg = $this->createEgg(['name' => 'Fabric']);
        $server = $this->createServer($egg);
        $repo = $this->app->make(DaemonFileRepository::class)->setServer($server);
        $wingsFs = new WingsRemoteFilesystem();

        // 1. Directory conflict on pull destination -> clean HTTP 400 (no ErrorException)
        $this->wings->seedDirectory($server->uuid, 'mods/existing-dir');
        $okUrl = 'https://cdn.modrinth.com/data/ok/m.jar';
        $this->registerRemoteFile($okUrl, "PK\x03\x04valid");
        try {
            $wingsFs->pullForeground($repo, $server, $okUrl, 'mods', 'existing-dir');
            self::fail('Expected HTTP 400 when pull destination conflicts with an existing directory.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }

        // 2. Non-directory ancestor conflict -> clean HTTP 400
        $this->seedServerFile($server, 'mods/not-a-dir', 'file');
        try {
            $wingsFs->pullForeground($repo, $server, $okUrl, 'mods/not-a-dir', 'child.jar');
            self::fail('Expected HTTP 400 when pull destination has a non-directory ancestor.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
        }

        // 3. Extended Wings internal/network/address.go SSRF prefixes + non-canonical IPv4 + zone + trailing-dot
        $blockedUrls = [
            'https://100.64.0.1/m.jar',
            'https://192.0.2.1/m.jar',
            'https://198.18.0.1/m.jar',
            'https://198.51.100.1/m.jar',
            'https://203.0.113.1/m.jar',
            'https://[2001:db8::1]/m.jar',
            'https://[::ffff:100.64.0.1]/m.jar',
            'https://[64:ff9b::100.64.0.1]/m.jar',
            'https://[fe80::1%25eth0]/m.jar',
            'https://127.1/m.jar',
            'https://2130706433/m.jar',
            'https://0x7f000001/m.jar',
            'https://0177.0.0.1/m.jar',
            'https://localhost./m.jar',
        ];
        foreach ($blockedUrls as $blockedUrl) {
            $this->registerRemoteFile($blockedUrl, "PK\x03\x04blocked");
            try {
                $wingsFs->pullForeground($repo, $server, $blockedUrl, 'mods', 'blocked.jar');
                self::fail("Expected HTTP 400 for blocked SSRF pull URL [{$blockedUrl}].");
            } catch (RequestException $e) {
                self::assertSame(400, $e->response->status(), "URL [{$blockedUrl}] must be rejected with 400.");
            }
        }

        // 4. Valid public IPv4 and public hostname succeed (200)
        $publicIpUrl = 'https://1.1.1.1/m.jar';
        $this->registerRemoteFile($publicIpUrl, "PK\x03\x04pub-ip");
        $statIp = $wingsFs->pullForeground($repo, $server, $publicIpUrl, 'mods', 'pub-ip.jar');
        self::assertSame('pub-ip.jar', $statIp['name'] ?? null);
        $statHost = $wingsFs->pullForeground($repo, $server, $okUrl, 'mods', 'pub-host.jar');
        self::assertSame('pub-host.jar', $statHost['name'] ?? null);

        // 5. unregisterRemoteFile removes fixture from BOTH Panel Http::fake() AND StrictWingsFake
        $this->unregisterRemoteFile($okUrl);
        try {
            $wingsFs->pullForeground($repo, $server, $okUrl, 'mods', 'after-unreg.jar');
            self::fail('Expected HTTP 400 after unregisterRemoteFile() removed pull fixture.');
        } catch (RequestException $e) {
            self::assertSame(400, $e->response->status());
            self::assertStringContainsString('Failed to download remote file.', (string) $e->response->json('error'));
        }
        try {
            Http::get($okUrl);
            self::fail('Expected Http::preventStrayRequests() error after unregisterRemoteFile().');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Attempted request to', $e->getMessage());
        }
    }

    public function test_html5_dom_assert_detects_nested_anchor_adoption_agency_fragmentation(): void
    {
        // Broken nested <a> inside <a class="fi-ta-record-content"> triggers WHATWG Adoption Agency split -> 2 anchors!
        $brokenHtml = '<div class="fi-ta-content-grid"><a class="fi-ta-record-content" href="https://modrinth.com/modpack/fo" target="_blank"><div class="fi-ta-stack"><a href="https://modrinth.com/user/robotkoer">robotkoer</a></div></a></div>';
        $doc = Html5DomAssert::parse($brokenHtml);
        self::assertSame(2, $doc->querySelectorAll('a.fi-ta-record-content')->length);

        // 1. Direct nested <a> (sibling <a> without cloning .fi-ta-record-content) must FAIL
        $directNestedHtml = '<div class="fi-ta-content-grid"><a class="fi-ta-record-content" href="https://modrinth.com/mod/a" target="_blank"><a href="https://example.com/nested">nested</a></a></div>';
        $this->assertDomAssertionFails(static fn () => Html5DomAssert::assertSingleAnchorPerTileCard($directNestedHtml, 1));

        // 2. 2+0 card cancellation across 2 .fi-ta-record cards must FAIL
        $cancellationHtml = '<div class="fi-ta-content-grid">'
            .'<div class="fi-ta-record"><a class="fi-ta-record-content" href="https://modrinth.com/mod/a" target="_blank"><div class="fi-ta-stack"><a href="https://modrinth.com/user/foo">foo</a></div></a></div>'
            .'<div class="fi-ta-record"><div class="fi-ta-record-content">no anchor</div></div>'
            .'</div>';
        $this->assertDomAssertionFails(static fn () => Html5DomAssert::assertSingleAnchorPerTileCard($cancellationHtml, 2));

        // 3. Protocol-relative and whitespace-padded Modrinth author links must FAIL
        $this->assertDomAssertionFails(static fn () => Html5DomAssert::assertNoModrinthAuthorLinks('<a href="//modrinth.com/user/foo">foo</a>'));
        $this->assertDomAssertionFails(static fn () => Html5DomAssert::assertNoModrinthAuthorLinks('<a href=" https://modrinth.com/user/bar ">bar</a>'));

        // 4. Missing author row & 1-item .fi-ta-split must FAIL
        $missingAuthorRowHtml = '<div class="fi-ta-content-grid"><a class="fi-ta-record-content" href="https://modrinth.com/mod/a" target="_blank"><div class="fi-ta-stack"><div class="fi-ta-stack"><div class="fi-ta-split"><div class="fi-align-end">Oct 1</div></div></div></div></a></div>';
        $this->assertDomAssertionFails(static fn () => Html5DomAssert::assertPureFilamentTileMetadataLayout($missingAuthorRowHtml, 1));

        // Clean pure Filament tile markup (fragment) passes all Html5DomAssert checks
        $cleanHtml = '<div class="fi-ta-content-grid"><a class="fi-ta-record-content" href="https://modrinth.com/modpack/fo" target="_blank"><div class="fi-ta-stack"><div class="fi-ta-split"><div>icon</div><div>title</div></div><div>desc</div><div class="fi-ta-stack"><div>robotkoer</div><div class="fi-ta-split"><div>1,234</div><div class="fi-align-end">Oct 1</div></div></div></div></a></div>';
        Html5DomAssert::assertNoModrinthAuthorLinks($cleanHtml);
        Html5DomAssert::assertExternalProjectLinkPresent($cleanHtml, 'https://modrinth.com/modpack/fo');
        Html5DomAssert::assertSingleAnchorPerTileCard($cleanHtml, 1);
        Html5DomAssert::assertPureFilamentTileMetadataLayout($cleanHtml, 1);

        // Valid .fi-ta-record card with an <a> inside .fi-ta-actions AND empty-author Filament 5 structure passes
        $filamentCardWithActionLink = '<div class="fi-ta-content-grid"><div class="fi-ta-record">'
            .'<a class="fi-ta-record-content" href="https://atlauncher.com/pack/NullAuthorPack" target="_blank">'
            .'<div class="fi-ta-stack"><div class="fi-ta-split"><div>icon</div><div>title</div></div><div>desc</div>'
            .'<div class="fi-ta-stack"><div class="fi-grid-col fi-ta-col"></div><div class="fi-grid-col"><div class="fi-ta-split"><div class="fi-grid-col fi-ta-col"></div><div class="fi-grid-col fi-ta-col fi-align-end"></div></div></div></div>'
            .'</div></a>'
            .'<div class="fi-ta-actions"><a href="https://example.test/install">Install</a></div>'
            .'</div></div>';
        Html5DomAssert::assertNoModrinthAuthorLinks($filamentCardWithActionLink);
        Html5DomAssert::assertSingleAnchorPerTileCard($filamentCardWithActionLink, 1);
        Html5DomAssert::assertPureFilamentTileMetadataLayout($filamentCardWithActionLink, 1);
    }

    private function assertDomAssertionFails(callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected Html5DomAssert check to fail.');
        } catch (AssertionFailedError $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    private function makeUnknownSizeStream(string $content): StreamInterface
    {
        $inner = Utils::streamFor($content);

        return new class($inner) implements StreamInterface
        {
            public function __construct(private readonly StreamInterface $inner) {}

            public function __toString(): string
            {
                return (string) $this->inner;
            }

            public function close(): void
            {
                $this->inner->close();
            }

            public function detach()
            {
                return $this->inner->detach();
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return $this->inner->tell();
            }

            public function eof(): bool
            {
                return $this->inner->eof();
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                throw new RuntimeException('Stream is not seekable.');
            }

            public function rewind(): void
            {
                throw new RuntimeException('Stream is not seekable.');
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new RuntimeException('Stream is not writable.');
            }

            public function isReadable(): bool
            {
                return $this->inner->isReadable();
            }

            public function read(int $length): string
            {
                return $this->inner->read($length);
            }

            public function getContents(): string
            {
                return $this->inner->getContents();
            }

            public function getMetadata(?string $key = null)
            {
                return $this->inner->getMetadata($key);
            }
        };
    }
}
