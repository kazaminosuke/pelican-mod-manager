<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier3;

use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\ModManagerPlugin;
use Kazaminosuke\ModManager\Services\InstalledArchiveTransaction;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\BackgroundJobRuntime;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use Kazaminosuke\ModManager\Tests\E2E\Support\StrictWingsFake;

/**
 * Tier 3 — Cross-Feature Combinations for Core Features #5–#12 (M2).
 */
final class CoreOpsQueueHttpWingsWorkflowE2ETest extends E2ETestCase
{
    public function test_saved_settings_feed_modrinth_and_hangar_auth_with_401_jwt_refresh_and_curseforge_503_retry(): void
    {
        config([
            'pelican-mod-manager.latest_minecraft_version' => '26.1.2',
            'pelican-mod-manager.modrinth_token' => 'mrp_workflow_token',
            'pelican-mod-manager.hangar_api_key' => 'hangar_workflow_key',
            'pelican-mod-manager.curseforge_api_key' => 'cf_workflow_key',
            'pelican-mod-manager.github_token' => 'gh_workflow_token',
        ]);

        // Round-trip settings save must retain both tokens
        $capturedEnv = [];
        $plugin = new class($capturedEnv) extends ModManagerPlugin
        {
            /** @param array<string, mixed> $out */
            public function __construct(private array &$out) {}

            public function writeToEnvironment(array $values = []): void
            {
                $this->out = $values;
            }
        };
        $formData = $plugin->getSettingsFormData();
        $plugin->saveSettings($formData);
        self::assertSame('mrp_workflow_token', $capturedEnv['MOD_MANAGER_MODRINTH_TOKEN'] ?? null, 'M2 (#5): Modrinth token must survive settings save.');
        self::assertSame('hangar_workflow_key', $capturedEnv['MOD_MANAGER_HANGAR_API_KEY'] ?? null, 'M2 (#5): Hangar key must survive settings save.');

        $modrinthAuth = null;
        $hangarAuthCalls = 0;
        $hangarProjectCalls = 0;
        $hangarSeenAuth = [];
        $cfCalls = 0;

        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/search*' => static function (HttpClientRequest $req) use (&$modrinthAuth) {
                $modrinthAuth = $req->header('Authorization')[0] ?? null;

                return Http::response(['hits' => [], 'total_hits' => 0], 200);
            },
            'hangar.papermc.io/api/v1/authenticate*' => static function () use (&$hangarAuthCalls) {
                $hangarAuthCalls++;
                $token = $hangarAuthCalls === 1 ? 'token-1' : 'token-2';

                return Http::response(['token' => $token, 'expiresIn' => 3600000], 200);
            },
            'hangar.papermc.io/api/v1/projects*' => static function (HttpClientRequest $req) use (&$hangarProjectCalls, &$hangarSeenAuth) {
                $hangarProjectCalls++;
                $auth = (string) ($req->header('Authorization')[0] ?? '');
                $hangarSeenAuth[] = $auth;

                if ($auth === 'HangarAuth token-1') {
                    return Http::response(['message' => 'Token expired'], 401);
                }

                if ($auth === 'HangarAuth token-2') {
                    return Http::response(['pagination' => ['count' => 0], 'result' => []], 200);
                }

                return Http::response(['message' => 'Unauthorized'], 401);
            },
            'api.curseforge.com/v1/mods/search*' => static function () use (&$cfCalls) {
                $cfCalls++;

                return $cfCalls === 1
                    ? Http::response(['error' => 'upstream overloaded'], 503)
                    : Http::response(['data' => [], 'pagination' => ['totalCount' => 0]], 200);
            },
        ]);

        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(110));
        $paperEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::paper(111));
        $fabricServer = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1']);
        $paperServer = $this->createServer($paperEgg, variableValues: ['MINECRAFT_VERSION' => '1.21.1']);

        $this->app->make(ModrinthSource::class)->search($fabricServer, ProjectType::Mod, 1, 'sodium');
        self::assertSame('mrp_workflow_token', $modrinthAuth);

        $this->app->make(HangarSource::class)->search($paperServer, ProjectType::Plugin, 1, 'worldedit');
        self::assertSame(2, $hangarAuthCalls, 'M2 (#9): Hangar must re-authenticate once after 401.');
        self::assertSame(2, $hangarProjectCalls, 'M2 (#9): Hangar 401 JWT refresh must retry /projects.');
        self::assertSame(['HangarAuth token-1', 'HangarAuth token-2'], $hangarSeenAuth);

        $this->app->make(CurseForgeSource::class)->search($fabricServer, ProjectType::Mod, 1, 'jei');
        self::assertGreaterThanOrEqual(2, $cfCalls, 'M2 (#9): CurseForge transient 503 must retry via UpstreamHttp.');
    }

    public function test_http_failure_marker_cleared_by_cli_revalidation_and_archive_install_survives_wings_curl_retry(): void
    {
        $runner = PluginBackgroundRunner::fake();
        $this->app->instance(PluginBackgroundRunner::class, $runner);

        // 1. Cross-process SourceCache recovery (#11): HTTP miss writes real failure marker -> CLI worker revalidates & clears it
        $this->app->forgetInstance(InstalledOperationManager::class);
        $this->app->forgetInstance(SourceCache::class);

        $projectState = ['fail' => true];
        $spec = new SourceFetchSpec('modrinth', 'project', ['project_id' => 'crossProcRecover']);

        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/project/crossProcRecover' => static function () use (&$projectState) {
                if ($projectState['fail']) {
                    return Http::response(['error' => 'upstream unavailable'], 500);
                }

                return Http::response([
                    'id' => 'crossProcRecover',
                    'slug' => 'cross-proc',
                    'title' => 'Cross Process Recovered',
                    'description' => 'Recovered in CLI worker',
                    'body' => str_repeat('HUGE ', 1000),
                    'downloads' => 42,
                    'updated' => '2026-10-01T00:00:00Z',
                    'team' => 'team9',
                ], 200);
            },
            'api.modrinth.com/v2/team/team9/members' => Http::response([
                ['role' => 'Owner', 'user' => ['username' => 'cli_dev']],
            ], 200),
        ]);

        // Request 1: HTTP miss fails with 500 -> writes genuine failure marker
        $this->app->instance('request', Request::create('/http-fail', 'GET'));
        /** @var SourceCache $httpCache */
        $httpCache = $this->app->make(SourceCache::class);
        $httpCache->swr($spec, CacheProfile::ProjectMetadata);

        // Request 1b: Confirm failure marker is active
        $this->app->instance('request', Request::create('/http-check', 'GET'));
        $this->app->forgetInstance(SourceCache::class);
        /** @var SourceCache $probeCache */
        $probeCache = $this->app->make(SourceCache::class);
        $before = $probeCache->peek($spec);
        self::assertFalse($before['hit']);
        self::assertTrue($before['retry_delayed']);

        // Simulate fresh CLI background worker process revalidating via SourceCache::revalidate()
        $projectState['fail'] = false;
        $this->app->instance('request', Request::create('/cli-revalidate', 'GET'));
        BackgroundJobRuntime::forgetRequestState();
        $this->app->forgetInstance(SourceCache::class);
        /** @var SourceCache $cliSourceCache */
        $cliSourceCache = $this->app->make(SourceCache::class);
        self::assertTrue($cliSourceCache->revalidate($spec, CacheProfile::ProjectMetadata));

        // Request 2: Fresh observer verifies hit AND that revalidateAsync() is unblocked (failure marker forgotten!)
        $this->app->instance('request', Request::create('/http-after', 'GET'));
        $this->app->forgetInstance(SourceCache::class);
        /** @var SourceCache $observerCache */
        $observerCache = $this->app->make(SourceCache::class);
        $after = $observerCache->peek($spec);
        self::assertTrue($after['hit']);
        self::assertTrue(
            $observerCache->revalidateAsync($spec, CacheProfile::ProjectMetadata),
            'M2 (#11): CLI SourceCache::revalidate() must clear cross-request failure marker so subsequent async revalidation succeeds.',
        );

        // 2. InstalledArchiveTransaction + WingsRemoteFilesystem pre-send cURL 7 retry + $stat reuse (#10 + #12)
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(112));
        $server = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1']);
        $this->wings->seedDirectory($server->uuid, 'mods');
        $jarBytes = "PK\x03\x04workflow-mod-jar";
        $this->registerRemoteFile('https://cdn.modrinth.com/data/wf/mod.jar', $jarBytes);

        // Inject transient cURL error 7 on listDirectory & pull -> must recover via WingsRemoteFilesystem retry!
        $this->wings->injectConnectionError('listDirectory', StrictWingsFake::CURL_ERR_7_REFUSED, 1);
        $this->wings->setRangeResponseEnabled(true);

        $tx = $this->app->make(InstalledArchiveTransaction::class);
        try {
            $tx->installOrUpdate(
                $server,
                $this->app->make(DaemonFileRepository::class),
                ProjectType::Mod,
                ['source' => ProjectSourceKey::Modrinth->value, 'project_id' => 'wf1', 'slug' => 'wf-mod', 'title' => 'WF Mod'],
                ['id' => 'v1', 'version_number' => '1.0.0'],
                ['filename' => 'mod.jar', 'url' => 'https://cdn.modrinth.com/data/wf/mod.jar', 'size' => strlen($jarBytes), 'hashes' => []],
            );
        } catch (\Throwable $e) {
            self::fail('M2 (#10 + #12): InstalledArchiveTransaction must survive transient pre-send cURL error 7 via WingsRemoteFilesystem retry: '.$e->getMessage());
        }

        self::assertSame($jarBytes, $this->serverFile($server, 'mods/mod.jar'));
        $this->wings->assertBytesWingsToPanelAtMost(4, 'M2 (#10): readPrefix during archive validation should read <= 4 bytes via Range.');

        // 3. Active OPERATION_MODPACK on server fences concurrent scan while allowing scan on sibling server (#7)
        $serverB = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1']);
        $ops = $this->app->make(InstalledOperationManager::class);
        $leases = $this->app->make(InstalledOperationLease::class);
        $leaseA = $leases->tryAcquire((int) $server->getKey(), ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK);
        self::assertNotNull($leaseA);
        $ops->start($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK, total: 5);

        $blockedA = $ops->dispatchScan($server, ProjectType::Mod, actorUserId: 1);
        self::assertFalse($blockedA['dispatched'] ?? true, 'M2 (#7): Active modpack on Server A must fence scan on Server A.');

        $allowedB = $ops->dispatchScan($serverB, ProjectType::Mod, actorUserId: 1);
        self::assertTrue($allowedB['dispatched'] ?? false, 'Server B must remain unaffected by Server A modpack lease.');
    }
}
