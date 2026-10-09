<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Sources\AtlauncherModpackSource;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\FtbModpackSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Sources\TechnicModpackSource;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDescriptor;
use Throwable;

/**
 * Search and version lookup for modpacks. Mod and plugin catalogs are unchanged.
 */
final class ModpackCatalog
{
    public function __construct(
        private readonly ModrinthSource $modrinth,
        private readonly CurseForgeSource $curseForge,
        private readonly FtbModpackSource $ftb,
        private readonly TechnicModpackSource $technic,
        private readonly AtlauncherModpackSource $atlauncher,
    ) {}

    /**
     * Options for a searchable install field. Keys are `source:projectId`.
     *
     * @return array<string, string>
     */
    public function searchOptions(Server $server, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $options = [];
        try {
            foreach ($this->modrinth->searchModpacks($server, 1, $query, false)['hits'] as $hit) {
                if (!is_array($hit)) {
                    continue;
                }
                $id = trim((string) ($hit['project_id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $options[ProjectSourceKey::Modrinth->value.':'.$id] = $this->label($hit, 'Modrinth');
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($this->curseForge->isConfigured()) {
            try {
                foreach ($this->curseForge->searchModpacks($server, 1, $query, false)['hits'] as $hit) {
                    if (!is_array($hit)) {
                        continue;
                    }
                    $id = trim((string) ($hit['project_id'] ?? ''));
                    if ($id === '' || !ctype_digit($id)) {
                        continue;
                    }
                    $options[ProjectSourceKey::CurseForge->value.':'.$id] = $this->label($hit, 'CurseForge');
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        foreach ([
            ModpackProvider::Ftb->value => $this->safeSearch(fn (): array => $this->ftb->search($query)),
            ModpackProvider::Technic->value => $this->safeSearch(fn (): array => $this->technic->search($query)),
            ModpackProvider::Atlauncher->value => $this->safeSearch(fn (): array => $this->atlauncher->search($query)),
        ] as $packs) {
            foreach ($packs as $pack) {
                $options[$pack->optionKey()] = $pack->optionLabel();
            }
        }

        return $options;
    }

    /**
     * @return array{source: string, project_id: string, version_id: string, version_number: string, filename: string, url: string}|null
     */
    public function latestFile(Server $server, string $source, string $projectId): ?array
    {
        $download = match ($source) {
            ProjectSourceKey::Modrinth->value => $this->modrinth->latestModpackDownload($server, $projectId),
            ProjectSourceKey::CurseForge->value => $this->curseForge->latestModpackDownload($server, $projectId),
            default => null,
        };

        if ($download === null) {
            return null;
        }

        return ['source' => $source, 'project_id' => $projectId] + $download;
    }

    /**
     * @return array<string, string>
     */
    public function versionOptions(string $selection): array
    {
        [$source, $projectId] = $this->splitSelection($selection);
        if ($source === null) {
            return [];
        }

        $options = [];
        try {
            $versions = match ($source) {
                ProjectSourceKey::Modrinth->value => $this->modrinth->listModpackVersions($projectId),
                ProjectSourceKey::CurseForge->value => $this->curseForge->isConfigured()
                    ? $this->curseForge->listModpackVersions($projectId)
                    : [],
                ModpackProvider::Ftb->value => $this->ftb->versions($projectId),
                ModpackProvider::Technic->value => [['id' => 'recommended', 'label' => 'Recommended server pack']],
                ModpackProvider::Atlauncher->value => $this->atlauncher->versions($projectId),
                default => [],
            };
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        foreach ($versions as $version) {
            if (!is_array($version)) {
                continue;
            }
            $id = trim((string) ($version['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $options[$id] = (string) ($version['label'] ?? $id);
        }

        return $options;
    }

    public function describe(Server $server, string $source, string $projectId, ?string $versionId): ?ModpackDescriptor
    {
        try {
            return match ($source) {
                ProjectSourceKey::Modrinth->value => $this->describeModrinth($server, $projectId, $versionId),
                ProjectSourceKey::CurseForge->value => $this->describeCurseForge($server, $projectId, $versionId),
                ModpackProvider::Ftb->value => $this->ftb->describe($projectId, $versionId),
                ModpackProvider::Technic->value => $this->technic->describe($projectId),
                ModpackProvider::Atlauncher->value => $this->atlauncher->describe($projectId, $versionId),
                default => null,
            };
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return array{source: string, project_id: string, version_id: string, version_number: string, filename: string, url: string}|null
     */
    public function file(Server $server, string $source, string $projectId, ?string $versionId = null): ?array
    {
        if ($versionId !== null && $versionId !== '') {
            $download = match ($source) {
                ProjectSourceKey::Modrinth->value => $this->modrinth->modpackDownloadById($projectId, $versionId),
                ProjectSourceKey::CurseForge->value => $this->curseForge->modpackDownloadById($projectId, $versionId),
                default => null,
            };
            if ($download !== null) {
                return ['source' => $source, 'project_id' => $projectId] + $download;
            }
        }

        return $this->latestFile($server, $source, $projectId);
    }

    private function describeModrinth(Server $server, string $projectId, ?string $versionId): ?ModpackDescriptor
    {
        $download = ($versionId !== null && $versionId !== '')
            ? $this->modrinth->modpackDownloadById($projectId, $versionId)
            : $this->modrinth->latestModpackDownload($server, $projectId);
        if ($download === null) {
            return null;
        }

        $resolvedVersion = (string) $download['version_id'];
        $usableGeneric = preg_match('/^[A-Za-z0-9]{1,8}$/', $projectId) === 1
            && preg_match('/^[A-Za-z0-9]{1,8}$/', $resolvedVersion) === 1;

        return new ModpackDescriptor(
            provider: ModpackProvider::Modrinth->value,
            id: $projectId,
            title: $projectId,
            versionId: $resolvedVersion,
            versionName: (string) ($download['version_number'] ?? $resolvedVersion),
            minecraftVersion: $download['minecraft'] ?? null,
            loader: $download['loader'] ?? null,
            loaderVersion: null,
            iconUrl: null,
            provisioningProfileId: $usableGeneric ? 'modrinth-generic' : null,
            variables: $usableGeneric ? ['PROJECT_ID' => $projectId, 'VERSION_ID' => $resolvedVersion] : [],
            archiveAllowed: true,
            unsupportedReason: null,
            downloadUrl: (string) $download['url'],
            filename: (string) ($download['filename'] ?? ''),
        );
    }

    private function describeCurseForge(Server $server, string $projectId, ?string $versionId): ?ModpackDescriptor
    {
        if (!$this->curseForge->isConfigured()) {
            return null;
        }

        $download = ($versionId !== null && $versionId !== '')
            ? $this->curseForge->modpackDownloadById($projectId, $versionId)
            : $this->curseForge->latestModpackDownload($server, $projectId);
        if ($download === null) {
            return null;
        }

        $resolvedVersion = (string) $download['version_id'];

        return new ModpackDescriptor(
            provider: ModpackProvider::CurseForge->value,
            id: $projectId,
            title: $projectId,
            versionId: $resolvedVersion,
            versionName: (string) ($download['version_number'] ?? $resolvedVersion),
            minecraftVersion: $download['minecraft'] ?? null,
            loader: $download['loader'] ?? null,
            loaderVersion: null,
            iconUrl: null,
            provisioningProfileId: 'curseforge-generic',
            variables: [
                'PROJECT_ID' => $projectId,
                'VERSION_ID' => $resolvedVersion,
            ],
            archiveAllowed: true,
            unsupportedReason: null,
            downloadUrl: (string) $download['url'],
            filename: (string) ($download['filename'] ?? ''),
        );
    }

    /**
     * @param  callable(): list<ModpackDescriptor>  $search
     * @return list<ModpackDescriptor>
     */
    private function safeSearch(callable $search): array
    {
        try {
            return $search();
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /** @return array{0: ?string, 1: string} */
    private function splitSelection(string $selection): array
    {
        $selected = explode(':', $selection, 2);
        if (count($selected) !== 2 || $selected[0] === '' || $selected[1] === '') {
            return [null, ''];
        }

        return [$selected[0], $selected[1]];
    }

    /** @param array<string, mixed> $hit */
    private function label(array $hit, string $source): string
    {
        $title = trim((string) ($hit['title'] ?? ''));

        return ($title !== '' ? $title : (string) ($hit['project_id'] ?? $source)).' ('.$source.')';
    }
}
