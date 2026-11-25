<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier1;

use App\Repositories\Daemon\DaemonFileRepository;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Http\Controllers\ModManagerAssetController;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Jobs\BulkUpdateInstalledProjects;
use Kazaminosuke\ModManager\Jobs\WarmCatalogSearch;
use Kazaminosuke\ModManager\Services\InstalledArchiveTransaction;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectUpdateService;
use Kazaminosuke\ModManager\Services\ModManagerDiagnostics;
use Kazaminosuke\ModManager\Sources\AtlauncherModpackSource;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\FtbModpackSource;
use Kazaminosuke\ModManager\Sources\GitHubReleasesSource;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Sources\SpigotSource;
use Kazaminosuke\ModManager\Sources\TechnicModpackSource;
use Kazaminosuke\ModManager\Support\BackgroundJobRuntime;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\DatabaseBackgroundJobQueue;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\ModManagerAssets;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Kazaminosuke\ModManager\Support\UpstreamHttp;
use Kazaminosuke\ModManager\Support\WarmRequestThrottle;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use Psr\Http\Message\StreamInterface;
use ReflectionMethod;
use ZipArchive;

/**
 * Tier 1 — Isolated Feature Coverage for Core Features #9, #10, #11, #12 (M2).
 */
final class CoreHttpWingsCacheAndSchedE2ETest extends E2ETestCase
{
    public function test_upstream_http_sends_descriptive_user_agent_gzip_and_hangar_refreshes_401_jwt(): void
    {
        self::assertTrue(
            method_exists(UpstreamHttp::class, 'userAgent'),
            'M2 (#9): UpstreamHttp::userAgent() must exist and expose descriptive plugin User-Agent.',
        );
        $ua = (new ReflectionMethod(UpstreamHttp::class, 'userAgent'))->invoke(null);
        self::assertIsString($ua);
        self::assertStringContainsString('pelican-mod-manager/', $ua);
        self::assertStringContainsString('https://github.com/kazaminosuke/pelican-mod-manager', $ua);

        $seenHeaders = [];
        $hangarAuthCalls = 0;
        $hangarProjectCalls = 0;
        $hangarAuthHeaders = [];
        $this->fakeUpstreamHttp([
            'api.modrinth.com/*' => static function (HttpClientRequest $req) use (&$seenHeaders) {
                $seenHeaders['modrinth'] = [
                    'ua' => (string) ($req->header('User-Agent')[0] ?? ''),
                    'encoding' => (string) ($req->header('Accept-Encoding')[0] ?? ''),
                ];

                return Http::response(['hits' => [], 'total_hits' => 0], 200);
            },
            'hangar.papermc.io/api/v1/authenticate*' => static function (HttpClientRequest $req) use (&$seenHeaders, &$hangarAuthCalls) {
                $seenHeaders['hangar_auth'] = [
                    'ua' => (string) ($req->header('User-Agent')[0] ?? ''),
                    'encoding' => (string) ($req->header('Accept-Encoding')[0] ?? ''),
                ];
                $hangarAuthCalls++;
                $token = $hangarAuthCalls === 1 ? 'jwt-token-1' : 'jwt-token-2';

                return Http::response(['token' => $token, 'expiresIn' => 3600000], 200);
            },
            'hangar.papermc.io/api/v1/projects*' => static function (HttpClientRequest $req) use (&$seenHeaders, &$hangarProjectCalls, &$hangarAuthHeaders) {
                $seenHeaders['hangar_projects'] = [
                    'ua' => (string) ($req->header('User-Agent')[0] ?? ''),
                    'encoding' => (string) ($req->header('Accept-Encoding')[0] ?? ''),
                ];
                $hangarProjectCalls++;
                $authHeader = (string) ($req->header('Authorization')[0] ?? '');
                $hangarAuthHeaders[] = $authHeader;

                if ($authHeader === 'HangarAuth jwt-token-1') {
                    return Http::response(['message' => 'Unauthorized'], 401);
                }

                if ($authHeader === 'HangarAuth jwt-token-2') {
                    return Http::response([
                        'pagination' => ['count' => 1],
                        'result' => [[
                            'name' => 'WorldEdit',
                            'namespace' => ['owner' => 'EngineHub', 'slug' => 'WorldEdit'],
                            'description' => 'Fast in-game map editor.',
                            'stats' => ['downloads' => 500000],
                            'lastUpdated' => '2026-10-01T00:00:00Z',
                            'avatarUrl' => null,
                        ]],
                    ], 200);
                }

                return Http::response(['message' => 'Missing HangarAuth'], 401);
            },
            'api.atlauncher.com/*' => static function (HttpClientRequest $req) use (&$seenHeaders) {
                $seenHeaders['atlauncher'] = [
                    'ua' => (string) ($req->header('User-Agent')[0] ?? ''),
                    'encoding' => (string) ($req->header('Accept-Encoding')[0] ?? ''),
                ];

                return Http::response(['data' => []], 200);
            },
        ]);

        // 1. Direct UpstreamHttp::json() (0-arg for baseline + M2 compatibility)
        UpstreamHttp::json()->get('https://api.modrinth.com/v2/search');

        // 2. Opaque-box Hangar 401 JWT refresh via 2-token swap (zero private cache-key coupling)
        config(['pelican-mod-manager.hangar_api_key' => 'hangar-test-key']);
        $paperEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::paper(30));
        $paperServer = $this->createServer($paperEgg, variableValues: ['MINECRAFT_VERSION' => '1.21.1']);

        /** @var HangarSource $hangar */
        $hangar = $this->app->make(HangarSource::class);
        $result = $hangar->search($paperServer, ProjectType::Plugin, 1, 'worldedit');
        self::assertSame(2, $hangarAuthCalls, 'M2 (#9): HangarSource must re-authenticate once after 401.');
        self::assertSame(2, $hangarProjectCalls, 'M2 (#9): HangarSource must retry /projects after refreshing JWT.');
        self::assertSame(['HangarAuth jwt-token-1', 'HangarAuth jwt-token-2'], $hangarAuthHeaders);
        self::assertNotEmpty($result['hits'] ?? [], 'M2 (#9): Retried Hangar search must return results.');

        // 3. ATLauncher source also routes through shared UpstreamHttp (UA + gzip)
        /** @var AtlauncherModpackSource $atlauncher */
        $atlauncher = $this->app->make(AtlauncherModpackSource::class);
        $atlauncher->search('sevtech');

        foreach (['modrinth', 'hangar_auth', 'hangar_projects', 'atlauncher'] as $sourceTag) {
            self::assertArrayHasKey($sourceTag, $seenHeaders, "Expected upstream request for [{$sourceTag}].");
            self::assertStringContainsString(
                'pelican-mod-manager/',
                $seenHeaders[$sourceTag]['ua'],
                "M2 (#9): [{$sourceTag}] must send descriptive plugin User-Agent.",
            );
            self::assertStringContainsString(
                'gzip',
                strtolower($seenHeaders[$sourceTag]['encoding']),
                "M2 (#9): [{$sourceTag}] must advertise gzip Accept-Encoding.",
            );
        }
    }

    public function test_wings_remote_filesystem_supports_explicit_content_length_range_prefix_decompress_and_batch_ops(): void
    {
        $egg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(31));
        $server = $this->createServer($egg);
        /** @var DaemonFileRepository $repo */
        $repo = $this->app->make(DaemonFileRepository::class);
        /** @var WingsRemoteFilesystem $wingsFs */
        $wingsFs = $this->app->make(WingsRemoteFilesystem::class);

        // 1. putStream() with unknown-size non-seekable stream must succeed against StrictWingsFake (explicit Content-Length)
        $payload = 'streamed-without-known-size-payload';
        $unknownStream = $this->unknownSizeStream($payload);
        try {
            $wingsFs->putStream($repo, $server, 'mods/streamed.jar', $unknownStream);
        } catch (RequestException $e) {
            self::fail('M2 (#10): WingsRemoteFilesystem::putStream() must send explicit Content-Length on unknown-size streams instead of failing with HTTP '.$e->response->status().': '.$e->response->body());
        }
        self::assertSame($payload, $this->serverFile($server, 'mods/streamed.jar'));

        // 2. readPrefix() sends Range: bytes=0-3 + Accept-Encoding: identity and transfers <= 4 bytes on 1 MiB file
        $bigJar = "PK\x03\x04".str_repeat('Z', 1024 * 1024);
        $this->seedServerFile($server, 'mods/big.jar', $bigJar);
        $this->wings->setRangeResponseEnabled(true);
        $this->wings->resetMetrics();

        $prefix = $wingsFs->readPrefix($repo, $server, 'mods', 'big.jar', 4);
        self::assertSame("PK\x03\x04", $prefix);
        self::assertSame(1, $this->wings->count('readPrefix'), 'M2 (#10): readPrefix() must send Range: bytes=0-3.');
        $recorded = $this->wings->recordedRequests('readPrefix');
        $headersLower = array_change_key_case($recorded[0]['headers'], CASE_LOWER);
        self::assertSame('bytes=0-3', $headersLower['range'][0] ?? null);
        self::assertSame('identity', strtolower((string) ($headersLower['accept-encoding'][0] ?? '')));
        $this->wings->assertBytesWingsToPanelAtMost(4);

        // 3. decompressFile(), renameBatch(), deleteBatch() exist and batch up to 200 items/call
        self::assertTrue(method_exists($wingsFs, 'decompressFile'), 'M2 (#10): WingsRemoteFilesystem::decompressFile() must exist.');
        self::assertTrue(method_exists($wingsFs, 'renameBatch'), 'M2 (#10): WingsRemoteFilesystem::renameBatch() must exist.');
        self::assertTrue(method_exists($wingsFs, 'deleteBatch'), 'M2 (#10): WingsRemoteFilesystem::deleteBatch() must exist.');

        $zipFile = $this->trackTempPath(sys_get_temp_dir().'/e2e-batch-'.bin2hex(random_bytes(4)).'.zip');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('a.txt', 'hello-a');
        $zip->addFromString('sub/b.txt', 'hello-b');
        $zip->close();
        $this->seedServerFile($server, '.stage/part.zip', (string) file_get_contents($zipFile));

        (new ReflectionMethod($wingsFs, 'decompressFile'))->invoke($wingsFs, $repo, $server, '.stage', 'part.zip');
        self::assertSame('hello-a', $this->serverFile($server, '.stage/a.txt'));
        self::assertSame('hello-b', $this->serverFile($server, '.stage/sub/b.txt'));

        // Batch rename 450 files -> 3 HTTP calls (200 + 200 + 50)
        $renamePairs = [];
        $deleteNames = [];
        for ($i = 0; $i < 450; $i++) {
            $src = sprintf('old-%03d.jar', $i);
            $dst = sprintf('new-%03d.jar', $i);
            $this->seedServerFile($server, "mods/{$src}", "jar-{$i}");
            $renamePairs[] = ['from' => $src, 'to' => $dst];
            $deleteNames[] = $dst;
        }

        $this->wings->resetMetrics();
        (new ReflectionMethod($wingsFs, 'renameBatch'))->invoke($wingsFs, $repo, $server, 'mods', $renamePairs);
        $this->wings->assertEndpointCalled('rename', 3, 'M2 (#10): 450 renames must batch into 3 calls (200+200+50).');
        self::assertTrue($this->hasServerFile($server, 'mods/new-000.jar'));
        self::assertTrue($this->hasServerFile($server, 'mods/new-449.jar'));

        $this->wings->resetMetrics();
        (new ReflectionMethod($wingsFs, 'deleteBatch'))->invoke($wingsFs, $repo, $server, 'mods', $deleteNames);
        $this->wings->assertEndpointCalled('delete', 3, 'M2 (#10): 450 deletes must batch into 3 calls (200+200+50).');
        self::assertFalse($this->hasServerFile($server, 'mods/new-000.jar'));
        self::assertFalse($this->hasServerFile($server, 'mods/new-449.jar'));
    }

    public function test_source_cache_clears_cli_failure_marker_caches_modpack_lists_and_trims_modrinth_project(): void
    {
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        $this->app->forgetInstance(InstalledOperationManager::class);
        $this->app->forgetInstance(SourceCache::class);

        $upstreamState = ['fail' => true];
        $atCalls = 0;
        $technicCalls = 0;
        $ftbSearchCalls = 0;
        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/project/cliMarkerTest' => static function () use (&$upstreamState) {
                if ($upstreamState['fail']) {
                    return Http::response(['error' => 'upstream down'], 500);
                }

                return Http::response([
                    'id' => 'cliMarkerTest',
                    'slug' => 'cli-marker-test',
                    'title' => 'Recovered',
                    'description' => 'Recovered project',
                    'downloads' => 10,
                    'updated' => '2026-10-01T00:00:00Z',
                ], 200);
            },
            'api.atlauncher.com/v1/packs/simple' => static function () use (&$atCalls) {
                $atCalls++;

                return Http::response([
                    'data' => [
                        ['id' => 1, 'type' => 'public', 'safeName' => 'SevTechAges', 'name' => 'SevTech: Ages', 'description' => 'Progression pack'],
                    ],
                ], 200);
            },
            'api.technicpack.net/search*' => static function () use (&$technicCalls) {
                $technicCalls++;

                return Http::response([
                    'modpacks' => [
                        ['slug' => 'tekkit-2', 'name' => 'Tekkit 2'],
                    ],
                ], 200);
            },
            'api.technicpack.net/modpack/tekkit-2*' => Http::response([
                'name' => 'tekkit-2',
                'displayName' => 'Tekkit 2',
                'version' => '1.2.2',
                'minecraft' => '1.12.2',
                'serverPackUrl' => 'https://servers.technicpack.net/Technic/servers/tekkit-2/Tekkit-2_Server_v1.2.2.zip',
            ], 200),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/search/*' => static function () use (&$ftbSearchCalls) {
                $ftbSearchCalls++;

                return Http::response(['packs' => [135]], 200);
            },
            'api.feed-the-beast.com/v1/modpacks/public/modpack/135' => Http::response([
                'id' => 135,
                'name' => 'FTB Evolution',
                'synopsis' => 'FTB test pack',
                'type' => 'Release',
                'installs' => 10000,
                'authors' => [['name' => 'FTB Team']],
                'versions' => [[
                    'id' => 100634,
                    'name' => '1.0.0',
                    'type' => 'Release',
                    'released' => 1760000000,
                    'targets' => [
                        ['type' => 'game', 'name' => 'minecraft', 'version' => '1.21.1'],
                        ['type' => 'modloader', 'name' => 'neoforge', 'version' => '21.1.266'],
                    ],
                ]],
            ], 200),
            'api.modrinth.com/v2/project/hugeproj' => Http::response([
                'id' => 'hugeproj',
                'slug' => 'huge-proj',
                'title' => 'Huge Project',
                'description' => 'Short summary',
                'body' => str_repeat('# Huge Markdown Documentation Body ', 2000),
                'gallery' => array_fill(0, 20, ['url' => 'https://cdn.modrinth.com/img.png', 'description' => 'shot']),
                'versions' => array_map(static fn (int $i): string => 'ver'.$i, range(1, 300)),
                'downloads' => 5000,
                'updated' => '2026-10-01T00:00:00Z',
                'team' => 'team123',
            ], 200),
            'api.modrinth.com/v2/team/team123/members' => Http::response([
                ['role' => 'Owner', 'user' => ['username' => 'devauthor']],
            ], 200),
        ]);

        // 1. Trigger a REAL failure marker via SourceCache::swr() against HTTP 500 (Request #1)
        $spec = new SourceFetchSpec(
            sourceKey: 'modrinth',
            operation: 'project',
            arguments: ['project_id' => 'cliMarkerTest'],
        );
        $this->app->instance('request', Request::create('/http-1', 'GET'));
        /** @var SourceCache $httpCache */
        $httpCache = $this->app->make(SourceCache::class);
        $httpCache->swr($spec, CacheProfile::ProjectMetadata);

        // Verify failure marker is genuinely active on a fresh observer request
        $this->app->instance('request', Request::create('/http-1b', 'GET'));
        $this->app->forgetInstance(SourceCache::class);
        /** @var SourceCache $probeCache */
        $probeCache = $this->app->make(SourceCache::class);
        $beforeRecovery = $probeCache->peek($spec);
        self::assertFalse($beforeRecovery['hit']);
        self::assertTrue($beforeRecovery['retry_delayed'], 'Failed swr() must record an active retry-delay failure marker.');

        // Simulate separate CLI worker process with fresh request & empty memos recovering via primeMany()
        $upstreamState['fail'] = false;
        $this->app->instance('request', Request::create('/cli-worker', 'GET'));
        BackgroundJobRuntime::forgetRequestState();
        $this->app->forgetInstance(SourceCache::class);
        /** @var SourceCache $cliCache */
        $cliCache = $this->app->make(SourceCache::class);
        $cliCache->primeMany(
            [['spec' => $spec, 'data' => ['id' => 'cliMarkerTest', 'title' => 'Recovered']]],
            CacheProfile::ProjectMetadata,
        );

        // Fresh observer request: entry is cached AND revalidateAsync() succeeds (proving failure marker was forgotten!)
        $this->app->instance('request', Request::create('/http-2', 'GET'));
        $this->app->forgetInstance(SourceCache::class);
        /** @var SourceCache $observerCache */
        $observerCache = $this->app->make(SourceCache::class);
        $afterRecovery = $observerCache->peek($spec);
        self::assertTrue($afterRecovery['hit']);
        self::assertSame('Recovered', $afterRecovery['data']['title'] ?? null);
        self::assertTrue(
            $observerCache->revalidateAsync($spec, CacheProfile::ProjectMetadata),
            'M2 (#11): CLI SourceCache write must forget pre-existing cross-request failure marker so subsequent async revalidation is not blocked.',
        );

        // 2. ATLauncher, Technic & FTB modpack lists are cached across repeated identical searches
        /** @var AtlauncherModpackSource $atlauncher */
        $atlauncher = $this->app->make(AtlauncherModpackSource::class);
        $atlauncher->search('sevtech');
        $atlauncher->search('sevtech');
        self::assertSame(1, $atCalls, 'M2 (#11): AtlauncherModpackSource::search() must cache pack list across repeated calls.');

        /** @var TechnicModpackSource $technic */
        $technic = $this->app->make(TechnicModpackSource::class);
        $technic->search('tekkit');
        $technic->search('tekkit');
        self::assertSame(1, $technicCalls, 'M2 (#11): TechnicModpackSource::search() must cache repeated searches.');

        /** @var FtbModpackSource $ftb */
        $ftb = $this->app->make(FtbModpackSource::class);
        $ftbFirst = $ftb->search('evolution');
        $ftbSecond = $ftb->search('evolution');
        self::assertCount(1, $ftbFirst);
        self::assertCount(1, $ftbSecond);
        self::assertSame(1, $ftbSearchCalls, 'M2 (#11): FtbModpackSource::search() must cache repeated searches.');

        // 3. ModrinthSource::getProject() trims huge unused body/gallery/versions before caching
        /** @var ModrinthSource $modrinth */
        $modrinth = $this->app->make(ModrinthSource::class);
        $project = $modrinth->getProject('hugeproj');
        self::assertIsArray($project);
        self::assertSame('Huge Project', $project['title'] ?? null);
        self::assertArrayNotHasKey('body', $project, 'M2 (#11): Cached Modrinth project should trim bulky body field.');
        self::assertArrayNotHasKey('gallery', $project, 'M2 (#11): Cached Modrinth project should trim unused gallery field.');
        self::assertArrayNotHasKey('versions', $project, 'M2 (#11): Cached Modrinth project should trim unused versions array.');
    }

    public function test_size_propagation_stat_reuse_warm_throttle_asset_304_diagnostics_dedupe_and_scheduler(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(32));
        $paperEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::paper(33));
        $fabricServer = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1']);
        $paperServer = $this->createServer($paperEgg, variableValues: ['MINECRAFT_VERSION' => '1.21.1']);

        $spigetCalls = 0;
        $spigetCdnAcceptEncoding = null;
        $this->fakeUpstreamHttp([
            'api.curseforge.com/v1/mods/555/files*' => Http::response([
                'data' => [[
                    'id' => 777,
                    'modId' => 555,
                    'fileName' => 'cf-mod.jar',
                    'displayName' => 'CF Mod 1.0',
                    'releaseType' => 1,
                    'fileLength' => 123456,
                    'downloadCount' => 100,
                    'fileDate' => '2026-10-01T00:00:00Z',
                    'downloadUrl' => 'https://mediafilez.forgecdn.net/files/777/cf-mod.jar',
                    'gameVersions' => ['1.21.1', 'Fabric'],
                    'hashes' => [['algo' => 1, 'value' => sha1('cf')]],
                ]],
            ], 200),
            'hangar.papermc.io/api/v1/projects/WorldEdit/versions*' => Http::response([
                'result' => [[
                    'id' => 99,
                    'name' => '7.3.0',
                    'createdAt' => '2026-10-01T00:00:00Z',
                    'channel' => ['name' => 'Release'],
                    'stats' => ['totalDownloads' => 1000],
                    'downloads' => [
                        'PAPER' => [
                            'fileInfo' => ['name' => 'worldedit.jar', 'sizeBytes' => 65432, 'sha256Hash' => hash('sha256', 'we')],
                            'downloadUrl' => 'https://hangarcdn.papermc.io/worldedit.jar',
                        ],
                    ],
                ]],
            ], 200),
            'api.github.com/repos/owner/repo/releases*' => Http::response([[
                'id' => 11,
                'tag_name' => 'v1.0.0',
                'prerelease' => false,
                'published_at' => '2026-10-01T00:00:00Z',
                'assets' => [[
                    'name' => 'gh-plugin.jar',
                    'size' => 98765,
                    'download_count' => 50,
                    'browser_download_url' => 'https://github.com/owner/repo/releases/download/v1.0.0/gh-plugin.jar',
                ]],
            ]], 200),
            'api.spiget.org/v2/resources/2/versions*' => Http::response([[
                'id' => 200,
                'name' => '2.0.0',
                'downloads' => 500,
                'releaseDate' => 1760000000,
            ]], 200),
            'api.spiget.org/v2/resources/2*' => static function () use (&$spigetCalls) {
                $spigetCalls++;

                return Http::response([
                    'id' => 2,
                    'name' => 'SpigotSample',
                    'file' => ['type' => '.jar', 'size' => 54321, 'sizeUnit' => 'B'],
                ], 200);
            },
            'cdn.spiget.org/file/spiget-resources/2.jar' => static function (HttpClientRequest $req) use (&$spigetCdnAcceptEncoding) {
                $spigetCdnAcceptEncoding = $req->header('Accept-Encoding')[0] ?? null;

                return Http::response("PK\x03\x04", 206);
            },
            'api.modrinth.com/*' => Http::response([['version' => '1.21.1']], 200),
            'api.curseforge.com/*' => Http::response(['data' => ['id' => 432]], 200),
            'api.feed-the-beast.com/*' => Http::response(['id' => 5, 'status' => 'success'], 200),
            'api.technicpack.net/*' => Http::response(['name' => 'tekkit'], 200),
            'api.atlauncher.com/*' => Http::response(['data' => []], 200),
            'hangar.papermc.io/*' => Http::response(['result' => []], 200),
            'api.github.com/*' => Http::response(['resources' => ['core' => ['remaining' => 60]]], 200),
        ]);

        // 1. Source 'size' propagation (CurseForge, Hangar, GitHub, Spigot)
        $cfVersions = $this->app->make(CurseForgeSource::class)->getVersions('555', $fabricServer, ProjectType::Mod);
        self::assertSame(123456, $cfVersions[0]['files'][0]['size'] ?? null, 'M2 (#12): CurseForge normalized file must expose size.');

        $hangarVersions = $this->app->make(HangarSource::class)->getVersions('WorldEdit', $paperServer, ProjectType::Plugin);
        self::assertSame(65432, $hangarVersions[0]['files'][0]['size'] ?? null, 'M2 (#12): Hangar normalized file must expose size.');

        $ghVersions = $this->app->make(GitHubReleasesSource::class)->getVersions('owner/repo', $paperServer, ProjectType::Plugin);
        self::assertSame(98765, $ghVersions[0]['files'][0]['size'] ?? null, 'M2 (#12): GitHub normalized file must expose size.');

        $spigotVersions = $this->app->make(SpigotSource::class)->getVersions('2', $paperServer, ProjectType::Plugin);
        self::assertGreaterThan(0, (int) ($spigotVersions[0]['files'][0]['size'] ?? 0), 'M2 (#12): Spigot normalized file must expose size.');

        // 2. InstalledArchiveTransaction reuses pullForeground() $stat (1 listDirectory, NOT 2)
        $jarBytes = "PK\x03\x04".str_repeat('M', 256);
        $this->registerRemoteFile('https://cdn.modrinth.com/data/x/mod-1.0.jar', $jarBytes);
        $this->wings->seedDirectory($fabricServer->uuid, 'mods');
        $this->wings->resetMetrics();

        $tx = $this->app->make(InstalledArchiveTransaction::class);
        $tx->installOrUpdate(
            $fabricServer,
            $this->app->make(DaemonFileRepository::class),
            ProjectType::Mod,
            ['source' => ProjectSourceKey::Modrinth->value, 'project_id' => 'modx', 'slug' => 'mod-x', 'title' => 'Mod X'],
            ['id' => 'v1', 'version_number' => '1.0.0'],
            ['filename' => 'mod-1.0.jar', 'url' => 'https://cdn.modrinth.com/data/x/mod-1.0.jar', 'size' => strlen($jarBytes), 'hashes' => []],
        );
        self::assertSame($jarBytes, $this->serverFile($fabricServer, 'mods/mod-1.0.jar'));
        $this->wings->assertEndpointCalledAtMost('listDirectory', 1, 'M2 (#12): InstalledArchiveTransaction must reuse pullForeground() $stat instead of re-listing directory.');

        // 3. WarmCatalogSearch validates server BEFORE consuming WarmRequestThrottle token
        config(['pelican-mod-manager.warm_rate_limit.modrinth' => 1]);
        $this->app->forgetInstance(WarmRequestThrottle::class);
        /** @var WarmRequestThrottle $throttle */
        $throttle = $this->app->make(WarmRequestThrottle::class);
        $warmJob = new WarmCatalogSearch(999999, 'modrinth', 'mod', 1, 'fabric', '1.21.1');
        $warmJob->handle($this->app->make(ProjectSourceRegistry::class), $throttle);
        self::assertSame(
            1,
            $throttle->remaining('modrinth'),
            'M2 (#12): WarmCatalogSearch for non-existent server must NOT consume throttle budget.',
        );
        self::assertTrue(
            $throttle->tryAcquire('modrinth'),
            'M2 (#12): Throttle slot must remain available after skipped WarmCatalogSearch.',
        );

        // 4. ModManagerAssetController 304 fast-path with matching ETag
        $assetDef = ModManagerAssets::get('table-swr-cache.js');
        $assetReq = Request::create('/plugins/pelican-mod-manager/assets/'.$assetDef['version'].'/table-swr-cache.js', 'GET', [], [], [], [
            'HTTP_ACCEPT_ENCODING' => 'gzip',
            'HTTP_IF_NONE_MATCH' => '"'.$assetDef['version'].'-gzip"',
        ]);
        $assetResp = (new ModManagerAssetController())($assetReq, $assetDef['version'], 'table-swr-cache.js');
        self::assertSame(304, $assetResp->getStatusCode());
        self::assertSame('', (string) $assetResp->getContent());

        // 5. ModManagerDiagnostics deduplicates SpiGet resource 2, sets identity on Range probe, includes Hangar & GitHub
        $spigetCalls = 0;
        $checks = $this->app->make(ModManagerDiagnostics::class)->run($fabricServer);
        $checkIds = array_column($checks, 'id');
        self::assertSame(1, $spigetCalls, 'M2 (#12): ModManagerDiagnostics must not fetch SpiGet resource 2 twice.');
        self::assertSame('identity', strtolower((string) $spigetCdnAcceptEncoding), 'M2 (#12): SpiGet CDN Range probe must send Accept-Encoding: identity.');
        self::assertContains('hangar', $checkIds, 'M2 (#12): Diagnostics should include Hangar reachability check.');
        self::assertTrue(
            in_array('github', $checkIds, true) || in_array('github_releases', $checkIds, true),
            'M2 (#12): Diagnostics should include GitHub Releases reachability check (github or github_releases).',
        );

        // 6. Scheduler registers background job worker(s) without single global 10-min starvation bottleneck
        $schedule = $this->app->make(Schedule::class);
        $jobEvents = array_values(array_filter(
            $schedule->events(),
            static fn ($event): bool => str_contains((string) $event->description, 'mod-manager:process')
                || str_contains((string) $event->command, 'mod-manager:process'),
        ));
        self::assertNotEmpty($jobEvents, 'ModManagerServiceProvider must register mod-manager:process-jobs scheduled event.');
        self::assertTrue(
            count($jobEvents) >= 2 || !$jobEvents[0]->withoutOverlapping,
            'M2 (#12): Scheduler must separate long-running jobs from fast interactive jobs or allow bounded concurrency instead of a single global withoutOverlapping(10) bottleneck.',
        );
    }

    public function test_bulk_update_checkpoints_metadata_writes_and_protects_long_job_reservation(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(34));
        $server = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1', 'LOADER_VERSION' => '0.16.10']);
        /** @var DaemonFileRepository $repo */
        $repo = $this->app->make(DaemonFileRepository::class);

        $installedMods = [];
        $updatePayload = [];
        $v2BytesByFile = [];
        for ($i = 1; $i <= 12; $i++) {
            $slug = sprintf('bulk-mod-%02d', $i);
            $v1File = "{$slug}-v1.jar";
            $v2File = "{$slug}-v2.jar";
            $v1Bytes = "PK\x03\x04v1-bytes-{$i}";
            $v2Bytes = "PK\x03\x04v2-bytes-{$i}";
            $v1Hash = hash('sha512', $v1Bytes);
            $v2Hash = hash('sha512', $v2Bytes);
            $v2Url = "https://cdn.modrinth.com/data/{$slug}/{$v2File}";

            $this->seedServerFile($server, "mods/{$v1File}", $v1Bytes);
            $this->registerRemoteFile($v2Url, $v2Bytes);
            $v2BytesByFile[$v2File] = $v2Bytes;

            $installedMods[] = [
                'source' => ProjectSourceKey::Modrinth->value,
                'project_id' => "proj-{$i}",
                'project_slug' => $slug,
                'project_title' => "Bulk Mod {$i}",
                'version_id' => "v1-{$i}",
                'version_number' => '1.0.0',
                'filename' => $v1File,
                'hashes' => ['sha512' => $v1Hash],
                'installed_at' => gmdate('c'),
            ];

            $updatePayload[$v1Hash] = [
                'id' => "v2-{$i}",
                'project_id' => "proj-{$i}",
                'version_number' => '2.0.0',
                'files' => [[
                    'primary' => true,
                    'filename' => $v2File,
                    'url' => $v2Url,
                    'size' => strlen($v2Bytes),
                    'hashes' => ['sha512' => $v2Hash],
                ]],
            ];
        }

        $this->seedServerFile($server, 'mods/.pelican-mod-manager.json', json_encode([
            'schema_version' => 2,
            'installed_mods' => $installedMods,
            'unresolved_files' => [],
        ], JSON_THROW_ON_ERROR));

        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/version_files/update' => Http::response($updatePayload, 200),
        ]);

        $this->wings->resetMetrics();
        /** @var InstalledProjectUpdateService $updater */
        $updater = $this->app->make(InstalledProjectUpdateService::class);
        $summary = $updater->updateAll($server, $repo, ProjectType::Mod);
        self::assertSame(12, $summary['updated']);
        self::assertSame(0, $summary['failed']);

        $metadataWrites = count(array_filter(
            $this->wings->recordedRequests('putStream'),
            static fn (array $req): bool => str_ends_with((string) ($req['query']['file'] ?? ''), '.pelican-mod-manager.json'),
        ));
        self::assertGreaterThanOrEqual(1, $metadataWrites);
        self::assertLessThan(
            12,
            $metadataWrites,
            'M2 (#12): InstalledProjectUpdateService must checkpoint/batch metadata writes instead of writing .pelican-mod-manager.json on every single mod.',
        );

        $savedMetadata = json_decode($this->serverFile($server, 'mods/.pelican-mod-manager.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(12, $savedMetadata['installed_mods'] ?? []);
        foreach ($savedMetadata['installed_mods'] as $entry) {
            self::assertStringStartsWith('v2-', (string) ($entry['version_id'] ?? ''));
        }
        foreach ($v2BytesByFile as $v2File => $expectedBytes) {
            self::assertSame($expectedBytes, $this->serverFile($server, "mods/{$v2File}"));
        }

        // Verify BulkUpdateInstalledProjects heartbeat OR extended BULK_UPDATE stale-reservation TTL beyond 5 minutes
        $server2 = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1', 'LOADER_VERSION' => '0.16.10']);
        $this->seedServerFile($server2, 'mods/one-v1.jar', "PK\x03\x04one-v1");
        $oneHash = hash('sha512', "PK\x03\x04one-v1");
        $this->seedServerFile($server2, 'mods/.pelican-mod-manager.json', json_encode([
            'schema_version' => 2,
            'installed_mods' => [[
                'source' => ProjectSourceKey::Modrinth->value,
                'project_id' => 'proj-one',
                'project_slug' => 'one-mod',
                'project_title' => 'One Mod',
                'version_id' => 'v1-one',
                'version_number' => '1.0.0',
                'filename' => 'one-v1.jar',
                'hashes' => ['sha512' => $oneHash],
                'installed_at' => gmdate('c'),
            ]],
            'unresolved_files' => [],
        ], JSON_THROW_ON_ERROR));

        /** @var InstalledOperationManager $operations */
        $operations = $this->app->make(InstalledOperationManager::class);
        /** @var InstalledOperationLease $leases */
        $leases = $this->app->make(InstalledOperationLease::class);
        $leaseToken = $leases->tryAcquire((int) $server2->getKey(), ProjectType::Mod, InstalledOperationLease::OPERATION_BULK_UPDATE);
        self::assertNotNull($leaseToken);

        $heartbeatCalls = 0;
        $job = new BulkUpdateInstalledProjects(
            serverId: (int) $server2->getKey(),
            projectType: ProjectType::Mod->value,
            leaseToken: $leaseToken,
            heartbeat: static function () use (&$heartbeatCalls): bool {
                $heartbeatCalls++;

                return true;
            },
        );
        $job->handle($repo, $updater, $operations, $leases);
        self::assertSame(
            InstalledOperationState::STATUS_COMPLETED,
            $operations->state($server2, ProjectType::Mod, InstalledOperationManager::OPERATION_BULK_UPDATE)?->status,
        );

        $queue = new DatabaseBackgroundJobQueue();
        self::assertTrue($queue->push(BackgroundJob::BULK_UPDATE, ['server_id' => $server2->getKey()]));
        $claimed = $queue->claim();
        self::assertNotNull($claimed);
        DB::table('mod_manager_background_jobs')
            ->where('id', $claimed['id'])
            ->update(['reserved_at' => now()->subMinutes(6)]);
        $reclaimedAt6Min = $queue->claim();

        self::assertTrue(
            $heartbeatCalls >= 1 || $reclaimedAt6Min === null,
            'M2 (#12): BulkUpdateInstalledProjects must either invoke heartbeat eagerly or extend BULK_UPDATE stale-reservation TTL beyond 5 minutes.',
        );
    }

    public function test_warm_catalog_cache_command_discovers_custom_egg_profile_mc_version_variables_and_idle_queue_avoids_db_churn(): void
    {
        $customUuid = 'c0570e11-1234-4abc-9def-000000000035';
        EggProfileRegistry::seed([[
            'id' => 'custom-e2e-fabric',
            'source_name' => 'Custom E2E Fabric',
            'match' => [
                'uuid' => [$customUuid],
                'update_url_contains' => [],
                'name_aliases' => ['custome2efabric'],
                'variable_signatures' => [['CUSTOM_MC_VER']],
            ],
            'status' => 'supported',
            'loader' => 'fabric',
            'project_type' => 'mod',
            'is_proxy' => false,
            'minecraft_version_variables' => ['CUSTOM_MC_VER'],
        ]]);

        $customEgg = $this->createEgg([
            'id' => 35,
            'uuid' => $customUuid,
            'name' => 'Custom E2E Fabric',
            'tags' => ['minecraft'],
        ], [
            [
                'name' => 'Custom MC Version',
                'env_variable' => 'CUSTOM_MC_VER',
                'default_value' => '1.20.6',
                'rules' => ['required', 'string'],
            ],
        ]);
        $server = $this->createServer($customEgg, variableValues: ['CUSTOM_MC_VER' => '1.20.6']);

        config([
            'pelican-mod-manager.latest_minecraft_version' => '26.1.2',
            'pelican-mod-manager.curseforge_api_key' => '',
            'pelican-mod-manager.warm_catalog_enabled' => true,
        ]);

        $modrinthUrls = [];
        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/search*' => static function (HttpClientRequest $req) use (&$modrinthUrls) {
                $modrinthUrls[] = rawurldecode($req->url());

                return Http::response(['hits' => [], 'total_hits' => 0], 200);
            },
        ]);

        $this->artisan('mod-manager:warm-catalog')->assertSuccessful();

        $matchedCustomVersion = array_filter(
            $modrinthUrls,
            static fn (string $url): bool => str_contains($url, 'versions:1.20.6'),
        );
        self::assertNotEmpty(
            $matchedCustomVersion,
            'M2 (#12): WarmCatalogCacheCommand must discover custom EggProfile::$minecraftVersionVariables (CUSTOM_MC_VER=1.20.6) instead of falling back to latest_minecraft_version.',
        );

        // Idle DatabaseBackgroundJobQueue::hasPending() DB-churn mitigation
        DB::table('mod_manager_background_jobs')->delete();
        $queue = new DatabaseBackgroundJobQueue();
        self::assertFalse($queue->hasPending());

        $idleQueries = 0;
        DB::listen(static function ($query) use (&$idleQueries): void {
            if (str_contains($query->sql, 'mod_manager_background_jobs')) {
                $idleQueries++;
            }
        });

        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($queue->hasPending());
        }
        self::assertSame(
            0,
            $idleQueries,
            'M2 (#12): Idle DatabaseBackgroundJobQueue::hasPending() must be cache-gated to avoid polling SQLite/MySQL on every tick.',
        );

        self::assertTrue($queue->push(BackgroundJob::SCAN, ['server_id' => $server->getKey()]));
        self::assertTrue(
            $queue->hasPending(),
            'M2 (#12): DatabaseBackgroundJobQueue::push() must immediately invalidate/update the idle hasPending() cache flag.',
        );
    }

    private function unknownSizeStream(string $content): StreamInterface
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
                throw new \RuntimeException('Not seekable');
            }

            public function rewind(): void
            {
                throw new \RuntimeException('Not seekable');
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new \RuntimeException('Not writable');
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
