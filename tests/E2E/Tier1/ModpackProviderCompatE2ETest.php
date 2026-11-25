<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier1;

use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackProvisioningPlanner;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\FtbModpackSource;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityDisposition;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Kazaminosuke\ModManager\Support\Modpacks\FtbReleaseManifest;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackArchive;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDescriptor;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\ModpackArchiveFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use ZipArchive;

/**
 * Tier 1 — Isolated Feature Coverage for Modpack Features #16, #17, #18 (M3).
 */
final class ModpackProviderCompatE2ETest extends E2ETestCase
{
    public function test_curseforge_class_id_routing_and_authoritative_client_only_jar_exclusion(): void
    {
        $fixtures = new ModpackArchiveFixtures();
        try {
            $universalJar = (string) file_get_contents($fixtures->buildFabricModJar('lithiumlike', '*'));
            $fabricClientJar = (string) file_get_contents($fixtures->buildFabricModJar('sodiumlike', 'client'));
            $quiltClientJar = (string) file_get_contents($fixtures->buildQuiltModJar('qclient', 'client'));
            $forgeClientJar = (string) file_get_contents($fixtures->buildForgeModJar('fclient', true, 'CLIENT'));
            $nfClientJar = (string) file_get_contents($fixtures->buildNeoForgeModJar('nfclient', true, 'CLIENT'));
            $datapackZip = (string) file_get_contents($fixtures->buildZip(['pack.mcmeta' => '{"pack":{"pack_format":48}}'], 'dp-'));
            $resourcepackZip = (string) file_get_contents($fixtures->buildZip(['pack.mcmeta' => '{"pack":{"pack_format":34}}'], 'rp-'));
            $shaderZip = (string) file_get_contents($fixtures->buildZip(['shaders/gbuffers_terrain.fsh' => '// glsl'], 'sh-'));
            $nativeDatapackZip = (string) file_get_contents($fixtures->buildZip(['pack.mcmeta' => '{"pack":{"pack_format":48}}'], 'dp6945-'));

            // Contract check for JarClientSideInspector (4 positive + 6 zero-false-positive negative checks)
            $inspectorClass = 'Kazaminosuke\\ModManager\\Support\\Modpacks\\JarClientSideInspector';
            self::assertTrue(
                class_exists($inspectorClass),
                'M3 (#16): JarClientSideInspector must exist for authoritative client-only JAR detection.',
            );
            self::assertNotNull($inspectorClass::isClientOnlyJar($fixtures->buildFabricModJar('clientonly-direct', 'client')));
            self::assertNotNull($inspectorClass::isClientOnlyJar($fixtures->buildQuiltModJar('quilt-client-direct', 'client')));
            self::assertNotNull($inspectorClass::isClientOnlyJar($fixtures->buildForgeModJar('forge-client-direct', true, 'CLIENT')));
            self::assertNotNull($inspectorClass::isClientOnlyJar($fixtures->buildNeoForgeModJar('nf-client-direct', true, 'CLIENT')));

            self::assertNull($inspectorClass::isClientOnlyJar($fixtures->buildFabricModJar('universal-direct', '*')));
            self::assertNull($inspectorClass::isClientOnlyJar($fixtures->buildQuiltModJar('universal-quilt', '*')));
            self::assertNull($inspectorClass::isClientOnlyJar($fixtures->buildForgeModJar('universal-forge', false, 'BOTH')));
            self::assertNull($inspectorClass::isClientOnlyJar($fixtures->buildZip([
                'META-INF/mods.toml' => "modLoader=\"javafml\"\nloaderVersion=\"[47,)\"\nclientSideOnly=false\n\n[[mods]]\nmodId=\"explicitfalse\"\nversion=\"1.0.0\"\n",
            ], 'forge-explicit-false-', '.jar')));
            self::assertNull($inspectorClass::isClientOnlyJar($fixtures->buildNeoForgeModJar('universal-neoforge', false, 'BOTH')));
            self::assertNull($inspectorClass::isClientOnlyJar($fixtures->buildZip([
                'META-INF/MANIFEST.MF' => "Manifest-Version: 1.0\r\n",
                'org/lib/Helper.class' => "\xCA\xFE\xBA\xBE",
            ], 'library-no-desc-', '.jar')));
            self::assertNull($inspectorClass::isClientOnlyJar($fixtures->buildZip([
                'fabric.mod.json' => json_encode(['schemaVersion' => 1, 'id' => 'sodium_server_compat', 'version' => '1.0.0', 'environment' => '*'], JSON_THROW_ON_ERROR),
            ], 'sodium-oculus-iris-client-', '.jar')));

            $urls = [
                1001 => 'https://mediafilez.forgecdn.net/files/10/01/universal.jar',
                1002 => 'https://mediafilez.forgecdn.net/files/10/02/fabric-client.jar',
                1003 => 'https://mediafilez.forgecdn.net/files/10/03/quilt-client.jar',
                1004 => 'https://mediafilez.forgecdn.net/files/10/04/forge-client.jar',
                1005 => 'https://mediafilez.forgecdn.net/files/10/05/nf-client.jar',
                1006 => 'https://mediafilez.forgecdn.net/files/10/06/world-datapack.zip',
                1007 => 'https://mediafilez.forgecdn.net/files/10/07/pack-resources.zip',
                1008 => 'https://mediafilez.forgecdn.net/files/10/08/bsl-shaders.zip',
                1009 => 'https://mediafilez.forgecdn.net/files/10/09/native-datapack.zip',
            ];
            $bytesMap = [
                1001 => $universalJar,
                1002 => $fabricClientJar,
                1003 => $quiltClientJar,
                1004 => $forgeClientJar,
                1005 => $nfClientJar,
                1006 => $datapackZip,
                1007 => $resourcepackZip,
                1008 => $shaderZip,
                1009 => $nativeDatapackZip,
            ];
            foreach ($urls as $id => $url) {
                $this->registerRemoteFile($url, $bytesMap[$id]);
            }

            $apiFiles = [
                ModpackArchiveFixtures::curseForgeApiFile(101, 1001, 'universal.jar', $universalJar, $urls[1001]),
                ModpackArchiveFixtures::curseForgeApiFile(102, 1002, 'fabric-client.jar', $fabricClientJar, $urls[1002]),
                ModpackArchiveFixtures::curseForgeApiFile(103, 1003, 'quilt-client.jar', $quiltClientJar, $urls[1003]),
                ModpackArchiveFixtures::curseForgeApiFile(104, 1004, 'forge-client.jar', $forgeClientJar, $urls[1004]),
                ModpackArchiveFixtures::curseForgeApiFile(105, 1005, 'nf-client.jar', $nfClientJar, $urls[1005]),
                ModpackArchiveFixtures::curseForgeApiFile(106, 1006, 'world-datapack.zip', $datapackZip, $urls[1006]),
                ModpackArchiveFixtures::curseForgeApiFile(107, 1007, 'pack-resources.zip', $resourcepackZip, $urls[1007]),
                ModpackArchiveFixtures::curseForgeApiFile(108, 1008, 'bsl-shaders.zip', $shaderZip, $urls[1008]),
                ModpackArchiveFixtures::curseForgeApiFile(109, 1009, 'native-datapack.zip', $nativeDatapackZip, $urls[1009]),
            ];
            $apiMods = [
                ModpackArchiveFixtures::curseForgeApiMod(101, 'Universal Mod', classId: 6),
                ModpackArchiveFixtures::curseForgeApiMod(102, 'Fabric Client', classId: 6),
                ModpackArchiveFixtures::curseForgeApiMod(103, 'Quilt Client', classId: 6),
                ModpackArchiveFixtures::curseForgeApiMod(104, 'Forge Client', classId: 6),
                ModpackArchiveFixtures::curseForgeApiMod(105, 'NeoForge Client', classId: 6),
                ModpackArchiveFixtures::curseForgeApiMod(106, 'World Datapack', classId: 12, categoryIds: [5193]),
                ModpackArchiveFixtures::curseForgeApiMod(107, 'Resource Pack', classId: 12),
                ModpackArchiveFixtures::curseForgeApiMod(108, 'BSL Shaders', classId: 6552),
                ModpackArchiveFixtures::curseForgeApiMod(109, 'Native Datapack', classId: 6945),
            ];

            $this->fakeUpstreamHttp([
                'api.curseforge.com/v1/mods/files' => Http::response(['data' => $apiFiles], 200),
                'api.curseforge.com/v1/mods' => Http::response(['data' => $apiMods], 200),
            ]);

            $manifestFiles = array_map(
                static fn (int $idx): array => ['projectID' => 100 + $idx, 'fileID' => 1000 + $idx, 'required' => true],
                range(1, 9),
            );
            $clientZip = $fixtures->buildCurseForgeClientPack(
                minecraft: '1.21.1',
                modLoaders: [['id' => 'fabric-0.16.10', 'primary' => true]],
                manifestFiles: $manifestFiles,
            );

            $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(50));
            $server = $this->createServer($fabricEgg);
            $installer = $this->app->make(ModpackInstaller::class);

            $installer->deploy(
                $server,
                $this->app->make(DaemonFileRepository::class),
                $clientZip,
                [
                    'provider' => ModpackManifest::FORMAT_CURSEFORGE,
                    'pack_id' => 'cf-classid',
                    'version_id' => 'v1',
                    'minecraft_version' => '1.21.1',
                    'loader' => 'fabric',
                    'loader_version' => '0.16.10',
                    'server_pack' => false,
                ],
                'fabric',
                '1.21.1',
                false,
                null,
                null,
                static fn (): bool => true,
            );

            self::assertTrue($this->hasServerFile($server, 'mods/universal.jar'), 'Universal mod must install in mods/.');
            self::assertTrue($this->hasServerFile($server, 'datapacks/world-datapack.zip'), 'M3 (#16): classId 12+5193 datapack must route to datapacks/.');
            self::assertTrue($this->hasServerFile($server, 'datapacks/native-datapack.zip'), 'M3 (#16): classId 6945 datapack must route to datapacks/.');
            self::assertFalse($this->hasServerFile($server, 'mods/native-datapack.zip'), 'M3 (#16): classId 6945 datapack must not be placed in mods/.');
            self::assertTrue($this->hasServerFile($server, 'resourcepacks/pack-resources.zip'), 'M3 (#16): classId 12 resourcepack must route to resourcepacks/.');
            self::assertFalse($this->hasServerFile($server, 'mods/bsl-shaders.zip'), 'M3 (#16): classId 6552 shader must NEVER install to mods/.');
            self::assertFalse($this->hasServerFile($server, 'shaderpacks/bsl-shaders.zip'), 'M3 (#16): classId 6552 shader must be skipped on server.');
            self::assertFalse($this->hasServerFile($server, 'mods/fabric-client.jar'), 'M3 (#16): Fabric environment=client JAR must be excluded.');
            self::assertFalse($this->hasServerFile($server, 'mods/quilt-client.jar'), 'M3 (#16): Quilt environment=client JAR must be excluded.');
            self::assertFalse($this->hasServerFile($server, 'mods/forge-client.jar'), 'M3 (#16): Forge clientSideOnly=true JAR must be excluded.');
            self::assertFalse($this->hasServerFile($server, 'mods/nf-client.jar'), 'M3 (#16): NeoForge clientSideOnly=true JAR must be excluded.');
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_curseforge_download_url_fallback_and_alternate_file_id_server_pack_resolution(): void
    {
        $jarBytes = "PK\x03\x04allowed-via-download-url";
        $resolvedUrl = 'https://mediafilez.forgecdn.net/files/20/01/allowed.jar';
        $serverPackUrl = 'https://mediafilez.forgecdn.net/files/90/02/server-pack.zip';

        $this->fakeUpstreamHttp([
            'api.curseforge.com/v1/mods/201/files/2001/download-url' => Http::response([
                'data' => $resolvedUrl,
            ], 200),
            'api.curseforge.com/v1/mods/201/files/2001' => Http::response([
                'data' => ModpackArchiveFixtures::curseForgeApiFile(201, 2001, 'allowed.jar', $jarBytes, downloadUrl: null),
            ], 200),
            'api.curseforge.com/v1/mods/files' => Http::response([
                'data' => [
                    ModpackArchiveFixtures::curseForgeApiFile(201, 2001, 'allowed.jar', $jarBytes, downloadUrl: null),
                ],
            ], 200),
            'api.curseforge.com/v1/mods' => Http::response([
                'data' => [
                    ModpackArchiveFixtures::curseForgeApiMod(201, 'Allowed Mod', classId: 6, allowModDistribution: true),
                ],
            ], 200),
            'api.curseforge.com/v1/mods/900/files/9001/download-url' => Http::response([
                'data' => 'https://mediafilez.forgecdn.net/files/90/01/pack-client.zip',
            ], 200),
            'api.curseforge.com/v1/mods/900/files/9001' => Http::response([
                'data' => ModpackArchiveFixtures::curseForgeApiFile(
                    projectId: 900,
                    fileId: 9001,
                    fileName: 'pack-client.zip',
                    contentBytes: 'client',
                    downloadUrl: null,
                    isServerPack: false,
                    serverPackFileId: null,
                    alternateFileId: 9002,
                ),
            ], 200),
            'api.curseforge.com/v1/mods/900/files/9002' => Http::response([
                'data' => ModpackArchiveFixtures::curseForgeApiFile(
                    projectId: 900,
                    fileId: 9002,
                    fileName: 'pack-server.zip',
                    contentBytes: 'server',
                    downloadUrl: $serverPackUrl,
                    isServerPack: true,
                    parentProjectFileId: 9001,
                ),
            ], 200),
        ]);

        /** @var CurseForgeSource $cf */
        $cf = $this->app->make(CurseForgeSource::class);

        $deps = $cf->dependencyFiles([['project_id' => 201, 'file_id' => 2001]]);
        self::assertSame(
            $resolvedUrl,
            $deps['201/2001']->url ?? null,
            'M3 (#16): Allowed mod with null downloadUrl must resolve via /download-url endpoint.',
        );

        $download = $cf->modpackDownloadById('900', '9001');
        self::assertIsArray($download);
        self::assertTrue((bool) ($download['server_pack'] ?? false), 'M3 (#16): alternateFileId pointing to server pack must be preferred.');
        self::assertSame($serverPackUrl, $download['url'] ?? null);
        self::assertSame(
            'https://mediafilez.forgecdn.net/files/90/01/pack-client.zip',
            $download['manifest_url'] ?? null,
            'M3 (#16): modpackReleaseDownload() must pass $projectId so manifest_url resolves via /download-url when client release omits downloadUrl.',
        );
    }

    public function test_ftb_expanded_managed_dirs_zero_byte_optional_curseforge_fallback_and_popular_browse(): void
    {
        $cfJarBytes = "PK\x03\x04ftb-cf-fallback-jar";
        $cfJarUrl = 'https://mediafilez.forgecdn.net/files/50/01/jei.jar';
        $this->registerRemoteFile($cfJarUrl, $cfJarBytes);

        $structBytes = 'BLUEPRINT';
        $dpBytes = "PK\x03\x04DP";
        $paxiBytes = '{"paxi":true}';
        $flanBytes = 'flan-rule';
        $ftbLocalBytes = '{chunks:true}';
        $optBytes = "PK\x03\x04OPT";

        $urls = [
            'struct' => 'https://files.feed-the-beast.com/b/keep.blueprint',
            'dp' => 'https://files.feed-the-beast.com/b/global.zip',
            'paxi' => 'https://files.feed-the-beast.com/b/paxi.json',
            'flan' => 'https://files.feed-the-beast.com/b/flan.txt',
            'local' => 'https://files.feed-the-beast.com/b/ftbchunks.snbt',
            'empty' => 'https://files.feed-the-beast.com/b/ironfurnaces.toml',
            'opt' => 'https://files.feed-the-beast.com/b/optional.jar',
        ];
        $this->registerRemoteFile($urls['struct'], $structBytes);
        $this->registerRemoteFile($urls['dp'], $dpBytes);
        $this->registerRemoteFile($urls['paxi'], $paxiBytes);
        $this->registerRemoteFile($urls['flan'], $flanBytes);
        $this->registerRemoteFile($urls['local'], $ftbLocalBytes);
        $this->registerRemoteFile($urls['empty'], '');
        $this->registerRemoteFile($urls['opt'], $optBytes);

        $manifestJson = ModpackArchiveFixtures::buildFtbManifestJson(
            packId: 130,
            versionId: 9001,
            versionName: '1.0.0',
            targets: [
                ['type' => 'game', 'name' => 'minecraft', 'version' => '1.21.1'],
                ['type' => 'modloader', 'name' => 'neoforge', 'version' => '21.1.266'],
                ['type' => 'runtime', 'name' => 'java', 'version' => '21.0.4+7-LTS'],
            ],
            files: [
                ['path' => './structurize/blueprints', 'name' => 'keep.blueprint', 'size' => strlen($structBytes), 'sha1' => sha1($structBytes), 'url' => $urls['struct'], 'clientonly' => false],
                ['path' => './global_data_packs', 'name' => 'global.zip', 'size' => strlen($dpBytes), 'sha1' => sha1($dpBytes), 'url' => $urls['dp'], 'clientonly' => false],
                ['path' => './paxi', 'name' => 'paxi.json', 'size' => strlen($paxiBytes), 'sha1' => sha1($paxiBytes), 'url' => $urls['paxi'], 'clientonly' => false],
                ['path' => './flan', 'name' => 'flan.txt', 'size' => strlen($flanBytes), 'sha1' => sha1($flanBytes), 'url' => $urls['flan'], 'clientonly' => false],
                ['path' => './local/ftbchunks', 'name' => 'ftbchunks.snbt', 'size' => strlen($ftbLocalBytes), 'sha1' => sha1($ftbLocalBytes), 'url' => $urls['local'], 'clientonly' => false],
                ['path' => './config', 'name' => 'ironfurnaces.toml', 'size' => 0, 'sha1' => sha1(''), 'url' => $urls['empty'], 'clientonly' => false],
                ['path' => './mods', 'name' => 'optional.jar', 'size' => strlen($optBytes), 'sha1' => sha1($optBytes), 'url' => $urls['opt'], 'clientonly' => false, 'optional' => true],
                ['path' => './mods', 'name' => 'jei.jar', 'size' => strlen($cfJarBytes), 'sha1' => sha1($cfJarBytes), 'url' => '', 'mirrors' => [], 'curseforge' => ['project' => 238222, 'file' => 500001], 'clientonly' => false],
            ],
        );

        try {
            $manifest = FtbReleaseManifest::read($manifestJson, '130', '9001');
        } catch (ModpackException $e) {
            self::fail('M3 (#17): FtbReleaseManifest::read() must accept structurize/, global_data_packs/, paxi/, flan/, local/ftb*, 0-byte configs, optional:true, and curseforge fallback: '.$e->getMessage());
        }
        $byPath = [];
        foreach ($manifest->remoteFiles as $rf) {
            $byPath[$rf->path] = $rf;
        }

        self::assertArrayHasKey('structurize/blueprints/keep.blueprint', $byPath, 'M3 (#17): structurize/ must be managed.');
        self::assertArrayHasKey('global_data_packs/global.zip', $byPath, 'M3 (#17): global_data_packs/ must be managed.');
        self::assertArrayHasKey('paxi/paxi.json', $byPath, 'M3 (#17): paxi/ must be managed.');
        self::assertArrayHasKey('flan/flan.txt', $byPath, 'M3 (#17): flan/ must be managed.');
        self::assertArrayHasKey('local/ftbchunks/ftbchunks.snbt', $byPath, 'M3 (#17): local/ftb* must be managed.');
        self::assertSame(0, $byPath['config/ironfurnaces.toml']->size, 'M3 (#17): 0-byte config files must be preserved.');
        self::assertFalse($byPath['mods/optional.jar']->required, 'M3 (#17): FTB optional:true must map to required=false.');
        self::assertSame(238222, $byPath['mods/jei.jar']->projectId, 'M3 (#17): FTB curseforge fallback must populate projectId/fileId.');
        self::assertSame(500001, $byPath['mods/jei.jar']->fileId);

        // Popular browse on empty & <4 char queries hits /popular/installs/
        $popularIds = range(101, 112);
        $this->fakeUpstreamHttp([
            'api.feed-the-beast.com/v1/modpacks/public/modpack/popular/installs/*' => Http::response([
                'packs' => $popularIds,
            ], 200),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/*' => static function (Request $req) {
                $id = (int) basename((string) parse_url($req->url(), PHP_URL_PATH));

                return Http::response([
                    'id' => $id,
                    'name' => "FTB Popular {$id}",
                    'synopsis' => 'Popular FTB pack',
                    'type' => 'Release',
                    'installs' => 100000 - $id,
                    'versions' => [[
                        'id' => $id * 10,
                        'name' => '1.0.0',
                        'type' => 'Release',
                        'released' => 1760000000,
                        'targets' => [
                            ['type' => 'game', 'name' => 'minecraft', 'version' => '1.21.1'],
                            ['type' => 'modloader', 'name' => 'neoforge', 'version' => '21.1.266'],
                        ],
                    ]],
                ], 200);
            },
        ]);

        /** @var FtbModpackSource $ftb */
        $ftb = $this->app->make(FtbModpackSource::class);
        $emptyResults = $ftb->search('');
        self::assertGreaterThan(6, count($emptyResults), 'M3 (#17): Empty FTB query must browse /popular/installs/{limit} (>6 results, up to 20).');
        $shortResults = $ftb->search('ftb');
        self::assertNotEmpty($shortResults, 'M3 (#17): Short (<4 char) FTB query must return popular packs instead of empty array.');
    }

    public function test_ftb_mirrors_failover_and_optional_download_failure_skips_with_warning(): void
    {
        $mirrorBytes = "PK\x03\x04ftb-mirror-ok";
        $coreBytes = "PK\x03\x04ftb-core-ok";
        $primaryBrokenUrl = 'https://edge.forgecdn.net/files/77/01/broken-primary.jar';
        $mirrorUrl = 'https://files.feed-the-beast.com/blob/mirror-ok.jar';
        $optionalBrokenUrl = 'https://files.feed-the-beast.com/blob/optional-missing.jar';
        $coreUrl = 'https://files.feed-the-beast.com/blob/core-ok.jar';

        $this->registerRemoteFile($mirrorUrl, $mirrorBytes);
        $this->registerRemoteFile($coreUrl, $coreBytes);
        $this->fakeUpstreamHttp([
            $primaryBrokenUrl => Http::response('Service Unavailable', 503),
            $optionalBrokenUrl => Http::response('Not Found', 404),
        ]);

        $manifestJson = ModpackArchiveFixtures::buildFtbManifestJson(
            packId: 131,
            versionId: 9002,
            versionName: '1.0.0',
            targets: [
                ['type' => 'game', 'name' => 'minecraft', 'version' => '1.21.1'],
                ['type' => 'modloader', 'name' => 'neoforge', 'version' => '21.1.266'],
                ['type' => 'runtime', 'name' => 'java', 'version' => '21.0.4+7-LTS'],
            ],
            files: [
                [
                    'path' => './mods',
                    'name' => 'mirror-mod.jar',
                    'size' => strlen($mirrorBytes),
                    'sha1' => sha1($mirrorBytes),
                    'hashes' => ['sha256' => hash('sha256', $mirrorBytes)],
                    'url' => $primaryBrokenUrl,
                    'mirrors' => [$mirrorUrl],
                    'optional' => false,
                    'clientonly' => false,
                ],
                [
                    'path' => './mods',
                    'name' => 'optional-broken.jar',
                    'size' => 16,
                    'sha1' => sha1('optional-missing'),
                    'url' => $optionalBrokenUrl,
                    'optional' => true,
                    'clientonly' => false,
                ],
                [
                    'path' => './mods',
                    'name' => 'core-ok.jar',
                    'size' => strlen($coreBytes),
                    'sha1' => sha1($coreBytes),
                    'hashes' => ['sha256' => hash('sha256', $coreBytes)],
                    'url' => $coreUrl,
                    'optional' => false,
                    'clientonly' => false,
                ],
            ],
        );

        $manifestPath = $this->trackTempPath(sys_get_temp_dir().'/e2e-ftb-mirrors-'.bin2hex(random_bytes(4)).'.json');
        file_put_contents($manifestPath, $manifestJson);

        $manifest = FtbReleaseManifest::read($manifestJson, '131', '9002');
        $byPath = [];
        foreach ($manifest->remoteFiles as $rf) {
            $byPath[$rf->path] = $rf;
        }
        self::assertSame([$mirrorUrl], $byPath['mods/mirror-mod.jar']->fallbackUrls ?? null, 'M3 (#17): FTB mirrors[] must be preserved in ModpackRemoteFile::$fallbackUrls.');
        self::assertFalse($byPath['mods/optional-broken.jar']->required ?? true, 'M3 (#17): FTB optional:true must map to ModpackRemoteFile::$required = false.');

        $neoforgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::neoforge(51));
        $server = $this->createServer($neoforgeEgg);
        /** @var ModpackInstaller $installer */
        $installer = $this->app->make(ModpackInstaller::class);

        $result = $installer->deploy(
            $server,
            $this->app->make(DaemonFileRepository::class),
            $manifestPath,
            [
                'provider' => ModpackManifest::FORMAT_FTB,
                'pack_id' => '131',
                'version_id' => '9002',
                'version_name' => '1.0.0',
                'minecraft_version' => '1.21.1',
                'loader' => 'neoforge',
                'loader_version' => '21.1.266',
                'server_pack' => false,
            ],
            'neoforge',
            '1.21.1',
            false,
            null,
            null,
            static fn (): bool => true,
        );

        self::assertSame($mirrorBytes, $this->serverFile($server, 'mods/mirror-mod.jar'), 'M3 (#17): Primary 503 must failover to FTB mirrors[].');
        self::assertSame($coreBytes, $this->serverFile($server, 'mods/core-ok.jar'));
        self::assertFalse($this->hasServerFile($server, 'mods/optional-broken.jar'));
        $matchingWarnings = array_filter(
            $result['warnings'] ?? [],
            static fn (string $w): bool => str_contains($w, 'mods/optional-broken.jar'),
        );
        self::assertNotEmpty($matchingWarnings, 'M3 (#17): Failed optional:true FTB download must skip with warning mentioning mods/optional-broken.jar.');
    }

    public function test_server_pack_three_level_unwrap_recommended_java_read_only_installer_meta_and_egg_rule_checks(): void
    {
        $fixtures = new ModpackArchiveFixtures();
        try {
            // 1. M3 (#18): 3-level nested wrapper directory unwrapping + RECOMMENDED_JAVA_VERSION
            $wrapped3 = $fixtures->buildCurseForgeServerPack(
                managedFiles: ['mods/alpha.jar' => "PK\x03\x04alpha", 'config/a.toml' => 'x=1'],
                wrapperDepth: 3,
                variablesTxt: "MINECRAFT_VERSION=1.20.1\nMODLOADER=forge\nMODLOADER_VERSION=47.4.20\nRECOMMENDED_JAVA_VERSION=21\n",
            );
            $zip3 = new ZipArchive();
            self::assertTrue($zip3->open($wrapped3, ZipArchive::RDONLY));
            try {
                try {
                    $files3 = ModpackArchive::serverFiles($zip3);
                } catch (ModpackException $e) {
                    self::fail('M3 (#18): Server pack with 3 nested lone wrapper folders must unwrap cleanly: '.$e->getMessage());
                }
                self::assertCount(2, $files3, 'M3 (#18): Server pack with 3 nested lone wrapper folders must unwrap cleanly.');
                $paths3 = array_column($files3, 'path');
                self::assertContains('mods/alpha.jar', $paths3);
                self::assertContains('config/a.toml', $paths3);
                $rt3 = ModpackArchive::serverPackRuntime($zip3, $files3[0]);
                self::assertNotNull($rt3);
                self::assertSame('1.20.1', $rt3['minecraft']);
                self::assertSame('forge', $rt3['loader']);
                self::assertSame('47.4.20', $rt3['build']);
            } finally {
                $zip3->close();
            }

            // 2. M3 (#18): Read-only metadata extraction from neoforge-26.1.2.129-installer.jar (NO variables.txt/manifest.json!)
            $installerOnlyZip = $fixtures->buildCurseForgeServerPack(
                managedFiles: ['mods/mod26.jar' => "PK\x03\x04m26"],
                wrapperDepth: 1,
                variablesTxt: null,
                embeddedManifest: null,
                rootAuxiliaryFiles: [
                    'neoforge-26.1.2.129-installer.jar' => "PK\x03\x04installer",
                    'run.sh' => "#!/usr/bin/env sh\njava @user_jvm_args.txt @libraries/net/neoforged/neoforge/26.1.2.129/unix_args.txt \"\$@\"\n",
                ],
            );
            $zip26 = new ZipArchive();
            self::assertTrue($zip26->open($installerOnlyZip, ZipArchive::RDONLY));
            try {
                $files26 = ModpackArchive::serverFiles($zip26);
                $rt26 = ModpackArchive::serverPackRuntime($zip26, $files26[0]);
                self::assertNotNull($rt26, 'M3 (#18): Must extract read-only loader/version metadata from *-installer.jar / run.sh.');
                self::assertSame('neoforge', $rt26['loader']);
                self::assertSame('26.1.2.129', $rt26['build']);
                self::assertSame('26.1.2', $rt26['minecraft'], 'M3 (#18): MC 26.x NeoForge must resolve minecraft=26.1.2, NEVER 1.26.1.');
            } finally {
                $zip26->close();
            }

            // 3. M3 (#18): RECOMMENDED_JAVA_VERSION=21 extraction (MC 1.20.1 -> Java 21) AND clamping (MC 1.12.2 -> <= 8 / yolks:java_8)
            $pack1201Path = $fixtures->buildCurseForgeServerPack(
                managedFiles: ['mods/alpha.jar' => "PK\x03\x04alpha"],
                wrapperDepth: 1,
                variablesTxt: "MINECRAFT_VERSION=1.20.1\nMODLOADER=forge\nMODLOADER_VERSION=47.4.20\nRECOMMENDED_JAVA_VERSION=21\n",
            );
            $pack1122Path = $fixtures->buildCurseForgeServerPack(
                managedFiles: ['mods/legacy.jar' => "PK\x03\x04legacy"],
                wrapperDepth: 1,
                variablesTxt: "MINECRAFT_VERSION=1.12.2\nMODLOADER=forge\nMODLOADER_VERSION=14.23.5.2860\nRECOMMENDED_JAVA_VERSION=21\n",
            );
            $pack1201Bytes = (string) file_get_contents($pack1201Path);
            $pack1122Bytes = (string) file_get_contents($pack1122Path);
            $url1201 = 'https://mediafilez.forgecdn.net/files/91/00/pack-1201.zip';
            $url1122 = 'https://mediafilez.forgecdn.net/files/91/01/pack-1122.zip';
            $metrics1201 = [];
            $metrics1122 = [];

            $this->fakeUpstreamHttp([
                'api.curseforge.com/v1/mods/9100' => Http::response(['data' => ['id' => 9100, 'slug' => 'pack-1201', 'name' => 'Pack 1.20.1', 'summary' => 'Java 21 pack', 'downloadCount' => 100]], 200),
                'api.curseforge.com/v1/mods/9100/files/91001' => Http::response([
                    'data' => ModpackArchiveFixtures::curseForgeApiFile(9100, 91001, 'pack-1201.zip', $pack1201Bytes, $url1201, isServerPack: true, gameVersions: ['1.20.1', 'Forge']),
                ], 200),
                'api.curseforge.com/v1/mods/9101' => Http::response(['data' => ['id' => 9101, 'slug' => 'pack-1122', 'name' => 'Pack 1.12.2', 'summary' => 'Legacy pack', 'downloadCount' => 100]], 200),
                'api.curseforge.com/v1/mods/9101/files/91011' => Http::response([
                    'data' => ModpackArchiveFixtures::curseForgeApiFile(9101, 91011, 'pack-1122.zip', $pack1122Bytes, $url1122, isServerPack: true, gameVersions: ['1.12.2', 'Forge']),
                ], 200),
                $url1201 => ModpackArchiveFixtures::rangeHttpResponder($pack1201Bytes, $metrics1201),
                $url1122 => ModpackArchiveFixtures::rangeHttpResponder($pack1122Bytes, $metrics1122),
            ]);

            $seededEggsById = [];
            foreach (OfficialPelicanEggFixtures::allStandard() as $def) {
                $seeded = OfficialPelicanEggFixtures::seedIntoDatabase($def);
                $seededEggsById[(int) $seeded->getKey()] = $seeded;
            }
            $forgeServer = $this->createServer($seededEggsById[1]);

            /** @var ModpackCatalog $catalog */
            $catalog = $this->app->make(ModpackCatalog::class);
            $desc1201 = $catalog->describe($forgeServer, 'curseforge', '9100', '91001');
            self::assertNotNull($desc1201);
            self::assertSame(21, $desc1201->minimumJava, 'M3 (#18): RECOMMENDED_JAVA_VERSION=21 on 1.20.1 must propagate to ModpackDescriptor::$minimumJava.');
            self::assertSame(
                'ghcr.io/pelican-eggs/yolks:java_21',
                JavaRuntimeImage::fromEggImages(OfficialPelicanEggFixtures::YOLKS_ALL, $desc1201->minecraftVersion, $desc1201->minimumJava, $desc1201->loader),
            );
            self::assertTrue(JavaRuntimeImage::isTooOld('ghcr.io/pelican-eggs/yolks:java_17', $desc1201->minecraftVersion, $desc1201->minimumJava));

            $desc1122 = $catalog->describe($forgeServer, 'curseforge', '9101', '91011');
            self::assertNotNull($desc1122);
            self::assertTrue(
                $desc1122->minimumJava === null || $desc1122->minimumJava <= 8,
                'M3 (#18): RECOMMENDED_JAVA_VERSION=21 on legacy 1.12.2 Forge must be clamped to <= Java 8.',
            );
            self::assertSame(
                'ghcr.io/pelican-eggs/yolks:java_8',
                JavaRuntimeImage::fromEggImages(OfficialPelicanEggFixtures::YOLKS_ALL, $desc1122->minecraftVersion, $desc1122->minimumJava, $desc1122->loader),
            );

            // 4. M3 (#18): DB-backed Egg rule validation catches NeoForge -beta build (rejected by official regex:/^[0-9\.\-]+$/)
            /** @var ModpackProvisioningPlanner $planner */
            $planner = $this->app->make(ModpackProvisioningPlanner::class);
            $eggs = $this->app->make(InstalledEggCatalog::class)->all();

            $betaPack = new ModpackDescriptor(
                provider: ModpackProvider::Modrinth->value,
                id: 'nf-beta',
                title: 'NeoForge Beta Pack',
                versionId: 'v1',
                versionName: '1.0',
                minecraftVersion: '1.20.4',
                loader: 'neoforge',
                loaderVersion: '20.4.190-beta',
                iconUrl: null,
                provisioningProfileId: null,
                variables: [],
                archiveAllowed: true,
                unsupportedReason: null,
            );
            $betaSelection = $planner->select($betaPack, $eggs, EggCompatibilityEngine::CONTEXT_CREATE);
            // Official NeoForge Egg rejects -beta in NEOFORGE_VERSION; planner must flag/reject or omit invalid variable!
            $invalidBetaVars = OfficialPelicanEggFixtures::validateVariables(
                OfficialPelicanEggFixtures::neoforge(),
                $betaSelection->variables,
            );
            self::assertSame(
                [],
                $invalidBetaVars,
                'M3 (#18): Planner must not emit NEOFORGE_VERSION=20.4.190-beta violating official Egg rule regex:/^[0-9\.\-]+$/ (got: '.json_encode($betaSelection->variables).').',
            );

            // Official Paper & Vanilla Eggs must NEVER be selected for any modpack
            $nonModEggs = array_values(array_filter(
                $eggs,
                static fn ($egg): bool => in_array((int) $egg->id, [7, 8], true),
            ));
            self::assertCount(2, $nonModEggs);
            $paperResult = $planner->select($betaPack, $nonModEggs, EggCompatibilityEngine::CONTEXT_CREATE);
            self::assertSame(CompatibilityDisposition::Unsupported, $paperResult->disposition);
        } finally {
            $fixtures->cleanup();
        }
    }
}
