<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Kazaminosuke\ModManager\Support\InstalledMetadataWriteSession;
use Kazaminosuke\ModManager\Support\ProjectPrimaryFile;
use Throwable;

final class InstalledProjectUpdateService
{
    public function __construct(
        private readonly InstalledProjectService $minecraft,
        private readonly InstalledArchiveTransaction $archives,
        private readonly VersionLookupCoordinator $versions,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @param callable(int, int): void|null $progress
     * @return array{total: int, updated: int, failed: int, skipped: int}
     */
    public function updateAll(
        Server $server,
        DaemonFileRepository $fileRepository,
        ProjectType $type,
        ?callable $progress = null,
    ): array {
        $metadata = $this->minecraft->getInstalledMetadataReadResult($server, $fileRepository, $type);

        if (!$metadata->isAuthoritative()) {
            throw new Exception('Installed metadata is unavailable.');
        }

        $installedMods = $metadata->document->installedMods();
        $total = count($installedMods);
        $result = $this->versions->lookupInstalled($installedMods, $server, $type);
        $updated = 0;
        $failed = 0;
        $skipped = 0;
        $metadataSession = new InstalledMetadataWriteSession(
            $metadata->document,
            fn ($document): bool => $this->minecraft->saveInstalledMetadataDocument(
                $server,
                $fileRepository,
                $document,
                $type,
            ),
        );

        foreach ($installedMods as $index => $installedMod) {
            try {
                $latestVersion = $this->latestVersionFor($installedMod, $result);

                if ($latestVersion === null || ($installedMod['version_id'] ?? null) === ($latestVersion['id'] ?? null)) {
                    $skipped++;
                } else {
                    $this->installVersion($server, $fileRepository, $type, $installedMod, $latestVersion, $metadataSession);
                    $updated++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            } finally {
                if ($progress !== null) {
                    $progress($index + 1, $total);
                }
            }
        }

        if ($updated > 0) {
            $this->cache->forget($this->minecraft->getHashScanCacheKey($server, $type));
        }

        return [
            'total' => $total,
            'updated' => $updated,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $installedMod
     * @return array<string, mixed>|null
     */
    private function latestVersionFor(array $installedMod, LatestVersionLookupResult $result): ?array
    {
        $projectId = $installedMod['project_id'] ?? null;
        $source = $installedMod['source'] ?? ProjectSourceKey::Modrinth->value;

        if (!is_string($projectId) || $projectId === '' || !is_string($source)) {
            throw new Exception('Installed metadata is missing its project identity.');
        }

        $key = "{$source}:{$projectId}";

        if (isset($result->failures()[$key])) {
            throw new Exception("Latest version lookup failed for [{$key}].");
        }

        return $result->version($key);
    }

    /**
     * @param array<string, mixed> $installedMod
     * @param array<string, mixed> $version
     */
    private function installVersion(
        Server $server,
        DaemonFileRepository $fileRepository,
        ProjectType $type,
        array $installedMod,
        array $version,
        InstalledMetadataWriteSession $metadataSession,
    ): void {
        $primaryFile = ProjectPrimaryFile::requireFromFiles($version['files'] ?? null);

        $this->archives->installOrUpdate(
            $server,
            $fileRepository,
            $type,
            $installedMod,
            $version,
            $primaryFile,
            $installedMod,
            $metadataSession,
        );
    }
}
