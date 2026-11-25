<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Tier4;

use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\ModManagerPlugin;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\ModManagerDiagnostics;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackInventory;
use Kazaminosuke\ModManager\Services\ServerEggMigration;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\LoaderRuntimeFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\ModpackArchiveFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Fixtures\OfficialPelicanEggFixtures;
use Kazaminosuke\ModManager\Tests\E2E\Support\E2ETestCase;
use Kazaminosuke\ModManager\Tests\E2E\Support\StrictWingsFake;
use ReflectionMethod;

/**
 * Tier 4 — Existing-Server Cross-Loader Switch, World/Config/Catalog Preservation,
 * Verified Mid-Apply Rollback, Production Panel Operations & Docs/Credits Compliance
 * (Features #5–#12, #16, #19, #21, #22).
 */
final class ExistingServerSwitchRollbackAndOpsComplianceE2ETest extends E2ETestCase
{
    public function test_existing_server_cross_loader_switch_preserves_worlds_user_configs_catalog_mods_and_recovers_from_mid_apply_failure(): void
    {
        $mergerClass = 'Kazaminosuke\\ModManager\\Services\\ServerPropertiesMerger';
        self::assertTrue(class_exists($mergerClass), 'M4 (#21): ServerPropertiesMerger must exist.');
        $migration = $this->app->make(ServerEggMigration::class);
        self::assertGreaterThanOrEqual(
            6,
            count((new ReflectionMethod($migration, 'apply'))->getParameters()),
            'M4 (#21): ServerEggMigration::apply() must implement evolved 6-arg contract.',
        );

        $forgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::forge(170));
        $neoforgeEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::neoforge(171));
        $server = $this->createServer($forgeEgg, overrides: ['skip_scripts' => true], variableValues: [
            'MC_VERSION' => '1.20.1',
            'FORGE_VERSION' => '47.4.20',
        ]);
        $repo = $this->app->make(DaemonFileRepository::class);
        $installer = $this->app->make(ModpackInstaller::class);
        $inventory = $this->app->make(ModpackInventory::class);
        $fixtures = new ModpackArchiveFixtures();

        try {
            // Seed existing server operational assets, 2 level.dat worlds, catalog mod, and user config
            $existingProps = "level-name=smp_main\nserver-port=25580\nmax-players=50\nwhite-list=true\nmotd=Production SMP\nlevel-type=minecraft\\:normal\n";
            $this->seedServerFile($server, 'server.properties', $existingProps);
            $this->seedServerFile($server, 'smp_main/level.dat', 'SMP-MAIN-WORLD-NBT');
            $this->seedServerFile($server, 'event_world/level.dat', 'EVENT-WORLD-NBT');
            $this->seedServerFile($server, 'eula.txt', "eula=true\n");
            $this->seedServerFile($server, 'ops.json', '[{"name":"founder"}]');
            $this->seedServerFile($server, 'whitelist.json', '[{"name":"vip"}]');
            $this->seedServerFile($server, 'mods/personal-catalog.jar', "PK\x03\x04catalog-kept");
            $this->seedServerFile($server, 'mods/.pelican-mod-manager.json', json_encode([
                'schema_version' => 2,
                'installed_mods' => [[
                    'source' => ProjectSourceKey::Modrinth->value,
                    'project_id' => 'pcat',
                    'project_slug' => 'personal-catalog',
                    'project_title' => 'Personal Catalog',
                    'version_id' => 'v1',
                    'version_number' => '1.0',
                    'filename' => 'personal-catalog.jar',
                    'managed_by' => 'catalog',
                    'installed_at' => gmdate('c'),
                ]],
                'unresolved_files' => [],
            ], JSON_THROW_ON_ERROR));

            // Deploy initial Forge v1 pack & simulate user editing config/rules.toml
            $f1Bytes = "PK\x03\x04forge-pack-mod-1";
            $this->registerRemoteFile('https://cdn.modrinth.com/data/sw/f1.jar', $f1Bytes);
            $forgePack = $fixtures->buildMrpack(
                ['minecraft' => '1.20.1', 'forge' => '47.4.20'],
                [['path' => 'mods/forge-a.jar', 'hashes' => ['sha1' => sha1($f1Bytes)], 'downloads' => ['https://cdn.modrinth.com/data/sw/f1.jar'], 'fileSize' => strlen($f1Bytes), 'env' => ['server' => 'required']]],
                overrides: ['config/rules.toml' => "pvp=true\n"],
            );
            $installer->deploy($server, $repo, $forgePack, [
                'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'forgepack', 'version_id' => 'v1',
                'minecraft_version' => '1.20.1', 'loader' => 'forge', 'loader_version' => '47.4.20', 'server_pack' => false,
            ], 'forge', '1.20.1', false, null, null, static fn (): bool => true);
            $this->seedServerFile($server, 'config/rules.toml', "pvp=false # operator edit\n");

            // Step 1: Migrate Forge 1.20.1 -> already-installed NeoForge 1.21.1 Egg
            $this->wings->onReinstall(function (string $uuid) use ($server): void {
                foreach (LoaderRuntimeFixtures::neoforgeModern('1.21.1', '21.1.266') as $path => $bytes) {
                    $this->wings->seedFile($uuid, $path, $bytes);
                }
                DB::table('servers')->where('id', $server->getKey())->update(['status' => null]);
            });
            (new ReflectionMethod($migration, 'apply'))->invoke(
                $migration,
                $server,
                $neoforgeEgg,
                ['MC_VERSION' => '1.21.1', 'NEOFORGE_VERSION' => '21.1.266'],
                'ghcr.io/pelican-eggs/yolks:java_21',
                null,
                static fn (): bool => true,
            );
            $server->refresh();
            self::assertSame($neoforgeEgg->getKey(), $server->egg_id);
            self::assertFalse((bool) $server->skip_scripts);

            // Step 2: Merge server.properties operational allowlist while accepting pack worldgen
            $mergedProps = $mergerClass::merge(
                $existingProps,
                "level-name=world\nserver-port=25565\nmotd=Pack Default\nlevel-type=biomesoplenty\\:default\n",
                true,
            );
            self::assertStringContainsString('server-port=25580', $mergedProps);
            self::assertStringContainsString('max-players=50', $mergedProps);
            self::assertStringContainsString('level-name=smp_main', $mergedProps);
            self::assertStringContainsString('biomesoplenty', $mergedProps);

            // Step 3: Deploy NeoForge pack cleanly & verify worlds, user config, catalog mod all preserved
            $nfBytes = "PK\x03\x04neoforge-pack-mod-1";
            $this->registerRemoteFile('https://cdn.modrinth.com/data/sw/nf1.jar', $nfBytes);
            $nfPack = $fixtures->buildMrpack(
                ['minecraft' => '1.21.1', 'neoforge' => '21.1.266'],
                [['path' => 'mods/neoforge-a.jar', 'hashes' => ['sha1' => sha1($nfBytes)], 'downloads' => ['https://cdn.modrinth.com/data/sw/nf1.jar'], 'fileSize' => strlen($nfBytes), 'env' => ['server' => 'required']]],
                overrides: ['config/rules.toml' => "pvp=true\n"],
            );

            $this->wings->setDisableRemoteDownload(true);
            $installer->deploy($server, $repo, $nfPack, [
                'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'nfpack', 'version_id' => 'v1',
                'minecraft_version' => '1.21.1', 'loader' => 'neoforge', 'loader_version' => '21.1.266',
                'server_pack' => false, 'switch' => true,
            ], 'neoforge', '1.21.1', false, null, null, static fn (): bool => true);

            self::assertSame($nfBytes, $this->serverFile($server, 'mods/neoforge-a.jar'));
            self::assertFalse($this->hasServerFile($server, 'mods/forge-a.jar'));
            self::assertSame("pvp=false # operator edit\n", $this->serverFile($server, 'config/rules.toml'));
            self::assertSame("PK\x03\x04catalog-kept", $this->serverFile($server, 'mods/personal-catalog.jar'));
            self::assertSame('SMP-MAIN-WORLD-NBT', $this->serverFile($server, 'smp_main/level.dat'));
            self::assertSame('EVENT-WORLD-NBT', $this->serverFile($server, 'event_world/level.dat'));
            self::assertSame('[{"name":"founder"}]', $this->serverFile($server, 'ops.json'));
            self::assertNull($inventory->read($server)['recovery']);

            // Step 4: Mid-apply rename failure on v2 update triggers verified rollback leaving v1 intact
            $nfV2Bytes = "PK\x03\x04neoforge-pack-mod-v2";
            $this->registerRemoteFile('https://cdn.modrinth.com/data/sw/nf2.jar', $nfV2Bytes);
            $nfPackV2 = $fixtures->buildMrpack(
                ['minecraft' => '1.21.1', 'neoforge' => '21.1.266'],
                [['path' => 'mods/neoforge-a.jar', 'hashes' => ['sha1' => sha1($nfV2Bytes)], 'downloads' => ['https://cdn.modrinth.com/data/sw/nf2.jar'], 'fileSize' => strlen($nfV2Bytes), 'env' => ['server' => 'required']]],
            );

            $failedOnce = false;
            $this->wings->beforeEndpoint('rename', static function (Request $req, string $uuid, string $endpoint, StrictWingsFake $wings) use (&$failedOnce) {
                $files = $req->data()['files'] ?? [];
                $isLiveActivation = false;
                if (is_array($files)) {
                    foreach ($files as $item) {
                        if (is_array($item) && isset($item['to']) && is_string($item['to']) && !str_contains($item['to'], '.mod-manager-')) {
                            $isLiveActivation = true;
                            break;
                        }
                    }
                }
                if (!$failedOnce && $isLiveActivation) {
                    $failedOnce = true;

                    return Http::response(json_encode(['error' => 'io error'], JSON_THROW_ON_ERROR), 500, [
                        'User-Agent' => $wings->userAgentHeader(),
                        'Content-Type' => 'application/json',
                    ]);
                }

                return null;
            });

            $caught = null;
            try {
                $installer->deploy($server, $repo, $nfPackV2, [
                    'provider' => ModpackManifest::FORMAT_MODRINTH, 'pack_id' => 'nfpack', 'version_id' => 'v2',
                    'minecraft_version' => '1.21.1', 'loader' => 'neoforge', 'loader_version' => '21.1.266',
                    'server_pack' => false,
                ], 'neoforge', '1.21.1', false, null, null, static fn (): bool => true);
            } catch (\Throwable $e) {
                $caught = $e;
            }
            $this->wings->clearFaults();
            self::assertTrue($failedOnce, 'Expected mid-apply HTTP 500 fault hook to trigger on live-activation rename.');
            self::assertNotNull($caught);
            self::assertNotInstanceOf(\Error::class, $caught);
            self::assertTrue(
                $caught instanceof RequestException || $caught instanceof ModpackException,
                'Mid-apply failure must throw RequestException or ModpackException, got '.get_class($caught).': '.$caught->getMessage(),
            );
            self::assertSame($nfBytes, $this->serverFile($server, 'mods/neoforge-a.jar'), 'Mid-apply rollback must restore v1 mod cleanly.');
            self::assertNull($inventory->read($server)['recovery']);
        } finally {
            $fixtures->cleanup();
        }
    }

    public function test_production_panel_ops_diagnostics_plugin_manifest_and_docs_credits_compliance(): void
    {
        $root = self::pluginRoot();
        $runner = PluginBackgroundRunner::fake();
        $this->app->instance(PluginBackgroundRunner::class, $runner);

        // 1. Valid plugin.json manifest
        $pluginJson = json_decode((string) file_get_contents($root.'/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pelican-mod-manager', $pluginJson['id'] ?? null);
        self::assertNotEmpty($pluginJson['version'] ?? null);

        // 2. Settings token preservation (#5)
        config([
            'pelican-mod-manager.modrinth_token' => 'prod_mrp_token',
            'pelican-mod-manager.hangar_api_key' => 'prod_hangar_key',
            'pelican-mod-manager.curseforge_api_key' => 'prod_cf_key',
            'pelican-mod-manager.github_token' => 'prod_gh_key',
        ]);
        $written = [];
        $plugin = new class($written) extends ModManagerPlugin
        {
            /** @param array<string, mixed> $out */
            public function __construct(private array &$out) {}

            public function writeToEnvironment(array $values = []): void
            {
                $this->out = $values;
            }
        };
        $data = $plugin->getSettingsFormData();
        $data['mod_nav_sort'] = 15;
        $plugin->saveSettings($data);
        self::assertSame('prod_mrp_token', $written['MOD_MANAGER_MODRINTH_TOKEN'] ?? null, 'M2 (#5): Admin updating nav sort must retain modrinth_token.');
        self::assertSame('prod_hangar_key', $written['MOD_MANAGER_HANGAR_API_KEY'] ?? null, 'M2 (#5): Admin updating nav sort must retain hangar_api_key.');

        // 3. Modpack concurrency fencing (#7)
        $fabricEgg = OfficialPelicanEggFixtures::seedIntoDatabase(OfficialPelicanEggFixtures::fabric(172));
        $server = $this->createServer($fabricEgg);
        $ops = $this->app->make(InstalledOperationManager::class);
        $leases = $this->app->make(InstalledOperationLease::class);
        $token = $leases->tryAcquire((int) $server->getKey(), ProjectType::Mod, InstalledOperationLease::OPERATION_MODPACK);
        self::assertNotNull($token);
        $ops->start($server, ProjectType::Mod, InstalledOperationManager::OPERATION_MODPACK, total: 10);
        self::assertFalse($ops->dispatchScan($server, ProjectType::Mod, actorUserId: 1)['dispatched'] ?? true);

        // 4. Diagnostics dedupe & full provider coverage (#12)
        $spigetCount = 0;
        $this->fakeUpstreamHttp([
            'api.modrinth.com/*' => Http::response([['version' => '1.21.1']], 200),
            'api.curseforge.com/*' => Http::response(['data' => ['id' => 432]], 200),
            'api.spiget.org/v2/resources/2*' => static function () use (&$spigetCount) {
                $spigetCount++;

                return Http::response(['id' => 2, 'file' => ['type' => '.jar']], 200);
            },
            'cdn.spiget.org/*' => Http::response("PK\x03\x04", 206),
            'api.feed-the-beast.com/*' => Http::response(['id' => 5], 200),
            'api.technicpack.net/*' => Http::response(['name' => 'tekkit'], 200),
            'api.atlauncher.com/*' => Http::response(['data' => []], 200),
            'hangar.papermc.io/*' => Http::response(['result' => []], 200),
            'api.github.com/*' => Http::response(['resources' => ['core' => ['remaining' => 60]]], 200),
        ]);
        $checks = $this->app->make(ModManagerDiagnostics::class)->run($server);
        self::assertSame(1, $spigetCount, 'M2 (#12): Diagnostics must fetch SpiGet resource 2 once.');
        self::assertContains('hangar', array_column($checks, 'id'));
        self::assertNotEmpty(
            array_intersect(['github', 'github_releases'], array_column($checks, 'id')),
            'M2 (#12): Diagnostics must include GitHub check (github or github_releases).',
        );

        // 5. License & Credits compliance (#22)
        foreach (['README.md', 'README.ja.md', 'docs/modpack-compatibility-audit.md'] as $doc) {
            $text = (string) file_get_contents($root.'/'.$doc);
            self::assertStringContainsString('https://github.com/cosmii02/pelican-modpackmanager', $text, "M5 (#22): {$doc} must attribute cosmii02/pelican-modpackmanager.");
        }
    }
}
