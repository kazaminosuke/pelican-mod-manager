<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\SourceCache;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;

class OptionalSourceCredentialTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Mockery::close();
        parent::tearDown();
    }

    public function test_modrinth_authorization_header_is_present_only_when_configured(): void
    {
        $source = $this->modrinthWithToken(null);
        self::assertArrayNotHasKey('Authorization', $this->pendingHeaders($source));

        $source = $this->modrinthWithToken('test-token');
        self::assertSame('test-token', $this->pendingHeaders($source)['Authorization']);
    }

    public function test_hangar_api_key_is_exchanged_and_only_the_jwt_is_used_as_authorization(): void
    {
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-mod-manager' => ['hangar_api_key' => 'test-api-key'],
        ]));
        $container->instance('cache', new LaravelCacheRepository(new ArrayStore()));
        $container->instance(Factory::class, new Factory());
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        Http::fake([
            'hangar.papermc.io/api/v1/authenticate*' => Http::response([
                'token' => 'test-jwt',
                'expiresIn' => 3_600_000,
            ]),
        ]);

        $source = new HangarSource($this->sourceCache());
        $method = new ReflectionMethod($source, 'authHeaders');

        self::assertSame(['Authorization' => 'HangarAuth test-jwt'], $method->invoke($source));
        self::assertSame(['Authorization' => 'HangarAuth test-jwt'], $method->invoke($source));
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['apiKey'] ?? null) === 'test-api-key'
                && !$request->hasHeader('Authorization');
        });
    }

    private function modrinthWithToken(?string $token): ModrinthSource
    {
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-mod-manager' => ['modrinth_token' => $token],
        ]));
        $container->instance(Factory::class, new Factory());
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        return new ModrinthSource($this->sourceCache());
    }

    /** @return array<string, string> */
    private function pendingHeaders(ModrinthSource $source): array
    {
        $method = new ReflectionMethod($source, 'http');
        $pending = $method->invoke($source, 1.0);

        return (new ReflectionObject($pending))->getProperty('options')->getValue($pending)['headers'] ?? [];
    }

    private function sourceCache(): SourceCache
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $operations = new InstalledOperationManager(
            $cache,
            new LaravelConfigRepository(['queue' => ['default' => 'sync']]),
        );

        return new SourceCache(
            $cache,
            $operations,
            Mockery::mock(SourceFetchExecutorInterface::class),
        );
    }
}
