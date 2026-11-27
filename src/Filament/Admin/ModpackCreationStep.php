<?php

namespace Kazaminosuke\ModManager\Filament\Admin;

use App\Filament\Admin\Resources\Servers\Pages\CreateServer;
use App\Models\Egg;
use App\Models\Server;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Validation\ValidationException;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Filament\Components\ModpackCatalogTable;
use Kazaminosuke\ModManager\Services\CreateModpackServer;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackLoaderInstaller;
use Kazaminosuke\ModManager\Services\ModpackProvisioningPlanner;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityDisposition;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityResult;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\Compatibility\ProvisioningMode;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDescriptor;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackLoader;
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

    public static function configureStartupFields(): void
    {
        Field::configureUsing(static function (Field $field): void {
            $name = $field->getName();
            if (!in_array($name, ['egg_id', 'skip_scripts', 'start_on_completion', 'select_startup', 'startup'], true)) {
                return;
            }

            $field->visible(static function (Get $get, $livewire) use ($name): bool {
                if (!$livewire instanceof CreateServer || !$get('mm_modpack') || !is_array($get('mm_original_startup'))) {
                    return true;
                }

                return !in_array($name, ['egg_id', 'skip_scripts'], true) && !(bool) $get('skip_scripts');
            })->dehydratedWhenHidden();
        });
    }

    public static function step(): Step
    {
        return Step::make('modpack')
            ->label(fn (): string => trans('pelican-mod-manager::strings.modpacks.create_step'))
            ->visible(fn (CreateServer $livewire): bool => ($livewire->restoreSource ?? null) === null)
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
                Hidden::make('mm_query')->dehydrated(false),
                Hidden::make('mm_provider')
                    ->default(ModpackProvider::Modrinth->value)
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(function (Set $set, Get $get): void {
                        $set('mm_project', null);
                        $set('mm_project_title', null);
                        $set('mm_version', null);
                        $set('mm_egg', null);
                        $set('mm_confirm_compatibility', false);
                        self::restore($set, $get);
                    }),
                Hidden::make('mm_minecraft')->dehydrated(false),
                Hidden::make('mm_loader')->dehydrated(false),
                Hidden::make('mm_project_title')->dehydrated(false),
                ViewField::make('mm_project')
                    ->hiddenLabel()
                    ->view('pelican-mod-manager::components.modpack-catalog-field')
                    ->viewData(fn (Get $get, ViewField $component): array => self::catalogViewData($get, $component))
                    ->required(fn (Get $get): bool => (bool) $get('mm_modpack'))
                    ->visible(fn (Get $get): bool => (bool) $get('mm_modpack'))
                    ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                        if (!is_string($state) || $state === '') {
                            $set('mm_project_title', null);
                        } elseif (!is_string($get('mm_project_title')) || trim($get('mm_project_title')) === '') {
                            $titles = array_column(self::catalogPage($get)['hits'], 'title', 'key');
                            if (isset($titles[$state])) {
                                $set('mm_project_title', $titles[$state]);
                            }
                        }
                        self::restore($set, $get);
                        $set('mm_version', null);
                        $set('mm_egg', null);
                        $set('mm_confirm_compatibility', false);
                    }),
                Hidden::make('mm_version')
                    ->live()
                    ->required(fn (Get $get): bool => (bool) $get('mm_modpack') && filled($get('mm_project')))
                    ->afterStateUpdated(function (Set $set, Get $get): void {
                        self::restore($set, $get);
                        $set('mm_egg', null);
                        $set('mm_confirm_compatibility', false);
                        self::apply($set, $get, null);
                    }),
                Hidden::make('mm_egg')
                    ->live()
                    ->required(function (Get $get): bool {
                        $result = self::result($get, null);

                        return $result !== null && $result->disposition === CompatibilityDisposition::Ambiguous;
                    })
                    ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                        self::restore($set, $get);
                        $set('mm_confirm_compatibility', false);
                        self::apply($set, $get, $state);
                    }),
                Hidden::make('mm_confirm_compatibility')
                    ->default(false)
                    ->dehydrated(false)
                    ->rules([
                        fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $warning = self::result($get, $get('mm_egg'))?->compatibilityWarning;
                            if ($warning !== null && !(bool) $value) {
                                $fail(trans('pelican-mod-manager::strings.modpacks.'.$warning));
                            }
                        },
                    ]),
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
        return ModpackCatalog::supportsCatalogFilters($get('mm_provider'));
    }

    /** @return array<string, mixed> */
    private static function catalogViewData(Get $get, ViewField $component): array
    {
        $provider = ModpackProvider::tryFrom((string) $get('mm_provider')) ?? ModpackProvider::Modrinth;
        $filters = self::hasCatalogFilters($get);
        $query = (string) $get('mm_query');
        $minecraft = $filters && is_string($get('mm_minecraft')) && $get('mm_minecraft') !== '' ? $get('mm_minecraft') : null;
        $loader = $filters && is_string($get('mm_loader')) && $get('mm_loader') !== '' ? $get('mm_loader') : null;
        $selectedVersion = $get('mm_version');

        return [
            'mode' => ModpackCatalogTable::MODE_CREATE,
            'provider' => $provider->value,
            'query' => $query,
            'minecraft' => $minecraft,
            'loader' => $loader,
            'serverId' => null,
            'selectedVersion' => is_scalar($selectedVersion) && (string) $selectedVersion !== '' ? (string) $selectedVersion : null,
            'titleStatePath' => $component->resolveRelativeStatePath('mm_project_title'),
            'versionStatePath' => $component->resolveRelativeStatePath('mm_version'),
            'eggStatePath' => $component->resolveRelativeStatePath('mm_egg'),
            'confirmStatePath' => $component->resolveRelativeStatePath('mm_confirm_compatibility'),
            'tableKey' => 'create-modpack-'.hash('sha256', serialize([$provider->value, $query, $minecraft, $loader])),
        ];
    }

    /** @return array{hits: list<array<string, mixed>>, total_hits: int, error: bool} */
    private static function catalogPage(Get $get): array
    {
        $provider = ModpackProvider::tryFrom((string) $get('mm_provider')) ?? ModpackProvider::Modrinth;
        $filters = self::hasCatalogFilters($get);
        $query = (string) $get('mm_query');
        $minecraft = $filters && is_string($get('mm_minecraft')) && $get('mm_minecraft') !== '' ? $get('mm_minecraft') : null;
        $loader = $filters && is_string($get('mm_loader')) && $get('mm_loader') !== '' ? $get('mm_loader') : null;
        $server = new Server();
        $key = 'modpack-catalog-table:'.hash('sha256', serialize([$server->getKey(), $provider->value, $query, 1, $minecraft, $loader]));
        $attributes = request()->attributes;
        if (!$attributes->has($key)) {
            $attributes->set($key, app(ModpackCatalog::class)->searchPage(
                $server,
                $provider,
                $query,
                1,
                $minecraft,
                $loader,
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
        if ($result->mode === ProvisioningMode::Archive && !CreateModpackServer::canStageBeforeWings()) {
            return trans('pelican-mod-manager::strings.modpacks.managed_panel_required');
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
            || (bool) $get('skip_scripts') !== ($result->mode === ProvisioningMode::Archive)
            || (self::requiresManualStart($result) && (bool) $get('start_on_completion'))) {
            throw self::startupValidationError($component);
        }

        $image = (string) $get('image');
        $loader = $result->loader ?? $result->selected?->loader;
        $environment = (array) $get('environment');
        $minecraft = $result->minecraftVersion;
        if ($result->mode === ProvisioningMode::Archive) {
            $minecraft = ModpackLoader::fixedMinecraftVersion($environment);
            if ($minecraft === null) {
                throw self::startupValidationError($component);
            }
        }
        if (JavaRuntimeImage::isTooOld($image, $minecraft, $result->minimumJava)) {
            throw ValidationException::withMessages([
                $component->resolveRelativeStatePath('image') => trans('pelican-mod-manager::strings.modpacks.java_too_old', [
                    'java' => JavaRuntimeImage::preferredLabel($minecraft, $result->minimumJava),
                ]),
            ]);
        }
        if (JavaRuntimeImage::isTooNew($image, $minecraft, $loader)) {
            throw ValidationException::withMessages([
                $component->resolveRelativeStatePath('image') => trans('pelican-mod-manager::strings.modpacks.java_too_new', [
                    'loader' => (string) $loader,
                    'java' => JavaRuntimeImage::maximumLabel($minecraft, $loader),
                ]),
            ]);
        }

        foreach ($result->variables as $name => $value) {
            if ($result->mode === ProvisioningMode::Archive && in_array($name, ['MC_VERSION', 'MINECRAFT_VERSION'], true)
                && !array_key_exists($name, $environment)) {
                continue;
            }
            if ((string) ($environment[$name] ?? '') !== $value) {
                throw self::startupValidationError($component);
            }
        }

        [$source, $project] = explode(':', (string) $get('mm_project'), 2);
        $descriptor = self::descriptor((string) $get('mm_project'), (string) $get('mm_version'));
        $title = trim((string) ($get('mm_project_title') ?: ($descriptor?->title ?? '')));
        $build = $descriptor?->loaderVersion;
        $startup = (string) $get('startup');
        if ($result->mode === ProvisioningMode::Archive) {
            $build ??= $loader === null ? null : ($environment[ModpackLoader::buildVariable($loader)] ?? null);
            $jar = $environment['SERVER_JARFILE'] ?? 'server.jar';
            try {
                ModpackLoaderInstaller::forRelease($loader ?? '', $minecraft, $build, $jar);
            } catch (ModpackException) {
                throw ValidationException::withMessages([
                    $component->resolveRelativeStatePath('mm_project') => trans('pelican-mod-manager::strings.modpacks.managed_runtime_required'),
                ]);
            }
            $startup = ModpackLoaderInstaller::startup($loader, $minecraft, $jar);
        }

        return [
            'mode' => $result->mode->value,
            'source' => $source,
            'project_id' => $project,
            'version_id' => (string) $get('mm_version'),
            'selection' => [
                'egg_id' => (string) $result->selected?->id,
                'image' => $image,
                'startup' => $startup,
                'minecraft' => $minecraft,
                'loader' => $loader,
                ...($result->mode === ProvisioningMode::Archive ? ['managed_initial' => true, 'loader_version' => $build] : []),
                'server_pack' => $result->serverFileStatus === 'server_pack_selected',
                'artifact_id' => $result->artifactId,
                ...($title === '' ? [] : ['title' => $title]),
                ...($result->minimumJava === null ? [] : ['minimum_java' => $result->minimumJava]),
                ...($result->manifestFingerprint === null ? [] : [
                    'manifest_fingerprint' => $result->manifestFingerprint,
                ]),
                'variables' => $result->mode === ProvisioningMode::Archive ? array_intersect_key(
                    $environment,
                    array_flip(['MINECRAFT_VERSION', 'MC_VERSION', 'LOADER_VERSION', 'FORGE_VERSION', 'NEOFORGE_VERSION', 'QUILT_VERSION', 'SERVER_JARFILE']),
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

    private static function requiresManualStart(CompatibilityResult $result): bool
    {
        return $result->mode === ProvisioningMode::Archive;
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
        $image = JavaRuntimeImage::fromEggImages($images, $result->minecraftVersion, $result->minimumJava, $result->loader ?? $result->selected?->loader);
        if ($image !== null) {
            $set('image', $image);
            $set('select_image', $image);
        }
        // Panel uses required ToggleButtons with numeric option keys.
        $set('skip_scripts', $result->mode === ProvisioningMode::Archive ? 1 : 0);
        if (self::requiresManualStart($result)) {
            $set('start_on_completion', 0);
        }
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
        if ($result->mode === ProvisioningMode::Archive && $result->minecraftVersion !== null && $result->loader !== null) {
            $set('startup', ModpackLoaderInstaller::startup($result->loader, $result->minecraftVersion, $environment['SERVER_JARFILE'] ?? 'server.jar'));
            $set('select_startup', 'custom');
        }
    }

    public static function evaluateSelection(string $project, string $version, mixed $explicitEggId = null): ?CompatibilityResult
    {
        if ($project === '' || $version === '') {
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
            $descriptor = self::descriptor($project, $version);
            if ($descriptor === null) {
                return null;
            }

            $result = app(ModpackProvisioningPlanner::class)->select(
                $descriptor,
                app(InstalledEggCatalog::class)->all(),
                EggCompatibilityEngine::CONTEXT_CREATE,
                null,
                [],
                $explicit,
                $explicit === null,
            );
            $attributes->set($key, $result);

            return $result;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private static function result(Get $get, mixed $explicitEggId): ?CompatibilityResult
    {
        $project = (string) $get('mm_project');
        $version = $get('mm_version');
        if (!(bool) $get('mm_modpack') || $project === '' || $version === null || $version === '') {
            return null;
        }

        return self::evaluateSelection($project, (string) $version, $explicitEggId);
    }

    private static function descriptor(string $project, string $version): ?ModpackDescriptor
    {
        $parts = explode(':', $project, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $key = 'modpack-creation-release:'.hash('sha256', serialize([$project, $version]));
        $attributes = request()->attributes;
        if (!$attributes->has($key)) {
            $attributes->set($key, app(ModpackCatalog::class)->describe(new Server(), $parts[0], $parts[1], $version));
        }

        return $attributes->get($key);
    }
}
