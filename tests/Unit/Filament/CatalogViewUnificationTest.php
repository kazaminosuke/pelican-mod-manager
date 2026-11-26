<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use App\Models\Egg;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use BladeUI\Icons\Factory as BladeIconsFactory;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Table;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftDatapackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftResourcePackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Providers\ModManagerServiceProvider;
use Kazaminosuke\ModManager\Support\CatalogTileColumns;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
final class CatalogViewUnificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'session.driver' => 'array',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'pelican-mod-manager.curseforge_api_key' => 'test-curseforge-credential',
            'pelican-mod-manager.egg_autodetect_enabled' => true,
            'pelican-mod-manager.warm_catalog_enabled' => false,
        ]);

        $this->app->register(ModManagerServiceProvider::class);
        $root = dirname(__DIR__, 3);
        View::addNamespace('pelican-mod-manager', $root.'/resources/views');
        $this->app['translator']->addNamespace('pelican-mod-manager', $root.'/lang');

        if ($this->app->bound(BladeIconsFactory::class)) {
            $factory = $this->app->make(BladeIconsFactory::class);
            assert($factory instanceof BladeIconsFactory);
            $factory->add('mcloader', [
                'path' => $root.'/resources/icons/loaders',
                'prefix' => 'mcloader',
            ]);
        }

        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        $this->createTestSchema();

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            static fn () => View::file($root.'/resources/views/components/catalog-sort-select.blade.php'),
            [ModManagerPage::class, MinecraftDatapackPage::class, MinecraftResourcePackPage::class],
        );
        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            static fn () => View::file($root.'/resources/views/components/catalog-view-toggle.blade.php', ['searchFallback' => true]),
            [ModManagerPage::class, MinecraftDatapackPage::class, MinecraftResourcePackPage::class],
        );
        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE,
            static fn () => View::file($root.'/resources/views/components/catalog-view-toggle.blade.php'),
            [
                ModManagerPage::class,
                MinecraftDatapackPage::class,
                MinecraftResourcePackPage::class,
            ],
        );

        Http::preventStrayRequests();
    }

    public function test_catalog_tile_columns_persists_normalizes_and_falls_back_to_cookie(): void
    {
        session()->forget(CatalogTileColumns::SESSION_KEY);
        request()->cookies->remove(CatalogTileColumns::COOKIE_KEY);

        self::assertSame(CatalogTileColumns::VIEW_LIST, $this->resolveInitialViewWithState(null, null));

        self::assertSame(CatalogTileColumns::VIEW_PANEL, CatalogTileColumns::normalizeView('panel'));
        self::assertSame(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::normalizeView('list'));
        self::assertSame(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::normalizeView(null));
        self::assertSame(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::normalizeView(''));
        self::assertSame(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::normalizeView('grid'));
        self::assertSame(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::normalizeView('PANEL'));
        self::assertSame(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::normalizeView(['panel']));
        self::assertSame(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::normalizeView(123));

        // Persisting 'panel' updates session and queues a 365-day cookie.
        self::assertSame(CatalogTileColumns::VIEW_PANEL, CatalogTileColumns::storeView(CatalogTileColumns::VIEW_PANEL));
        self::assertSame(CatalogTileColumns::VIEW_PANEL, session()->get(CatalogTileColumns::SESSION_KEY));
        self::assertSame(CatalogTileColumns::VIEW_PANEL, CatalogTileColumns::resolveInitialView());

        $queuedPanel = Cookie::queued(CatalogTileColumns::COOKIE_KEY);
        self::assertNotNull($queuedPanel);
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $queuedPanel->getValue());
        self::assertGreaterThan(time() + (CatalogTileColumns::COOKIE_MINUTES * 60) - 120, $queuedPanel->getExpiresTime());

        // Persisting an invalid value normalizes to 'list'.
        self::assertSame(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::storeView('unexpected'));
        self::assertSame(CatalogTileColumns::VIEW_LIST, session()->get(CatalogTileColumns::SESSION_KEY));
        $queuedList = Cookie::queued(CatalogTileColumns::COOKIE_KEY);
        self::assertNotNull($queuedList);
        self::assertSame(CatalogTileColumns::VIEW_LIST, $queuedList->getValue());

        // Empty session falls back to cookie, session takes precedence over cookie, and invalid cookie falls back to 'list'.
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $this->resolveInitialViewWithState(null, CatalogTileColumns::VIEW_PANEL));
        self::assertSame(CatalogTileColumns::VIEW_LIST, $this->resolveInitialViewWithState(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::VIEW_PANEL));
        self::assertSame(CatalogTileColumns::VIEW_LIST, $this->resolveInitialViewWithState(null, 'bogus'));
    }

    public function test_clean_session_and_cookie_default_to_list_view_across_all_catalogs(): void
    {
        $this->fakeCatalogSources();
        $this->bindStubDaemonFileRepository();

        $pageCases = [
            [ModManagerPage::class, ProjectType::Mod],
            [ModManagerPage::class, ProjectType::Plugin],
            [MinecraftDatapackPage::class, ProjectType::Datapack],
            [MinecraftResourcePackPage::class, ProjectType::ResourcePack],
        ];

        foreach ($pageCases as [$pageClass, $type]) {
            session()->forget(CatalogTileColumns::SESSION_KEY);
            request()->cookies->remove(CatalogTileColumns::COOKIE_KEY);
            $this->configureServerPanelFor($type);

            $component = Livewire::test($pageClass)->call('loadTable');
            self::assertSame(CatalogTileColumns::VIEW_LIST, $component->get('catalogView'));
            $instance = $component->instance();
            assert($instance instanceof ModManagerPage);
            self::assertFalse($instance->getTable()->hasColumnsLayout());
            $html = $component->html();
            self::assertStringContainsString('fi-ta-table', $html);
            self::assertStringNotContainsString('fi-ta-content-grid', $html);
        }

        $this->configureServerPanelFor(ProjectType::Mod);
        $panelCookiePage = Livewire::withCookies([CatalogTileColumns::COOKIE_KEY => CatalogTileColumns::VIEW_PANEL])
            ->test(ModManagerPage::class)
            ->call('loadTable');
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $panelCookiePage->get('catalogView'));
    }

    public function test_legacy_view_is_restored_only_until_a_server_preference_exists(): void
    {
        $this->fakeCatalogSources();
        $this->bindStubDaemonFileRepository();
        $this->configureServerPanelFor(ProjectType::Mod);
        session()->forget(CatalogTileColumns::SESSION_KEY);
        request()->cookies->remove(CatalogTileColumns::COOKIE_KEY);

        Livewire::test(ModManagerPage::class)
            ->assertSet('catalogView', CatalogTileColumns::VIEW_LIST)
            ->assertSeeHtml('restoreLegacyCatalogView()')
            ->call('restoreLegacyCatalogView')
            ->assertSet('catalogView', CatalogTileColumns::VIEW_PANEL)
            ->assertDontSeeHtml('restoreLegacyCatalogView()')
            ->call('toggleCatalogView')
            ->call('restoreLegacyCatalogView')
            ->assertSet('catalogView', CatalogTileColumns::VIEW_LIST);
    }

    /** @return array<string, array{class-string<ModManagerPage>, ProjectType, string}> */
    public static function modManagerCatalogCases(): array
    {
        return [
            'mod' => [ModManagerPage::class, ProjectType::Mod, 'https://modrinth.com/mod/sodium'],
            'plugin' => [ModManagerPage::class, ProjectType::Plugin, 'https://modrinth.com/plugin/sodium'],
            'datapack' => [MinecraftDatapackPage::class, ProjectType::Datapack, 'https://modrinth.com/datapack/sodium'],
            'resourcepack' => [MinecraftResourcePackPage::class, ProjectType::ResourcePack, 'https://modrinth.com/resourcepack/sodium'],
        ];
    }

    /** @param class-string<ModManagerPage> $pageClass */
    #[DataProvider('modManagerCatalogCases')]
    public function test_modrinth_tiles_and_lists_omit_author_links_and_nested_anchors_across_all_mod_manager_catalogs(
        string $pageClass,
        ProjectType $type,
        string $expectedProjectUrl,
    ): void {
        $this->fakeCatalogSources();
        $this->bindStubDaemonFileRepository();
        $this->configureServerPanelFor($type);

        $component = Livewire::test($pageClass)
            ->set('activeTab', 'modrinth')
            ->call('loadTable');
        $listHtml = $component->html();

        // List view: zero Modrinth author links, external project URL preserved, author cell wrapped in record link.
        self::assertStringNotContainsString('modrinth.com/user/', $listHtml);
        self::assertStringContainsString('href="'.$expectedProjectUrl.'"', $listHtml);
        self::assertStringContainsString('target="_blank"', $listHtml);
        self::assertStringContainsString('jellysquid3', $listHtml);

        $listXpath = $this->xpathFor($listHtml);
        $authorAnchors = $listXpath->query('//td[@data-mmr-swr-cell="author"]//a[@href="'.$expectedProjectUrl.'"]');
        self::assertNotFalse($authorAnchors);
        self::assertGreaterThanOrEqual(
            1,
            $authorAnchors->length,
            'List-view author cell should inherit the clickable recordUrl anchor',
        );

        // Tile view: zero Modrinth author links, zero nested <a>, single card anchor per record, external URL preserved.
        $component->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $component->get('catalogView'));
        $tileHtml = $component->html();

        self::assertStringContainsString('fi-ta-content-grid', $tileHtml);
        self::assertStringContainsString('jellysquid3', $tileHtml);
        self::assertStringContainsString('href="'.$expectedProjectUrl.'"', $tileHtml);
        self::assertStringNotContainsString('modrinth.com/user/', $tileHtml);
        $this->assertNoNestedAnchors($tileHtml);

        $tileXpath = $this->xpathFor($tileHtml);
        self::assertSame(
            1,
            $tileXpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " fi-ta-record-content ")]')?->length,
        );
    }

    public function test_non_modrinth_mod_manager_providers_preserve_external_links_and_layout_in_tile_view(): void
    {
        $this->fakeAllModManagerSources();
        $this->bindStubDaemonFileRepository();
        $this->configureServerPanelFor(ProjectType::Plugin);
        session()->put(CatalogTileColumns::SESSION_KEY, CatalogTileColumns::VIEW_PANEL);

        $cases = [
            'curseforge' => ['https://www.curseforge.com/minecraft/bukkit-plugins/worldedit', 'sk89q'],
            'hangar' => ['https://hangar.papermc.io/PaperMC/ViaVersion', 'PaperMC'],
            'spigot' => ['https://www.spigotmc.org/resources/9089/', 'EssentialsX'],
        ];

        $component = Livewire::test(ModManagerPage::class);

        foreach ($cases as $tab => [$expectedUrl, $expectedAuthor]) {
            $component->set('activeTab', $tab)->call('loadTable');
            $tileHtml = $component->html();

            self::assertStringContainsString('fi-ta-content-grid', $tileHtml, "Provider {$tab} should render tile grid");
            self::assertStringContainsString('href="'.$expectedUrl.'"', $tileHtml, "Provider {$tab} missing external recordUrl");
            self::assertStringContainsString($expectedAuthor, $tileHtml, "Provider {$tab} missing author text");
            self::assertStringNotContainsString('modrinth.com/user/', $tileHtml);
            $this->assertNoNestedAnchors($tileHtml);

            $xpath = $this->xpathFor($tileHtml);
            self::assertSame(
                1,
                $xpath->query('//button[@data-mmr-view-toggle and @*[name()="wire:click"]="toggleCatalogView"]')?->length,
                "Provider {$tab} should render a working switch back to list even without filters",
            );
            self::assertSame(
                1,
                $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " fi-ta-record-content ")]')?->length,
                "Provider {$tab} should render exactly one card anchor",
            );
        }
    }

    public function test_catalog_toggle_remains_available_when_filament_skips_the_trigger_group(): void
    {
        $this->fakeAllModManagerSources();
        $this->bindStubDaemonFileRepository();
        $this->configureServerPanelFor(ProjectType::Plugin);

        Table::configureUsing(
            static fn (Table $table): Table => $table->columnManager(false),
            function (): void {
                $page = Livewire::test(ModManagerPage::class)->set('activeTab', 'spigot')->call('loadTable');
                foreach ([CatalogTileColumns::VIEW_LIST, CatalogTileColumns::VIEW_PANEL] as $view) {
                    $page->assertSet('catalogView', $view);
                    $instance = $page->instance();
                    assert($instance instanceof ModManagerPage);
                    self::assertFalse($instance->getTable()->hasColumnManager());
                    self::assertFalse($instance->getTable()->isFilterable());
                    self::assertSame(1, $this->xpathFor($page->html())->query('//button[@data-mmr-view-toggle]')?->length);
                    $page->call('toggleCatalogView');
                }
            },
        );
    }

    public function test_mod_manager_page_mounted_in_panel_view_and_tab_switches_preserve_columns_without_session_poisoning(): void
    {
        $this->fakeCatalogSources();
        $this->bindStubDaemonFileRepository(includeInstalledMetadata: true);
        $this->configureServerPanelFor(ProjectType::Mod);

        // (a) Mount directly in 'panel' view -> toggle to 'list' recovers all toggleable columns.
        session()->flush();
        session()->put(CatalogTileColumns::SESSION_KEY, CatalogTileColumns::VIEW_PANEL);

        $panelPage = Livewire::test(ModManagerPage::class)->call('loadTable');
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $panelPage->get('catalogView'));
        self::assertSame([], $panelPage->get('tableColumns'));
        self::assertStringContainsString('fi-ta-content-grid', $panelPage->html());

        $panelPage->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_LIST, $panelPage->get('catalogView'));
        $panelPageInstance = $panelPage->instance();
        assert($panelPageInstance instanceof ModManagerPage);
        self::assertSame(['icon_url', 'title', 'author', 'downloads', 'date_modified'], array_keys($panelPageInstance->getTable()->getVisibleColumns()));
        $recoveredHtml = $panelPage->html();
        self::assertStringContainsString('data-mmr-swr-cell="author"', $recoveredHtml);
        self::assertStringContainsString('data-mmr-swr-cell="downloads"', $recoveredHtml);
        self::assertStringContainsString('data-mmr-swr-cell="date_modified"', $recoveredHtml);
        self::assertStringContainsString('ATMTeam', $recoveredHtml);

        // (b) Mount directly in 'panel' view -> switch Catalog -> Installed -> Catalog -> toggle 'list'.
        session()->flush();
        session()->put(CatalogTileColumns::SESSION_KEY, CatalogTileColumns::VIEW_PANEL);

        $tabSwitchPage = Livewire::test(ModManagerPage::class)->call('loadTable');
        self::assertStringContainsString('fi-ta-content-grid', $tabSwitchPage->html());

        $tabSwitchPage->set('activeTab', 'installed');
        $installedInstance = $tabSwitchPage->instance();
        assert($installedInstance instanceof ModManagerPage);
        self::assertFalse($installedInstance->getTable()->hasColumnsLayout());
        self::assertSame(['icon_url', 'title', 'source', 'author', 'downloads', 'date_modified'], array_keys($installedInstance->getTable()->getVisibleColumns()));
        self::assertNotSame([], session()->get($installedInstance->getTableColumnsSessionKey()));

        $installedHtml = $tabSwitchPage->html();
        self::assertStringContainsString('fi-ta-table', $installedHtml);
        self::assertStringNotContainsString('fi-ta-content-grid', $installedHtml);
        self::assertStringNotContainsString('fi-ta-table-loading-ctn', $installedHtml);
        self::assertStringNotContainsString('data-mmr-view-toggle', $installedHtml);
        self::assertStringContainsString('data-mmr-swr-cell="source"', $installedHtml);
        self::assertStringContainsString('data-mmr-swr-cell="author"', $installedHtml);
        self::assertStringContainsString('data-mmr-swr-cell="downloads"', $installedHtml);
        self::assertStringContainsString('data-mmr-swr-cell="date_modified"', $installedHtml);

        // Switch back to Modrinth catalog tab (still tile view) then toggle to list view.
        $tabSwitchPage->set('activeTab', 'modrinth')->call('loadTable');
        self::assertStringContainsString('fi-ta-content-grid', $tabSwitchPage->html());
        $tabSwitchPage->call('toggleCatalogView');
        $afterReturnInstance = $tabSwitchPage->instance();
        assert($afterReturnInstance instanceof ModManagerPage);
        self::assertSame(['icon_url', 'title', 'author', 'downloads', 'date_modified'], array_keys($afterReturnInstance->getTable()->getVisibleColumns()));

        // (c) Bug B2: customize list columns -> switch to panel -> apply/reset in panel -> switch to Installed & back -> toggle list preserves customization!
        session()->flush();
        $customPage = Livewire::test(ModManagerPage::class)->call('loadTable');
        $tableColumns = $customPage->get('tableColumns');
        self::assertIsArray($tableColumns);
        $customized = array_map(static function (mixed $col): mixed {
            if (is_array($col) && ($col['name'] ?? null) === 'downloads') {
                $col['isToggled'] = false;
            }

            return $col;
        }, $tableColumns);
        $customPage->call('applyTableColumnManager', $customized);

        $customPage->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $customPage->get('catalogView'));
        $customPage->call('applyTableColumnManager');
        $customPage->call('resetTableColumnManager');

        $customPage->set('activeTab', 'installed')->call('loadTable');
        $customPage->set('activeTab', 'modrinth')->call('loadTable');
        $customPage->call('toggleCatalogView');

        $finalInstance = $customPage->instance();
        assert($finalInstance instanceof ModManagerPage);
        self::assertSame(['icon_url', 'title', 'author', 'date_modified'], array_keys($finalInstance->getTable()->getVisibleColumns()));
        self::assertStringNotContainsString('data-mmr-swr-cell="downloads"', $customPage->html());
    }

    public function test_tile_date_modified_suppresses_calendar_icon_on_unparseable_dates_and_preserves_tooltip_on_valid_dates(): void
    {
        $validIso = '2026-10-01T12:00:00Z';

        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([
                ['version' => '1.21.1', 'version_type' => 'release'],
            ]),
            'api.modrinth.com/v2/tag/category' => Http::response([]),
            'api.modrinth.com/v2/tag/loader' => Http::response([]),
            'api.modrinth.com/v2/search*' => static function ($request) use ($validIso) {
                $url = $request->url();
                $isBadDate = str_contains($url, 'bad-date');

                return Http::response([
                    'hits' => [[
                        'project_id' => $isBadDate ? 'badDate1' : 'goodDate1',
                        'slug' => $isBadDate ? 'bad-date-project' : 'good-date-project',
                        'title' => $isBadDate ? 'Bad Date Project' : 'Good Date Project',
                        'description' => 'Date formatting regression check.',
                        'icon_url' => null,
                        'author' => 'robotkoer',
                        'downloads' => $isBadDate ? 0 : 1234567,
                        'date_modified' => $isBadDate ? 'not-a-real-date' : $validIso,
                        'versions' => ['1.21.1'],
                        'categories' => ['fabric'],
                    ]],
                    'total_hits' => 1,
                ]);
            },
        ]);

        $this->bindStubDaemonFileRepository();
        $this->configureServerPanelFor(ProjectType::Mod);
        session()->put(CatalogTileColumns::SESSION_KEY, CatalogTileColumns::VIEW_PANEL);

        $badDatePage = Livewire::test(ModManagerPage::class)
            ->set('activeTab', 'modrinth')
            ->set('tableSearch', 'bad-date')
            ->call('loadTable');
        $this->assertBottomMetadataDomStructure($badDatePage->html(), 'robotkoer', '0', expectDateIcon: false);

        $goodDatePage = Livewire::test(ModManagerPage::class)
            ->set('activeTab', 'modrinth')
            ->set('tableSearch', 'good-date')
            ->call('loadTable');
        $goodPageInstance = $goodDatePage->instance();
        assert($goodPageInstance instanceof ModManagerPage);
        $expectedPageTooltip = Carbon::parse($validIso, 'UTC')->format(
            $goodPageInstance->getTable()->getDefaultDateTimeDisplayFormat(),
        );
        self::assertStringContainsString('2026', $expectedPageTooltip);
        self::assertStringContainsString('12:00:00', $expectedPageTooltip);
        $this->assertBottomMetadataDomStructure(
            $goodDatePage->html(),
            'robotkoer',
            '1,234,567',
            expectDateIcon: true,
            expectedDateTooltip: $expectedPageTooltip,
        );
    }

    private function createTestSchema(): void
    {
        Schema::create('eggs', static function ($table): void {
            $table->id();
            $table->string('uuid')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('startup')->nullable();
            $table->text('docker_images')->nullable();
            $table->json('features')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
        });

        Schema::create('egg_variables', static function ($table): void {
            $table->id();
            $table->unsignedBigInteger('egg_id');
            $table->string('name');
            $table->string('env_variable');
            $table->string('default_value')->nullable();
            $table->timestamps();
        });

        Schema::create('servers', static function ($table): void {
            $table->id();
            $table->string('uuid')->nullable();
            $table->string('uuidShort')->nullable();
            $table->unsignedBigInteger('egg_id')->nullable();
            $table->string('image')->nullable();
            $table->string('startup')->nullable();
            $table->timestamps();
        });

        Schema::create('subusers', static function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('server_id');
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        Schema::create('server_variables', static function ($table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('variable_id');
            $table->string('variable_value')->nullable();
            $table->timestamps();
        });

        Schema::create('mod_manager_egg_profiles', static function ($table): void {
            $table->id();
            $table->unsignedInteger('egg_id')->unique();
            $table->string('egg_uuid')->nullable();
            $table->string('project_type')->nullable();
            $table->string('loader')->nullable();
            $table->string('minecraft_version')->nullable();
            $table->boolean('supports_datapacks')->default(false);
            $table->timestamps();
        });

        Schema::create('mod_manager_server_settings', static function ($table): void {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->boolean('allow_user_egg_profile_edit')->nullable();
            $table->boolean('allow_user_project_install')->nullable();
            $table->boolean('allow_user_project_update')->nullable();
            $table->boolean('allow_user_project_delete')->nullable();
            $table->timestamps();
        });

        DB::table('eggs')->insert([
            [
                'id' => 1,
                'uuid' => 'fabric-egg-uuid',
                'name' => 'Fabric',
                'startup' => 'java -jar fabric-server-launch.jar',
                'docker_images' => json_encode(['Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21']),
            ],
            [
                'id' => 2,
                'uuid' => 'paper-egg-uuid',
                'name' => 'Paper',
                'startup' => 'java -jar server.jar',
                'docker_images' => json_encode(['Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21']),
            ],
        ]);

        DB::table('egg_variables')->insert([
            ['egg_id' => 1, 'name' => 'MC Version', 'env_variable' => 'MC_VERSION', 'default_value' => '1.21.1'],
            ['egg_id' => 2, 'name' => 'MC Version', 'env_variable' => 'MC_VERSION', 'default_value' => '1.21.1'],
        ]);

        DB::table('servers')->insert([
            'id' => 10,
            'uuid' => '11111111-2222-3333-4444-555555555555',
            'uuidShort' => 'abcd1234',
            'egg_id' => 1,
            'image' => 'ghcr.io/pelican-eggs/yolks:java_21',
            'startup' => 'java -jar fabric-server-launch.jar',
        ]);
    }

    private function configureServerPanelFor(ProjectType $type): void
    {
        $user = new class extends User
        {
            public function isRootAdmin(): bool
            {
                return true;
            }

            public function can($abilities, mixed $arguments = []): bool
            {
                return true;
            }
        };
        $user->forceFill([
            'id' => 1,
            'username' => 'admin',
            'email' => 'admin@example.com',
            'timezone' => 'UTC',
        ]);
        $this->actingAs($user);

        $eggId = $type === ProjectType::Plugin ? 2 : 1;
        DB::table('servers')->where('id', 10)->update(['egg_id' => $eggId]);
        $egg = Egg::query()->with('variables')->findOrFail($eggId);
        $server = Server::query()->findOrFail(10);
        $server->setRelation('egg', $egg);
        $server->setRelation('variables', collect());

        Filament::setCurrentPanel(Filament::getPanel('server'));
        Filament::setTenant($server);
        URL::defaults(['tenant' => 'abcd1234']);
    }

    private function bindStubDaemonFileRepository(bool $includeInstalledMetadata = false): void
    {
        $metadataJson = $includeInstalledMetadata
            ? json_encode((new InstalledMetadataDocument([
                [
                    'source' => 'modrinth',
                    'project_id' => 'AANobbMI',
                    'project_slug' => 'sodium',
                    'project_title' => 'Sodium',
                    'version_id' => 'v1',
                    'version_number' => '0.6.0',
                    'filename' => 'sodium-0.6.0.jar',
                    'installed_at' => '2026-10-01T12:00:00Z',
                    'author' => 'jellysquid3',
                    'downloads' => 50000000,
                    'date_modified' => '2026-10-01T12:00:00Z',
                ],
                [
                    'source' => 'curseforge',
                    'project_id' => '394468',
                    'project_slug' => 'sodium-cf',
                    'project_title' => 'Lithium',
                    'version_id' => 'v2',
                    'version_number' => '0.13.0',
                    'filename' => 'lithium-0.13.0.jar',
                    'installed_at' => '2026-10-01T12:00:00Z',
                    'author' => 'jellysquid3',
                    'downloads' => 25000000,
                    'date_modified' => '2026-10-01T12:00:00Z',
                ],
            ]))->toArray(), JSON_THROW_ON_ERROR)
            : '';

        $repo = $this->createStub(DaemonFileRepository::class);
        $repo->method('setServer')->willReturnSelf();
        $repo->method('getDirectory')->willReturnCallback(
            static function (string $path = '/') use ($includeInstalledMetadata, $metadataJson): array {
                if (!$includeInstalledMetadata) {
                    return [];
                }

                if ($path === '/' || $path === '') {
                    return [
                        ['name' => 'mods', 'file' => false, 'size' => 0],
                        ['name' => 'plugins', 'file' => false, 'size' => 0],
                    ];
                }

                return [
                    ['name' => '.pelican-mod-manager.json', 'file' => true, 'size' => strlen($metadataJson)],
                    ['name' => 'sodium-0.6.0.jar', 'file' => true, 'size' => 1024],
                    ['name' => 'lithium-0.13.0.jar', 'file' => true, 'size' => 1024],
                ];
            },
        );
        $repo->method('getContent')->willReturnCallback(
            static function (string $path) use ($includeInstalledMetadata, $metadataJson): string {
                if ($includeInstalledMetadata && str_ends_with($path, '.pelican-mod-manager.json')) {
                    return $metadataJson;
                }

                return '';
            },
        );

        $this->app->instance(DaemonFileRepository::class, $repo);
    }

    private function fakeCatalogSources(): void
    {
        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([
                ['version' => '1.21.1', 'version_type' => 'release'],
            ]),
            'api.modrinth.com/v2/tag/category' => Http::response([]),
            'api.modrinth.com/v2/tag/loader' => Http::response([]),
            'api.modrinth.com/v2/search*' => Http::response([
                'hits' => [[
                    'project_id' => 'AANobbMI',
                    'slug' => 'sodium',
                    'title' => 'Sodium',
                    'description' => 'Modern rendering engine for Minecraft.',
                    'icon_url' => null,
                    'author' => 'jellysquid3',
                    'downloads' => 50000000,
                    'date_modified' => '2026-10-01T12:00:00Z',
                    'versions' => ['1.21.1'],
                    'categories' => ['fabric'],
                ]],
                'total_hits' => 1,
            ]),
            'api.modrinth.com/v2/projects*' => Http::response([[
                'id' => 'AANobbMI',
                'slug' => 'sodium',
                'title' => 'Sodium',
                'description' => 'Modern rendering engine for Minecraft.',
                'downloads' => 50000000,
                'updated' => '2026-10-01T12:00:00Z',
            ]]),
            'api.curseforge.com/v1/mods/search*' => Http::response([
                'data' => [[
                    'id' => 925200,
                    'slug' => 'all-the-mods-10',
                    'name' => 'All the Mods 10',
                    'summary' => 'Kitchen sink pack.',
                    'authors' => [['name' => 'ATMTeam']],
                    'downloadCount' => 500000,
                    'dateModified' => '2026-10-01T12:00:00Z',
                    'latestFilesIndexes' => [['gameVersion' => '1.21.1', 'modLoader' => 6]],
                ]],
                'pagination' => ['totalCount' => 1],
            ]),
            'api.curseforge.com/v1/mods' => Http::response(['data' => []]),
            'api.curseforge.com/v1/categories*' => Http::response(['data' => []]),
            'api.curseforge.com/v1/minecraft/version*' => Http::response(['data' => []]),
            'hangar.papermc.io/*' => Http::response(['result' => [], 'pagination' => ['count' => 0]]),
            'api.spiget.org/*' => Http::response([]),
        ]);
    }

    private function fakeAllModManagerSources(): void
    {
        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([
                ['version' => '1.21.1', 'version_type' => 'release'],
            ]),
            'api.modrinth.com/v2/tag/category' => Http::response([]),
            'api.modrinth.com/v2/tag/loader' => Http::response([]),
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [], 'total_hits' => 0]),
            'api.curseforge.com/v1/categories*' => Http::response(['data' => []]),
            'api.curseforge.com/v1/minecraft/version*' => Http::response(['data' => []]),
            'api.curseforge.com/v1/mods/search*' => Http::response([
                'data' => [[
                    'id' => 225608,
                    'slug' => 'worldedit',
                    'name' => 'WorldEdit',
                    'summary' => 'In-game Minecraft map editor.',
                    'authors' => [['name' => 'sk89q']],
                    'downloadCount' => 9800000,
                    'dateModified' => '2026-10-01T12:00:00Z',
                    'latestFilesIndexes' => [['gameVersion' => '1.21.1']],
                ]],
                'pagination' => ['totalCount' => 1],
            ]),
            'hangar.papermc.io/api/v1/projects*' => Http::response([
                'result' => [[
                    'name' => 'ViaVersion',
                    'namespace' => ['owner' => 'PaperMC', 'slug' => 'ViaVersion'],
                    'description' => 'Allow newer clients to connect.',
                    'stats' => ['downloads' => 750000],
                    'lastUpdated' => '2026-10-01T12:00:00Z',
                    'avatarUrl' => null,
                ]],
                'pagination' => ['count' => 1],
            ]),
            'hangar.papermc.io/api/v1/platforms*' => Http::response([]),
            'api.spiget.org/v2/resources*' => Http::response([[
                'id' => 9089,
                'name' => 'EssentialsX',
                'tag' => 'The essential plugin suite.',
                'downloads' => 3200000,
                'updateDate' => 1700000000,
                'author' => ['id' => 111],
                'file' => ['type' => '.jar'],
            ]]),
            'api.spiget.org/v2/authors/111' => Http::response(['id' => 111, 'name' => 'EssentialsX']),
            'api.spiget.org/v2/categories*' => Http::response([]),
        ]);
    }

    private function assertNoNestedAnchors(string $html): void
    {
        $sanitized = preg_replace('/<!--.*?-->|<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        preg_match_all('/<(\/?)a(?:\s[^>]*)?>/i', $sanitized, $matches);
        $depth = 0;
        $maxDepth = 0;
        foreach ($matches[1] as $closing) {
            if ($closing === '/') {
                $depth = max(0, $depth - 1);
            } else {
                $depth++;
                $maxDepth = max($maxDepth, $depth);
            }
        }
        self::assertLessThanOrEqual(1, $maxDepth, 'Rendered HTML must never nest <a> tags');

        $xpath = $this->xpathFor($html);
        self::assertSame(0, $xpath->query('//a//a')?->length, 'DOM must contain zero nested <a> descendants');
    }

    private function assertBottomMetadataDomStructure(
        string $html,
        ?string $expectedAuthor,
        ?string $expectedDownloads,
        bool $expectDateIcon,
        ?string $expectedDateTooltip = null,
    ): void {
        $xpath = $this->xpathFor($html);
        $metadataStacks = $xpath->query(
            '//a[contains(concat(" ", normalize-space(@class), " "), " fi-ta-record-content ")]'
            .'//div[contains(concat(" ", normalize-space(@class), " "), " mmr-tile-stats ")]',
        );

        self::assertNotFalse($metadataStacks);
        self::assertSame(1, $metadataStacks->length);

        $stackNode = $metadataStacks->item(0);
        self::assertInstanceOf(DOMElement::class, $stackNode);

        $directDivChildren = $xpath->query('./div', $stackNode);
        self::assertNotFalse($directDivChildren);
        self::assertSame(2, $directDivChildren->length, 'Only the author and the combined download/date group may wrap onto separate rows.');

        $authorRow = $directDivChildren->item(0);
        $splitRow = $directDivChildren->item(1);
        self::assertInstanceOf(DOMElement::class, $authorRow);
        self::assertInstanceOf(DOMElement::class, $splitRow);

        if ($expectedAuthor !== null) {
            self::assertStringContainsString($expectedAuthor, $authorRow->textContent);
            self::assertSame(1, $xpath->query('.//*[local-name()="svg"]', $authorRow)?->length);
        } else {
            self::assertSame('', trim($authorRow->textContent));
            self::assertSame(0, $xpath->query('.//*[local-name()="svg"]', $authorRow)?->length, 'Null author must not render a dangling icon');
        }

        $splitDiv = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " mmr-tile-stat-values ")]', $splitRow)?->item(0);
        self::assertInstanceOf(DOMElement::class, $splitDiv);

        $splitChildren = $xpath->query('./div', $splitDiv);
        self::assertNotFalse($splitChildren);
        self::assertSame(2, $splitChildren->length, 'Bottom Split must contain downloads and date_modified columns');

        $downloadsCol = $splitChildren->item(0);
        $dateCol = $splitChildren->item(1);
        self::assertInstanceOf(DOMElement::class, $downloadsCol);
        self::assertInstanceOf(DOMElement::class, $dateCol);

        self::assertStringNotContainsString('fi-growable', $downloadsCol->getAttribute('class'));
        self::assertStringContainsString('fi-align-end', $dateCol->getAttribute('class'));
        self::assertStringNotContainsString('fi-growable', $dateCol->getAttribute('class'));

        if ($expectedDownloads !== null) {
            self::assertStringContainsString($expectedDownloads, $downloadsCol->textContent);
            self::assertSame(1, $xpath->query('.//*[local-name()="svg"]', $downloadsCol)?->length);
        } else {
            self::assertSame('', trim($downloadsCol->textContent));
            self::assertSame(0, $xpath->query('.//*[local-name()="svg"]', $downloadsCol)?->length, 'Null downloads must not render a dangling icon');
        }

        if ($expectDateIcon) {
            self::assertNotSame('', trim($dateCol->textContent));
            self::assertSame(1, $xpath->query('.//*[local-name()="svg"]', $dateCol)?->length);
            if ($expectedDateTooltip !== null) {
                $tooltipNodes = $xpath->query('.//*[@x-tooltip]', $dateCol);
                self::assertNotFalse($tooltipNodes);
                self::assertSame(1, $tooltipNodes->length, 'Valid date_modified must render an x-tooltip attribute');
                $tooltipNode = $tooltipNodes->item(0);
                self::assertInstanceOf(DOMElement::class, $tooltipNode);
                self::assertStringContainsString($expectedDateTooltip, $tooltipNode->getAttribute('x-tooltip'));
            }
        } else {
            self::assertSame('', trim($dateCol->textContent));
            self::assertSame(0, $xpath->query('.//*[local-name()="svg"]', $dateCol)?->length, 'Missing or unparseable date_modified must not render a dangling icon');
            self::assertSame(0, $xpath->query('.//*[@x-tooltip]', $dateCol)?->length, 'Missing or unparseable date_modified must not render a tooltip');
        }
    }

    private function resolveInitialViewWithState(?string $sessionView, ?string $cookieView): string
    {
        if ($sessionView === null) {
            session()->forget(CatalogTileColumns::SESSION_KEY);
        } else {
            session()->put(CatalogTileColumns::SESSION_KEY, $sessionView);
        }

        if ($cookieView === null) {
            request()->cookies->remove(CatalogTileColumns::COOKIE_KEY);
        } else {
            request()->cookies->set(CatalogTileColumns::COOKIE_KEY, $cookieView);
        }

        return CatalogTileColumns::resolveInitialView();
    }

    private function xpathFor(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($dom);
    }
}
