<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Jobs;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Kazaminosuke\ModManager\Jobs\WarmCatalogSearch;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\WarmRequestThrottle;
use Mockery;
use PHPUnit\Framework\TestCase;

class WarmCatalogSearchTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Mockery::close();

        parent::tearDown();
    }

    public function test_unique_id_does_not_depend_on_server_id(): void
    {
        $first = new WarmCatalogSearch(
            serverId: 1,
            sourceKey: 'modrinth',
            projectType: 'mod',
            page: 1,
            loader: 'fabric',
            mcVersion: '1.21.1',
        );
        $second = new WarmCatalogSearch(
            serverId: 999,
            sourceKey: 'modrinth',
            projectType: 'mod',
            page: 1,
            loader: 'fabric',
            mcVersion: '1.21.1',
        );

        self::assertSame($first->uniqueId(), $second->uniqueId());
    }

    public function test_unique_id_differs_by_page_loader_version_source_and_type(): void
    {
        $base = new WarmCatalogSearch(1, 'modrinth', 'mod', 1, 'fabric', '1.21.1');
        $variants = [
            new WarmCatalogSearch(1, 'modrinth', 'mod', 2, 'fabric', '1.21.1'),
            new WarmCatalogSearch(1, 'modrinth', 'mod', 1, 'forge', '1.21.1'),
            new WarmCatalogSearch(1, 'modrinth', 'mod', 1, 'fabric', '1.20.4'),
            new WarmCatalogSearch(1, 'curseforge', 'mod', 1, 'fabric', '1.21.1'),
            new WarmCatalogSearch(1, 'modrinth', 'plugin', 1, 'fabric', '1.21.1'),
        ];

        foreach ($variants as $variant) {
            self::assertNotSame($base->uniqueId(), $variant->uniqueId());
        }
    }

    public function test_handle_skips_without_touching_the_database_when_throttled(): void
    {
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-mod-manager' => ['warm_rate_limit' => ['modrinth' => 0]],
        ]));
        Container::setInstance($container);

        $throttle = new WarmRequestThrottle(new RateLimiter(new LaravelCacheRepository(new ArrayStore())));
        $registry = Mockery::mock(ProjectSourceRegistry::class);
        $registry->shouldNotReceive('getByValue');

        $job = new WarmCatalogSearch(1, 'modrinth', 'mod', 1, 'fabric', '1.21.1');

        // No exception from a missing DB connection is itself the
        // assertion: handle() must return before Server::query()->find()
        // ever runs when the throttle denies the request.
        $job->handle($registry, $throttle);

        self::assertTrue(true);
    }
}
