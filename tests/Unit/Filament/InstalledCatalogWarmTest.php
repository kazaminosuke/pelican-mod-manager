<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use PHPUnit\Framework\TestCase;

final class InstalledCatalogWarmPage extends ModManagerPage
{
    public int $dispatches = 0;

    public function warmInstalledStateForTest(): void
    {
        $this->warmInstalledStateIfMissing();
    }

    protected function dispatchInstalledScanIfMissing(): void
    {
        $this->dispatches++;
    }
}

final class InstalledCatalogWarmTest extends TestCase
{
    public function test_catalog_entry_point_warms_installed_state_without_an_installed_tab_visit(): void
    {
        $page = new InstalledCatalogWarmPage();
        $page->activeTab = ProjectSourceKey::Modrinth->value;

        $page->warmInstalledStateForTest();

        self::assertSame(1, $page->dispatches);
    }
}
