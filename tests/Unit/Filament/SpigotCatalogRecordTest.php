<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use Filament\Support\ArrayRecord;
use Illuminate\Pagination\LengthAwarePaginator;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Sources\SpigotSource;
use PHPUnit\Framework\TestCase;

final class SpigotCatalogRecordTest extends TestCase
{
    public function test_record_action_resolves_the_visible_project_after_search_cache_changes(): void
    {
        $source = $this->createStub(SpigotSource::class);
        $page = new class($source) extends ModManagerPage
        {
            public function __construct(private readonly SpigotSource $spigot) {}

            protected function getCurrentSource(): SpigotSource
            {
                return $this->spigot;
            }

            public function pageRecords(array $records): void
            {
                $this->cachedTableRecords = new LengthAwarePaginator($records, count($records), 20);
            }

            public function resolve(?string $key): mixed
            {
                return $this->resolveTableRecord($key);
            }
        };
        $page->activeTab = 'spigot';
        $keyName = ArrayRecord::getKeyName();
        $original = [$keyName => 'spigot:50', 'project_id' => '50', 'source' => 'spigot', 'title' => 'Plugin'];
        $replacement = [$keyName => 'spigot:51', 'project_id' => '51', 'source' => 'spigot', 'title' => 'Other'];

        $page->pageRecords([$original]);
        $page->rendered(null, '');
        $page->pageRecords([$replacement]);
        self::assertSame($original, $page->resolve('spigot:50'));
        self::assertNull($page->resolve('spigot:51'));
        self::assertNull($page->resolve('0'));

        $page->mountedActions = [['context' => ['recordKey' => 'spigot:50']]];
        $page->rendered(null, '');
        self::assertSame($original, $page->resolve('spigot:50'));
        self::assertSame($replacement, $page->resolve('spigot:51'));

        $page->mountedActions = [];
        $page->rendered(null, '');
        self::assertNull($page->resolve('spigot:50'));
    }
}
