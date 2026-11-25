<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier2;

use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Http\Controllers\ModManagerAssetController;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Services\InstalledArchiveTransaction;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Services\ResourcePackService;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\DatabaseBackgroundJobQueue;
use Kazaminosuke\ModManager\Support\ModManagerAssets;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Kazaminosuke\ModManager\Support\UpstreamHttp;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use Kazaminosuke\ModManager\Tests\E2E\Support\StrictWingsFake;
use Throwable;

/**
 * Tier 2 — Boundary, Fault Injection & Security Cases for Core Features #5–#12 (M2).
 */
final class CoreFailuresLocksAndTransportBoundaryE2ETest extends E2ETestCase
{
    public function test_parallel_multi_file_scan_absorbs_wings_500_and_404_without_hashing_error_json_or_leaking_temp_files(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(70));
        $server = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1']);

        $validJar = "PK\x03\x04valid-mod-a";
        $this->seedServerFile($server, 'mods/valid-a.jar', $validJar);
        $this->seedServerFile($server, 'mods/err500-b.jar', "PK\x03\x04jar-b");
        $this->seedServerFile($server, 'mods/err404-c.jar', "PK\x03\x04jar-c");
        $this->seedServerFile($server, 'mods/.pelican-mod-manager.json', json_encode([
            'schema_version' => 2,
            'installed_mods' => [],
            'unresolved_files' => [],
        ], JSON_THROW_ON_ERROR));

        $err500Body = '{"error":"wings 500 failure"}';
        $err404Body = '{"error":"wings 404 missing"}';
        $this->wings->injectHttpError('readStream', 500, $err500Body, 1, 'err500-b.jar');
        $this->wings->injectHttpError('readStream', 404, $err404Body, 1, 'err404-c.jar');

        $validSha512 = hash('sha512', $validJar);
        $err500Sha512 = hash('sha512', $err500Body);
        $err404Sha512 = hash('sha512', $err404Body);

        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/version_files' => Http::response([
                $validSha512 => [
                    'id' => 'ver-a',
                    'project_id' => 'proj-a',
                    'version_number' => '1.0.0',
                    'files' => [['primary' => true, 'filename' => 'valid-a.jar', 'hashes' => ['sha512' => $validSha512]]],
                ],
            ], 200),
            'api.modrinth.com/v2/projects*' => Http::response([[
                'id' => 'proj-a',
                'slug' => 'valid-a',
                'title' => 'Valid Mod A',
                'team' => 'team-a',
            ]], 200),
            'api.modrinth.com/v2/teams*' => Http::response([[
                ['team_id' => 'team-a', 'role' => 'Owner', 'user' => ['username' => 'authorA']],
            ]], 200),
            'api.curseforge.com/v1/fingerprints/432' => Http::response(['data' => ['exactMatches' => []]], 200),
        ]);

        $beforeScanTmp = glob(sys_get_temp_dir().'/pmm-scan-*') ?: [];
        $result = $this->app->make(InstalledProjectService::class)->scanAndImportModsResult(
            $server,
            $this->app->make(DaemonFileRepository::class),
            ProjectType::Mod,
            force: true,
        );
        $afterScanTmp = glob(sys_get_temp_dir().'/pmm-scan-*') ?: [];

        self::assertSame($beforeScanTmp, $afterScanTmp, 'Parallel scan must not leak pmm-scan-* temp files.');
        Http::assertNotSent(static fn (HttpClientRequest $r): bool => str_contains($r->body(), $err500Sha512) || str_contains($r->body(), $err404Sha512));
        self::assertFalse($result->isFresh(), 'Partial scan with hash failures must stay stale rather than caching fresh for 24h.');
    }

    public function test_parallel_scan_skips_oversized_files_using_wings_directory_stat_size_without_downloading(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(74));
        $server = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1']);

        $smallJar = "PK\x03\x04small-valid-mod";
        $this->seedServerFile($server, 'mods/small-valid.jar', $smallJar);
        $this->seedServerFile($server, 'mods/oversized-giant.jar', "PK\x03\x04placeholder");
        $this->seedServerFile($server, 'mods/.pelican-mod-manager.json', json_encode([
            'schema_version' => 2,
            'installed_mods' => [],
            'unresolved_files' => [],
        ], JSON_THROW_ON_ERROR));

        $twoGiB = 2 * 1024 * 1024 * 1024;
        $smallSize = strlen($smallJar);
        $nowIso = gmdate('c');
        $this->wings->beforeEndpoint('listDirectory', static function (HttpClientRequest $req, string $uuid, string $ep, StrictWingsFake $wings) use ($smallSize, $twoGiB, $nowIso) {
            parse_str($req->toPsrRequest()->getUri()->getQuery(), $query);
            $dir = trim((string) ($query['directory'] ?? ''), '/');
            if ($dir !== 'mods') {
                return null;
            }

            return Http::response(json_encode([
                [
                    'name' => 'small-valid.jar',
                    'created' => $nowIso,
                    'modified' => $nowIso,
                    'mode' => '-rw-r--r--',
                    'mode_bits' => '0644',
                    'size' => $smallSize,
                    'directory' => false,
                    'file' => true,
                    'symlink' => false,
                    'mime' => 'application/octet-stream',
                ],
                [
                    'name' => 'oversized-giant.jar',
                    'created' => $nowIso,
                    'modified' => $nowIso,
                    'mode' => '-rw-r--r--',
                    'mode_bits' => '0644',
                    'size' => $twoGiB,
                    'directory' => false,
                    'file' => true,
                    'symlink' => false,
                    'mime' => 'application/octet-stream',
                ],
                [
                    'name' => '.pelican-mod-manager.json',
                    'created' => $nowIso,
                    'modified' => $nowIso,
                    'mode' => '-rw-r--r--',
                    'mode_bits' => '0644',
                    'size' => 64,
                    'directory' => false,
                    'file' => true,
                    'symlink' => false,
                    'mime' => 'application/json',
                ],
            ], JSON_THROW_ON_ERROR), 200, [
                'User-Agent' => $wings->userAgentHeader(),
                'Content-Type' => 'application/json',
            ]);
        });

        $smallSha512 = hash('sha512', $smallJar);
        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/version_files' => Http::response([
                $smallSha512 => [
                    'id' => 'ver-small',
                    'project_id' => 'proj-small',
                    'version_number' => '1.0.0',
                    'files' => [['primary' => true, 'filename' => 'small-valid.jar', 'hashes' => ['sha512' => $smallSha512]]],
                ],
            ], 200),
            'api.modrinth.com/v2/projects*' => Http::response([[
                'id' => 'proj-small',
                'slug' => 'small-valid',
                'title' => 'Small Valid Mod',
                'team' => 'team-small',
            ]], 200),
            'api.modrinth.com/v2/teams*' => Http::response([[
                ['team_id' => 'team-small', 'role' => 'Owner', 'user' => ['username' => 'smallAuthor']],
            ]], 200),
            'api.curseforge.com/v1/fingerprints/432' => Http::response(['data' => ['exactMatches' => []]], 200),
        ]);

        $beforeScanTmp = glob(sys_get_temp_dir().'/pmm-scan-*') ?: [];
        $this->wings->resetMetrics();
        $this->app->make(InstalledProjectService::class)->scanAndImportModsResult(
            $server,
            $this->app->make(DaemonFileRepository::class),
            ProjectType::Mod,
            force: true,
        );
        $afterScanTmp = glob(sys_get_temp_dir().'/pmm-scan-*') ?: [];

        self::assertSame($beforeScanTmp, $afterScanTmp, 'Parallel scan must not leak pmm-scan-* temp files.');

        $readFiles = array_map(
            static fn (array $req): string => (string) ($req['query']['file'] ?? ''),
            $this->wings->recordedRequests('readStream'),
        );
        $oversizedReads = array_filter($readFiles, static fn (string $f): bool => str_contains($f, 'oversized-giant.jar'));
        $smallReads = array_filter($readFiles, static fn (string $f): bool => str_contains($f, 'small-valid.jar'));

        self::assertCount(
            0,
            $oversizedReads,
            'M2 (#8): Parallel scan must skip downloading oversized files exceeding max-file-size cap based on Wings directory Stat size.',
        );
        self::assertGreaterThanOrEqual(1, count($smallReads));
    }

    public function test_database_background_job_queue_caps_release_loops_and_reports_database_exceptions(): void
    {
        $queue = new DatabaseBackgroundJobQueue();
        self::assertTrue($queue->push(BackgroundJob::SCAN, ['server_id' => 1, '_unique_key' => 'loop-test']));

        // M2 (#7): Repeated claim() + release($id, 0, $attempts) must hit a release-loop guard so a stuck job
        // cannot spin infinitely at attempts=0!
        $observedMaxAttempts = 0;
        $stillClaimableAfterCap = true;
        for ($i = 0; $i < 25; $i++) {
            $claimed = $queue->claim();
            if ($claimed === null) {
                $stillClaimableAfterCap = false;
                break;
            }
            $observedMaxAttempts = max($observedMaxAttempts, (int) $claimed['attempts']);
            $queue->release($claimed['id'], 0, $claimed['attempts']);
        }
        self::assertTrue(
            !$stillClaimableAfterCap || $observedMaxAttempts >= 3,
            'M2 (#7): DatabaseBackgroundJobQueue::release() must enforce a release-loop guard instead of infinitely resetting attempts to 0.',
        );

        // M2 (#7): Database errors must be passed to ExceptionHandler::report() instead of silently swallowed.
        $reported = [];
        $previousHandler = $this->app->make(ExceptionHandler::class);
        $this->app->instance(ExceptionHandler::class, new class($previousHandler, $reported) implements ExceptionHandler
        {
            /** @param list<Throwable> $log */
            public function __construct(
                private readonly ExceptionHandler $inner,
                private array &$log,
            ) {}

            public function report(Throwable $e): void
            {
                $this->log[] = $e;
            }

            public function shouldReport(Throwable $e): bool
            {
                return true;
            }

            public function render($request, Throwable $e)
            {
                return $this->inner->render($request, $e);
            }

            public function renderForConsole($output, Throwable $e): void
            {
                $this->inner->renderForConsole($output, $e);
            }
        });

        Schema::drop('mod_manager_background_jobs');
        self::assertFalse($queue->push(BackgroundJob::SCAN, ['server_id' => 1]));
        self::assertNotEmpty($reported, 'M2 (#7): DatabaseBackgroundJobQueue must call report() on database exceptions.');
    }

    public function test_resource_pack_service_rolls_back_server_properties_on_metadata_failure_and_enforces_per_server_lock(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(71));
        $server = $this->createServer($fabricEgg);
        $originalProps = "level-name=world\nresource-pack=https://old.example/old.zip\nresource-pack-sha1=".sha1('old')."\n";
        $this->seedServerFile($server, 'server.properties', $originalProps);

        $lockLog = [
            'acquired' => [],
            'released' => [],
            'in_flight' => 0,
            'block_with_timeout' => false,
        ];
        $spyStore = new class($lockLog) extends ArrayStore
        {
            /** @param array{acquired: list<string>, released: list<string>, in_flight: int, block_with_timeout: bool} $log */
            public function __construct(private array &$log)
            {
                parent::__construct();
            }

            public function lock($name, $seconds = 0, $owner = null): Lock
            {
                $log = &$this->log;
                $lockName = (string) $name;

                return new class($log, $lockName) implements Lock
                {
                    /** @param array{acquired: list<string>, released: list<string>, in_flight: int, block_with_timeout: bool} $log */
                    public function __construct(
                        private array &$log,
                        private readonly string $name,
                    ) {}

                    public function get($callback = null)
                    {
                        if ($this->log['block_with_timeout']) {
                            return false;
                        }
                        $this->log['acquired'][] = $this->name;
                        $this->log['in_flight']++;
                        if (is_callable($callback)) {
                            try {
                                return $callback();
                            } finally {
                                $this->release();
                            }
                        }

                        return true;
                    }

                    public function block($seconds, $callback = null)
                    {
                        if ($this->log['block_with_timeout']) {
                            throw new LockTimeoutException("Simulated contention on lock [{$this->name}].");
                        }
                        $this->log['acquired'][] = $this->name;
                        $this->log['in_flight']++;
                        if (is_callable($callback)) {
                            try {
                                return $callback();
                            } finally {
                                $this->release();
                            }
                        }

                        return true;
                    }

                    public function release()
                    {
                        $this->log['released'][] = $this->name;
                        $this->log['in_flight'] = max(0, $this->log['in_flight'] - 1);

                        return true;
                    }

                    public function owner()
                    {
                        return 'spy-owner';
                    }

                    public function forceRelease(): void
                    {
                        $this->log['in_flight'] = 0;
                    }
                };
            }
        };

        $previousDefault = (string) config('cache.default', 'array');
        Cache::extend('e2e_spy_lock', fn () => Cache::repository($spyStore));
        config([
            'cache.stores.e2e_spy_lock' => ['driver' => 'e2e_spy_lock'],
            'cache.default' => 'e2e_spy_lock',
        ]);
        Cache::setDefaultDriver('e2e_spy_lock');
        $this->app->forgetInstance('cache.store');
        $this->app->forgetInstance(ResourcePackService::class);

        try {
            /** @var DaemonFileRepository $repo */
            $repo = $this->app->make(DaemonFileRepository::class);
            /** @var ResourcePackService $rpService */
            $rpService = $this->app->make(ResourcePackService::class);

            // 1. Inject HTTP 500 when writing .pelican-mod-manager-resource-pack.json -> must acquire per-server lock, roll back server.properties, and release lock
            $this->wings->injectHttpError('putStream', 500, ['error' => 'disk full'], 1, ResourcePackService::METADATA_FILENAME);

            $caught = null;
            try {
                $rpService->installOrUpdate(
                    $server,
                    $repo,
                    [
                        'source' => ProjectSourceKey::Modrinth->value,
                        'project_id' => 'rpfail',
                        'slug' => 'rp-fail',
                        'title' => 'RP Fail',
                    ],
                    ['id' => 'v1', 'version_number' => '1.0'],
                    [
                        'filename' => 'rp.zip',
                        'url' => 'https://cdn.modrinth.com/data/rpfail/rp.zip',
                        'hashes' => ['sha1' => sha1('new')],
                    ],
                );
            } catch (Throwable $e) {
                $caught = $e;
            }
            self::assertNotNull($caught);
            self::assertNotInstanceOf(\Error::class, $caught);
            self::assertSame($originalProps, $this->serverFile($server, 'server.properties'));
            self::assertFalse($this->hasServerFile($server, ResourcePackService::METADATA_FILENAME));

            $serverIdStr = (string) $server->getKey();
            $matchingKeys = array_filter(
                $lockLog['acquired'],
                static fn (string $k): bool => str_contains($k, $serverIdStr) || str_contains($k, $server->uuid),
            );
            self::assertNotEmpty(
                $matchingKeys,
                'M2 (#8): ResourcePackService::installOrUpdate() must acquire a per-server cache lock.',
            );
            self::assertSame(0, $lockLog['in_flight'], 'M2 (#8): ResourcePackService must release its per-server lock in finally on failure.');

            // 2. Clean installOrUpdate() and uninstall() both acquire and release the per-server lock
            $acquiredBeforeInstall = count($lockLog['acquired']);
            $ok = $rpService->installOrUpdate(
                $server,
                $repo,
                [
                    'source' => ProjectSourceKey::Modrinth->value,
                    'project_id' => 'rpok',
                    'slug' => 'rp-ok',
                    'title' => 'RP OK',
                ],
                ['id' => 'v2', 'version_number' => '2.0'],
                [
                    'filename' => 'rp2.zip',
                    'url' => 'https://cdn.modrinth.com/data/rpok/rp2.zip',
                    'hashes' => ['sha1' => sha1('new2')],
                ],
            );
            self::assertSame('rp2.zip', $ok['filename']);
            self::assertGreaterThan($acquiredBeforeInstall, count($lockLog['acquired']));
            self::assertSame(0, $lockLog['in_flight']);

            $acquiredBeforeUninstall = count($lockLog['acquired']);
            $rpService->uninstall($server, $repo);
            self::assertGreaterThan($acquiredBeforeUninstall, count($lockLog['acquired']), 'M2 (#8): ResourcePackService::uninstall() must acquire the per-server lock.');
            self::assertSame(0, $lockLog['in_flight']);

            // 3. Contention: when another worker holds the lock (LockTimeoutException), installOrUpdate() aborts with 0 Wings writes!
            $propsBeforeContention = $this->serverFile($server, 'server.properties');
            $this->wings->resetMetrics();
            $lockLog['block_with_timeout'] = true;

            $contentionErr = null;
            try {
                $rpService->installOrUpdate(
                    $server,
                    $repo,
                    [
                        'source' => ProjectSourceKey::Modrinth->value,
                        'project_id' => 'rpbusy',
                        'slug' => 'rp-busy',
                        'title' => 'RP Busy',
                    ],
                    ['id' => 'v3', 'version_number' => '3.0'],
                    [
                        'filename' => 'rp3.zip',
                        'url' => 'https://cdn.modrinth.com/data/rpbusy/rp3.zip',
                        'hashes' => ['sha1' => sha1('new3')],
                    ],
                );
            } catch (Throwable $e) {
                $contentionErr = $e;
            }
            self::assertNotNull($contentionErr, 'M2 (#8): Contended per-server lock must prevent concurrent ResourcePackService mutation.');
            self::assertNotInstanceOf(\Error::class, $contentionErr);
            $this->wings->assertEndpointCalled('putStream', 0, 'M2 (#8): Lock contention must abort before any Wings write.');
            self::assertSame($propsBeforeContention, $this->serverFile($server, 'server.properties'));
        } finally {
            config(['cache.default' => $previousDefault]);
            Cache::setDefaultDriver($previousDefault);
            $this->app->forgetInstance('cache.store');
            $this->app->forgetInstance(ResourcePackService::class);
        }
    }

    public function test_upstream_http_retries_429_408_503_and_connection_errors_boundedly_but_never_retries_400_403_404(): void
    {
        $counts = ['429' => 0, '503' => 0, '408' => 0, 'conn' => 0, '404' => 0, '400' => 0, '403' => 0, 'persist503' => 0];
        $this->fakeUpstreamHttp([
            'api.modrinth.com/retry-429' => static function () use (&$counts) {
                $counts['429']++;

                return $counts['429'] === 1
                    ? Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '0'])
                    : Http::response(['ok' => true], 200);
            },
            'api.modrinth.com/retry-503' => static function () use (&$counts) {
                $counts['503']++;

                return $counts['503'] === 1 ? Http::response(['error' => 'down'], 503) : Http::response(['ok' => true], 200);
            },
            'api.modrinth.com/retry-408' => static function () use (&$counts) {
                $counts['408']++;

                return $counts['408'] === 1 ? Http::response(['error' => 'timeout'], 408) : Http::response(['ok' => true], 200);
            },
            'api.modrinth.com/retry-conn' => static function (HttpClientRequest $req) use (&$counts) {
                $counts['conn']++;
                if ($counts['conn'] === 1) {
                    throw new ConnectException('cURL error 7: Failed to connect', $req->toPsrRequest());
                }

                return Http::response(['ok' => true], 200);
            },
            'api.modrinth.com/no-retry-404' => static function () use (&$counts) {
                $counts['404']++;

                return Http::response(['error' => 'not found'], 404);
            },
            'api.modrinth.com/no-retry-400' => static function () use (&$counts) {
                $counts['400']++;

                return Http::response(['error' => 'bad request'], 400);
            },
            'api.modrinth.com/no-retry-403' => static function () use (&$counts) {
                $counts['403']++;

                return Http::response(['error' => 'forbidden'], 403);
            },
            'api.modrinth.com/persist-503' => static function () use (&$counts) {
                $counts['persist503']++;

                return Http::response(['error' => 'always down'], 503);
            },
        ]);

        self::assertSame(200, UpstreamHttp::json()->get('https://api.modrinth.com/retry-429')->status(), 'M2 (#9): UpstreamHttp must retry 429 Retry-After: 0.');
        self::assertSame(2, $counts['429']);
        self::assertSame(200, UpstreamHttp::json()->get('https://api.modrinth.com/retry-503')->status(), 'M2 (#9): UpstreamHttp must retry 503.');
        self::assertSame(2, $counts['503']);
        self::assertSame(200, UpstreamHttp::json()->get('https://api.modrinth.com/retry-408')->status(), 'M2 (#9): UpstreamHttp must retry 408.');
        self::assertSame(2, $counts['408']);
        self::assertSame(200, UpstreamHttp::json()->get('https://api.modrinth.com/retry-conn')->status(), 'M2 (#9): UpstreamHttp must retry ConnectionException.');
        self::assertSame(2, $counts['conn']);

        // Non-retryable 4xx must execute ONCE only
        self::assertSame(404, UpstreamHttp::json()->get('https://api.modrinth.com/no-retry-404')->status());
        self::assertSame(1, $counts['404']);
        self::assertSame(400, UpstreamHttp::json()->get('https://api.modrinth.com/no-retry-400')->status());
        self::assertSame(1, $counts['400']);
        self::assertSame(403, UpstreamHttp::json()->get('https://api.modrinth.com/no-retry-403')->status());
        self::assertSame(1, $counts['403']);

        // Persistent 503 terminates within bounded retries
        $persist = UpstreamHttp::json()->get('https://api.modrinth.com/persist-503');
        self::assertSame(503, $persist->status());
        self::assertGreaterThanOrEqual(2, $counts['persist503']);
        self::assertLessThanOrEqual(4, $counts['persist503']);
    }

    public function test_wings_remote_filesystem_retries_pre_send_curl_errors_and_idempotent_reads_but_does_not_retry_post_send_mutating_timeout(): void
    {
        $egg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(72));
        $server = $this->createServer($egg);
        /** @var DaemonFileRepository $repo */
        $repo = $this->app->make(DaemonFileRepository::class);
        /** @var WingsRemoteFilesystem $wingsFs */
        $wingsFs = $this->app->make(WingsRemoteFilesystem::class);

        $this->seedServerFile($server, 'mods/a.jar', "PK\x03\x04hello-jar");

        // 1. Pre-send cURL 6 / 7 / 35 / 28 Resolving & Connection timed out recover cleanly on listDirectory!
        foreach ([
            StrictWingsFake::CURL_ERR_6_DNS,
            StrictWingsFake::CURL_ERR_7_REFUSED,
            StrictWingsFake::CURL_ERR_35_SSL,
            StrictWingsFake::CURL_ERR_28_RESOLVE,
            StrictWingsFake::CURL_ERR_28_CONNECT,
        ] as $curlMsg) {
            $this->wings->injectConnectionError('listDirectory', $curlMsg, 1);
            try {
                $listed = $wingsFs->listDirectory($repo, $server, 'mods');
            } catch (ConnectionException $e) {
                self::fail("M2 (#10): WingsRemoteFilesystem must retry pre-send [{$curlMsg}]: ".$e->getMessage());
            }
            self::assertNotEmpty($listed);
        }

        // 1b. Mutating move() (rename) and delete() also retry pre-send cURL 7 / 28 Resolving timed out safely!
        $this->seedServerFile($server, 'mods/move-src.jar', "PK\x03\x04move-me");
        $this->wings->injectConnectionError('rename', StrictWingsFake::CURL_ERR_7_REFUSED, 1);
        try {
            $wingsFs->move($repo, $server, 'mods', 'move-src.jar', 'move-dst.jar');
        } catch (ConnectionException $e) {
            self::fail('M2 (#10): Mutating move() must retry pre-send cURL 7: '.$e->getMessage());
        }
        self::assertTrue($this->hasServerFile($server, 'mods/move-dst.jar'));
        self::assertFalse($this->hasServerFile($server, 'mods/move-src.jar'));

        $this->wings->injectConnectionError('delete', StrictWingsFake::CURL_ERR_28_RESOLVE, 1);
        try {
            $wingsFs->delete($repo, $server, 'mods', 'move-dst.jar');
        } catch (ConnectionException $e) {
            self::fail('M2 (#10): Mutating delete() must retry pre-send cURL 28 Resolving timed out: '.$e->getMessage());
        }
        self::assertFalse($this->hasServerFile($server, 'mods/move-dst.jar'));

        // 2. Idempotent readStream retries transient 503
        $this->wings->injectHttpError('readStream', 503, ['error' => 'restarting'], 1);
        try {
            $stream = $wingsFs->readStream($repo, $server, 'mods/a.jar');
            self::assertSame("PK\x03\x04hello-jar", (string) $stream);
        } catch (RequestException $e) {
            self::fail('M2 (#10): Idempotent readStream() must retry transient HTTP 503: '.$e->getMessage());
        }

        // 3. readPrefix handles HTTP 200 OK fallback (server ignoring Range) safely returning 4 bytes
        $this->wings->setRangeResponseEnabled(false);
        self::assertSame("PK\x03\x04", $wingsFs->readPrefix($repo, $server, 'mods', 'a.jar', 4));

        // 4. Post-send Operation timed out on mutating delete MUST NOT blindly retry!
        $this->wings->resetMetrics();
        $this->wings->injectConnectionError('delete', StrictWingsFake::CURL_ERR_28_OPERATION, 1);
        $caught = null;
        try {
            $wingsFs->delete($repo, $server, 'mods', 'a.jar');
        } catch (ConnectionException $e) {
            $caught = $e;
        }
        self::assertInstanceOf(ConnectionException::class, $caught);
        $this->wings->assertEndpointCalled('delete', 1, 'M2 (#10): Post-send cURL 28 Operation timed out on mutating delete must NOT retry.');
    }

    public function test_source_cache_lock_timeout_does_not_write_outage_marker_or_dispatch_duplicate_revalidation(): void
    {
        $runner = PluginBackgroundRunner::fake();
        $store = new class extends ArrayStore
        {
            public function lock($name, $seconds = 0, $owner = null): Lock
            {
                return new class implements Lock
                {
                    public function get($callback = null)
                    {
                        return false;
                    }

                    public function block($seconds, $callback = null)
                    {
                        throw new LockTimeoutException('Simulated lock contention timeout.');
                    }

                    public function release()
                    {
                        return true;
                    }

                    public function owner()
                    {
                        return 'other';
                    }

                    public function forceRelease() {}
                };
            }
        };

        $cacheRepo = new CacheRepository($store);
        $executor = new class implements SourceFetchExecutorInterface
        {
            public function fetch(SourceFetchSpec $spec, float $timeoutSeconds): mixed
            {
                return ['hits' => [['id' => 'never-called']], 'total_hits' => 1];
            }

            public function emptyResult(SourceFetchSpec $spec): mixed
            {
                return ['hits' => [], 'total_hits' => 0];
            }
        };

        $this->app->instance(PluginBackgroundRunner::class, $runner);
        $this->app->forgetInstance(InstalledOperationManager::class);
        $operations = $this->app->make(InstalledOperationManager::class);
        $sourceCache = new SourceCache($cacheRepo, $operations, $executor);
        $spec = new SourceFetchSpec('modrinth', 'search', ['query' => 'lock-storm']);
        $result = $sourceCache->swr($spec, CacheProfile::Search);

        self::assertSame(['hits' => [], 'total_hits' => 0], $result);
        $peeked = $sourceCache->peek($spec);
        self::assertFalse($peeked['hit']);
        self::assertFalse(
            $peeked['retry_delayed'],
            'M2 (#11): LockTimeoutException on cold miss must NEVER poison cache with a 30s outage failure marker.',
        );
        self::assertSame(
            [],
            $runner->spawned,
            'M2 (#11): LockTimeoutException must NOT dispatch duplicate background revalidation.',
        );
    }

    public function test_catalog_filter_option_endpoints_back_off_during_upstream_outage_instead_of_hammering_api(): void
    {
        $modrinthTagCalls = 0;
        $cfCatCalls = 0;
        $hangarVerCalls = 0;

        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/tag/game_version*' => static function () use (&$modrinthTagCalls) {
                $modrinthTagCalls++;

                return Http::response(['error' => 'modrinth down'], 500);
            },
            'api.curseforge.com/v1/categories*' => static function () use (&$cfCatCalls) {
                $cfCatCalls++;

                return Http::response(['error' => 'curseforge down'], 500);
            },
            'hangar.papermc.io/api/v1/platforms/PAPER/versions*' => static function () use (&$hangarVerCalls) {
                $hangarVerCalls++;

                return Http::response(['error' => 'hangar down'], 500);
            },
        ]);

        /** @var ModrinthSource $modrinth */
        $modrinth = $this->app->make(ModrinthSource::class);
        $modrinth->catalogVersionOptions();
        $modrinthAfterFirst = $modrinthTagCalls;
        self::assertGreaterThanOrEqual(1, $modrinthAfterFirst);
        $modrinth->catalogVersionOptions();
        $modrinth->catalogVersionOptions();
        self::assertSame(
            $modrinthAfterFirst,
            $modrinthTagCalls,
            'M2 (#11): Modrinth filter-option outage must back off via SWR/failure marker instead of repeating blocking requests on every render.',
        );

        /** @var CurseForgeSource $curseforge */
        $curseforge = $this->app->make(CurseForgeSource::class);
        $curseforge->catalogCategoryOptions(ProjectType::Mod);
        $cfAfterFirst = $cfCatCalls;
        self::assertGreaterThanOrEqual(1, $cfAfterFirst);
        $curseforge->catalogCategoryOptions(ProjectType::Mod);
        $curseforge->catalogCategoryOptions(ProjectType::Mod);
        self::assertSame(
            $cfAfterFirst,
            $cfCatCalls,
            'M2 (#11): CurseForge filter-option outage must back off via SWR/failure marker instead of repeating blocking requests on every render.',
        );

        /** @var HangarSource $hangar */
        $hangar = $this->app->make(HangarSource::class);
        $hangar->catalogVersionOptions('PAPER');
        $hangarAfterFirst = $hangarVerCalls;
        self::assertGreaterThanOrEqual(1, $hangarAfterFirst);
        $hangar->catalogVersionOptions('PAPER');
        $hangar->catalogVersionOptions('PAPER');
        self::assertSame(
            $hangarAfterFirst,
            $hangarVerCalls,
            'M2 (#11): Hangar filter-option outage must back off via SWR/failure marker instead of repeating blocking requests on every render.',
        );
    }

    public function test_archive_transaction_aborts_on_size_mismatch_without_touching_live_jar_and_asset_controller_checks_etag(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(73));
        $server = $this->createServer($fabricEgg);

        $liveBytes = "PK\x03\x04live-v1-jar";
        $this->seedServerFile($server, 'mods/target.jar', $liveBytes);
        $this->seedServerFile($server, 'mods/.pelican-mod-manager.json', json_encode([
            'schema_version' => 2,
            'installed_mods' => [[
                'source' => ProjectSourceKey::Modrinth->value,
                'project_id' => 'p1',
                'project_slug' => 'p1',
                'project_title' => 'P1',
                'version_id' => 'v1',
                'version_number' => '1.0',
                'filename' => 'target.jar',
                'installed_at' => gmdate('c'),
            ]],
            'unresolved_files' => [],
        ], JSON_THROW_ON_ERROR));

        $actualPulled = "PK\x03\x04truncated-v2";
        $this->registerRemoteFile('https://cdn.modrinth.com/data/p1/target-v2.jar', $actualPulled);

        $caught = null;
        try {
            $this->app->make(InstalledArchiveTransaction::class)->installOrUpdate(
                $server,
                $this->app->make(DaemonFileRepository::class),
                ProjectType::Mod,
                ['source' => ProjectSourceKey::Modrinth->value, 'project_id' => 'p1', 'slug' => 'p1', 'title' => 'P1'],
                ['id' => 'v2', 'version_number' => '2.0'],
                [
                    'filename' => 'target.jar',
                    'url' => 'https://cdn.modrinth.com/data/p1/target-v2.jar',
                    'size' => strlen($actualPulled) + 50,
                    'hashes' => [],
                ],
                ['source' => ProjectSourceKey::Modrinth->value, 'project_id' => 'p1', 'project_slug' => 'p1', 'project_title' => 'P1', 'version_id' => 'v1', 'version_number' => '1.0', 'filename' => 'target.jar'],
            );
        } catch (Exception $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertStringContainsString('size', strtolower($caught->getMessage()));
        self::assertSame($liveBytes, $this->serverFile($server, 'mods/target.jar'));
        $this->wings->assertNoTemporaryModManagerFiles($server->uuid);

        // Stale ETag returns 200 with payload; matching ETag returns 304
        $assetDef = ModManagerAssets::get('table-swr-cache.js');
        $staleReq = Request::create('/assets', 'GET', [], [], [], [
            'HTTP_ACCEPT_ENCODING' => 'gzip',
            'HTTP_IF_NONE_MATCH' => '"stale-etag-value"',
        ]);
        $staleResp = (new ModManagerAssetController())($staleReq, $assetDef['version'], 'table-swr-cache.js');
        self::assertSame(200, $staleResp->getStatusCode());
        self::assertNotEmpty($staleResp->getContent());
    }
}
