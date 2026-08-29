<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use App\Models\Server;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
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

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'queue' => ['default' => 'sync'],
            'pelican-mod-manager' => ['hangar_api_key' => null],
        ]));
        $container->instance('cache', new LaravelCacheRepository(new ArrayStore()));
        $container->instance(Factory::class, new Factory());
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        CatalogCompatibilityOverride::clear();
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Mockery::close();
        parent::tearDown();
    }

    public function test_catalog_version_options_use_the_official_platform_metadata_endpoint(): void
    {
        Http::fake([
            'hangar.papermc.io/api/v1/platforms/PAPER/versions*' => Http::response([
                ['version' => '1.21', 'subVersions' => ['1.21.4', '1.21.1']],
                ['version' => '1.20', 'subVersions' => ['1.20.6']],
            ]),
        ]);

        $cache = new LaravelCacheRepository(new ArrayStore());
        $operations = new InstalledOperationManager($cache, app('config'));
        $source = new HangarSource(new SourceCache(
            $cache,
            $operations,
            Mockery::mock(SourceFetchExecutorInterface::class),
        ));

        self::assertSame([
            '1.21.4' => '1.21.4',
            '1.21.1' => '1.21.1',
            '1.21' => '1.21',
            '1.20.6' => '1.20.6',
            '1.20' => '1.20',
        ], $source->catalogVersionOptions('paper'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://hangar.papermc.io/api/v1/platforms/PAPER/versions');
    }

    public function test_search_passes_supported_platform_version_category_tag_and_sort_filters(): void
    {
        $server = Mockery::mock(Server::class);
        CatalogCompatibilityOverride::set($server, '1.21.4', 'paper');

        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldReceive('fetch')
            ->once()
            ->withArgs(function ($spec): bool {
                self::assertSame([
                    'platform' => 'VELOCITY',
                    'version' => '1.21.1',
                    'limit' => 20,
                    'offset' => 20,
                    'sort' => 'recent_downloads',
                    'category' => 'admin_tools',
                    'tag' => 'SUPPORTS_FOLIA',
                ], $spec->arguments['params']);

                return true;
            })
            ->andReturn(['hits' => [], 'total_hits' => 0]);

        $cache = new LaravelCacheRepository(new ArrayStore());
        $operations = new InstalledOperationManager($cache, app('config'));
        $source = new HangarSource(new SourceCache($cache, $operations, $executor));

        self::assertSame(
            ['hits' => [], 'total_hits' => 0],
            $source->search($server, ProjectType::Plugin, 2, filters: [
                'sort' => 'recent_downloads',
                'platform' => 'VELOCITY',
                'version' => '1.21.1',
                'category' => 'admin_tools',
                'tag' => 'SUPPORTS_FOLIA',
            ]),
        );
    }
}
