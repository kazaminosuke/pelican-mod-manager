<?php

namespace Kazaminosuke\ModManager;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Models\Egg;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\EnvironmentWriterTrait;
use BladeUI\Icons\Factory as BladeIconsFactory;
use Exception;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Filament\Admin\ServerModManagerTab;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftDatapackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftResourcePackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Http\Middleware\ShiftCoreNavigationRows;
use Kazaminosuke\ModManager\Models\ModManagerEggProfile;
use Kazaminosuke\ModManager\Services\InstalledMetadataResetService;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\CacheVersion;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use Kazaminosuke\ModManager\Support\ModManagerAssets;
use Kazaminosuke\ModManager\Support\NavigationSort;
use Kazaminosuke\ModManager\Support\ProjectIconUrl;

class ModManagerPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'pelican-mod-manager';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        if ($panel->getId() === 'admin') {
            EditServer::registerCustomTabs(TabPosition::After, ServerModManagerTab::make());

            return;
        }

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Kazaminosuke\\ModManager\\Filament\\$id\\Pages");

        // The manager pages' sidebar rows are claimed through the tenant
        // middleware below: the admin tab presents a display-order value as
        // an absolute row (10 = tenth entry from the top), so any core page
        // occupying that number (Startup 10, Settings/Webhooks 11) is pushed
        // down before the navigation renders. The pages themselves mount
        // their items the stock Filament way.
        if ($panel->getId() === 'server') {
            $panel->tenantMiddleware([ShiftCoreNavigationRows::class]);
        }

        app(BladeIconsFactory::class)->add('mcloader', [
            'path' => plugin_path($this->getId(), 'resources/icons/loaders'),
            'prefix' => 'mcloader',
        ]);

        // Filament's render hook scoping matches the exact runtime class
        // (BasePage::getRenderHookScopes() returns [static::class], compared
        // by string key - see ViewManager::renderHook()), not instanceof. A
        // scope of ModManagerPage::class alone therefore never fires on
        // MinecraftDatapackPage even though it extends ModManagerPage and
        // renders through the exact same content()/table. Every hook below
        // that targets this page's own table chrome must list every
        // concrete page class it needs to appear on.
        $pageClasses = [ModManagerPage::class, MinecraftDatapackPage::class, MinecraftResourcePackPage::class];
        $projectIconPlaceholder = e(ProjectIconUrl::placeholderDataUri());
        $switchToListLabel = trans('pelican-mod-manager::strings.table.view.switch_to_list');
        $switchToPanelLabel = trans('pelican-mod-manager::strings.table.view.switch_to_panel');
        $viewToggle = fn (): string => Action::make('catalogViewToggle')
            ->iconButton()
            ->color('gray')
            ->label($switchToPanelLabel)
            // Use the same Action/icon-button rendering path as Filament's
            // filter and column-manager triggers. The runtime only swaps the
            // SVG paths when the client-only view state changes; it never
            // replaces or repositions the button/icon DOM.
            ->icon('tabler-layout-grid')
            // The actual view change is handled by the external runtime so it
            // can remain client-only and preserve localStorage without a
            // Livewire request. A non-empty Alpine handler disables the
            // default mountAction wire click on this standalone Action.
            ->alpineClickHandler('null')
            ->extraAttributes([
                'class' => 'fi-force-enabled',
                'data-mmr-view-toggle' => true,
                'data-mmr-view-list-label' => $switchToListLabel,
                'data-mmr-view-panel-label' => $switchToPanelLabel,
                'title' => $switchToPanelLabel,
                'x-cloak' => true,
                'x-show' => '$wire.activeTab !== \'installed\'',
            ])
            ->toHtml();

        $panel->renderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            fn () => new HtmlString(
                '<div class="mmr-catalog-sort-toolbar" x-cloak x-show="$wire.activeTab !== \'installed\'">'
                .'<label for="mmr-catalog-sort" class="fi-sr-only">'.e(trans('pelican-mod-manager::strings.table.sort.label')).'</label>'
                .'<select id="mmr-catalog-sort" wire:model.live="catalogSort" class="mmr-catalog-sort-select">'
                .'<template x-for="([value, label]) in Object.entries($wire.catalogSortOptions || {})" :key="value">'
                .'<option :value="value" x-text="label"></option>'
                .'</template>'
                .'</select></div>',
            ),
            $pageClasses,
        );
        // Filament renders this hook inside the same toolbar group, directly
        // before its column-manager trigger. This gives us the required DOM
        // order without CSS ordering or client-side node movement.
        $panel->renderHook(
            TablesRenderHook::TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE,
            fn () => new HtmlString($viewToggle()),
            $pageClasses,
        );
        $panel->renderHook(
            PanelsRenderHook::HEAD_END,
            fn () => new HtmlString(
                '<link rel="preconnect" href="https://cdn.modrinth.com" crossorigin>'
                .'<link rel="preconnect" href="https://media.forgecdn.net" crossorigin>'
                .'<link rel="preconnect" href="https://hangarcdn.papermc.io" crossorigin>'
                .'<link rel="preconnect" href="https://avatars.githubusercontent.com" crossorigin>'
                .'<link rel="dns-prefetch" href="https://cdn.modrinth.com">'
                .'<link rel="dns-prefetch" href="https://media.forgecdn.net">'
                .'<link rel="dns-prefetch" href="https://hangarcdn.papermc.io">'
                .'<link rel="dns-prefetch" href="https://avatars.githubusercontent.com">'
                .'<link rel="stylesheet" href="'.e(ModManagerAssets::url('mod-manager.css')).'" data-navigate-track>',
            ),
            $pageClasses,
        );
        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn () => new HtmlString(
                '<script src="'.e(ModManagerAssets::url('mod-manager-runtime.js')).'"'
                    .' data-mmr-project-icon-placeholder="'.$projectIconPlaceholder.'" defer data-navigate-once></script>'
                .'<script src="'.e(ModManagerAssets::url('table-layout.js')).'" defer data-navigate-once></script>'
                .'<script src="'.e(ModManagerAssets::url('table-swr-cache.js')).'"'
                    .' data-mmr-project-icon-placeholder="'.$projectIconPlaceholder.'" defer data-navigate-once></script>'
                .'<script src="'.e(ModManagerAssets::url('catalog-url-history.js')).'" defer data-navigate-once></script>',
            ),
            $pageClasses,
        );

        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn () => config('pelican-mod-manager.debug_timing')
                ? view('pelican-mod-manager::components.performance-profiler')
                : '',
            $pageClasses,
        );

    }

    public function boot(Panel $panel): void {}

    /**
     * Return the current values for Filament's plugin settings slide-over.
     *
     * @return array<string, mixed>
     */
    public function getSettingsFormData(): array
    {
        return [
            'latest_minecraft_version' => config('pelican-mod-manager.latest_minecraft_version', '26.1.2'),
            'mod_nav_sort' => config('pelican-mod-manager.navigation_sort.mod', 11),
            'plugin_nav_sort' => config('pelican-mod-manager.navigation_sort.plugin', 11),
            'datapack_nav_sort' => config('pelican-mod-manager.navigation_sort.datapack', 12),
            'resourcepack_nav_sort' => config('pelican-mod-manager.navigation_sort.resourcepack', 13),
            'curseforge_api_key' => config('pelican-mod-manager.curseforge_api_key'),
            'github_token' => config('pelican-mod-manager.github_token'),
            'allow_user_egg_profile_edit' => (bool) config('pelican-mod-manager.allow_user_egg_profile_edit', false),
            'allow_user_project_install' => (bool) config('pelican-mod-manager.allow_user_project_install', false),
            'allow_user_project_update' => (bool) config('pelican-mod-manager.allow_user_project_update', false),
            'allow_user_project_delete' => (bool) config('pelican-mod-manager.allow_user_project_delete', false),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            TextInput::make('latest_minecraft_version')
                ->label(trans('pelican-mod-manager::strings.settings.latest_minecraft_version'))
                ->required()
                ->default(fn () => config('pelican-mod-manager.latest_minecraft_version', '26.1.2')),
            TextInput::make('mod_nav_sort')
                ->label(trans('pelican-mod-manager::strings.settings.mod_nav_sort'))
                ->helperText(trans('pelican-mod-manager::strings.settings.nav_sort_helper'))
                ->required()
                ->integer()
                ->minValue(NavigationSort::MIN_VALUE)
                ->maxValue(NavigationSort::MAX_VALUE)
                ->default(fn () => config('pelican-mod-manager.navigation_sort.mod', 11)),
            TextInput::make('plugin_nav_sort')
                ->label(trans('pelican-mod-manager::strings.settings.plugin_nav_sort'))
                ->helperText(trans('pelican-mod-manager::strings.settings.nav_sort_helper'))
                ->required()
                ->integer()
                ->minValue(NavigationSort::MIN_VALUE)
                ->maxValue(NavigationSort::MAX_VALUE)
                ->default(fn () => config('pelican-mod-manager.navigation_sort.plugin', 11)),
            TextInput::make('datapack_nav_sort')
                ->label(trans('pelican-mod-manager::strings.settings.datapack_nav_sort'))
                ->helperText(trans('pelican-mod-manager::strings.settings.nav_sort_helper'))
                ->required()
                ->integer()
                ->minValue(NavigationSort::MIN_VALUE)
                ->maxValue(NavigationSort::MAX_VALUE)
                ->default(fn () => config('pelican-mod-manager.navigation_sort.datapack', 12)),
            TextInput::make('resourcepack_nav_sort')
                ->label(trans('pelican-mod-manager::strings.settings.resourcepack_nav_sort'))
                ->helperText(trans('pelican-mod-manager::strings.settings.nav_sort_helper'))
                ->required()
                ->integer()
                ->minValue(NavigationSort::MIN_VALUE)
                ->maxValue(NavigationSort::MAX_VALUE)
                ->default(fn () => config('pelican-mod-manager.navigation_sort.resourcepack', 13)),
            TextInput::make('curseforge_api_key')
                ->label(trans('pelican-mod-manager::strings.settings.curseforge_api_key'))
                ->helperText(trans('pelican-mod-manager::strings.settings.curseforge_api_key_helper'))
                ->password()
                ->revealable()
                ->default(fn () => config('pelican-mod-manager.curseforge_api_key')),
            TextInput::make('modrinth_token')
                ->label(trans('pelican-mod-manager::strings.settings.modrinth_token'))
                ->helperText(trans('pelican-mod-manager::strings.settings.modrinth_token_helper'))
                ->password()
                ->revealable()
                ->default(fn () => config('pelican-mod-manager.modrinth_token')),
            TextInput::make('hangar_api_key')
                ->label(trans('pelican-mod-manager::strings.settings.hangar_api_key'))
                ->helperText(trans('pelican-mod-manager::strings.settings.hangar_api_key_helper'))
                ->password()
                ->revealable()
                ->default(fn () => config('pelican-mod-manager.hangar_api_key')),
            TextInput::make('github_token')
                ->label(trans('pelican-mod-manager::strings.settings.github_token'))
                ->helperText(trans('pelican-mod-manager::strings.settings.github_token_helper'))
                ->password()
                ->revealable()
                ->default(fn () => config('pelican-mod-manager.github_token')),
            // Stage 8: off by default (admin-only egg profile editing). See
            // ModManagerPage's manual-profile form for where this is
            // actually consulted, and EggProfileResolver's docblock for the
            // full auto-detection cascade this only ever supplements.
            Toggle::make('allow_user_egg_profile_edit')
                ->label(trans('pelican-mod-manager::strings.settings.allow_user_egg_profile_edit'))
                ->helperText(trans('pelican-mod-manager::strings.settings.allow_user_egg_profile_edit_helper'))
                ->default(fn () => (bool) config('pelican-mod-manager.allow_user_egg_profile_edit', false)),
            Toggle::make('allow_user_project_install')
                ->label(trans('pelican-mod-manager::strings.settings.allow_user_project_install'))
                ->helperText(trans('pelican-mod-manager::strings.settings.allow_user_project_install_helper'))
                ->default(fn () => (bool) config('pelican-mod-manager.allow_user_project_install', false)),
            Toggle::make('allow_user_project_update')
                ->label(trans('pelican-mod-manager::strings.settings.allow_user_project_update'))
                ->helperText(trans('pelican-mod-manager::strings.settings.allow_user_project_update_helper'))
                ->default(fn () => (bool) config('pelican-mod-manager.allow_user_project_update', false)),
            Toggle::make('allow_user_project_delete')
                ->label(trans('pelican-mod-manager::strings.settings.allow_user_project_delete'))
                ->helperText(trans('pelican-mod-manager::strings.settings.allow_user_project_delete_helper'))
                ->default(fn () => (bool) config('pelican-mod-manager.allow_user_project_delete', false)),
            // A standalone action embedded in the settings form's schema
            // (rather than a plugin-settings form field) - it runs
            // independently of the "Save" submission that PluginResource
            // wires up around this whole schema, so clicking it doesn't
            // require or trigger a settings save.
            Actions::make([
                Action::make('clear_cache')
                    ->label(trans('pelican-mod-manager::strings.settings.clear_cache'))
                    ->color('danger')
                    ->icon('tabler-trash')
                    ->modalHeading(trans('pelican-mod-manager::strings.settings.clear_cache_confirmation_heading'))
                    ->modalDescription(trans('pelican-mod-manager::strings.settings.clear_cache_confirmation_description'))
                    // A schema already makes this action open a confirmation
                    // modal (with the heading/description above) before
                    // running, the same as requiresConfirmation() would - so
                    // that isn't also needed here, which would risk stacking
                    // a second, redundant confirmation step in front of it.
                    ->schema([
                        Select::make('server_id')
                            ->label(trans('pelican-mod-manager::strings.settings.clear_cache_server_label'))
                            ->native(false)
                            ->required()
                            ->default('all')
                            // Array union (+), NOT spread (...) - spread
                            // renumbers integer keys sequentially (0, 1, 2...)
                            // instead of preserving them, which would silently
                            // replace every server's real id with an unrelated
                            // sequential number as this Select's option value.
                            ->options(fn () => ['all' => trans('pelican-mod-manager::strings.settings.clear_cache_all_servers')]
                                + Server::query()->orderBy('name')->pluck('name', 'id')->all()),
                    ])
                    ->action(function (array $data) {
                        $resets = app(InstalledMetadataResetService::class);
                        $operations = app(InstalledOperationManager::class);
                        /** @var DaemonFileRepository $fileRepository */
                        $fileRepository = app(DaemonFileRepository::class);
                        $actor = user();
                        $actor = $actor instanceof User ? $actor : null;

                        if (($data['server_id'] ?? 'all') === 'all') {
                            self::clearAllServers($resets, $fileRepository, $actor);

                            return;
                        }

                        self::clearSingleServer($operations, (int) $data['server_id'], $actor);
                    }),
            ])->belowContent(trans('pelican-mod-manager::strings.settings.clear_cache_helper')),
            // Stage 8's admin-facing half of the GUI fallback: always
            // available regardless of the allow_user_egg_profile_edit
            // toggle above (this action is on the plugin settings screen,
            // which PluginResource already restricts to
            // user()?->can('update', $plugin) - no separate permission
            // check is needed here). Saves/clears one row in
            // Models\ModManagerEggProfile, keyed by egg - see that model's
            // docblock for why per-egg rather than per-server.
            Actions::make([
                Action::make('egg_profiles')
                    ->label(trans('pelican-mod-manager::strings.settings.egg_profiles'))
                    ->color('gray')
                    ->icon('tabler-egg')
                    ->modalHeading(trans('pelican-mod-manager::strings.settings.egg_profiles_confirmation_heading'))
                    ->modalDescription(trans('pelican-mod-manager::strings.settings.egg_profiles_confirmation_description'))
                    ->schema(self::eggProfileFormSchema())
                    ->action(fn (array $data) => self::saveEggProfile($data)),
            ])->belowContent(trans('pelican-mod-manager::strings.settings.egg_profiles_helper')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'MOD_MANAGER_LATEST_MINECRAFT_VERSION' => $data['latest_minecraft_version'],
            'MOD_MANAGER_MOD_NAV_SORT' => $data['mod_nav_sort'],
            'MOD_MANAGER_PLUGIN_NAV_SORT' => $data['plugin_nav_sort'],
            'MOD_MANAGER_DATAPACK_NAV_SORT' => $data['datapack_nav_sort'],
            'MOD_MANAGER_RESOURCEPACK_NAV_SORT' => $data['resourcepack_nav_sort'],
            'MOD_MANAGER_CURSEFORGE_API_KEY' => $data['curseforge_api_key'] ?? '',
            'MOD_MANAGER_MODRINTH_TOKEN' => $data['modrinth_token'] ?? '',
            'MOD_MANAGER_HANGAR_API_KEY' => $data['hangar_api_key'] ?? '',
            'MOD_MANAGER_GITHUB_TOKEN' => $data['github_token'] ?? '',
            'MOD_MANAGER_ALLOW_USER_EGG_PROFILE_EDIT' => ($data['allow_user_egg_profile_edit'] ?? false) ? 'true' : 'false',
            'MOD_MANAGER_ALLOW_USER_PROJECT_INSTALL' => ($data['allow_user_project_install'] ?? false) ? 'true' : 'false',
            'MOD_MANAGER_ALLOW_USER_PROJECT_UPDATE' => ($data['allow_user_project_update'] ?? false) ? 'true' : 'false',
            'MOD_MANAGER_ALLOW_USER_PROJECT_DELETE' => ($data['allow_user_project_delete'] ?? false) ? 'true' : 'false',
        ]);

        Notification::make()
            ->title(trans('pelican-mod-manager::strings.settings.settings_saved'))
            ->success()
            ->send();
    }

    /**
     * Shared between the admin settings action here and
     * ModManagerPage::eggProfileFormSchema() (Stage 8's user-facing half,
     * gated separately - see that method). The egg_id field itself is only
     * meaningful here, where any egg can be picked; the server-side form
     * fixes it to the current server's own egg instead.
     *
     * @return array<int, mixed>
     */
    public static function eggProfileFormSchema(bool $includeEggSelect = true): array
    {
        $fields = [];

        if ($includeEggSelect) {
            $fields[] = Select::make('egg_id')
                ->label(fn (): string => trans('pelican-mod-manager::strings.settings.egg_profiles_egg_label'))
                ->native(false)
                ->required()
                ->searchable()
                ->live()
                ->options(fn () => Egg::query()->orderBy('name')->pluck('name', 'id'))
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    self::fillEggProfileDefaults($state ? Egg::query()->find($state) : null, $set);
                });
        }

        $fields[] = Select::make('project_type')
            ->label(fn (): string => trans('pelican-mod-manager::strings.settings.egg_profiles_project_type_label'))
            ->native(false)
            ->required()
            ->options(fn (): array => [
                'auto' => trans('pelican-mod-manager::strings.settings.egg_profiles_project_type_auto'),
                ProjectType::Mod->value => ProjectType::Mod->getLabel(),
                ProjectType::Plugin->value => ProjectType::Plugin->getLabel(),
                ProjectType::Datapack->value => ProjectType::Datapack->getLabel(),
            ])
            ->default('auto');
        $fields[] = Select::make('loader')
            ->label(fn (): string => trans('pelican-mod-manager::strings.settings.egg_profiles_loader_label'))
            ->native(false)
            ->options(fn (): array => ['' => trans('pelican-mod-manager::strings.settings.egg_profiles_loader_none')]
                + collect(MinecraftLoader::cases())->mapWithKeys(fn (MinecraftLoader $loader) => [$loader->value => $loader->getLabel()])->all())
            ->default('');
        $fields[] = TextInput::make('minecraft_version')
            ->label(fn (): string => trans('pelican-mod-manager::strings.settings.egg_profiles_minecraft_version_label'))
            ->helperText(fn (): string => trans('pelican-mod-manager::strings.settings.egg_profiles_minecraft_version_helper'));
        $fields[] = Toggle::make('supports_datapacks')
            ->label(fn (): string => trans('pelican-mod-manager::strings.settings.egg_profiles_supports_datapacks_label'));

        return $fields;
    }

    /** Prefills the form with an existing manual profile, or - failing that - the plugin's own current best-effort guess for the egg, purely as a convenience default. */
    public static function fillEggProfileDefaults(?Egg $egg, Set $set): void
    {
        foreach (self::eggProfileDefaults($egg) as $field => $value) {
            $set($field, $value);
        }
    }

    /**
     * The plain-array counterpart to fillEggProfileDefaults(): Filament's
     * Set utility only exists inside a live form's reactive context (an
     * ->afterStateUpdated() callback), whereas an Action's ->fillForm()
     * closure just returns the array of initial values directly - this is
     * what ModManagerPage's server-scoped form (fixed to one egg, so it
     * never needs the reactive egg_id-changed case fillEggProfileDefaults()
     * exists for) uses instead.
     *
     * @return array{project_type: string, loader: string, minecraft_version: ?string, supports_datapacks: bool}
     */
    public static function eggProfileDefaults(?Egg $egg): array
    {
        if (!$egg) {
            return ['project_type' => 'auto', 'loader' => '', 'minecraft_version' => null, 'supports_datapacks' => false];
        }

        $manual = ModManagerEggProfile::query()->where('egg_id', $egg->getKey())->first();

        if ($manual) {
            return [
                'project_type' => $manual->project_type ?? 'auto',
                'loader' => $manual->loader ?? '',
                'minecraft_version' => $manual->minecraft_version,
                'supports_datapacks' => (bool) $manual->supports_datapacks,
            ];
        }

        $resolved = EggProfileResolver::resolveForEgg($egg);

        return [
            'project_type' => $resolved->status === 'resolved' && $resolved->projectType !== null ? $resolved->projectType : 'auto',
            'loader' => $resolved->loader ?? '',
            'minecraft_version' => null,
            'supports_datapacks' => $resolved->supportsDatapacks,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws Exception
     */
    public static function saveEggProfile(array $data): void
    {
        $egg = Egg::query()->find($data['egg_id'] ?? null);

        if (!$egg) {
            throw new Exception('Egg not found.');
        }

        if (($data['project_type'] ?? 'auto') === 'auto') {
            ModManagerEggProfile::query()->where('egg_id', $egg->getKey())->delete();

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.settings.egg_profiles_removed', ['egg' => $egg->name]))
                ->success()
                ->send();

            return;
        }

        ModManagerEggProfile::query()->updateOrCreate(
            ['egg_id' => $egg->getKey()],
            [
                'egg_uuid' => $egg->uuid,
                'project_type' => $data['project_type'],
                'loader' => ($data['loader'] ?? '') !== '' ? $data['loader'] : null,
                'minecraft_version' => ($data['minecraft_version'] ?? '') !== '' ? $data['minecraft_version'] : null,
                'supports_datapacks' => (bool) ($data['supports_datapacks'] ?? false),
            ],
        );

        Notification::make()
            ->title(trans('pelican-mod-manager::strings.settings.egg_profiles_saved', ['egg' => $egg->name]))
            ->success()
            ->send();
    }

    /**
     * Clears every server's installed-mods metadata/caches, plus the two
     * caches that aren't per-server at all (hydration display data has no
     * single global scope, but the Hangar hash-match cache does - see
     * CacheVersion). Deliberately does NOT re-scan every server
     * synchronously from this one request - doing that for every server,
     * each potentially hundreds of mods, in a single web request risks a
     * real timeout. Re-scanning instead happens lazily, the normal way, the
     * next time an applicable Mod/Plugin/Datapack Catalog or Installed view
     * is opened.
     */
    private static function clearAllServers(
        InstalledMetadataResetService $resets,
        DaemonFileRepository $fileRepository,
        ?User $actor,
    ): void {
        $clearedServers = [];
        $failureCount = 0;

        // Keep memory bounded on installations with many servers. Busy
        // server/type leases are skipped; the action never waits for an
        // install, update, uninstall, scan, or bulk update to finish.
        foreach (Server::query()->with('egg')->lazyById(100) as $server) {
            try {
                $result = $resets->clearWithoutScan(
                    $server,
                    $fileRepository,
                    self::installedMetadataTypes($server),
                    $actor,
                );

                if ($result['cleared_types'] !== []) {
                    $clearedServers[$server->getKey()] = true;
                }

                if ($result['status'] !== InstalledMetadataResetService::STATUS_CLEARED) {
                    $failureCount++;
                }
            } catch (\Throwable $exception) {
                report($exception);
                $failureCount++;
            }
        }

        // Each successful delete bumps its server hydration generation. The
        // shared Hangar generation must only move after at least one delete
        // succeeded; bumping it before the deletes would hide partial failure.
        if ($clearedServers !== []) {
            CacheVersion::bumpHangarHash();
        }

        if ($failureCount > 0) {
            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.reset_metadata_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('pelican-mod-manager::strings.settings.cache_cleared', ['count' => count($clearedServers)]))
            ->success()
            ->send();
    }

    /**
     * Clears one server and queues a fresh scan without blocking the settings
     * request. Deliberately
     * does not bump the Hangar hash-match cache (see CacheVersion) since
     * that cache isn't per-server - bumping it here would affect every
     * other server too, contradicting "just this one server".
     */
    private static function clearSingleServer(
        InstalledOperationManager $operations,
        int $serverId,
        ?User $actor,
    ): void {
        $server = Server::query()->with('egg')->find($serverId);

        if (!$server) {
            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.reset_metadata_failed'))
                ->danger()
                ->send();

            return;
        }

        $dispatch = $operations->dispatchMetadataReset(
            $server,
            self::installedMetadataTypes($server),
            actorUserId: $actor !== null ? (int) $actor->getKey() : null,
        );

        if (!$dispatch['dispatched'] && $dispatch['reason'] === 'sync_queue') {
            Notification::make()
                ->title(trans('pelican-mod-manager::strings.operations.queue_required'))
                ->danger()
                ->send();

            return;
        }

        if (!$dispatch['dispatched'] && $dispatch['reason'] !== 'no_types') {
            Notification::make()
                ->title(trans('pelican-mod-manager::strings.notifications.reset_metadata_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('pelican-mod-manager::strings.settings.cache_reset_queued', ['name' => $server->name]))
            ->success()
            ->send();
    }

    /** @return array<int, ProjectType> */
    private static function installedMetadataTypes(Server $server): array
    {
        $types = [];
        $primaryType = ProjectType::fromServer($server);

        if (in_array($primaryType, [ProjectType::Mod, ProjectType::Plugin], true)) {
            $types[] = $primaryType;
        }

        if (ProjectType::supportsDatapacks($server)) {
            $types[] = ProjectType::Datapack;
        }

        return $types;
    }
}
