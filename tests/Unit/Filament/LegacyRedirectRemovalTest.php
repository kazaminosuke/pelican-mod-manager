<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use PHPUnit\Framework\TestCase;

final class LegacyRedirectRemovalTest extends TestCase
{
    public function test_server_page_discovery_directory_contains_only_current_manager_pages(): void
    {
        $pages = dirname(__DIR__, 3).'/src/Filament/Server/Pages';

        self::assertFileExists($pages.'/ModManagerPage.php');
        self::assertFileExists($pages.'/MinecraftDatapackPage.php');
        self::assertFileExists($pages.'/MinecraftResourcePackPage.php');
        self::assertFileDoesNotExist($pages.'/LegacyModrinthSlugRedirectPage.php');
        self::assertFileDoesNotExist($pages.'/MinecraftDatapackLegacyRedirectPage.php');
    }
}
