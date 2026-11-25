<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier1;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Support\ArrayRecord;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\ModManagerPlugin;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Services\ResourcePackService;
use Kazaminosuke\ModManager\Support\BackgroundJobRuntime;
use Kazaminosuke\ModManager\Support\DatabaseBackgroundJobQueue;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use Kazaminosuke\ModManager\Tests\E2E\Support\StrictWingsFake;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Throwable;

/**
 * Tier 1 — Isolated Feature Coverage for Core Features #5, #6, #7, #8 (M2).
 */
final class CoreSettingsScanAndOpsE2ETest extends E2ETestCase
{
    public function test_settings_form_data_and_round_trip_save_preserve_modrinth_token_and_hangar_api_key(): void
    {
        config([
            'pelican-mod-manager.latest_minecraft_version' => '26.1.2',
            'pelican-mod-manager.navigation_sort.mod' => 11,
            'pelican-mod-manager.navigation_sort.plugin' => 12,
            'pelican-mod-manager.navigation_sort.datapack' => 13,
            'pelican-mod-manager.navigation_sort.resourcepack' => 14,
            'pelican-mod-manager.curseforge_api_key' => 'cf_secret_key_1',
            'pelican-mod-manager.modrinth_token' => 'mrp_secret_token_2',
            'pelican-mod-manager.hangar_api_key' => 'hangar_secret_key_3',
            'pelican-mod-manager.github_token' => 'ghp_secret_token_4',
            'pelican-mod-manager.allow_user_egg_profile_edit' => true,
            'pelican-mod-manager.allow_user_project_install' => true,
            'pelican-mod-manager.allow_user_project_update' => false,
            'pelican-mod-manager.allow_user_project_delete' => false,
        ]);

        $writtenEnv = [];
        $plugin = new class($writtenEnv) extends ModManagerPlugin
        {
            /** @param array<string, mixed> $captured */
            public function __construct(private array &$captured) {}

            public function writeToEnvironment(array $values = []): void
            {
                $this->captured = $values;
            }
        };

        $formData = $plugin->getSettingsFormData();
        $expectedFormValues = [
            'latest_minecraft_version' => '26.1.2',
            'mod_nav_sort' => 11,
            'plugin_nav_sort' => 12,
            'datapack_nav_sort' => 13,
            'resourcepack_nav_sort' => 14,
            'curseforge_api_key' => 'cf_secret_key_1',
            'modrinth_token' => 'mrp_secret_token_2',
            'hangar_api_key' => 'hangar_secret_key_3',
            'github_token' => 'ghp_secret_token_4',
            'allow_user_egg_profile_edit' => true,
            'allow_user_project_install' => true,
            'allow_user_project_update' => false,
            'allow_user_project_delete' => false,
        ];
        foreach ($expectedFormValues as $key => $expected) {
            self::assertArrayHasKey($key, $formData, "M2 (#5): getSettingsFormData() must include {$key}.");
            self::assertSame($expected, $formData[$key], "M2 (#5): getSettingsFormData()[{$key}] mismatch.");
        }

        // Simulate admin changing an unrelated setting and saving the form state back.
        $formData['latest_minecraft_version'] = '26.1.3';
        $plugin->saveSettings($formData);

        self::assertSame('mrp_secret_token_2', $writtenEnv['MOD_MANAGER_MODRINTH_TOKEN'] ?? null);
        self::assertSame('hangar_secret_key_3', $writtenEnv['MOD_MANAGER_HANGAR_API_KEY'] ?? null);
        self::assertSame('cf_secret_key_1', $writtenEnv['MOD_MANAGER_CURSEFORGE_API_KEY'] ?? null);
        self::assertSame('ghp_secret_token_4', $writtenEnv['MOD_MANAGER_GITHUB_TOKEN'] ?? null);
        self::assertSame('26.1.3', $writtenEnv['MOD_MANAGER_LATEST_MINECRAFT_VERSION'] ?? null);
    }

    public function test_sequential_single_file_scan_throws_on_wings_http_error_instead_of_hashing_error_json(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(20));
        $server = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1']);
        $this->seedServerFile($server, 'mods/only-mod.jar', "PK\x03\x04valid-jar-bytes");
        $this->seedServerFile($server, 'mods/.pelican-mod-manager.json', json_encode([
            'schema_version' => 2,
            'installed_mods' => [],
            'unresolved_files' => [],
        ], JSON_THROW_ON_ERROR));

        $errorJson = '{"error":"daemon unavailable"}';
        $errorSha512 = hash('sha512', $errorJson);

        // Use a non-throwing DaemonFileRepository HTTP client to prove InstalledProjectService::openDaemonFileStream()
        // itself calls $response->throw() (M2 #6) instead of silently hashing HTTP 500 JSON as a JAR.
        $this->wings->injectHttpError('readStream', 500, $errorJson, 1, 'only-mod.jar');
        $nonThrowingRepo = new class($server) extends DaemonFileRepository
        {
            public function __construct(private readonly Server $e2eServer) {}

            public function getHttpClient(array $headers = []): PendingRequest
            {
                return Http::acceptJson()
                    ->withToken($this->e2eServer->node->daemon_token)
                    ->withHeaders($headers)
                    ->baseUrl($this->e2eServer->node->getConnectionAddress());
            }
        };

        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/version_files' => Http::response([], 200),
            'api.curseforge.com/v1/fingerprints/432' => Http::response(['data' => ['exactMatches' => []]], 200),
        ]);

        /** @var InstalledProjectService $service */
        $service = $this->app->make(InstalledProjectService::class);
        $result = $service->scanAndImportModsResult($server, $nonThrowingRepo, ProjectType::Mod, force: true);

        // If openDaemonFileStream() fails to call $response->throw(), the 500 JSON is hashed and
        // sent to Modrinth /v2/version_files as a real JAR hash (and recorded as a clean fresh scan!).
        Http::assertNotSent(static function (Request $request) use ($errorSha512): bool {
            return str_contains($request->url(), 'version_files')
                && str_contains($request->body(), $errorSha512);
        });
        self::assertTrue(
            !$result->successful || !$result->isFresh(),
            'M2 (#6): Sequential scan must treat Wings HTTP 500 on /files/contents as a hash failure, never hash the JSON error body as a fresh scan.',
        );
    }

    public function test_active_modpack_blocks_scan_and_bulk_update_and_worker_reset_clears_progress_buffer(): void
    {
        $runner = PluginBackgroundRunner::fake();
        $this->app->instance(PluginBackgroundRunner::class, $runner);

        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(21));
        $server = $this->createServer($fabricEgg);

        /** @var InstalledOperationManager $operations */
        $operations = $this->app->make(InstalledOperationManager::class);
        /** @var InstalledOperationLease $leases */
        $leases = $this->app->make(InstalledOperationLease::class);

        // Acquire active OPERATION_MODPACK lease + running state.
        $lease = $leases->tryAcquire((int) $server->getKey(), ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK);
        self::assertNotNull($lease);
        $operations->start($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK, total: 10);

        $scanDispatch = $operations->dispatchScan($server, ProjectType::Mod, actorUserId: 1);
        self::assertFalse(
            $scanDispatch['dispatched'] ?? true,
            'M2 (#7): Active OPERATION_MODPACK must prevent concurrent dispatchScan().',
        );
        self::assertNotNull($scanDispatch['state']);
        self::assertSame(InstalledOperationManager::OPERATION_MODPACK, $scanDispatch['state']->operation);

        $bulkDispatch = $operations->dispatchBulkUpdate($server, ProjectType::Mod);
        self::assertFalse(
            $bulkDispatch['dispatched'] ?? true,
            'M2 (#7): Active OPERATION_MODPACK must prevent concurrent dispatchBulkUpdate().',
        );
        self::assertNotNull($bulkDispatch['state']);
        self::assertSame(InstalledOperationManager::OPERATION_MODPACK, $bulkDispatch['state']->operation);
        self::assertSame([], $runner->spawned, 'No background jobs may be spawned while OPERATION_MODPACK is active.');

        // Verify DatabaseBackgroundJobQueue basic lifecycle (push -> hasPending -> claim -> heartbeat -> ack).
        $queue = new DatabaseBackgroundJobQueue();
        self::assertTrue($queue->push(BackgroundJob::SCAN, ['server_id' => $server->getKey(), '_unique_key' => 'scan-1']));
        self::assertTrue($queue->hasPending());
        $claimed = $queue->claim();
        self::assertNotNull($claimed);
        self::assertSame(BackgroundJob::SCAN, $claimed['type']);
        self::assertTrue($queue->heartbeat($claimed['id'], $claimed['attempts']));
        $queue->ack($claimed['id'], $claimed['attempts']);
        self::assertFalse($queue->hasPending());
    }

    public function test_background_job_runtime_forget_request_state_clears_operation_progress_buffer(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(23));
        $server = $this->createServer($fabricEgg);

        /** @var InstalledOperationManager $operations */
        $operations = $this->app->make(InstalledOperationManager::class);
        $operations->start($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK, total: 10);

        // Rapid progress() ticks buffer in-memory on the singleton (progress=1 flushes; progress=2 stays buffered).
        $operations->progress($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK, 1, 10);
        $operations->progress($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK, 2, 10);
        self::assertSame(2, $operations->state($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK)?->progress);

        BackgroundJobRuntime::forgetRequestState();

        // Simulate a genuinely separate worker completing the operation in shared cache while keeping
        // the original $operations singleton reference intact (never invoking `new InstalledOperationManager(...)`
        // directly so M2 constructor cleanup cannot break the test).
        $this->app->forgetInstance(InstalledOperationManager::class);
        /** @var InstalledOperationManager $otherWorkerOps */
        $otherWorkerOps = $this->app->make(InstalledOperationManager::class);
        self::assertNotSame($operations, $otherWorkerOps);
        $otherWorkerOps->complete($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);

        $stateAfter = $operations->state($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK);
        self::assertNotNull($stateAfter);
        self::assertSame(
            InstalledOperationState::STATUS_COMPLETED,
            $stateAfter->status,
            'M2 (#7): BackgroundJobRuntime::forgetRequestState() must clear InstalledOperationManager::$progressBuffer so stale in-memory progress cannot shadow shared cache updates.',
        );
    }

    public function test_resource_pack_service_handles_missing_server_properties_with_lock(): void
    {
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(22));
        $server = $this->createServer($fabricEgg);
        /** @var DaemonFileRepository $repo */
        $repo = $this->app->make(DaemonFileRepository::class);

        /** @var ResourcePackService $rpService */
        $rpService = $this->app->make(ResourcePackService::class);
        $record = [
            'source' => ProjectSourceKey::Modrinth->value,
            'project_id' => 'rp123',
            'slug' => 'faithful-e2e',
            'title' => 'Faithful E2E',
            'author' => 'artist',
        ];
        $version = ['id' => 'ver1', 'version_number' => '1.0.0'];
        $primary = [
            'filename' => 'faithful.zip',
            'url' => 'https://cdn.modrinth.com/data/rp123/faithful.zip',
            'hashes' => ['sha1' => sha1('resource-pack-bytes')],
        ];

        // M2 (#8): Missing server.properties on fresh server must NOT crash with raw FileNotFoundException;
        // valid implementations either create server.properties cleanly OR throw a non-FileNotFoundException domain error.
        $createdProperties = false;
        try {
            $installed = $rpService->installOrUpdate($server, $repo, $record, $version, $primary);
            $createdProperties = true;
            self::assertSame('faithful.zip', $installed['filename']);
            self::assertTrue($this->hasServerFile($server, 'server.properties'));
            self::assertStringContainsString('resource-pack=', $this->serverFile($server, 'server.properties'));
            self::assertTrue($this->hasServerFile($server, ResourcePackService::METADATA_FILENAME));
        } catch (FileNotFoundException $e) {
            self::fail('M2 (#8): ResourcePackService::installOrUpdate() must handle missing server.properties gracefully instead of leaking raw FileNotFoundException: '.$e->getMessage());
        } catch (Throwable $e) {
            self::assertNotSame('', trim($e->getMessage()));
            self::assertFalse($this->hasServerFile($server, ResourcePackService::METADATA_FILENAME));
        }

        if (!$createdProperties) {
            $this->seedServerFile($server, 'server.properties', "level-name=world\n");
            $installed = $rpService->installOrUpdate($server, $repo, $record, $version, $primary);
            self::assertSame('faithful.zip', $installed['filename']);
            self::assertStringContainsString('resource-pack=', $this->serverFile($server, 'server.properties'));
            self::assertTrue($this->hasServerFile($server, ResourcePackService::METADATA_FILENAME));
        }

        // Uninstall cleanly removes metadata and clears resource-pack entries in server.properties.
        $rpService->uninstall($server, $repo);
        self::assertFalse($this->hasServerFile($server, ResourcePackService::METADATA_FILENAME));
        self::assertStringNotContainsString('faithful.zip', $this->serverFile($server, 'server.properties'));
    }

    #[RunInSeparateProcess]
    public function test_mod_manager_page_uninstall_routes_through_wings_abstraction_and_survives_pre_send_curl_retry(): void
    {
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        $user = $this->createUser(rootAdmin: true);
        $this->actingAs($user);

        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(24));
        $server = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1', 'LOADER_VERSION' => '0.16.10']);
        $this->seedServerFile($server, 'server.properties', "level-name=world\n");
        $this->seedServerFile($server, 'mods/removable-mod.jar', "PK\x03\x04removable-mod-bytes");
        $this->seedServerFile($server, 'mods/.pelican-mod-manager.json', json_encode([
            'schema_version' => 2,
            'installed_mods' => [[
                'source' => ProjectSourceKey::Modrinth->value,
                'project_id' => 'rem1',
                'project_slug' => 'removable-mod',
                'project_title' => 'Removable Mod',
                'version_id' => 'v1',
                'version_number' => '1.0.0',
                'filename' => 'removable-mod.jar',
                'installed_at' => gmdate('c'),
            ]],
            'unresolved_files' => [],
        ], JSON_THROW_ON_ERROR));

        $this->useServerPanel($server);

        $page = Livewire::test(ModManagerPage::class)
            ->set('activeTab', 'installed')
            ->call('loadTable');

        $pageInstance = $page->instance();
        assert($pageInstance instanceof ModManagerPage);
        $records = $pageInstance->getTableRecords();
        $items = $records instanceof Paginator ? $records->items() : $records->all();
        $firstKey = array_key_first($items);
        self::assertNotNull($firstKey, 'Installed tab must render the seeded mod row.');
        $firstRecord = $items[$firstKey];
        $keyName = ArrayRecord::getKeyName();
        $recordKey = is_array($firstRecord) && isset($firstRecord[$keyName])
            ? (string) $firstRecord[$keyName]
            : (string) $firstKey;

        // Inject transient pre-send cURL error 7 on Wings /files/delete:
        // Raw Http::daemon() fails without retry (file survives), whereas WingsRemoteFilesystem::delete() retries and deletes!
        $this->wings->resetMetrics();
        $this->wings->injectConnectionError('delete', StrictWingsFake::CURL_ERR_7_REFUSED, 1);

        $page->callTableAction('uninstall', $recordKey);

        self::assertFalse(
            $this->hasServerFile($server, 'mods/removable-mod.jar'),
            'M2 (#8): ModManagerPage uninstall must delegate to WingsRemoteFilesystem so transient pre-send cURL 7 retries and deletes the file.',
        );
        $this->wings->assertEndpointCalled(
            'delete',
            2,
            'M2 (#8): Uninstall through WingsRemoteFilesystem must retry once after pre-send cURL error 7.',
        );
        $metadata = json_decode($this->serverFile($server, 'mods/.pelican-mod-manager.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $metadata['installed_mods'] ?? null);
    }
}
