<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadResult;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\InstalledScanResult;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class TestableModManagerPage extends ModManagerPage
{
    public bool $returnNullInstalledOperationForTest = false;

    public int $installedScanRefreshesForTest = 0;

    /** @param array<int, ProjectSourceInterface> $sources */
    public function __construct(private readonly array $sources = []) {}

    protected function getAvailableSources(): array
    {
        return $this->sources;
    }

    /** @return array<int, string> */
    public function catalogSourceKeysForTest(): array
    {
        return array_map(
            static fn (ProjectSourceInterface $source): string => $source->getKey()->value,
            $this->getCatalogSources(),
        );
    }

    public function clampTablePageForTest(int $page, int $total): int
    {
        return $this->clampTablePage($page, $total);
    }

    public static function navigationSortForTest(ProjectType $type): int
    {
        return self::navigationSortFor($type);
    }

    public function catalogTabForSourceTest(?string $source): ?string
    {
        return $this->catalogTabForSource($source);
    }

    public function sourceForTabTest(string|int|null $tab): ?string
    {
        return $this->sourceForTab($tab);
    }

    public function syncSourceFromActiveTabForTest(?string $activeTab): ?string
    {
        $this->syncSourceFromActiveTab($activeTab);

        return $this->source;
    }

    public function markOperationHandledForTest(InstalledOperationState $state): void
    {
        $this->markInstalledOperationHandled($state);
    }

    public function shouldPollForTest(?InstalledOperationState $state): bool
    {
        return $this->shouldPollInstalledOperation($state);
    }

    public function applyInstalledOperationForTest(?InstalledOperationState $state): void
    {
        $this->setInstalledOperationState($state);
    }

    /** @param array<string, mixed>|null $previousOperation */
    public function shouldSkipInstalledOperationPollRenderForTest(?array $previousOperation, InstalledOperationState $state): bool
    {
        return $this->shouldSkipInstalledOperationPollRender($previousOperation, $state);
    }

    public function displayProjectFolderForTest(Server $server, DaemonFileRepository $fileRepository, ProjectType $type): string
    {
        return $this->getDisplayProjectFolder($server, $fileRepository, $type);
    }

    public function rememberScanCompletionForTest(InstalledOperationState $state): void
    {
        $this->rememberInstalledScanCompletion($state);
    }

    public function shouldShowOperationStatusForTest(): bool
    {
        return $this->shouldShowInstalledOperationStatus();
    }

    /** @return array<string, string> */
    public function operationStatusAttributesForTest(): array
    {
        return $this->installedOperationStatusExtraAttributes();
    }

    public function formatExternalProjectDateForTest(mixed $value): string
    {
        return $this->formatExternalProjectDate($value);
    }

    public function truncateProjectDescriptionForTest(string $value): string
    {
        return $this->truncateProjectDescription($value);
    }

    public function lowercaseInstalledSearchValueForTest(string $value): string
    {
        return $this->lowercaseInstalledSearchValue($value);
    }

    public int $tablePageForTest = 1;

    public ?string $tableSearchForTest = null;

    public function catalogPagesToWarmForTest(bool $includeOtherSources = true): array
    {
        return $this->catalogPagesToWarm($includeOtherSources);
    }

    /**
     * @return array{queued: array<int, array{sourceKey: string, page: int}>, immediate: array<int, array{sourceKey: string, page: int}>}
     */
    public function catalogWarmPlanForTest(bool $includeOtherSources = true): array
    {
        return $this->catalogWarmPlan($includeOtherSources);
    }

    public function shouldPublishPerformanceProfilerForTest(): bool
    {
        return $this->shouldPublishPerformanceProfiler();
    }

    public function getTablePage(): int
    {
        return $this->tablePageForTest;
    }

    public function getTableSearch(): ?string
    {
        return $this->tableSearchForTest;
    }

    public int $pollEnrichmentSkipRenderCallsForTest = 0;

    public int $flushCachedTableRecordsCallsForTest = 0;

    public ?string $peekedEnrichmentSignatureForTest = null;

    public function currentInstalledModForOperationForTest(Server $server, DaemonFileRepository $files, ProjectType $type, string $projectId, ProjectSourceKey $source): ?array
    {
        return $this->getCurrentInstalledModForOperation($server, $files, $type, $projectId, $source);
    }

    public function skipRender($html = null)
    {
        $this->pollEnrichmentSkipRenderCallsForTest++;

        return $this;
    }

    public function flushCachedTableRecords(): void
    {
        $this->flushCachedTableRecordsCallsForTest++;
    }

    protected function peekInstalledEnrichmentSignature(): ?string
    {
        return $this->peekedEnrichmentSignatureForTest;
    }

    protected function peekCatalogEnrichmentSignature(): ?string
    {
        return $this->peekedEnrichmentSignatureForTest;
    }

    protected function refreshInstalledOperationState(
        ?InstalledOperationState $scanState = null,
        ?InstalledOperationState $bulkState = null,
        bool $preloaded = false,
    ): ?InstalledOperationState {
        if ($this->returnNullInstalledOperationForTest) {
            $this->installedOperation = null;

            return null;
        }

        return parent::refreshInstalledOperationState($scanState, $bulkState, $preloaded);
    }

    protected function refreshInstalledScanDataReady(): void
    {
        if ($this->returnNullInstalledOperationForTest) {
            $this->installedScanRefreshesForTest++;
            $this->installedFilesCount = 4;
            $this->installedScanDataReady = true;

            return;
        }

        parent::refreshInstalledScanDataReady();
    }
}

class ModManagerPagePayloadTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_catalog_page_uses_livewire_url_sync_without_page_one(): void
    {
        $property = new ReflectionProperty(ModManagerPage::class, 'catalogPage');
        $attribute = $property->getAttributes(Url::class)[0];

        self::assertSame([
            'as' => 'page',
            'history' => true,
            'keep' => false,
            'except' => 1,
        ], $attribute->getArguments());
    }

    public function test_unknown_files_are_not_part_of_the_livewire_snapshot(): void
    {
        $property = new ReflectionProperty(ModManagerPage::class, 'unknownFiles');

        self::assertTrue($property->isProtected());
        self::assertFalse($property->isPublic());
    }

    public function test_datapack_world_name_is_locked_component_state(): void
    {
        $property = new ReflectionProperty(ModManagerPage::class, 'datapackWorldName');

        self::assertTrue($property->isPublic());
        self::assertFalse($property->isProtected());
        self::assertCount(1, $property->getAttributes(Locked::class));
    }

    public function test_installed_enrichment_signature_is_locked_component_state(): void
    {
        $property = new ReflectionProperty(ModManagerPage::class, 'installedEnrichmentSignature');

        self::assertTrue($property->isPublic());
        self::assertCount(1, $property->getAttributes(Locked::class));
    }

    public function test_display_folder_reuses_the_component_datapack_world_name(): void
    {
        $page = new TestableModManagerPage();
        $page->datapackWorldName = 'custom-world';
        $fileRepository = Mockery::mock(DaemonFileRepository::class);

        self::assertSame(
            'custom-world/datapacks',
            $page->displayProjectFolderForTest(new Server(), $fileRepository, ProjectType::Datapack),
        );
    }

    public function test_persisted_scan_count_invalidates_the_memoized_tab_definition(): void
    {
        $page = new class extends ModManagerPage
        {
            public function primeTabsForTest(): void
            {
                $this->cachedTabs = [];
            }

            public function applyScanResultForTest(?InstalledScanResult $scanResult): void
            {
                $this->setInstalledScanResult($scanResult);
            }

            public function hasCachedTabsForTest(): bool
            {
                return isset($this->cachedTabs);
            }
        };

        $page->primeTabsForTest();
        self::assertTrue($page->hasCachedTabsForTest());

        $page->applyScanResultForTest(InstalledScanResult::success([], 4));

        self::assertSame(4, $page->installedFilesCount);
        self::assertTrue($page->installedScanDataReady);
        self::assertFalse($page->hasCachedTabsForTest());
    }

    public function test_catalog_tab_keeps_polling_an_active_automatic_scan(): void
    {
        $page = new class extends ModManagerPage
        {
            public function shouldPollForTest(?InstalledOperationState $state): bool
            {
                return $this->shouldPollInstalledOperation($state);
            }
        };
        $page->activeTab = 'modrinth';

        $state = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Mod,
        );

        self::assertTrue($page->shouldPollForTest($state));
    }

    public function test_active_operation_poll_skips_render_only_when_its_payload_is_unchanged(): void
    {
        $page = new TestableModManagerPage();
        $page->activeTab = 'installed';
        $queued = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Plugin,
        );
        $page->applyInstalledOperationForTest($queued);

        self::assertTrue($page->shouldSkipInstalledOperationPollRenderForTest($queued->toCachePayload(), $queued));

        $running = $queued->running(10);
        $page->applyInstalledOperationForTest($running);

        self::assertFalse($page->shouldSkipInstalledOperationPollRenderForTest($queued->toCachePayload(), $running));
        self::assertTrue($page->shouldSkipInstalledOperationPollRenderForTest($running->toCachePayload(), $running));

        $completed = $running->completed();
        $page->applyInstalledOperationForTest($completed);

        self::assertFalse($page->shouldSkipInstalledOperationPollRenderForTest($running->toCachePayload(), $completed));
    }

    public function test_catalog_scan_progress_skips_render_while_installed_and_bulk_progress_do_not(): void
    {
        $page = new TestableModManagerPage();
        $page->activeTab = ProjectSourceKey::Modrinth->value;
        $queuedScan = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Plugin,
        );
        $runningScan = $queuedScan->running(10);
        $page->applyInstalledOperationForTest($runningScan);

        self::assertTrue($page->shouldSkipInstalledOperationPollRenderForTest($queuedScan->toCachePayload(), $runningScan));

        $page->activeTab = 'installed';
        self::assertFalse($page->shouldSkipInstalledOperationPollRenderForTest($queuedScan->toCachePayload(), $runningScan));

        $page->activeTab = ProjectSourceKey::Modrinth->value;
        $queuedBulk = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_BULK_UPDATE,
            42,
            ProjectType::Plugin,
        );
        $runningBulk = $queuedBulk->running(10);
        $page->applyInstalledOperationForTest($runningBulk);

        self::assertFalse($page->shouldSkipInstalledOperationPollRenderForTest($queuedBulk->toCachePayload(), $runningBulk));
        self::assertFalse($page->shouldSkipInstalledOperationPollRenderForTest($runningScan->toCachePayload(), $runningScan->completed()));
    }

    public function test_scan_status_is_visible_only_when_installed_tab_observes_the_scan(): void
    {
        $page = new TestableModManagerPage();
        $scan = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Plugin,
        );

        $page->activeTab = ProjectSourceKey::Modrinth->value;
        $page->applyInstalledOperationForTest($scan);
        self::assertFalse($page->shouldShowOperationStatusForTest());
        self::assertNull($page->observedInstalledScan);

        $page->activeTab = 'installed';
        $page->applyInstalledOperationForTest($scan);
        self::assertTrue($page->shouldShowOperationStatusForTest());
        self::assertNotNull($page->observedInstalledScan);
    }

    public function test_scan_completion_is_short_lived_and_uses_an_absolute_deadline(): void
    {
        $page = new TestableModManagerPage();
        $page->activeTab = 'installed';
        $scan = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Datapack,
            now: new \DateTimeImmutable('-1 minute'),
        );
        $page->applyInstalledOperationForTest($scan);
        $completed = $scan->completed([], new \DateTimeImmutable('-1 second'));
        $page->applyInstalledOperationForTest($completed);
        $page->rememberScanCompletionForTest($completed);

        self::assertTrue($page->shouldShowOperationStatusForTest());
        $firstAttributes = $page->operationStatusAttributesForTest();
        $secondAttributes = $page->operationStatusAttributesForTest();
        self::assertSame($firstAttributes, $secondAttributes);
        self::assertArrayHasKey('x-init', $firstAttributes);
        self::assertStringContainsString('Date.now()', $firstAttributes['x-init']);

        $page->activeTab = ProjectSourceKey::Modrinth->value;
        self::assertFalse($page->shouldShowOperationStatusForTest());
    }

    public function test_expired_scan_completion_is_not_rendered(): void
    {
        $page = new TestableModManagerPage();
        $page->activeTab = 'installed';
        $scan = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Datapack,
            now: new \DateTimeImmutable('-2 minutes'),
        );
        $page->applyInstalledOperationForTest($scan);
        $completed = $scan->completed([], new \DateTimeImmutable('-30 seconds'));
        $page->applyInstalledOperationForTest($completed);
        $page->rememberScanCompletionForTest($completed);

        self::assertFalse($page->shouldShowOperationStatusForTest());
        self::assertSame([], $page->operationStatusAttributesForTest());
    }

    public function test_bulk_update_status_remains_rendered(): void
    {
        $page = new TestableModManagerPage();

        $page->installedOperation = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_BULK_UPDATE,
            42,
            ProjectType::Datapack,
        )->toCachePayload();
        self::assertTrue($page->shouldShowOperationStatusForTest());
    }

    public function test_finished_bulk_update_status_is_not_left_visible(): void
    {
        $page = new TestableModManagerPage();
        $page->installedOperation = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_BULK_UPDATE,
            42,
            ProjectType::Datapack,
        )->failed('failed')->toCachePayload();

        self::assertFalse($page->shouldShowOperationStatusForTest());
    }

    public function test_missing_shared_terminal_operation_refreshes_the_durable_scan_badge(): void
    {
        $page = new TestableModManagerPage();
        $page->returnNullInstalledOperationForTest = true;
        $page->pollInstalledOperations = true;

        $page->pollInstalledOperation();

        self::assertSame(1, $page->installedScanRefreshesForTest);
        self::assertSame(4, $page->installedFilesCount);
        self::assertTrue($page->installedScanDataReady);
        self::assertFalse($page->pollInstalledOperations);
    }

    public function test_malformed_external_dates_do_not_break_table_state_formatting(): void
    {
        $page = new TestableModManagerPage();

        self::assertSame('', $page->formatExternalProjectDateForTest('not-a-date'));
        self::assertSame('', $page->formatExternalProjectDateForTest(''));
        self::assertNotSame('', $page->formatExternalProjectDateForTest('2026-08-01T00:00:00Z'));
    }

    public function test_installed_search_and_description_helpers_are_unicode_safe(): void
    {
        $page = new TestableModManagerPage();
        $description = str_repeat('あ', 61);
        $truncated = $page->truncateProjectDescriptionForTest($description);

        self::assertSame(function_exists('mb_strtolower') ? 'éclair' : 'Éclair', $page->lowercaseInstalledSearchValueForTest('ÉCLAIR'));
        self::assertStringEndsWith('...', $truncated);
        self::assertSame(1, preg_match('//u', $truncated));
    }

    public function test_mutating_operation_reads_current_authoritative_metadata(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $service = Mockery::mock(InstalledProjectService::class);
        $server = new Server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $document = InstalledMetadataDocument::fromArray(['schema_version' => 2, 'installed_mods' => [[
            'project_id' => 'project',
            'project_slug' => 'project',
            'project_title' => 'Project',
            'version_id' => 'old',
            'version_number' => '1.0.0',
            'filename' => 'old.jar',
            'installed_at' => '2026-08-01T00:00:00Z',
            'source' => ProjectSourceKey::GitHubReleases->value,
        ]]]);
        self::assertNotNull($document);
        $service->shouldReceive('getInstalledMetadataReadResult')
            ->once()
            ->with($server, $files, ProjectType::Plugin)
            ->andReturn(new InstalledMetadataReadResult($document, InstalledMetadataReadStatus::Current));
        $container->instance(InstalledProjectService::class, $service);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        try {
            $installed = (new TestableModManagerPage())->currentInstalledModForOperationForTest(
                $server,
                $files,
                ProjectType::Plugin,
                'project',
                ProjectSourceKey::GitHubReleases,
            );

            self::assertSame('old.jar', $installed['filename']);
        } finally {
            Facade::clearResolvedInstance(InstalledProjectService::class);
            Container::setInstance($previousContainer);
            Facade::setFacadeApplication($previousFacadeApplication);
        }
    }

    public function test_mutating_operation_accepts_missing_metadata_as_an_empty_install(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $service = Mockery::mock(InstalledProjectService::class);
        $server = new Server();
        $files = Mockery::mock(DaemonFileRepository::class);
        $service->shouldReceive('getInstalledMetadataReadResult')
            ->once()
            ->with($server, $files, ProjectType::Plugin)
            ->andReturn(new InstalledMetadataReadResult(
                InstalledMetadataDocument::empty(),
                InstalledMetadataReadStatus::Missing,
            ));
        $container->instance(InstalledProjectService::class, $service);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        try {
            self::assertNull((new TestableModManagerPage())->currentInstalledModForOperationForTest(
                $server,
                $files,
                ProjectType::Plugin,
                'project',
                ProjectSourceKey::GitHubReleases,
            ));
        } finally {
            Facade::clearResolvedInstance(InstalledProjectService::class);
            Container::setInstance($previousContainer);
            Facade::setFacadeApplication($previousFacadeApplication);
        }
    }

    public function test_catalog_tabs_exclude_sources_without_search_capability(): void
    {
        $page = $this->pageWithSources([
            $this->source(ProjectSourceKey::Modrinth, supportsSearch: true),
            $this->source(ProjectSourceKey::GitHubReleases, supportsSearch: false),
        ]);

        self::assertSame([ProjectSourceKey::Modrinth->value], $page->catalogSourceKeysForTest());
    }

    public function test_multi_source_catalog_defaults_to_the_first_visible_source(): void
    {
        $page = $this->pageWithSources([
            $this->source(ProjectSourceKey::CurseForge, supportsSearch: true),
            $this->source(ProjectSourceKey::Modrinth, supportsSearch: true),
        ]);

        self::assertSame(ProjectSourceKey::CurseForge->value, $page->getDefaultActiveTab());
    }

    public function test_catalog_source_is_canonicalized_for_url_and_installed_has_no_source(): void
    {
        $previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('translator', new Translator(new ArrayLoader(), 'en'));
        Container::setInstance($container);

        try {
            $page = $this->pageWithSources([
                $this->source(ProjectSourceKey::CurseForge, supportsSearch: true),
                $this->source(ProjectSourceKey::Modrinth, supportsSearch: true),
            ]);

            self::assertSame(ProjectSourceKey::CurseForge->value, $page->catalogTabForSourceTest(ProjectSourceKey::CurseForge->value));
            self::assertSame(ProjectSourceKey::CurseForge->value, $page->sourceForTabTest(ProjectSourceKey::CurseForge->value));
            self::assertNull($page->catalogTabForSourceTest('missing-source'));
            self::assertNull($page->catalogTabForSourceTest('installed'));
            self::assertNull($page->sourceForTabTest('installed'));
            self::assertSame(ProjectSourceKey::Modrinth->value, $page->syncSourceFromActiveTabForTest(ProjectSourceKey::Modrinth->value));
            self::assertNull($page->syncSourceFromActiveTabForTest('installed'));

            $singleSourcePage = $this->pageWithSources([
                $this->source(ProjectSourceKey::Modrinth, supportsSearch: true),
            ]);
            self::assertSame('all', $singleSourcePage->catalogTabForSourceTest(ProjectSourceKey::Modrinth->value));
            self::assertSame(ProjectSourceKey::Modrinth->value, $singleSourcePage->sourceForTabTest('all'));
        } finally {
            Container::setInstance($previousContainer);
        }
    }

    public function test_out_of_range_table_pages_are_clamped_to_the_last_real_page(): void
    {
        $page = new TestableModManagerPage();

        self::assertSame(71, $page->clampTablePageForTest(72, 1416));
        self::assertSame(71, $page->clampTablePageForTest(71, 1416));
        self::assertSame(1, $page->clampTablePageForTest(4, 0));
    }

    public function test_navigation_sort_uses_a_distinct_setting_for_each_project_type(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $config = new LaravelConfigRepository([
            'pelican-minecraft-modrinth' => [
                'navigation_sort' => [
                    'mod' => 10,
                    'plugin' => 20,
                    'datapack' => 30,
                ],
            ],
        ]);
        $container->instance('config', $config);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        try {
            self::assertSame(10, TestableModManagerPage::navigationSortForTest(ProjectType::Mod));
            self::assertSame(20, TestableModManagerPage::navigationSortForTest(ProjectType::Plugin));
            self::assertSame(30, TestableModManagerPage::navigationSortForTest(ProjectType::Datapack));
        } finally {
            Container::setInstance($previousContainer);
            Facade::setFacadeApplication($previousFacadeApplication);
        }
    }

    public function test_terminal_dispatch_failure_is_marked_handled_without_polling_again(): void
    {
        $page = new TestableModManagerPage();
        $page->pollInstalledOperations = true;
        $failed = InstalledOperationState::queued(
            InstalledOperationManager::OPERATION_SCAN,
            42,
            ProjectType::Datapack,
        )->failed('dispatch_failed');

        $page->markOperationHandledForTest($failed);

        self::assertSame($failed->operation.':'.$failed->finishedAt, $page->handledInstalledOperation);
        self::assertFalse($page->pollInstalledOperations);
        self::assertFalse($page->shouldPollForTest($failed));
    }

    public function test_enrichment_poll_skips_render_when_the_visible_payload_is_unchanged(): void
    {
        $page = new TestableModManagerPage();
        $page->activeTab = 'installed';
        $page->pollEnrichment = true;
        $page->installedEnrichmentSignature = 'same-payload';
        $page->peekedEnrichmentSignatureForTest = 'same-payload';

        $page->pollEnrichment();

        self::assertSame(1, $page->pollEnrichmentSkipRenderCallsForTest);
        self::assertSame(0, $page->flushCachedTableRecordsCallsForTest);
        self::assertTrue($page->pollEnrichment);
        self::assertSame('same-payload', $page->installedEnrichmentSignature);
    }

    public function test_enrichment_poll_renders_when_a_background_fill_lands(): void
    {
        $page = new TestableModManagerPage();
        $page->activeTab = 'installed';
        $page->pollEnrichment = true;
        $page->installedEnrichmentSignature = 'placeholders';
        $page->peekedEnrichmentSignatureForTest = 'filled-icons';

        $page->pollEnrichment();

        self::assertSame(0, $page->pollEnrichmentSkipRenderCallsForTest);
        self::assertSame(1, $page->flushCachedTableRecordsCallsForTest);
        self::assertSame('filled-icons', $page->installedEnrichmentSignature);
    }

    public function test_enrichment_poll_on_a_catalog_tab_skips_render_when_the_payload_is_unchanged(): void
    {
        $page = new TestableModManagerPage();
        $page->activeTab = ProjectSourceKey::Modrinth->value;
        $page->pollEnrichment = true;
        $page->installedEnrichmentSignature = 'same-payload';
        $page->peekedEnrichmentSignatureForTest = 'same-payload';

        $page->pollEnrichment();

        self::assertSame(1, $page->pollEnrichmentSkipRenderCallsForTest);
        self::assertSame(0, $page->flushCachedTableRecordsCallsForTest);
        self::assertTrue($page->pollEnrichment);
        self::assertSame('same-payload', $page->installedEnrichmentSignature);
    }

    public function test_enrichment_poll_on_a_catalog_tab_reloads_pending_version_badges(): void
    {
        $page = new TestableModManagerPage();
        $page->activeTab = ProjectSourceKey::Modrinth->value;
        $page->pollEnrichment = true;
        $page->installedEnrichmentSignature = 'stale';
        $page->peekedEnrichmentSignatureForTest = 'filled';

        $page->pollEnrichment();

        self::assertTrue($page->pollEnrichment);
        self::assertSame('filled', $page->installedEnrichmentSignature);
        self::assertSame(0, $page->pollEnrichmentSkipRenderCallsForTest);
        self::assertSame(1, $page->flushCachedTableRecordsCallsForTest);
    }

    public function test_enrichment_poll_on_a_catalog_tab_is_idle_when_nothing_is_pending(): void
    {
        $page = new TestableModManagerPage();
        $page->activeTab = ProjectSourceKey::Modrinth->value;
        $page->pollEnrichment = false;
        $page->installedEnrichmentSignature = 'stale';

        $page->pollEnrichment();

        self::assertFalse($page->pollEnrichment);
        self::assertNull($page->installedEnrichmentSignature);
        self::assertSame(0, $page->flushCachedTableRecordsCallsForTest);
    }

    public function test_catalog_warm_skips_the_active_source_landing_page(): void
    {
        $page = $this->pageWithSources([
            $this->source(ProjectSourceKey::CurseForge, true),
            $this->source(ProjectSourceKey::Modrinth, true),
            $this->source(ProjectSourceKey::Hangar, true),
        ]);
        $page->activeTab = ProjectSourceKey::CurseForge->value;

        self::assertSame([
            ['sourceKey' => 'modrinth', 'page' => 1],
            ['sourceKey' => 'hangar', 'page' => 1],
            ['sourceKey' => 'curseforge', 'page' => 2],
        ], $page->catalogPagesToWarmForTest());
        self::assertSame([
            'queued' => [
                ['sourceKey' => 'modrinth', 'page' => 1],
                ['sourceKey' => 'curseforge', 'page' => 2],
            ],
            'immediate' => [
                ['sourceKey' => 'hangar', 'page' => 1],
            ],
        ], $page->catalogWarmPlanForTest());
    }

    public function test_catalog_warm_skips_unconfigured_sources(): void
    {
        $page = $this->pageWithSources([
            $this->source(ProjectSourceKey::CurseForge, true, true),
            $this->source(ProjectSourceKey::Modrinth, true, false),
        ]);
        $page->activeTab = ProjectSourceKey::CurseForge->value;

        self::assertSame([
            ['sourceKey' => 'curseforge', 'page' => 2],
        ], $page->catalogPagesToWarmForTest());
    }

    public function test_catalog_warm_prefers_the_next_page_then_the_previous_page(): void
    {
        $page = $this->pageWithSources([
            $this->source(ProjectSourceKey::CurseForge, true),
            $this->source(ProjectSourceKey::Modrinth, true),
        ]);
        $page->activeTab = ProjectSourceKey::Modrinth->value;
        $page->tablePageForTest = 250;

        self::assertSame([
            ['sourceKey' => 'curseforge', 'page' => 1],
            ['sourceKey' => 'modrinth', 'page' => 251],
            ['sourceKey' => 'modrinth', 'page' => 249],
        ], $page->catalogPagesToWarmForTest());
        self::assertSame([
            ['sourceKey' => 'modrinth', 'page' => 251],
            ['sourceKey' => 'modrinth', 'page' => 249],
        ], $page->catalogPagesToWarmForTest(false));
    }

    public function test_catalog_warm_keeps_modrinth_pages_beyond_the_curseforge_index_cap(): void
    {
        $page = $this->pageWithSources([
            $this->source(ProjectSourceKey::Modrinth, true),
        ]);
        $page->activeTab = ProjectSourceKey::Modrinth->value;
        $page->tablePageForTest = 501;

        self::assertSame([
            ['sourceKey' => 'modrinth', 'page' => 502],
            ['sourceKey' => 'modrinth', 'page' => 500],
        ], $page->catalogPagesToWarmForTest());
    }

    public function test_catalog_warm_does_not_queue_curseforge_pages_past_the_api_cap(): void
    {
        $page = $this->pageWithSources([
            $this->source(ProjectSourceKey::CurseForge, true),
        ]);
        $page->activeTab = ProjectSourceKey::CurseForge->value;
        $page->tablePageForTest = 500;

        self::assertSame([
            ['sourceKey' => 'curseforge', 'page' => 499],
        ], $page->catalogPagesToWarmForTest());
    }

    public function test_catalog_warm_skips_adjacent_pages_when_searching(): void
    {
        $page = $this->pageWithSources([
            $this->source(ProjectSourceKey::CurseForge, true),
            $this->source(ProjectSourceKey::Modrinth, true),
        ]);
        $page->activeTab = ProjectSourceKey::CurseForge->value;
        $page->tablePageForTest = 3;
        $page->tableSearchForTest = 'jei';

        self::assertSame([
            ['sourceKey' => 'modrinth', 'page' => 1],
        ], $page->catalogPagesToWarmForTest());
        self::assertSame([], $page->catalogPagesToWarmForTest(false));
    }

    public function test_profiler_is_not_published_for_enrichment_polls(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $request = Request::create('/livewire/update', 'POST', [
            'components' => [[
                'calls' => [['method' => 'pollEnrichment', 'params' => []]],
            ]],
        ]);
        $container = new Container();
        $container->instance('request', $request);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        try {
            $page = new TestableModManagerPage();
            self::assertFalse($page->shouldPublishPerformanceProfilerForTest());

            $listRequest = Request::create('/livewire/update', 'POST', [
                'components' => [[
                    'calls' => [['method' => 'loadTable', 'params' => []]],
                ]],
            ]);
            $container->instance('request', $listRequest);
            self::assertTrue($page->shouldPublishPerformanceProfilerForTest());
        } finally {
            Container::setInstance($previousContainer);
            Facade::setFacadeApplication($previousFacade);
        }
    }

    public function test_catalog_records_peek_latest_versions_instead_of_blocking(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Server/Pages/ModManagerPage.php');

        self::assertStringContainsString('$this->peekVisibleLatestVersions($hits, $server, $type);', $source);
        self::assertStringNotContainsString('function warmVisibleLatestVersions', $source);
    }

    public function test_curseforge_datapack_external_links_use_the_data_packs_namespace(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Server/Pages/ModManagerPage.php');

        self::assertStringContainsString("ProjectType::Datapack->value => 'data-packs'", $source);
        self::assertStringNotContainsString("ProjectType::Datapack->value => 'texture-packs'", $source);
    }

    /** @param array<int, ProjectSourceInterface> $sources */
    private function pageWithSources(array $sources): TestableModManagerPage
    {
        return new TestableModManagerPage($sources);
    }

    private function source(ProjectSourceKey $key, bool $supportsSearch, bool $configured = true): ProjectSourceInterface
    {
        $source = Mockery::mock(ProjectSourceInterface::class);
        $source->shouldReceive('getKey')->andReturn($key);
        $source->shouldReceive('getLabel')->andReturn($key->value);
        $source->shouldReceive('supportsSearch')->andReturn($supportsSearch);
        $source->shouldReceive('isConfigured')->andReturn($configured);

        return $source;
    }
}
