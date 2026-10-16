<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use App\Models\Server;
use Exception;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchAuthoritativeInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\DownloadUnavailableException;
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

    /**
     * @param  array<string, mixed>  $versionData
     * @return array<string, mixed>
     */
    public function modalInstallableVersionForTest(
        ProjectSourceInterface $source,
        string $projectId,
        array $versionData,
    ): array {
        return $this->modalInstallableVersion($source, $projectId, $versionData, new Server(), ProjectType::Plugin);
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
            ['id' => '4', 'version_number' => '1.3.0', 'files' => []],
            ['id' => '3', 'version_number' => '1.2.0', 'files' => [[
                'primary' => true,
                'filename' => 'Plugin-1.2.0.jar',
                'url' => 'https://www.spigotmc.org/resources/50/download?version=3',
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

    public function test_external_spigot_version_is_not_reported_as_a_missing_url(): void
    {
        $source = Mockery::mock(ProjectSourceInterface::class.', '.SourceFetchAuthoritativeInterface::class);
        $source->shouldReceive('getVersionsAuthoritatively')->once()->andReturn([
            [
                'id' => '606394',
                'project_id' => '2124',
                'version_number' => 'latest',
                'files' => [],
                'download_unavailable' => DownloadUnavailableException::EXTERNAL,
            ],
        ]);

        try {
            (new LatestInstallableVersionPage())->latestInstallableVersionForTest($source, '2124');
            self::fail('An external resource must not be installed.');
        } catch (DownloadUnavailableException $exception) {
            self::assertSame(DownloadUnavailableException::EXTERNAL, $exception->reason());
        }
    }

    public function test_premium_spigot_version_is_not_reported_as_a_missing_url(): void
    {
        $source = Mockery::mock(ProjectSourceInterface::class.', '.SourceFetchAuthoritativeInterface::class);
        $source->shouldReceive('getVersionsAuthoritatively')->andReturn([
            [
                'id' => '7',
                'project_id' => '99',
                'version_number' => '1.0.0',
                'files' => [],
                'download_unavailable' => DownloadUnavailableException::PREMIUM,
            ],
        ]);

        $this->expectException(DownloadUnavailableException::class);
        $this->expectExceptionMessage('premium');

        (new LatestInstallableVersionPage())->latestInstallableVersionForTest($source, '99');
    }

    public function test_invalid_spigot_version_is_not_reported_as_a_missing_url(): void
    {
        $source = Mockery::mock(ProjectSourceInterface::class.', '.SourceFetchAuthoritativeInterface::class);
        $source->shouldReceive('getVersionsAuthoritatively')->andReturn([
            [
                'id' => '0',
                'version_number' => '1.0.0',
                'files' => [],
                'download_unavailable' => DownloadUnavailableException::INVALID,
            ],
        ]);

        try {
            (new LatestInstallableVersionPage())->latestInstallableVersionForTest($source, '50');
            self::fail('An invalid download address must not be installed.');
        } catch (DownloadUnavailableException $exception) {
            self::assertSame(DownloadUnavailableException::INVALID, $exception->reason());
        }
    }

    public function test_disabled_third_party_download_is_not_reported_as_a_missing_url(): void
    {
        $source = Mockery::mock(ProjectSourceInterface::class.', '.SourceFetchAuthoritativeInterface::class);
        $source->shouldReceive('getVersionsAuthoritatively')->andReturn([
            [
                'id' => '15',
                'version_number' => '1.0.0',
                'files' => [],
                'download_unavailable' => DownloadUnavailableException::DISTRIBUTION,
            ],
        ]);

        try {
            (new LatestInstallableVersionPage())->latestInstallableVersionForTest($source, '15');
            self::fail('A file with third-party downloads disabled must not be installed.');
        } catch (DownloadUnavailableException $exception) {
            self::assertSame(DownloadUnavailableException::DISTRIBUTION, $exception->reason());
        }
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

    public function test_new_spigot_download_unavailable_reasons_are_propagated_for_latest_and_modal_install(): void
    {
        $reasons = [
            DownloadUnavailableException::UNSUPPORTED_FILE,
            DownloadUnavailableException::UPDATE_REQUESTED,
            DownloadUnavailableException::RATE_LIMITED,
            DownloadUnavailableException::VERSION_UNAVAILABLE,
            DownloadUnavailableException::FETCH_FAILED,
        ];

        foreach ($reasons as $reason) {
            $source = Mockery::mock(ProjectSourceInterface::class.', '.SourceFetchAuthoritativeInterface::class);
            $source->shouldReceive('getVersionsAuthoritatively')->once()->andReturn([
                [
                    'id' => '3',
                    'project_id' => '50',
                    'version_number' => '1.2.0',
                    'files' => [],
                    'download_unavailable' => $reason,
                ],
            ]);

            try {
                (new LatestInstallableVersionPage())->latestInstallableVersionForTest($source, '50');
                self::fail("Reason [{$reason}] must throw DownloadUnavailableException.");
            } catch (DownloadUnavailableException $exception) {
                self::assertSame($reason, $exception->reason());
            }

            $modalSource = Mockery::mock(ProjectSourceInterface::class.', '.SourceFetchAuthoritativeInterface::class);
            $modalSource->shouldReceive('getVersionsAuthoritatively')->once()->andReturn([
                [
                    'id' => '2',
                    'project_id' => '50',
                    'version_number' => '1.1.0',
                    'files' => [],
                    'download_unavailable' => $reason,
                ],
            ]);

            try {
                (new LatestInstallableVersionPage())->modalInstallableVersionForTest($modalSource, '50', [
                    'id' => '2',
                    'version_number' => '1.1.0',
                    'files' => [],
                ]);
                self::fail("Modal install with reason [{$reason}] must throw DownloadUnavailableException.");
            } catch (DownloadUnavailableException $exception) {
                self::assertSame($reason, $exception->reason());
            }
        }
    }
}
