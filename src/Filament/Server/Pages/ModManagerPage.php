<?php

namespace Kazaminosuke\ModManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Admin\Resources\Plugins\PluginResource;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ProjectOperation;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Facades\ModManager;
use Kazaminosuke\ModManager\Filament\Actions\CatalogRowAction;
use Kazaminosuke\ModManager\Jobs\WarmCatalogSearch;
use Kazaminosuke\ModManager\ModManagerPlugin;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectMutationService;
use Kazaminosuke\ModManager\Services\ResourcePackService;
use Kazaminosuke\ModManager\Services\VersionLookupCoordinator;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Support\CacheVersion;
use Kazaminosuke\ModManager\Support\CatalogCompatibilityOverride;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use Kazaminosuke\ModManager\Support\InstalledMetadataIndex;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use Kazaminosuke\ModManager\Support\InstalledScanResult;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\ProjectIconUrl;
use Kazaminosuke\ModManager\Support\ProjectOperationAuthorizer;
use Kazaminosuke\ModManager\Support\ProjectPrimaryFile;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\RequestPerformanceProfiler;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithoutUrlPagination;
use Throwable;

class ModManagerPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HasTabs {
        HasTabs::updatedActiveTab as protected baseUpdatedActiveTab;
    }
    use InteractsWithTable {
        InteractsWithTable::applyTableColumnManager as protected baseApplyTableColumnManager;
        InteractsWithTable::bootedInteractsWithTable as protected baseBootedInteractsWithTable;
        InteractsWithTable::loadTable as protected baseLoadTable;
        InteractsWithTable::resetTableColumnManager as protected baseResetTableColumnManager;
        InteractsWithTable::updatedTableFilters as protected baseUpdatedTableFilters;
        InteractsWithTable::updatedTableSearch as protected baseUpdatedTableSearch;
    }
    use WithoutUrlPagination;

    /** Keep every catalog source and the table paginator on the same page size. */
    private const TABLE_PAGE_SIZE = 20;

    /** Bound direct URL input before it can become an upstream API offset. */
    private const MAX_CATALOG_PAGE = 10_000;

    /** CurseForge rejects index + page size values above 10,000. */
    private const MAX_CURSEFORGE_CATALOG_PAGE = 500;

    /** The catalog table has no Filament query-string identifier. */
    private const TABLE_PAGINATOR_NAME = 'page';

    /** A success outcome remains visible long enough to be read, but never persists. */
    private const INSTALLED_SCAN_COMPLETION_VISIBLE_SECONDS = 5;

    /** @var array<int, array{source: string, project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>|null */
    protected ?array $installedModsMetadata = null;

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $installedModsIndex = null;

    /**
     * Current-page row index for project icons, rebuilt once per table render.
     *
     * @var array<string, int>|null
     */
    protected ?array $projectIconRowIndexMap = null;

    /** @var array<string, array<int, mixed>> Cache for version data by "source:project_id" */
    protected array $versionsCache = [];

    /** @var array<string, array<string, mixed>|null> Latest compatible version by "source:project_id" */
    protected array $latestVersionsCache = [];

    /**
     * Keys ("source:project_id") whose latest-version lookup was a cold
     * cache miss on the non-blocking peek path - a background
     * revalidation was queued rather than fetched inline. Set only by
     * peekVisibleLatestVersions() for both Installed and catalog rows.
     *
     * @var array<string, true>
     */
    protected array $pendingLatestVersionKeys = [];

    /** @var array<int, ProjectSourceInterface>|null */
    protected ?array $availableSources = null;

    /** @var array<int, ProjectSourceInterface>|null */
    protected ?array $catalogSources = null;

    protected ?ProjectSourceInterface $currentSource = null;

    protected ?string $currentSourceTab = null;

    /** @var array<string, string>|null */
    protected ?array $catalogCategoryOptions = null;

    protected ?string $catalogCategoryOptionsKey = null;

    /** @var array<string, bool> */
    protected array $projectOperationPermissionMemo = [];

    /** @var array<string, Carbon|null> */
    protected array $externalProjectDateMemo = [];

    /** @var array<string> */
    protected array $unknownFiles = [];

    /**
     * The catalog sort is deliberately separate from Filament's table filters:
     * it changes result ordering but never narrows the result set.
     */
    public string $catalogSort = 'downloads';

    /** Catalog source only; Installed intentionally clears this query value. */
    #[Url(as: 'source', history: true, keep: false)]
    public ?string $source = null;

    /** Catalog page; page 1 is intentionally omitted from the URL. */
    #[Url(as: 'page', history: true, keep: false, except: 1)]
    public int $catalogPage = 1;

    /** Catalog-only compatibility overrides; null means use auto-detection. */
    #[Url(as: 'mc', history: true, keep: false)]
    public ?string $minecraftVersionOverride = null;

    #[Url(as: 'loader', history: true, keep: false)]
    public ?string $loaderOverride = null;

    protected bool $syncingCatalogUrl = false;

    /** Keep the default catalog source omitted while restoring a URL pop. */
    protected bool $preservingDefaultCatalogSource = false;

    /** Null until a successful installed-scan cache provides the Wings file count. */
    public ?int $installedFilesCount = null;

    /** @var array<string, mixed>|null Browser-safe status payload for the active background operation. */
    public ?array $installedOperation = null;

    /** Prevent a completed operation from refreshing the deferred table more than once. */
    public ?string $handledInstalledOperation = null;

    /**
     * The scan operation observed while this component was on the Installed
     * tab. Its queued-at timestamp is immutable across the scan lifecycle,
     * so it identifies one scan without exposing daemon details.
     */
    public ?string $observedInstalledScan = null;

    /**
     * @var array<string, mixed>|null A short-lived successful scan outcome
     *                                for the Installed tab only. It is component-local so an old
     *                                completion message never returns after a browser reload.
     */
    public ?array $installedScanCompletion = null;

    /** Avoid repeating the operator-facing queue configuration warning in one component session. */
    public bool $operationQueueWarningShown = false;

    /** Enable polling only while an Installed operation needs observation. */
    public bool $pollInstalledOperations = false;

    /**
     * Enable a coarser, independent poll only while the Installed tab has
     * rows still waiting on a background enrichment fetch (icon/downloads/
     * date_modified or the latest-version/update badge). Deliberately kept
     * separate from pollInstalledOperations, which tracks scan/bulk-update
     * job state rather than passive cache-fill progress.
     */
    public bool $pollEnrichment = false;

    /**
     * Hash of the visible enrichment payload. Compared on pollEnrichment()
     * so an unchanged pending fill does not remorph the table every five
     * seconds. Shared by Installed and Catalog.
     */
    #[Locked]
    public ?string $installedEnrichmentSignature = null;

    /**
     * Compact catalog-row identities used by pollEnrichment() to peek
     * latest-version fills without repeating search().
     *
     * @var array<int, array{project_id: string, source: string}>
     */
    #[Locked]
    public array $catalogEnrichmentPeekKeys = [];

    /** Whether a still-valid scan result already exists, independent of background-operation state. */
    public bool $installedScanDataReady = false;

    /** Per-request timing state used only by temporary initial-load diagnostics. */
    protected bool $modManagerTimingEnabled = false;

    protected float $modManagerTimingStartedAt = 0.0;

    protected string $modManagerTimingRequestId = '';

    protected int $modManagerTimingVersionLookups = 0;

    protected int $modManagerTimingVersionLookupDurationMs = 0;

    /** Dispatch the initial catalog warm only after Filament has initialized the table. */
    protected bool $catalogWarmPending = false;

    /**
     * Display-only component state. Keeping this in the Livewire snapshot
     * avoids another server.properties read for every poll request; a page
     * reload still resolves the current value from Wings.
     */
    #[Locked]
    public ?string $datapackWorldName = null;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'mod-manager';

    public static function getNavigationSort(): ?int
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return static::navigationSortFor(static::detectProjectType($server) ?? ProjectType::Mod, $server);
    }

    /**
     * The sidebar sorts by this value, and ShiftCoreNavigationRows (wired
     * through ModManagerPlugin::register() -> tenantMiddleware()) pushes any
     * core page sharing the number down first, so the value configured in
     * the admin tab lands on its literal row: 10 renders as the tenth entry
     * from the top, 11 as the eleventh. The middleware only claims rows for
     * pages whose canAccess() passes, so a hidden manager page never
     * displaces a core row.
     */
    protected static function navigationSortFor(ProjectType $type, Server $server): int
    {
        return app(ServerModManagerSettings::class)->navigationSort($server, $type);
    }

    /** @return array<int, ProjectType> */
    protected static function enabledProjectTypesForAccess(): array
    {
        return [ProjectType::Mod, ProjectType::Plugin];
    }

    protected static function detectProjectType(Server $server): ?ProjectType
    {
        return ProjectType::fromServer($server);
    }

    /**
     * Stage 8: an egg profile the auto-detection cascade recognizes as
     * plausibly Minecraft-related, but can't place automatically (a
     * modpack egg, or one this plugin has simply never seen before), still
     * makes this page accessible - content() then renders the manual-setup
     * notice/form instead of the normal catalog, rather than hiding the
     * page entirely the way an egg with nothing at all to do with
     * Minecraft correctly still does.
     *
     * MinecraftDatapackPage overrides needsManualEggSetup() back to always
     * false: the manual-setup prompt only ever appears once, on this page,
     * not duplicated on the datapack page too - see that class.
     */
    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $settings = app(ServerModManagerSettings::class);

        $hasEnabledPageType = false;
        foreach (static::enabledProjectTypesForAccess() as $enabledType) {
            if ($settings->isTypeEnabled($server, $enabledType)) {
                $hasEnabledPageType = true;

                break;
            }
        }

        if (!$hasEnabledPageType) {
            return false;
        }

        $type = static::detectProjectType($server);

        return parent::canAccess()
            && ($type === null || $settings->isTypeEnabled($server, $type))
            && ($type !== null || static::needsManualEggSetup($server));
    }

    protected static function needsManualEggSetup(Server $server): bool
    {
        if (!(bool) config('pelican-mod-manager.egg_autodetect_enabled', true)) {
            return false;
        }

        return EggProfileResolver::resolve($server)->needsManualSetup();
    }

    public static function getNavigationLabel(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = static::detectProjectType($server);

        return $type?->getLabel() ?? 'Managed';
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function boot(): void
    {
        $this->modManagerTimingEnabled = (bool) config('pelican-mod-manager.debug_timing', false);

        if (!$this->modManagerTimingEnabled) {
            return;
        }

        $this->modManagerTimingStartedAt = microtime(true);
        $this->modManagerTimingRequestId = bin2hex(random_bytes(6));
        $this->modManagerTimingVersionLookups = 0;
        $this->modManagerTimingVersionLookupDurationMs = 0;

        request()->attributes->set('mmr_timing_request_id', $this->modManagerTimingRequestId);
        request()->attributes->set('mmr_timing_started_at', $this->modManagerTimingStartedAt);
        RequestPerformanceProfiler::start($this->modManagerTimingRequestId);
    }

    public function rendering($view): void
    {
        RequestPerformanceProfiler::markRenderStart();
    }

    public function rendered($view, $html): void
    {
        RequestPerformanceProfiler::markRenderEnd();
    }

    public function dehydrate(): void
    {
        if (!$this->isModManagerTimingEnabled()) {
            return;
        }

        $filterState = $this->tableFilters ?? [];
        RequestPerformanceProfiler::mergeContext([
            'source' => $this->activeTab,
            'page' => (int) $this->getTablePage(),
            'search' => (string) $this->getTableSearch(),
            'filter_category' => $filterState['catalog_category']['value'] ?? null,
            'filter_environment' => $filterState['catalog_environment']['value'] ?? null,
            'sort' => $this->catalogSort,
            'table_loaded' => (bool) $this->isTableLoaded,
            'php_ms' => $this->getModManagerTimingElapsedMs(),
            'version_lookup_count' => $this->modManagerTimingVersionLookups,
            'version_lookup_ms' => $this->modManagerTimingVersionLookupDurationMs,
        ]);

        Log::info('Mod manager timing', [
            'stage' => 'total_component_request',
            'request_id' => $this->modManagerTimingRequestId,
            'duration_ms' => $this->getModManagerTimingElapsedMs(),
            'request_path' => request()->path(),
            'table_loaded' => $this->isTableLoaded,
            'version_lookup_count' => $this->modManagerTimingVersionLookups,
            'version_lookup_duration_ms' => $this->modManagerTimingVersionLookupDurationMs,
        ]);

        if ($this->shouldPublishPerformanceProfiler()) {
            $this->dispatch(RequestPerformanceProfiler::EVENT, snapshot: RequestPerformanceProfiler::snapshot());
        }
    }

    /**
     * @return array<int, string>
     */
    protected function currentLivewireCallMethods(): array
    {
        $components = request()->input('components');
        if (!is_array($components) || $components === []) {
            return [];
        }

        $calls = $components[0]['calls'] ?? [];
        if (!is_array($calls)) {
            return [];
        }

        $methods = [];
        foreach ($calls as $call) {
            if (is_array($call) && is_string($call['method'] ?? null) && $call['method'] !== '') {
                $methods[] = $call['method'];
            }
        }

        return $methods;
    }

    protected function shouldPublishPerformanceProfiler(): bool
    {
        $methods = $this->currentLivewireCallMethods();
        if ($methods === []) {
            return true;
        }

        foreach ($methods as $method) {
            if (!in_array($method, ['pollInstalledOperation', 'pollEnrichment'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function getModManagerTimingElapsedMs(?float $timestamp = null): int
    {
        if (!$this->isModManagerTimingEnabled()) {
            return 0;
        }

        return (int) round((($timestamp ?? microtime(true)) - $this->modManagerTimingStartedAt) * 1000);
    }

    protected function isModManagerTimingEnabled(): bool
    {
        return $this->modManagerTimingEnabled;
    }

    public function mount(): void
    {
        $this->catalogSort = $this->normalizeCatalogSort(
            session()->get($this->getCatalogSortSessionKey(), 'downloads'),
        );

        $this->refreshInstalledScanDataReady();
        // HasTabs resolves and then caches getTabs() while choosing the default
        // tab. Read the persisted scan result first, otherwise that first
        // cached definition permanently misses the Installed count badge for
        // the whole component request (including after a browser reload).
        $this->loadDefaultActiveTab();
        $this->restoreCatalogStateFromUrl();
        $this->rejectUnauthorizedInstalledTab();
        $this->normalizeCatalogCompatibilityOverrides();
        $this->configureCatalogCompatibilityOverride();
        if ($this->activeTab === 'installed') {
            $this->dispatchInstalledScanIfMissing();
        }
        $this->catalogPage = $this->normalizeCatalogPage($this->catalogPage, $this->source);
        $this->paginators[self::TABLE_PAGINATOR_NAME] = $this->catalogPage;
        $this->refreshInstalledOperationState();

        $this->catalogWarmPending = true;
    }

    public function hydrate(): void
    {
        $this->normalizeCatalogCompatibilityOverrides();
        $this->configureCatalogCompatibilityOverride();
    }

    /**
     * InteractsWithTable initializes its typed $table property in this hook,
     * which runs after the page's mount() method. Defer the initial warm until
     * that initialization has completed; subsequent Livewire updates already
     * hydrate the table before their updated* hooks run.
     */
    public function bootedInteractsWithTable(): void
    {
        $this->baseBootedInteractsWithTable();

        if (!$this->catalogWarmPending) {
            return;
        }

        $this->catalogWarmPending = false;
        $this->dispatchCatalogWarm();
    }

    /**
     * Warm likely-next catalog pages in the background. Hangar's
     * /projects call is ~1s, so its jobs are dispatched first instead of
     * waiting behind Modrinth on a single queue worker. Other sources stay
     * queued so this request never blocks on their APIs. The active
     * source's current page is fetched by records()/loadTable itself.
     */
    protected function dispatchCatalogWarm(bool $includeOtherSources = true): void
    {
        if (!(bool) config('pelican-mod-manager.warm_catalog_enabled', true)) {
            return;
        }

        // A sync/null queue driver would run this inline, during mount(),
        // defeating the entire point (and potentially blocking this
        // request on a throttled or slow upstream call).
        if (!app(InstalledOperationManager::class)->supportsAsyncDispatch()) {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);

        if (!$type) {
            return;
        }

        $loader = $type->getLoaderSlug($server);
        $mcVersion = ModManager::getMinecraftVersion($server);

        if ((!$loader && $type !== ProjectType::ResourcePack) || !$mcVersion) {
            return;
        }

        $loader ??= $type->value;

        foreach ($this->catalogPagesToWarm($includeOtherSources) as $page) {
            $source = $this->catalogSourceByKey($page['sourceKey']);
            if ($source !== null && $source->hasFreshCachedSearch(
                $server,
                $type,
                $page['page'],
                null,
                ['sort' => $this->catalogSort],
            )) {
                continue;
            }

            WarmCatalogSearch::dispatch(
                $server->id,
                $page['sourceKey'],
                $type->value,
                $page['page'],
                $loader,
                $mcVersion,
                $this->catalogSort,
                $this->hasCatalogCompatibilityOverride(),
            );
        }
    }

    /**
     * Pages the current visit should warm in the background. The active
     * source's current page is omitted: records() (or the deferred
     * loadTable that follows a cold miss) already performs that fetch.
     *
     * Adjacent pages (p+1, then p-1) are queued only for the default
     * unfiltered catalog. Search/filter combinations use different cache
     * keys than WarmCatalogSearch, so prefetching them would spend the
     * throttle on unused entries.
     *
     * @return array<int, array{sourceKey: string, page: int}>
     */
    protected function catalogPagesToWarm(bool $includeOtherSources = true): array
    {
        $activeSourceKey = $this->getCurrentSource()?->getKey()->value;
        $otherSourcePages = [];
        $activeSourcePages = [];

        foreach ($this->getCatalogSources() as $source) {
            if (!$source->isConfigured() || !$source->supportsSearch()) {
                continue;
            }

            $sourceKey = $source->getKey()->value;

            if ($sourceKey === $activeSourceKey) {
                if ($this->shouldWarmAdjacentCatalogPages()) {
                    $activeSourcePages = $this->adjacentCatalogPagesToWarm(
                        $sourceKey,
                        $this->currentCatalogPageForWarm(),
                    );
                }

                continue;
            }

            if ($includeOtherSources) {
                $otherSourcePages[] = ['sourceKey' => $sourceKey, 'page' => 1];
            }
        }

        $pages = [...$otherSourcePages, ...$activeSourcePages];

        // Hangar search is the slowest catalog request. Preserve its former
        // dispatch priority without exposing a misleading immediate/queued
        // split: every target below is still the same queued job.
        return [
            ...array_values(array_filter(
                $pages,
                static fn (array $page): bool => $page['sourceKey'] === ProjectSourceKey::Hangar->value,
            )),
            ...array_values(array_filter(
                $pages,
                static fn (array $page): bool => $page['sourceKey'] !== ProjectSourceKey::Hangar->value,
            )),
        ];
    }

    protected function shouldWarmAdjacentCatalogPages(): bool
    {
        if (trim((string) $this->getTableSearch()) !== '') {
            return false;
        }

        $filterState = $this->tableFilters ?? [];
        $category = $filterState['catalog_category']['value'] ?? null;
        $environment = $filterState['catalog_environment']['value'] ?? null;

        return ($category === null || $category === '')
            && ($environment === null || $environment === '');
    }

    protected function currentCatalogPageForWarm(): int
    {
        return $this->normalizeCatalogPage(
            $this->getTablePage(),
            $this->getCurrentSource()?->getKey()->value,
        );
    }

    /**
     * @return array<int, array{sourceKey: string, page: int}>
     */
    protected function adjacentCatalogPagesToWarm(string $sourceKey, int $currentPage): array
    {
        $currentPage = max(1, $currentPage);
        // CurseForge rejects index + pageSize above 10,000 (page 500 at
        // 20 hits). Do not apply that clamp to Modrinth: later pages stay
        // browsable and can still be warmed.
        $maxPage = $sourceKey === ProjectSourceKey::CurseForge->value ? 500 : null;
        $pages = [];
        $nextPage = $currentPage + 1;

        if ($maxPage === null || $nextPage <= $maxPage) {
            $pages[] = ['sourceKey' => $sourceKey, 'page' => $nextPage];
        }

        $previousPage = $currentPage - 1;

        if ($previousPage >= 1 && ($maxPage === null || $previousPage <= $maxPage)) {
            $pages[] = ['sourceKey' => $sourceKey, 'page' => $previousPage];
        }

        return $pages;
    }

    protected function catalogSourceByKey(string $sourceKey): ?ProjectSourceInterface
    {
        foreach ($this->getCatalogSources() as $source) {
            if ($source->getKey()->value === $sourceKey) {
                return $source;
            }
        }

        return null;
    }

    /**
     * A cheap cache-only check (no Wings API call, unlike the deferred
     * table's own scan) so the very first render already knows whether a
     * valid scan result exists - without this, installedScanDataReady stays
     * at its default false until the deferred table's records() closure
     * runs, so the status badge visibly flashes its "checking" state before
     * disappearing a moment later even when the data was ready all along.
     */
    protected function refreshInstalledScanDataReady(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);

        if (!$type) {
            return;
        }

        if ($type === ProjectType::ResourcePack) {
            $this->getInstalledModsMetadata();

            return;
        }

        $scanCacheKey = ModManager::getHashScanCacheKey($server, $type);
        $this->setInstalledScanResult(InstalledScanResult::fromCache(Cache::get($scanCacheKey)));
    }

    /**
     * Start the first Installed scan when the Installed tab is opened. Catalog
     * visitors should not incur a server-wide Wings scan merely to render
     * catalog rows; the tab-switch path remains the explicit lazy entry point.
     *
     * The durable scan cache is the normal ten-minute cooldown. On a cache
     * miss, InstalledOperationManager's per-server/type active state prevents
     * repeat dispatches during a running scan; ScanInstalledProjects is also a
     * unique queued job, which closes the simultaneous-page-load race before
     * it can result in duplicate Wings scans.
     */
    protected function dispatchInstalledScanIfMissing(): void
    {
        if ($this->installedScanDataReady || !$this->canScanInstalledProjects()) {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);

        if ($type === ProjectType::ResourcePack) {
            return;
        }

        if (!$type) {
            return;
        }

        $actorUserId = $this->actorUserIdForScan();

        if ($actorUserId === null) {
            return;
        }

        $dispatch = app(InstalledOperationManager::class)->dispatchScan(
            $server,
            $type,
            actorUserId: $actorUserId,
        );
        $state = $dispatch['state'];

        if ($state !== null) {
            $this->setInstalledOperationState($state);
        }

        if ($dispatch['reason'] === 'sync_queue') {
            $this->operationQueueWarningShown = true;
            $this->pollInstalledOperations = false;

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.operations.queue_required'))
                ->danger()
                ->send();
        }
    }

    /**
     * Keep the tab definition coherent with the durable scan cache. Filament's
     * HasTabs trait memoizes getTabs() in $cachedTabs, so merely changing the
     * public count property cannot update an already-rendered Installed badge.
     */
    protected function setInstalledScanResult(?InstalledScanResult $scanResult): void
    {
        $installedFilesCount = $scanResult?->diskFileCount;
        $changed = $this->installedFilesCount !== $installedFilesCount
            || $this->installedScanDataReady !== ($scanResult !== null);

        $this->installedScanDataReady = $scanResult !== null;
        $this->installedFilesCount = $installedFilesCount;

        if ($changed) {
            unset($this->cachedTabs);
        }
    }

    /** @return array<string, string> */
    protected function getCatalogSortOptions(): array
    {
        return [
            'downloads' => trans('pelican-mod-manager::strings.table.sort.downloads'),
            'updated' => trans('pelican-mod-manager::strings.table.sort.updated'),
            'popularity' => trans('pelican-mod-manager::strings.table.sort.popularity'),
        ];
    }

    protected function normalizeCatalogSort(mixed $sort): string
    {
        return is_string($sort) && array_key_exists($sort, $this->getCatalogSortOptions())
            ? $sort
            : 'downloads';
    }

    protected function getCatalogSortSessionKey(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return 'pelican-mod-manager.catalog-sort.'.$server->getKey();
    }

    public function updatedCatalogSort(mixed $sort): void
    {
        $this->isTableLoaded = false;
        $this->catalogSort = $this->normalizeCatalogSort($sort);
        session()->put($this->getCatalogSortSessionKey(), $this->catalogSort);

        $this->resetPage(self::TABLE_PAGINATOR_NAME);
        $this->resetTable();
        $this->dispatchCatalogWarm();
    }

    public function updatedActiveTab(?string $activeTab): void
    {
        if ($activeTab === 'installed' && !$this->canScanInstalledProjects()) {
            $this->activeTab = $this->getDefaultActiveTab();
            $activeTab = is_string($this->activeTab) ? $this->activeTab : null;
        }

        $catalogPageBeforeTabChange = $this->catalogPage;
        $preserveCatalogPage = $this->shouldPreserveCatalogPageOnTabChange($activeTab);

        // Scan progress and its brief success outcome belong exclusively to
        // the Installed tab. Do not let a tab switch make a catalog visitor
        // see an operation that finished while they were browsing sources.
        if ($activeTab !== 'installed') {
            $this->observedInstalledScan = null;
            $this->installedScanCompletion = null;
            $this->installedEnrichmentSignature = null;
        }

        $this->currentSource = null;
        $this->currentSourceTab = null;
        $this->catalogCategoryOptions = null;
        $this->catalogCategoryOptionsKey = null;
        $this->projectIconRowIndexMap = null;

        // A loaded table normally evaluates its records during this same
        // Livewire update. Reset it to Filament's deferred state first, so an
        // Installed-tab hydration runs in the follow-up loadTable request
        // while the tab itself has already switched and shows its spinner.
        $this->isTableLoaded = false;

        // HasTabs::updatedActiveTab() (aliased above) already resets the table's
        // page - each tab (source or "installed") paginates its own independent
        // result set, so a page number from the previous tab has no meaning here
        // (e.g. leaving Modrinth on page 909 and switching to a CurseForge tab
        // with far fewer results) - plus resets the column manager state. It was
        // being silently dropped by this method overriding it without calling it.
        $this->baseUpdatedActiveTab();
        $this->configureCatalogCompatibilityOverride();
        // A tab change starts a new result set. Let Livewire initialize page 1
        // from its default instead of carrying a literal `page=1` into the
        // URL, especially for Installed where page and source are not
        // meaningful query state.
        unset($this->paginators[self::TABLE_PAGINATOR_NAME]);
        $this->catalogPage = 1;
        $this->refreshInstalledScanDataReady();
        // A tab switch into Installed is the explicit lazy entry point for a
        // missing scan. Catalog visits do not dispatch server-wide work.
        if ($activeTab === 'installed') {
            $this->dispatchInstalledScanIfMissing();
        }
        $this->refreshInstalledOperationState();

        // Category IDs and the Modrinth-only environment filter are scoped to
        // a source tab, so discard them before Filament rebuilds the form.
        // Catalog sorting is an independent Livewire property and stays intact.
        $this->tableFilters = [];
        $this->resetTable();
        $this->queueHeaderScroll();
        $this->dispatchCatalogWarm();
        $this->syncSourceFromActiveTab($activeTab);
        // resetTable() above resets the paginator after the earlier lifecycle
        // hook, so clear it once more before Livewire serializes the URL.
        if ($preserveCatalogPage) {
            $this->paginators[self::TABLE_PAGINATOR_NAME] = $catalogPageBeforeTabChange;
            $this->catalogPage = $catalogPageBeforeTabChange;
        } else {
            unset($this->paginators[self::TABLE_PAGINATOR_NAME]);
            $this->catalogPage = 1;
        }
    }

    protected function shouldPreserveCatalogPageOnTabChange(?string $activeTab): bool
    {
        if ($activeTab === null || $activeTab === 'installed' || $this->catalogPage <= 1) {
            return false;
        }

        $tabSource = $this->sourceForTab($activeTab);

        return $this->source === $tabSource
            || ($this->source === null && $activeTab === $this->getDefaultActiveTab());
    }

    public function updatedSource(?string $source): void
    {
        if ($this->syncingCatalogUrl) {
            return;
        }

        $tab = $this->catalogTabForSource($source);
        $preserveDefaultCatalogSource = false;

        if ($tab === null) {
            $this->syncingCatalogUrl = true;
            $this->source = null;
            unset($this->paginators[self::TABLE_PAGINATOR_NAME]);
            $this->catalogPage = 1;
            $this->syncingCatalogUrl = false;
            $tab = $this->getDefaultActiveTab();
            $preserveDefaultCatalogSource = true;
        }

        if ($this->activeTab === $tab) {
            return;
        }

        $this->syncingCatalogUrl = true;
        $this->activeTab = $tab;
        $this->syncingCatalogUrl = false;

        $this->preservingDefaultCatalogSource = $preserveDefaultCatalogSource;

        try {
            $this->updatedActiveTab($tab);
        } finally {
            $this->preservingDefaultCatalogSource = false;
        }
    }

    public function updatedPaginators($page, $pageName): void
    {
        if ($pageName !== self::TABLE_PAGINATOR_NAME) {
            return;
        }

        $this->catalogPage = $this->normalizeCatalogPage($page, $this->source);
        $this->isTableLoaded = false;
        $this->queueHeaderScroll();
        $this->dispatchCatalogWarm(includeOtherSources: false);
    }

    public function updatedCatalogPage($page): void
    {
        $page = $this->normalizeCatalogPage($page, $this->source);
        $this->catalogPage = $page;
        $this->paginators[self::TABLE_PAGINATOR_NAME] = $page;
    }

    /**
     * Keep the paginator out of Livewire's built-in URL hook. Livewire's
     * PaginationUrl drops the `except` option while installing that hook, so
     * page 1 would remain in the URL. The public catalogPage property above
     * uses the normal Url attribute instead.
     *
     * @return array<string, never>
     */
    public function queryStringHandlesPagination(): array
    {
        return [];
    }

    protected function normalizeCatalogPage(mixed $page, ?string $sourceKey = null): int
    {
        $page = is_numeric($page) ? (int) $page : 1;
        $maximum = $sourceKey === ProjectSourceKey::CurseForge->value
            ? self::MAX_CURSEFORGE_CATALOG_PAGE
            : self::MAX_CATALOG_PAGE;

        return min($maximum, max(1, $page));
    }

    /**
     * Complete Filament's deferred initial table load, then resize the
     * newly-morphed table content just as we do after other table updates.
     */
    public function loadTable(): void
    {
        $startedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);

        if ($type === ProjectType::ResourcePack) {
            // Resource packs have no archive scan cache. Their direct
            // URL/SHA-1 metadata is the installed-state source of truth.
            $this->getInstalledModsMetadata();
        } else {
            $scanResult = $type === null
                ? null
                : InstalledScanResult::fromCache(
                    Cache::get(ModManager::getHashScanCacheKey($server, $type)),
                );
            $this->setInstalledScanResult($scanResult);
        }

        if ($this->isModManagerTimingEnabled()) {
            Log::info('Mod manager timing', [
                'stage' => 'load_table_prepare',
                'request_id' => $this->modManagerTimingRequestId,
                'active_tab' => $this->activeTab,
                'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }

        $this->baseLoadTable();
    }

    /**
     * Filament's deferred column manager does not update a
     * `tableColumnManager*` property. Its Alpine component directly invokes
     * this method via `$wire.call()` after copying its deferred state to
     * `tableColumns`, so this is the post-morph hook that actually runs when
     * the user presses "Apply columns".
     *
     * @param  array<int, array<string, mixed>>|null  $state
     */
    public function applyTableColumnManager(?array $state = null, bool $wasReordered = false): void
    {
        $this->baseApplyTableColumnManager($state, $wasReordered);
    }

    public function resetTableColumnManager(): void
    {
        $this->baseResetTableColumnManager();
    }

    public function updatedTableSearch(): void
    {
        $this->isTableLoaded = false;

        // CanSearchRecords::updatedTableSearch() (aliased above, since it's
        // pulled into InteractsWithTable from a nested trait) persists the
        // search term to the session and resets the page - both silently
        // dropped if this override doesn't call it. No queueHeaderScroll()
        // here unlike the other two triggers: yanking the page's scroll
        // position while the user is actively typing in the search box
        // would be disruptive, whereas a row count change is the whole
        // point of searching.
        $this->baseUpdatedTableSearch();
    }

    public function updatedTableFilters(): void
    {
        $this->isTableLoaded = false;
        $this->baseUpdatedTableFilters();
    }

    /**
     * Scroll a table page change to Filament's page title after layout settles.
     */
    protected function queueHeaderScroll(): void
    {
        $this->js("window.dispatchEvent(new CustomEvent('mmr:scroll-header'))");
    }

    /**
     * Sources enabled in this server's Mod Manager settings that support the
     * current page's project type. Provider capability and configuration are
     * still evaluated by ProjectSourceRegistry.
     *
     * @return array<int, ProjectSourceInterface>
     */
    protected function getAvailableSources(): array
    {
        if ($this->availableSources !== null) {
            return $this->availableSources;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);

        return $this->availableSources = $type
            ? app(ProjectSourceRegistry::class)->availableFor($server, $type)
            : [];
    }

    /**
     * Sources that can power a browsable catalog tab. Sources such as GitHub
     * Releases remain available for installed-file provenance and their
     * direct-tracking action, but must not produce an always-empty tab.
     *
     * @return array<int, ProjectSourceInterface>
     */
    protected function getCatalogSources(): array
    {
        if ($this->catalogSources !== null) {
            return $this->catalogSources;
        }

        return $this->catalogSources = array_values(array_filter(
            $this->getAvailableSources(),
            static fn (ProjectSourceInterface $source): bool => $source->supportsSearch(),
        ));
    }

    /**
     * The source backing the currently active tab. When only one catalog
     * source is available, it is used regardless of the tab key, since the
     * tab is the source's own catalog label rather than a per-source key.
     */
    protected function getCurrentSource(): ?ProjectSourceInterface
    {
        if ($this->currentSource !== null && $this->currentSourceTab === $this->activeTab) {
            return $this->currentSource;
        }

        $sources = $this->getCatalogSources();

        if (count($sources) <= 1) {
            $this->currentSource = $sources[0] ?? null;
            $this->currentSourceTab = $this->activeTab;

            return $this->currentSource;
        }

        foreach ($sources as $source) {
            if ($source->getKey()->value === $this->activeTab) {
                $this->currentSource = $source;
                $this->currentSourceTab = $this->activeTab;

                return $source;
            }
        }

        $this->currentSource = null;
        $this->currentSourceTab = $this->activeTab;

        return null;
    }

    /**
     * Catalog tabs are ordered by ProjectSourceRegistry. The first configured
     * catalog source is the initial tab, matching the visible tab order.
     */
    public function getDefaultActiveTab(): string|int|null
    {
        $sources = $this->getCatalogSources();

        if (count($sources) > 1) {
            return $sources[0]->getKey()->value;
        }

        return array_key_first($this->getCachedTabs());
    }

    protected function restoreCatalogStateFromUrl(): void
    {
        if ($this->source === null) {
            return;
        }

        $tab = $this->catalogTabForSource($this->source);

        if ($tab === null) {
            $this->source = null;
            unset($this->paginators[self::TABLE_PAGINATOR_NAME]);
            $this->catalogPage = 1;

            return;
        }

        $this->source = $this->sourceForTab($tab);
        $this->activeTab = $tab;
    }

    protected function rejectUnauthorizedInstalledTab(): void
    {
        if ($this->activeTab !== 'installed' || $this->canScanInstalledProjects()) {
            return;
        }

        $this->activeTab = $this->getDefaultActiveTab();
        $this->source = $this->sourceForTab($this->activeTab);
    }

    protected function catalogTabForSource(?string $source): ?string
    {
        if ($source === null || $source === '') {
            return null;
        }

        $sources = $this->getCatalogSources();

        if (count($sources) <= 1) {
            $onlySource = $sources[0]?->getKey()->value;

            return in_array($source, ['all', $onlySource], true) ? 'all' : null;
        }

        return array_key_exists($source, $this->getCachedTabs()) && $source !== 'installed'
            ? $source
            : null;
    }

    protected function sourceForTab(string|int|null $tab): ?string
    {
        if (!is_string($tab) || $tab === 'installed') {
            return null;
        }

        // With one source the visible catalog tab is named `all`, but the
        // source still has a stable URL identity. Keep an explicit source
        // selection copyable without leaking it into the Installed tab.
        if ($tab === 'all') {
            return $this->getCatalogSources()[0]?->getKey()->value;
        }

        return $this->catalogTabForSource($tab) === $tab ? $tab : null;
    }

    protected function syncSourceFromActiveTab(?string $activeTab): void
    {
        if ($this->syncingCatalogUrl || $this->preservingDefaultCatalogSource) {
            return;
        }

        $this->syncingCatalogUrl = true;
        $this->source = $this->sourceForTab($activeTab);
        $this->syncingCatalogUrl = false;
    }

    protected function getSourceLabel(?string $sourceKey): string
    {
        if (!$sourceKey) {
            return '';
        }

        $key = ProjectSourceKey::tryFrom($sourceKey);
        $source = $key ? app(ProjectSourceRegistry::class)->get($key) : null;

        return $source?->getLabel() ?? ucfirst($sourceKey);
    }

    /**
     * One tab per searchable source (when more than one is enabled for this
     * egg), plus the "Installed" tab with the cached scan's file count. A
     * source requiring setup is excluded by ProjectSourceRegistry, so no
     * unusable tab remains. When only one catalog source is available, its
     * label is shown instead of a misleading generic "All" tab.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $sources = $this->getCatalogSources();
        $tabs = [];

        if (count($sources) <= 1) {
            $tabs['all'] = Tab::make($sources[0]?->getLabel() ?? trans('pelican-mod-manager::strings.page.view_all'));
        } else {
            foreach ($sources as $source) {
                $tabs[$source->getKey()->value] = Tab::make($source->getLabel());
            }
        }

        $installedTab = Tab::make(trans('pelican-mod-manager::strings.page.view_installed'));
        if ($this->installedFilesCount !== null && $this->installedFilesCount >= 0) {
            $installedTab = $installedTab->badge($this->installedFilesCount);
        }

        if ($this->canScanInstalledProjects()) {
            $tabs['installed'] = $installedTab;
        }

        return $tabs;
    }

    /**
     * Clamp stale/direct paginator state to a real page. LengthAwarePaginator
     * accepts a current page beyond its last page, which otherwise produces
     * an empty table with a misleading "0 to 0" summary.
     */
    protected function clampTablePage(int $page, int $total): int
    {
        $lastPage = max(1, (int) ceil($total / self::TABLE_PAGE_SIZE));

        return min(max(1, $page), $lastPage);
    }

    protected function synchronizeTablePage(int $page, int $total): int
    {
        $clampedPage = $this->clampTablePage($page, $total);

        if ($clampedPage !== $page) {
            // This runs from the records() callback itself. setPage() would
            // invoke updatedPaginators(), which deliberately returns this
            // table to deferred loading and discards the valid page we can
            // already render in this same response. Keep Livewire's public
            // paginator state in sync directly instead.
            $this->paginators[self::TABLE_PAGINATOR_NAME] = $clampedPage;
            $this->catalogPage = $clampedPage;
        }

        return $clampedPage;
    }

    /** @return array<int, array{source: string, project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string}> */
    protected function getInstalledModsMetadata(): array
    {
        if (!$this->canScanInstalledProjects()) {
            return [];
        }

        if ($this->installedModsMetadata === null) {
            $startedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;

            /** @var Server $server */
            $server = Filament::getTenant();
            /** @var DaemonFileRepository $fileRepository */
            $fileRepository = app(DaemonFileRepository::class);

            $type = static::detectProjectType($server);

            if ($type === ProjectType::ResourcePack) {
                try {
                    $installed = app(ResourcePackService::class)->getInstalled($server, $fileRepository);
                    $this->installedModsMetadata = $installed === null ? [] : [$installed];
                    $this->installedFilesCount = $installed === null ? 0 : 1;
                    $this->installedScanDataReady = true;
                } catch (Exception $exception) {
                    if (function_exists('report') && app()->bound(\Illuminate\Contracts\Debug\ExceptionHandler::class)) {
                        report($exception);
                    }
                    $this->installedModsMetadata = [];
                    $this->installedFilesCount = -1;
                    $this->installedScanDataReady = false;
                }
                unset($this->cachedTabs);

                return $this->installedModsMetadata;
            }

            $generation = CacheVersion::hydration($server);
            $typeKey = $type instanceof ProjectType ? $type->value : 'unknown';
            $cacheKey = "installed_metadata_display:v2:{$server->id}:{$typeKey}:{$generation}";
            $cached = Cache::get($cacheKey);
            $cacheHit = is_array($cached);
            $metadataStatus = 'cache';
            $canPrimeIndex = $cacheHit;

            if ($cacheHit) {
                $this->installedModsMetadata = $cached;
            } else {
                $metadataResult = ModManager::getInstalledMetadataReadResult($server, $fileRepository, $type);
                $this->installedModsMetadata = $metadataResult->document->installedMods();
                $metadataStatus = $metadataResult->status->value;

                // Never turn a transient Wings/metadata read failure into an
                // hour of an apparently empty Installed tab. A valid empty
                // current/legacy document remains authoritative and cacheable.
                //
                // The generation stamp in $cacheKey already invalidates this
                // entry on every write (install/update/uninstall/scan - see
                // InstalledMetadataRepository::write()), so this TTL is only
                // a safety net for edits made outside the plugin (e.g. via
                // the file manager). "Rescan" (scan_mods, below) writes the
                // metadata document unconditionally and so doubles as a
                // manual refresh for that case.
                if ($metadataResult->isAuthoritative()) {
                    Cache::put($cacheKey, $this->installedModsMetadata, now()->addHour());
                    $canPrimeIndex = true;
                }
            }

            if ($canPrimeIndex && $type instanceof ProjectType) {
                app(InstalledMetadataIndex::class)->prime(
                    $server,
                    $type,
                    $generation,
                    $this->installedModsMetadata,
                );
            }

            if ($this->isModManagerTimingEnabled()) {
                Log::info('Mod manager timing', [
                    'stage' => 'installed_metadata',
                    'request_id' => $this->modManagerTimingRequestId,
                    'cache_hit' => $cacheHit,
                    'metadata_status' => $metadataStatus,
                    'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                    'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'entries' => count($this->installedModsMetadata),
                ]);
            }
        }

        return $this->installedModsMetadata;
    }

    /** @return array{source: string, project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    protected function getInstalledMod(string $projectId, string $sourceKey = ''): ?array
    {
        $sourceKey = $sourceKey !== '' ? $sourceKey : ProjectSourceKey::Modrinth->value;
        if ($this->installedModsIndex === null) {
            $this->installedModsIndex = [];

            foreach ($this->getInstalledModsMetadata() as $mod) {
                $key = ($mod['source'] ?? ProjectSourceKey::Modrinth->value).':'.$mod['project_id'];
                $this->installedModsIndex[$key] = $mod;
            }
        }

        return $this->installedModsIndex[$sourceKey.':'.$projectId] ?? null;
    }

    /**
     * Build only the current catalog page's installed-membership map. The
     * complete Wings metadata document is decoded once per hydration
     * generation, then later catalog renders use cache multi-get for their
     * visible identities. Installed-tab and mutation workflows deliberately
     * keep their authoritative full-document reads.
     *
     * @param array<int, array<string, mixed>> $records
     */
    protected function hydrateVisibleInstalledModsIndex(array $records, Server $server, ProjectType $type): void
    {
        if ($type === ProjectType::ResourcePack
            || $this->installedModsMetadata !== null
            || !$this->canScanInstalledProjects()) {
            return;
        }

        $identities = [];

        foreach ($records as $record) {
            $projectId = $record['project_id'] ?? null;
            $sourceKey = $record['source'] ?? null;

            if (is_string($projectId) && $projectId !== ''
                && is_string($sourceKey) && ProjectSourceKey::tryFrom($sourceKey) !== null) {
                $identities[] = InstalledMetadataIndex::identity($sourceKey, $projectId);
            }
        }

        if ($identities === []) {
            $this->installedModsIndex = [];

            return;
        }

        /** @var DaemonFileRepository $fileRepository */
        $fileRepository = app(DaemonFileRepository::class);
        $this->installedModsIndex = app(InstalledMetadataIndex::class)->getMany(
            $server,
            $type,
            CacheVersion::hydration($server),
            $identities,
            fn () => ModManager::getInstalledMetadataReadResult($server, $fileRepository, $type),
        );
    }

    /**
     * Read the metadata document directly before a mutating operation. The
     * component-local metadata cache is deliberately suitable for rendering,
     * but it must not decide whether a concurrent install is an Install or
     * Update operation (nor which old archive needs removing).
     *
     * @return array<string, mixed>|null
     *
     * @throws Exception
     */
    protected function getCurrentInstalledModForOperation(
        Server $server,
        DaemonFileRepository $fileRepository,
        ProjectType $type,
        string $projectId,
        ProjectSourceKey $sourceKey,
    ): ?array {
        if ($type === ProjectType::ResourcePack) {
            // There is only one active resource pack. Return it regardless of
            // the requested project so replacing a pack is authorized as an
            // update and does not leave the old URL/hash behind.
            return app(ResourcePackService::class)->getInstalled($server, $fileRepository);
        }

        $metadataResult = ModManager::getInstalledMetadataReadResult($server, $fileRepository, $type);

        // Treat a failed direct read as an error, rather than accidentally
        // treating an existing project as a fresh install.
        if (!$metadataResult->isAuthoritative() && $metadataResult->status !== InstalledMetadataReadStatus::Missing) {
            throw new Exception('Unable to verify installed project metadata');
        }

        foreach ($metadataResult->document->installedMods() as $installedMod) {
            if (($installedMod['project_id'] ?? null) === $projectId
                && ($installedMod['source'] ?? ProjectSourceKey::Modrinth->value) === $sourceKey->value) {
                return $installedMod;
            }
        }

        return null;
    }

    protected function forgetInstalledModsMetadata(): void
    {
        $this->installedModsMetadata = null;
        $this->installedModsIndex = null;
    }

    protected function forgetVersionCaches(): void
    {
        $this->versionsCache = [];
        $this->latestVersionsCache = [];
    }

    protected function forgetVersionCache(string $cacheIndex): void
    {
        unset($this->versionsCache[$cacheIndex]);
        unset($this->latestVersionsCache[$cacheIndex]);
    }

    /** @return array<int, mixed> */
    protected function getCachedVersions(string $projectId, string $sourceKey): array
    {
        $cacheIndex = "$sourceKey:$projectId";

        if (!isset($this->versionsCache[$cacheIndex])) {
            $startedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;

            /** @var Server $server */
            $server = Filament::getTenant();
            $type = static::detectProjectType($server);
            $source = app(ProjectSourceRegistry::class)->get(ProjectSourceKey::tryFrom($sourceKey) ?? ProjectSourceKey::Modrinth);

            $this->versionsCache[$cacheIndex] = ($source && $type) ? $source->getVersions($projectId, $server, $type) : [];

            if ($this->isModManagerTimingEnabled()) {
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $this->modManagerTimingVersionLookups++;
                $this->modManagerTimingVersionLookupDurationMs += $durationMs;

                Log::info('Mod manager timing', [
                    'stage' => 'record_version_lookup',
                    'request_id' => $this->modManagerTimingRequestId,
                    'source' => $sourceKey,
                    'project_id' => $projectId,
                    'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                    'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                    'duration_ms' => $durationMs,
                    'versions_count' => count($this->versionsCache[$cacheIndex]),
                ]);
            }
        }

        return $this->versionsCache[$cacheIndex];
    }

    /** @return array<string, mixed>|null */
    protected function getCachedLatestVersion(string $projectId, string $sourceKey): ?array
    {
        $cacheIndex = "$sourceKey:$projectId";

        if (!array_key_exists($cacheIndex, $this->latestVersionsCache)) {
            $startedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;
            $installedMod = $this->getInstalledMod($projectId, $sourceKey);

            /** @var Server $server */
            $server = Filament::getTenant();
            $type = static::detectProjectType($server);
            $result = ($installedMod !== null && $type !== null)
                ? app(VersionLookupCoordinator::class)->lookupInstalled([$installedMod], $server, $type)
                : null;

            $this->latestVersionsCache[$cacheIndex] = $result?->version($cacheIndex);
            if ($this->isModManagerTimingEnabled()) {
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $this->modManagerTimingVersionLookups++;
                $this->modManagerTimingVersionLookupDurationMs += $durationMs;

                Log::info('Mod manager timing', [
                    'stage' => 'record_version_lookup',
                    'request_id' => $this->modManagerTimingRequestId,
                    'source' => $sourceKey,
                    'project_id' => $projectId,
                    'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                    'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                    'duration_ms' => $durationMs,
                    'versions_count' => $this->latestVersionsCache[$cacheIndex] === null ? 0 : 1,
                    'coordinated' => true,
                ]);
            }
        }

        return $this->latestVersionsCache[$cacheIndex];
    }

    /**
     * Whether the given project's latest-version lookup is still waiting on
     * a background revalidation queued by peekVisibleLatestVersions(). Only
     * ever true on the Installed tab; the catalog tab's warm path never
     * leaves an entry pending.
     */
    protected function isLatestVersionPending(string $projectId, string $sourceKey): bool
    {
        return isset($this->pendingLatestVersionKeys["$sourceKey:$projectId"]);
    }

    /**
     * Non-blocking latest-version lookup used by both the Installed tab
     * and catalog rows that overlap installed projects, so a cold cache
     * never blocks the response. A cache hit (fresh or stale) is used
     * immediately; a miss queues a background revalidation and leaves
     * the entry out of latestVersionsCache entirely (see
     * isLatestVersionPending()) so it isn't mistaken for a confirmed
     * no-update result.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return bool Whether any of the given records is still pending.
     */
    protected function peekVisibleLatestVersions(array $records, Server $server, ProjectType $type): bool
    {
        $this->hydrateVisibleInstalledModsIndex($records, $server, $type);

        $installedMods = [];

        foreach ($records as $record) {
            $projectId = $record['project_id'] ?? null;
            $sourceKey = $record['source'] ?? null;

            if (!is_string($projectId) || $projectId === '' || !is_string($sourceKey) || $sourceKey === '') {
                continue;
            }

            $cacheIndex = "$sourceKey:$projectId";
            if (array_key_exists($cacheIndex, $this->latestVersionsCache) && !isset($this->pendingLatestVersionKeys[$cacheIndex])) {
                continue;
            }

            $installedMod = $this->getInstalledMod($projectId, $sourceKey);
            if ($installedMod !== null) {
                $installedMods[$cacheIndex] = $installedMod;
            }
        }

        if ($installedMods === []) {
            return $this->pendingLatestVersionKeys !== [];
        }

        $startedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;
        $result = app(VersionLookupCoordinator::class)->peekInstalled(array_values($installedMods), $server, $type);

        foreach (array_keys($installedMods) as $cacheIndex) {
            if ($result->isPending($cacheIndex)) {
                $this->pendingLatestVersionKeys[$cacheIndex] = true;

                continue;
            }

            unset($this->pendingLatestVersionKeys[$cacheIndex]);
            $this->latestVersionsCache[$cacheIndex] = $result->version($cacheIndex);
        }

        if ($this->isModManagerTimingEnabled()) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::info('Mod manager timing', [
                'stage' => 'record_version_lookup_peek',
                'request_id' => $this->modManagerTimingRequestId,
                'source' => 'coordinator',
                'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                'duration_ms' => $durationMs,
                'project_count' => count($installedMods),
                'resolved_count' => count($result->versions()),
                'unresolved_count' => count($result->unresolvedKeys()),
                'failed_count' => count($result->failures()),
                'pending_count' => count($result->pendingKeys()),
            ]);
        }

        return $this->pendingLatestVersionKeys !== [];
    }

    protected function getCachedDatapackWorldName(Server $server, DaemonFileRepository $fileRepository): string
    {
        if ($this->datapackWorldName === null) {
            $this->datapackWorldName = ModManager::getDatapackWorldName($server, $fileRepository);
        }

        return $this->datapackWorldName;
    }

    /**
     * Resolve a folder for display-only UI such as header actions. Mutating
     * operations continue to resolve the folder at execution time.
     */
    protected function getDisplayProjectFolder(
        Server $server,
        DaemonFileRepository $fileRepository,
        ProjectType $type,
    ): string {
        if ($type === ProjectType::Datapack) {
            return $this->getCachedDatapackWorldName($server, $fileRepository).'/datapacks';
        }

        return ModManager::getProjectFolder($server, $fileRepository, $type);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getPrimaryFile(mixed $files): ?array
    {
        return ProjectPrimaryFile::fromFiles($files);
    }

    /**
     * @throws Exception
     */
    protected function validateFilename(string $filename): string
    {
        if ($filename === '' || $filename === '.' || str_contains($filename, "\0") || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new Exception('Invalid filename: potential path traversal detected');
        }

        return basename($filename);
    }

    /** @param array<string, mixed> $record */
    protected function getExternalProjectUrl(array $record): ?string
    {
        $sourceKey = $record['source'] ?? null;
        $slug = $record['slug'] ?? null;

        if (!$sourceKey || !$slug) {
            return null;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);
        $projectType = $type?->value ?? ($record['project_type'] ?? 'mod');

        return match ($sourceKey) {
            ProjectSourceKey::Modrinth->value => "https://modrinth.com/{$projectType}/{$slug}",
            ProjectSourceKey::CurseForge->value => 'https://www.curseforge.com/minecraft/'.match ($projectType) {
                ProjectType::Plugin->value => 'bukkit-plugins',
                ProjectType::Datapack->value => 'data-packs',
                ProjectType::ResourcePack->value => 'texture-packs',
                default => 'mc-mods',
            }."/{$slug}",
            ProjectSourceKey::Hangar->value => empty($record['author']) ? null : "https://hangar.papermc.io/{$record['author']}/{$slug}",
            ProjectSourceKey::GitHubReleases->value => "https://github.com/{$slug}",
            default => null,
        };
    }

    protected function canManageProjectOperation(Server $server, ProjectOperation $operation): bool
    {
        $memoKey = $server->getKey().':'.$operation->value;
        if (array_key_exists($memoKey, $this->projectOperationPermissionMemo)) {
            return $this->projectOperationPermissionMemo[$memoKey];
        }

        return $this->projectOperationPermissionMemo[$memoKey] = app(ProjectOperationAuthorizer::class)
            ->allows(user(), $server, $operation);
    }

    protected function canManageCurrentProjectOperation(ProjectOperation $operation): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $this->canManageProjectOperation($server, $operation);
    }

    protected function canManageInstallOrUpdate(Server $server): bool
    {
        return $this->canManageProjectOperation($server, ProjectOperation::Install)
            || $this->canManageProjectOperation($server, ProjectOperation::Update);
    }

    protected function canManageCurrentInstallOrUpdate(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $this->canManageInstallOrUpdate($server);
    }

    protected function canScanInstalledProjects(): bool
    {
        try {
            $server = Filament::getTenant();

            return $server instanceof Server
                && $this->canManageProjectOperation($server, ProjectOperation::Scan);
        } catch (Throwable) {
            return false;
        }
    }

    protected function actorUserIdForScan(): ?int
    {
        $id = user()?->getKey();

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    protected function authorizeProjectOperation(Server $server, ProjectOperation $operation): void
    {
        abort_unless($this->canManageProjectOperation($server, $operation), 403);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $versionData
     * @param  array<string, mixed>  $primaryFile
     * @param  array<string, mixed>|null  $installedMod
     *
     * @throws Exception
     */
    private function performInstallOrUpdate(
        Server $server,
        DaemonFileRepository $fileRepository,
        array $record,
        array $versionData,
        array $primaryFile,
        ?array $installedMod = null
    ): void {
        $type = static::detectProjectType($server);
        if (!$type) {
            throw new Exception('Server does not support managed projects');
        }

        $currentInstalled = $type === ProjectType::ResourcePack
            ? app(ResourcePackService::class)->getInstalled($server, $fileRepository)
            : $installedMod;

        $this->authorizeProjectOperation(
            $server,
            $currentInstalled === null ? ProjectOperation::Install : ProjectOperation::Update,
        );

        $safeNewFilename = $this->validateFilename((string) ($primaryFile['filename'] ?? ''));

        if ($type === ProjectType::ResourcePack) {
            $operation = $currentInstalled === null
                ? InstalledOperationLease::OPERATION_INSTALL
                : InstalledOperationLease::OPERATION_UPDATE;

            app(InstalledOperationLease::class)->run(
                (int) $server->getKey(),
                $type,
                $operation,
                fn (): array => app(ResourcePackService::class)->installOrUpdate(
                    $server,
                    $fileRepository,
                    $record,
                    $versionData,
                    $primaryFile,
                ),
            );

            $this->forgetInstalledModsMetadata();
            $this->forgetVersionCaches();
            $this->flushCachedTableRecords();

            return;
        }

        app(InstalledProjectMutationService::class)->installOrUpdate(
            $server,
            $fileRepository,
            $type,
            $record,
            $versionData,
            $installedMod,
            $primaryFile,
        );

        Cache::forget(ModManager::getHashScanCacheKey($server, $type));
        $this->setInstalledScanResult(null);
        $this->unknownFiles = array_values(
            array_filter($this->unknownFiles, fn (string $filename) => strtolower($filename) !== strtolower($safeNewFilename))
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function performUninstall(
        Server $server,
        DaemonFileRepository $fileRepository,
        array $record,
        ProjectType $type,
    ): void {
        if ($type === ProjectType::ResourcePack) {
            app(ResourcePackService::class)->uninstall($server, $fileRepository);
            $this->forgetInstalledModsMetadata();
            $this->forgetVersionCaches();
            $this->installedFilesCount = 0;
            $this->installedScanDataReady = true;
            unset($this->cachedTabs);
            $this->flushCachedTableRecords();

            return;
        }

        $safeFilename = $this->getUninstallFilename($record);
        $folder = ModManager::getProjectFolder($server, $fileRepository, $type);

        Http::daemon($server->node)
            ->post("/api/servers/{$server->uuid}/files/delete", [
                'root' => '/',
                'files' => [$folder.'/'.$safeFilename],
            ])
            ->throw();

        Cache::forget(ModManager::getHashScanCacheKey($server, $type));
        $this->setInstalledScanResult(null);
        $this->unknownFiles = array_values(
            array_filter($this->unknownFiles, fn (string $filename) => strtolower($filename) !== strtolower($safeFilename))
        );

        $sourceKey = ProjectSourceKey::tryFrom($record['source'] ?? '') ?? ProjectSourceKey::Modrinth;

        $metadataRemoved = true;
        if (!empty($record['project_id'])) {
            $metadataRemoved = ModManager::removeModMetadata($server, $fileRepository, $record['project_id'], $type, $sourceKey);
        }

        if (!$metadataRemoved) {
            Log::warning('Failed to remove mod metadata after successful file deletion', [
                'project_id' => $record['project_id'],
                'source' => $sourceKey->value,
                'server_id' => $server->id,
            ]);

            if (is_array($this->installedModsMetadata)) {
                $this->installedModsMetadata = array_values(
                    array_filter(
                        $this->installedModsMetadata,
                        fn ($mod) => !($mod['project_id'] === $record['project_id'] && ($mod['source'] ?? ProjectSourceKey::Modrinth->value) === $sourceKey->value)
                    )
                );
            }

            $this->forgetVersionCache("{$sourceKey->value}:{$record['project_id']}");
        } else {
            $this->forgetInstalledModsMetadata();
            $this->forgetVersionCaches();
        }

        if ($this->activeTab === 'installed') {
            $this->flushCachedTableRecords();
        }
    }

    /**
     * @param  array<string, mixed>  $record
     *
     * @throws Exception
     */
    private function getUninstallFilename(array $record): string
    {
        if (($record['untracked'] ?? false) === true) {
            return $this->validateFilename((string) ($record['title'] ?? ''));
        }

        if (empty($record['project_id'])) {
            throw new Exception('Missing project ID for uninstall');
        }

        $installedMod = $this->getInstalledMod($record['project_id'], $record['source'] ?? ProjectSourceKey::Modrinth->value);

        if (!$installedMod) {
            throw new Exception('Mod not found in metadata');
        }

        return $this->validateFilename($installedMod['filename']);
    }

    /**
     * Whether ->records()'s current (activeTab, page, search, filters,
     * sort) already has real data available with no upstream I/O required,
     * so ->deferLoading() can be skipped and this response can go out with
     * real records already in place instead of paying for the second
     * Livewire round trip (wire:init="loadTable") a deferred table always
     * costs.
     *
     * Deliberately always false for the Installed tab: unlike the catalog
     * tab, records()'s installed branch can still discover a missing scan
     * cache and dispatch a job (or show the queue-configuration warning).
     * hasWarmRecordsCache() can only see the longer-lived metadata display
     * cache, not that separate scan-result cache. Keeping the Installed tab
     * unconditionally deferred gives that state transition its own request;
     * the manager rejects sync/null queues, and the render itself remains
     * non-blocking through peekVisibleLatestVersions()/peekInstalled() and
     * pollEnrichment().
     */
    protected function hasWarmRecordsCache(): bool
    {
        if ($this->activeTab === 'installed') {
            return false;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);
        $currentSource = $this->getCurrentSource();

        if (!$type || !$currentSource || !$currentSource->isConfigured() || !$currentSource->supportsSearch()) {
            // records() resolves this to an empty paginator with no cache
            // lookup at all - nothing a deferred round trip would hide.
            return true;
        }

        $filterState = $this->tableFilters ?? [];
        $category = $filterState['catalog_category']['value'] ?? null;
        $environment = $filterState['catalog_environment']['value'] ?? null;

        return $currentSource->hasCachedSearch(
            $server,
            $type,
            (int) $this->getTablePage(),
            $this->getTableSearch(),
            [
                'sort' => $this->catalogSort,
                'category' => $category,
                'environment' => $currentSource->getKey() === ProjectSourceKey::Modrinth ? $environment : null,
            ],
        );
    }

    protected function lowercaseInstalledSearchValue(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    /**
     * @param  array<int, array<string, mixed>>  $installedMods
     * @param  array<int, string>  $unknownFiles
     * @return array{mods: array<int, array<string, mixed>>, unknown: array<int, string>}
     */
    protected function applyInstalledSearch(array $installedMods, array $unknownFiles, ?string $search): array
    {
        if (!$search) {
            return [
                'mods' => array_values($installedMods),
                'unknown' => array_values($unknownFiles),
            ];
        }

        $searchLower = $this->lowercaseInstalledSearchValue($search);

        return [
            'mods' => array_values(array_filter($installedMods, function (array $mod) use ($searchLower) {
                return str_contains($this->lowercaseInstalledSearchValue($mod['project_title'] ?? ''), $searchLower)
                    || str_contains($this->lowercaseInstalledSearchValue($mod['project_slug'] ?? ''), $searchLower);
            })),
            'unknown' => array_values(array_filter(
                $unknownFiles,
                fn (string $filename) => str_contains($this->lowercaseInstalledSearchValue($filename), $searchLower),
            )),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $installedMods
     * @return array<int, array<string, mixed>>
     */
    protected function installedModsInSourceOrder(array $installedMods): array
    {
        $installedBySource = [];
        foreach ($installedMods as $installedMod) {
            $sourceKey = $installedMod['source'] ?? ProjectSourceKey::Modrinth->value;
            $installedBySource[$sourceKey][] = $installedMod;
        }

        return $installedBySource !== []
            ? array_merge(...array_values($installedBySource))
            : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     */
    protected function enrichmentSignatureFromProjects(array $projects, bool $latestPending): string
    {
        $parts = [];

        foreach ($projects as $project) {
            $parts[] = implode("\1", [
                (string) ($project['project_id'] ?? ''),
                (string) ($project['source'] ?? ''),
                (string) ($project['icon_url'] ?? ''),
                (string) ($project['downloads'] ?? ''),
                (string) ($project['date_modified'] ?? ''),
                !empty($project['enrichment_pending']) ? '1' : '0',
            ]);
        }

        $parts[] = $latestPending ? '1' : '0';

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @param  array<int, array<string, mixed>>  $hits
     * @return array<int, array{project_id: string, source: string}>
     */
    protected function catalogEnrichmentPeekKeysFromHits(array $hits): array
    {
        $keys = [];

        foreach ($hits as $hit) {
            $projectId = $hit['project_id'] ?? null;
            $sourceKey = $hit['source'] ?? null;

            if (!is_string($projectId) || $projectId === '' || !is_string($sourceKey) || $sourceKey === '') {
                continue;
            }

            $keys[] = [
                'project_id' => $projectId,
                'source' => $sourceKey,
            ];
        }

        return $keys;
    }

    /**
     * @param  array<int, array<string, mixed>>  $hits
     */
    protected function catalogEnrichmentSignatureFromHits(array $hits): string
    {
        $pending = [];
        $resolved = [];

        foreach ($hits as $hit) {
            $projectId = $hit['project_id'] ?? null;
            $sourceKey = $hit['source'] ?? null;

            if (!is_string($projectId) || $projectId === '' || !is_string($sourceKey) || $sourceKey === '') {
                continue;
            }

            $cacheIndex = "$sourceKey:$projectId";

            if (isset($this->pendingLatestVersionKeys[$cacheIndex])) {
                $pending[] = $cacheIndex;
            }

            $version = $this->latestVersionsCache[$cacheIndex] ?? null;
            $resolved[$cacheIndex] = is_array($version) ? (string) ($version['id'] ?? '') : '';
        }

        sort($pending);
        ksort($resolved);

        return hash('sha256', json_encode([$pending, $resolved], JSON_THROW_ON_ERROR));
    }

    protected function peekCatalogEnrichmentSignature(): ?string
    {
        try {
            /** @var Server $server */
            $server = Filament::getTenant();
            $type = static::detectProjectType($server);
            $hits = $this->catalogEnrichmentPeekKeys;

            if ($hits !== [] && $type !== null) {
                $this->peekVisibleLatestVersions($hits, $server, $type);
            }

            return $this->catalogEnrichmentSignatureFromHits($hits);
        } catch (Exception) {
            return null;
        }
    }

    protected function peekInstalledEnrichmentSignature(): ?string
    {
        try {
            /** @var Server $server */
            $server = Filament::getTenant();
            $type = static::detectProjectType($server);
            $search = $this->getTableSearch();
            $page = (int) $this->getTablePage();
            $installedMods = $this->getInstalledModsMetadata();
            $filtered = $this->applyInstalledSearch($installedMods, [], $search);
            $pagedInstalledMods = array_slice(
                $this->installedModsInSourceOrder($filtered['mods']),
                ($page - 1) * self::TABLE_PAGE_SIZE,
                self::TABLE_PAGE_SIZE,
            );

            if ($pagedInstalledMods !== [] && $type !== null) {
                $this->peekVisibleLatestVersions($pagedInstalledMods, $server, $type);
            }

            $projects = $pagedInstalledMods !== []
                ? app(ProjectSourceRegistry::class)->peekInstalled($pagedInstalledMods, $server)
                : [];

            return $this->enrichmentSignatureFromProjects(
                $projects,
                $this->pendingLatestVersionKeys !== [],
            );
        } catch (Exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $record */
    protected function projectIconImgAttributes(array $record): array
    {
        return ProjectIconUrl::imgAttributes($this->projectIconRowIndex($record));
    }

    /** @param array<string, mixed> $record */
    protected function projectIconRowIndex(array $record): int
    {
        if ($this->projectIconRowIndexMap === null) {
            $this->projectIconRowIndexMap = [];
            $index = 0;

            foreach ($this->getTableRecords() as $row) {
                if (is_array($row)) {
                    $this->projectIconRowIndexMap[$this->projectIconRecordKey($row)] = $index;
                }
                $index++;
            }
        }

        return $this->projectIconRowIndexMap[$this->projectIconRecordKey($record)] ?? PHP_INT_MAX;
    }

    /** @param array<string, mixed> $record */
    protected function projectIconRecordKey(array $record): string
    {
        return ($record['source'] ?? '')."\0".($record['project_id'] ?? '')."\0".($record['title'] ?? '');
    }

    protected function truncateProjectDescription(string $description): string
    {
        return Str::limit($description, 120, '...');
    }

    protected function parseExternalProjectDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $key = trim($value);
        if (array_key_exists($key, $this->externalProjectDateMemo)) {
            return $this->externalProjectDateMemo[$key];
        }

        try {
            return $this->externalProjectDateMemo[$key] = Carbon::parse($key, 'UTC');
        } catch (Exception) {
            return $this->externalProjectDateMemo[$key] = null;
        }
    }

    protected function formatExternalProjectDate(mixed $value): string
    {
        return $this->parseExternalProjectDate($value)?->diffForHumans() ?? '';
    }

    /**
     * Resource packs have one metadata entry and no managed archive scan.
     * Keep the normal installed-table enrichment path, but never ask Wings to
     * enumerate the resourcepacks directory or write the mod metadata file.
     */
    protected function resourcePackRecords(Server $server, ?string $search, int $page): LengthAwarePaginator
    {
        $this->unknownFiles = [];
        $this->pollInstalledOperations = false;

        $filtered = $this->applyInstalledSearch(
            $this->getInstalledModsMetadata(),
            [],
            $search,
        );
        $installedMods = $this->installedModsInSourceOrder($filtered['mods']);
        $page = $this->synchronizeTablePage($page, count($installedMods));
        $pagedInstalledMods = array_slice(
            $installedMods,
            ($page - 1) * self::TABLE_PAGE_SIZE,
            self::TABLE_PAGE_SIZE,
        );

        $enrichmentPending = false;
        if ($pagedInstalledMods !== []) {
            $enrichmentPending = $this->peekVisibleLatestVersions($pagedInstalledMods, $server, ProjectType::ResourcePack);
        }

        $projects = $pagedInstalledMods
            ? app(ProjectSourceRegistry::class)->peekInstalled($pagedInstalledMods, $server)
            : [];

        foreach ($projects as $project) {
            if ($project['enrichment_pending'] ?? false) {
                $enrichmentPending = true;

                break;
            }
        }

        $this->pollEnrichment = $enrichmentPending
            && app(InstalledOperationManager::class)->supportsAsyncDispatch();
        $this->installedEnrichmentSignature = $this->enrichmentSignatureFromProjects(
            $projects,
            $this->pendingLatestVersionKeys !== [],
        );

        return new LengthAwarePaginator($projects, count($installedMods), self::TABLE_PAGE_SIZE, $page);
    }

    /**
     * @throws Exception
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page) {
                $this->projectIconRowIndexMap = null;

                /** @var Server $server */
                $server = Filament::getTenant();
                $type = static::detectProjectType($server);

                if ($this->activeTab === 'installed') {
                    if ($type === ProjectType::ResourcePack) {
                        return $this->resourcePackRecords($server, $search, $page);
                    }

                    $perPage = self::TABLE_PAGE_SIZE;
                    $scanCacheKey = ModManager::getHashScanCacheKey($server, $type);
                    $operations = app(InstalledOperationManager::class);
                    $snapshot = $operations->installedTabCacheSnapshot($server, $type, $scanCacheKey);
                    $scanResult = InstalledScanResult::fromCache($snapshot['scan_result']);
                    $installedMods = $this->getInstalledModsMetadata();
                    $unknownFiles = $scanResult === null ? [] : $scanResult->unknownFiles;
                    $this->unknownFiles = $unknownFiles;
                    $this->setInstalledScanResult($scanResult);

                    $scanState = $snapshot['scan'];

                    if ($scanResult === null && $scanState === null && $this->canScanInstalledProjects()) {
                        $dispatch = $operations->dispatchScan(
                            $server,
                            $type,
                            actorUserId: $this->actorUserIdForScan(),
                        );
                        $scanState = $dispatch['state'];

                        if ($dispatch['reason'] === 'sync_queue' && !$this->operationQueueWarningShown) {
                            $this->operationQueueWarningShown = true;
                            Notification::make()
                                ->title(trans('pelican-mod-manager::strings.operations.queue_required'))
                                ->warning()
                                ->send();
                        }
                    }

                    if ($scanState !== null) {
                        $this->setInstalledOperationState($scanState);
                    } else {
                        $state = $this->refreshInstalledOperationState(
                            $snapshot['scan'],
                            $snapshot['bulk'],
                            true,
                        );
                        $this->pollInstalledOperations = $state === null
                            ? $scanResult === null && !$this->operationQueueWarningShown
                            : $this->shouldPollInstalledOperation($state);
                    }

                    $filtered = $this->applyInstalledSearch($installedMods, $unknownFiles, $search);
                    $installedMods = $filtered['mods'];
                    $unknownFiles = $filtered['unknown'];

                    // hydrateInstalled()/peekInstalled() group records by source.
                    // Reproduce that ordering before pagination, then hydrate only
                    // this page's records instead of every installed project on
                    // every request.
                    $orderedInstalledMods = $this->installedModsInSourceOrder($installedMods);
                    $totalCount = count($orderedInstalledMods) + count($unknownFiles);
                    $page = $this->synchronizeTablePage($page, $totalCount);
                    $offset = ($page - 1) * $perPage;
                    $pagedInstalledMods = array_slice($orderedInstalledMods, $offset, $perPage);

                    // Neither call below performs an upstream fetch: both are
                    // cache-only reads that queue a background revalidation on a
                    // miss instead of blocking the response (see SourceCache::
                    // swrDeferred()). pollEnrichment drives a self-terminating
                    // poll (see getHeaderActions()/EmbeddedTable wrapper) that
                    // reloads the table once the background fills land.
                    $enrichmentPending = false;

                    if ($pagedInstalledMods !== [] && $type !== null) {
                        $enrichmentPending = $this->peekVisibleLatestVersions($pagedInstalledMods, $server, $type);
                    }

                    $projects = $pagedInstalledMods
                        ? app(ProjectSourceRegistry::class)->peekInstalled($pagedInstalledMods, $server)
                        : [];

                    foreach ($projects as $project) {
                        if ($project['enrichment_pending'] ?? false) {
                            $enrichmentPending = true;

                            break;
                        }
                    }

                    // A sync/null queue cannot complete a deferred metadata
                    // fill, so polling it would only repeat the same cache
                    // reads and table render indefinitely.
                    $this->pollEnrichment = $enrichmentPending && $operations->supportsAsyncDispatch();
                    $this->installedEnrichmentSignature = $this->enrichmentSignatureFromProjects(
                        $projects,
                        $this->pendingLatestVersionKeys !== [],
                    );

                    $unknownOffset = max(0, $offset - count($orderedInstalledMods));
                    $remainingSlots = $perPage - count($pagedInstalledMods);
                    foreach (array_slice($unknownFiles, $unknownOffset, $remainingSlots) as $filename) {
                        $projects[] = [
                            'project_id' => null,
                            'slug' => null,
                            'title' => $filename,
                            'description' => null,
                            'icon_url' => null,
                            'author' => null,
                            'downloads' => null,
                            'date_modified' => null,
                            'source' => null,
                            'untracked' => true,
                        ];
                    }

                    return new LengthAwarePaginator($projects, $totalCount, $perPage, $page);
                }

                $currentSource = $this->getCurrentSource();

                $catalogStartedAt = $this->isModManagerTimingEnabled() ? microtime(true) : 0.0;

                if (!$type || !$currentSource || !$currentSource->isConfigured() || !$currentSource->supportsSearch()) {
                    $this->pollEnrichment = false;
                    $this->catalogEnrichmentPeekKeys = [];
                    $this->installedEnrichmentSignature = null;

                    return new LengthAwarePaginator([], 0, self::TABLE_PAGE_SIZE, $this->synchronizeTablePage($page, 0));
                }

                // Treat the URL/paginator as untrusted input. This second
                // boundary also protects direct Livewire calls that bypassed
                // mount()/updatedCatalogPage().
                $page = $this->normalizeCatalogPage($page, $currentSource->getKey()->value);
                $this->paginators[self::TABLE_PAGINATOR_NAME] = $page;
                $this->catalogPage = $page;

                $filterState = $this->tableFilters ?? [];
                $category = $filterState['catalog_category']['value'] ?? null;
                $environment = $filterState['catalog_environment']['value'] ?? null;
                $sortOption = $this->catalogSort;

                $searchCatalog = fn (int $catalogPage): array => $currentSource->search(
                    $server,
                    $type,
                    $catalogPage,
                    $search,
                    [
                        'sort' => $sortOption,
                        'category' => $category,
                        'environment' => $currentSource->getKey() === ProjectSourceKey::Modrinth ? $environment : null,
                    ],
                );
                $requestedPage = $page;
                $response = $searchCatalog($page);
                $page = $this->synchronizeTablePage($page, (int) $response['total_hits']);

                if ($page !== $requestedPage && $response['total_hits'] > 0) {
                    $response = $searchCatalog($page);
                }

                if ($this->isModManagerTimingEnabled()) {
                    RequestPerformanceProfiler::mergeContext([
                        'source' => $currentSource->getKey()->value,
                        'hits' => count($response['hits']),
                    ]);
                    Log::info('Mod manager timing', [
                        'stage' => 'catalog_records',
                        'request_id' => $this->modManagerTimingRequestId,
                        'source' => $currentSource->getKey()->value,
                        'started_after_ms' => $this->getModManagerTimingElapsedMs($catalogStartedAt),
                        'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                        'duration_ms' => (int) round((microtime(true) - $catalogStartedAt) * 1000),
                        'hits' => count($response['hits']),
                    ]);
                }

                $hits = array_map(function (array $hit) use ($currentSource) {
                    $hit['source'] = $currentSource->getKey()->value;

                    return $hit;
                }, $response['hits']);

                // Catalog update badges overlap installed projects. Do not
                // block first paint on those lookups: peek queues a
                // background fill and pollEnrichment reloads the row once
                // it lands, same as the Installed tab.
                $this->pendingLatestVersionKeys = [];
                $versionPending = $this->peekVisibleLatestVersions($hits, $server, $type);
                $this->pollEnrichment = $versionPending
                    && app(InstalledOperationManager::class)->supportsAsyncDispatch();
                $this->catalogEnrichmentPeekKeys = $this->catalogEnrichmentPeekKeysFromHits($hits);
                $this->installedEnrichmentSignature = $this->catalogEnrichmentSignatureFromHits($hits);

                return new LengthAwarePaginator($hits, $response['total_hits'], self::TABLE_PAGE_SIZE, $page);
            })
            // Render the page shell immediately, unless the current request's
            // records are already cached (fresh or stale) with no upstream
            // fetch required - in which case skip the deferred round trip
            // entirely and render real records synchronously. See
            // hasWarmRecordsCache() for what "warm" means per tab, and why
            // the Installed tab is deliberately excluded from this check.
            ->deferLoading(fn (): bool => !$this->hasWarmRecordsCache())
            ->paginated([self::TABLE_PAGE_SIZE])
            // Category labels can be long (for example, "Armor, Tools, and
            // Weapons"), so retain a wider filters panel for the two real filters.
            ->filtersFormWidth(Width::Medium)
            ->filters([
                SelectFilter::make('catalog_category')
                    ->label(trans('pelican-mod-manager::strings.table.filters.category'))
                    ->options(fn () => $this->getCatalogCategoryOptions())
                    ->visible(fn () => $this->activeTab !== 'installed' && $this->getCatalogCategoryOptions() !== []),
                SelectFilter::make('catalog_environment')
                    ->label(trans('pelican-mod-manager::strings.table.filters.environment'))
                    ->options([
                        'server' => trans('pelican-mod-manager::strings.table.filters.environment_server'),
                        'client' => trans('pelican-mod-manager::strings.table.filters.environment_client'),
                    ])
                    ->visible(fn () => $this->activeTab !== 'installed' && $this->getCurrentSource()?->getKey() === ProjectSourceKey::Modrinth),
            ])
            ->emptyStateHeading(function () {
                $currentSource = $this->getCurrentSource();

                if ($this->activeTab !== 'installed' && $currentSource && !$currentSource->isConfigured()) {
                    return trans('pelican-mod-manager::strings.page.source_not_configured_heading');
                }

                return null;
            })
            ->emptyStateDescription(function () {
                $currentSource = $this->getCurrentSource();

                if ($this->activeTab !== 'installed' && $currentSource && !$currentSource->isConfigured()) {
                    return trans('pelican-mod-manager::strings.page.source_not_configured');
                }

                return null;
            })
            ->columns([
                ImageColumn::make('icon_url')
                    ->label('')
                    // ImageColumn omits its <img> when the state is blank. A
                    // local SVG keeps the common placeholder structure without
                    // an external request or visible "No image" text.
                    ->defaultImageUrl(ProjectIconUrl::placeholderDataUri())
                    ->alignCenter()
                    // The client-side stale preview updates only values in the
                    // real Filament cell. Keep this selector independent of
                    // Filament's generated HTML below the cell.
                    ->extraCellAttributes(['data-mmr-swr-cell' => 'icon', 'class' => 'mmr-project-icon-cell'])
                    ->extraImgAttributes(fn (array $record): array => $this->projectIconImgAttributes($record)),
                TextColumn::make('title')
                    ->label(trans('pelican-mod-manager::strings.table.columns.title'))
                    ->searchable()
                    ->wrap()
                    ->lineClamp(1)
                    ->extraCellAttributes(['data-mmr-swr-cell' => 'title'])
                    ->description(function (array $record): ?string {
                        if ($record['untracked'] ?? false) {
                            return trans('pelican-mod-manager::strings.badges.untracked');
                        }

                        $description = $record['description'] ?? null;
                        if (!is_string($description)) {
                            return null;
                        }

                        return $this->truncateProjectDescription($description);
                    }),
                TextColumn::make('source')
                    ->label(trans('pelican-mod-manager::strings.table.columns.source'))
                    ->badge()
                    ->extraCellAttributes(['data-mmr-swr-cell' => 'source'])
                    ->formatStateUsing(fn (?string $state) => $this->getSourceLabel($state))
                    ->color(fn (?string $state) => match ($state) {
                        'modrinth' => 'success',
                        'curseforge' => 'warning',
                        'hangar' => 'info',
                        'github_releases' => 'gray',
                        default => 'gray',
                    })
                    ->visible(fn () => $this->activeTab === 'installed' && count($this->getAvailableSources()) > 1)
                    ->toggleable(),
                TextColumn::make('author')
                    ->label(trans('pelican-mod-manager::strings.table.columns.author'))
                    ->url(fn (array $record, $state) => (($record['source'] ?? null) === ProjectSourceKey::Modrinth->value && $state) ? "https://modrinth.com/user/$state" : null, true)
                    ->extraCellAttributes(['data-mmr-swr-cell' => 'author'])
                    ->toggleable(),
                TextColumn::make('downloads')
                    ->label(trans('pelican-mod-manager::strings.table.columns.downloads'))
                    ->numeric()
                    ->prefix(new HtmlString('<span class="mmr-stat-icon" data-mmr-stat-icon="downloads" aria-hidden="true"></span>'))
                    ->extraCellAttributes(['data-mmr-swr-cell' => 'downloads'])
                    ->toggleable(),
                TextColumn::make('date_modified')
                    ->label(trans('pelican-mod-manager::strings.table.columns.date_modified'))
                    ->prefix(new HtmlString('<span class="mmr-stat-icon" data-mmr-stat-icon="calendar" aria-hidden="true"></span>'))
                    ->formatStateUsing(fn ($state): string => $this->formatExternalProjectDate($state))
                    ->tooltip(function ($state) use ($table): string {
                        $date = $this->parseExternalProjectDate($state);

                        return $date?->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) ?? '';
                    })
                    ->extraCellAttributes(['data-mmr-swr-cell' => 'date_modified'])
                    ->toggleable(),
            ])
            ->recordUrl(function (array $record) {
                if (!empty($record['unavailable']) || ($record['untracked'] ?? false)) {
                    return null;
                }

                return $this->getExternalProjectUrl($record);
            }, true)
            ->recordActions([
                CatalogRowAction::compact('versions', 'info')
                    ->tooltip(trans('pelican-mod-manager::strings.actions.versions'))
                    ->hidden(fn (array $record): bool => $record['untracked'] ?? false)
                    ->modalSubmitAction(false)
                    ->schema(function (array $record) {
                        $sourceKey = $record['source'] ?? ProjectSourceKey::Modrinth->value;
                        $versions = $this->getCachedVersions($record['project_id'], $sourceKey);

                        $installedMod = $this->getInstalledMod($record['project_id'], $sourceKey);
                        $installedVersionId = $installedMod['version_id'] ?? null;

                        $sections = [];
                        foreach ($versions as $versionIndex => $versionData) {
                            $primaryFile = $this->getPrimaryFile($versionData['files'] ?? []);

                            $sectionComponents = [
                                TextEntry::make('type_' . $versionIndex)
                                    ->label(trans('pelican-mod-manager::strings.version.type'))
                                    ->state($versionData['version_type'] ?? '')
                                    ->badge()
                                    ->color(match ($versionData['version_type'] ?? '') {
                                        'release' => 'success',
                                        'beta' => 'warning',
                                        'alpha' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('downloads_' . $versionIndex)
                                    ->label(trans('pelican-mod-manager::strings.version.downloads'))
                                    ->state($versionData['downloads'] ?? 0)
                                    ->icon('tabler-download')
                                    ->numeric(),
                                TextEntry::make('published_' . $versionIndex)
                                    ->label(trans('pelican-mod-manager::strings.version.published'))
                                    ->state(fn (): string => $this->formatExternalProjectDate($versionData['date_published'] ?? null)),
                            ];

                            if (!empty($versionData['changelog'])) {
                                $sectionComponents[] = TextEntry::make('changelog_' . $versionIndex)
                                    ->label(trans('pelican-mod-manager::strings.version.changelog'))
                                    ->state($versionData['changelog'])
                                    ->markdown();
                            }

                            if (($versionData['id'] ?? null) === $installedVersionId) {
                                $headerAction = Action::make('installed_' . $versionIndex)
                                    ->label(trans('pelican-mod-manager::strings.actions.installed'))
                                    ->icon('tabler-check')
                                    ->color('success')
                                    ->disabled();
                                $sectionIcon = 'tabler-check';
                                $sectionIconColor = 'success';
                            } else {
                                $headerAction = Action::make('install_version_' . $versionIndex)
                                    ->label(trans('pelican-mod-manager::strings.actions.install'))
                                    ->icon('tabler-download')
                                    ->authorize(fn (): bool => $this->canManageCurrentInstallOrUpdate())
                                    ->disabled($primaryFile === null)
                                    ->action(function (DaemonFileRepository $fileRepository) use ($record, $versionData, $sourceKey) {
                                        try {
                                            /** @var Server $server */
                                            $server = Filament::getTenant();

                                            if (!isset($versionData['id'], $versionData['version_number'])) {
                                                throw new Exception('Invalid version data structure');
                                            }

                                            $primaryFile = $this->getPrimaryFile($versionData['files'] ?? null);

                                            if (!$primaryFile) {
                                                throw new Exception('No downloadable file found');
                                            }

                                            $type = static::detectProjectType($server);
                                            if (!$type) {
                                                throw new Exception('Server does not support managed projects');
                                            }

                                            $installedMod = $this->getCurrentInstalledModForOperation(
                                                $server,
                                                $fileRepository,
                                                $type,
                                                $record['project_id'],
                                                ProjectSourceKey::tryFrom($sourceKey) ?? ProjectSourceKey::Modrinth,
                                            );

                                            $this->performInstallOrUpdate($server, $fileRepository, $record, $versionData, $primaryFile, $installedMod);

                                            $this->forgetInstalledModsMetadata();
                                            $this->forgetVersionCaches();
                                            $this->flushCachedTableRecords();

                                            Notification::make()
                                                ->title(trans('pelican-mod-manager::strings.notifications.install_success'))
                                                ->body(trans('pelican-mod-manager::strings.notifications.install_success_body', [
                                                    'name' => $record['title'],
                                                    'version' => $versionData['version_number'],
                                                ]))
                                                ->success()
                                                ->send();
                                        } catch (Exception $exception) {
                                            report($exception);

                                            $this->forgetInstalledModsMetadata();
                                            $this->forgetVersionCaches();
                                            $this->flushCachedTableRecords();

                                            Notification::make()
                                                ->title(trans('pelican-mod-manager::strings.notifications.install_failed'))
                                                ->body(trans('pelican-mod-manager::strings.notifications.install_failed_body'))
                                                ->danger()
                                                ->send();
                                        }
                                    });
                                $sectionIcon = null;
                                $sectionIconColor = null;
                            }

                            $section = Section::make($versionData['version_number'] ?? '')
                                ->headerActions([$headerAction])
                                ->schema($sectionComponents)
                                ->collapsible()
                                ->collapsed(!($versionData['featured'] ?? false));

                            if ($sectionIcon !== null) {
                                $section = $section->icon($sectionIcon)->iconColor($sectionIconColor);
                            }

                            $sections[] = $section;
                        }

                        return $sections;
                    }),
                CatalogRowAction::compact('install_latest', 'success')
                    ->tooltip(trans('pelican-mod-manager::strings.actions.install_latest'))
                    ->authorize(fn (): bool => $this->canManageCurrentInstallOrUpdate())
                    ->hidden(fn (array $record): bool => $record['untracked'] ?? false)
                    ->visible(function (array $record) {
                        if (empty($record['project_id'])) {
                            return false;
                        }

                        return is_null($this->getInstalledMod($record['project_id'], $record['source'] ?? ProjectSourceKey::Modrinth->value));
                    })
                    ->action(function (array $record, DaemonFileRepository $fileRepository) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            $type = static::detectProjectType($server);

                            $sourceKey = ProjectSourceKey::tryFrom($record['source'] ?? '') ?? ProjectSourceKey::Modrinth;
                            $source = app(ProjectSourceRegistry::class)->get($sourceKey);

                            if (!$source || !$type) {
                                throw new Exception('Source unavailable');
                            }

                            $versions = $source->getVersions($record['project_id'], $server, $type);

                            if (empty($versions)) {
                                throw new Exception('No compatible versions found');
                            }

                            $latestVersion = $versions[0];

                            if (!isset($latestVersion['id'], $latestVersion['version_number'], $latestVersion['files'])) {
                                throw new Exception('Invalid version data structure');
                            }

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            $installedMod = $this->getCurrentInstalledModForOperation(
                                $server,
                                $fileRepository,
                                $type,
                                $record['project_id'],
                                $sourceKey,
                            );

                            $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile, $installedMod);

                            $this->forgetInstalledModsMetadata();
                            $this->forgetVersionCaches();

                            Notification::make()
                                ->title(trans('pelican-mod-manager::strings.notifications.install_success'))
                                ->body(trans('pelican-mod-manager::strings.notifications.install_success_body', [
                                    'name' => $record['title'],
                                    'version' => $latestVersion['version_number'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->forgetInstalledModsMetadata();
                            $this->forgetVersionCaches();

                            Notification::make()
                                ->title(trans('pelican-mod-manager::strings.notifications.install_failed'))
                                ->body(trans('pelican-mod-manager::strings.notifications.install_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                CatalogRowAction::compact('update', 'warning')
                    ->tooltip(trans('pelican-mod-manager::strings.actions.update'))
                    ->authorize(fn (): bool => $this->canManageCurrentProjectOperation(ProjectOperation::Update))
                    ->hidden(fn (array $record): bool => $record['untracked'] ?? false)
                    ->visible(function (array $record) {
                        if (empty($record['project_id'])) {
                            return false;
                        }

                        $sourceKey = $record['source'] ?? ProjectSourceKey::Modrinth->value;
                        $installedMod = $this->getInstalledMod($record['project_id'], $sourceKey);

                        if (is_null($installedMod)) {
                            return false;
                        }

                        // The update badge is still being resolved in the
                        // background (see peekVisibleLatestVersions()). Default
                        // to "installed" (see that action's visible()) rather
                        // than claiming no update is available.
                        if ($this->isLatestVersionPending($record['project_id'], $sourceKey)) {
                            return false;
                        }

                        $latestVersion = $this->getCachedLatestVersion($record['project_id'], $sourceKey);

                        if ($latestVersion === null) {
                            return false;
                        }

                        return $installedMod['version_id'] !== ($latestVersion['id'] ?? null);
                    })
                    ->requiresConfirmation()
                    ->modalHeading(trans('pelican-mod-manager::strings.modals.update_heading'))
                    ->modalDescription(function (array $record) {
                        $sourceKey = $record['source'] ?? ProjectSourceKey::Modrinth->value;
                        $installedMod = $this->getInstalledMod($record['project_id'], $sourceKey);
                        $latestVersion = $this->getCachedLatestVersion($record['project_id'], $sourceKey);

                        return trans('pelican-mod-manager::strings.modals.update_description', [
                            'old_version' => $installedMod['version_number'] ?? 'unknown',
                            'new_version' => $latestVersion['version_number'] ?? 'unknown',
                        ]);
                    })
                    ->action(function (array $record, DaemonFileRepository $fileRepository) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            $type = static::detectProjectType($server);

                            $sourceKey = ProjectSourceKey::tryFrom($record['source'] ?? '') ?? ProjectSourceKey::Modrinth;
                            if (!$type) {
                                throw new Exception('Source unavailable');
                            }

                            $installedMod = $this->getCurrentInstalledModForOperation(
                                $server,
                                $fileRepository,
                                $type,
                                $record['project_id'],
                                $sourceKey,
                            );

                            if (!$installedMod) {
                                throw new Exception('Mod not found in metadata');
                            }

                            $source = app(ProjectSourceRegistry::class)->get($sourceKey);

                            if (!$source) {
                                throw new Exception('Source unavailable');
                            }

                            $latestVersion = $this->getCachedLatestVersion($record['project_id'], $sourceKey->value);
                            if ($latestVersion === null) {
                                $versions = $source->getVersions($record['project_id'], $server, $type);
                                if (empty($versions)) {
                                    throw new Exception('No compatible versions found');
                                }

                                $latestVersion = $versions[0];
                            }

                            if (!isset($latestVersion['id'], $latestVersion['version_number'], $latestVersion['files'])) {
                                throw new Exception('Invalid version data structure');
                            }

                            $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                            if (!$primaryFile) {
                                throw new Exception('No downloadable file found');
                            }

                            $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile, $installedMod);

                            $this->forgetInstalledModsMetadata();
                            $this->forgetVersionCaches();

                            Notification::make()
                                ->title(trans('pelican-mod-manager::strings.notifications.update_success'))
                                ->body(trans('pelican-mod-manager::strings.notifications.update_success_body', [
                                    'version' => $latestVersion['version_number'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->forgetInstalledModsMetadata();
                            $this->forgetVersionCaches();

                            Notification::make()
                                ->title(trans('pelican-mod-manager::strings.notifications.update_failed'))
                                ->body(trans('pelican-mod-manager::strings.notifications.update_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
                CatalogRowAction::compact('installed', 'success')
                    ->tooltip(trans('pelican-mod-manager::strings.actions.installed'))
                    ->disabled()
                    ->hidden(fn (array $record): bool => $record['untracked'] ?? false)
                    ->visible(function (array $record) {
                        if (empty($record['project_id'])) {
                            return false;
                        }

                        $sourceKey = $record['source'] ?? ProjectSourceKey::Modrinth->value;
                        $installedMod = $this->getInstalledMod($record['project_id'], $sourceKey);

                        if (is_null($installedMod)) {
                            return false;
                        }

                        // Default to "installed" while the update badge is
                        // still resolving in the background - see the
                        // update action's visible(). No update is shown as
                        // available until it's actually confirmed, and the
                        // row corrects itself once pollEnrichment reloads
                        // the table.
                        if ($this->isLatestVersionPending($record['project_id'], $sourceKey)) {
                            return true;
                        }

                        $latestVersion = $this->getCachedLatestVersion($record['project_id'], $sourceKey);

                        if ($latestVersion === null) {
                            return true;
                        }

                        return $installedMod['version_id'] === ($latestVersion['id'] ?? null);
                    }),
                CatalogRowAction::compact('uninstall', 'danger')
                    ->tooltip(trans('pelican-mod-manager::strings.actions.uninstall'))
                    ->authorize(fn (): bool => $this->canManageCurrentProjectOperation(ProjectOperation::Delete))
                    ->visible(function (array $record) {
                        if (($record['untracked'] ?? false) === true) {
                            return true;
                        }

                        if (empty($record['project_id'])) {
                            return false;
                        }

                        return !is_null($this->getInstalledMod($record['project_id'], $record['source'] ?? ProjectSourceKey::Modrinth->value));
                    })
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('pelican-mod-manager::strings.modals.uninstall_heading'))
                    ->modalDescription(fn (array $record) => trans('pelican-mod-manager::strings.modals.uninstall_description', ['name' => $record['title']]))
                    ->action(function (array $record, DaemonFileRepository $fileRepository) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            $this->authorizeProjectOperation($server, ProjectOperation::Delete);

                            $type = static::detectProjectType($server);
                            if (!$type) {
                                throw new Exception('Server does not support managed projects');
                            }

                            app(InstalledOperationLease::class)->run(
                                (int) $server->getKey(),
                                $type,
                                InstalledOperationLease::OPERATION_UNINSTALL,
                                function () use ($server, $fileRepository, $record, $type): void {
                                    $this->performUninstall($server, $fileRepository, $record, $type);
                                },
                            );

                            Notification::make()
                                ->title(trans('pelican-mod-manager::strings.notifications.uninstall_success'))
                                ->body(trans('pelican-mod-manager::strings.notifications.uninstall_success_body', [
                                    'name' => $record['title'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->forgetInstalledModsMetadata();
                            $this->forgetVersionCaches();

                            if ($this->activeTab === 'installed') {
                                $this->flushCachedTableRecords();
                            }

                            Notification::make()
                                ->title(trans('pelican-mod-manager::strings.notifications.uninstall_failed'))
                                ->body(trans('pelican-mod-manager::strings.notifications.uninstall_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    /** @return array<string, string> */
    protected function getCatalogCategoryOptions(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $sourceKey = $this->getCurrentSource()?->getKey()?->value;
        $typeValue = static::detectProjectType($server)?->value;
        $memoKey = ($sourceKey ?? '').':'.($typeValue ?? '');
        if ($this->catalogCategoryOptions !== null && $this->catalogCategoryOptionsKey === $memoKey) {
            return $this->catalogCategoryOptions;
        }

        $this->catalogCategoryOptionsKey = $memoKey;

        $type = static::detectProjectType($server);
        if ($type === null) {
            return $this->catalogCategoryOptions = [];
        }

        $source = $this->getCurrentSource();

        return $this->catalogCategoryOptions = match ($source?->getKey()) {
            ProjectSourceKey::Modrinth => $this->modrinthCategoryOptions($type),
            ProjectSourceKey::CurseForge => $source instanceof CurseForgeSource
                ? $source->catalogCategoryOptions($type)
                : [],
            ProjectSourceKey::Hangar => $type === ProjectType::Plugin ? [
                'admin_tools' => 'Admin Tools',
                'chat' => 'Chat',
                'dev_tools' => 'Developer Tools',
                'economy' => 'Economy',
                'gameplay' => 'Gameplay',
                'games' => 'Games',
                'protection' => 'Protection',
                'role_playing' => 'Role Playing',
                'world_management' => 'World Management',
                'misc' => 'Miscellaneous',
            ] : [],
            default => [],
        };
    }

    /** @return array<string, string> */
    private function modrinthCategoryOptions(ProjectType $type): array
    {
        $categories = $type === ProjectType::ResourcePack
            ? ['combat', 'cursed', 'decoration', 'modded', 'realistic', 'simplistic', 'themed', 'tweaks', 'utility', 'vanilla-like', 'audio', 'blocks', 'core-shaders', 'entities', 'environment', 'equipment', 'fonts', 'gui', 'items', 'locale', 'models', '8x-', '16x', '32x', '48x', '64x', '128x', '256x', '512x+']
            : ['adventure', 'cursed', 'decoration', 'economy', 'equipment', 'food', 'game-mechanics', 'library', 'magic', 'management', 'minigame', 'mobs', 'optimization', 'social', 'storage', 'technology', 'transportation', 'utility', 'worldgen'];

        return array_combine($categories, array_map(
            static fn (string $category): string => Str::of($category)->replace('-', ' ')->title()->toString(),
            $categories,
        ));
    }

    /** @return array<string, string> */
    protected function getCatalogLoaderOverrideOptions(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);
        $source = $this->getCurrentSource()?->getKey();

        $loaders = match (true) {
            $type === ProjectType::Mod && in_array($source, [ProjectSourceKey::Modrinth, ProjectSourceKey::CurseForge], true) => [
                MinecraftLoader::NeoForge,
                MinecraftLoader::Forge,
                MinecraftLoader::Fabric,
                MinecraftLoader::Quilt,
            ],
            $type === ProjectType::Plugin && $source === ProjectSourceKey::Modrinth => [
                MinecraftLoader::Paper,
                MinecraftLoader::Purpur,
                MinecraftLoader::Folia,
                MinecraftLoader::Spigot,
                MinecraftLoader::Bukkit,
                MinecraftLoader::Sponge,
                MinecraftLoader::Velocity,
                MinecraftLoader::Waterfall,
                MinecraftLoader::Bungeecord,
            ],
            $type === ProjectType::Plugin && $source === ProjectSourceKey::Hangar => [
                MinecraftLoader::Paper,
                MinecraftLoader::Velocity,
                MinecraftLoader::Waterfall,
            ],
            default => [],
        };

        $options = [];
        foreach ($loaders as $loader) {
            $options[$loader->value] = $loader->getLabel();
        }

        return $options;
    }

    protected function canOverrideCatalogVersion(): bool
    {
        return $this->activeTab !== 'installed'
            && $this->getCurrentSource()?->supportsSearch() === true;
    }

    protected function hasCatalogCompatibilityOverride(): bool
    {
        return ($this->canOverrideCatalogVersion() && $this->minecraftVersionOverride !== null)
            || ($this->loaderOverride !== null && array_key_exists($this->loaderOverride, $this->getCatalogLoaderOverrideOptions()));
    }

    protected function normalizeCatalogCompatibilityOverrides(): void
    {
        $version = is_string($this->minecraftVersionOverride) ? trim($this->minecraftVersionOverride) : '';
        $this->minecraftVersionOverride = $version !== '' && preg_match('/^[0-9A-Za-z._+\-]{1,32}$/', $version) === 1
            ? $version
            : null;

        $loader = is_string($this->loaderOverride) ? trim($this->loaderOverride) : '';
        $this->loaderOverride = MinecraftLoader::tryFrom($loader)?->value;
    }

    protected function configureCatalogCompatibilityOverride(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        if (!$this->canOverrideCatalogVersion()) {
            $this->minecraftVersionOverride = null;
        }
        if ($this->loaderOverride !== null
            && !array_key_exists($this->loaderOverride, $this->getCatalogLoaderOverrideOptions())) {
            $this->loaderOverride = null;
        }

        CatalogCompatibilityOverride::set(
            $server,
            $this->minecraftVersionOverride,
            $this->loaderOverride,
        );
    }

    protected function automaticMinecraftVersion(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return CatalogCompatibilityOverride::without(
            $server,
            fn (): string => MinecraftVersionResolver::resolve($server) ?? trans('pelican-mod-manager::strings.page.unknown'),
        );
    }

    protected function automaticMinecraftLoader(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return CatalogCompatibilityOverride::without(
            $server,
            fn (): string => MinecraftLoader::fromServer($server)?->getLabel() ?? trans('pelican-mod-manager::strings.page.unknown'),
        );
    }

    public function applyCatalogCompatibilityOverride(array $data): void
    {
        $this->minecraftVersionOverride = $this->canOverrideCatalogVersion()
            ? ($data['minecraft_version'] ?? null)
            : null;
        $this->loaderOverride = array_key_exists((string) ($data['loader'] ?? ''), $this->getCatalogLoaderOverrideOptions())
            ? (string) $data['loader']
            : null;
        $this->normalizeCatalogCompatibilityOverrides();
        $this->configureCatalogCompatibilityOverride();
        $this->refreshCatalogAfterCompatibilityChange();
    }

    public function resetCatalogCompatibilityOverride(): void
    {
        $this->minecraftVersionOverride = null;
        $this->loaderOverride = null;
        $this->configureCatalogCompatibilityOverride();
        $this->refreshCatalogAfterCompatibilityChange();
    }

    private function refreshCatalogAfterCompatibilityChange(): void
    {
        $this->isTableLoaded = false;
        $this->catalogPage = 1;
        unset($this->paginators[self::TABLE_PAGINATOR_NAME]);
        $this->forgetVersionCaches();
        $this->resetTable();
        $this->dispatchCatalogWarm(false);
    }

    protected function getHeaderActions(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = static::detectProjectType($server);
        if (!$type) {
            return [];
        }

        /** @var DaemonFileRepository $fileRepository */
        $fileRepository = app(DaemonFileRepository::class);
        $folder = $this->getDisplayProjectFolder($server, $fileRepository, $type);

        $githubSource = app(ProjectSourceRegistry::class)->get(ProjectSourceKey::GitHubReleases);
        $availableSourceKeys = array_map(fn (ProjectSourceInterface $source) => $source->getKey()->value, $this->getAvailableSources());
        $githubAvailable = $githubSource
            && $githubSource->supportsProjectType($type)
            && in_array(ProjectSourceKey::GitHubReleases->value, $availableSourceKeys, true);

        return [
            Action::make('catalog_compatibility_override')
                ->label(fn (): string => $this->hasCatalogCompatibilityOverride()
                    ? trans('pelican-mod-manager::strings.table.override.active')
                    : trans('pelican-mod-manager::strings.table.override.label'))
                ->icon('tabler-adjustments-horizontal')
                ->color(fn (): string => $this->hasCatalogCompatibilityOverride() ? 'warning' : 'gray')
                ->visible(fn (): bool => $this->canOverrideCatalogVersion() || $this->getCatalogLoaderOverrideOptions() !== [])
                ->modalHeading(trans('pelican-mod-manager::strings.table.override.heading'))
                ->modalDescription(trans('pelican-mod-manager::strings.table.override.description'))
                ->modalWidth(Width::Small)
                ->modalSubmitActionLabel(trans('pelican-mod-manager::strings.table.override.apply'))
                ->fillForm(fn (): array => [
                    'minecraft_version' => $this->minecraftVersionOverride,
                    'loader' => $this->loaderOverride,
                ])
                ->schema([
                    TextInput::make('minecraft_version')
                        ->label(trans('pelican-mod-manager::strings.table.override.minecraft_version'))
                        ->helperText(fn (): string => trans('pelican-mod-manager::strings.table.override.automatic_value', ['value' => $this->automaticMinecraftVersion()]))
                        ->maxLength(32)
                        ->regex('/^[0-9A-Za-z._+\-]+$/')
                        ->visible(fn (): bool => $this->canOverrideCatalogVersion()),
                    Select::make('loader')
                        ->label(trans('pelican-mod-manager::strings.table.override.loader'))
                        ->helperText(fn (): string => trans('pelican-mod-manager::strings.table.override.automatic_value', ['value' => $this->automaticMinecraftLoader()]))
                        ->options(fn (): array => $this->getCatalogLoaderOverrideOptions())
                        ->native(false)
                        ->visible(fn (): bool => $this->getCatalogLoaderOverrideOptions() !== []),
                ])
                ->extraModalFooterActions([
                    Action::make('reset_catalog_compatibility_override')
                        ->label(trans('pelican-mod-manager::strings.table.override.reset'))
                        ->color('gray')
                        ->action(fn () => $this->resetCatalogCompatibilityOverride())
                        ->cancelParentActions(),
                ])
                ->action(fn (array $data) => $this->applyCatalogCompatibilityOverride($data)),
            Action::make('open_folder')
                ->label(fn () => trans('pelican-mod-manager::strings.page.open_folder', ['folder' => $folder]))
                ->tooltip(fn () => trans('pelican-mod-manager::strings.page.open_folder', ['folder' => $folder]))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(['path' => $folder]), true)
                ->visible(fn () => $type !== ProjectType::ResourcePack),
            Action::make('track_github_repo')
                ->label(trans('pelican-mod-manager::strings.actions.track_github_repo'))
                ->icon('tabler-brand-github')
                ->authorize(fn (): bool => $this->canManageInstallOrUpdate($server))
                ->disabled(fn () => !$githubSource?->isConfigured())
                ->tooltip(fn () => $githubSource?->isConfigured() ? null : trans('pelican-mod-manager::strings.page.source_not_configured'))
                ->schema([
                    TextInput::make('repository')
                        ->label(trans('pelican-mod-manager::strings.page.github_repo_label'))
                        ->placeholder('owner/repo')
                        ->helperText(trans('pelican-mod-manager::strings.page.github_repo_helper'))
                        ->required(),
                ])
                ->action(function (array $data, DaemonFileRepository $fileRepository) use ($server, $type, $githubSource) {
                    try {
                        if (!$githubSource) {
                            throw new Exception('GitHub Releases source not available');
                        }

                        $project = $githubSource->resolveProjectByIdentifier(trim($data['repository']));

                        if (!$project) {
                            throw new Exception('Repository not found');
                        }

                        $versions = $githubSource->getVersions($project['project_id'], $server, $type);

                        if (empty($versions) || !isset($versions[0]['id'], $versions[0]['version_number'], $versions[0]['files'])) {
                            throw new Exception('No installable release found for this repository');
                        }

                        $latestVersion = $versions[0];
                        $primaryFile = $this->getPrimaryFile($latestVersion['files']);

                        if (!$primaryFile) {
                            throw new Exception('No downloadable file found');
                        }

                        $record = [
                            'project_id' => $project['project_id'],
                            'slug' => $project['slug'],
                            'title' => $project['title'],
                            'author' => $project['author'] ?? null,
                            'source' => ProjectSourceKey::GitHubReleases->value,
                        ];

                        $installedMod = $this->getCurrentInstalledModForOperation(
                            $server,
                            $fileRepository,
                            $type,
                            $record['project_id'],
                            ProjectSourceKey::GitHubReleases,
                        );

                        $this->performInstallOrUpdate($server, $fileRepository, $record, $latestVersion, $primaryFile, $installedMod);

                        $this->forgetInstalledModsMetadata();
                        $this->forgetVersionCaches();
                        $this->flushCachedTableRecords();

                        Notification::make()
                            ->title(trans('pelican-mod-manager::strings.notifications.install_success'))
                            ->body(trans('pelican-mod-manager::strings.notifications.install_success_body', [
                                'name' => $project['title'],
                                'version' => $latestVersion['version_number'],
                            ]))
                            ->success()
                            ->send();
                    } catch (Exception $exception) {
                        report($exception);

                        Notification::make()
                            ->title(trans('pelican-mod-manager::strings.notifications.install_failed'))
                            ->body(trans('pelican-mod-manager::strings.notifications.install_failed_body'))
                            ->danger()
                            ->send();
                    }
                })
                ->visible(fn () => $githubAvailable && $this->canManageInstallOrUpdate($server)),
            Action::make('update_all')
                ->label(fn () => trans(match ($type) {
                    ProjectType::Plugin => 'pelican-mod-manager::strings.actions.update_all_plugins',
                    ProjectType::Datapack => 'pelican-mod-manager::strings.actions.update_all_datapacks',
                    default => 'pelican-mod-manager::strings.actions.update_all_mods',
                }))
                ->icon('tabler-download')
                ->color('warning')
                ->requiresConfirmation()
                ->authorize(fn (): bool => $this->canManageProjectOperation($server, ProjectOperation::Update))
                ->action(function () use ($server, $type) {
                    $this->authorizeProjectOperation($server, ProjectOperation::Update);

                    $dispatch = app(InstalledOperationManager::class)->dispatchBulkUpdate($server, $type);
                    $this->notifyInstalledOperationDispatched($dispatch);
                })
                ->visible(fn () => static::detectProjectType($server) !== null
                    && $this->activeTab === 'installed'
                    && static::detectProjectType($server) !== ProjectType::ResourcePack
                    && $this->canManageProjectOperation($server, ProjectOperation::Update)),
            Action::make('scan_mods')
                ->label(fn () => $this->getRescanActionLabel($type))
                ->tooltip(fn () => $this->getRescanActionLabel($type))
                ->icon('tabler-search')
                ->authorize(fn (): bool => $this->canScanInstalledProjects())
                ->action(function () use ($server, $type) {
                    $this->authorizeProjectOperation($server, ProjectOperation::Scan);

                    $dispatch = app(InstalledOperationManager::class)->dispatchScan(
                        $server,
                        $type,
                        force: true,
                        actorUserId: $this->actorUserIdForScan(),
                    );
                    $this->notifyInstalledOperationDispatched($dispatch);
                })
                ->visible(fn () => static::detectProjectType($server) !== null
                    && static::detectProjectType($server) !== ProjectType::ResourcePack
                    && $this->canScanInstalledProjects()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = static::detectProjectType($server);

        if ($type === null) {
            // Only reachable via needsManualEggSetup() - canAccess() already
            // guarantees that when detectProjectType() is null.
            return $this->eggManualSetupContent($schema, $server);
        }

        return $schema
            ->components([
                Grid::make(match ($type) {
                    ProjectType::Datapack => 4,
                    ProjectType::ResourcePack => 2,
                    default => 3,
                })
                    ->extraAttributes(['class' => 'mmr-page-header'])
                    ->schema([
                        TextEntry::make('minecraft_version')
                            ->label(trans('pelican-mod-manager::strings.page.minecraft_version'))
                            ->state(fn () => ModManager::getMinecraftVersion($server) ?? trans('pelican-mod-manager::strings.page.unknown'))
                            ->badge()
                            ->size(TextSize::Large),
                        ...($type === ProjectType::Datapack ? [
                            TextEntry::make('world')
                                ->label(trans('pelican-mod-manager::strings.page.world'))
                                ->state(fn (DaemonFileRepository $fileRepository) => $this->getCachedDatapackWorldName($server, $fileRepository))
                                ->badge()
                                ->size(TextSize::Large),
                        ] : []),
                        ...($type !== ProjectType::ResourcePack ? [
                            TextEntry::make('loader')
                                ->label(trans('pelican-mod-manager::strings.page.loader'))
                                ->state(fn () => MinecraftLoader::fromServer($server)?->getLabel() ?? trans('pelican-mod-manager::strings.page.unknown'))
                                ->icon(function () use ($server) {
                                    $loader = MinecraftLoader::fromServer($server);
                                    if (!$loader) {
                                        return null;
                                    }
                                    $name = strtolower($loader->name);
                                    $path = plugin_path('pelican-mod-manager', 'resources/icons/loaders/' . $name . '.svg');

                                    return file_exists($path) ? 'mcloader-' . $name : null;
                                })
                                // Stage 8 diagnostic (依頼 I): says which
                                // detection tier decided the shown type/loader.
                                ->tooltip(fn () => trans('pelican-mod-manager::strings.page.resolved_by', ['source' => $this->eggResolutionSourceLabel($server)]))
                                ->badge()
                                ->size(TextSize::Large)
                                ->extraAttributes(['class' => 'mcloader-badge']),
                        ] : []),
                        TextEntry::make('installed')
                            // $type is non-null for the rest of this method
                            // (see the early return above), so unlike
                            // getNavigationLabel()'s/getExternalProjectUrl()'s
                            // own $type?-> uses, this one is provably dead
                            // defensiveness - confirmed by PHPStan flagging
                            // it once that guard was added.
                            ->label(fn () => trans('pelican-mod-manager::strings.page.installed', ['type' => $type->getLabel()]))
                            ->state(fn () => match (true) {
                                $this->installedFilesCount === null => '…',
                                $this->installedFilesCount < 0 => trans('pelican-mod-manager::strings.page.unknown'),
                                default => $this->installedFilesCount,
                            })
                            ->badge()
                            ->size(TextSize::Large),
                    ]),
                $this->getTabsContentComponent(),
                Section::make()
                    ->extraAttributes(fn () => $this->installedOperationStatusExtraAttributes())
                    ->schema([
                        TextEntry::make('installed_operation_status')
                            ->hiddenLabel()
                            ->state(fn () => $this->installedOperationStatus())
                            // Mirrors installedOperationIsActive()'s split: the
                            // spinning loader belongs only to states that are
                            // genuinely still in flight. The terminal states get
                            // an icon that reads as an outcome instead, so a
                            // finished scan no longer looks like a running one.
                            ->icon(fn () => match ($this->getInstalledOperationDisplayPayload()['status'] ?? null) {
                                InstalledOperationState::STATUS_COMPLETED => 'tabler-check',
                                InstalledOperationState::STATUS_FAILED => 'tabler-alert-triangle',
                                default => $this->operationQueueWarningShown
                                    ? 'tabler-alert-triangle'
                                    : 'tabler-loader-2',
                            })
                            ->badge()
                            ->color(fn () => match ($this->getInstalledOperationDisplayPayload()['status'] ?? null) {
                                InstalledOperationState::STATUS_RUNNING => 'info',
                                InstalledOperationState::STATUS_COMPLETED => 'success',
                                InstalledOperationState::STATUS_FAILED => 'danger',
                                default => $this->operationQueueWarningShown ? 'danger' : 'gray',
                            })
                            // TextEntry has no dedicated hook for extra icon
                            // attributes (unlike ImageColumn's
                            // extraImgAttributes()) - Filament's own
                            // generate_icon_html() puts the "fi-icon" class
                            // directly on the rendered <svg>, so scoping the
                            // spin animation through this wrapper class and
                            // a descendant selector (like the existing
                            // .mcloader-badge rule below) reaches it without
                            // needing that hook.
                            ->extraAttributes(fn () => $this->installedOperationIsActive()
                                ? ['class' => 'mmr-installed-operation-spinning']
                                : []),
                    ])
                    // Bulk-update progress remains useful in the page body.
                    // A scan uses this same position, but only while the
                    // Installed tab is open (including its brief success
                    // outcome); catalog tabs stay free of scan state.
                    ->visible(fn () => $this->shouldShowInstalledOperationStatus()),
                Group::make([
                    EmbeddedTable::make(),
                ])->extraAttributes(fn () => array_merge([
                    'class' => 'mmr-table-scroll-ctn',
                    'data-mmr-swr-scope' => json_encode([
                        'user_id' => (string) user()->getKey(),
                        'server_id' => (string) $server->getKey(),
                        // Same as the 'installed' TextEntry's label above:
                        // $type is non-null for the rest of this method.
                        'project_type' => $type->value,
                    ], JSON_THROW_ON_ERROR),
                    // This class is what the external stylesheet's flex layout
                    // and table-layout.js both hang off, so the table
                    // fills the remaining viewport and the paginator stays
                    // put. Deliberately nothing is queued from PHP for it:
                    // the space above the table depends on the topbar, the
                    // sidebar mode and this page's own header wrapping, none
                    // of which change when a table updates, and re-measuring
                    // per update is what previously coupled the layout to
                    // render timing. A ResizeObserver covers the cases that
                    // do change it.
                ], $this->tablePollingAttributes())),
            ]);
    }

    /** @return array<string, string> */
    protected function tablePollingAttributes(): array
    {
        if ($this->pollInstalledOperations) {
            // Operation progress wins while both activities are pending. Its
            // terminal render flushes table records, after which the existing
            // enrichment poll resumes if background fills are still pending.
            return ['wire:poll.2s' => 'pollInstalledOperation'];
        }

        if ($this->pollEnrichment) {
            return ['wire:poll.5s' => 'pollEnrichment'];
        }

        return [];
    }

    /**
     * Stage 8's GUI fallback: rendered instead of the normal catalog/
     * Installed content when EggProfileResolver could not place this
     * server's egg automatically but judged it plausibly Minecraft-related
     * (see needsManualEggSetup()/canAccess()). Whoever can edit gets an
     * inline form scoped to just this server's egg (egg_id is fixed, not
     * user-selectable - contrast the admin settings screen's version of
     * this same schema, which lets an admin pick any egg); everyone else
     * sees a read-only notice, with a link to the settings screen for an
     * admin whose edit check itself failed (see canEditEggProfile()).
     */
    protected function eggManualSetupContent(Schema $schema, Server $server): Schema
    {
        $canEdit = $this->canEditEggProfile($server);
        $isAdmin = (bool) user()?->isAdmin();

        $noticeEntries = [
            TextEntry::make('egg_manual_setup_notice')
                ->hiddenLabel()
                ->state(trans('pelican-mod-manager::strings.page.egg_manual_setup_heading').' — '.trans('pelican-mod-manager::strings.page.egg_manual_setup_description'))
                ->icon('tabler-alert-triangle')
                ->color('warning'),
        ];

        if (!$canEdit && !$isAdmin) {
            $noticeEntries[] = TextEntry::make('egg_manual_setup_readonly')
                ->hiddenLabel()
                ->state(trans('pelican-mod-manager::strings.page.egg_manual_setup_readonly'))
                ->color('gray');
        }

        $actions = [];

        if ($canEdit) {
            $actions[] = Action::make('configure_egg_profile')
                ->label(trans('pelican-mod-manager::strings.settings.egg_profiles'))
                ->color('primary')
                ->icon('tabler-egg')
                ->modalHeading(trans('pelican-mod-manager::strings.settings.egg_profiles_confirmation_heading'))
                ->modalDescription(trans('pelican-mod-manager::strings.page.egg_manual_setup_form_warning'))
                ->schema(ModManagerPlugin::eggProfileFormSchema(includeEggSelect: false))
                ->fillForm(function () use ($server): array {
                    $server->loadMissing('egg');

                    return ModManagerPlugin::eggProfileDefaults($server->egg);
                })
                ->action(function (array $data) use ($server): void {
                    $server->loadMissing('egg');

                    if ($server->egg === null) {
                        return;
                    }

                    $data['egg_id'] = $server->egg->getKey();
                    ModManagerPlugin::saveEggProfile($data);

                    // The resolver memoizes per (server, egg) for the life
                    // of this request - without clearing it, the very next
                    // read (this same Livewire action's re-render) would
                    // still see the pre-save result.
                    EggProfileResolver::clear();
                });
        } elseif ($isAdmin) {
            // canEdit failed for an admin only when the toggle is on and
            // this specific server falls outside their node access (see
            // canEditEggProfile()) - the settings screen's own version of
            // this form isn't server-scoped, so it works regardless.
            $actions[] = Action::make('goto_egg_settings')
                ->label(trans('pelican-mod-manager::strings.page.egg_manual_setup_admin_action'))
                ->color('gray')
                ->icon('tabler-settings')
                ->url(fn () => PluginResource::getUrl('index', panel: 'admin'));
        }

        return $schema->components([
            Section::make()
                ->schema([
                    ...$noticeEntries,
                    ...($actions ? [Actions::make($actions)] : []),
                ]),
        ]);
    }

    protected function canEditEggProfile(Server $server): bool
    {
        if (app(ServerModManagerSettings::class)->allowsEggProfileEdit($server)) {
            return (bool) user()?->can(SubuserPermission::StartupUpdate, $server);
        }

        return (bool) user()?->isAdmin();
    }

    /**
     * Stage 8 diagnostic (see the loader TextEntry's tooltip in content()).
     * Checked independently of EggProfileResolver's own memoized result so
     * this never triggers a profile-database/manual-table lookup for the
     * (overwhelmingly common) case where the egg's own explicit features/
     * tags already answered the question - matching ProjectType::
     * fromServer()'s/MinecraftLoader::fromServer()'s own explicit-first
     * short-circuit exactly.
     */
    protected function eggResolutionSourceLabel(Server $server): string
    {
        $server->loadMissing('egg');

        if (ProjectType::fromServerExplicit($server) !== null || MinecraftLoader::fromTags($server->egg->tags ?? []) !== null) {
            return trans('pelican-mod-manager::strings.page.resolved_by_explicit');
        }

        if (!(bool) config('pelican-mod-manager.egg_autodetect_enabled', true)) {
            return trans('pelican-mod-manager::strings.page.resolved_by_none');
        }

        $source = EggProfileResolver::resolve($server)->source;

        return trans('pelican-mod-manager::strings.page.resolved_by_'.$source);
    }

    protected function getRescanActionLabel(?ProjectType $type): string
    {
        return trans(match ($type) {
            ProjectType::Plugin => 'pelican-mod-manager::strings.actions.rescan_plugins_for_updates',
            ProjectType::Datapack => 'pelican-mod-manager::strings.actions.rescan_datapacks_for_updates',
            default => 'pelican-mod-manager::strings.actions.rescan_mods_for_updates',
        });
    }

    protected function getInstalledOperationFingerprint(InstalledOperationState $state): string
    {
        return $state->operation.':'.($state->finishedAt ?? '');
    }

    protected function markInstalledOperationHandled(InstalledOperationState $state): void
    {
        $this->handledInstalledOperation = $this->getInstalledOperationFingerprint($state);
        $this->pollInstalledOperations = false;
    }

    protected function forgetTerminalInstalledOperation(InstalledOperationState $state): void
    {
        if (!$state->isFinished()) {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();
        app(InstalledOperationManager::class)->forget(
            $server,
            $state->projectType,
            $state->operation,
        );
    }

    protected function getInstalledScanFingerprint(InstalledOperationState $state): string
    {
        return implode(':', [
            $state->operation,
            $state->serverId,
            $state->projectType->value,
            $state->queuedAt,
        ]);
    }

    protected function setInstalledOperationState(?InstalledOperationState $state): void
    {
        $this->installedOperation = $state?->toCachePayload();
        $this->pollInstalledOperations = $this->shouldPollInstalledOperation($state);

        if ($state?->operation !== InstalledOperationManager::OPERATION_SCAN || !$state->isActive()) {
            return;
        }

        // A new scan supersedes any prior short-lived success outcome. Only
        // remember it when Installed is actually open; a catalog visitor
        // should never inherit scan UI after changing tabs.
        $this->installedScanCompletion = null;
        $this->observedInstalledScan = $this->activeTab === 'installed'
            ? $this->getInstalledScanFingerprint($state)
            : null;
    }

    protected function rememberInstalledScanCompletion(InstalledOperationState $state): void
    {
        if ($state->operation !== InstalledOperationManager::OPERATION_SCAN
            || $state->status !== InstalledOperationState::STATUS_COMPLETED
            || $this->activeTab !== 'installed'
            || $this->observedInstalledScan !== $this->getInstalledScanFingerprint($state)) {
            return;
        }

        $this->installedScanCompletion = $state->toCachePayload();
    }

    public function pollInstalledOperation(): void
    {
        $previousOperation = $this->installedOperation;
        $state = $this->refreshInstalledOperationState();

        if ($state === null) {
            // Another browser may have handled the terminal state first and
            // removed it from the shared operation cache. The durable scan
            // result is still the source of truth for this tab's count badge.
            $this->refreshInstalledScanDataReady();

            if ($previousOperation !== null) {
                $this->forgetInstalledModsMetadata();
                $this->forgetVersionCaches();
                $this->flushCachedTableRecords();
            }

            $this->pollInstalledOperations = false;

            return;
        }

        if (!$state->isFinished()) {
            $this->pollInstalledOperations = true;

            // Avoid rebuilding the complete component (including catalog cards
            // and image nodes) when this poll cannot change visible state.
            // shouldSkipInstalledOperationPollRender() retains normal renders
            // for Installed scan progress, bulk progress, and completion.
            if ($this->shouldSkipInstalledOperationPollRender($previousOperation, $state)) {
                $this->skipRender();
            }

            return;
        }

        $fingerprint = $this->getInstalledOperationFingerprint($state);
        if ($this->handledInstalledOperation === $fingerprint) {
            return;
        }

        $this->markInstalledOperationHandled($state);
        $this->rememberInstalledScanCompletion($state);
        // Catalog records do not read the Installed scan cache themselves.
        // Refresh it explicitly so the header badge changes in this same
        // Livewire poll response, without requiring a page reload or an
        // Installed-tab visit.
        $this->refreshInstalledScanDataReady();
        $this->forgetInstalledModsMetadata();
        $this->forgetVersionCaches();
        // A poll response re-renders the component already. Invalidate only
        // the records cache so the table sees the new scan data while keeping
        // the active page, search term, and catalog filters intact.
        $this->flushCachedTableRecords();
        $this->notifyInstalledOperationFinished($state);

        $this->forgetTerminalInstalledOperation($state);
    }

    /** @param array<string, mixed>|null $previousOperation */
    protected function shouldSkipInstalledOperationPollRender(?array $previousOperation, InstalledOperationState $state): bool
    {
        // Scan progress is intentionally not shown outside Installed. While a
        // catalog is open, intermediate queued/running updates therefore do
        // not need to rebuild the catalog table or its image DOM. A terminal
        // state still renders below so the count badge and cache refresh are
        // applied immediately.
        if ($state->isActive()
            && $state->operation === InstalledOperationManager::OPERATION_SCAN
            && $this->activeTab !== 'installed') {
            return true;
        }

        return $state->isActive() && $this->installedOperation === $previousOperation;
    }

    /**
     * Invalidate the table records so a background enrichment fill (project
     * metadata or a latest-version lookup queued by SourceCache::swrDeferred())
     * that landed since the last render becomes visible. The poll request
     * itself re-renders the component; unlike resetTable(), this preserves
     * the Installed table's current page, search, and filters.
     *
     * When the peeked payload is unchanged, skip the remorph so in-flight
     * icons are not decoded again while the fill is still pending.
     */
    public function pollEnrichment(): void
    {
        if ($this->activeTab !== 'installed') {
            if (!$this->pollEnrichment) {
                $this->installedEnrichmentSignature = null;
                $this->catalogEnrichmentPeekKeys = [];

                return;
            }

            $signature = $this->peekCatalogEnrichmentSignature();
            if ($signature !== null && $signature === $this->installedEnrichmentSignature) {
                $this->skipRender();

                return;
            }

            $this->installedEnrichmentSignature = $signature;
            $this->flushCachedTableRecords();

            return;
        }

        $signature = $this->peekInstalledEnrichmentSignature();
        if ($this->pollEnrichment
            && $signature !== null
            && $signature === $this->installedEnrichmentSignature) {
            $this->skipRender();

            return;
        }

        $this->installedEnrichmentSignature = $signature;
        $this->flushCachedTableRecords();
    }

    protected function refreshInstalledOperationState(
        ?InstalledOperationState $scanState = null,
        ?InstalledOperationState $bulkState = null,
        bool $preloaded = false,
    ): ?InstalledOperationState {
        /** @var Server $server */
        $server = Filament::getTenant();
        $type = static::detectProjectType($server);

        if (!$type) {
            $this->installedOperation = null;
            $this->pollInstalledOperations = false;

            return null;
        }

        $operations = app(InstalledOperationManager::class);

        if (!$preloaded) {
            $fetched = $operations->states($server, $type, [
                InstalledOperationManager::OPERATION_SCAN,
                InstalledOperationManager::OPERATION_BULK_UPDATE,
            ]);
            $scanState = $fetched[InstalledOperationManager::OPERATION_SCAN];
            $bulkState = $fetched[InstalledOperationManager::OPERATION_BULK_UPDATE];
        }

        // Scan and bulk update are mutually exclusive. The common active
        // scan path therefore needs only the already-fetched scan state.
        if ($scanState?->isActive()) {
            $this->setInstalledOperationState($scanState);

            return $scanState;
        }

        $states = array_values(array_filter([
            $scanState,
            $bulkState,
        ]));

        usort($states, function (InstalledOperationState $left, InstalledOperationState $right): int {
            if ($left->isActive() !== $right->isActive()) {
                return $left->isActive() ? -1 : 1;
            }

            return strcmp(
                $right->finishedAt ?? $right->startedAt ?? $right->queuedAt,
                $left->finishedAt ?? $left->startedAt ?? $left->queuedAt,
            );
        });

        $state = $states[0] ?? null;
        $this->setInstalledOperationState($state);

        return $state;
    }

    /**
     * Scan progress is inline only while Installed is open. Bulk-update
     * progress intentionally keeps its existing page-level visibility.
     */
    protected function shouldShowInstalledOperationStatus(): bool
    {
        if (($this->installedOperation['operation'] ?? null) === InstalledOperationManager::OPERATION_BULK_UPDATE) {
            return in_array($this->installedOperation['status'] ?? null, [
                InstalledOperationState::STATUS_QUEUED,
                InstalledOperationState::STATUS_RUNNING,
            ], true);
        }

        if ($this->activeTab !== 'installed') {
            return false;
        }

        $operation = $this->getInstalledOperationDisplayPayload();

        if (($operation['operation'] ?? null) !== InstalledOperationManager::OPERATION_SCAN) {
            return false;
        }

        $status = $operation['status'] ?? null;

        return in_array($status, [
            InstalledOperationState::STATUS_QUEUED,
            InstalledOperationState::STATUS_RUNNING,
        ], true)
            || ($status === InstalledOperationState::STATUS_COMPLETED
                && $this->isInstalledScanCompletionVisible());
    }

    /** @return array<string, mixed>|null */
    protected function getInstalledOperationDisplayPayload(): ?array
    {
        return $this->isInstalledScanCompletionVisible()
            ? $this->installedScanCompletion
            : $this->installedOperation;
    }

    protected function isInstalledScanCompletionVisible(): bool
    {
        $completion = $this->installedScanCompletion;
        $finishedAt = $completion['finished_at'] ?? null;

        if ($this->activeTab !== 'installed'
            || ($completion['operation'] ?? null) !== InstalledOperationManager::OPERATION_SCAN
            || ($completion['status'] ?? null) !== InstalledOperationState::STATUS_COMPLETED
            || !is_string($finishedAt)) {
            return false;
        }

        try {
            return Carbon::parse($finishedAt)
                ->addSeconds(self::INSTALLED_SCAN_COMPLETION_VISIBLE_SECONDS)
                ->isFuture();
        } catch (Exception) {
            return false;
        }
    }

    /** @return array<string, string> */
    protected function installedOperationStatusExtraAttributes(): array
    {
        if (!$this->isInstalledScanCompletionVisible()) {
            return [];
        }

        try {
            $finishedAt = Carbon::parse($this->installedScanCompletion['finished_at']);
        } catch (Exception) {
            return [];
        }

        // The deadline is absolute, not "now + five seconds". Livewire can
        // re-render during the outcome window, but that must not reset its
        // timer and make the completion badge stick around indefinitely.
        $deadline = $finishedAt
            ->addSeconds(self::INSTALLED_SCAN_COMPLETION_VISIBLE_SECONDS)
            ->getTimestamp() * 1000;

        return [
            'x-data' => '{}',
            'x-init' => 'const remaining = Math.max(0, '.$deadline.' - Date.now()); if (remaining === 0) { $el.remove(); } else { setTimeout(() => $el.remove(), remaining); }',
        ];
    }

    /**
     * Whether the status badge's loader icon should spin - true while
     * something is queued, running, or about to be dispatched, false for
     * the terminal completed/failed states and the static queue-config
     * warning (all of which mean nothing is actually in progress anymore).
     */
    protected function installedOperationIsActive(): bool
    {
        $status = $this->getInstalledOperationDisplayPayload()['status'] ?? null;

        if (in_array($status, [InstalledOperationState::STATUS_COMPLETED, InstalledOperationState::STATUS_FAILED], true)) {
            return false;
        }

        return !($status === null && $this->operationQueueWarningShown);
    }

    protected function shouldPollInstalledOperation(?InstalledOperationState $state): bool
    {
        if ($state === null) {
            // A valid scan result already covers this case, so there is
            // nothing to poll for. Without this, a caller like mount() that
            // already knows installedScanDataReady is true would still enable
            // polling here until the next request corrected it.
            return $this->activeTab === 'installed'
                && !$this->operationQueueWarningShown
                && !$this->installedScanDataReady;
        }

        if ($state->isActive()) {
            return true;
        }

        return $this->handledInstalledOperation !== $state->operation.':'.($state->finishedAt ?? '');
    }

    protected function installedOperationStatus(): string
    {
        $installedOperation = $this->getInstalledOperationDisplayPayload();

        if ($installedOperation === null) {
            return trans($this->operationQueueWarningShown
                ? 'pelican-mod-manager::strings.operations.queue_required'
                : 'pelican-mod-manager::strings.operations.checking');
        }

        $operation = trans(
            $installedOperation['operation'] === InstalledOperationManager::OPERATION_BULK_UPDATE
                ? 'pelican-mod-manager::strings.operations.bulk_update'
                : 'pelican-mod-manager::strings.operations.scan',
        );
        $status = $installedOperation['status'] ?? null;
        $progress = (int) ($installedOperation['progress'] ?? 0);
        $total = $installedOperation['total'] ?? null;

        return match ($status) {
            InstalledOperationState::STATUS_QUEUED => trans('pelican-mod-manager::strings.operations.queued', compact('operation')),
            InstalledOperationState::STATUS_RUNNING => is_int($total) && $total > 0
                ? trans('pelican-mod-manager::strings.operations.running_progress', compact('operation', 'progress', 'total'))
                : trans('pelican-mod-manager::strings.operations.running', compact('operation')),
            InstalledOperationState::STATUS_COMPLETED => trans('pelican-mod-manager::strings.operations.completed', compact('operation')),
            InstalledOperationState::STATUS_FAILED => trans('pelican-mod-manager::strings.operations.failed', compact('operation')),
            default => '',
        };
    }

    protected function notifyInstalledOperationFinished(InstalledOperationState $state): void
    {
        if ($state->status === InstalledOperationState::STATUS_FAILED) {
            Notification::make()
                ->title(trans('pelican-mod-manager::strings.operations.failed', [
                    'operation' => trans(
                        $state->operation === InstalledOperationManager::OPERATION_BULK_UPDATE
                            ? 'pelican-mod-manager::strings.operations.bulk_update'
                            : 'pelican-mod-manager::strings.operations.scan',
                    ),
                ]))
                ->danger()
                ->send();

            return;
        }

        if ($state->operation === InstalledOperationManager::OPERATION_BULK_UPDATE) {
            $updated = (int) ($state->result['updated'] ?? 0);
            $failed = (int) ($state->result['failed'] ?? 0);

            if ($updated === 0 && $failed === 0) {
                Notification::make()
                    ->title(trans('pelican-mod-manager::strings.notifications.bulk_update_none'))
                    ->info()
                    ->send();

                return;
            }

            $notification = Notification::make()
                ->title($failed > 0
                    ? trans('pelican-mod-manager::strings.notifications.bulk_update_partial', [
                        'updated' => $updated,
                        'failed' => $failed,
                    ])
                    : trans('pelican-mod-manager::strings.notifications.bulk_update_success', ['count' => $updated]));

            if ($failed > 0) {
                $notification->warning();
            } else {
                $notification->success();
            }

            $notification->send();

            return;
        }

        // Successful scans are deliberately represented by the short-lived
        // Installed-tab status instead of a global Filament notification.
    }

    /**
     * @param  array{dispatched: bool, reason: ?string, state: ?InstalledOperationState}  $dispatch
     */
    protected function notifyInstalledOperationDispatched(array $dispatch): void
    {
        $state = $dispatch['state'];
        if ($state !== null) {
            $this->setInstalledOperationState($state);
        }

        if ($dispatch['dispatched']) {
            if ($state !== null && $state->operation === InstalledOperationManager::OPERATION_SCAN) {
                // Scan progress belongs in the Installed tab, not in a
                // global notification. The tab condition is enforced by
                // shouldShowInstalledOperationStatus().
                return;
            }

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.operations.dispatched'))
                ->info()
                ->send();

            return;
        }

        $reason = $dispatch['reason'];

        if ($reason === 'sync_queue') {
            $this->operationQueueWarningShown = true;
            $this->pollInstalledOperations = false;
        }

        // dispatchScan()/dispatchBulkUpdate() persist a terminal failed
        // state when their dispatcher throws. This method is already showing
        // the immediate dispatch error, so do not let the next two-second
        // poll show a second, generic operation-failed notification.
        if ($reason === 'dispatch_failed' && $state !== null) {
            $this->markInstalledOperationHandled($state);
            $this->forgetTerminalInstalledOperation($state);
        }

        $title = match ($reason) {
            'already_active' => trans('pelican-mod-manager::strings.operations.already_active'),
            'sync_queue' => trans('pelican-mod-manager::strings.operations.queue_required'),
            default => trans('pelican-mod-manager::strings.operations.dispatch_failed'),
        };

        $notification = Notification::make()
            ->title($title);

        if ($reason === 'already_active') {
            $notification->warning();
        } else {
            $notification->danger();
        }

        $notification->send();
    }
}
