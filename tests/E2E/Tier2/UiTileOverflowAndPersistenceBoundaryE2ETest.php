<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier2;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Filament\Components\ModpackCatalogTable;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Support\CatalogTileColumns;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use Kazaminosuke\ModManager\Tests\E2E\Support\Html5DomAssert;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tier 2 — Boundary & Corner Cases for UI Features #1, #2, #3, #4 (M1).
 */
#[RunTestsInSeparateProcesses]
final class UiTileOverflowAndPersistenceBoundaryE2ETest extends E2ETestCase
{
    public function test_55_char_unbreakable_author_multi_locale_dates_and_null_author_render_intact_filament_tiles(): void
    {
        $this->useAdminPanel();
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        $longAuthor = 'SuperLongUnbreakableModrinthAuthorIdentifierForLayoutTest';
        self::assertSame(57, strlen($longAuthor));

        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/tag/game_version' => Http::response([
                ['version' => '1.21.1', 'version_type' => 'release'],
            ]),
            'api.modrinth.com/v2/search*' => Http::response([
                'hits' => [
                    [
                        'project_id' => 'LONG0001',
                        'slug' => 'long-author-pack',
                        'title' => 'Long Author Stress Test Pack',
                        'description' => 'Stress tests 57-char unbreakable author and large download counters across locales.',
                        'icon_url' => null,
                        'author' => $longAuthor,
                        'downloads' => 123456789,
                        'date_modified' => '2026-11-24T23:59:59Z',
                        'versions' => ['1.21.1'],
                        'categories' => ['fabric'],
                    ],
                    [
                        'project_id' => 'SPEC0002',
                        'slug' => '',
                        'title' => 'Special Chars & Fallback Slug Pack',
                        'description' => 'Author with special characters and missing slug fallback.',
                        'icon_url' => null,
                        'author' => 'Dev_Team+2026 (QA)',
                        'downloads' => 42,
                        'date_modified' => '2026-01-05T00:00:00Z',
                        'versions' => ['1.21.1'],
                        'categories' => ['neoforge'],
                    ],
                ],
                'total_hits' => 2,
            ]),
            'api.atlauncher.com/v1/packs/simple' => Http::response([
                'data' => [
                    [
                        'id' => 1,
                        'type' => 'public',
                        'safeName' => 'NullAuthorPack',
                        'name' => 'Null Author ATLauncher Pack',
                        'description' => 'ATLauncher packs have null author.',
                    ],
                ],
            ]),
        ]);

        foreach (['en', 'ja', 'de'] as $locale) {
            $this->app->setLocale($locale);
            session()->put(CatalogTileColumns::SESSION_KEY, CatalogTileColumns::VIEW_PANEL);

            $component = Livewire::test(ModpackCatalogTable::class, [
                'mode' => ModpackCatalogTable::MODE_CREATE,
                'provider' => ModpackProvider::Modrinth->value,
                'query' => 'stress',
            ]);

            $tileHtml = $component->html();
            Html5DomAssert::assertNoModrinthAuthorLinks($tileHtml, "Locale {$locale}:");
            Html5DomAssert::assertExternalProjectLinkPresent($tileHtml, 'https://modrinth.com/modpack/long-author-pack', "Locale {$locale}:");
            Html5DomAssert::assertExternalProjectLinkPresent($tileHtml, 'https://modrinth.com/modpack/SPEC0002', "Locale {$locale} missing-slug fallback:");
            Html5DomAssert::assertSingleAnchorPerTileCard($tileHtml, 2, "Locale {$locale}:");
            Html5DomAssert::assertPureFilamentTileMetadataLayout($tileHtml, 2, "Locale {$locale}:");
            self::assertStringContainsString($longAuthor, $tileHtml);
            self::assertMatchesRegularExpression('/123[,.]456[,.]789/', $tileHtml);
        }

        // Null author on ATLauncher renders cleanly in tile view without crashing or breaking DOM
        $this->app->setLocale('en');
        session()->put(CatalogTileColumns::SESSION_KEY, CatalogTileColumns::VIEW_PANEL);
        $atComponent = Livewire::test(ModpackCatalogTable::class, [
            'mode' => ModpackCatalogTable::MODE_CREATE,
            'provider' => ModpackProvider::Atlauncher->value,
            'query' => 'null',
        ]);
        $atTileHtml = $atComponent->html();
        Html5DomAssert::assertNoModrinthAuthorLinks($atTileHtml, 'ATLauncher null author:');
        Html5DomAssert::assertSingleAnchorPerTileCard($atTileHtml, 1, 'ATLauncher null author:');
        Html5DomAssert::assertPureFilamentTileMetadataLayout($atTileHtml, 1, 'ATLauncher null author:');
    }

    public function test_corrupt_and_xss_session_or_cookie_view_values_normalize_to_list_and_session_overrides_cookie(): void
    {
        foreach (['', 'grid', 'tile', '<script>alert(1)</script>', '../panel', 'PANEL'] as $bogus) {
            self::assertSame(
                CatalogTileColumns::VIEW_LIST,
                CatalogTileColumns::normalizeView($bogus),
                "Bogus view value [{$bogus}] must normalize to 'list'.",
            );

            session()->put(CatalogTileColumns::SESSION_KEY, $bogus);
            self::assertSame(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::resolveInitialView());
        }

        // Cookie fallback when session is empty
        session()->forget(CatalogTileColumns::SESSION_KEY);
        $reqWithPanelCookie = Request::create('/test', 'GET', [], [CatalogTileColumns::COOKIE_KEY => CatalogTileColumns::VIEW_PANEL]);
        $this->app->instance('request', $reqWithPanelCookie);
        self::assertSame(CatalogTileColumns::VIEW_PANEL, CatalogTileColumns::resolveInitialView());

        // Session takes precedence over cookie when both exist
        session()->put(CatalogTileColumns::SESSION_KEY, CatalogTileColumns::VIEW_LIST);
        self::assertSame(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::resolveInitialView());

        // Corrupt cookie when session is empty normalizes safely to list
        session()->forget(CatalogTileColumns::SESSION_KEY);
        $reqWithBogusCookie = Request::create('/test', 'GET', [], [CatalogTileColumns::COOKIE_KEY => '<img src=x>']);
        $this->app->instance('request', $reqWithBogusCookie);
        self::assertSame(CatalogTileColumns::VIEW_LIST, CatalogTileColumns::resolveInitialView());
    }

    public function test_repeated_view_toggles_and_catalog_installed_catalog_tab_cycles_never_lose_columns(): void
    {
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        $user = $this->createUser(rootAdmin: true);
        $this->actingAs($user);

        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(15));
        $server = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1', 'LOADER_VERSION' => '0.16.10']);
        $this->seedServerFile($server, 'server.properties', "level-name=world\n");
        $this->useServerPanel($server);

        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/tag/*' => Http::response([['version' => '1.21.1', 'version_type' => 'release']]),
            'api.modrinth.com/v2/search*' => Http::response([
                'hits' => [[
                    'project_id' => 'CYC1',
                    'slug' => 'cycle-mod',
                    'title' => 'Cycle Mod',
                    'description' => 'Tests repeated toggles.',
                    'icon_url' => null,
                    'author' => 'cycler',
                    'downloads' => 5000,
                    'date_modified' => '2026-10-01T00:00:00Z',
                    'versions' => ['1.21.1'],
                    'categories' => ['fabric'],
                ]],
                'total_hits' => 1,
            ]),
        ]);

        // Start in tile view directly
        session()->put(CatalogTileColumns::SESSION_KEY, CatalogTileColumns::VIEW_PANEL);
        $page = Livewire::test(ModManagerPage::class)
            ->set('activeTab', 'modrinth')
            ->call('loadTable');

        self::assertSame(CatalogTileColumns::VIEW_PANEL, $page->get('catalogView'));
        self::assertStringContainsString('fi-ta-content-grid', $page->html());

        // Cycle: panel -> list -> panel -> list
        $page->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_LIST, $page->get('catalogView'));
        $page->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $page->get('catalogView'));
        $page->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_LIST, $page->get('catalogView'));

        $pageInstance = $page->instance();
        self::assertInstanceOf(ModManagerPage::class, $pageInstance);
        $visibleAfterToggles = array_keys($pageInstance->getTable()->getVisibleColumns());
        self::assertContains('author', $visibleAfterToggles);
        self::assertContains('downloads', $visibleAfterToggles);
        self::assertContains('date_modified', $visibleAfterToggles);

        // Switch back to panel, then jump Catalog(tile) -> Installed -> Catalog(modrinth)
        $page->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $page->get('catalogView'));

        $page->set('activeTab', 'installed')->call('loadTable');
        $installedHtml = $page->html();
        self::assertStringContainsString('fi-ta-table', $installedHtml);
        self::assertStringNotContainsString('fi-ta-content-grid', $installedHtml);
        $installedInstance = $page->instance();
        self::assertInstanceOf(ModManagerPage::class, $installedInstance);
        self::assertContains('author', array_keys($installedInstance->getTable()->getVisibleColumns()));

        $page->set('activeTab', 'modrinth')->call('loadTable');
        self::assertStringContainsString('fi-ta-content-grid', $page->html());
        $page->call('toggleCatalogView');
        $finalInstance = $page->instance();
        self::assertInstanceOf(ModManagerPage::class, $finalInstance);
        self::assertContains('downloads', array_keys($finalInstance->getTable()->getVisibleColumns()));
    }
}
