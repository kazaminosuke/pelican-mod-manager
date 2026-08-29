<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Support\CatalogCompatibilityOverride;
use Kazaminosuke\ModManager\Support\SourceCache;
use Mockery;
use PHPUnit\Framework\TestCase;

class HangarSourceSearchCacheTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'queue' => ['default' => 'sync'],
            'pelican-mod-manager' => ['hangar_api_key' => null],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        CatalogCompatibilityOverride::clear();
        Container::setInstance($this->previousContainer);
        Mockery::close();
        parent::tearDown();
    }

    public function test_search_passes_supported_platform_version_category_and_sort_filters(): void
    {
        $server = Mockery::mock(Server::class);
        CatalogCompatibilityOverride::set($server, '1.21.4', 'paper');

        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function ($spec): bool {
                self::assertSame([
                    'platform' => 'PAPER',
                    'version' => '1.21.4',
                    'limit' => 20,
                    'offset' => 20,
                    'sort' => 'updated',
                    'category' => 'admin_tools',
                ], $spec->arguments['params']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 0]);

        $cache = new LaravelCacheRepository(new ArrayStore());
        $operations = new InstalledOperationManager($cache, app('config'));
        $source = new HangarSource(new SourceCache($cache, $operations, $executor));

        self::assertSame(
            ['hits' => [], 'total_hits' => 0],
            $source->search($server, ProjectType::Plugin, 2, filters: ['sort' => 'updated', 'category' => 'admin_tools']),
        );
    }
}
