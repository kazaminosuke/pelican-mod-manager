<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
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
            $hit['provider'] = $provider->value;
            $hit['title'] = (string) ($hit['title'] ?? '');
            $hit['description'] = $this->shortDescription($hit['description'] ?? null);
            $hit['source'] = $provider->getLabel();
            $hit['url'] = self::externalProjectUrl(
                $provider,
                (string) $hit['project_id'],
                is_string($hit['slug'] ?? null) ? $hit['slug'] : null,
                $hit['title'],
            );
            $hit['minecraft_versions'] = $this->normalizeMinecraftVersions($hit['minecraft_versions'] ?? []);
            $hit['loaders'] = $this->normalizeLoaders($hit['loaders'] ?? []);
            $hit['downloads'] = isset($hit['downloads']) && is_numeric($hit['downloads']) ? (int) $hit['downloads'] : null;
            $hit['date_modified'] = is_string($hit['date_modified'] ?? null) && trim($hit['date_modified']) !== ''
                ? trim($hit['date_modified'])
                : null;
            $hit['author'] = is_string($hit['author'] ?? null) && trim($hit['author']) !== ''
                ? trim($hit['author'])
                : null;

            if ($provider === ModpackProvider::CurseForge && $hit['title'] !== '' && app()->bound('cache')) {
                Cache::put('mod-manager:cf-title:'.$hit['project_id'], $hit['title'], 600);
            }
            $hits[] = $hit;
        }

        return ['hits' => $hits, 'total_hits' => min((int) $result['total_hits'], 10000), 'error' => false];
    }

    public static function externalProjectUrl(
        ModpackProvider $provider,
        string $projectId,
        ?string $slug = null,
        ?string $title = null,
    ): ?string {
        $id = trim($projectId);
        $cleanSlug = is_string($slug) ? trim($slug) : '';

        return match ($provider) {
            ModpackProvider::Modrinth => self::modrinthProjectUrl($id, $cleanSlug),
            ModpackProvider::CurseForge => self::curseForgeProjectUrl($cleanSlug),
            ModpackProvider::Ftb => self::ftbProjectUrl($id, $title),
            ModpackProvider::Technic => preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', $id) === 1
                ? "https://www.technicpack.net/modpack/{$id}"
                : null,
            ModpackProvider::Atlauncher => preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id) === 1
                ? "https://atlauncher.com/pack/{$id}"
                : null,
        };
    }

    private static function modrinthProjectUrl(string $projectId, string $slug): ?string
    {
        if ($slug !== '' && preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $slug) === 1) {
            return "https://modrinth.com/modpack/{$slug}";
        }

        if (preg_match('/^[A-Za-z0-9]{1,64}$/D', $projectId) === 1) {
            return "https://modrinth.com/modpack/{$projectId}";
        }

        return null;
    }

    private static function curseForgeProjectUrl(string $slug): ?string
    {
        if ($slug === '' || preg_match('/^[A-Za-z0-9._-]{1,200}$/D', $slug) !== 1) {
            return null;
        }

        return "https://www.curseforge.com/minecraft/modpacks/{$slug}";
    }

    private static function ftbProjectUrl(string $projectId, ?string $title): ?string
    {
        if (preg_match('/^[1-9]\d*$/D', $projectId) !== 1) {
            return null;
        }

        $titleSlug = is_string($title) ? Str::slug($title) : '';

        return $titleSlug !== ''
            ? "https://www.feed-the-beast.com/modpacks/{$projectId}-{$titleSlug}"
            : "https://www.feed-the-beast.com/modpacks/{$projectId}";
    }

    public static function supportsCatalogFilters(ModpackProvider|string|null $provider): bool
    {
        $value = $provider instanceof ModpackProvider ? $provider->value : (is_string($provider) ? $provider : '');

        return in_array($value, [ModpackProvider::Modrinth->value, ModpackProvider::CurseForge->value], true);
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
                'description' => (string) ($pack->description ?? ''),
                'minecraft_versions' => $pack->minecraftVersion !== null ? [$pack->minecraftVersion] : [],
                'loaders' => $pack->loader !== null ? [$pack->loader] : [],
                'downloads' => $pack->downloads,
                'date_modified' => $pack->dateModified,
                'author' => $pack->author,
            ], $packs),
            'total_hits' => count($packs),
        ];
    }

    private function shortDescription(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $normalized = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($normalized === '') {
            return '';
        }

        if (mb_strlen($normalized) <= 120) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, 119)).'…';
    }

    /** @return list<string> */
    private function normalizeMinecraftVersions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $versions = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if (preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $trimmed) === 1) {
                $versions[$trimmed] = $trimmed;
            }
        }

        $list = array_values($versions);
        usort($list, static fn (string $a, string $b): int => version_compare($b, $a));

        return $list;
    }

    /** @return list<string> */
    private function normalizeLoaders(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $loaders = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $slug = strtolower(trim($item));
            if (in_array($slug, ['forge', 'neoforge', 'fabric', 'quilt'], true)) {
                $loaders[$slug] = $slug;
            }
        }

        return array_values($loaders);
    }

    /** @return array<string, string> */
    public function archiveProviders(): array
    {
        return array_intersect_key($this->providers(), array_fill_keys([ModpackProvider::Modrinth->value, ModpackProvider::CurseForge->value, ModpackProvider::Ftb->value], true));
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
     * @return list<array{id: string, label: string, version_number: string, version_type: ?string, downloads: ?int, date_published: ?string, minecraft: ?string, loader: ?string, featured: bool}>
     */
    public function versionList(string $selection): array
    {
        [$source, $projectId] = $this->splitSelection($selection);
        if ($source === null) {
            return [];
        }

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

        $normalized = [];
        foreach ($versions as $index => $version) {
            if (!is_array($version)) {
                continue;
            }
            $id = trim((string) ($version['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $label = trim((string) ($version['label'] ?? $id));
            $versionNumber = trim((string) ($version['version_number'] ?? $label));
            $normalized[] = [
                'id' => $id,
                'label' => $label !== '' ? $label : $id,
                'version_number' => $versionNumber !== '' ? $versionNumber : $id,
                'version_type' => is_string($version['version_type'] ?? null) && trim($version['version_type']) !== ''
                    ? strtolower(trim($version['version_type']))
                    : null,
                'downloads' => isset($version['downloads']) && is_numeric($version['downloads']) ? (int) $version['downloads'] : null,
                'date_published' => is_string($version['date_published'] ?? null) && trim($version['date_published']) !== ''
                    ? trim($version['date_published'])
                    : null,
                'minecraft' => is_string($version['minecraft'] ?? null) && trim($version['minecraft']) !== ''
                    ? trim($version['minecraft'])
                    : null,
                'loader' => is_string($version['loader'] ?? null) && trim($version['loader']) !== ''
                    ? trim($version['loader'])
                    : null,
                'featured' => (bool) ($version['featured'] ?? ($index === 0)),
            ];
        }

        return $normalized;
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
                ModpackProvider::Ftb->value => $this->ftbFile($projectId, $versionId),
                default => null,
            };

            return $download === null ? null : ['source' => $source, 'project_id' => $projectId] + $download;
        }

        return $this->latestFile($server, $source, $projectId);
    }

    /** @return array{version_id: string, version_number: string, filename: string, url: string}|null */
    private function ftbFile(string $packId, string $versionId): ?array
    {
        $url = FtbModpackSource::releaseUrl($packId, $versionId);

        return $url === null ? null : ['version_id' => $versionId, 'version_number' => $versionId,
            'filename' => 'ftb-'.$packId.'-'.$versionId.'.json', 'url' => $url];
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
                $reader = new CurseForgeManifestReader();
                $variables = $reader->read($serverUrl, 'variables.txt', allowWrapper: true);
                $runtime = $variables === null ? null : ModpackArchive::curseForgeServerPackMetadata($variables);
                if ($runtime !== null) {
                    return $runtime;
                }

                $manifest = $reader->read($serverUrl, 'manifest.json', allowWrapper: true);
                try {
                    return $manifest === null ? [] : array_filter(ModpackArchive::curseForgeInstallMetadata($manifest) ?? []);
                } catch (ModpackException) {
                    return [];
                }
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

        $cachedTitle = Cache::get('mod-manager:cf-title:'.$projectId);
        $title = is_string($cachedTitle) && $cachedTitle !== '' ? $cachedTitle : null;
        if ($title === null) {
            try {
                $peeked = $this->curseForge->peekProject($projectId, dispatchOnMiss: false)['data'] ?? null;
                $title = is_string($peeked['name'] ?? null) && $peeked['name'] !== '' ? $peeked['name'] : null;
            } catch (Throwable) {
                $title = null;
            }
        }

        return new ModpackDescriptor(
            provider: ModpackProvider::CurseForge->value,
            id: $projectId,
            title: $title ?? $projectId,
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
}
