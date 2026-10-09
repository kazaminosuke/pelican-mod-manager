<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityDisposition;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\Compatibility\PackRequirement;
use Kazaminosuke\ModManager\Support\Compatibility\ProvisioningMode;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Kazaminosuke\ModManager\Support\Modpacks\TechnicEggBinding;
use PHPUnit\Framework\TestCase;

final class EggCompatibilityEngineTest extends TestCase
{
    private EggCompatibilityEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new EggCompatibilityEngine();
        EggProfileRegistry::seed([
            $this->profile('fabric', 'fabric-uuid', 'fabric', ['FABRIC_VERSION', 'LOADER_VERSION', 'MC_VERSION', 'SERVER_JARFILE'], 'mod', 'fabric'),
            $this->profile('forge', 'forge-uuid', 'forge', ['BUILD_TYPE', 'FORGE_VERSION', 'MC_VERSION', 'SERVER_JARFILE'], 'mod', 'forge'),
            $this->profile('neoforge', 'neoforge-uuid', 'neoforge', ['MC_VERSION', 'NEOFORGE_VERSION'], 'mod', 'neoforge'),
            $this->profile('paper', 'paper-uuid', 'paper', ['BUILD_NUMBER', 'MINECRAFT_VERSION', 'SERVER_JARFILE'], 'plugin', 'paper'),
            $this->profile('ftb-server', 'ftb-uuid', 'ftbserver', ['FTB_MODPACK_ID', 'FTB_MODPACK_VERSION_ID', 'FTB_SEARCH_TERM', 'FTB_VERSION_STRING'], null, null, ['java/ftb/egg-f-t-b-server.json']),
            $this->profile('ftb-modpacks-ch', 'ftb-uuid', 'ftbmodpackschserver', ['FTB_MODPACK_ID', 'FTB_MODPACK_VERSION_ID', 'FTB_SEARCH_TERM', 'FTB_VERSION_STRING'], null, null, ['java/ftb/outdated/egg-f-t-b-modpacks-ch-server.json']),
            $this->profile('modrinth-generic', 'modrinth-uuid', 'modrinthgeneric', ['PROJECT_ID', 'VERSION_ID'], null, null),
        ]);
        // The seeded FTB profiles share a uuid on purpose. Give the current
        // egg a distinct update URL so the registry can tell them apart.
        $profiles = EggProfileRegistry::all();
        self::assertNull(EggProfileRegistry::findByUuid('ftb-uuid'));
        unset($profiles);
    }

    protected function tearDown(): void
    {
        EggProfileRegistry::clear();
        parent::tearDown();
    }

    public function test_a_fabric_pack_selects_the_fabric_egg_and_not_a_paper_egg_named_fabric(): void
    {
        $fabric = $this->engine->classify(1, 'Fabric', 'fabric-uuid', null, ['FABRIC_VERSION', 'LOADER_VERSION', 'MC_VERSION', 'SERVER_JARFILE']);
        $paperNamedFabric = $this->engine->classify(2, 'Fabric', null, null, ['BUILD_NUMBER', 'MINECRAFT_VERSION', 'SERVER_JARFILE']);
        $renamed = $this->engine->classify(3, 'My private pack server', 'fabric-uuid', null, ['FABRIC_VERSION', 'LOADER_VERSION', 'MC_VERSION', 'SERVER_JARFILE']);

        self::assertSame('none', $paperNamedFabric->confidence);
        self::assertTrue($paperNamedFabric->contradicts);
        self::assertSame('high', $renamed->confidence);
        self::assertSame('fabric', $renamed->profileId);

        $result = $this->engine->select(
            $this->fabricRequirement('modrinth-generic'),
            [$fabric, $paperNamedFabric, $renamed],
            EggCompatibilityEngine::CONTEXT_CREATE,
        );

        self::assertSame(CompatibilityDisposition::Ambiguous, $result->disposition);
        self::assertCount(2, $result->alternatives);
    }

    public function test_one_high_confidence_fabric_egg_is_selected_for_creation(): void
    {
        $fabric = $this->engine->classify(1, 'Fabric', 'fabric-uuid', null, ['FABRIC_VERSION', 'LOADER_VERSION', 'MC_VERSION', 'SERVER_JARFILE']);
        $result = $this->engine->select(
            $this->fabricRequirement(null),
            [$fabric],
            EggCompatibilityEngine::CONTEXT_CREATE,
        );

        self::assertSame(CompatibilityDisposition::Compatible, $result->disposition);
        self::assertSame(ProvisioningMode::Archive, $result->mode);
        self::assertSame(1, $result->selected?->id);
    }

    public function test_creation_prefers_the_modrinth_provisioning_egg_over_the_loader_egg(): void
    {
        $fabric = $this->engine->classify(1, 'Fabric', 'fabric-uuid', null, ['FABRIC_VERSION', 'LOADER_VERSION', 'MC_VERSION', 'SERVER_JARFILE']);
        $generic = $this->engine->classify(9, 'Modrinth Generic', 'modrinth-uuid', null, ['PROJECT_ID', 'VERSION_ID']);
        $result = $this->engine->select(
            $this->fabricRequirement('modrinth-generic'),
            [$fabric, $generic],
            EggCompatibilityEngine::CONTEXT_CREATE,
        );

        self::assertSame(9, $result->selected?->id);
        self::assertSame(ProvisioningMode::EggInstall, $result->mode);
        self::assertSame('VERSION_ID', array_key_first($result->redactedVariables()) === 'PROJECT_ID' ? 'VERSION_ID' : '');
        self::assertArrayHasKey('PROJECT_ID', $result->variables);
    }

    public function test_an_existing_compatible_loader_is_kept(): void
    {
        $fabric = $this->engine->classify(1, 'Fabric', 'fabric-uuid', null, ['FABRIC_VERSION', 'LOADER_VERSION', 'MC_VERSION', 'SERVER_JARFILE']);
        $generic = $this->engine->classify(9, 'Modrinth Generic', 'modrinth-uuid', null, ['PROJECT_ID', 'VERSION_ID']);
        $result = $this->engine->select(
            $this->fabricRequirement('modrinth-generic'),
            [$fabric, $generic],
            EggCompatibilityEngine::CONTEXT_EXISTING,
            1,
        );

        self::assertSame(CompatibilityDisposition::Compatible, $result->disposition);
        self::assertSame(ProvisioningMode::Archive, $result->mode);
        self::assertSame(1, $result->selected?->id);
        self::assertFalse($result->requiresConfirmation);
    }

    public function test_an_existing_paper_server_changes_to_the_only_fabric_egg(): void
    {
        $paper = $this->engine->classify(4, 'Paper', 'paper-uuid', null, ['BUILD_NUMBER', 'MINECRAFT_VERSION', 'SERVER_JARFILE']);
        $fabric = $this->engine->classify(1, 'Fabric', 'fabric-uuid', null, ['FABRIC_VERSION', 'LOADER_VERSION', 'MC_VERSION', 'SERVER_JARFILE']);
        $result = $this->engine->select(
            $this->fabricRequirement(null),
            [$paper, $fabric],
            EggCompatibilityEngine::CONTEXT_EXISTING,
            4,
        );

        self::assertSame(CompatibilityDisposition::EggChange, $result->disposition);
        self::assertSame(1, $result->selected?->id);
        self::assertTrue($result->requiresConfirmation);
        self::assertTrue($result->reinstall);
    }

    public function test_a_medium_confidence_renamed_egg_is_not_selected_automatically(): void
    {
        $renamed = $this->engine->classify(8, 'Custom loaders', null, null, ['FABRIC_VERSION', 'LOADER_VERSION', 'MC_VERSION', 'SERVER_JARFILE']);

        self::assertSame('signature', $renamed->matchSource);
        self::assertSame('medium', $renamed->confidence);

        $result = $this->engine->select(
            $this->fabricRequirement(null),
            [$renamed],
            EggCompatibilityEngine::CONTEXT_CREATE,
        );

        self::assertSame(CompatibilityDisposition::Ambiguous, $result->disposition);
        self::assertNull($result->selected);
        self::assertSame(8, $result->alternatives[0]->id);
    }

    public function test_an_explicit_incompatible_egg_is_not_replaced_until_automatic_selection_is_requested(): void
    {
        $paper = $this->engine->classify(4, 'Paper', 'paper-uuid', null, ['BUILD_NUMBER', 'MINECRAFT_VERSION', 'SERVER_JARFILE']);
        $fabric = $this->engine->classify(1, 'Fabric', 'fabric-uuid', null, ['FABRIC_VERSION', 'LOADER_VERSION', 'MC_VERSION', 'SERVER_JARFILE']);
        $kept = $this->engine->select($this->fabricRequirement(null), [$paper, $fabric], EggCompatibilityEngine::CONTEXT_CREATE, explicitEggId: 4, automatic: false);

        self::assertSame(CompatibilityDisposition::Unsupported, $kept->disposition);
        self::assertSame('explicit_incompatible', $kept->reasonCode);

        $automatic = $this->engine->select($this->fabricRequirement(null), [$paper, $fabric], EggCompatibilityEngine::CONTEXT_CREATE, explicitEggId: null, automatic: true);
        self::assertSame(1, $automatic->selected?->id);
    }

    public function test_ftb_uses_the_current_egg_when_the_shared_uuid_is_disambiguated_by_update_url(): void
    {
        $current = $this->engine->classify(5, 'FTB Server', 'ftb-uuid', 'https://example.test/java/ftb/egg-f-t-b-server.json', ['FTB_MODPACK_ID', 'FTB_MODPACK_VERSION_ID', 'FTB_SEARCH_TERM', 'FTB_VERSION_STRING']);
        $outdated = $this->engine->classify(6, 'FTB-modpacks.ch Server', 'ftb-uuid', 'https://example.test/java/ftb/outdated/egg-f-t-b-modpacks-ch-server.json', ['FTB_MODPACK_ID', 'FTB_MODPACK_VERSION_ID', 'FTB_SEARCH_TERM', 'FTB_VERSION_STRING']);

        self::assertSame('ftb-server', $current->profileId);
        self::assertSame('ftb-modpacks-ch', $outdated->profileId);

        $result = $this->engine->select(
            new PackRequirement('ftb', '5', '89', '1.12.2', 'forge', 'ftb-server', [
                'FTB_MODPACK_ID' => '5',
                'FTB_MODPACK_VERSION_ID' => '89',
                'FTB_SEARCH_TERM' => '',
                'FTB_VERSION_STRING' => '',
            ], false, []),
            [$current, $outdated],
            EggCompatibilityEngine::CONTEXT_CREATE,
        );

        self::assertSame(5, $result->selected?->id);
        self::assertSame(ProvisioningMode::EggInstall, $result->mode);
    }

    public function test_ftb_variable_changes_on_the_current_egg_require_confirmation(): void
    {
        $current = $this->engine->classify(5, 'FTB Server', 'ftb-uuid', 'https://example.test/java/ftb/egg-f-t-b-server.json', ['FTB_MODPACK_ID', 'FTB_MODPACK_VERSION_ID', 'FTB_SEARCH_TERM', 'FTB_VERSION_STRING']);
        $result = $this->engine->select(
            new PackRequirement('ftb', '5', '90', '1.12.2', 'forge', 'ftb-server', [
                'FTB_MODPACK_ID' => '5',
                'FTB_MODPACK_VERSION_ID' => '90',
                'FTB_SEARCH_TERM' => '',
                'FTB_VERSION_STRING' => '',
            ], false, []),
            [$current],
            EggCompatibilityEngine::CONTEXT_EXISTING,
            5,
            ['FTB_MODPACK_ID' => '1', 'FTB_MODPACK_VERSION_ID' => '2', 'FTB_SEARCH_TERM' => '', 'FTB_VERSION_STRING' => ''],
        );

        self::assertSame(CompatibilityDisposition::Variables, $result->disposition);
        self::assertTrue($result->requiresConfirmation);
        self::assertSame('90', $result->variables['FTB_MODPACK_VERSION_ID']);
    }

    public function test_an_unsupported_provider_does_not_select_a_similarly_named_egg(): void
    {
        $forge = $this->engine->classify(3, 'ATLauncher', 'forge-uuid', null, ['BUILD_TYPE', 'FORGE_VERSION', 'MC_VERSION', 'SERVER_JARFILE']);
        $result = $this->engine->select(
            new PackRequirement('atlauncher', 'SevTechAges', '3.2.3', '1.12.2', null, null, [], false, [], 'no server archive'),
            [$forge],
            EggCompatibilityEngine::CONTEXT_CREATE,
        );

        self::assertSame(CompatibilityDisposition::Unsupported, $result->disposition);
        self::assertNull($result->selected);
    }

    public function test_secrets_are_redacted_from_the_structured_result(): void
    {
        $generic = $this->engine->classify(9, 'CurseForge Generic', null, 'https://example.test/java/curseforge/egg-curse-forge-generic.yaml', ['API_KEY', 'PROJECT_ID', 'VERSION_ID']);
        // This seeded set has no curseforge profile, so classify from a local profile.
        EggProfileRegistry::seed([
            $this->profile('curseforge-generic', 'cf-uuid', 'curseforgegeneric', ['API_KEY', 'PROJECT_ID', 'VERSION_ID'], null, null),
        ]);
        $generic = $this->engine->classify(9, 'CurseForge Generic', 'cf-uuid', null, ['API_KEY', 'PROJECT_ID', 'VERSION_ID']);
        $result = $this->engine->select(
            new PackRequirement('curseforge', '10', '20', '1.20.1', 'forge', 'curseforge-generic', [
                'API_KEY' => 'secret-key',
                'PROJECT_ID' => '10',
                'VERSION_ID' => '20',
            ], true, ['forge']),
            [$generic],
            EggCompatibilityEngine::CONTEXT_CREATE,
        );

        self::assertSame('[redacted]', $result->toArray()['variable_names'] === ['API_KEY', 'PROJECT_ID', 'VERSION_ID'] ? $result->redactedVariables()['API_KEY'] : '');
        self::assertSame('secret-key', $result->variables['API_KEY']);
        self::assertSame('[redacted]', $result->redactedVariables()['API_KEY']);
    }

    public function test_java_image_uses_only_an_image_the_egg_offers(): void
    {
        $images = [
            'Java 8' => 'ghcr.io/example/java_8',
            'Java 17' => 'ghcr.io/example/java_17',
            'Java 21' => 'ghcr.io/example/java_21',
        ];

        self::assertSame('ghcr.io/example/java_21', JavaRuntimeImage::fromEggImages($images, '1.21.1'));
        self::assertSame('ghcr.io/example/java_17', JavaRuntimeImage::fromEggImages($images, '1.20.1'));
        self::assertSame('ghcr.io/example/java_8', JavaRuntimeImage::fromEggImages($images, '1.12.2'));
        self::assertNull(JavaRuntimeImage::fromEggImages(['Java 8' => 'ghcr.io/example/java_8'], '1.21.1'));
    }

    public function test_technic_bindings_read_the_version_token_from_the_server_archive_url(): void
    {
        $matched = TechnicEggBinding::match('https://servers.technicpack.net/Technic/servers/tekkitmain/Tekkit_Server_v1.2.9g-2.zip');
        self::assertNotNull($matched);
        self::assertSame('tekkit', $matched[0]->profileId);
        self::assertSame('v1.2.9g-2', $matched[1]);

        $classic = TechnicEggBinding::match('https://servers.technicpack.net/Technic/servers/tekkit/Tekkit_Server_3.1.2.zip');
        self::assertNotNull($classic);
        self::assertSame('tekkit-classic', $classic[0]->profileId);
        self::assertSame('3.1.2', $classic[1]);
        self::assertNull(TechnicEggBinding::match('https://example.invalid/nope.zip'));
    }

    /**
     * @param  list<string>  $variables
     * @param  list<string>|null  $updateUrls
     * @return array<string, mixed>
     */
    private function profile(string $id, string $uuid, string $alias, array $variables, ?string $projectType, ?string $loader, ?array $updateUrls = null): array
    {
        return [
            'id' => $id,
            'match' => [
                'uuid' => [$uuid],
                'update_url_contains' => $updateUrls ?? ['java/'.$id.'/egg.json'],
                'name_aliases' => [$alias],
                'variable_signatures' => [$variables],
            ],
            'status' => $loader === null ? 'manual_required' : 'resolved',
            'project_type' => $projectType,
            'loader' => $loader,
            'is_proxy' => false,
            'minecraft_version_variables' => [],
        ];
    }

    private function fabricRequirement(?string $provisioningProfile): PackRequirement
    {
        return new PackRequirement(
            provider: 'modrinth',
            packId: 'abcdefgh',
            versionId: '12345678',
            minecraftVersion: '1.21.1',
            loader: 'fabric',
            provisioningProfileId: $provisioningProfile,
            variables: $provisioningProfile === null ? [] : ['PROJECT_ID' => 'abcdefgh', 'VERSION_ID' => '12345678'],
            archiveAllowed: true,
            loaderProfileIds: ['fabric'],
        );
    }
}
