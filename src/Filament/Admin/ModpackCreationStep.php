<?php

namespace Kazaminosuke\ModManager\Filament\Admin;

use App\Models\Egg;
use App\Models\Server;
use Filament\Forms\Components\Checkbox;
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
use Illuminate\Validation\ValidationException;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackProvisioningPlanner;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityDisposition;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityResult;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\Compatibility\ProvisioningMode;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Throwable;

/**
 * Optional first step on the admin create-server wizard.
 */
final class ModpackCreationStep
{
    private const STARTUP_FIELDS = [
        'egg_id', 'startup', 'select_startup', 'image', 'select_image',
        'skip_scripts', 'start_on_completion', 'server_variables', 'environment',
    ];

    private const MASKED_API_KEY = '********';

    public static function step(): Step
    {
        return Step::make('modpack')
            ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.create_step'))
            ->afterValidation(fn (Set $set, Get $get) => self::apply($set, $get, $get('mm_egg')))
            ->schema([
                Toggle::make('mm_modpack')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.use_modpack'))
                    ->helperText(fn (): string => trans('pelican-mod-manager::strings.modpacks.use_modpack_helper'))
                    ->live()
                    ->default(false)
                    ->afterStateUpdated(function (Set $set, Get $get, bool $state): void {
                        if ($state) {
                            self::apply($set, $get, $get('mm_egg'));
                        } else {
                            self::restore($set, $get);
                            $set('mm_confirm_compatibility', false);
                        }
                    }),
                Hidden::make('mm_original_startup')->dehydrated(false),
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
                        self::restore($set, $get);
                        $set('mm_version', null);
                        $set('mm_egg', null);
                        $set('mm_confirm_compatibility', false);
                    }),
                Select::make('mm_version')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.version'))
                    ->live()
                    ->required(fn (Get $get): bool => (bool) $get('mm_modpack') && filled($get('mm_project')))
                    ->visible(fn (Get $get): bool => (bool) $get('mm_modpack') && filled($get('mm_project')))
                    ->options(fn (Get $get): array => app(ModpackCatalog::class)->versionOptions((string) $get('mm_project')))
                    ->afterStateUpdated(function (Set $set, Get $get): void {
                        self::restore($set, $get);
                        $set('mm_egg', null);
                        $set('mm_confirm_compatibility', false);
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
                        self::restore($set, $get);
                        $set('mm_confirm_compatibility', false);
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
                            $result->serverFileStatus !== null
                                ? trans('pelican-mod-manager::strings.modpacks.'.$result->serverFileStatus) : null,
                            $result->mode === ProvisioningMode::Archive
                                ? trans('pelican-mod-manager::strings.modpacks.archive_startup') : null,
                        ]);

                        return implode("\n", $lines);
                    }),
                Checkbox::make('mm_confirm_compatibility')
                    ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.confirm_server_compatibility'))
                    ->helperText(function (Get $get): string {
                        $warning = self::result($get, $get('mm_egg'))?->compatibilityWarning;

                        return $warning !== null ? trans('pelican-mod-manager::strings.modpacks.'.$warning) : '';
                    })
                    ->visible(fn (Get $get): bool => self::result($get, $get('mm_egg'))?->compatibilityWarning !== null)
                    ->accepted()
                    ->dehydrated(false),
                Hidden::make('mm_guard')
                    ->default(true)
                    ->dehydrated(false)
                    ->rules([
                        fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            if (!(bool) $get('mm_modpack')) {
                                return;
                            }

                            $error = self::planError($get, self::result($get, $get('mm_egg')));
                            if ($error !== null) {
                                $fail($error);
                            }
                        },
                    ]),
                Hidden::make('mm_install_plan')
                    ->dehydrated(fn (Get $get): bool => (bool) $get('mm_modpack'))
                    ->dehydrateStateUsing(fn (Get $get, Hidden $component): ?array => self::planForCreation($get, $component)),
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

    private static function planError(Get $get, ?CompatibilityResult $result): ?string
    {
        if ($result === null || $result->selected === null
            || !in_array($result->disposition, [CompatibilityDisposition::Compatible, CompatibilityDisposition::EggChange], true)) {
            return $result?->reasonDetail ?: trans('pelican-mod-manager::strings.modpacks.plan_unavailable');
        }

        if ($result->compatibilityWarning !== null && !(bool) $get('mm_confirm_compatibility')) {
            return trans('pelican-mod-manager::strings.modpacks.'.$result->compatibilityWarning);
        }

        if ($result->mode === ProvisioningMode::Archive && !app(InstalledOperationManager::class)->supportsAsyncDispatch()) {
            return trans('pelican-mod-manager::strings.operations.queue_required');
        }

        return null;
    }

    /** @return array{mode: string, source: string, project_id: string, version_id: string, selection: array<string, mixed>}|null */
    private static function planForCreation(Get $get, Hidden $component): ?array
    {
        if (!(bool) $get('mm_modpack')) {
            return null;
        }

        $result = self::result($get, $get('mm_egg'));
        $error = self::planError($get, $result);
        if ($result === null) {
            throw ValidationException::withMessages([
                $component->resolveRelativeStatePath('mm_project') => trans('pelican-mod-manager::strings.modpacks.plan_unavailable'),
            ]);
        }
        if ($error !== null) {
            throw ValidationException::withMessages([$component->resolveRelativeStatePath('mm_project') => $error]);
        }

        if ((string) $get('egg_id') !== (string) $result->selected?->id
            || (bool) $get('skip_scripts')
            || ($result->mode === ProvisioningMode::Archive && (bool) $get('start_on_completion'))) {
            throw self::startupValidationError($component);
        }

        $image = (string) $get('image');
        if (JavaRuntimeImage::isTooOld($image, $result->minecraftVersion)) {
            throw ValidationException::withMessages([
                $component->resolveRelativeStatePath('image') => trans('pelican-mod-manager::strings.modpacks.java_too_old', [
                    'java' => JavaRuntimeImage::preferredLabel($result->minecraftVersion),
                ]),
            ]);
        }

        $environment = (array) $get('environment');
        $usesApiKey = self::usesCurseForgeApiKey($result);
        foreach ($result->variables as $name => $value) {
            if ($usesApiKey && $name === 'API_KEY') {
                continue;
            }
            if ($result->mode === ProvisioningMode::Archive && !array_key_exists($name, $environment)) {
                continue;
            }
            if ((string) ($environment[$name] ?? '') !== $value) {
                throw self::startupValidationError($component);
            }
        }

        if ($usesApiKey) {
            $environmentField = $component->getRootContainer()->getComponentByStatePath(
                $component->resolveRelativeStatePath('environment'),
                withHidden: true,
                withAbsoluteStatePath: true,
            );
            if (!$environmentField instanceof Hidden || !array_key_exists('API_KEY', $environment)) {
                throw self::startupValidationError($component);
            }
            // The Panel service receives the real credential. Livewire's
            // state and the startup editor only ever display the placeholder.
            $environmentField->dehydrateStateUsing(
                fn (mixed $state): array => array_replace((array) $state, ['API_KEY' => $result->variables['API_KEY']]),
            );
        }

        [$source, $project] = explode(':', (string) $get('mm_project'), 2);

        return [
            'mode' => $result->mode->value,
            'source' => $source,
            'project_id' => $project,
            'version_id' => (string) $get('mm_version'),
            'selection' => [
                'egg_id' => (string) $result->selected?->id,
                'image' => $image,
                'minecraft' => $result->minecraftVersion,
                'loader' => $result->loader ?? $result->selected?->loader,
                'server_pack' => $result->serverFileStatus === 'server_pack_selected',
                'variables' => $result->mode === ProvisioningMode::Archive ? array_intersect_key(
                    $environment,
                    array_flip(['MINECRAFT_VERSION', 'MC_VERSION', 'LOADER_VERSION', 'FORGE_VERSION', 'NEOFORGE_VERSION', 'QUILT_VERSION']),
                ) : [],
            ],
        ];
    }

    private static function startupValidationError(Hidden $component): ValidationException
    {
        return ValidationException::withMessages([
            $component->resolveRelativeStatePath('mm_project') => trans('pelican-mod-manager::strings.modpacks.startup_configuration_changed'),
        ]);
    }

    private static function usesCurseForgeApiKey(CompatibilityResult $result): bool
    {
        return $result->provider === ModpackProvider::CurseForge->value && $result->mode === ProvisioningMode::EggInstall
            && isset($result->variables['API_KEY']);
    }

    private static function restore(Set $set, Get $get): void
    {
        $previous = $get('mm_original_startup');
        if (!is_array($previous)) {
            return;
        }

        foreach (self::STARTUP_FIELDS as $field) {
            $set($field, $previous[$field] ?? null);
        }
        $set('mm_original_startup', null);
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

        if (!is_array($get('mm_original_startup'))) {
            $previous = [];
            foreach (self::STARTUP_FIELDS as $field) {
                $previous[$field] = $get($field);
            }
            $set('mm_original_startup', $previous);
        }

        $set('egg_id', $egg->getKey());
        $startup = collect($egg->startup_commands ?? [])->first();
        $set('startup', $startup);
        $set('select_startup', $startup);

        $images = is_array($egg->docker_images) ? $egg->docker_images : [];
        $image = JavaRuntimeImage::fromEggImages($images, $result->minecraftVersion);
        $set('image', $image);
        $set('select_image', $image);
        $set('skip_scripts', false);
        if ($result->mode === ProvisioningMode::Archive) {
            $set('start_on_completion', false);
        }
        $rows = [];
        $environment = [];
        foreach ($egg->variables->sortBy('sort')->values() as $variable) {
            $name = (string) $variable->env_variable;
            $value = ($name === 'API_KEY' && self::usesCurseForgeApiKey($result))
                ? self::MASKED_API_KEY
                : ($result->variables[$name] ?? (string) $variable->default_value);
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
        $key = 'modpack-creation:'.hash('sha256', serialize([$project, $version, $explicit]));
        $attributes = request()->attributes;
        if ($attributes->has($key)) {
            return $attributes->get($key);
        }
        $attributes->set($key, null);

        try {
            $parts = explode(':', $project, 2);
            if (count($parts) !== 2) {
                return null;
            }

            $descriptor = app(ModpackCatalog::class)->describe(new Server(), $parts[0], $parts[1], (string) $version);
            if ($descriptor === null) {
                return null;
            }

            $keyValue = config('pelican-mod-manager.curseforge_api_key');

            $result = app(ModpackProvisioningPlanner::class)->select(
                $descriptor,
                app(InstalledEggCatalog::class)->all(),
                EggCompatibilityEngine::CONTEXT_CREATE,
                null,
                [],
                $explicit,
                $explicit === null,
                is_string($keyValue) ? $keyValue : null,
            );
            $attributes->set($key, $result);

            return $result;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
