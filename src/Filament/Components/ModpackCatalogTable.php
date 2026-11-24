<?php

namespace Kazaminosuke\ModManager\Filament\Components;

use App\Models\Server;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\ArrayRecord;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Support\ProjectIconUrl;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Filament-native modpack catalog table shared by server creation and existing-server modpack selection.
 *
 * Mirrors ModManagerPage::table() directly without custom CSS, replacing the per-row
 * install actions with a selection action and omitting the redundant source column.
 */
final class ModpackCatalogTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public string $provider = ModpackProvider::Modrinth->value;

    public string $query = '';

    public ?string $minecraft = null;

    public ?string $loader = null;

    public ?int $serverId = null;

    public string $targetStatePath = '';

    public ?string $titleStatePath = null;

    #[Reactive]
    public ?string $selectedKey = null;

    private bool $lastSearchErrored = false;

    /** @var array<string, int>|null */
    private ?array $rowIndexMap = null;

    /** @var array<string, ?Carbon> */
    private array $dateMemo = [];

    public function mount(
        string $provider = ModpackProvider::Modrinth->value,
        string $query = '',
        ?string $minecraft = null,
        ?string $loader = null,
        ?int $serverId = null,
        ?string $selectedKey = null,
        string $targetStatePath = '',
        ?string $titleStatePath = null,
    ): void {
        $this->provider = ModpackProvider::tryFrom($provider)?->value ?? ModpackProvider::Modrinth->value;
        $this->query = $query;
        $this->minecraft = $minecraft;
        $this->loader = $loader;
        $this->serverId = $serverId;
        $this->selectedKey = $selectedKey;
        $this->targetStatePath = $targetStatePath;
        $this->titleStatePath = $titleStatePath;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int $page): LengthAwarePaginator {
                $this->rowIndexMap = null;

                $provider = ModpackProvider::tryFrom($this->provider) ?? ModpackProvider::Modrinth;
                $hasFilters = ModpackCatalog::supportsCatalogFilters($provider);
                $minecraft = $hasFilters ? $this->minecraft : null;
                $loader = $hasFilters ? $this->loader : null;

                $result = $this->searchPage($provider, $this->query, $page, $minecraft, $loader);
                $this->lastSearchErrored = $result['error'];

                $keyName = ArrayRecord::getKeyName();
                $hits = array_map(static function (array $hit) use ($keyName): array {
                    $hit[$keyName] = (string) ($hit['key'] ?? '');

                    return $hit;
                }, $result['hits']);

                return new LengthAwarePaginator(
                    $hits,
                    $result['total_hits'],
                    ModpackCatalog::PAGE_SIZE,
                    $page,
                );
            })
            ->deferLoading(false)
            ->paginated([ModpackCatalog::PAGE_SIZE])
            ->emptyStateIcon('tabler-packages')
            ->emptyStateHeading(fn (): string => $this->emptyStateMessage())
            ->emptyStateDescription(null)
            ->columns([
                ImageColumn::make('icon_url')
                    ->label('')
                    ->defaultImageUrl(ProjectIconUrl::placeholderDataUri())
                    ->imageSize(40)
                    ->alignCenter()
                    ->extraImgAttributes(fn (array $record): array => ProjectIconUrl::imgAttributes($this->rowIndex($record))),
                TextColumn::make('title')
                    ->label(trans('pelican-mod-manager::strings.table.columns.title'))
                    ->wrap()
                    ->lineClamp(1)
                    ->description(function (array $record): ?string {
                        $description = $record['description'] ?? null;
                        if (!is_string($description) || trim($description) === '') {
                            return null;
                        }

                        return Str::limit($description, 120, '...');
                    }),
                TextColumn::make('minecraft_versions')
                    ->label(trans('pelican-mod-manager::strings.table.filters.minecraft_version'))
                    ->badge()
                    ->color('gray')
                    ->wrap()
                    ->limitList(2)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('loaders')
                    ->label(trans('pelican-mod-manager::strings.table.filters.loader'))
                    ->badge()
                    ->wrap()
                    ->formatStateUsing(fn (string $state): string => MinecraftLoader::tryFrom($state)?->getLabel() ?? ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'fabric' => 'info',
                        'neoforge' => 'warning',
                        'forge' => 'danger',
                        'quilt' => 'primary',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('author')
                    ->label(trans('pelican-mod-manager::strings.table.columns.author'))
                    ->url(fn (array $record, mixed $state): ?string => (($record['provider'] ?? null) === ModpackProvider::Modrinth->value
                        && is_string($state)
                        && preg_match('/^[A-Za-z0-9._-]+$/D', $state) === 1)
                        ? "https://modrinth.com/user/{$state}"
                        : null, true)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('downloads')
                    ->label(trans('pelican-mod-manager::strings.table.columns.downloads'))
                    ->numeric()
                    ->icon('tabler-download')
                    ->toggleable(),
                TextColumn::make('date_modified')
                    ->label(trans('pelican-mod-manager::strings.table.columns.date_modified'))
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn (mixed $state): string => $this->formatDate($state))
                    ->tooltip(function (mixed $state) use ($table): string {
                        $date = $this->parseDate($state);
                        $timezone = function_exists('user') ? (user()?->timezone ?? 'UTC') : 'UTC';

                        return $date?->timezone($timezone)->format($table->getDefaultDateTimeDisplayFormat()) ?? '';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (array $record): ?string => is_string($record['url'] ?? null) && $record['url'] !== '' ? $record['url'] : null, true)
            ->recordClasses(fn (array $record): ?string => $this->isSelected($record) ? 'fi-striped' : null)
            ->recordActions([
                Action::make('select')
                    ->label(fn (array $record): string => $this->isSelected($record)
                        ? trans('pelican-mod-manager::strings.modpacks.pack_selected')
                        : trans('pelican-mod-manager::strings.modpacks.select_pack'))
                    ->icon(fn (array $record): string => $this->isSelected($record)
                        ? 'tabler-check'
                        : 'tabler-plus')
                    ->button()
                    ->size(Size::Small)
                    ->color(fn (array $record): string => $this->isSelected($record) ? 'success' : 'gray')
                    ->outlined(fn (array $record): bool => !$this->isSelected($record))
                    ->alpineClickHandler(fn (array $record): string => $this->selectClickHandler($record)),
            ]);
    }

    public function render(): View
    {
        return view('pelican-mod-manager::components.modpack-catalog-table');
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

    /** @param array<string, mixed> $record */
    private function selectClickHandler(array $record): string
    {
        $key = (string) ($record['key'] ?? '');
        $title = (string) ($record['title'] ?? '');
        $statements = [];

        if (is_string($this->titleStatePath) && $this->titleStatePath !== '') {
            $statements[] = '$wire.$parent.$set('.Js::from($this->titleStatePath).', '.Js::from($title).', false)';
        }

        $statements[] = '$wire.$parent.$set('.Js::from($this->targetStatePath).', '.Js::from($key).')';

        return implode('; ', $statements);
    }

    private function emptyStateMessage(): string
    {
        $provider = ModpackProvider::tryFrom($this->provider) ?? ModpackProvider::Modrinth;

        $key = match (true) {
            $this->lastSearchErrored => 'catalog_unavailable',
            !ModpackCatalog::supportsCatalogFilters($provider) && blank($this->query) => 'catalog_search_prompt',
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
}
