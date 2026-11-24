<?php

namespace Kazaminosuke\ModManager\Filament\Components;

use App\Models\Server;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\ArrayRecord;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Filament\Actions\CatalogRowAction;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Support\CatalogFields;
use Kazaminosuke\ModManager\Support\CatalogTileColumns;
use Kazaminosuke\ModManager\Support\ProjectIconUrl;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Filament-native modpack catalog table shared by server creation and
 * existing-server modpack selection.
 *
 * Reuses the exact same Filament Table layout, toolbar search/filters,
 * table-body scrolling, tile grid toggle, versions modal, and download action
 * patterns as ModManagerPage.
 */
final class ModpackCatalogTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public const MODE_CREATE = 'create';

    public const MODE_SERVER = 'server';

    public string $mode = self::MODE_CREATE;

    public string $provider = ModpackProvider::Modrinth->value;

    public ?int $serverId = null;

    public string $targetStatePath = '';

    public ?string $titleStatePath = null;

    public ?string $versionStatePath = null;

    public string $catalogView = CatalogTileColumns::VIEW_LIST;

    #[Reactive]
    public ?string $selectedKey = null;

    #[Reactive]
    public ?string $selectedVersion = null;

    private bool $lastSearchErrored = false;

    /** @var array<string, int>|null */
    private ?array $rowIndexMap = null;

    /** @var array<string, ?Carbon> */
    private array $dateMemo = [];

    public function mount(
        string $mode = self::MODE_CREATE,
        string $provider = ModpackProvider::Modrinth->value,
        string $query = '',
        ?string $minecraft = null,
        ?string $loader = null,
        ?int $serverId = null,
        ?string $selectedKey = null,
        ?string $selectedVersion = null,
        string $targetStatePath = '',
        ?string $titleStatePath = null,
        ?string $versionStatePath = null,
    ): void {
        $this->mode = $mode === self::MODE_SERVER ? self::MODE_SERVER : self::MODE_CREATE;
        $available = $this->providerTabs();
        $resolvedProvider = ModpackProvider::tryFrom($provider)?->value;
        $this->provider = ($resolvedProvider !== null && array_key_exists($resolvedProvider, $available))
            ? $resolvedProvider
            : (string) (array_key_first($available) ?? ModpackProvider::Modrinth->value);
        $this->serverId = $serverId;
        $this->selectedKey = $selectedKey;
        $this->selectedVersion = $selectedVersion;
        $this->targetStatePath = $targetStatePath;
        $this->titleStatePath = $titleStatePath;
        $this->versionStatePath = $versionStatePath;
        $this->catalogView = CatalogTileColumns::resolveInitialView();

        if ($query !== '') {
            $this->tableSearch = $query;
        }

        if (ModpackCatalog::supportsCatalogFilters($this->provider)) {
            $filters = [];
            if (is_string($minecraft) && $minecraft !== '') {
                $filters['catalog_version'] = ['value' => $minecraft];
            }
            if (is_string($loader) && $loader !== '') {
                $filters['catalog_loader'] = ['value' => $loader];
            }
            if ($filters !== []) {
                $this->tableFilters = $filters;
            }
        }
    }

    /** @return array<string, string> */
    public function providerTabs(): array
    {
        $catalog = app(ModpackCatalog::class);

        return $this->mode === self::MODE_SERVER
            ? $catalog->archiveProviders()
            : $catalog->providers();
    }

    public function setProvider(string $provider): void
    {
        $available = $this->providerTabs();
        if (!array_key_exists($provider, $available) || $this->provider === $provider) {
            return;
        }

        $this->provider = $provider;
        $this->tableFilters = [];
        $this->resetTable();
    }

    public function toggleCatalogView(): void
    {
        $this->catalogView = $this->catalogView === CatalogTileColumns::VIEW_PANEL
            ? CatalogTileColumns::VIEW_LIST
            : CatalogTileColumns::VIEW_PANEL;

        session()->put(CatalogTileColumns::SESSION_KEY, $this->catalogView);
        Cookie::queue(CatalogTileColumns::COOKIE_KEY, $this->catalogView, 525600);
        $this->table = $this->table($this->makeTable());
        $this->flushCachedTableRecords();
    }

    public function table(Table $table): Table
    {
        $isPanelView = $this->catalogView === CatalogTileColumns::VIEW_PANEL;

        $table = $table
            ->records(function (?string $search, int $page): LengthAwarePaginator {
                $this->rowIndexMap = null;

                $provider = ModpackProvider::tryFrom($this->provider) ?? ModpackProvider::Modrinth;
                $hasFilters = ModpackCatalog::supportsCatalogFilters($provider);
                $minecraft = $hasFilters ? $this->selectedMinecraftFilter() : null;
                $loader = $hasFilters ? $this->selectedLoaderFilter() : null;

                $result = $this->searchPage($provider, (string) ($search ?? ''), $page, $minecraft, $loader);
                $this->lastSearchErrored = $result['error'];

                $keyName = ArrayRecord::getKeyName();
                $hits = [];
                foreach ($result['hits'] as $hit) {
                    $recordKey = (string) ($hit['key'] ?? '');
                    if ($recordKey === '') {
                        continue;
                    }
                    $hit[$keyName] = $recordKey;
                    $hits[$recordKey] = $hit;
                }

                return new LengthAwarePaginator(
                    $hits,
                    $result['total_hits'],
                    ModpackCatalog::PAGE_SIZE,
                    $page,
                );
            })
            ->deferLoading(false)
            ->searchable()
            ->searchPlaceholder(trans('pelican-mod-manager::strings.modpacks.catalog_search'))
            ->paginated([ModpackCatalog::PAGE_SIZE])
            ->extraAttributes(['class' => 'fi-fixed-positioning-context'])
            ->filtersFormWidth(Width::Large)
            ->filtersFormColumns(['default' => 1, 'md' => 2])
            ->filtersFormMaxHeight('calc(100dvh - 8rem)')
            ->filtersTriggerAction(fn (Action $action): Action => $action->extraAttributes(
                fn (): array => [
                    'data-mmr-active-filters' => (string) $this->getTable()->getActiveFiltersCount(),
                ],
            ))
            ->filters([
                SelectFilter::make('catalog_version')
                    ->label(trans('pelican-mod-manager::strings.table.filters.minecraft_version'))
                    ->options(fn (): array => $this->minecraftVersionFilterOptions())
                    ->placeholder(trans('pelican-mod-manager::strings.table.filters.all'))
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => ModpackCatalog::supportsCatalogFilters($this->provider)),
                SelectFilter::make('catalog_loader')
                    ->label(trans('pelican-mod-manager::strings.table.filters.loader'))
                    ->options(fn (): array => app(ModpackCatalog::class)->loaders())
                    ->placeholder(trans('pelican-mod-manager::strings.table.filters.all'))
                    ->visible(fn (): bool => ModpackCatalog::supportsCatalogFilters($this->provider)),
            ])
            ->emptyStateIcon('tabler-packages')
            ->emptyStateHeading(fn (): string => $this->emptyStateMessage())
            ->emptyStateDescription(null)
            ->columns($isPanelView
                ? CatalogTileColumns::columns(
                    iconImgAttributes: fn (array $record): array => ProjectIconUrl::imgAttributes($this->rowIndex($record)),
                    formatDate: fn (mixed $state): string => $this->formatDate($state),
                    dateTooltip: fn (mixed $state): string => $this->formatDateTooltip($state, $table),
                    authorUrl: fn (array $record, mixed $state): ?string => $this->authorUrl($record, $state),
                )
                : $this->listColumns($table))
            ->recordUrl(fn (array $record): ?string => is_string($record['url'] ?? null) && $record['url'] !== '' ? $record['url'] : null, true)
            ->recordClasses(fn (array $record): ?string => $this->isSelected($record) ? 'fi-striped' : null)
            ->recordActions([
                CatalogRowAction::compact('versions', 'info')
                    ->tooltip(trans('pelican-mod-manager::strings.actions.versions'))
                    ->modalHeading(fn (array $record): string => (string) ($record['title'] ?? trans('pelican-mod-manager::strings.actions.versions')))
                    ->modalSubmitAction(false)
                    ->schema(fn (array $record): array => $this->versionSections($record)),
                CatalogRowAction::compact('install_latest', 'success')
                    ->tooltip(trans('pelican-mod-manager::strings.actions.install_latest'))
                    ->action(function (array $record): void {
                        $selection = (string) ($record['key'] ?? '');
                        $versions = app(ModpackCatalog::class)->versionList($selection);
                        $latest = $versions[0] ?? null;

                        if ($latest === null) {
                            Notification::make()
                                ->title(trans('pelican-mod-manager::strings.modpacks.plan_unavailable'))
                                ->warning()
                                ->send();

                            return;
                        }

                        $this->commitRelease($record, $latest['id']);
                    }),
            ]);

        if ($isPanelView) {
            $table = $table->contentGrid(CatalogTileColumns::contentGrid());
        }

        return $table;
    }

    public function render(): View
    {
        return view('pelican-mod-manager::components.modpack-catalog-table');
    }

    /**
     * @return list<Column>
     */
    private function listColumns(Table $table): array
    {
        return [
            ImageColumn::make('icon_url')
                ->label('')
                ->defaultImageUrl(ProjectIconUrl::placeholderDataUri())
                ->alignCenter()
                ->extraCellAttributes(['class' => 'mmr-project-icon-cell'])
                ->extraImgAttributes(fn (array $record): array => ProjectIconUrl::imgAttributes($this->rowIndex($record))),
            TextColumn::make('title')
                ->label(trans('pelican-mod-manager::strings.table.columns.title'))
                ->searchable()
                ->wrap()
                ->lineClamp(1)
                ->description(function (array $record): ?string {
                    $description = $record['description'] ?? null;
                    if (!is_string($description) || trim($description) === '') {
                        return null;
                    }

                    return Str::limit($description, CatalogFields::DESCRIPTION_MAX_LENGTH, '...');
                }),
            TextColumn::make('minecraft_versions')
                ->label(trans('pelican-mod-manager::strings.table.filters.minecraft_version'))
                ->state(fn (array $record): ?string => $this->formatMinecraftVersions($record['minecraft_versions'] ?? []))
                ->placeholder('—')
                ->wrap()
                ->toggleable(),
            TextColumn::make('loaders')
                ->label(trans('pelican-mod-manager::strings.table.filters.loader'))
                ->state(fn (array $record): ?string => $this->formatLoaders($record['loaders'] ?? []))
                ->placeholder('—')
                ->wrap()
                ->toggleable(),
            TextColumn::make('author')
                ->label(trans('pelican-mod-manager::strings.table.columns.author'))
                ->url(fn (array $record, mixed $state): ?string => $this->authorUrl($record, $state), true)
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('downloads')
                ->label(trans('pelican-mod-manager::strings.table.columns.downloads'))
                ->numeric()
                ->prefix(new HtmlString('<span class="mmr-stat-icon" data-mmr-stat-icon="downloads" aria-hidden="true"></span>'))
                ->toggleable(),
            TextColumn::make('date_modified')
                ->label(trans('pelican-mod-manager::strings.table.columns.date_modified'))
                ->prefix(new HtmlString('<span class="mmr-stat-icon" data-mmr-stat-icon="calendar" aria-hidden="true"></span>'))
                ->formatStateUsing(fn (mixed $state): string => $this->formatDate($state))
                ->tooltip(fn (mixed $state): string => $this->formatDateTooltip($state, $table))
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<Section>
     */
    private function versionSections(array $record): array
    {
        $selection = (string) ($record['key'] ?? '');
        $versions = app(ModpackCatalog::class)->versionList($selection);
        $sections = [];

        foreach ($versions as $index => $version) {
            $components = [];

            if ($version['version_type'] !== null) {
                $components[] = TextEntry::make('type_'.$index)
                    ->label(trans('pelican-mod-manager::strings.version.type'))
                    ->state($version['version_type'])
                    ->badge()
                    ->color(match ($version['version_type']) {
                        'release' => 'success',
                        'beta' => 'warning',
                        'alpha' => 'danger',
                        default => 'gray',
                    });
            }

            if ($version['minecraft'] !== null) {
                $components[] = TextEntry::make('minecraft_'.$index)
                    ->label(trans('pelican-mod-manager::strings.table.filters.minecraft_version'))
                    ->state($version['minecraft']);
            }

            if ($version['loader'] !== null) {
                $components[] = TextEntry::make('loader_'.$index)
                    ->label(trans('pelican-mod-manager::strings.table.filters.loader'))
                    ->state(MinecraftLoader::tryFrom($version['loader'])?->getLabel() ?? ucfirst($version['loader']));
            }

            if ($version['downloads'] !== null) {
                $components[] = TextEntry::make('downloads_'.$index)
                    ->label(trans('pelican-mod-manager::strings.version.downloads'))
                    ->state($version['downloads'])
                    ->icon('tabler-download')
                    ->numeric();
            }

            if ($version['date_published'] !== null) {
                $components[] = TextEntry::make('published_'.$index)
                    ->label(trans('pelican-mod-manager::strings.version.published'))
                    ->state($this->formatDate($version['date_published']));
            }

            $isSelectedVersion = $this->isSelected($record) && $this->selectedVersion === $version['id'];

            $headerAction = $isSelectedVersion
                ? Action::make('selected_version_'.$index)
                    ->label(trans('pelican-mod-manager::strings.modpacks.pack_selected'))
                    ->icon('tabler-check')
                    ->color('success')
                    ->disabled()
                : Action::make('install_version_'.$index)
                    ->label(trans('pelican-mod-manager::strings.actions.install'))
                    ->icon('tabler-download')
                    ->action(function () use ($record, $version): void {
                        $this->commitRelease($record, $version['id']);
                    })
                    ->cancelParentActions();

            $section = Section::make($version['label'])
                ->headerActions([$headerAction])
                ->schema($components)
                ->collapsible()
                ->collapsed(!$version['featured']);

            if ($isSelectedVersion) {
                $section = $section->icon('tabler-check')->iconColor('success');
            }

            $sections[] = $section;
        }

        return $sections;
    }

    /** @param array<string, mixed> $record */
    private function commitRelease(array $record, string $versionId): void
    {
        $key = (string) ($record['key'] ?? '');
        $title = (string) ($record['title'] ?? '');

        if ($key === '' || $versionId === '') {
            return;
        }

        if ($this->mode === self::MODE_SERVER) {
            $this->js('$wire.$parent.selectModpackRelease('.Js::from($key).', '.Js::from($title).', '.Js::from($versionId).')');

            return;
        }

        $steps = [];
        if (is_string($this->titleStatePath) && $this->titleStatePath !== '') {
            $steps[] = 'await $wire.$parent.$set('.Js::from($this->titleStatePath).', '.Js::from($title).', false);';
        }
        $steps[] = 'await $wire.$parent.$set('.Js::from($this->targetStatePath).', '.Js::from($key).');';
        if (is_string($this->versionStatePath) && $this->versionStatePath !== '') {
            $steps[] = 'await $wire.$parent.$set('.Js::from($this->versionStatePath).', '.Js::from($versionId).');';
        }
        $steps[] = "const wizard = \$wire.\$el.closest('.fi-sc-wizard'); if (wizard && window.Alpine) { window.Alpine.\$data(wizard)?.requestNextStep?.(); }";

        $this->js('(async () => { '.implode(' ', $steps).' })()');
    }

    /** @return array<string, string> */
    private function minecraftVersionFilterOptions(): array
    {
        $versions = app(ModpackCatalog::class)->minecraftVersions();
        if ($versions === []) {
            return [];
        }

        return [CatalogFields::VERSION_ALL => trans('pelican-mod-manager::strings.table.filters.all')] + $versions;
    }

    private function selectedMinecraftFilter(): ?string
    {
        $value = $this->tableFilters['catalog_version']['value'] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '' || $trimmed === CatalogFields::VERSION_ALL) {
            return null;
        }

        return $trimmed;
    }

    private function selectedLoaderFilter(): ?string
    {
        $value = $this->tableFilters['catalog_loader']['value'] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function formatMinecraftVersions(mixed $raw): ?string
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $versions = array_values(array_filter($raw, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
        if ($versions === []) {
            return null;
        }

        $visible = array_slice($versions, 0, 2);
        $remaining = count($versions) - count($visible);
        $text = implode(', ', $visible);

        return $remaining > 0 ? $text.' (+'.$remaining.')' : $text;
    }

    private function formatLoaders(mixed $raw): ?string
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $labels = [];
        foreach ($raw as $item) {
            if (!is_string($item) || trim($item) === '') {
                continue;
            }
            $slug = strtolower(trim($item));
            $labels[] = MinecraftLoader::tryFrom($slug)?->getLabel() ?? ucfirst($slug);
        }

        return $labels !== [] ? implode(', ', $labels) : null;
    }

    /** @param array<string, mixed> $record */
    private function authorUrl(array $record, mixed $state): ?string
    {
        return ($record['provider'] ?? null) === ModpackProvider::Modrinth->value
            && is_string($state)
            && preg_match('/^[A-Za-z0-9._-]+$/D', $state) === 1
            ? "https://modrinth.com/user/{$state}"
            : null;
    }

    /**
     * @return array{hits: list<array<string, mixed>>, total_hits: int, error: bool}
     */
    private function searchPage(
        ModpackProvider $provider,
        string $query,
        int $page,
        ?string $minecraft,
        ?string $loader,
    ): array {
        $server = $this->resolveServer();
        $cacheKey = 'modpack-catalog-table:'.hash('sha256', serialize([
            $server->getKey(),
            $provider->value,
            $query,
            $page,
            $minecraft,
            $loader,
        ]));
        $attributes = request()->attributes;

        if (!$attributes->has($cacheKey)) {
            $attributes->set($cacheKey, app(ModpackCatalog::class)->searchPage(
                $server,
                $provider,
                $query,
                $page,
                $minecraft,
                $loader,
            ));
        }

        /** @var array{hits: list<array<string, mixed>>, total_hits: int, error: bool} */
        return $attributes->get($cacheKey);
    }

    private function resolveServer(): Server
    {
        if ($this->serverId !== null) {
            return Server::query()->find($this->serverId) ?? new Server();
        }

        return new Server();
    }

    /** @param array<string, mixed> $record */
    private function isSelected(array $record): bool
    {
        $key = $record['key'] ?? null;

        return is_string($key) && $key !== '' && $this->selectedKey === $key;
    }

    private function emptyStateMessage(): string
    {
        $provider = ModpackProvider::tryFrom($this->provider) ?? ModpackProvider::Modrinth;

        $key = match (true) {
            $this->lastSearchErrored => 'catalog_unavailable',
            !ModpackCatalog::supportsCatalogFilters($provider) && blank($this->getTableSearch()) => 'catalog_search_prompt',
            default => 'catalog_empty',
        };

        return trans('pelican-mod-manager::strings.modpacks.'.$key);
    }

    /** @param array<string, mixed> $record */
    private function rowIndex(array $record): int
    {
        if ($this->rowIndexMap === null) {
            $this->rowIndexMap = [];
            $index = 0;

            foreach ($this->getTableRecords() as $row) {
                if (is_array($row)) {
                    $this->rowIndexMap[(string) ($row['key'] ?? '')] = $index;
                }
                $index++;
            }
        }

        return $this->rowIndexMap[(string) ($record['key'] ?? '')] ?? PHP_INT_MAX;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $key = trim($value);
        if (array_key_exists($key, $this->dateMemo)) {
            return $this->dateMemo[$key];
        }

        try {
            return $this->dateMemo[$key] = Carbon::parse($key, 'UTC');
        } catch (Exception) {
            return $this->dateMemo[$key] = null;
        }
    }

    private function formatDate(mixed $value): string
    {
        return $this->parseDate($value)?->diffForHumans() ?? '';
    }

    private function formatDateTooltip(mixed $state, Table $table): string
    {
        $date = $this->parseDate($state);
        $timezone = function_exists('user') ? (user()?->timezone ?? 'UTC') : 'UTC';

        return $date?->timezone($timezone)->format($table->getDefaultDateTimeDisplayFormat()) ?? '';
    }
}
