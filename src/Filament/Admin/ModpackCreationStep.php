<?php

namespace Kazaminosuke\ModManager\Filament\Admin;

use App\Models\Egg;
use App\Models\Server;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackProvisioningPlanner;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityDisposition;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityResult;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Throwable;

/**
 * Optional first step on the admin create-server wizard.
 */
final class ModpackCreationStep
{
    /** @var array<string, CompatibilityResult|null> */
    private static array $results = [];

    public static function step(): Step
    {
        return Step::make('modpack')
            ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.create_step'))
            ->schema([
                Toggle::make('mm_modpack')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.use_modpack'))
                    ->helperText(fn (): string => trans('pelican-mod-manager::strings.modpacks.use_modpack_helper'))
                    ->live()
                    ->default(false),
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->visible(fn (Get $get): bool => (bool) $get('mm_modpack'))
                    ->schema([
                        TextInput::make('mm_query')
                            ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.catalog_search'))
                            ->maxLength(100)
                            ->live(debounce: 500)
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (Set $set) => $set('mm_page', 1)),
                        Select::make('mm_provider')
                            ->label(fn (): string => trans('pelican-mod-manager::strings.table.columns.source'))
                            ->default(ModpackProvider::Modrinth->value)
                            ->options(fn (): array => app(ModpackCatalog::class)->providers())
                            ->selectablePlaceholder(false)
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (Set $set) => $set('mm_page', 1)),
                        Select::make('mm_minecraft')
                            ->label(fn (): string => trans('pelican-mod-manager::strings.table.filters.minecraft_version'))
                            ->options(fn (): array => app(ModpackCatalog::class)->minecraftVersions())
                            ->searchable()
                            ->live()
                            ->dehydrated(false)
                            ->visible(self::hasCatalogFilters(...))
                            ->afterStateUpdated(fn (Set $set) => $set('mm_page', 1)),
                        Select::make('mm_loader')
                            ->label(fn (): string => trans('pelican-mod-manager::strings.table.filters.loader'))
                            ->options(fn (): array => app(ModpackCatalog::class)->loaders())
                            ->live()
                            ->dehydrated(false)
                            ->visible(self::hasCatalogFilters(...))
                            ->afterStateUpdated(fn (Set $set) => $set('mm_page', 1)),
                    ]),
                Hidden::make('mm_page')->default(1)->dehydrated(false),
                Hidden::make('mm_project_title')->dehydrated(false),
                ViewField::make('mm_project')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.pack'))
                    ->view('pelican-mod-manager::components.modpack-catalog')
                    ->viewData(fn (Get $get, ViewField $component): array => self::catalogViewData($get, $component))
                    ->required(fn (Get $get): bool => (bool) $get('mm_modpack'))
                    ->visible(fn (Get $get): bool => (bool) $get('mm_modpack'))
                    ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                        $titles = array_column(self::catalogPage($get)['hits'], 'title', 'key');
                        $set('mm_project_title', is_string($state) ? ($titles[$state] ?? null) : null);
                        $set('mm_version', null);
                        $set('mm_egg', null);
                    }),
                Select::make('mm_version')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.version'))
                    ->live()
                    ->required(fn (Get $get): bool => (bool) $get('mm_modpack') && filled($get('mm_project')))
                    ->visible(fn (Get $get): bool => (bool) $get('mm_modpack') && filled($get('mm_project')))
                    ->options(fn (Get $get): array => app(ModpackCatalog::class)->versionOptions((string) $get('mm_project')))
                    ->afterStateUpdated(function (Set $set, Get $get): void {
                        $set('mm_egg', null);
                        self::apply($set, $get, null);
                    }),
                Select::make('mm_egg')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.choose_egg'))
                    ->helperText(fn (): string => trans('pelican-mod-manager::strings.modpacks.choose_egg_helper'))
                    ->live()
                    ->required(function (Get $get): bool {
                        $result = self::result($get, null);

                        return $result !== null && $result->disposition === CompatibilityDisposition::Ambiguous;
                    })
                    ->visible(function (Get $get): bool {
                        $result = self::result($get, null);

                        return $result !== null && $result->disposition === CompatibilityDisposition::Ambiguous;
                    })
                    ->options(function (Get $get): array {
                        $result = self::result($get, null);
                        $options = [];
                        foreach ($result->alternatives ?? [] as $egg) {
                            $options[(string) $egg->id] = $egg->name.' · '.($egg->profileId ?? $egg->loader ?? 'custom').' · '.$egg->confidence;
                        }

                        return $options;
                    })
                    ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                        self::apply($set, $get, $state);
                    }),
                TextEntry::make('mm_summary')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.plan'))
                    ->visible(fn (Get $get): bool => (bool) $get('mm_modpack') && filled($get('mm_project')))
                    ->state(function (Get $get): string {
                        $result = self::result($get, $get('mm_egg'));
                        if ($result === null) {
                            return trans('pelican-mod-manager::strings.modpacks.plan_unavailable');
                        }

                        $lines = array_filter([
                            $result->provider,
                            $result->minecraftVersion !== null ? 'Minecraft '.$result->minecraftVersion : null,
                            $result->loader,
                            $result->selected?->name,
                            $result->reasonDetail,
                        ]);

                        return implode("\n", $lines);
                    }),
                Hidden::make('mm_guard')
                    ->dehydrated(false)
                    ->rules([
                        fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            if (!(bool) $get('mm_modpack') || !filled($get('mm_project'))) {
                                return;
                            }

                            $result = self::result($get, $get('mm_egg'));
                            if ($result === null || $result->disposition === CompatibilityDisposition::Unsupported) {
                                $fail($result?->reasonDetail ?: (string) trans('pelican-mod-manager::strings.modpacks.plan_unavailable'));
                            }
                        },
                    ]),
            ]);
    }

    private static function hasCatalogFilters(Get $get): bool
    {
        return in_array($get('mm_provider'), [ModpackProvider::Modrinth->value, ModpackProvider::CurseForge->value], true);
    }

    /** @return array<string, mixed> */
    private static function catalogViewData(Get $get, ViewField $component): array
    {
        $page = min(500, max(1, (int) $get('mm_page')));
        $result = self::catalogPage($get);
        $message = match (true) {
            $result['error'] => 'catalog_unavailable',
            !self::hasCatalogFilters($get) && blank($get('mm_query')) => 'catalog_search_prompt',
            default => 'catalog_empty',
        };

        return [
            'hits' => $result['hits'],
            'selectedTitle' => $get('mm_project_title'),
            'page' => $page,
            'pageStatePath' => $component->resolveRelativeStatePath('mm_page'),
            'hasNext' => $page * ModpackCatalog::PAGE_SIZE < $result['total_hits'],
            'emptyMessage' => trans('pelican-mod-manager::strings.modpacks.'.$message),
        ];
    }

    /** @return array{hits: list<array<string, mixed>>, total_hits: int, error: bool} */
    private static function catalogPage(Get $get): array
    {
        $provider = ModpackProvider::tryFrom((string) $get('mm_provider')) ?? ModpackProvider::Modrinth;
        $filters = self::hasCatalogFilters($get);
        $query = (string) $get('mm_query');
        $page = (int) $get('mm_page');
        $minecraft = $filters ? $get('mm_minecraft') : null;
        $loader = $filters ? $get('mm_loader') : null;
        $key = 'modpack-catalog:'.hash('sha256', serialize([$provider->value, $query, $page, $minecraft, $loader]));
        $attributes = request()->attributes;
        if (!$attributes->has($key)) {
            $attributes->set($key, app(ModpackCatalog::class)->searchPage(
                new Server(), $provider, $query, $page,
                is_string($minecraft) ? $minecraft : null,
                is_string($loader) ? $loader : null,
            ));
        }

        return $attributes->get($key);
    }

    private static function apply(Set $set, Get $get, mixed $explicitEggId): void
    {
        $result = self::result($get, $explicitEggId);
        if ($result === null || $result->selected === null || $result->disposition === CompatibilityDisposition::Ambiguous) {
            return;
        }

        if (!in_array($result->disposition, [CompatibilityDisposition::Compatible, CompatibilityDisposition::EggChange], true)) {
            return;
        }

        $egg = Egg::query()->with('variables')->find($result->selected->id);
        if ($egg === null) {
            return;
        }

        $set('egg_id', $egg->getKey());
        $startup = collect($egg->startup_commands ?? [])->first();
        if (is_string($startup) && $startup !== '') {
            $set('startup', $startup);
            $set('select_startup', $startup);
        }

        $images = is_array($egg->docker_images) ? $egg->docker_images : [];
        $image = JavaRuntimeImage::fromEggImages($images, $result->minecraftVersion);
        if ($image !== null) {
            $set('image', $image);
            $set('select_image', $image);
        }

        $set('skip_scripts', false);
        $rows = [];
        $environment = [];
        foreach ($egg->variables->sortBy('sort')->values() as $variable) {
            $name = (string) $variable->env_variable;
            $value = $result->variables[$name] ?? (string) $variable->default_value;
            $row = $variable->toArray();
            $row['variable_value'] = $value;
            $row['variable_id'] = $variable->getKey();
            $rows[] = $row;
            $environment[$name] = $value;
        }
        $set('server_variables', $rows);
        $set('environment', $environment);
    }

    private static function result(Get $get, mixed $explicitEggId): ?CompatibilityResult
    {
        $project = (string) $get('mm_project');
        $version = $get('mm_version');
        if (!(bool) $get('mm_modpack') || $project === '' || $version === null || $version === '') {
            return null;
        }

        $explicit = is_scalar($explicitEggId) && (string) $explicitEggId !== '' ? (string) $explicitEggId : null;
        $key = $project.'|'.$version.'|'.($explicit ?? '');
        if (array_key_exists($key, self::$results)) {
            return self::$results[$key];
        }

        try {
            $parts = explode(':', $project, 2);
            if (count($parts) !== 2) {
                return self::$results[$key] = null;
            }

            $descriptor = app(ModpackCatalog::class)->describe(new Server(), $parts[0], $parts[1], (string) $version);
            if ($descriptor === null) {
                return self::$results[$key] = null;
            }

            $keyValue = config('pelican-mod-manager.curseforge_api_key');

            return self::$results[$key] = app(ModpackProvisioningPlanner::class)->select(
                $descriptor,
                app(InstalledEggCatalog::class)->all(),
                EggCompatibilityEngine::CONTEXT_CREATE,
                null,
                [],
                $explicit,
                $explicit === null,
                is_string($keyValue) ? $keyValue : null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return self::$results[$key] = null;
        }
    }
}
