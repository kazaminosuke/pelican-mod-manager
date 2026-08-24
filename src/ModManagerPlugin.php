<?php

namespace Kazaminosuke\ModManager;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Models\Egg;
use App\Models\Server;
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
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Http\Middleware\ShiftCoreNavigationRows;
use Kazaminosuke\ModManager\Models\ModManagerEggProfile;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\CacheVersion;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use Kazaminosuke\ModManager\Support\NavigationSort;
use Kazaminosuke\ModManager\Support\ProjectIconUrl;

class ModManagerPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'pelican-minecraft-modrinth';
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
        $pageClasses = [ModManagerPage::class, MinecraftDatapackPage::class];
        $projectIconPlaceholder = json_encode(
            ProjectIconUrl::placeholderDataUri(),
            JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        );

        $panel->renderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            fn () => new HtmlString(
                '<div class="mmr-catalog-sort-toolbar" x-cloak x-show="$wire.activeTab !== \'installed\'">'
                .'<label for="mmr-catalog-sort" class="fi-sr-only">'.e(trans('pelican-minecraft-modrinth::strings.table.sort.label')).'</label>'
                .'<select id="mmr-catalog-sort" wire:model.live="catalogSort" class="mmr-catalog-sort-select">'
                .'<option value="downloads">'.e(trans('pelican-minecraft-modrinth::strings.table.sort.downloads')).'</option>'
                .'<option value="updated">'.e(trans('pelican-minecraft-modrinth::strings.table.sort.updated')).'</option>'
                .'<option value="popularity">'.e(trans('pelican-minecraft-modrinth::strings.table.sort.popularity')).'</option>'
                .'</select></div>',
            ),
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
                .'<style>'
                .'.mcloader-badge .fi-icon{width:1em!important;height:1em!important;}'
                // The mod/plugin table owns the rest of the screen, and the row
                // area alone scrolls, so the Minecraft Version/Loader/Installed
                // summary and the source tabs above it never move and the
                // paginator below never moves either.
                //
                // Filament's own table markup (verified against its 4.x blade
                // view) is .fi-ta > .fi-ta-ctn > .fi-ta-main, with the row
                // viewport, the empty state and nav.fi-pagination as siblings
                // inside .fi-ta-main. Turning that into a fixed-height flex
                // column is what pins the paginator: the row viewport absorbs
                // all the slack, so neither the row count, nor how much the
                // descriptions wrap, nor the paginator briefly disappearing
                // during a deferred load (Filament renders it only once
                // $records is a paginator) can move anything.
                //
                // This replaces a JS pass that measured document.scrollHeight
                // and window.scrollY after every render. That made the reserved
                // height a function of how far the page happened to be scrolled
                // at the time, and each pass altered the input to the next -
                // the reason the paginator's position drifted by hundreds of
                // pixels and varied between tabs.
                //
                // What CSS cannot derive comes from the table-layout partial:
                // where the table starts, how much page chrome sits below it,
                // and the viewport height. All three are measured rather than
                // assumed - a fixed 1.5rem guess for the trailing chrome left
                // the document 64px taller than the viewport, which brought the
                // page's own scrollbar back. Subtracting the measured value
                // instead makes the document exactly one viewport tall, so the
                // row area is the only thing that scrolls. The fallbacks only
                // have to keep the first paint sane.
                .'.mmr-table-scroll-ctn .fi-ta-main{'
                    .'display:flex;flex-direction:column;'
                    .'height:calc('
                        .'var(--mmr-viewport-height, 100dvh)'
                        .' - var(--mmr-table-top, 24rem)'
                        .' - var(--mmr-table-bottom, 2rem)'
                    .');'
                    .'min-height:15rem;'
                .'}'
                // A container-relative, square image footprint reserves space
                // before remote icons decode without introducing a fixed
                // pixel size. It scales with the actual table viewport.
                .'.mmr-table-scroll-ctn{container-type:inline-size;}'
                .'.mmr-table-scroll-ctn .mmr-project-icon-cell .fi-ta-image img{'
                    .'display:block;inline-size:4.5cqi;'
                    .'height:auto!important;block-size:auto!important;'
                    .'aspect-ratio:1;object-fit:cover;'
                .'}'
                // Header, toolbar, filter indicators and the paginator keep
                // their natural heights; only the row viewport flexes.
                .'.mmr-table-scroll-ctn .fi-ta-main>*{flex:0 0 auto;}'
                .'.mmr-table-scroll-ctn .fi-ta-content-ctn,'
                .'.mmr-table-scroll-ctn .fi-ta-empty-state{flex:1 1 auto;min-height:0;}'
                .'.mmr-table-scroll-ctn .fi-ta-content-ctn{overflow-y:auto;}'
                // Filament's paginator is a 1fr/auto/1fr grid. On this page
                // ->paginated([20]) renders no records-per-page selector, so
                // relying on auto-placement makes the count and page list trade
                // places as their contents change. Explicitly place the count in
                // the left equal track and the list in the central auto track.
                // Using minmax(0, 1fr), rather than a bare 1fr, prevents the
                // count's min-content width from making the two side tracks
                // unequal. The page list consequently stays centred regardless
                // of the count's length or whether it wraps.
                .'.mmr-table-scroll-ctn .fi-pagination{'
                    .'grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);'
                .'}'
                .'.mmr-table-scroll-ctn .fi-pagination-overview{'
                    .'grid-column:1;min-inline-size:0;'
                    // Filament's text-sm line-height is 1.25rem. Reserving two
                    // lines from the first paint means a long overview can wrap
                    // without changing the paginator's block size or moving the
                    // row viewport above it.
                    .'min-block-size:2.5rem;white-space:normal;'
                    // Do not allow arbitrary character breaks. The overview
                    // formatter below supplies Japanese phrase boundaries where
                    // they are useful, while the browser's ordinary strict
                    // Japanese line-breaking rules handle every other locale.
                    .'overflow-wrap:normal;word-break:normal;line-break:strict;'
                .'}'
                .'.mmr-table-scroll-ctn .mmr-pagination-overview-content{min-inline-size:0;}'
                .'.mmr-table-scroll-ctn .mmr-pagination-overview-chunk{white-space:nowrap;}'
                // The overview is hidden in Filament's compact paginator. Only
                // switch it to a grid at the same wide breakpoints where
                // Filament shows it, so a single line can sit vertically centred
                // within the reserved two-line box without changing compact
                // pagination's native visibility rules.
                .'@supports (container-type:inline-size){'
                    .'@container (min-width:56rem){'
                        .'.mmr-table-scroll-ctn .fi-pagination-overview{display:grid;align-content:center;}'
                    .'}'
                .'}'
                .'@supports not (container-type:inline-size){'
                    .'@media (min-width:48rem){'
                        .'.mmr-table-scroll-ctn .fi-pagination-overview{display:grid;align-content:center;}'
                    .'}'
                .'}'
                .'.mmr-table-scroll-ctn .fi-pagination-items{grid-column:2;justify-self:center;}'
                // Filament conditionally omits the first/previous and
                // next/last <li>s. The table-layout script supplies a complete
                // native disabled item for a missing edge, avoiding a computed
                // spacer while making both navigation directions explicit.
                .'.mmr-table-scroll-ctn .mmr-pagination-placeholder{pointer-events:none;}'
                // The placeholder clones the available opposite-direction
                // icon, so only its glyph is mirrored; Filament's button size,
                // border, corner and disabled colours stay untouched.
                .'.mmr-table-scroll-ctn .mmr-pagination-placeholder-icon{transform:scaleX(-1);}'
                .'.mmr-catalog-sort-toolbar{width:12rem;min-width:10rem;}'
                .'.mmr-catalog-sort-select{display:block;width:100%;min-height:2.25rem;border-radius:.5rem;border:1px solid rgb(209 213 219);background:#fff;padding:.5rem .75rem;color:rgb(17 24 39);font-size:.875rem;line-height:1.25rem;}'
                .'.dark .mmr-catalog-sort-select{border-color:rgb(75 85 99);background:rgb(31 41 55);color:#fff;}'
                // Keeps the column header row (Title/Author/Downloads/Modified)
                // pinned to the top of that scrolling area as rows scroll past
                // underneath it; its own background (set by Filament) keeps rows
                // from showing through.
                .'.mmr-table-scroll-ctn .fi-ta-table>thead{position:sticky;top:0;z-index:1;}'
                .$this->catalogRowActionCss()
                .$this->catalogStatIconCss()
                // TextEntry exposes no extraImgAttributes()-style hook for
                // its icon, so this class goes on the entry's own wrapper
                // (via ->extraAttributes()) and reaches the icon - rendered
                // by Filament's generate_icon_html() as an inline <svg
                // class="fi-icon ...">, same as the .mcloader-badge rule
                // above - through a descendant selector instead. Matches
                // Filament's own .fi-loading-indicator (motion-safe:animate-spin)
                // in respecting a reduced-motion preference.
                .'@keyframes mmr-spin{to{transform:rotate(360deg);}}'
                .'@media (prefers-reduced-motion: no-preference){.mmr-installed-operation-spinning .fi-icon{animation:mmr-spin 1s linear infinite;}}'
                .'</style>'
                // Capture-phase error events reach this listener even though
                // image errors do not bubble. Keeping it once in HEAD removes
                // a large inline onerror attribute from every table row and
                // also works when Livewire changes the src on the same node.
                .'<script data-navigate-once>'
                    .'(()=>{'
                        .'if(window.__mmrProjectIconFallbackListener){return;}'
                        .'window.__mmrProjectIconFallbackListener=true;'
                        .'const placeholder='.$projectIconPlaceholder.';'
                        .'document.addEventListener("error",(event)=>{'
                            .'const image=event.target;'
                            .'if(!(image instanceof HTMLImageElement)||!image.matches(".mmr-table-scroll-ctn .mmr-project-icon-cell .fi-ta-image img")){return;}'
                            .'const source=image.currentSrc||image.src;'
                            .'if(!source||source===placeholder){return;}'
                            .'image.src=placeholder;'
                        .'},true);'
                    .'})();'
                .'</script>'
            ),
            $pageClasses,
        );
        // Supplies --mmr-table-top for the flex layout above, and puts the
        // paginator's inline offset back after a morph strips it.
        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn () => view('pelican-minecraft-modrinth::components.table-layout'),
            $pageClasses,
        );
        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn () => view('pelican-minecraft-modrinth::components.table-swr-cache'),
            $pageClasses,
        );
        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn () => view('pelican-minecraft-modrinth::components.catalog-url-history'),
            $pageClasses,
        );
        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn () => config('pelican-minecraft-modrinth.debug_timing')
                ? view('pelican-minecraft-modrinth::components.performance-profiler')
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
            'latest_minecraft_version' => config('pelican-minecraft-modrinth.latest_minecraft_version', '26.1.2'),
            'mod_nav_sort' => config('pelican-minecraft-modrinth.navigation_sort.mod', 11),
            'plugin_nav_sort' => config('pelican-minecraft-modrinth.navigation_sort.plugin', 11),
            'datapack_nav_sort' => config('pelican-minecraft-modrinth.navigation_sort.datapack', 12),
            'curseforge_api_key' => config('pelican-minecraft-modrinth.curseforge_api_key'),
            'github_token' => config('pelican-minecraft-modrinth.github_token'),
            'allow_user_egg_profile_edit' => (bool) config('pelican-minecraft-modrinth.allow_user_egg_profile_edit', false),
            'allow_user_project_install' => (bool) config('pelican-minecraft-modrinth.allow_user_project_install', false),
            'allow_user_project_update' => (bool) config('pelican-minecraft-modrinth.allow_user_project_update', false),
            'allow_user_project_delete' => (bool) config('pelican-minecraft-modrinth.allow_user_project_delete', false),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            TextInput::make('latest_minecraft_version')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.latest_minecraft_version'))
                ->required()
                ->default(fn () => config('pelican-minecraft-modrinth.latest_minecraft_version', '26.1.2')),
            TextInput::make('mod_nav_sort')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.mod_nav_sort'))
                ->helperText(trans('pelican-minecraft-modrinth::strings.settings.nav_sort_helper'))
                ->required()
                ->integer()
                ->minValue(NavigationSort::MIN_VALUE)
                ->maxValue(NavigationSort::MAX_VALUE)
                ->default(fn () => config('pelican-minecraft-modrinth.navigation_sort.mod', 11)),
            TextInput::make('plugin_nav_sort')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.plugin_nav_sort'))
                ->helperText(trans('pelican-minecraft-modrinth::strings.settings.nav_sort_helper'))
                ->required()
                ->integer()
                ->minValue(NavigationSort::MIN_VALUE)
                ->maxValue(NavigationSort::MAX_VALUE)
                ->default(fn () => config('pelican-minecraft-modrinth.navigation_sort.plugin', 11)),
            TextInput::make('datapack_nav_sort')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.datapack_nav_sort'))
                ->helperText(trans('pelican-minecraft-modrinth::strings.settings.nav_sort_helper'))
                ->required()
                ->integer()
                ->minValue(NavigationSort::MIN_VALUE)
                ->maxValue(NavigationSort::MAX_VALUE)
                ->default(fn () => config('pelican-minecraft-modrinth.navigation_sort.datapack', 12)),
            TextInput::make('curseforge_api_key')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.curseforge_api_key'))
                ->helperText(trans('pelican-minecraft-modrinth::strings.settings.curseforge_api_key_helper'))
                ->password()
                ->revealable()
                ->default(fn () => config('pelican-minecraft-modrinth.curseforge_api_key')),
            TextInput::make('github_token')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.github_token'))
                ->helperText(trans('pelican-minecraft-modrinth::strings.settings.github_token_helper'))
                ->password()
                ->revealable()
                ->default(fn () => config('pelican-minecraft-modrinth.github_token')),
            // Stage 8: off by default (admin-only egg profile editing). See
            // ModManagerPage's manual-profile form for where this is
            // actually consulted, and EggProfileResolver's docblock for the
            // full auto-detection cascade this only ever supplements.
            Toggle::make('allow_user_egg_profile_edit')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.allow_user_egg_profile_edit'))
                ->helperText(trans('pelican-minecraft-modrinth::strings.settings.allow_user_egg_profile_edit_helper'))
                ->default(fn () => (bool) config('pelican-minecraft-modrinth.allow_user_egg_profile_edit', false)),
            Toggle::make('allow_user_project_install')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.allow_user_project_install'))
                ->helperText(trans('pelican-minecraft-modrinth::strings.settings.allow_user_project_install_helper'))
                ->default(fn () => (bool) config('pelican-minecraft-modrinth.allow_user_project_install', false)),
            Toggle::make('allow_user_project_update')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.allow_user_project_update'))
                ->helperText(trans('pelican-minecraft-modrinth::strings.settings.allow_user_project_update_helper'))
                ->default(fn () => (bool) config('pelican-minecraft-modrinth.allow_user_project_update', false)),
            Toggle::make('allow_user_project_delete')
                ->label(trans('pelican-minecraft-modrinth::strings.settings.allow_user_project_delete'))
                ->helperText(trans('pelican-minecraft-modrinth::strings.settings.allow_user_project_delete_helper'))
                ->default(fn () => (bool) config('pelican-minecraft-modrinth.allow_user_project_delete', false)),
            // A standalone action embedded in the settings form's schema
            // (rather than a plugin-settings form field) - it runs
            // independently of the "Save" submission that PluginResource
            // wires up around this whole schema, so clicking it doesn't
            // require or trigger a settings save.
            Actions::make([
                Action::make('clear_cache')
                    ->label(trans('pelican-minecraft-modrinth::strings.settings.clear_cache'))
                    ->color('danger')
                    ->icon('tabler-trash')
                    ->modalHeading(trans('pelican-minecraft-modrinth::strings.settings.clear_cache_confirmation_heading'))
                    ->modalDescription(trans('pelican-minecraft-modrinth::strings.settings.clear_cache_confirmation_description'))
                    // A schema already makes this action open a confirmation
                    // modal (with the heading/description above) before
                    // running, the same as requiresConfirmation() would - so
                    // that isn't also needed here, which would risk stacking
                    // a second, redundant confirmation step in front of it.
                    ->schema([
                        Select::make('server_id')
                            ->label(trans('pelican-minecraft-modrinth::strings.settings.clear_cache_server_label'))
                            ->native(false)
                            ->required()
                            ->default('all')
                            // Array union (+), NOT spread (...) - spread
                            // renumbers integer keys sequentially (0, 1, 2...)
                            // instead of preserving them, which would silently
                            // replace every server's real id with an unrelated
                            // sequential number as this Select's option value.
                            ->options(fn () => ['all' => trans('pelican-minecraft-modrinth::strings.settings.clear_cache_all_servers')]
                                + Server::query()->orderBy('name')->pluck('name', 'id')->all()),
                    ])
                    ->action(function (array $data) {
                        $service = app(InstalledProjectService::class);
                        $operations = app(InstalledOperationManager::class);
                        /** @var DaemonFileRepository $fileRepository */
                        $fileRepository = app(DaemonFileRepository::class);

                        if (($data['server_id'] ?? 'all') === 'all') {
                            self::clearAllServers($service, $fileRepository);

                            return;
                        }

                        self::clearSingleServer($service, $fileRepository, $operations, (int) $data['server_id']);
                    }),
            ])->belowContent(trans('pelican-minecraft-modrinth::strings.settings.clear_cache_helper')),
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
                    ->label(trans('pelican-minecraft-modrinth::strings.settings.egg_profiles'))
                    ->color('gray')
                    ->icon('tabler-egg')
                    ->modalHeading(trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_confirmation_heading'))
                    ->modalDescription(trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_confirmation_description'))
                    ->schema(self::eggProfileFormSchema())
                    ->action(fn (array $data) => self::saveEggProfile($data)),
            ])->belowContent(trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_helper')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'LATEST_MINECRAFT_VERSION' => $data['latest_minecraft_version'],
            'MINECRAFT_MODRINTH_MOD_NAV_SORT' => $data['mod_nav_sort'],
            'MINECRAFT_MODRINTH_PLUGIN_NAV_SORT' => $data['plugin_nav_sort'],
            'MINECRAFT_MODRINTH_DATAPACK_NAV_SORT' => $data['datapack_nav_sort'],
            'CURSEFORGE_API_KEY' => $data['curseforge_api_key'] ?? '',
            'GITHUB_TOKEN' => $data['github_token'] ?? '',
            'MOD_MANAGER_ALLOW_USER_EGG_PROFILE_EDIT' => ($data['allow_user_egg_profile_edit'] ?? false) ? 'true' : 'false',
            'MOD_MANAGER_ALLOW_USER_PROJECT_INSTALL' => ($data['allow_user_project_install'] ?? false) ? 'true' : 'false',
            'MOD_MANAGER_ALLOW_USER_PROJECT_UPDATE' => ($data['allow_user_project_update'] ?? false) ? 'true' : 'false',
            'MOD_MANAGER_ALLOW_USER_PROJECT_DELETE' => ($data['allow_user_project_delete'] ?? false) ? 'true' : 'false',
        ]);

        Notification::make()
            ->title(trans('pelican-minecraft-modrinth::strings.settings.settings_saved'))
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
                ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_egg_label'))
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
            ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_project_type_label'))
            ->native(false)
            ->required()
            ->options(fn (): array => [
                'auto' => trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_project_type_auto'),
                ProjectType::Mod->value => ProjectType::Mod->getLabel(),
                ProjectType::Plugin->value => ProjectType::Plugin->getLabel(),
                ProjectType::Datapack->value => ProjectType::Datapack->getLabel(),
            ])
            ->default('auto');
        $fields[] = Select::make('loader')
            ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_loader_label'))
            ->native(false)
            ->options(fn (): array => ['' => trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_loader_none')]
                + collect(MinecraftLoader::cases())->mapWithKeys(fn (MinecraftLoader $loader) => [$loader->value => $loader->getLabel()])->all())
            ->default('');
        $fields[] = TextInput::make('minecraft_version')
            ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_minecraft_version_label'))
            ->helperText(fn (): string => trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_minecraft_version_helper'));
        $fields[] = Toggle::make('supports_datapacks')
            ->label(fn (): string => trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_supports_datapacks_label'));

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
                ->title(trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_removed', ['egg' => $egg->name]))
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
            ->title(trans('pelican-minecraft-modrinth::strings.settings.egg_profiles_saved', ['egg' => $egg->name]))
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
     * next time an applicable Mod/Plugin/Datapack Installed tab is opened.
     */
    private static function clearAllServers(InstalledProjectService $service, DaemonFileRepository $fileRepository): void
    {
        $clearedServers = [];
        $failureCount = 0;

        // Metadata deletion needs each server's egg loaded to resolve its
        // project type. This query is intentionally infrequent and lets each
        // successful delete invalidate only the affected server.
        foreach (Server::query()->with('egg')->get() as $server) {
            try {
                $type = ProjectType::fromServer($server);

                if ($type) {
                    $service->clearInstalledModsMetadata($server, $fileRepository, $type);
                    $clearedServers[$server->getKey()] = true;
                }

                if (ProjectType::supportsDatapacks($server)) {
                    $service->clearInstalledModsMetadata($server, $fileRepository, ProjectType::Datapack);
                    $clearedServers[$server->getKey()] = true;
                }
            } catch (Exception $exception) {
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
                ->title(trans('pelican-minecraft-modrinth::strings.notifications.reset_metadata_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('pelican-minecraft-modrinth::strings.settings.cache_cleared', ['count' => count($clearedServers)]))
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
        InstalledProjectService $service,
        DaemonFileRepository $fileRepository,
        InstalledOperationManager $operations,
        int $serverId,
    ): void {
        if (!$operations->supportsAsyncDispatch()) {
            Notification::make()
                ->title(trans('pelican-minecraft-modrinth::strings.operations.queue_required'))
                ->danger()
                ->send();

            return;
        }

        $server = Server::query()->with('egg')->find($serverId);

        if (!$server) {
            Notification::make()
                ->title(trans('pelican-minecraft-modrinth::strings.notifications.reset_metadata_failed'))
                ->danger()
                ->send();

            return;
        }

        try {
            $type = ProjectType::fromServer($server);

            if ($type) {
                $scanState = $operations->state($server, $type, InstalledOperationManager::OPERATION_SCAN);
                if (!$scanState?->isActive()) {
                    $service->clearInstalledModsMetadata($server, $fileRepository, $type);
                    $dispatch = $operations->dispatchScan(
                        $server,
                        $type,
                        force: true,
                        actorUserId: is_numeric(user()?->getKey()) ? (int) user()->getKey() : null,
                    );
                    if (!$dispatch['dispatched'] && $dispatch['reason'] !== 'already_active') {
                        throw new Exception('Failed to dispatch installed scan.');
                    }
                }
            }

            if (ProjectType::supportsDatapacks($server)) {
                $datapackState = $operations->state($server, ProjectType::Datapack, InstalledOperationManager::OPERATION_SCAN);
                if (!$datapackState?->isActive()) {
                    $service->clearInstalledModsMetadata($server, $fileRepository, ProjectType::Datapack);
                    $dispatch = $operations->dispatchScan(
                        $server,
                        ProjectType::Datapack,
                        force: true,
                        actorUserId: is_numeric(user()?->getKey()) ? (int) user()->getKey() : null,
                    );
                    if (!$dispatch['dispatched'] && $dispatch['reason'] !== 'already_active') {
                        throw new Exception('Failed to dispatch datapack scan.');
                    }
                }
            }
        } catch (Exception $exception) {
            report($exception);

            Notification::make()
                ->title(trans('pelican-minecraft-modrinth::strings.notifications.reset_metadata_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('pelican-minecraft-modrinth::strings.settings.cache_cleared_single', ['name' => $server->name]))
            ->success()
            ->send();
    }

    /**
     * Icons for catalog row actions are CSS masks defined once, not inline
     * SVGs copied onto every Filament icon button.
     */
    private function catalogRowActionCss(): string
    {
        $masks = [
            'versions' => ['M9 6l11 0', 'M9 12l11 0', 'M9 18l11 0', 'M5 6l0 .01', 'M5 12l0 .01', 'M5 18l0 .01'],
            'install_latest' => ['M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2', 'M7 11l5 5l5 -5', 'M12 4l0 12'],
            'update' => ['M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4', 'M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4'],
            'installed' => ['M5 12l5 5l10 -10'],
            'uninstall' => ['M4 7l16 0', 'M10 11l0 6', 'M14 11l0 6', 'M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12', 'M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3'],
        ];

        $maskRules = '';
        foreach ($masks as $name => $paths) {
            $pathMarkup = '';
            foreach ($paths as $path) {
                $pathMarkup .= '<path d="'.$path.'"/>';
            }

            $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>{$pathMarkup}</svg>";
            $maskRules .= '.mmr-table-scroll-ctn .mmr-row-action[data-mmr-swr-row-action="'.$name.'"]{--mmr-row-action-mask:url("data:image/svg+xml,'.rawurlencode($svg).'");}';
        }

        return
            '.mmr-table-scroll-ctn .mmr-row-action{'
                .'display:inline-flex;align-items:center;justify-content:center;'
                .'width:2.25rem;height:2.25rem;padding:0;border:0;background:transparent;cursor:pointer;color:inherit;'
            .'}'
            .'.mmr-table-scroll-ctn .mmr-row-action.fi-disabled{opacity:.55;cursor:default;}'
            .'.mmr-table-scroll-ctn .mmr-row-action[data-mmr-swr-row-action-color="info"]{color:rgb(59 130 246);}'
            .'.mmr-table-scroll-ctn .mmr-row-action[data-mmr-swr-row-action-color="success"]{color:rgb(16 185 129);}'
            .'.mmr-table-scroll-ctn .mmr-row-action[data-mmr-swr-row-action-color="warning"]{color:rgb(245 158 11);}'
            .'.mmr-table-scroll-ctn .mmr-row-action[data-mmr-swr-row-action-color="danger"]{color:rgb(239 68 68);}'
            .'.dark .mmr-table-scroll-ctn .mmr-row-action[data-mmr-swr-row-action-color="info"]{color:rgb(96 165 250);}'
            .'.dark .mmr-table-scroll-ctn .mmr-row-action[data-mmr-swr-row-action-color="success"]{color:rgb(52 211 153);}'
            .'.dark .mmr-table-scroll-ctn .mmr-row-action[data-mmr-swr-row-action-color="warning"]{color:rgb(251 191 36);}'
            .'.dark .mmr-table-scroll-ctn .mmr-row-action[data-mmr-swr-row-action-color="danger"]{color:rgb(248 113 113);}'
            .'.mmr-table-scroll-ctn .mmr-row-action-icon{'
                .'display:block;width:1.25rem;height:1.25rem;background:currentColor;'
                .'-webkit-mask:var(--mmr-row-action-mask) center/contain no-repeat;'
                .'mask:var(--mmr-row-action-mask) center/contain no-repeat;'
            .'}'
            .$maskRules;
    }

    /**
     * Downloads / Modified column icons are CSS masks, not a Tabler SVG
     * copied into every catalog row.
     */
    private function catalogStatIconCss(): string
    {
        $masks = [
            'downloads' => ['M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2', 'M7 11l5 5l5 -5', 'M12 4l0 12'],
            'calendar' => ['M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z', 'M16 3v4', 'M8 3v4', 'M4 11h16', 'M11 15h1', 'M12 15v3'],
        ];

        $maskRules = '';
        foreach ($masks as $name => $paths) {
            $pathMarkup = '';
            foreach ($paths as $path) {
                $pathMarkup .= '<path d="'.$path.'"/>';
            }

            $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>{$pathMarkup}</svg>";
            $maskRules .= '.mmr-table-scroll-ctn .mmr-stat-icon[data-mmr-stat-icon="'.$name.'"]{--mmr-stat-icon-mask:url("data:image/svg+xml,'.rawurlencode($svg).'");}';
        }

        return
            '.mmr-table-scroll-ctn .mmr-stat-icon{'
                .'display:inline-block;width:1rem;height:1rem;flex:0 0 1rem;background:currentColor;vertical-align:-0.125em;'
                .'-webkit-mask:var(--mmr-stat-icon-mask) center/contain no-repeat;'
                .'mask:var(--mmr-stat-icon-mask) center/contain no-repeat;'
            .'}'
            .$maskRules;
    }
}
