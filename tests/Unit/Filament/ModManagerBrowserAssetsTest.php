<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModManagerBrowserAssetsTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function javascriptAssets(): array
    {
        return [
            'runtime' => ['mod-manager-runtime.js'],
            'layout' => ['table-layout.js'],
            'swr cache' => ['table-swr-cache.js'],
            'history' => ['catalog-url-history.js'],
        ];
    }

    #[DataProvider('javascriptAssets')]
    public function test_manager_javascript_is_external_and_not_a_blade_payload(string $asset): void
    {
        $source = $this->asset($asset);

        self::assertStringNotContainsString('<script', $source);
        self::assertStringNotContainsString('@js(', $source);
        self::assertStringContainsString("'use strict'", $source);
    }

    public function test_livewire_hooks_are_component_scoped_and_released_on_navigation(): void
    {
        $layout = $this->asset('table-layout.js');
        $swr = $this->asset('table-swr-cache.js');
        $history = $this->asset('catalog-url-history.js');

        self::assertStringContainsString('const contextByComponent = new WeakMap()', $layout);
        self::assertStringContainsString('contextByComponent.get(component?.el)', $layout);
        self::assertStringContainsString("document.addEventListener('livewire:navigating', deactivate)", $layout);

        self::assertStringContainsString('const heldContentControllers = new WeakMap()', $swr);
        self::assertStringContainsString('const heldPaginationControllers = new WeakMap()', $swr);
        self::assertStringContainsString('activeComponents.has(component?.el)', $swr);
        self::assertStringContainsString("const CELL_SELECTOR = 'td[data-mmr-swr-cell]'", $swr);
        self::assertStringContainsString("querySelectorAll('tbody > tr.fi-ta-row')", $swr);
        self::assertStringContainsString('morphUnsubscribes.splice(0)', $swr);
        self::assertStringNotContainsString('documentObserver.observe(document.documentElement', $swr);
        self::assertStringNotContainsString('for (const wrapper of document.querySelectorAll(WRAPPER_SELECTOR))', $swr);

        self::assertStringContainsString('commitUnsubscribe?.()', $history);
        self::assertStringContainsString('window.history.pushState = originalPushState', $history);
        self::assertStringContainsString("document.addEventListener('livewire:navigating', deactivate)", $history);

        $runtime = $this->asset('mod-manager-runtime.js');
        self::assertStringContainsString("document.addEventListener('livewire:navigating'", $runtime);
        self::assertStringContainsString('cancelAnimationFrame(headerScrollFrame)', $runtime);
    }

    public function test_row_action_mask_matches_old_zip_xl_icon_size(): void
    {
        $css = $this->asset('mod-manager.css');

        self::assertDoesNotMatchRegularExpression(
            '/\.mmr-row-action\{[^}]*width:2\.25rem;height:2\.25rem;padding:0/',
            $css,
        );
        self::assertStringContainsString('.mmr-row-action{flex-shrink:0;}', $css);
        self::assertStringContainsString('.mmr-row-action-icon{display:block;width:1.75rem;height:1.75rem;flex:0 0 1.75rem;', $css);
        self::assertStringNotContainsString('width:1.25rem;height:1.25rem;flex:0 0 1.25rem;background:currentColor;-webkit-mask:var(--mmr-row-action-mask)', $css);
        self::assertStringContainsString('-webkit-mask:var(--mmr-row-action-mask)', $css);
        self::assertStringContainsString('mask:var(--mmr-row-action-mask)', $css);
        foreach (['versions', 'install_latest', 'update', 'installed', 'uninstall'] as $action) {
            self::assertStringContainsString('data-mmr-swr-row-action="'.$action.'"', $css);
        }
    }

    public function test_catalog_toolbar_uses_column_manager_and_single_view_toggle(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Server/Pages/ModManagerPage.php');
        $plugin = (string) file_get_contents(dirname(__DIR__, 3).'/src/ModManagerPlugin.php');
        $runtime = $this->asset('mod-manager-runtime.js');
        $css = $this->asset('mod-manager.css');

        self::assertStringNotContainsString('mmr-table-action-registration', $page);
        self::assertStringNotContainsString("mountTableAction(\\'catalog_compatibility_override\\')", $plugin);
        self::assertStringNotContainsString("mountAction(\\'catalogCompatibilityOverride\\')", $plugin);
        self::assertStringNotContainsString('data-mmr-compatibility-override', $plugin);
        self::assertSame(1, substr_count($plugin, 'data-mmr-view-toggle'));
        self::assertStringNotContainsString('mmr-catalog-toolbar-actions', $plugin);
        self::assertStringNotContainsString('mmr-catalog-view-toggle', $plugin);
        self::assertStringNotContainsString('data-mmr-view-mode=', $plugin);
        self::assertStringContainsString("Action::make('catalogViewToggle')", $plugin);
        self::assertStringContainsString('->iconButton()', $plugin);
        self::assertStringContainsString("->icon('tabler-layout-grid')", $plugin);
        self::assertStringContainsString("->alpineClickHandler('null')", $plugin);
        self::assertStringContainsString('TablesRenderHook::TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE', $plugin);
        self::assertStringNotContainsString('TablesRenderHook::TOOLBAR_COLUMN_MANAGER_TRIGGER_AFTER', $plugin);
        self::assertStringContainsString("document.querySelector('[data-mmr-view-toggle]')", $runtime);
        self::assertStringContainsString("const target = view === 'panel' ? 'list' : 'panel'", $runtime);
        self::assertStringContainsString("toggle.querySelector(':scope > svg.fi-icon')", $runtime);
        self::assertStringNotContainsString("querySelectorAll('[data-mmr-view-icon]')", $runtime);
        self::assertStringNotContainsString('.mmr-catalog-toolbar-button', $css);
        self::assertStringNotContainsString('.mmr-catalog-toolbar-icon-button', $css);
        self::assertStringNotContainsString('[data-mmr-override-active="true"]{border-color:', $css);
        self::assertStringNotContainsString('.fi-ta-col-manager-dropdown{order:', $css);
        self::assertStringNotContainsString('[data-mmr-view-toggle]{order:', $css);
        self::assertStringContainsString('data-mmr-active-filters', $page);
        self::assertStringContainsString('[data-mmr-active-filters="0"] .fi-icon-btn-badge-ctn{display:none;}', $css);
        self::assertStringContainsString("CatalogSelectFilter::make('catalog_version')", $page);
        self::assertStringContainsString("CatalogSelectFilter::make('catalog_loader')", $page);
        self::assertStringContainsString('->activeCountExcludedValues(fn (): array => $this->catalogDefaultVersionValues())', $page);
        self::assertStringContainsString('->activeCountExcludedValues(fn (): array => $this->catalogDefaultLoaderValues())', $page);
    }

    public function test_catalog_filters_are_compact_and_reuse_compatibility_as_placeholders(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Server/Pages/ModManagerPage.php');

        self::assertStringContainsString("->filtersFormColumns(['default' => 1, 'md' => 2])", $page);
        self::assertStringContainsString("->filtersFormMaxHeight('calc(100dvh - 8rem)')", $page);
        self::assertStringContainsString('->placeholder(fn (): string => $this->catalogVersionFilterPlaceholder())', $page);
        self::assertStringContainsString("SelectFilter::make('catalog_version')", $page);
        self::assertStringContainsString('->default(fn (): array => $this->catalogDefaultVersionValues())', $page);
        self::assertStringContainsString('->default(fn (): array => $this->catalogDefaultLoaderValues())', $page);
        self::assertStringContainsString('->displayFormat(fn (): ?string => $this->catalogDateDisplayFormat())', $page);
        self::assertStringContainsString("return app()->getLocale() === 'ja' ? 'Y年n月j日以降' : null;", $page);
        self::assertStringContainsString("return \$date->format('Y年n月j日').'以降';", $page);
        self::assertStringNotContainsString("->placeholder(trans('pelican-mod-manager::strings.table.filters.date_placeholder'))", $page);
        self::assertStringNotContainsString("'date_placeholder'", (string) file_get_contents(dirname(__DIR__, 3).'/lang/ja/strings.php'));
        self::assertStringContainsString("'versions' => \$this->catalogFilterValues(\$state, 'catalog_version')", $page);
        self::assertStringContainsString("'exclude_disclosures' => '除外'", (string) file_get_contents(dirname(__DIR__, 3).'/lang/ja/strings.php'));
        self::assertStringContainsString("->placeholder(trans('pelican-mod-manager::strings.table.filters.none'))", $page);
        self::assertStringContainsString('->placeholder(fn (): string => $this->catalogLoaderFilterPlaceholder())', $page);
        self::assertStringContainsString('return MinecraftVersionResolver::resolve($server) ?? $this->catalogAllFilterPlaceholder();', $page);
        self::assertStringContainsString('$loader = MinecraftLoader::fromServer($server);', $page);
        self::assertStringContainsString("Filter::make('catalog_advanced')", $page);
        self::assertStringContainsString("->columns(['default' => 1, 'md' => 2])", $page);
        self::assertStringContainsString('->columnSpanFull()', $page);
        self::assertStringContainsString('protected function normalizeCatalogMultiSelectState(): void', $page);
        self::assertStringNotContainsString("Section::make(trans('pelican-mod-manager::strings.table.filters.advanced'))", $page);
    }

    public function test_catalog_list_keeps_the_original_table_columns_and_panel_is_css_scoped(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Server/Pages/ModManagerPage.php');
        $css = $this->asset('mod-manager.css');

        self::assertStringNotContainsString('use Filament\\Tables\\Columns\\Layout\\Split;', $page);
        self::assertStringNotContainsString('use Filament\\Tables\\Columns\\Layout\\Stack;', $page);
        self::assertStringContainsString("->extraCellAttributes(['data-mmr-swr-cell' => 'icon', 'class' => 'mmr-project-icon-cell'])", $page);
        self::assertStringContainsString('->description(function (array $record): ?string {', $page);
        self::assertSame(4, substr_count($page, '->toggleable()'));
        self::assertStringNotContainsString('mmr-record-', $page);
        self::assertStringNotContainsString('.fi-ta-record-content-ctn', $css);
        self::assertStringContainsString('.mmr-project-icon-cell .fi-ta-image img{display:block;inline-size:4.5cqi;', $css);
        self::assertStringContainsString('[data-mmr-catalog-view="panel"] .fi-ta-table>tbody{display:grid;', $css);
        self::assertStringContainsString('@container (min-width:36rem)', $css);
        self::assertStringContainsString('repeat(2,minmax(0,1fr))', $css);
        self::assertStringContainsString('@container (min-width:54rem)', $css);
        self::assertStringContainsString('repeat(3,minmax(0,1fr))', $css);
        self::assertStringNotContainsString('repeat(auto-fit', $css);
    }

    public function test_all_manager_assets_have_a_deterministic_compressed_size_budget(): void
    {
        $assets = [
            'mod-manager.css',
            ...array_column(self::javascriptAssets(), 0),
        ];
        $raw = 0;
        $gzip = 0;

        foreach ($assets as $asset) {
            $contents = $this->asset($asset);
            $compressed = gzencode($contents, 9);

            self::assertNotFalse($compressed);
            $raw += strlen($contents);
            $gzip += strlen($compressed);
        }

        // The previous three inline scripts, stylesheet and small runtime
        // listener were about 84.9 kB
        // raw and were resent in page/Livewire HTML. Keep the external bundle
        // bounded while allowing comments that document the fragile DOM work.
        self::assertLessThan(100_000, $raw);
        self::assertLessThan(25_000, $gzip);
    }

    public function test_header_scroll_payload_and_resource_pack_header_are_minimal(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 3).'/src/Filament/Server/Pages/ModManagerPage.php');

        self::assertStringContainsString(
            '$this->js("window.dispatchEvent(new CustomEvent(\'mmr:scroll-header\'))")',
            $page,
        );
        self::assertStringNotContainsString('window.mmrHeaderScrollFrame', $page);
        self::assertStringContainsString('...($type !== ProjectType::ResourcePack ? [', $page);

        $loaderStart = strpos($page, "TextEntry::make('loader')");
        $installedStart = strpos($page, "TextEntry::make('installed')", $loaderStart);
        self::assertIsInt($loaderStart);
        self::assertIsInt($installedStart);
        self::assertStringNotContainsString(
            '->visible(',
            substr($page, $loaderStart, $installedStart - $loaderStart),
        );
    }

    public function test_icon_placeholder_is_attached_to_each_asset_that_reads_it(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 3).'/src/ModManagerPlugin.php');

        foreach (['mod-manager-runtime.js', 'table-swr-cache.js'] as $asset) {
            $start = strpos($plugin, "ModManagerAssets::url('{$asset}')");
            $end = strpos($plugin, '</script>', $start);

            self::assertIsInt($start);
            self::assertIsInt($end);
            self::assertStringContainsString(
                'data-mmr-project-icon-placeholder=',
                substr($plugin, $start, $end - $start),
            );
        }
    }

    public function test_asset_urls_are_resolved_only_when_render_hooks_run(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 3).'/src/ModManagerPlugin.php');
        $registrationPrefix = strstr($plugin, '$panel->renderHook(', true);

        self::assertIsString($registrationPrefix);
        self::assertStringNotContainsString('ModManagerAssets::url(', $registrationPrefix);
    }

    private function asset(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/resources/assets/'.$name);

        self::assertIsString($contents);

        return $contents;
    }
}
