<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Filament\Components\ModpackCatalogTable;
use Kazaminosuke\ModManager\Providers\ModManagerServiceProvider;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
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
        ]);
        $this->app->register(ModManagerServiceProvider::class);
        View::addNamespace('pelican-mod-manager', dirname(__DIR__, 3).'/resources/views');
        $this->app['translator']->addNamespace('pelican-mod-manager', dirname(__DIR__, 3).'/lang');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
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

    public function test_renders_filament_table_with_external_links_metadata_and_separate_select_action(): void
    {
        Http::fake([
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
        ]);

        $html = $component->html();

        // Standard Filament Table markup, no source column.
        self::assertStringContainsString('fi-ta-table', $html);
        self::assertStringNotContainsString('>Source<', $html);

        // External project page links open in a new tab via Filament's recordUrl.
        self::assertStringContainsString('href="https://modrinth.com/modpack/fabulously-optimized"', $html);
        self::assertStringContainsString('href="https://modrinth.com/modpack/simply-optimized"', $html);
        self::assertStringContainsString('target="_blank"', $html);

        // Modpack metadata columns render API values without guessing missing values.
        self::assertStringContainsString('Fabulously Optimized', $html);
        self::assertStringContainsString('1.21.1', $html);
        self::assertStringContainsString('Fabric', $html);
        self::assertStringContainsString('1,234,567', $html);
        self::assertStringContainsString('Simply Optimized', $html);

        // Selection state and separate Alpine click handler targeting parent form state.
        self::assertStringContainsString('Selected', $html);
        self::assertStringContainsString('Select', $html);
        self::assertStringContainsString('fi-striped', $html);
        self::assertStringContainsString('$wire.$parent.$set(', $html);
        self::assertStringContainsString('modrinth:1KVo5zza', $html);
        self::assertStringContainsString('modrinth:2AbCdEfG', $html);
    }

    public function test_renders_external_links_for_curseforge_ftb_technic_and_atlauncher(): void
    {
        Http::fake([
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
