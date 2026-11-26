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
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurseForgeSourceResourcePackDistributionTest extends TestCase
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
            'pelican-mod-manager' => [
                'curseforge_api_key' => 'test-key',
                'debug_timing' => false,
            ],
        ]));
        $factory = new Factory();
        $container->instance(Factory::class, $factory);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Http::swap($factory);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::setFacadeApplication($this->previousFacadeApplication);

        parent::tearDown();
    }

    public function test_resource_pack_catalog_drops_only_explicitly_undistributable_projects(): void
    {
        Http::fake([
            'api.curseforge.com/v1/mods/search*' => Http::response($this->searchPayload([
                $this->mod(1, 'allowed', true),
                $this->mod(2, 'blocked', false),
                $this->mod(3, 'unknown-null', null),
                $this->mod(4, 'unknown-absent'),
                $this->mod(5, 'datapack-category', true, 5193),
            ]), 200),
        ]);

        $result = $this->source()->fetchSourceData($this->searchSpec(ProjectType::ResourcePack), 1.5);

        self::assertSame(['allowed', 'unknown-null', 'unknown-absent'], array_column($result['hits'], 'slug'));
        self::assertSame(5, $result['total_hits']);
        Http::assertSentCount(1);
    }

    #[DataProvider('nonResourcePackTypes')]
    public function test_other_catalogs_keep_explicitly_undistributable_projects(ProjectType $type): void
    {
        Http::fake([
            'api.curseforge.com/v1/mods/search*' => Http::response($this->searchPayload([
                $this->mod(10, 'blocked-mod', false),
                $this->mod(11, 'allowed-mod', true),
            ]), 200),
        ]);

        $result = $this->source()->fetchSourceData($this->searchSpec($type), 1.5);

        self::assertSame(['blocked-mod', 'allowed-mod'], array_column($result['hits'], 'slug'));
        Http::assertSentCount(1);
    }

    /** @return array<string, array{0: ProjectType}> */
    public static function nonResourcePackTypes(): array
    {
        return [
            'mod' => [ProjectType::Mod],
            'plugin' => [ProjectType::Plugin],
            'datapack' => [ProjectType::Datapack],
        ];
    }

    public function test_install_file_identifies_a_restricted_mod_using_the_exact_release(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.curseforge.com/v1/mods/264231/files/2693547' => Http::response(['data' => [
                'id' => 2693547,
                'modId' => 264231,
                'fileName' => 'iceandfire.jar',
                'fileLength' => 54,
                'downloadUrl' => null,
                'hashes' => [['algo' => 1, 'value' => str_repeat('a', 40)]],
            ]]),
            'api.curseforge.com/v1/mods' => Http::response(['data' => [[
                'id' => 264231,
                'allowModDistribution' => false,
                'name' => 'Ice and Fire: Dragons',
                'links' => ['websiteUrl' => 'https://www.curseforge.com/minecraft/mc-mods/ice-and-fire-dragons'],
            ]]]),
            'api.curseforge.com/v1/mods/264231/files/2693548' => Http::response(['data' => [
                'id' => 2693547, 'modId' => 264231,
            ]]),
        ]);

        $source = $this->source();
        $manual = $source->getInstallFile(264231, 2693547);
        self::assertTrue($manual->canUpload());
        self::assertSame(['sha1' => str_repeat('a', 40)], $manual->hashes);
        self::assertSame(54, $manual->size);
        self::assertNull($manual->url);
        self::assertSame('https://www.curseforge.com/minecraft/mc-mods/ice-and-fire-dragons/files/2693547', $manual->page);
        self::assertSame('metadata_mismatch', $source->getInstallFile(264231, 2693548)->reason);
        Http::assertSentCount(3);
    }

    #[DataProvider('dependencyApiErrors')]
    public function test_install_file_api_errors_are_distinct_from_distribution_policy(int $status, string $reason): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.curseforge.com/v1/mods/264231/files/2693547' => $status === 0
            ? Http::failedConnection('timed out') : Http::response([], $status)]);

        $file = $this->source()->getInstallFile(264231, 2693547);

        self::assertSame($reason, $file->reason);
        self::assertFalse($file->canUpload());
    }

    public static function dependencyApiErrors(): array
    {
        return [[401, 'api_authentication'], [403, 'api_forbidden'], [404, 'api_not_found'],
            [429, 'api_rate_limited'], [500, 'api_error'], [0, 'api_timeout']];
    }

    private function source(): CurseForgeSource
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $operations = new InstalledOperationManager($cache, app('config'));
        $executor = new class implements SourceFetchExecutorInterface
        {
            public function fetch(SourceFetchSpec $spec, float $timeoutSeconds): mixed
            {
                return null;
            }

            public function emptyResult(SourceFetchSpec $spec): mixed
            {
                return [];
            }
        };

        return new CurseForgeSource(new SourceCache($cache, $operations, $executor));
    }

    private function searchSpec(ProjectType $type): SourceFetchSpec
    {
        return new SourceFetchSpec(
            sourceKey: 'curseforge',
            operation: 'search',
            arguments: [
                'params' => [
                    'gameId' => 432,
                    'classId' => 12,
                    'index' => 0,
                    'pageSize' => 20,
                ],
                'project_type' => $type->value,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $mods
     * @return array<string, mixed>
     */
    private function searchPayload(array $mods): array
    {
        return [
            'data' => $mods,
            'pagination' => [
                'index' => 0,
                'pageSize' => 20,
                'resultCount' => count($mods),
                'totalCount' => count($mods),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function mod(int $id, string $slug, mixed $allowModDistribution = 'absent', ?int $categoryId = null): array
    {
        $mod = [
            'id' => $id,
            'slug' => $slug,
            'name' => $slug,
            'summary' => $slug,
            'downloadCount' => 1,
            'authors' => [['name' => 'author']],
            'categories' => $categoryId === null ? [] : [['id' => $categoryId]],
        ];

        if ($allowModDistribution !== 'absent') {
            $mod['allowModDistribution'] = $allowModDistribution;
        }

        return $mod;
    }
}
