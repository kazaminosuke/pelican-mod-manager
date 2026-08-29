<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use App\Models\Server;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Support\CatalogCompatibilityOverride;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Mockery;
use PHPUnit\Framework\TestCase;

class CatalogCompatibilityOverrideTest extends TestCase
{
    protected function tearDown(): void
    {
        CatalogCompatibilityOverride::clear();
        MinecraftVersionResolver::clear();
        Mockery::close();
        parent::tearDown();
    }

    public function test_override_precedes_existing_auto_detection_without_touching_the_server(): void
    {
        $server = Mockery::mock(Server::class);
        CatalogCompatibilityOverride::set($server, '1.20.6', 'fabric');

        self::assertSame('1.20.6', MinecraftVersionResolver::resolve($server));
        self::assertSame(MinecraftLoader::Fabric, MinecraftLoader::fromServer($server));
    }

    public function test_without_temporarily_exposes_auto_state_and_restores_override(): void
    {
        $server = Mockery::mock(Server::class);
        CatalogCompatibilityOverride::set($server, '1.20.6', null);

        self::assertNull(CatalogCompatibilityOverride::without(
            $server,
            fn () => CatalogCompatibilityOverride::version($server),
        ));
        self::assertSame('1.20.6', CatalogCompatibilityOverride::version($server));
    }
}
