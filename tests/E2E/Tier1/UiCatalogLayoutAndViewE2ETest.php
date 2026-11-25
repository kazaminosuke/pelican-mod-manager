<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier1;

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Filament\Components\ModpackCatalogTable;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftDatapackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftResourcePackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Support\CatalogTileColumns;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use Kazaminosuke\ModManager\Tests\E2E\Support\Html5DomAssert;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tier 1 — Isolated Feature Coverage for UI Features #1, #2, #3, #4 (M1).
 */
#[RunTestsInSeparateProcesses]
final class UiCatalogLayoutAndViewE2ETest extends E2ETestCase
{
    public function test_modrinth_modpack_catalog_omits_author_links_preserves_record_url_and_uses_filament_stack_split_layout(): void
    {
        $this->useAdminPanel();
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        $this->fakeModrinthCatalogEndpoints('modpack');

        foreach ([ModpackCatalogTable::MODE_CREATE, ModpackCatalogTable::MODE_SERVER] as $mode) {
            session()->forget(CatalogTileColumns::SESSION_KEY);
            $component = Livewire::test(ModpackCatalogTable::class, [
                'mode' => $mode,
                'provider' => ModpackProvider::Modrinth->value,
                'query' => 'optimized',
            ]);

            // Feature #3: Clean initial view MUST be list table (not tile view).
            self::assertSame(CatalogTileColumns::VIEW_LIST, $component->get('catalogView'));
            $listHtml = $component->html();
            self::assertStringContainsString('fi-ta-table', $listHtml);
            self::assertStringNotContainsString('fi-ta-content-grid', $listHtml);

            // Feature #1: Zero Modrinth author links in list view; project recordUrl preserved.
            Html5DomAssert::assertNoModrinthAuthorLinks($listHtml, "ModpackCatalogTable({$mode}) list:");
            Html5DomAssert::assertExternalProjectLinkPresent($listHtml, 'https://modrinth.com/modpack/fabulously-optimized', "ModpackCatalogTable({$mode}) list:");

            // Switch to tile view -> Feature #1 (zero nested <a>) + Feature #2 (pure Filament Stack+Split).
            $component->call('toggleCatalogView');
            self::assertSame(CatalogTileColumns::VIEW_PANEL, $component->get('catalogView'));
            $tileHtml = $component->html();

            Html5DomAssert::assertNoModrinthAuthorLinks($tileHtml, "ModpackCatalogTable({$mode}) tile:");
            Html5DomAssert::assertExternalProjectLinkPresent($tileHtml, 'https://modrinth.com/modpack/fabulously-optimized', "ModpackCatalogTable({$mode}) tile:");
            Html5DomAssert::assertSingleAnchorPerTileCard($tileHtml, 2, "ModpackCatalogTable({$mode}) tile:");
            Html5DomAssert::assertPureFilamentTileMetadataLayout($tileHtml, 2, "ModpackCatalogTable({$mode}) tile:");
            self::assertStringContainsString('robotkoer', $tileHtml);
        }
    }

    public function test_mod_plugin_datapack_and_resourcepack_catalogs_omit_author_links_default_to_list_and_render_clean_tiles(): void
    {
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        $user = $this->createUser(rootAdmin: true);
        $this->actingAs($user);

        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(10));
        $paperEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::paper(11));

        $fabricServer = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1', 'LOADER_VERSION' => '0.16.10']);
        $paperServer = $this->createServer($paperEgg, variableValues: ['MINECRAFT_VERSION' => '1.21.1', 'BUILD_NUMBER' => 'latest']);
        $this->seedServerFile($fabricServer, 'server.properties', "level-name=world\n");
        $this->seedServerFile($paperServer, 'server.properties', "level-name=world\n");

        $cases = [
            ['page' => ModManagerPage::class, 'server' => $fabricServer, 'type' => 'mod', 'url' => 'https://modrinth.com/mod/sodium-e2e'],
            ['page' => ModManagerPage::class, 'server' => $paperServer, 'type' => 'plugin', 'url' => 'https://modrinth.com/plugin/sodium-e2e'],
            ['page' => MinecraftDatapackPage::class, 'server' => $fabricServer, 'type' => 'datapack', 'url' => 'https://modrinth.com/datapack/sodium-e2e'],
            ['page' => MinecraftResourcePackPage::class, 'server' => $fabricServer, 'type' => 'resourcepack', 'url' => 'https://modrinth.com/resourcepack/sodium-e2e'],
        ];

        $this->fakeModrinthCatalogEndpoints('project');

        foreach ($cases as $case) {
            session()->forget(CatalogTileColumns::SESSION_KEY);
            $this->useServerPanel($case['server']);

            $component = Livewire::test($case['page'])
                ->set('activeTab', 'modrinth')
                ->call('loadTable');
            self::assertSame(CatalogTileColumns::VIEW_LIST, $component->get('catalogView'), "Page {$case['type']} must default to list view.");
            $listHtml = $component->html();
            self::assertStringContainsString('fi-ta-table', $listHtml);
            self::assertStringNotContainsString('fi-ta-content-grid', $listHtml);
            Html5DomAssert::assertNoModrinthAuthorLinks($listHtml, "{$case['type']} list:");
            Html5DomAssert::assertExternalProjectLinkPresent($listHtml, $case['url'], "{$case['type']} list:");

            $component->call('toggleCatalogView');
            self::assertSame(CatalogTileColumns::VIEW_PANEL, $component->get('catalogView'));
            $tileHtml = $component->html();
            Html5DomAssert::assertNoModrinthAuthorLinks($tileHtml, "{$case['type']} tile:");
            Html5DomAssert::assertExternalProjectLinkPresent($tileHtml, $case['url'], "{$case['type']} tile:");
            Html5DomAssert::assertSingleAnchorPerTileCard($tileHtml, 2, "{$case['type']} tile:");
            Html5DomAssert::assertPureFilamentTileMetadataLayout($tileHtml, 2, "{$case['type']} tile:");
        }
    }

    public function test_catalog_view_persistence_swr_cache_key_and_zero_adhoc_css_constraints(): void
    {
        // Feature #3: Centralized CatalogTileColumns::storeView() writes session + queued cookie.
        self::assertTrue(
            method_exists(CatalogTileColumns::class, 'storeView'),
            'M1 requirement (#3): CatalogTileColumns::storeView() must centralize session and cookie persistence.',
        );

        session()->forget(CatalogTileColumns::SESSION_KEY);
        self::assertSame(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::resolveInitialView());

        $stored = CatalogTileColumns::storeView(CatalogTileColumns::VIEW_PANEL);
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $stored);
        self::assertSame(CatalogTileColumns::VIEW_PANEL, session()->get(CatalogTileColumns::SESSION_KEY));
        self::assertSame(CatalogTileColumns::VIEW_PANEL, CatalogTileColumns::resolveInitialView());

        $queuedCookie = Cookie::queued(CatalogTileColumns::COOKIE_KEY);
        self::assertNotNull($queuedCookie, 'CatalogTileColumns::storeView() must queue persistent view cookie.');
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $queuedCookie->getValue());

        // Feature #3: table-swr-cache.js keys by catalogView and removes stale Modrinth author URL comment.
        $swrJs = (string) file_get_contents(self::pluginRoot().'/resources/assets/table-swr-cache.js');
        self::assertMatchesRegularExpression('/describeViewState[\s\S]*?catalogView/', $swrJs);
        self::assertMatchesRegularExpression('/buildKey[\s\S]*?catalogView/', $swrJs);
        self::assertStringNotContainsString('the Modrinth author URL', $swrJs);

        // Feature #2 & §5: Zero ad-hoc CSS overrides targeting Filament tile layout classes.
        $css = (string) file_get_contents(self::pluginRoot().'/resources/assets/mod-manager.css');
        foreach (['.fi-ta-content-grid', '.fi-ta-split', '.fi-ta-stack', '.fi-ta-record-content'] as $forbiddenSelector) {
            self::assertStringNotContainsString(
                $forbiddenSelector,
                $css,
                "ORIGINAL_REQUEST §2/§5 forbids ad-hoc CSS override [{$forbiddenSelector}] in mod-manager.css.",
            );
        }
        self::assertFileDoesNotExist(self::pluginRoot().'/resources/assets/modpack-catalog.css');
        self::assertFileDoesNotExist(self::pluginRoot().'/resources/views/components/modpack-catalog.blade.php');
    }

    public function test_direct_tile_mount_switching_to_list_or_installed_preserves_toggleable_columns(): void
    {
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        $this->fakeModrinthCatalogEndpoints('modpack');

        // 1. ModpackCatalogTable mounted directly in tile view -> toggle to list view.
        $this->useAdminPanel();
        session()->put(CatalogTileColumns::SESSION_KEY, CatalogTileColumns::VIEW_PANEL);

        $modpackComponent = Livewire::test(ModpackCatalogTable::class, [
            'mode' => ModpackCatalogTable::MODE_CREATE,
            'provider' => ModpackProvider::Modrinth->value,
            'query' => 'optimized',
        ]);
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $modpackComponent->get('catalogView'));
        self::assertStringContainsString('fi-ta-content-grid', $modpackComponent->html());

        $modpackComponent->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_LIST, $modpackComponent->get('catalogView'));
        $modpackInstance = $modpackComponent->instance();
        self::assertInstanceOf(ModpackCatalogTable::class, $modpackInstance);
        $visibleModpackCols = array_keys($modpackInstance->getTable()->getVisibleColumns());
        self::assertContains('minecraft_versions', $visibleModpackCols, 'M1 (#4): minecraft_versions column lost on Tile->List switch.');
        self::assertContains('loaders', $visibleModpackCols, 'M1 (#4): loaders column lost on Tile->List switch.');
        self::assertContains('downloads', $visibleModpackCols, 'M1 (#4): downloads column lost on Tile->List switch.');
        $modpackListHtml = $modpackComponent->html();
        self::assertStringContainsString('1.21.1, 1.20.4 (+1)', $modpackListHtml);
        self::assertStringContainsString('Fabric', $modpackListHtml);

        // 2. ModManagerPage mounted directly in tile view -> toggle to list AND switch to Installed tab.
        $user = $this->createUser(rootAdmin: true);
        $this->actingAs($user);
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(12));
        $server = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1', 'LOADER_VERSION' => '0.16.10']);
        $this->seedServerFile($server, 'server.properties', "level-name=world\n");
        $this->useServerPanel($server);
        $this->fakeModrinthCatalogEndpoints('project');

        session()->put(CatalogTileColumns::SESSION_KEY, CatalogTileColumns::VIEW_PANEL);
        $page = Livewire::test(ModManagerPage::class);
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $page->get('catalogView'));

        $page->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_LIST, $page->get('catalogView'));
        $pageInstance = $page->instance();
        self::assertInstanceOf(ModManagerPage::class, $pageInstance);
        $visiblePageCols = array_keys($pageInstance->getTable()->getVisibleColumns());
        self::assertContains('author', $visiblePageCols, 'M1 (#4): author column lost on ModManagerPage Tile->List.');
        self::assertContains('downloads', $visiblePageCols, 'M1 (#4): downloads column lost on ModManagerPage Tile->List.');
        self::assertContains('date_modified', $visiblePageCols, 'M1 (#4): date_modified column lost on ModManagerPage Tile->List.');

        // Mount fresh in tile view, switch directly to Installed tab.
        session()->put(CatalogTileColumns::SESSION_KEY, CatalogTileColumns::VIEW_PANEL);
        $installedPage = Livewire::test(ModManagerPage::class);
        $installedPage->set('activeTab', 'installed');
        $installedHtml = $installedPage->html();
        self::assertStringContainsString('fi-ta-table', $installedHtml);
        self::assertStringNotContainsString('fi-ta-content-grid', $installedHtml);
        $installedInstance = $installedPage->instance();
        self::assertInstanceOf(ModManagerPage::class, $installedInstance);
        $visibleInstalledCols = array_keys($installedInstance->getTable()->getVisibleColumns());
        self::assertContains('author', $visibleInstalledCols, 'M1 (#4): author column lost on Catalog(tile)->Installed switch.');
    }

    private function fakeModrinthCatalogEndpoints(string $kind): void
    {
        $slug1 = $kind === 'modpack' ? 'fabulously-optimized' : 'sodium-e2e';
        $slug2 = $kind === 'modpack' ? 'simply-optimized' : 'lithium-e2e';

        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/tag/game_version' => Http::response([
                ['version' => '1.21.1', 'version_type' => 'release'],
                ['version' => '1.20.4', 'version_type' => 'release'],
            ]),
            'api.modrinth.com/v2/tag/category' => Http::response([]),
            'api.modrinth.com/v2/tag/loader' => Http::response([]),
            'api.modrinth.com/v2/search*' => Http::response([
                'hits' => [
                    [
                        'project_id' => '1KVo5zza',
                        'slug' => $slug1,
                        'title' => 'Fabulously Optimized',
                        'description' => 'Performance pack focusing on smooth rendering.',
                        'icon_url' => 'https://cdn.modrinth.com/data/1KVo5zza/icon.png',
                        'author' => 'robotkoer',
                        'downloads' => 1234567,
                        'date_modified' => '2026-10-01T12:00:00Z',
                        'versions' => ['1.21.1', '1.20.4', '1.20.1'],
                        'categories' => ['fabric', 'optimization'],
                    ],
                    [
                        'project_id' => '2AbCdEfG',
                        'slug' => $slug2,
                        'title' => 'Simply Optimized',
                        'description' => 'Minimal performance enhancements.',
                        'icon_url' => null,
                        'author' => 'hypervisor',
                        'downloads' => 98765,
                        'date_modified' => '2026-09-15T08:00:00Z',
                        'versions' => ['1.21.1'],
                        'categories' => ['fabric'],
                    ],
                ],
                'total_hits' => 2,
            ]),
        ]);
    }
}
