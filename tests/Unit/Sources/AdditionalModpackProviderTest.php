<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Sources\AtlauncherModpackSource;
use Kazaminosuke\ModManager\Sources\FtbModpackSource;
use Kazaminosuke\ModManager\Sources\TechnicModpackSource;
use PHPUnit\Framework\TestCase;

final class AdditionalModpackProviderTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacade = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $container = new Container();
        $factory = new Factory();
        $container->instance(Factory::class, $factory);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Http::swap($factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacade);
        parent::tearDown();
    }

    public function test_ftb_search_uses_pack_ids_and_records_exact_version_variables(): void
    {
        Http::fake([
            'api.feed-the-beast.com/v1/modpacks/public/modpack/search/*' => Http::response([
                'status' => 'success',
                'packs' => [5],
                'curseforge' => [999],
            ]),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/5' => Http::response([
                'status' => 'success',
                'id' => 5,
                'name' => 'FTB Interactions',
                'private' => false,
                'art' => [],
                'versions' => [
                    [
                        'id' => 89,
                        'name' => '2.0.4',
                        'released' => 200,
                        'private' => false,
                        'targets' => [
                            ['name' => 'minecraft', 'version' => '1.12.2', 'type' => 'game'],
                            ['name' => 'forge', 'version' => '14.23.5.2847', 'type' => 'modloader'],
                        ],
                    ],
                    [
                        'id' => 90,
                        'name' => '2.1.0',
                        'released' => 300,
                        'private' => false,
                        'targets' => [
                            ['name' => 'minecraft', 'version' => '1.12.2', 'type' => 'game'],
                            ['name' => 'neoforge', 'version' => '21.1.1', 'type' => 'modloader'],
                        ],
                    ],
                ],
            ]),
        ]);

        $latest = (new FtbModpackSource())->describe('5');
        $older = (new FtbModpackSource())->describe('5', '89');

        self::assertNotNull($latest);
        self::assertSame('90', $latest->versionId);
        self::assertSame('neoforge', $latest->loader);
        self::assertSame('ftb-server', $latest->provisioningProfileId);
        self::assertSame('', $latest->variables['FTB_SEARCH_TERM']);
        self::assertFalse($latest->archiveAllowed);
        self::assertSame('89', $older?->versionId);
        self::assertSame('forge', $older?->loader);
        self::assertSame('89', $older?->variables['FTB_MODPACK_VERSION_ID']);
    }

    public function test_technic_without_a_matching_egg_is_unsupported(): void
    {
        Http::fake([
            'api.technicpack.net/modpack/custom-pack*' => Http::response([
                'name' => 'custom-pack',
                'displayName' => 'Custom Pack',
                'minecraft' => '1.12.2',
                'version' => '1.0',
                'serverPackUrl' => 'https://example.com/not-an-egg-template.zip',
                'icon' => ['url' => 'https://cdn.technicpack.net/icon.png'],
            ]),
        ]);

        $pack = (new TechnicModpackSource())->describe('custom-pack');

        self::assertNotNull($pack);
        self::assertNull($pack->provisioningProfileId);
        self::assertFalse($pack->archiveAllowed);
        self::assertNotNull($pack->unsupportedReason);
    }

    public function test_technic_server_archive_maps_to_the_tekkit_egg_version_token(): void
    {
        Http::fake([
            'api.technicpack.net/modpack/tekkitmain*' => Http::response([
                'name' => 'tekkitmain',
                'displayName' => 'Tekkit',
                'minecraft' => '1.12.2',
                'version' => '1.0',
                'serverPackUrl' => 'https://servers.technicpack.net/Technic/servers/tekkitmain/Tekkit_Server_v1.2.9g-2.zip',
                'icon' => ['url' => 'https://cdn.technicpack.net/icon.png'],
            ]),
        ]);

        $pack = (new TechnicModpackSource())->describe('tekkitmain');

        self::assertSame('tekkit', $pack?->provisioningProfileId);
        self::assertSame('v1.2.9g-2', $pack?->variables['MODPACK_VERSION']);
        self::assertNotSame('1.0', $pack?->variables['MODPACK_VERSION']);
    }

    public function test_atlauncher_versions_do_not_invent_a_loader_or_archive(): void
    {
        Http::fake([
            'api.atlauncher.com/v1/pack/SevTechAges/*' => Http::response([
                'data' => [
                    'name' => 'SevTech: Ages',
                    'safeName' => 'SevTechAges',
                    'versions' => [
                        ['version' => '3.2.3', 'minecraft' => '1.12.2'],
                    ],
                ],
            ]),
        ]);

        $pack = (new AtlauncherModpackSource())->describe('SevTechAges', '3.2.3');

        self::assertSame('1.12.2', $pack?->minecraftVersion);
        self::assertNull($pack?->loader);
        self::assertNull($pack?->provisioningProfileId);
        self::assertFalse($pack?->archiveAllowed);
        self::assertSame(AtlauncherModpackSource::UNSUPPORTED, $pack?->unsupportedReason);
    }
}
