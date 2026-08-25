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
