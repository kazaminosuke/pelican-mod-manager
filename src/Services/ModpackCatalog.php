<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Sources\AtlauncherModpackSource;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\FtbModpackSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Sources\TechnicModpackSource;
use Kazaminosuke\ModManager\Support\Modpacks\CurseForgeManifestReader;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackArchive;
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

    public const PAGE_SIZE = 20;

    /** @return array<string, string> */
    public function providers(): array
    {
        $options = [];
        foreach (ModpackProvider::cases() as $provider) {
            if ($provider !== ModpackProvider::CurseForge || $this->curseForge->isConfigured()) {
                $options[$provider->value] = $provider->getLabel();
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    public function minecraftVersions(): array
    {
        return $this->modrinth->catalogVersionOptions();
    }

    /** @return array<string, string> */
    public function loaders(): array
    {
        $options = [];
        foreach ([MinecraftLoader::Forge, MinecraftLoader::NeoForge, MinecraftLoader::Fabric, MinecraftLoader::Quilt] as $loader) {
            $options[$loader->value] = $loader->getLabel();
        }

        return $options;
    }

    /**
     * @return array{hits: list<array<string, mixed>>, total_hits: int, error: bool}
     */
    public function searchPage(
        Server $server,
        ModpackProvider $provider,
        string $query,
        int $page,
        ?string $minecraft = null,
        ?string $loader = null,
    ): array {
        $query = mb_substr(trim($query), 0, 100);
        $page = min(500, max(1, $page));
        $minecraft = is_string($minecraft) && preg_match('/^\d+(?:\.\d+){1,2}$/D', $minecraft) === 1 ? $minecraft : null;
        $loader = in_array($loader, ['forge', 'neoforge', 'fabric', 'quilt'], true) ? $loader : null;

        try {
            $result = match ($provider) {
                ModpackProvider::Modrinth => $this->modrinth->searchModpacks($server, $page, $query, false, $minecraft, $loader),
                ModpackProvider::CurseForge => $this->curseForge->searchModpacks($server, $page, $query, false, $minecraft, $loader),
                ModpackProvider::Ftb => $this->providerHits($this->ftb->search($query)),
                ModpackProvider::Technic => $this->providerHits($this->technic->search($query)),
                ModpackProvider::Atlauncher => $this->providerHits($this->atlauncher->search($query)),
            };
        } catch (Throwable $exception) {
            report($exception);

            return ['hits' => [], 'total_hits' => 0, 'error' => true];
        }

        $hits = [];
        foreach ($result['hits'] as $hit) {
            if (!is_array($hit) || blank($hit['project_id'] ?? null)) {
                continue;
            }

            $hit['key'] = $provider->value.':'.$hit['project_id'];
            $hit['title'] = (string) ($hit['title'] ?? '');
            $hit['source'] = $provider->getLabel();
            $hits[] = $hit;
        }

        return ['hits' => $hits, 'total_hits' => min((int) $result['total_hits'], 10000), 'error' => false];
    }

    /**
     * @param  list<ModpackDescriptor>  $packs
     * @return array{hits: list<array<string, mixed>>, total_hits: int}
     */
    private function providerHits(array $packs): array
    {
        return [
            'hits' => array_map(static fn (ModpackDescriptor $pack): array => [
                'project_id' => $pack->id,
                'title' => $pack->title,
                'icon_url' => $pack->iconUrl,
                'description' => $pack->minecraftVersion !== null ? 'Minecraft '.$pack->minecraftVersion : '',
            ], $packs),
            'total_hits' => count($packs),
        ];
    }

    /** @return array<string, string> */
    public function archiveProviders(): array
    {
        return array_intersect_key($this->providers(), array_fill_keys([ModpackProvider::Modrinth->value, ModpackProvider::CurseForge->value], true));
    }

    /** @return array<string, string> */
    public function searchOptions(Server $server, ModpackProvider $provider, string $query): array
    {
        if (mb_strlen(trim($query)) < 2 || !array_key_exists($provider->value, $this->archiveProviders())) {
            return [];
        }

        $options = [];
        foreach ($this->searchPage($server, $provider, $query, 1)['hits'] as $hit) {
            $options[$hit['key']] = $this->label($hit, $provider->getLabel());
        }

        return $options;
    }

    /**
     * @return array{source: string, project_id: string, version_id: string, version_number: string, filename: string, url: string, minecraft?: ?string, loader?: ?string, server_pack?: bool}|null
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
                ModpackProvider::Technic->value => $this->technicVersions($projectId),
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
                ModpackProvider::Technic->value => $this->technic->describe($projectId, $versionId),
                ModpackProvider::Atlauncher->value => $this->atlauncher->describe($projectId, $versionId),
                default => null,
            };
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return array{source: string, project_id: string, version_id: string, version_number: string, filename: string, url: string, minecraft?: ?string, loader?: ?string, server_pack?: bool}|null
     */
    public function file(Server $server, string $source, string $projectId, ?string $versionId = null): ?array
    {
        if ($versionId !== null && $versionId !== '') {
            $download = match ($source) {
                ProjectSourceKey::Modrinth->value => $this->modrinth->modpackDownloadById($projectId, $versionId),
                ProjectSourceKey::CurseForge->value => $this->curseForge->modpackDownloadById($projectId, $versionId),
                default => null,
            };

            return $download === null ? null : ['source' => $source, 'project_id' => $projectId] + $download;
        }

        return $this->latestFile($server, $source, $projectId);
    }

    /** @return list<array{id: string, label: string}> */
    private function technicVersions(string $projectId): array
    {
        $pack = $this->technic->describe($projectId);
        if ($pack === null || $pack->versionId === null) {
            return [];
        }

        return [['id' => $pack->versionId, 'label' => $pack->versionName ?? $pack->versionId]];
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
        $project = $this->modrinth->getProject($projectId);
        $serverUnsupported = ($project['id'] ?? null) === $projectId && ($project['server_side'] ?? null) === 'unsupported';
        $archiveAllowed = !$serverUnsupported || !$server->exists;
        $metadata = $archiveAllowed ? $this->inspectModrinthArchive($download['url']) : null;
        $minecraft = $metadata['minecraft'] ?? $download['minecraft'] ?? null;

        return new ModpackDescriptor(
            provider: ModpackProvider::Modrinth->value,
            id: $projectId,
            title: (string) ($project['title'] ?? $projectId),
            versionId: $resolvedVersion,
            versionName: (string) ($download['version_number'] ?? $resolvedVersion),
            minecraftVersion: $minecraft,
            loader: $metadata['loader'] ?? $download['loader'] ?? null,
            loaderVersion: $metadata['loader_version'] ?? null,
            iconUrl: null,
            provisioningProfileId: null,
            variables: [],
            archiveAllowed: $archiveAllowed,
            unsupportedReason: $archiveAllowed ? null : 'Modrinth marks this modpack as unsupported on a dedicated server.',
            downloadUrl: (string) $download['url'],
            filename: (string) ($download['filename'] ?? ''),
            compatibilityWarning: $archiveAllowed && $serverUnsupported ? 'modrinth_server_unsupported' : null,
        );
    }

    /** @return array{minecraft: ?string, loader: ?string, loader_version: ?string}|null */
    private function inspectModrinthArchive(string $url): ?array
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'cdn.modrinth.com') {
            return null;
        }

        $key = 'mod-manager:mrpack-runtime:'.hash('sha256', $url);

        return Cache::remember($key, 600, static function () use ($url): ?array {
            $temp = tempnam(sys_get_temp_dir(), 'mrpack-index');
            if ($temp === false) {
                return null;
            }

            try {
                $body = Http::timeout(5)->connectTimeout(2)
                    ->withOptions(['stream' => true, 'allow_redirects' => false])
                    ->get($url)->toPsrResponse()->getBody();
                $limit = 8 * 1024 * 1024;
                $remaining = $limit;
                try {
                    while (!$body->eof() && $remaining > 0) {
                        $chunk = $body->read(min($remaining, 65536));
                        if ($chunk === '' || file_put_contents($temp, $chunk, FILE_APPEND) !== strlen($chunk)) {
                            return null;
                        }
                        $remaining -= strlen($chunk);
                    }
                    if (!$body->eof()) {
                        return null;
                    }
                } finally {
                    $body->close();
                }

                return ModpackArchive::modrinthInstallMetadata($temp);
            } catch (Throwable) {
                // The archive installer can still validate the pack later.
                return null;
            } finally {
                unlink($temp);
            }
        });
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
        $metadata = [];
        if ($download['server_pack']) {
            $serverUrl = $download['url'];
            $metadata = Cache::remember('mod-manager:cf-server-runtime:'.hash('sha256', $serverUrl), 600, static function () use ($serverUrl): array {
                $variables = (new CurseForgeManifestReader())->read($serverUrl, 'variables.txt', allowWrapper: true);

                return $variables !== null ? (ModpackArchive::curseForgeServerPackMetadata($variables) ?? []) : [];
            });
        }

        $url = $download['manifest_url'] ?? null;
        if ($metadata === [] && is_string($url)) {
            $metadata = Cache::remember('mod-manager:cf-manifest:'.hash('sha256', $url), 600, static function () use ($url): array {
                try {
                    $json = (new CurseForgeManifestReader())->read($url);

                    return $json !== null ? (ModpackArchive::curseForgeInstallMetadata($json) ?? []) : [];
                } catch (Throwable) {
                    return [];
                }
            });
        }

        return new ModpackDescriptor(
            provider: ModpackProvider::CurseForge->value,
            id: $projectId,
            title: $projectId,
            versionId: $resolvedVersion,
            versionName: (string) ($download['version_number'] ?? $resolvedVersion),
            minecraftVersion: $metadata['minecraft'] ?? $download['minecraft'] ?? null,
            loader: $metadata['loader'] ?? $download['loader'] ?? null,
            loaderVersion: $metadata['build'] ?? null,
            iconUrl: null,
            provisioningProfileId: null,
            variables: [],
            archiveAllowed: true,
            unsupportedReason: null,
            downloadUrl: $download['url'],
            filename: $download['filename'],
            hasServerPack: $download['has_server_pack'],
            usesServerPack: $download['server_pack'],
            artifactId: $download['artifact_id'],
        );
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
