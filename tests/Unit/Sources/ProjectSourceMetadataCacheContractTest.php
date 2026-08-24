<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchExecutorInterface;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\GitHubReleasesSource;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProjectSourceMetadataCacheContractTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-mod-manager' => [
                'curseforge_api_key' => 'test-key',
                'github_token' => '',
            ],
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Mockery::close();

        parent::tearDown();
    }

    #[DataProvider('sourceMetadataCases')]
    public function test_prime_get_and_peek_share_the_provider_specific_project_cache_key(
        string $sourceClass,
        string $projectId,
        string $sourceKey,
        array $arguments,
    ): void {
        [$source, $cache] = $this->source($sourceClass);
        $project = ['project_id' => $projectId, 'title' => 'Cached project'];
        $spec = new SourceFetchSpec($sourceKey, 'project', $arguments);

        $source->primeProjects([$projectId => $project]);

        self::assertSame($project, $cache->get($spec->cacheKey())['data'] ?? null);
        self::assertSame($project, $source->getProject($projectId));
        self::assertSame(
            ['data' => $project, 'pending' => false, 'retry_delayed' => false],
            $source->peekProject($projectId, dispatchOnMiss: false),
        );
        self::assertSame(
            ['data' => $project, 'pending' => false, 'retry_delayed' => false],
            $source->peekProjects([$projectId, $projectId])[$projectId],
        );
    }

    #[DataProvider('sourceMetadataCases')]
    public function test_primed_null_is_an_authoritative_missing_project_not_a_pending_or_retry_state(
        string $sourceClass,
        string $projectId,
        string $sourceKey,
        array $arguments,
    ): void {
        [$source, $cache] = $this->source($sourceClass);
        $spec = new SourceFetchSpec($sourceKey, 'project', $arguments);

        $source->primeProjects([$projectId => null]);

        $entry = $cache->get($spec->cacheKey());
        self::assertIsArray($entry);
        self::assertArrayHasKey('data', $entry);
        self::assertNull($entry['data']);
        self::assertSame(
            ['data' => null, 'pending' => false, 'retry_delayed' => false],
            $source->peekProject($projectId, dispatchOnMiss: false),
        );
        self::assertSame(
            ['data' => null, 'pending' => false, 'retry_delayed' => false],
            $source->peekProjects([$projectId])[$projectId],
        );
    }

    public function test_invalid_github_identifier_remains_a_terminal_miss_when_prime_input_contains_it(): void
    {
        [$source] = $this->source(GitHubReleasesSource::class);

        $source->primeProjects(['not-a-repository' => ['title' => 'Invalid']]);

        self::assertSame(
            ['data' => null, 'pending' => false, 'retry_delayed' => false],
            $source->peekProject('not-a-repository', dispatchOnMiss: false),
        );
        self::assertSame(
            ['data' => null, 'pending' => false, 'retry_delayed' => false],
            $source->peekProjects(['not-a-repository'])['not-a-repository'],
        );
    }

    /** @return iterable<string, array{class-string<ProjectSourceInterface>, string, string, array<string, mixed>}> */
    public static function sourceMetadataCases(): iterable
    {
        yield 'Modrinth' => [
            ModrinthSource::class,
            'project-one',
            'modrinth',
            ['project_id' => 'project-one'],
        ];
        yield 'CurseForge' => [
            CurseForgeSource::class,
            '123',
            'curseforge',
            ['project_id' => '123'],
        ];
        yield 'Hangar' => [
            HangarSource::class,
            'Owner/Project',
            'hangar',
            ['project_id' => 'Owner/Project'],
        ];
        yield 'GitHub Releases' => [
            GitHubReleasesSource::class,
            'Owner/Repository',
            'github_releases',
            ['name' => 'repository', 'owner' => 'owner'],
        ];
    }

    /** @param class-string<ProjectSourceInterface> $sourceClass
     * @return array{ProjectSourceInterface, LaravelCacheRepository}
     */
    private function source(string $sourceClass): array
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $config = new LaravelConfigRepository(['queue' => ['default' => 'sync']]);
        $executor = Mockery::mock(SourceFetchExecutorInterface::class);
        $executor->shouldNotReceive('fetch');
        $sourceCache = new SourceCache($cache, new InstalledOperationManager($cache, $config), $executor);

        return [new $sourceClass($sourceCache), $cache];
    }
}
