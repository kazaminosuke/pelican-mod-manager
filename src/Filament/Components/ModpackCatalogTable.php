<?php

namespace Kazaminosuke\ModManager\Filament\Components;

use App\Models\Server;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\ArrayRecord;
use Filament\Support\Enums\Alignment;
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
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Filament\Actions\CatalogRowAction;
use Kazaminosuke\ModManager\Filament\Admin\ModpackCreationStep;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackInstallCoordinator;
use Kazaminosuke\ModManager\Support\CatalogFields;
use Kazaminosuke\ModManager\Support\CatalogTileColumns;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityDisposition;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityResult;
use Kazaminosuke\ModManager\Support\Compatibility\ProvisioningMode;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Kazaminosuke\ModManager\Support\ProjectIconUrl;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Throwable;

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
    use InteractsWithTable {
        InteractsWithTable::applyTableColumnManager as protected baseApplyTableColumnManager;
        InteractsWithTable::resetTableColumnManager as protected baseResetTableColumnManager;
    }

    public const MODE_CREATE = 'create';

    public const MODE_SERVER = 'server';

    public string $mode = self::MODE_CREATE;

    public string $provider = ModpackProvider::Modrinth->value;

    public ?int $serverId = null;

    public string $targetStatePath = '';

    public ?string $titleStatePath = null;

    public ?string $versionStatePath = null;

    public ?string $eggStatePath = null;

    public ?string $confirmStatePath = null;

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
        ?string $eggStatePath = null,
        ?string $confirmStatePath = null,
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
        $this->eggStatePath = $eggStatePath;
        $this->confirmStatePath = $confirmStatePath;
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

    public function updatedProvider(string $value): void
    {
        $available = $this->providerTabs();
        if (!array_key_exists($value, $available)) {
            $this->provider = (string) (array_key_first($available) ?? ModpackProvider::Modrinth->value);
        }

        $this->tableFilters = [];
        $this->resetTable();
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
        $this->catalogView = CatalogTileColumns::storeView(
            $this->catalogView === CatalogTileColumns::VIEW_PANEL
                ? CatalogTileColumns::VIEW_LIST
                : CatalogTileColumns::VIEW_PANEL,
        );

        if (isset($this->table)) {
            $this->table = $this->table($this->makeTable());
            $this->syncTableColumnManagerAfterViewChange();
        }

        $this->flushCachedTableRecords();
        $this->dispatch(CatalogTileColumns::VIEW_CHANGED_EVENT, view: $this->catalogView);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $state
     */
    public function applyTableColumnManager(?array $state = null, bool $wasReordered = false): void
    {
        if (isset($this->table) && $this->getTable()->hasColumnsLayout()) {
            return;
        }

        $this->baseApplyTableColumnManager($state, $wasReordered);
    }

    public function resetTableColumnManager(): void
    {
        if (isset($this->table) && $this->getTable()->hasColumnsLayout()) {
            return;
        }

        $this->baseResetTableColumnManager();
    }

    private function syncTableColumnManagerAfterViewChange(): void
    {
        if (!isset($this->table) || $this->getTable()->hasColumnsLayout()) {
            return;
        }

        $this->cachedDefaultTableColumnState = null;
        $this->tableColumnsToggleStateByName = null;
        $this->initTableColumnManager();
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
                )
                : $this->listColumns($table))
            ->contentGrid(fn (): ?array => $this->catalogView === CatalogTileColumns::VIEW_PANEL ? CatalogTileColumns::contentGrid() : null)
            ->recordUrl(fn (array $record): ?string => is_string($record['url'] ?? null) && $record['url'] !== '' ? $record['url'] : null, true)
            ->recordClasses(fn (array $record): ?string => $this->isSelected($record) ? 'fi-striped' : null)
            ->recordActionsAlignment(Alignment::End->value)
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

                        $this->openPlanModal($record, $latest['id'], $latest['label']);
                    }),
            ]);

        return $table;
    }

    public function reviewModpackPlanAction(): Action
    {
        return Action::make('reviewModpackPlan')
            ->modalHeading(fn (array $arguments): string => (string) ($arguments['title'] ?? trans('pelican-mod-manager::strings.modpacks.plan')))
            ->modalDescription(fn (array $arguments): ?string => is_string($arguments['version_label'] ?? null) && $arguments['version_label'] !== '' ? $arguments['version_label'] : null)
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel(trans('pelican-mod-manager::strings.actions.install'))
            ->modalSubmitAction(function (Action $action, array $arguments): Action {
                $selection = (string) ($arguments['key'] ?? '');
                $versionId = (string) ($arguments['version_id'] ?? '');
                $result = $this->evaluatePlan($selection, $versionId, null);

                return $this->planBlockingError($result, allowAmbiguous: true) !== null
                    ? $action->disabled()
                    : $action;
            })
            ->schema(function (array $arguments): array {
                $selection = (string) ($arguments['key'] ?? '');
                $versionId = (string) ($arguments['version_id'] ?? '');
                $server = $this->mode === self::MODE_SERVER ? $this->resolveServer() : null;

                return [
                    Section::make(trans('pelican-mod-manager::strings.modpacks.plan'))
                        ->columns(2)
                        ->schema([
                            TextEntry::make('plan_provider')
                                ->label(trans('pelican-mod-manager::strings.table.columns.source'))
                                ->state(function (Get $get) use ($selection, $versionId): string {
                                    $result = $this->evaluatePlan($selection, $versionId, $get('egg_id'));

                                    return $result !== null
                                        ? (ModpackProvider::tryFrom($result->provider)?->getLabel() ?? ucfirst($result->provider))
                                        : '—';
                                }),
                            TextEntry::make('plan_minecraft')
                                ->label(trans('pelican-mod-manager::strings.table.filters.minecraft_version'))
                                ->state(fn (Get $get): string => $this->evaluatePlan($selection, $versionId, $get('egg_id'))?->minecraftVersion ?? '—'),
                            TextEntry::make('plan_loader')
                                ->label(trans('pelican-mod-manager::strings.table.filters.loader'))
                                ->state(function (Get $get) use ($selection, $versionId): string {
                                    $loader = $this->evaluatePlan($selection, $versionId, $get('egg_id'))?->loader;
                                    if (!is_string($loader) || trim($loader) === '') {
                                        return '—';
                                    }

                                    return MinecraftLoader::tryFrom(strtolower($loader))?->getLabel() ?? ucfirst($loader);
                                }),
                            TextEntry::make('plan_java')
                                ->label(trans('pelican-mod-manager::strings.modpacks.java_runtime'))
                                ->state(function (Get $get) use ($selection, $versionId, $server): string {
                                    $result = $this->evaluatePlan($selection, $versionId, $get('egg_id'));
                                    if ($result === null) {
                                        return '—';
                                    }

                                    $java = JavaRuntimeImage::preferredLabel($result->minecraftVersion, $result->minimumJava);
                                    $parts = [$java];

                                    if ($server !== null && $server->exists) {
                                        if (JavaRuntimeImage::isTooOld($server->image, $result->minecraftVersion, $result->minimumJava)) {
                                            $parts[] = trans('pelican-mod-manager::strings.modpacks.java_too_old', ['java' => $java]);
                                        }
                                        if (JavaRuntimeImage::isTooNew($server->image, $result->minecraftVersion, $result->loader)) {
                                            $parts[] = trans('pelican-mod-manager::strings.modpacks.java_too_new', [
                                                'loader' => $result->loader ?? 'Minecraft',
                                                'java' => JavaRuntimeImage::maximumLabel($result->minecraftVersion, $result->loader),
                                            ]);
                                        }
                                    }

                                    return implode(' ', array_filter($parts));
                                }),
                            TextEntry::make('plan_egg')
                                ->label(trans('pelican-mod-manager::strings.modpacks.egg_compatibility'))
                                ->columnSpanFull()
                                ->state(function (Get $get) use ($selection, $versionId): string {
                                    $result = $this->evaluatePlan($selection, $versionId, $get('egg_id'));
                                    if ($result === null) {
                                        return trans('pelican-mod-manager::strings.modpacks.plan_unavailable');
                                    }

                                    $parts = array_filter([
                                        $result->selected?->name,
                                        $result->reasonDetail,
                                    ]);

                                    return $parts !== [] ? implode(' — ', $parts) : '—';
                                }),
                            TextEntry::make('plan_server_pack')
                                ->label(trans('pelican-mod-manager::strings.modpacks.server_pack'))
                                ->columnSpanFull()
                                ->visible(fn (Get $get): bool => $this->evaluatePlan($selection, $versionId, $get('egg_id'))?->serverFileStatus !== null)
                                ->state(function (Get $get) use ($selection, $versionId): string {
                                    $status = $this->evaluatePlan($selection, $versionId, $get('egg_id'))?->serverFileStatus;

                                    return $status !== null ? trans('pelican-mod-manager::strings.modpacks.'.$status) : '';
                                }),
                            TextEntry::make('plan_archive_startup')
                                ->hiddenLabel()
                                ->columnSpanFull()
                                ->color('gray')
                                ->visible(fn (Get $get): bool => $this->mode === self::MODE_CREATE
                                    && $this->evaluatePlan($selection, $versionId, $get('egg_id'))?->mode === ProvisioningMode::Archive)
                                ->state(trans('pelican-mod-manager::strings.modpacks.archive_startup')),
                        ]),
                    Select::make('egg_id')
                        ->label(trans('pelican-mod-manager::strings.modpacks.choose_egg'))
                        ->helperText(trans('pelican-mod-manager::strings.modpacks.choose_egg_helper'))
                        ->live()
                        ->visible(function () use ($selection, $versionId): bool {
                            if ($this->mode !== self::MODE_CREATE) {
                                return false;
                            }

                            return $this->evaluatePlan($selection, $versionId, null)?->disposition === CompatibilityDisposition::Ambiguous;
                        })
                        ->required(function () use ($selection, $versionId): bool {
                            if ($this->mode !== self::MODE_CREATE) {
                                return false;
                            }

                            return $this->evaluatePlan($selection, $versionId, null)?->disposition === CompatibilityDisposition::Ambiguous;
                        })
                        ->options(function () use ($selection, $versionId): array {
                            $result = $this->evaluatePlan($selection, $versionId, null);
                            $options = [];
                            foreach ($result->alternatives ?? [] as $egg) {
                                $options[(string) $egg->id] = $egg->name.' · '.($egg->profileId ?? $egg->loader ?? 'custom').' · '.$egg->confidence;
                            }

                            return $options;
                        }),
                    TextEntry::make('plan_error')
                        ->hiddenLabel()
                        ->color('danger')
                        ->visible(function (Get $get) use ($selection, $versionId): bool {
                            $result = $this->evaluatePlan($selection, $versionId, $get('egg_id'));

                            return $this->planBlockingError($result, allowAmbiguous: true) !== null;
                        })
                        ->state(function (Get $get) use ($selection, $versionId): string {
                            $result = $this->evaluatePlan($selection, $versionId, $get('egg_id'));

                            return $this->planBlockingError($result, allowAmbiguous: true) ?? '';
                        }),
                    TextEntry::make('compatibility_warning')
                        ->label(trans('pelican-mod-manager::strings.modpacks.compatibility_warning'))
                        ->color('warning')
                        ->visible(fn (Get $get): bool => $this->evaluatePlan($selection, $versionId, $get('egg_id'))?->compatibilityWarning !== null)
                        ->state(function (Get $get) use ($selection, $versionId): string {
                            $warning = $this->evaluatePlan($selection, $versionId, $get('egg_id'))?->compatibilityWarning;

                            return $warning !== null ? trans('pelican-mod-manager::strings.modpacks.'.$warning) : '';
                        }),
                    Checkbox::make('confirm_compatibility')
                        ->label(trans('pelican-mod-manager::strings.modpacks.confirm_server_compatibility'))
                        ->visible(fn (Get $get): bool => $this->evaluatePlan($selection, $versionId, $get('egg_id'))?->compatibilityWarning !== null)
                        ->accepted(fn (Get $get): bool => $this->evaluatePlan($selection, $versionId, $get('egg_id'))?->compatibilityWarning !== null)
                        ->required(fn (Get $get): bool => $this->evaluatePlan($selection, $versionId, $get('egg_id'))?->compatibilityWarning !== null),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $key = (string) ($arguments['key'] ?? '');
                $title = (string) ($arguments['title'] ?? '');
                $versionId = (string) ($arguments['version_id'] ?? '');
                $eggId = is_scalar($data['egg_id'] ?? null) && (string) $data['egg_id'] !== '' ? (string) $data['egg_id'] : null;
                $confirmed = (bool) ($data['confirm_compatibility'] ?? false);

                $result = $this->evaluatePlan($key, $versionId, $eggId);
                $blockingError = $this->planBlockingError($result, allowAmbiguous: false);
                if ($blockingError !== null) {
                    Notification::make()
                        ->title($blockingError)
                        ->warning()
                        ->send();

                    return;
                }

                $this->commitRelease($key, $title, $versionId, $eggId, $confirmed);
            });
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
                        $this->openPlanModal($record, $version['id'], $version['label']);
                    });

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
    private function openPlanModal(array $record, string $versionId, string $versionLabel = ''): void
    {
        $key = (string) ($record['key'] ?? '');
        $title = (string) ($record['title'] ?? '');

        if ($key === '' || $versionId === '') {
            return;
        }

        $this->replaceMountedAction('reviewModpackPlan', [
            'key' => $key,
            'title' => $title,
            'version_id' => $versionId,
            'version_label' => $versionLabel,
        ]);
    }

    private function evaluatePlan(string $selection, string $versionId, mixed $explicitEggId): ?CompatibilityResult
    {
        if ($selection === '' || $versionId === '') {
            return null;
        }

        if ($this->mode === self::MODE_CREATE) {
            return ModpackCreationStep::evaluateSelection($selection, $versionId, $explicitEggId);
        }

        $server = $this->resolveServer();
        $cacheKey = 'modpack-server-plan:'.hash('sha256', serialize([$server->getKey(), $selection, $versionId]));
        $attributes = request()->attributes;
        if ($attributes->has($cacheKey)) {
            return $attributes->get($cacheKey);
        }
        $attributes->set($cacheKey, null);

        try {
            $result = app(ModpackInstallCoordinator::class)->preview($server, $selection, $versionId)['result'];
            $attributes->set($cacheKey, $result);

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    private function planBlockingError(?CompatibilityResult $result, bool $allowAmbiguous): ?string
    {
        if ($result === null) {
            return trans('pelican-mod-manager::strings.modpacks.plan_unavailable');
        }

        if ($allowAmbiguous && $this->mode === self::MODE_CREATE && $result->disposition === CompatibilityDisposition::Ambiguous) {
            return null;
        }

        $allowed = $this->mode === self::MODE_SERVER
            ? [CompatibilityDisposition::Compatible]
            : [CompatibilityDisposition::Compatible, CompatibilityDisposition::EggChange];

        if ($result->selected === null || !in_array($result->disposition, $allowed, true)) {
            return $result->reasonDetail ?: trans('pelican-mod-manager::strings.modpacks.plan_unavailable');
        }

        if ($result->mode === ProvisioningMode::Archive && !app(InstalledOperationManager::class)->supportsAsyncDispatch()) {
            return trans('pelican-mod-manager::strings.operations.queue_required');
        }

        return null;
    }

    private function commitRelease(string $key, string $title, string $versionId, ?string $eggId = null, bool $confirmCompatibility = false): void
    {
        if ($key === '' || $versionId === '') {
            return;
        }

        if ($this->mode === self::MODE_SERVER) {
            $this->js('$wire.$parent.selectModpackRelease('.Js::from($key).', '.Js::from($title).', '.Js::from($versionId).', '.Js::from($confirmCompatibility).')');

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
        if ($eggId !== null && is_string($this->eggStatePath) && $this->eggStatePath !== '') {
            $steps[] = 'await $wire.$parent.$set('.Js::from($this->eggStatePath).', '.Js::from($eggId).');';
        }
        if ($confirmCompatibility && is_string($this->confirmStatePath) && $this->confirmStatePath !== '') {
            $steps[] = 'await $wire.$parent.$set('.Js::from($this->confirmStatePath).', true);';
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
