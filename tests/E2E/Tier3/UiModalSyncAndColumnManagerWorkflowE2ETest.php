<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier3;

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
 * Tier 3 — Cross-Feature Combinations for UI Features #1 + #2 + #3 + #4 (M1).
 */
#[RunTestsInSeparateProcesses]
final class UiModalSyncAndColumnManagerWorkflowE2ETest extends E2ETestCase
{
    public function test_child_modpack_modal_view_toggle_syncs_parent_mod_manager_page_and_preserves_columns_across_tabs(): void
    {
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        $user = $this->createUser(rootAdmin: true);
        $this->actingAs($user);

        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(100));
        $server = $this->createServer($fabricEgg, variableValues: ['MC_VERSION' => '1.21.1', 'LOADER_VERSION' => '0.16.10']);
        $this->seedServerFile($server, 'server.properties', "level-name=world\n");
        $this->useServerPanel($server);

        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/tag/*' => Http::response([['version' => '1.21.1', 'version_type' => 'release']]),
            'api.modrinth.com/v2/search*' => Http::response([
                'hits' => [[
                    'project_id' => 'SYNC1',
                    'slug' => 'sync-mod',
                    'title' => 'Sync Test Project',
                    'description' => 'Verifies modal <-> parent view synchronization.',
                    'icon_url' => null,
                    'author' => 'syncauthor',
                    'downloads' => 77777,
                    'date_modified' => '2026-10-12T10:00:00Z',
                    'versions' => ['1.21.1'],
                    'categories' => ['fabric'],
                ]],
                'total_hits' => 1,
            ]),
        ]);

        // 1. Mount parent ModManagerPage in clean default 'list' view
        session()->forget(CatalogTileColumns::SESSION_KEY);
        $parent = Livewire::test(ModManagerPage::class)
            ->set('activeTab', 'modrinth')
            ->call('loadTable');

        self::assertSame(CatalogTileColumns::VIEW_LIST, $parent->get('catalogView'));
        self::assertStringContainsString('fi-ta-table', $parent->html());

        // 2. Open child ModpackCatalogTable modal (MODE_SERVER) and toggle view to 'panel' (tile view)
        $modal = Livewire::test(ModpackCatalogTable::class, [
            'mode' => ModpackCatalogTable::MODE_SERVER,
            'provider' => ModpackProvider::Modrinth->value,
            'query' => 'sync',
        ]);
        self::assertSame(CatalogTileColumns::VIEW_LIST, $modal->get('catalogView'));
        $modal->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $modal->get('catalogView'));
        self::assertSame(CatalogTileColumns::VIEW_PANEL, session()->get(CatalogTileColumns::SESSION_KEY));

        $modalTileHtml = $modal->html();
        Html5DomAssert::assertNoModrinthAuthorLinks($modalTileHtml, 'Modal tile:');
        Html5DomAssert::assertSingleAnchorPerTileCard($modalTileHtml, 1, 'Modal tile:');
        Html5DomAssert::assertPureFilamentTileMetadataLayout($modalTileHtml, 1, 'Modal tile:');

        // 3. Deliver browser-side Livewire events dispatched by the child modal to the parent page
        // (matching Livewire's real client event bus), falling back to $refresh if no event was
        // dispatched so BOTH event-bus sync (M1) and hydrate-based sync pass while unsynced code fails.
        /** @var mixed $rawDispatches */
        $rawDispatches = $modal->effects['dispatches'] ?? [];
        $dispatches = is_array($rawDispatches) ? $rawDispatches : [];
        $relayed = false;
        foreach ($dispatches as $dispatch) {
            if (!is_array($dispatch)) {
                continue;
            }
            $eventName = $dispatch['name'] ?? null;
            if (!is_string($eventName) || $eventName === '') {
                continue;
            }
            $params = is_array($dispatch['params'] ?? null) ? $dispatch['params'] : [];
            $parent->dispatch($eventName, ...$params);
            $relayed = true;
        }
        if (!$relayed) {
            $parent->call('$refresh');
        }

        self::assertSame(
            CatalogTileColumns::VIEW_PANEL,
            $parent->get('catalogView'),
            'M1 (#3): Parent ModManagerPage must sync catalogView (via Livewire event or hydrate) after child ModpackCatalogTable toggles shared preference.',
        );
        $parentTileHtml = $parent->html();
        self::assertStringContainsString('fi-ta-content-grid', $parentTileHtml);
        Html5DomAssert::assertNoModrinthAuthorLinks($parentTileHtml, 'Parent synced tile:');
        Html5DomAssert::assertSingleAnchorPerTileCard($parentTileHtml, 1, 'Parent synced tile:');
        Html5DomAssert::assertPureFilamentTileMetadataLayout($parentTileHtml, 1, 'Parent synced tile:');

        // 4. Switch parent to Installed tab (forces list table + preserves toggleable columns) then back & toggle to list
        $parent->set('activeTab', 'installed')->call('loadTable');
        self::assertStringContainsString('fi-ta-table', $parent->html());
        self::assertStringNotContainsString('fi-ta-content-grid', $parent->html());
        $parentInstalled = $parent->instance();
        self::assertInstanceOf(ModManagerPage::class, $parentInstalled);
        self::assertContains('author', array_keys($parentInstalled->getTable()->getVisibleColumns()));

        $parent->set('activeTab', 'modrinth')->call('loadTable')->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_LIST, $parent->get('catalogView'));
        $parentList = $parent->instance();
        self::assertInstanceOf(ModManagerPage::class, $parentList);
        $visibleCols = array_keys($parentList->getTable()->getVisibleColumns());
        self::assertContains('author', $visibleCols);
        self::assertContains('downloads', $visibleCols);
        self::assertContains('date_modified', $visibleCols);
    }

    public function test_cross_provider_modpack_tiles_render_cleanly_across_modrinth_curseforge_ftb_and_technic(): void
    {
        $this->useAdminPanel();
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());

        $this->fakeUpstreamHttp([
            'api.modrinth.com/v2/tag/*' => Http::response([['version' => '1.21.1', 'version_type' => 'release']]),
            'api.modrinth.com/v2/search*' => Http::response([
                'hits' => [[
                    'project_id' => 'MR1',
                    'slug' => 'mr-pack',
                    'title' => 'MR Pack',
                    'description' => 'Modrinth pack',
                    'icon_url' => null,
                    'author' => 'mr_dev',
                    'downloads' => 50000,
                    'date_modified' => '2026-10-01T00:00:00Z',
                    'versions' => ['1.21.1'],
                    'categories' => ['fabric'],
                ]],
                'total_hits' => 1,
            ]),
            'api.curseforge.com/v1/mods/search*' => Http::response([
                'data' => [[
                    'id' => 925200,
                    'slug' => 'atm-10',
                    'name' => 'ATM 10',
                    'summary' => 'CurseForge pack',
                    'downloadCount' => 900000,
                    'dateModified' => '2026-10-02T00:00:00Z',
                    'authors' => [['name' => 'ATMTeam']],
                    'latestFilesIndexes' => [['gameVersion' => '1.21.1', 'modLoader' => 6]],
                ]],
                'pagination' => ['totalCount' => 1],
            ]),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/search/*' => Http::response(['packs' => [135]]),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/135' => Http::response([
                'id' => 135,
                'name' => 'FTB Evolution',
                'synopsis' => 'Modern FTB pack',
                'type' => 'Release',
                'installs' => 250000,
                'authors' => [['name' => 'FTB Team']],
                'versions' => [[
                    'id' => 100634,
                    'name' => '1.0.0',
                    'type' => 'Release',
                    'released' => 1760000000,
                    'targets' => [
                        ['type' => 'game', 'name' => 'minecraft', 'version' => '1.21.1'],
                        ['type' => 'modloader', 'name' => 'neoforge', 'version' => '21.1.266'],
                    ],
                ]],
            ]),
            'api.technicpack.net/search*' => Http::response([
                'modpacks' => [['slug' => 'tekkit-2', 'name' => 'Tekkit 2']],
            ]),
            'api.technicpack.net/modpack/tekkit-2*' => Http::response([
                'name' => 'tekkit-2',
                'displayName' => 'Tekkit 2',
                'version' => '1.2.2',
                'minecraft' => '1.12.2',
                'downloads' => 333000,
                'serverPackUrl' => 'https://servers.technicpack.net/Technic/servers/tekkit-2/Tekkit-2_Server_v1.2.2.zip',
            ]),
        ]);

        session()->put(CatalogTileColumns::SESSION_KEY, CatalogTileColumns::VIEW_PANEL);

        $providers = [
            [ModpackProvider::Modrinth->value, 'mr', 'https://modrinth.com/modpack/mr-pack'],
            [ModpackProvider::CurseForge->value, 'atm', 'https://www.curseforge.com/minecraft/modpacks/atm-10'],
            [ModpackProvider::Ftb->value, 'evolution', 'https://www.feed-the-beast.com/modpacks/135-ftb-evolution'],
            [ModpackProvider::Technic->value, 'tekkit', 'https://www.technicpack.net/modpack/tekkit-2'],
        ];

        foreach ($providers as [$provider, $query, $expectedUrl]) {
            $comp = Livewire::test(ModpackCatalogTable::class, [
                'mode' => ModpackCatalogTable::MODE_CREATE,
                'provider' => $provider,
                'query' => $query,
            ]);
            $html = $comp->html();
            Html5DomAssert::assertNoModrinthAuthorLinks($html, "Provider {$provider}:");
            Html5DomAssert::assertExternalProjectLinkPresent($html, $expectedUrl, "Provider {$provider}:");
            Html5DomAssert::assertSingleAnchorPerTileCard($html, 1, "Provider {$provider}:");
            Html5DomAssert::assertPureFilamentTileMetadataLayout($html, 1, "Provider {$provider}:");
        }
    }
}
