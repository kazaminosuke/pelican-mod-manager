<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use App\Models\Egg;
use App\Models\Server;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\TestCase;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftDatapackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftResourcePackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\InstalledOperationState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
final class InstalledEmptyStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace('pelican-mod-manager', dirname(__DIR__, 3).'/lang');
    }

    /** @return array<string, array{class-string<ModManagerPage>, ProjectType, string, string, string}> */
    public static function projectTypeCases(): array
    {
        return [
            'mod' => [ModManagerPage::class, ProjectType::Mod, 'No mods installed', 'インストール済みのModはありません', 'tabler-packages'],
            'plugin' => [ModManagerPage::class, ProjectType::Plugin, 'No plugins installed', 'インストール済みのプラグインはありません', 'tabler-packages'],
            'datapack' => [MinecraftDatapackPage::class, ProjectType::Datapack, 'No datapacks installed', 'インストール済みのデータパックはありません', 'tabler-file-zip'],
            'resourcepack' => [MinecraftResourcePackPage::class, ProjectType::ResourcePack, 'No resource packs installed', 'インストール済みのリソースパックはありません', 'tabler-photo'],
        ];
    }

    /** @param class-string<ModManagerPage> $pageClass */
    #[DataProvider('projectTypeCases')]
    public function test_returns_catalog_specific_empty_state_when_nothing_is_installed(
        string $pageClass,
        ProjectType $type,
        string $expectedEn,
        string $expectedJa,
        string $expectedIcon,
    ): void {
        $server = $this->serverForType($type);
        Filament::shouldReceive('getTenant')->andReturn($server);

        $page = new class($pageClass) extends ModManagerPage
        {
            /** @var class-string<ModManagerPage> */
            private static string $delegateClass = ModManagerPage::class;

            /** @param class-string<ModManagerPage> $delegateClass */
            public function __construct(string $delegateClass)
            {
                self::$delegateClass = $delegateClass;
                $this->activeTab = 'installed';
                $this->installedScanDataReady = true;
            }

            public static function detectProjectType(Server $server): ?ProjectType
            {
                return self::$delegateClass::detectProjectType($server);
            }

            public function getTableSearch(): ?string
            {
                return $this->tableSearch;
            }

            public function exposeIcon(): string
            {
                return $this->installedEmptyStateIcon();
            }

            public function exposeHeading(): string
            {
                return $this->installedEmptyStateHeading();
            }

            public function exposeDescription(): string
            {
                return $this->installedEmptyStateDescription();
            }
        };

        $this->app->setLocale('en');
        self::assertSame($expectedIcon, $page->exposeIcon());
        self::assertSame($expectedEn, $page->exposeHeading());
        self::assertSame('Browse the catalog tabs above to install one.', $page->exposeDescription());

        $this->app->setLocale('ja');
        self::assertSame($expectedJa, $page->exposeHeading());
        self::assertSame('上部のカタログタブから検索してインストールできます。', $page->exposeDescription());
    }

    public function test_distinguishes_scanning_error_and_search_empty_states(): void
    {
        $server = $this->serverForType(ProjectType::Mod);
        Filament::shouldReceive('getTenant')->andReturn($server);
        $this->app->setLocale('en');

        $page = new class extends ModManagerPage
        {
            public function __construct()
            {
                $this->activeTab = 'installed';
                $this->installedScanDataReady = true;
            }

            public function getTableSearch(): ?string
            {
                return $this->tableSearch;
            }

            public function setMetadataReadFailed(bool $failed): void
            {
                $this->installedMetadataReadFailed = $failed;
            }

            public function exposeIcon(): string
            {
                return $this->installedEmptyStateIcon();
            }

            public function exposeHeading(): string
            {
                return $this->installedEmptyStateHeading();
            }

            public function exposeDescription(): string
            {
                return $this->installedEmptyStateDescription();
            }

            public function exposesPendingScan(): bool
            {
                return $this->showInstalledScanInEmptyState();
            }

            public function exposeStatus(): array
            {
                return $this->installedOperationStatusViewData(inTable: true);
            }
        };

        $page->installedScanDataReady = false;
        $page->installedOperation = [
            'operation' => InstalledOperationManager::OPERATION_SCAN,
            'status' => InstalledOperationState::STATUS_QUEUED,
        ];
        self::assertTrue($page->exposesPendingScan());
        self::assertSame('Installed-file scan is queued.', $page->exposeStatus()['message']);
        self::assertSame('tabler-clock', $page->exposeStatus()['icon']);
        self::assertFalse($page->exposeStatus()['loading']);
        $page->installedOperation = [
            'operation' => InstalledOperationManager::OPERATION_SCAN,
            'status' => InstalledOperationState::STATUS_RUNNING,
        ];
        self::assertTrue($page->exposesPendingScan());
        self::assertSame('Installed-file scan is running…', $page->exposeStatus()['message']);
        self::assertTrue($page->exposeStatus()['loading']);
        $page->installedScanDataReady = true;
        self::assertFalse($page->exposesPendingScan());

        $page->installedOperation = [
            'operation' => InstalledOperationManager::OPERATION_BULK_UPDATE,
            'status' => InstalledOperationState::STATUS_QUEUED,
        ];
        $page->installedScanCompletion = [
            'operation' => InstalledOperationManager::OPERATION_SCAN,
            'status' => InstalledOperationState::STATUS_COMPLETED,
            'finished_at' => now()->toIso8601String(),
        ];
        self::assertSame('Bulk update is queued.', $page->exposeStatus()['message']);
        self::assertFalse($page->exposeStatus()['loading']);

        // 2. Metadata read or scan error shows error state.
        $page->installedOperation = null;
        self::assertFalse($page->exposesPendingScan());
        $page->setMetadataReadFailed(true);
        self::assertSame('tabler-alert-triangle', $page->exposeIcon());
        self::assertSame('Unable to load installed items', $page->exposeHeading());

        $page->setMetadataReadFailed(false);
        $page->installedScanCompletion = ['status' => InstalledOperationState::STATUS_FAILED];
        self::assertSame('tabler-alert-triangle', $page->exposeIcon());
        self::assertSame('Unable to load installed items', $page->exposeHeading());

        // 3. Non-empty search query shows no-matching-results state.
        $page->installedScanCompletion = null;
        $page->tableSearch = 'sodium';
        self::assertSame('tabler-search', $page->exposeIcon());
        self::assertSame('No matching installed items', $page->exposeHeading());

        // 4. Clearing search falls back to natural empty installed state.
        $page->tableSearch = '';
        self::assertSame('tabler-packages', $page->exposeIcon());
        self::assertSame('No mods installed', $page->exposeHeading());
    }

    private function serverForType(ProjectType $type): Server
    {
        $eggName = match ($type) {
            ProjectType::Plugin => 'Paper',
            ProjectType::Mod, ProjectType::Datapack, ProjectType::ResourcePack => 'Fabric',
        };

        $egg = (new Egg())->forceFill([
            'id' => 1,
            'name' => $eggName,
            'startup' => 'java -jar server.jar',
        ]);
        $egg->setRelation('variables', collect());
        $server = (new Server())->forceFill(['id' => 10, 'egg_id' => 1]);
        $server->setRelation('egg', $egg);

        return $server;
    }
}
