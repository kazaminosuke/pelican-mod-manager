<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\CatalogRowActionMarkup;
use PHPUnit\Framework\TestCase;

class CatalogRowActionMarkupTest extends TestCase
{
    public function test_compact_buttons_omit_svg_tooltips_and_wire_target(): void
    {
        $html = CatalogRowActionMarkup::button([
            'name' => 'install_latest',
            'color' => 'success',
            'label' => 'Install latest',
            'wireClick' => "mountAction('install_latest', {}, {'recordKey':'1','table':true})",
            'wireKey' => 'page.actions.install_latest.abc',
        ]);

        self::assertStringNotContainsString('<svg', $html);
        self::assertStringNotContainsString('x-tooltip', $html);
        self::assertStringNotContainsString('wire:target', $html);
        self::assertStringNotContainsString('wire:loading', $html);
        self::assertStringContainsString('data-mmr-swr-row-action="install_latest"', $html);
        self::assertStringContainsString('title="Install latest"', $html);
        self::assertStringContainsString('aria-label="Install latest"', $html);
        self::assertStringContainsString('class="fi-icon-btn fi-ac-icon-btn-action fi-size-sm mmr-row-action"', $html);
        self::assertStringContainsString('class="fi-icon fi-size-xl mmr-row-action-icon"', $html);
        self::assertStringNotContainsString('fi-size-md', $html);
        self::assertStringContainsString('wire:click=', $html);
    }

    public function test_twenty_catalog_rows_stay_well_under_the_old_action_payload(): void
    {
        $row = CatalogRowActionMarkup::button([
            'name' => 'versions',
            'color' => 'info',
            'label' => 'Versions',
            'wireClick' => "mountAction('versions', {}, {'recordKey':'12','table':true})",
        ])
            .CatalogRowActionMarkup::button([
                'name' => 'install_latest',
                'color' => 'success',
                'label' => 'Install latest',
                'wireClick' => "mountAction('install_latest', {}, {'recordKey':'12','table':true})",
            ])
            .CatalogRowActionMarkup::button([
                'name' => 'uninstall',
                'color' => 'danger',
                'label' => 'Uninstall',
                'wireClick' => "mountAction('uninstall', {}, {'recordKey':'12','table':true})",
            ]);

        $page = str_repeat($row, 20);

        self::assertLessThan(50_000, strlen($page));
        self::assertGreaterThan(4_000, strlen($page));
    }

    public function test_disabled_installed_action_has_no_wire_click(): void
    {
        $html = CatalogRowActionMarkup::button([
            'name' => 'installed',
            'color' => 'success',
            'label' => 'Installed',
            'disabled' => true,
            'wireClick' => "mountAction('installed')",
        ]);

        self::assertStringContainsString('disabled="disabled"', $html);
        self::assertStringNotContainsString('wire:click', $html);
    }
}
