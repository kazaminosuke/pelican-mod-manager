<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Filament\Components\ModpackCatalogTable;
use Kazaminosuke\ModManager\Providers\ModManagerServiceProvider;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Support\CatalogFields;
use Kazaminosuke\ModManager\Support\CatalogTileColumns;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
final class ModpackCatalogTableTest extends TestCase
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
            'pelican-mod-manager.curseforge_api_key' => 'test-curseforge-credential',
            'queue.default' => 'database',
        ]);
        $this->app->register(ModManagerServiceProvider::class);
        View::addNamespace('pelican-mod-manager', dirname(__DIR__, 3).'/resources/views');
        $this->app['translator']->addNamespace('pelican-mod-manager', dirname(__DIR__, 3).'/lang');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            fn () => view('pelican-mod-manager::components.modpack-provider-select'),
            [ModpackCatalogTable::class],
        );
        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE,
            fn () => view('pelican-mod-manager::components.catalog-view-toggle'),
            [ModpackCatalogTable::class],
        );
        Http::preventStrayRequests();
    }

    /** @return array<string, array{ModpackProvider, string, ?string, ?string, ?string}> */
    public static function externalUrlCases(): array
    {
        return [
            'modrinth with slug' => [ModpackProvider::Modrinth, '1KVo5zza', 'fabulously-optimized', 'Fabulously Optimized', 'https://modrinth.com/modpack/fabulously-optimized'],
            'modrinth fallback to project id' => [ModpackProvider::Modrinth, '1KVo5zza', '', 'Fabulously Optimized', 'https://modrinth.com/modpack/1KVo5zza'],
            'modrinth rejects traversal slug' => [ModpackProvider::Modrinth, 'bad/id', '../evil', 'Bad', null],
            'curseforge with slug' => [ModpackProvider::CurseForge, '925200', 'all-the-mods-10', 'All the Mods 10', 'https://www.curseforge.com/minecraft/modpacks/all-the-mods-10'],
            'curseforge without slug returns null' => [ModpackProvider::CurseForge, '925200', '', 'All the Mods 10', null],
            'ftb with title' => [ModpackProvider::Ftb, '126', null, 'FTB Presents Direwolf20 1.21', 'https://www.feed-the-beast.com/modpacks/126-ftb-presents-direwolf20-121'],
            'ftb without title' => [ModpackProvider::Ftb, '126', null, '', 'https://www.feed-the-beast.com/modpacks/126'],
            'ftb rejects non-numeric id' => [ModpackProvider::Ftb, 'abc', null, 'Invalid', null],
            'technic with slug' => [ModpackProvider::Technic, 'tekkit-2', null, 'Tekkit 2', 'https://www.technicpack.net/modpack/tekkit-2'],
            'technic rejects traversal' => [ModpackProvider::Technic, '../tekkit', null, 'Bad', null],
            'atlauncher with safe name' => [ModpackProvider::Atlauncher, 'SevTechAges', null, 'SevTech: Ages', 'https://atlauncher.com/pack/SevTechAges'],
            'atlauncher rejects invalid characters' => [ModpackProvider::Atlauncher, 'SevTech/Ages', null, 'Bad', null],
        ];
    }

    #[DataProvider('externalUrlCases')]
    public function test_external_project_urls_resolve_safely_for_all_providers(
        ModpackProvider $provider,
        string $projectId,
        ?string $slug,
        ?string $title,
        ?string $expectedUrl,
    ): void {
        self::assertSame($expectedUrl, ModpackCatalog::externalProjectUrl($provider, $projectId, $slug, $title));
    }

    public function test_renders_unified_table_with_provider_dropdown_view_toggle_column_manager_and_right_aligned_tile_actions(): void
    {
        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([
                ['version' => '1.21.1', 'version_type' => 'release'],
            ]),
            'api.modrinth.com/v2/search*' => Http::response([
                'hits' => [
                    [
                        'project_id' => '1KVo5zza',
                        'slug' => 'fabulously-optimized',
                        'title' => 'Fabulously Optimized',
                        'description' => 'A simple Minecraft modpack focusing on performance and graphics enhancements.',
                        'icon_url' => 'https://cdn.modrinth.com/data/1KVo5zza/icon.png',
                        'author' => 'robotkoer',
                        'downloads' => 1234567,
                        'date_modified' => '2026-10-01T12:00:00Z',
                        'versions' => ['1.21.1', '1.20.4', '1.20.1'],
                        'categories' => ['fabric', 'optimization'],
                    ],
                    [
                        'project_id' => '2AbCdEfG',
                        'slug' => 'simply-optimized',
                        'title' => 'Simply Optimized',
                        'description' => 'Minimal performance pack.',
                        'icon_url' => null,
                        'author' => 'hypervisor',
                        'downloads' => 98765,
                        'date_modified' => '2026-09-15T08:00:00Z',
                        'versions' => [],
                        'categories' => [],
                    ],
                ],
                'total_hits' => 2,
            ]),
        ]);

        $component = Livewire::test(ModpackCatalogTable::class, [
            'provider' => ModpackProvider::Modrinth->value,
            'query' => 'optimized',
            'selectedKey' => 'modrinth:1KVo5zza',
            'targetStatePath' => 'data.mm_project',
            'titleStatePath' => 'data.mm_project_title',
            'versionStatePath' => 'data.mm_version',
        ]);

        $html = $component->html();

        // Shared scroll container, provider dropdown (not tabs), view toggle, column manager, Filament table, no source column.
        self::assertStringContainsString('mmr-table-scroll-ctn', $html);
        self::assertStringNotContainsString('fi-tabs', $html);
        self::assertStringContainsString('wire:model.live="provider"', $html);
        self::assertStringContainsString('data-mmr-view-toggle', $html);
        self::assertStringContainsString('fi-ta-col-manager', $html);
        self::assertStringContainsString('fi-ta-table', $html);
        self::assertStringNotContainsString('>Source<', $html);

        // External project page links open in a new tab via Filament's recordUrl.
        self::assertStringContainsString('href="https://modrinth.com/modpack/fabulously-optimized"', $html);
        self::assertStringContainsString('href="https://modrinth.com/modpack/simply-optimized"', $html);
        self::assertStringContainsString('target="_blank"', $html);

        // Minecraft versions and loaders render as plain text without badges/borders.
        self::assertStringContainsString('1.21.1, 1.20.4 (+1)', $html);
        self::assertStringContainsString('Fabric', $html);
        self::assertStringNotContainsString('fi-ta-text-has-badges', $html);

        // Existing Mod catalog compact row actions (versions + install_latest download button).
        self::assertStringContainsString('data-mmr-swr-row-action="versions"', $html);
        self::assertStringContainsString('data-mmr-swr-row-action="install_latest"', $html);

        // Switching to tile view uses Filament's contentGrid, keeps view toggle & column manager visible,
        // right-aligns row actions, moves author into the bottom metadata row, and omits MC version/loader.
        $component->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_PANEL, $component->get('catalogView'));
        $tileHtml = $component->html();
        self::assertStringContainsString('fi-ta-content-grid', $tileHtml);
        self::assertStringContainsString('fi-ta-split', $tileHtml);
        self::assertStringContainsString('fi-ta-stack', $tileHtml);
        self::assertStringContainsString('fi-align-end', $tileHtml);
        self::assertStringContainsString('data-mmr-view-toggle', $tileHtml);
        self::assertStringContainsString('fi-ta-col-manager', $tileHtml);
        self::assertStringContainsString('Fabulously Optimized', $tileHtml);
        self::assertStringContainsString('robotkoer', $tileHtml);
        self::assertStringNotContainsString('1.21.1, 1.20.4 (+1)', $tileHtml);

        // Switching back to table view restores the standard Filament table cleanly.
        $component->call('toggleCatalogView');
        self::assertSame(CatalogTileColumns::VIEW_LIST, $component->get('catalogView'));
        self::assertStringContainsString('fi-ta-table', $component->html());
        self::assertStringNotContainsString('fi-ta-content-grid', $component->html());
    }

    public function test_download_action_opens_filament_plan_modal_and_submitting_advances_to_next_step(): void
    {
        $this->app->instance(PluginBackgroundRunner::class, PluginBackgroundRunner::fake());
        Schema::create('eggs', function ($table): void {
            $table->id();
            $table->string('uuid')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('startup')->nullable();
            $table->text('docker_images')->nullable();
            $table->timestamps();
        });
        Schema::create('egg_variables', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('egg_id');
            $table->string('name');
            $table->string('env_variable');
            $table->string('default_value')->nullable();
            $table->timestamps();
        });
        DB::table('eggs')->insert([
            'id' => 7,
            'uuid' => 'neoforge-egg-uuid',
            'name' => 'NeoForge Server',
            'startup' => 'java -jar server.jar',
            'docker_images' => json_encode(['Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21']),
        ]);
        DB::table('egg_variables')->insert([
            ['egg_id' => 7, 'name' => 'MC Version', 'env_variable' => 'MC_VERSION', 'default_value' => 'latest'],
            ['egg_id' => 7, 'name' => 'NeoForge Version', 'env_variable' => 'NEOFORGE_VERSION', 'default_value' => 'latest'],
        ]);

        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([
                ['version' => '1.21.1', 'version_type' => 'release'],
            ]),
            'api.curseforge.com/v1/mods/search*' => Http::response([
                'data' => [[
                    'id' => 925200,
                    'slug' => 'all-the-mods-10',
                    'name' => 'All the Mods 10',
                    'summary' => 'Kitchen sink pack.',
                    'downloadCount' => 500000,
                    'latestFilesIndexes' => [['gameVersion' => '1.21.1', 'modLoader' => 6]],
                ]],
                'pagination' => ['totalCount' => 1],
            ]),
            'api.curseforge.com/v1/mods/925200' => Http::response([
                'data' => [
                    'id' => 925200,
                    'slug' => 'all-the-mods-10',
                    'name' => 'All the Mods 10',
                    'summary' => 'Kitchen sink pack.',
                ],
            ]),
            'api.curseforge.com/v1/mods/925200/files?*' => Http::response([
                'data' => [[
                    'id' => 9620701,
                    'fileName' => 'ATM10-8.6.zip',
                    'displayName' => 'ATM10-8.6.zip',
                    'releaseType' => 1,
                    'downloadCount' => 12345,
                    'fileDate' => '2026-10-01T12:00:00Z',
                    'gameVersions' => ['1.21.1', 'NeoForge'],
                    'downloadUrl' => 'https://edge.forgecdn.net/files/9620/701/ATM10-8.6.zip',
                ]],
            ]),
            'api.curseforge.com/v1/mods/925200/files/9620701' => Http::response([
                'data' => [
                    'id' => 9620701,
                    'fileName' => 'ATM10-8.6.zip',
                    'displayName' => 'ATM10-8.6.zip',
                    'releaseType' => 1,
                    'gameVersions' => ['1.21.1', 'NeoForge'],
                    'downloadUrl' => 'https://edge.forgecdn.net/files/9620/701/ATM10-8.6.zip',
                ],
            ]),
            'edge.forgecdn.net/*' => Http::response('', 404),
        ]);

        // Create mode: clicking install_latest opens the Filament reviewModpackPlan modal instead of appending below catalog.
        $create = Livewire::test(ModpackCatalogTable::class, [
            'mode' => ModpackCatalogTable::MODE_CREATE,
            'provider' => ModpackProvider::CurseForge->value,
            'query' => 'All the Mods',
            'targetStatePath' => 'data.mm_project',
            'titleStatePath' => 'data.mm_project_title',
            'versionStatePath' => 'data.mm_version',
            'eggStatePath' => 'data.mm_egg',
            'confirmStatePath' => 'data.mm_confirm_compatibility',
        ]);

        // Versions modal lists releases like the Mod catalog.
        $create->mountTableAction('versions', 'curseforge:925200');
        $modalHtml = $create->getMountedActionModalHtml();
        self::assertStringContainsString('ATM10-8.6.zip', $modalHtml);
        self::assertStringContainsString('install_version_0', $modalHtml);
        $create->unmountAction();

        // Clicking install_latest mounts Filament's reviewModpackPlan modal with plan details & compatibility warning.
        $create->callTableAction('install_latest', 'curseforge:925200');
        self::assertSame('reviewModpackPlan', $create->instance()->getMountedAction()?->getName());
        $planHtml = $create->getMountedActionModalHtml();
        self::assertStringContainsString('All the Mods 10', $planHtml);
        self::assertStringContainsString('1.21.1', $planHtml);
        self::assertStringContainsString('NeoForge', $planHtml);
        self::assertStringContainsString('Java 21', $planHtml);
        self::assertStringContainsString('NeoForge Server', $planHtml);
        self::assertStringContainsString('Server compatibility could not be verified for this release.', $planHtml);
        self::assertStringContainsString('I want to try installing this release.', $planHtml);

        // Submitting the plan modal with Egg selection & confirmation sets parent fields and advances Filament Wizard step.
        $create->set('mountedActions.0.data.egg_id', '7')
            ->set('mountedActions.0.data.confirm_compatibility', true)
            ->callMountedAction()
            ->assertHasNoErrors();
        self::assertNull($create->instance()->getMountedAction());
        $jsCalls = $create->effects['xjs'] ?? [];
        $joinedJs = implode("\n", array_map(static fn (mixed $item): string => is_array($item) ? (string) ($item['expression'] ?? '') : (string) $item, $jsCalls));
        self::assertStringContainsString('curseforge:925200', $joinedJs);
        self::assertStringContainsString('9620701', $joinedJs);
        self::assertStringContainsString('data.mm_egg', $joinedJs);
        self::assertStringContainsString('data.mm_confirm_compatibility', $joinedJs);
        self::assertStringContainsString('requestNextStep', $joinedJs);

        // Server mode: install_latest opens reviewModpackPlan modal and submitting calls ModManagerPage::selectModpackRelease().
        Schema::create('servers', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('egg_id')->nullable();
            $table->string('image')->nullable();
            $table->string('startup')->nullable();
            $table->timestamps();
        });
        Schema::create('server_variables', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('variable_id');
            $table->string('variable_value')->nullable();
            $table->timestamps();
        });
        DB::table('servers')->insert([
            'id' => 10,
            'egg_id' => 7,
            'image' => 'ghcr.io/pelican-eggs/yolks:java_21',
            'startup' => 'java -jar server.jar',
        ]);

        $server = Livewire::test(ModpackCatalogTable::class, [
            'mode' => ModpackCatalogTable::MODE_SERVER,
            'provider' => ModpackProvider::CurseForge->value,
            'query' => 'All the Mods',
            'serverId' => 10,
            'targetStatePath' => 'mountedActions.0.data.project',
            'titleStatePath' => 'mountedActions.0.data.project_title',
            'versionStatePath' => 'mountedActions.0.data.version',
        ]);
        $server->callTableAction('install_latest', 'curseforge:925200');
        self::assertSame('reviewModpackPlan', $server->instance()->getMountedAction()?->getName());
        $serverPlanHtml = $server->getMountedActionModalHtml();
        self::assertStringContainsString('All the Mods 10', $serverPlanHtml);
        self::assertStringContainsString('NeoForge Server', $serverPlanHtml);
        self::assertStringContainsString('No dedicated server ZIP was listed.', $serverPlanHtml);
        $server->set('mountedActions.0.data.confirm_compatibility', true)
            ->callMountedAction()
            ->assertHasNoErrors();
        $serverJsCalls = $server->effects['xjs'] ?? [];
        $serverJoinedJs = implode("\n", array_map(static fn (mixed $item): string => is_array($item) ? (string) ($item['expression'] ?? '') : (string) $item, $serverJsCalls));
        self::assertStringContainsString('selectModpackRelease', $serverJoinedJs);
        self::assertStringContainsString('9620701', $serverJoinedJs);
        self::assertStringContainsString('true', $serverJoinedJs);
    }

    public function test_explicit_all_version_filter_omits_minecraft_version_constraint(): void
    {
        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([
                ['version' => '1.21.1', 'version_type' => 'release'],
            ]),
            'api.modrinth.com/v2/search*' => Http::response([
                'hits' => [],
                'total_hits' => 0,
            ]),
        ]);

        Livewire::test(ModpackCatalogTable::class, [
            'provider' => ModpackProvider::Modrinth->value,
            'minecraft' => CatalogFields::VERSION_ALL,
            'targetStatePath' => 'data.mm_project',
        ]);

        Http::assertSent(static function ($request): bool {
            if (!str_contains($request->url(), 'api.modrinth.com/v2/search')) {
                return false;
            }

            return !str_contains($request->url(), 'versions%3A')
                && !str_contains($request->url(), 'versions:');
        });
    }

    public function test_renders_external_links_for_curseforge_ftb_technic_and_atlauncher(): void
    {
        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([]),
            'api.curseforge.com/v1/mods/search*' => Http::response([
                'data' => [[
                    'id' => 925200,
                    'slug' => 'all-the-mods-10',
                    'name' => 'All the Mods 10',
                    'summary' => 'Kitchen sink pack.',
                    'downloadCount' => 500000,
                    'latestFilesIndexes' => [['gameVersion' => '1.21.1', 'modLoader' => 6]],
                ]],
                'pagination' => ['totalCount' => 1],
            ]),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/search/*' => Http::response(['packs' => [126]]),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/126' => Http::response([
                'id' => 126,
                'name' => 'FTB Direwolf20',
                'synopsis' => 'Official Direwolf20 pack.',
                'plays' => 42000,
                'versions' => [[
                    'id' => 1001,
                    'name' => '1.0.0',
                    'updated' => 1700000000,
                    'targets' => [
                        ['type' => 'game', 'name' => 'minecraft', 'version' => '1.21.1'],
                        ['type' => 'modloader', 'name' => 'neoforge', 'version' => '21.1.1'],
                    ],
                ]],
            ]),
            'api.technicpack.net/search?*' => Http::response([
                'modpacks' => [['slug' => 'tekkit-2', 'name' => 'Tekkit 2']],
            ]),
            'api.atlauncher.com/v1/packs/simple' => Http::response([
                'data' => [['type' => 'public', 'name' => 'SevTech: Ages', 'safeName' => 'SevTechAges']],
            ]),
        ]);

        $cases = [
            [ModpackProvider::CurseForge->value, 'All the Mods', 'https://www.curseforge.com/minecraft/modpacks/all-the-mods-10'],
            [ModpackProvider::Ftb->value, 'Direwolf', 'https://www.feed-the-beast.com/modpacks/126-ftb-direwolf20'],
            [ModpackProvider::Technic->value, 'Tekkit', 'https://www.technicpack.net/modpack/tekkit-2'],
            [ModpackProvider::Atlauncher->value, 'SevTech', 'https://atlauncher.com/pack/SevTechAges'],
        ];

        foreach ($cases as [$provider, $query, $expectedUrl]) {
            $html = Livewire::test(ModpackCatalogTable::class, [
                'provider' => $provider,
                'query' => $query,
                'targetStatePath' => 'data.mm_project',
            ])->html();

            self::assertStringContainsString('href="'.$expectedUrl.'"', $html, "Missing external link for provider {$provider}");
            self::assertStringContainsString('target="_blank"', $html, "Missing new-tab target for provider {$provider}");
        }
    }

    public function test_renders_appropriate_empty_state_messages(): void
    {
        Http::fake([
            'api.modrinth.com/v2/tag/game_version' => Http::response([]),
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [], 'total_hits' => 0]),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/search/*' => Http::response([], 503),
        ]);

        // Search prompt when a search-only provider has no query yet.
        Livewire::test(ModpackCatalogTable::class, [
            'provider' => ModpackProvider::Ftb->value,
            'query' => '',
            'targetStatePath' => 'data.mm_project',
        ])->assertSee(trans('pelican-mod-manager::strings.modpacks.catalog_search_prompt'));

        // Empty state when a search returns no hits.
        Livewire::test(ModpackCatalogTable::class, [
            'provider' => ModpackProvider::Modrinth->value,
            'query' => 'nonexistent',
            'targetStatePath' => 'data.mm_project',
        ])->assertSee(trans('pelican-mod-manager::strings.modpacks.catalog_empty'));

        // Unavailable message on upstream HTTP failure.
        Livewire::test(ModpackCatalogTable::class, [
            'provider' => ModpackProvider::Ftb->value,
            'query' => 'direwolf',
            'targetStatePath' => 'data.mm_project',
        ])->assertSee(trans('pelican-mod-manager::strings.modpacks.catalog_unavailable'));
    }
}
