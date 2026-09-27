<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use App\Models\Server;
use Exception;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchAuthoritativeInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LatestInstallableVersionPage extends ModManagerPage
{
    /** @return array<string, mixed> */
    public function latestInstallableVersionForTest(ProjectSourceInterface $source, string $projectId): array
    {
        return $this->latestInstallableVersion($source, $projectId, new Server(), ProjectType::Plugin);
    }
}

final class LatestInstallableVersionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_install_uses_the_action_read_and_skips_versions_without_a_file(): void
    {
        $source = Mockery::mock(ProjectSourceInterface::class.', '.SourceFetchAuthoritativeInterface::class);
        $source->shouldNotReceive('getVersions');
        $source->shouldReceive('getVersionsAuthoritatively')->once()->andReturn([
            // e.g. a Spigot version Spiget has not mirrored yet
            ['id' => '4', 'version_number' => '1.3.0', 'files' => []],
            ['id' => '3', 'version_number' => '1.2.0', 'files' => [[
                'primary' => true,
                'filename' => 'Plugin-1.2.0.jar',
                'url' => 'https://cdn.spiget.org/file/spiget-resources/50.jar',
            ]]],
        ]);

        $version = (new LatestInstallableVersionPage())->latestInstallableVersionForTest($source, '50');

        self::assertSame('3', $version['id']);
    }

    public function test_source_failure_is_not_reported_as_no_compatible_versions(): void
    {
        $source = Mockery::mock(ProjectSourceInterface::class.', '.SourceFetchAuthoritativeInterface::class);
        $source->shouldReceive('getVersionsAuthoritatively')->andThrow(new RuntimeException('Source [spigot] operation [versions] is temporarily unavailable.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('temporarily unavailable');

        (new LatestInstallableVersionPage())->latestInstallableVersionForTest($source, '50');
    }

    public function test_versions_without_any_downloadable_file_are_rejected(): void
    {
        $source = Mockery::mock(ProjectSourceInterface::class);
        $source->shouldReceive('getVersions')->andReturn([
            ['id' => '4', 'version_number' => '1.3.0', 'files' => [['filename' => 'x.jar', 'url' => null]]],
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No downloadable file found');

        (new LatestInstallableVersionPage())->latestInstallableVersionForTest($source, '50');
    }

    public function test_empty_version_list_is_reported_as_no_compatible_versions(): void
    {
        $source = Mockery::mock(ProjectSourceInterface::class.', '.SourceFetchAuthoritativeInterface::class);
        $source->shouldReceive('getVersionsAuthoritatively')->andReturn([]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No compatible versions found');

        (new LatestInstallableVersionPage())->latestInstallableVersionForTest($source, '50');
    }
}
