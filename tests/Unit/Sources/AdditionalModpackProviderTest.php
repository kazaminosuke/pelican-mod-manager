<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Sources\AtlauncherModpackSource;
use Kazaminosuke\ModManager\Sources\FtbModpackSource;
use Kazaminosuke\ModManager\Sources\TechnicModpackSource;
use Kazaminosuke\ModManager\Support\Modpacks\FtbReleaseManifest;
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
        Cache::swap(new Repository(new ArrayStore()));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacade);
        parent::tearDown();
    }

    public function test_ftb_search_reuses_pack_summaries_and_inspects_only_the_selected_release(): void
    {
        Http::fake([
            'api.feed-the-beast.com/v1/modpacks/public/modpack/search/*' => Http::response([
                'status' => 'success',
                'packs' => [5, 5, 6],
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
            'api.feed-the-beast.com/v1/modpacks/public/modpack/6' => Http::response([], 503),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/5/89' => Http::response([
                'parent' => 5, 'id' => 89, 'name' => '2.0.4',
                'targets' => [
                    ['name' => 'minecraft', 'version' => '1.12.2', 'type' => 'game'],
                    ['name' => 'forge', 'version' => '14.23.5.2847', 'type' => 'modloader'],
                    ['name' => 'java', 'version' => '1.8.0_312', 'type' => 'runtime'],
                ],
                'files' => [
                    ['path' => './config', 'name' => 'pack.cfg', 'size' => 2, 'sha1' => sha1('ok'),
                        'url' => 'https://files.example.test/pack.cfg'],
                ],
            ]),
        ]);

        $source = new FtbModpackSource();
        [$latest] = $source->search('interactions');
        self::assertSame(['89', '90'], array_column(array_reverse($source->versions('5')), 'id'));
        $older = $source->describe('5', '89');

        self::assertNotNull($latest);
        self::assertSame('90', $latest->versionId);
        self::assertSame('neoforge', $latest->loader);
        self::assertNull($latest->provisioningProfileId);
        self::assertSame([], $latest->variables);
        self::assertFalse($latest->archiveAllowed);
        self::assertSame('89', $older?->versionId);
        self::assertSame('forge', $older?->loader);
        self::assertTrue($older?->archiveAllowed);
        self::assertSame('89', $older->artifactId);
        self::assertSame(8, $older->minimumJava);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $older->manifestFingerprint);
        Http::assertSentCount(4);
        Http::assertNotSent(static fn ($request): bool => str_ends_with($request->url(), '/5/90'));
    }

    public function test_ftb_rejects_metadata_for_a_different_pack(): void
    {
        Http::fake([
            'api.feed-the-beast.com/v1/modpacks/public/modpack/18' => Http::response([
                'id' => 19,
                'versions' => [['id' => 12169, 'name' => '1.1.1']],
            ]),
        ]);

        self::assertSame([], (new FtbModpackSource())->versions('18'));
        self::assertNull((new FtbModpackSource())->describe('18', '12169'));
    }

    public function test_ftb_manifest_supports_real_pack_layouts_mirrors_sha256_and_rejects_unsafe_targets(): void
    {
        $mod = 'PK..mod';
        $opt = 'PK..optional';
        $cfg = "key=value\n";
        $json = json_encode([
            'parent' => 135,
            'id' => 100624,
            'name' => '1.10.1',
            'status' => 'success',
            'private' => false,
            'targets' => [
                ['type' => 'game', 'name' => 'minecraft', 'version' => '1.21.1'],
                ['type' => 'modloader', 'name' => 'neoforge', 'version' => '21.1.265'],
                ['type' => 'runtime', 'name' => 'java', 'version' => '21.0.10+7-LTS'],
            ],
            'files' => [
                ['path' => './', 'name' => 'default-server.properties', 'size' => 10, 'sha1' => sha1('props'), 'url' => 'https://ftb.example/props', 'clientonly' => false],
                ['path' => './config', 'name' => '.gitkeep', 'size' => 0, 'sha1' => sha1(''), 'url' => 'https://ftb.example/gk', 'clientonly' => false],
                ['path' => './config/fancymenu/.disabled', 'name' => 'menu.txt', 'size' => 4, 'sha1' => sha1('menu'), 'url' => 'https://ftb.example/menu', 'clientonly' => false],
                ['path' => './shaderpacks', 'name' => 'Complementary.zip', 'size' => 10, 'sha1' => sha1('shader'), 'url' => 'https://ftb.example/shader', 'clientonly' => false],
                ['path' => './local/ftbchunks', 'name' => 'client-config.snbt', 'size' => 4, 'sha1' => sha1('snbt'), 'url' => 'https://ftb.example/snbt', 'clientonly' => false],
                ['path' => './configureddefaults', 'name' => 'server.toml', 'size' => strlen($cfg), 'sha1' => sha1($cfg), 'url' => 'https://ftb.example/c1', 'clientonly' => false],
                ['path' => './ftbteambases/pregen_initial/region', 'name' => 'r.-1.-1.mca', 'size' => 4, 'sha1' => sha1('regn'), 'url' => 'https://ftb.example/r', 'clientonly' => false],
                ['path' => './datapacks/ftb/data/ftb/tinkering/materials/stats', 'name' => 'steel.json', 'size' => 2, 'sha1' => sha1('{}'), 'url' => 'https://ftb.example/s', 'clientonly' => false],
                ['path' => './groovy', 'name' => 'init.groovy', 'size' => 3, 'sha1' => sha1('g()'), 'url' => 'https://ftb.example/g', 'clientonly' => false],
                ['path' => './config', 'name' => 'EnderIO.cfg', 'size' => strlen($cfg), 'sha1' => sha1($cfg), 'url' => 'https://ftb.example/cfg', 'clientonly' => false],
                ['path' => './config', 'name' => 'enderio.cfg', 'size' => strlen($cfg), 'sha1' => sha1($cfg), 'url' => 'https://ftb.example/cfg', 'clientonly' => false],
                ['path' => './mods', 'name' => 'rftools.jar', 'size' => strlen($mod), 'sha1' => sha1($mod), 'hashes' => ['sha256' => hash('sha256', $mod)], 'url' => 'https://cdn.example/wrong.jar', 'mirrors' => ['https://ftb.example/right.jar'], 'clientonly' => false, 'optional' => false],
                ['path' => './mods', 'name' => 'optional.jar', 'size' => strlen($opt), 'sha1' => sha1($opt), 'url' => 'https://cdn.example/opt.jar', 'clientonly' => false, 'optional' => true],
            ],
        ], JSON_THROW_ON_ERROR);

        $manifest = FtbReleaseManifest::read($json, '135', '100624');
        self::assertCount(7, $manifest->remoteFiles);
        self::assertCount(5, $manifest->skipped);
        $byPath = [];
        foreach ($manifest->remoteFiles as $file) {
            $byPath[$file->path] = $file;
        }
        self::assertSame(['https://ftb.example/right.jar'], $byPath['mods/rftools.jar']->fallbackUrls);
        self::assertSame(hash('sha256', $mod), $byPath['mods/rftools.jar']->hashes['sha256']);
        self::assertTrue($byPath['mods/rftools.jar']->required);
        self::assertTrue($byPath['mods/optional.jar']->required);
        self::assertArrayHasKey('config/EnderIO.cfg', $byPath);

        // Conflicting hashes for case-colliding files must still be rejected.
        $badPayload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $badPayload['files'][10]['sha1'] = sha1('different');
        $this->expectException(ModpackException::class);
        FtbReleaseManifest::read(json_encode($badPayload, JSON_THROW_ON_ERROR), '135', '100624');
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
        self::assertNull($pack?->minecraftVersion);
        self::assertSame('v1.2.9g-2', $pack?->variables['MODPACK_VERSION']);
        self::assertNotSame('1.0', $pack?->variables['MODPACK_VERSION']);
        self::assertSame('v1.2.9g-2', (new TechnicModpackSource())->describe('tekkitmain', 'v1.2.9g-2')?->versionId);
        self::assertNull((new TechnicModpackSource())->describe('tekkitmain', 'v1.2.8'));
    }

    public function test_technic_uses_the_exact_solder_release_and_never_falls_back_to_the_platform_minecraft_version(): void
    {
        Http::fake([
            'api.technicpack.net/modpack/tekkit-legends*' => Http::response([
                'name' => 'tekkit-legends', 'minecraft' => '1.4.7', 'version' => '1.1.1',
                'solder' => 'https://solder.technicpack.net/api/',
                'serverPackUrl' => 'https://servers.technicpack.net/Technic/servers/tekkit-legends/Tekkit_Legends_Server_v1.1.1.zip',
            ]),
            'solder.technicpack.net/api/modpack/tekkit-legends/1.1.1' => Http::sequence()
                ->push(['minecraft' => '1.7.10'])->pushStatus(503),
        ]);
        $source = new TechnicModpackSource();
        $pack = $source->describe('tekkit-legends', '1.1.1');
        self::assertSame('1.7.10', $pack?->minecraftVersion);
        self::assertSame('tekkit-legends', $pack->provisioningProfileId);
        self::assertSame(['MODPACK_VERSION' => '1.1.1'], $pack->variables);
        self::assertNull($source->describe('tekkit-legends', '1.1.1')?->minecraftVersion);
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
