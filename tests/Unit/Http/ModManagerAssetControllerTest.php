<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Http;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\Facade;
use Kazaminosuke\ModManager\Http\Controllers\ModManagerAssetController;
use Kazaminosuke\ModManager\Providers\ModManagerAssetServiceProvider;
use Kazaminosuke\ModManager\Support\ModManagerAssets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ModManagerAssetControllerTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();

        $application = new Application($this->panelRoot());
        Container::setInstance($application);
        Facade::setFacadeApplication($application);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);

        parent::tearDown();
    }

    public function test_allowlisted_asset_is_content_addressed_and_immutable(): void
    {
        $definition = ModManagerAssets::get('table-layout.js');
        $response = (new ModManagerAssetController())(
            Request::create('/asset', 'GET'),
            $definition['version'],
            'table-layout.js',
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame((string) file_get_contents($definition['path']), $response->getContent());
        self::assertSame('text/javascript; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertTrue($response->headers->hasCacheControlDirective('immutable'));
        self::assertSame(31_536_000, (int) $response->headers->getCacheControlDirective('max-age'));
        self::assertSame('"'.$definition['version'].'-identity"', $response->headers->get('ETag'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('Accept-Encoding', $response->headers->get('Vary'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $definition['version']);
    }

    public function test_gzip_is_served_even_when_the_web_server_does_not_compress_assets(): void
    {
        $definition = ModManagerAssets::get('table-swr-cache.js');
        $request = Request::create('/asset', 'GET', server: [
            'HTTP_ACCEPT_ENCODING' => 'gzip, deflate',
        ]);
        $response = (new ModManagerAssetController())(
            $request,
            $definition['version'],
            'table-swr-cache.js',
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('gzip', $response->headers->get('Content-Encoding'));
        self::assertSame('Accept-Encoding', $response->headers->get('Vary'));
        self::assertSame('"'.$definition['version'].'-gzip"', $response->headers->get('ETag'));
        self::assertSame((string) file_get_contents($definition['path']), gzdecode((string) $response->getContent()));
        self::assertLessThan(filesize($definition['path']), strlen((string) $response->getContent()));
    }

    public function test_matching_if_none_match_returns_not_modified(): void
    {
        $definition = ModManagerAssets::get('mod-manager.css');
        $request = Request::create('/asset', 'GET', server: [
            'HTTP_IF_NONE_MATCH' => '"'.$definition['version'].'-identity"',
        ]);
        $response = (new ModManagerAssetController())(
            $request,
            $definition['version'],
            'mod-manager.css',
        );

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame('"'.$definition['version'].'-identity"', $response->headers->get('ETag'));
    }

    public function test_stale_content_hash_is_not_served(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new ModManagerAssetController())(
            Request::create('/asset', 'GET'),
            str_repeat('0', 64),
            'mod-manager.css',
        );
    }

    public function test_unknown_asset_name_is_not_served(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new ModManagerAssetController())(
            Request::create('/asset', 'GET'),
            str_repeat('0', 64),
            '../plugin.json',
        );
    }

    public function test_asset_route_uses_a_controller_and_strict_parameters(): void
    {
        $application = Container::getInstance();
        self::assertInstanceOf(Application::class, $application);
        $application->register(RoutingServiceProvider::class);

        (new ModManagerAssetServiceProvider($application))->boot();

        $routes = $application['router']->getRoutes();
        $routes->refreshNameLookups();
        $route = $routes->getByName('pelican-mod-manager.asset');

        self::assertNotNull($route);
        self::assertSame(
            'plugins/pelican-mod-manager/assets/{version}/{asset}',
            $route->uri(),
        );
        self::assertSame(ModManagerAssetController::class, $route->getActionName());
        self::assertSame('[a-f0-9]{64}', $route->wheres['version']);
        self::assertSame('[a-z0-9.-]+', $route->wheres['asset']);
    }

    private function panelRoot(): string
    {
        $pluginRoot = dirname(__DIR__, 3);

        return dirname($pluginRoot, 2);
    }
}
