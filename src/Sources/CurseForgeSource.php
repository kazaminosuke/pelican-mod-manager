<?php

namespace Kazaminosuke\ModManager\Sources;

use App\Models\Server;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Contracts\AuthoritativeBatchProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\BatchLatestVersionSourceInterface;
use Kazaminosuke\ModManager\Contracts\ProjectMetadataPeekManyInterface;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchAuthoritativeInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchHandlerInterface;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\DownloadUnavailableException;
use Kazaminosuke\ModManager\Exceptions\PartialSourceFetchException;
use Kazaminosuke\ModManager\Support\CachedProjectMetadata;
use Kazaminosuke\ModManager\Support\CachedSearchOperations;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\CatalogFields;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\Modpacks\CurseForgeFile;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackFileSelector;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackLoader;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackPaths;
use Kazaminosuke\ModManager\Support\ProjectIconUrl;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Kazaminosuke\ModManager\Support\UpstreamHttp;
use Throwable;

class CurseForgeSource implements AuthoritativeBatchProjectSourceInterface, BatchLatestVersionSourceInterface, ProjectMetadataPeekManyInterface, ProjectSourceInterface, SourceFetchAuthoritativeInterface, SourceFetchHandlerInterface
{
    protected const BASE_URL = 'https://api.curseforge.com/v1';

    /** Minecraft's numeric CurseForge gameId. */
    protected const GAME_ID = 432;

    /** CurseForge classId for "Minecraft Mods". */
    protected const CLASS_ID_MOD = 6;

    /** CurseForge classId for "Bukkit Plugins". */
    protected const CLASS_ID_PLUGIN = 5;

    /**
     * CurseForge publishes Minecraft datapacks and resource packs under its
     * Texture Packs class (classId 12). Datapacks are narrowed to the Data
     * Packs category; resource-pack searches exclude that category. They are
     * archive downloads rather than loader-specific jars, so searches and
     * version lookups deliberately do not apply a mod-loader filter
     * (see isCompatibleFileForType()).
     */
    protected const CLASS_ID_DATAPACK = 12;

    /** Resource Packs share CurseForge's Texture Packs classId with datapacks. */
    protected const CLASS_ID_RESOURCE_PACK = 12;

    /** CurseForge classId for Minecraft modpacks. */
    public const CLASS_ID_MODPACK = 4471;

    protected const CATEGORY_ID_DATAPACK = 5193;

    /** CurseForge rejects catalog requests whose index plus page size exceeds this. */
    protected const MAX_SEARCH_RESULTS = 10_000;

    protected const SEARCH_PAGE_SIZE = 20;

    /**
     * The API documentation does not publish a maximum for POST /mods/files.
     * A live request with 105 entries (93 unique IDs) succeeds, but keep response sizes bounded.
     */
    protected const BULK_FILES_CHUNK_SIZE = 100;

    /**
     * CurseForge's ModsSearchSortField enum values used by the catalog sort
     * dropdown (1 Featured, 2 Popularity, 3 LastUpdated, 4 Name, 5 Author,
     * 6 TotalDownloads, 7 Category, 8 GameVersion, 9 EarlyAccess,
     * 10 FeaturedRelease, 11 ReleaseDate, 12 Rating).
     */
    protected const SORT_FIELD_TOTAL_DOWNLOADS = 6;

    protected const SORT_FIELD_LAST_UPDATED = 3;

    protected const SORT_FIELD_POPULARITY = 2;

    protected const SORT_FIELD_RELEASED_DATE = 11;

    /** @var array<string, string> */
    protected array $lastWarmVersionFailures = [];

    private readonly CachedProjectMetadata $cachedProjectMetadata;

    private readonly CachedSearchOperations $cachedSearch;

    public function __construct(
        private readonly SourceCache $sourceCache,
    ) {
        $this->cachedProjectMetadata = new CachedProjectMetadata($sourceCache);
        $this->cachedSearch = new CachedSearchOperations($sourceCache);
    }

    public function getKey(): ProjectSourceKey
    {
        return ProjectSourceKey::CurseForge;
    }

    public function getLabel(): string
    {
        return 'CurseForge';
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    public function supportsProjectType(ProjectType $type): bool
    {
        return $this->classIdFor($type) !== null;
    }

    public function supportsSearch(): bool
    {
        return true;
    }

    public function supportsHashLookup(): bool
    {
        return true;
    }

    public function getHashAlgorithm(): ?string
    {
        return 'murmur2';
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function search(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): array
    {
        $result = $this->cachedSearch->search($this->buildSearchSpec($server, $type, $page, $search, $filters));

        return $this->normalizeSearchResult($result);
    }

    public function hasCachedSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool
    {
        return $this->cachedSearch->hasCached($this->buildSearchSpec($server, $type, $page, $search, $filters));
    }

    public function hasFreshCachedSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool
    {
        return $this->cachedSearch->hasFreshCached($this->buildSearchSpec($server, $type, $page, $search, $filters));
    }

    public function warmSearch(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): bool
    {
        return $this->cachedSearch->warm($this->buildSearchSpec($server, $type, $page, $search, $filters));
    }

    /**
     * Modpack catalog. This is separate from mod search: a missing loader does
     * not hide the catalog, and the class id is the modpack class.
     *
     * @return array{hits: array<int, array<string, mixed>>, total_hits: int}
     */
    public function searchModpacks(
        Server $server,
        int $page = 1,
        ?string $search = null,
        bool $constrainToServer = true,
        ?string $minecraft = null,
        ?string $loader = null,
    ): array {
        $result = $this->cachedSearch->search($this->buildModpackSearchSpec($server, $page, $search, $constrainToServer, $minecraft, $loader));

        return $this->normalizeSearchResult($result);
    }

    /**
     * Newest installable pack zip. A server pack is preferred over the
     * client installer when CurseForge publishes one.
     *
     * @return array{version_id: string, version_number: string, filename: string, url: string, minecraft: ?string, loader: ?string, server_pack: bool, has_server_pack: bool, manifest_url: ?string, artifact_id: string}|null
     */
    public function latestModpackDownload(Server $server, string $projectId): ?array
    {
        if (!$this->isConfigured() || !ctype_digit($projectId)) {
            return null;
        }

        $params = ['pageSize' => 50];
        $version = MinecraftVersionResolver::resolve($server);
        if ($version !== null) {
            $params['gameVersion'] = $version;
        }
        $loader = $this->modLoaderTypeFor($server);
        if ($loader !== null) {
            $params['modLoaderType'] = $loader;
        }

        $response = $this->getJson('/mods/'.$projectId.'/files', $params);
        $files = ModpackFileSelector::curseForgeZips((array) ($response['data'] ?? []));
        foreach ($files as $release) {
            if (ModpackFileSelector::isCurseForgeAdditionalFile($release)) {
                continue;
            }

            $download = $this->resolveModpackRelease($projectId, $release, $files);
            if ($download !== null) {
                return $download;
            }
        }

        foreach ($files as $file) {
            if (($file['isServerPack'] ?? false) === true && (int) ($file['parentProjectFileId'] ?? 0) === 0) {
                $download = $this->modpackReleaseDownload($projectId, $file, $file, true, null);
                if ($download !== null) {
                    return $download;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, label: string, version_number: string, version_type: ?string, downloads: ?int, date_published: ?string, minecraft: ?string, loader: ?string}>
     */
    public function listModpackVersions(string $projectId): array
    {
        if (!$this->isConfigured() || !ctype_digit($projectId)) {
            return [];
        }

        $response = $this->getJson('/mods/'.$projectId.'/files', ['pageSize' => 30]);
        $files = is_array($response['data'] ?? null) ? $response['data'] : [];
        $choices = [];
        foreach (ModpackFileSelector::curseForgeZips($files) as $file) {
            if (ModpackFileSelector::isCurseForgeAdditionalFile($file)) {
                continue;
            }

            $id = (string) $file['id'];
            [$minecraft, $loader] = $this->curseForgeFileTargets($file);
            $display = trim((string) ($file['displayName'] ?? ''));
            $label = $display !== '' ? $display : (string) $file['fileName'];
            if ($minecraft !== null) {
                $label .= ' · '.$minecraft;
            }
            if ($loader !== null) {
                $label .= ' · '.$loader;
            }

            $releaseType = match ((int) ($file['releaseType'] ?? 0)) {
                1 => 'release',
                2 => 'beta',
                3 => 'alpha',
                default => null,
            };

            $choices[] = [
                'id' => $id,
                'label' => $label,
                'version_number' => $display !== '' ? $display : (string) $file['fileName'],
                'version_type' => $releaseType,
                'downloads' => isset($file['downloadCount']) && is_numeric($file['downloadCount']) ? (int) $file['downloadCount'] : null,
                'date_published' => is_string($file['fileDate'] ?? null) ? $file['fileDate'] : null,
                'minecraft' => $minecraft,
                'loader' => $loader,
            ];
        }

        return $choices;
    }

    /**
     * @return array{version_id: string, version_number: string, filename: string, url: string, minecraft: ?string, loader: ?string, server_pack: bool, has_server_pack: bool, manifest_url: ?string, artifact_id: string}|null
     */
    public function modpackDownloadById(string $projectId, string $fileId): ?array
    {
        if (!$this->isConfigured() || !ctype_digit($projectId) || !ctype_digit($fileId)) {
            return null;
        }

        $file = $this->findModpackFile($projectId, (int) $fileId);
        if ($file === null) {
            return null;
        }

        // Accept old callers that saved a server pack ID directly.
        if (($file['isServerPack'] ?? false) === true) {
            $parent = $this->findModpackFile($projectId, (int) ($file['parentProjectFileId'] ?? 0));

            return $this->modpackReleaseDownload($projectId, $parent ?? $file, $file, true);
        }
        if (ModpackFileSelector::isCurseForgeAdditionalFile($file)) {
            return null;
        }

        return $this->resolveModpackRelease($projectId, $file);
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  list<array<string, mixed>>|null  $listedFiles
     * @return array{version_id: string, version_number: string, filename: string, url: string, minecraft: ?string, loader: ?string, server_pack: bool, has_server_pack: bool, manifest_url: ?string, artifact_id: string}|null
     */
    private function resolveModpackRelease(string $projectId, array $release, ?array $listedFiles = null): ?array
    {
        $linkedId = (int) ($release['serverPackFileId'] ?? 0);
        $linked = $this->findModpackFile($projectId, $linkedId);
        $hasServerPack = $linkedId > 0;
        if ($linked !== null && ModpackFileSelector::isCurseForgeServerPackFor($release, $linked)) {
            $download = $this->modpackReleaseDownload($projectId, $release, $linked, true);
            if ($download !== null) {
                return $download;
            }
        }

        if ($listedFiles === null) {
            // The additional file may only point back to its parent. Scope
            // this lookup to the selected project's file listing.
            try {
                $response = $this->getJson('/mods/'.$projectId.'/files', ['pageSize' => 50]);
                $listedFiles = ModpackFileSelector::curseForgeZips((array) ($response['data'] ?? []));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        foreach ($listedFiles ?? [] as $candidate) {
            if ((string) ($candidate['modId'] ?? $projectId) !== $projectId
                || !ModpackFileSelector::isCurseForgeServerPackFor($release, $candidate)) {
                continue;
            }

            $hasServerPack = true;
            $download = $this->modpackReleaseDownload($projectId, $release, $candidate, true);
            if ($download !== null) {
                return $download;
            }
        }

        $download = $this->modpackReleaseDownload($projectId, $release, $release, false);

        return $download === null ? null : array_replace($download, ['has_server_pack' => $hasServerPack]);
    }

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $file
     * @return array{version_id: string, version_number: string, filename: string, url: string, minecraft: ?string, loader: ?string, server_pack: bool, has_server_pack: bool, manifest_url: ?string, artifact_id: string}|null
     */
    private function modpackReleaseDownload(string $projectId, array $release, array $file, bool $serverPack): ?array
    {
        $download = $this->modpackZipDownload($file, $projectId);
        if ($download === null) {
            return null;
        }

        [$minecraft, $loader] = $this->curseForgeFileTargets($release);
        [$fileMinecraft, $fileLoader] = $this->curseForgeFileTargets($file);

        return array_replace($download, [
            'version_id' => (string) $release['id'],
            'version_number' => trim((string) ($release['displayName'] ?? '')) ?: (string) $release['fileName'],
            'minecraft' => $minecraft ?? $fileMinecraft,
            'loader' => $loader ?? $fileLoader,
            'server_pack' => $serverPack,
            'has_server_pack' => $serverPack,
            'artifact_id' => $download['version_id'],
            'manifest_url' => ModpackFileSelector::isCurseForgeAdditionalFile($release)
                ? null : ($serverPack ? ($this->modpackZipDownload($release)['url'] ?? null) : $download['url']),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function findModpackFile(string $projectId, int $fileId): ?array
    {
        if ($fileId <= 0) {
            return null;
        }

        try {
            $file = $this->modpackFile((int) $projectId, $fileId);
        } catch (RequestException $exception) {
            if (!in_array($exception->response->status(), [403, 404, 410], true)) {
                throw $exception;
            }

            return null;
        }

        return $file !== null
            && (int) ($file['id'] ?? 0) === $fileId
            && (string) ($file['modId'] ?? $projectId) === $projectId
            ? $file
            : null;
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array{0: ?string, 1: ?string}
     */
    private function curseForgeFileTargets(array $file): array
    {
        $minecraft = [];
        $loaders = [];
        foreach (is_array($file['gameVersions'] ?? null) ? $file['gameVersions'] : [] as $token) {
            if (!is_scalar($token)) {
                continue;
            }

            $value = trim((string) $token);
            $loader = ModpackLoader::normalize($value);
            if ($loader !== null) {
                $loaders[] = $loader;

                continue;
            }

            if (preg_match('/^\d+\.\d+(?:\.\d+)?$/', $value) === 1) {
                $minecraft[] = $value;
            }
        }

        return [
            count(array_unique($minecraft)) === 1 ? $minecraft[0] : null,
            count(array_unique($loaders)) === 1 ? $loaders[0] : null,
        ];
    }

    /**
     * Read exact Core API file records, including files without a download URL.
     *
     * @param list<array{project_id: int, file_id: int}> $dependencies
     * @return array<string, CurseForgeFile> keyed by "projectId/fileId"
     */
    public function dependencyFiles(array $dependencies): array
    {
        $deadline = microtime(true) + 45;
        $resolved = [];
        $stopReason = $this->isConfigured() ? null : 'api_unconfigured';
        foreach (array_chunk($dependencies, self::BULK_FILES_CHUNK_SIZE) as $chunk) {
            $bulk = [];
            if ($stopReason === null && count($chunk) > 1) {
                try {
                    $data = $this->getBulkFiles(array_map('strval', array_column($chunk, 'file_id')), $this->remainingTimeout($deadline));
                    foreach ($data['data'] as $file) {
                        $bulk[(int) ($file['id'] ?? 0)] = $file;
                    }
                } catch (Throwable $exception) {
                    $stopReason = $this->dependencyFailureReason($exception);
                }
            }

            $files = [];
            $needProjects = [];
            foreach ($chunk as ['project_id' => $projectId, 'file_id' => $fileId]) {
                $key = "{$projectId}/{$fileId}";
                $file = $bulk[$fileId] ?? null;
                $reason = null;
                if ($file === null && $stopReason === null) {
                    try {
                        $file = $this->getJson("/mods/{$projectId}/files/{$fileId}", timeoutSeconds: $this->remainingTimeout($deadline))['data'] ?? null;
                    } catch (Throwable $exception) {
                        $reason = $this->dependencyFailureReason($exception);
                        if ($reason !== 'api_not_found') {
                            $stopReason = $reason;
                        }
                    }
                }
                if ($file === null) {
                    $resolved[$key] = new CurseForgeFile($projectId, $fileId, reason: $reason ?? $stopReason ?? 'metadata_missing');

                    continue;
                }
                if (!is_array($file) || (int) ($file['id'] ?? 0) !== $fileId || (int) ($file['modId'] ?? 0) !== $projectId) {
                    $resolved[$key] = new CurseForgeFile($projectId, $fileId, reason: 'metadata_mismatch');

                    continue;
                }
                $files[$key] = $file;
                if (!ModpackPaths::isSafeHttpsDownloadUrl($file['downloadUrl'] ?? null)) {
                    $needProjects[$projectId] = (string) $projectId;
                }
            }

            $projects = [];
            if ($needProjects !== [] && $stopReason === null) {
                try {
                    foreach ($this->getBulkMods(array_values($needProjects), min(5.0, $this->remainingTimeout($deadline)))['data'] ?? [] as $project) {
                        if (is_array($project)) {
                            $projects[(int) ($project['id'] ?? 0)] = $project;
                        }
                    }
                } catch (Throwable) {
                    // File identity and hashes do not depend on display metadata.
                }
            }

            foreach ($files as $key => $file) {
                $resolved[$key] = $this->dependencyFile($file, $projects[(int) $file['modId']] ?? []);
            }
        }

        return $resolved;
    }

    private function dependencyFailureReason(Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            return match ($exception->response->status()) {
                401 => 'api_authentication',
                403 => 'api_forbidden',
                404, 410 => 'api_not_found',
                429 => 'api_rate_limited',
                default => 'api_error',
            };
        }

        return $exception instanceof ConnectionException || str_contains($exception->getMessage(), 'time budget')
            ? 'api_timeout' : 'api_error';
    }

    /** @param array<string, mixed> $file @param array<string, mixed> $project */
    private function dependencyFile(array $file, array $project): CurseForgeFile
    {
        $filename = ModpackPaths::safeRelative((string) ($file['fileName'] ?? ''));
        if ($filename !== null && ($filename !== ($file['fileName'] ?? null) || preg_match('~[/\x00-\x1f\x7f:]~', $filename) === 1)) {
            $filename = null;
        }
        $url = $file['downloadUrl'] ?? null;
        $canDownload = $filename !== null && ModpackPaths::isSafeHttpsDownloadUrl($url);
        $reason = match (true) {
            $filename === null => 'invalid_filename',
            $canDownload => null,
            ($project['allowModDistribution'] ?? null) === false,
            array_key_exists('downloadUrl', $file) && $url === null && ($project['allowModDistribution'] ?? null) !== true => 'distribution',
            default => 'metadata_missing',
        };
        $id = (int) $file['id'];
        $website = rtrim((string) ($project['links']['websiteUrl'] ?? ''), '/');
        $page = preg_match('~^https://(?:www\.)?curseforge\.com/minecraft/mc-mods/[a-z0-9_-]+$~iD', $website) === 1
            ? $website.'/files/'.$id : null;
        $hashes = [];
        foreach ($file['hashes'] ?? [] as $hash) {
            $algo = match ($hash['algo'] ?? null) {
                1 => 'sha1',
                2 => 'md5',
                default => null,
            };
            $value = $hash['value'] ?? null;
            if ($algo !== null && is_string($value) && ctype_xdigit($value) && strlen($value) === ($algo === 'sha1' ? 40 : 32)) {
                $hashes[$algo] = strtolower($value);
            }
        }

        return new CurseForgeFile(
            projectId: (int) $file['modId'],
            fileId: $id,
            filename: $filename,
            url: $canDownload ? $url : null,
            size: is_numeric($file['fileLength'] ?? null) && (int) $file['fileLength'] > 0 ? (int) $file['fileLength'] : null,
            hashes: $hashes,
            name: $project['name'] ?? $file['displayName'] ?? $filename,
            page: $page,
            reason: $reason,
        );
    }

    /** @param array<string, mixed> $filters */
    private function buildSearchSpec(Server $server, ProjectType $type, int $page, ?string $search, array $filters): ?SourceFetchSpec
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $classId = $this->classIdFor($type);
        if (!$classId) {
            return null;
        }

        // CurseForge caps index + pageSize at 10,000. Keeping page 501+ on
        // the final reachable offset gives the paginator a valid total to
        // clamp against instead of turning an API validation error into an
        // empty page with a zero range.
        $page = min(max(1, $page), intdiv(self::MAX_SEARCH_RESULTS, self::SEARCH_PAGE_SIZE));

        $hasVersionList = array_key_exists('versions', $filters);
        $rawVersions = $hasVersionList ? $filters['versions'] : ($filters['version'] ?? []);
        $disableVersionFilter = CatalogFields::disablesVersionFilter($rawVersions);
        $requestedVersions = $disableVersionFilter ? [] : $this->versionFilterValues($rawVersions);
        $automaticVersion = MinecraftVersionResolver::resolve($server);
        $params = [
            'gameId' => self::GAME_ID,
            'classId' => $classId,
            'index' => ($page - 1) * self::SEARCH_PAGE_SIZE,
            'pageSize' => self::SEARCH_PAGE_SIZE,
        ];

        if ($disableVersionFilter) {
            // Explicit "All" selected: omit gameVersion/gameVersions completely.
        } elseif ($hasVersionList) {
            if ($requestedVersions !== []) {
                // CurseForge's official API accepts up to four game versions
                // in the gameVersions list, with OR semantics.
                $params['gameVersions'] = json_encode(array_slice($requestedVersions, 0, 4), JSON_THROW_ON_ERROR);
            } elseif ($automaticVersion !== null) {
                $params['gameVersion'] = $automaticVersion;
            }
        } else {
            // Keep the pre-multi-select input contract for callers outside
            // the table page (and for older queued cache entries).
            $requestedVersion = $requestedVersions[0] ?? null;
            $params['gameVersion'] = $requestedVersion ?? $automaticVersion;
        }

        $sortField = match ($filters['sort'] ?? 'downloads') {
            'relevance' => null,
            'updated' => self::SORT_FIELD_LAST_UPDATED,
            'popularity' => self::SORT_FIELD_POPULARITY,
            'created' => self::SORT_FIELD_RELEASED_DATE,
            default => self::SORT_FIELD_TOTAL_DOWNLOADS,
        };
        if ($sortField !== null) {
            $params['sortField'] = $sortField;
            $params['sortOrder'] = 'desc';
        }

        if ($type === ProjectType::Mod) {
            $selectedLoaders = array_values(array_filter(array_map(
                static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null,
                (array) ($filters['loaders'] ?? []),
            ), static fn (?int $value): bool => $value !== null && in_array($value, [1, 2, 3, 4, 5, 6], true)));
            $modLoaderType = $selectedLoaders === [] ? $this->modLoaderTypeFor($server) : null;
            if ($selectedLoaders === [] && $modLoaderType === null) {
                return null;
            }
            if ($selectedLoaders !== []) {
                $params['modLoaderTypes'] = json_encode(array_values(array_unique($selectedLoaders)), JSON_THROW_ON_ERROR);
            } else {
                $params['modLoaderType'] = $modLoaderType;
            }
        }

        if ($type === ProjectType::Datapack) {
            $params['categoryId'] = self::CATEGORY_ID_DATAPACK;
        } else {
            $categoryIds = array_values(array_filter(array_map(
                static fn (mixed $value): int => (int) $value,
                (array) ($filters['categories'] ?? []),
            ), static fn (int $value): bool => $value > 0));
            if ($categoryIds !== []) {
                $params['categoryIds'] = json_encode(array_slice(array_values(array_unique($categoryIds)), 0, 10), JSON_THROW_ON_ERROR);
            }
        }
        if ($search) {
            $params['searchFilter'] = $search;
        }

        return new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'search',
            arguments: [
                'params' => $params,
                'project_type' => $type->value,
            ],
        );
    }

    private function buildModpackSearchSpec(Server $server, int $page, ?string $search, bool $constrainToServer, ?string $minecraft, ?string $loader): ?SourceFetchSpec
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $page = min(max(1, $page), intdiv(self::MAX_SEARCH_RESULTS, self::SEARCH_PAGE_SIZE));
        $params = [
            'gameId' => self::GAME_ID,
            'classId' => self::CLASS_ID_MODPACK,
            'index' => ($page - 1) * self::SEARCH_PAGE_SIZE,
            'pageSize' => self::SEARCH_PAGE_SIZE,
            'sortField' => self::SORT_FIELD_TOTAL_DOWNLOADS,
            'sortOrder' => 'desc',
        ];
        $minecraft ??= $constrainToServer ? MinecraftVersionResolver::resolve($server) : null;
        if ($minecraft !== null) {
            $params['gameVersion'] = $minecraft;
        }
        $loaderType = match ($loader) {
            'forge' => 1,
            'fabric' => 4,
            'quilt' => 5,
            'neoforge' => 6,
            default => $constrainToServer ? $this->modLoaderTypeFor($server) : null,
        };
        if ($loaderType !== null) {
            $params['modLoaderType'] = $loaderType;
        }
        $search = trim((string) $search);
        if ($search !== '') {
            $params['searchFilter'] = $search;
        }

        // project_type stays "mod" so the shared search handler can resolve the
        // enum. The class id is what makes the cached payload a modpack search,
        // and hits are relabeled before they are stored.
        return new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'search',
            arguments: [
                'params' => $params,
                'project_type' => ProjectType::Mod->value,
            ],
        );
    }

    /** @return array<string, mixed>|null */
    private function modpackFile(int $projectId, int $fileId): ?array
    {
        $response = $this->getJson("/mods/{$projectId}/files/{$fileId}");
        $file = $response['data'] ?? null;

        return is_array($file) ? $file : null;
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array{version_id: string, version_number: string, filename: string, url: string}|null
     */
    private function modpackZipDownload(array $file, ?string $projectId = null): ?array
    {
        $filename = trim((string) ($file['fileName'] ?? ''));
        $url = $file['downloadUrl'] ?? null;
        $id = trim((string) ($file['id'] ?? ''));
        if (!ctype_digit($id) || !str_ends_with(strtolower($filename), '.zip')) {
            return null;
        }

        if (($url === null || $url === '') && $projectId !== null) {
            // Use the official endpoint when the file object omits the URL.
            // A denied URL stays denied; the provider controls distribution.
            try {
                $url = $this->getJson("/mods/{$projectId}/files/{$id}/download-url", timeoutSeconds: 5.0)['data'] ?? null;
            } catch (RequestException $exception) {
                if (!in_array($exception->response->status(), [403, 404, 410], true)) {
                    throw $exception;
                }

                return null;
            }
        }

        if (!is_string($url) || !ModpackPaths::isSafeHttpsDownloadUrl($url)) {
            return null;
        }

        $version = trim((string) ($file['displayName'] ?? ''));

        return [
            'version_id' => $id,
            'version_number' => $version !== '' ? $version : $filename,
            'filename' => $filename,
            'url' => $url,
        ];
    }

    /**
     * @param array<string, int|string> $params
     * @return array{hits: array<int, array<string, mixed>>, total_hits: int}
     */
    protected function fetchSearch(array $params, ProjectType $type, float $timeoutSeconds): array
    {
        $debugTiming = (bool) config('pelican-mod-manager.debug_timing', false);
        $startedAt = $debugTiming ? microtime(true) : 0.0;
        $responseBytes = null;

        try {
            $response = $this->http($timeoutSeconds)
                ->throw()
                ->get(self::BASE_URL.'/mods/search', $params);
            if ($debugTiming) {
                $responseBytes = strlen($response->body());
            }

            $payload = $response->json();

            if (!is_array($payload)) {
                throw new Exception('Invalid CurseForge search response');
            }

            $isModpack = (int) ($params['classId'] ?? 0) === self::CLASS_ID_MODPACK;

            return $this->normalizeSearchResult([
                'hits' => collect($payload['data'] ?? [])
                    ->filter(fn ($mod) => is_array($mod))
                    ->filter(fn (array $mod) => $this->includeSearchHit($mod, $type))
                    ->map(function (array $mod) use ($type, $isModpack): array {
                        $project = $this->normalizeProject($mod, $type);
                        if ($isModpack) {
                            $project['project_type'] = 'modpack';
                        }

                        return $project;
                    })
                    ->values()
                    ->all(),
                'total_hits' => (int) ($payload['pagination']['totalCount'] ?? 0),
            ]);
        } finally {
            if ($debugTiming) {
                logger()->debug('Catalog search API timing', [
                    'source' => 'curseforge',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'response_bytes' => $responseBytes,
                ]);
            }
        }
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    private function normalizeSearchResult(mixed $result): array
    {
        if (!is_array($result)) {
            return ['hits' => [], 'total_hits' => 0];
        }

        return [
            'hits' => is_array($result['hits'] ?? null) ? $result['hits'] : [],
            'total_hits' => min(max(0, (int) ($result['total_hits'] ?? 0)), self::MAX_SEARCH_RESULTS),
        ];
    }

    /** @return array<string, mixed>|null */
    public function getProject(string $projectId): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return $this->cachedProjectMetadata->get($this->projectSpec($projectId));
    }

    /** @return array{data: array<string, mixed>|null, pending: bool, retry_delayed: bool} */
    public function peekProject(string $projectId, bool $dispatchOnMiss = true): array
    {
        if (!$this->isConfigured()) {
            return ['data' => null, 'pending' => false, 'retry_delayed' => false];
        }

        return $this->cachedProjectMetadata->peek($this->projectSpec($projectId), $dispatchOnMiss);
    }

    /** @return array<string, array{data: array<string, mixed>|null, pending: bool, retry_delayed: bool}> */
    public function peekProjects(array $projectIds): array
    {
        $projectIds = array_values(array_unique($projectIds));

        if (!$this->isConfigured()) {
            return array_fill_keys($projectIds, ['data' => null, 'pending' => false, 'retry_delayed' => false]);
        }

        return $this->cachedProjectMetadata->peekMany($projectIds, $this->projectSpec(...));
    }

    public function primeProjects(array $dataByProjectId): void
    {
        $this->cachedProjectMetadata->primeMany($dataByProjectId, $this->projectSpec(...));
    }

    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function getProjectsByIds(array $projectIds): array
    {
        return $this->getProjectsByIdsUsingCache($projectIds, authoritative: false);
    }

    public function getProjectsByIdsAuthoritatively(array $projectIds): array
    {
        return $this->getProjectsByIdsUsingCache($projectIds, authoritative: true);
    }

    public function getProjectsByIdsForMetadataWarm(array $projectIds): array
    {
        return $this->getProjectsByIdsUsingCache($projectIds, authoritative: true, freshRequired: true);
    }

    public function deferProjectMetadataRetries(array $projectIds): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $this->cachedProjectMetadata->markRetryDelayedMany($projectIds, $this->projectSpec(...));
    }

    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed>
     */
    protected function getProjectsByIdsUsingCache(array $projectIds, bool $authoritative, bool $freshRequired = false): array
    {
        if (empty($projectIds) || !$this->isConfigured()) {
            return [];
        }

        $modIds = array_values(array_unique(array_filter(
            array_map('intval', $projectIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($modIds === []) {
            return [];
        }

        $projects = [];
        $pendingModIds = $modIds;

        if (!$freshRequired) {
            $stringIds = array_map('strval', $modIds);
            $peeked = $this->cachedProjectMetadata->peekMany($stringIds, $this->projectSpec(...));
            $pendingModIds = [];

            foreach ($modIds as $modId) {
                $key = (string) $modId;
                $probe = $peeked[$key] ?? null;
                if (is_array($probe['data'] ?? null)) {
                    $projects[$key] = $probe['data'];

                    continue;
                }

                $pendingModIds[] = $modId;
            }
        }

        if ($pendingModIds === []) {
            return $projects;
        }

        sort($pendingModIds);
        $fetchedToPrime = [];

        foreach (array_chunk($pendingModIds, 100) as $chunk) {
            $spec = new SourceFetchSpec(
                sourceKey: $this->getKey()->value,
                operation: 'projects',
                arguments: ['project_ids' => $chunk],
            );

            $batch = $this->cachedProjectMetadata->getBatch($spec, $authoritative, $freshRequired);

            foreach ($batch as $id => $project) {
                if (is_array($project)) {
                    $key = (string) $id;
                    $projects[$key] = $project;
                    $fetchedToPrime[$key] = $project;
                }
            }
        }

        if ($fetchedToPrime !== []) {
            $this->primeProjects($fetchedToPrime);
        }

        return $projects;
    }

    /** @param array<int, int> $modIds */
    protected function fetchProjectsByIds(array $modIds, float $timeoutSeconds): array
    {
        $response = $this->http($timeoutSeconds)
            ->throw()
            ->post(self::BASE_URL.'/mods', ['modIds' => $modIds])
            ->json();

        $mods = $response['data'] ?? [];

        if (!is_array($mods)) {
            throw new Exception('Invalid CurseForge projects response');
        }

        $map = [];
        foreach ($mods as $mod) {
            if (is_array($mod) && isset($mod['id'])) {
                $map[(string) $mod['id']] = $this->normalizeProject($mod);
            }
        }

        return $map;
    }

    /** @return array<int, mixed> */
    public function getVersions(string $projectId, Server $server, ProjectType $type): array
    {
        $spec = $this->versionsSpec($projectId, $server, $type);
        $versions = $spec !== null ? $this->cache()->swr($spec, CacheProfile::InstalledLatest) : [];

        return is_array($versions) ? $versions : [];
    }

    /** @return array<int, mixed> */
    public function getVersionsAuthoritatively(string $projectId, Server $server, ProjectType $type): array
    {
        $spec = $this->versionsSpec($projectId, $server, $type);
        $versions = $spec !== null ? $this->cache()->swrForAction($spec, CacheProfile::InstalledLatest) : [];

        return is_array($versions) ? $versions : [];
    }

    private function versionsSpec(string $projectId, Server $server, ProjectType $type): ?SourceFetchSpec
    {
        $params = $this->getVersionRequestParams($server, $type);

        if ($params === null) {
            return null;
        }

        return new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'versions',
            arguments: [
                'project_id' => $projectId,
                'params' => $params,
                'project_type' => $type->value,
            ],
        );
    }

    protected function fetchWarmVersions(
        array $projectIds,
        array $params,
        ProjectType $type,
        float $timeoutSeconds,
    ): array {
        $this->lastWarmVersionFailures = [];
        $versionsByProjectId = [];
        $pending = [];

        foreach (array_values(array_unique($projectIds)) as $projectId) {
            $projectId = (string) $projectId;
            $pending[$projectId] = true;
        }

        $bulkStartedAt = (bool) config('pelican-mod-manager.debug_timing', false)
            ? microtime(true)
            : 0.0;
        $deadline = microtime(true) + $timeoutSeconds;
        $bulkResponse = $this->getBulkMods(
            array_keys($pending),
            $this->remainingTimeout($deadline),
        );

        $this->logBulkVersionsTiming(
            $bulkStartedAt,
            count($pending),
            is_array($bulkResponse['data'] ?? null) ? count($bulkResponse['data']) : 0,
        );

        $bulkVersionsByProjectId = $this->extractBulkLatestVersions($bulkResponse, $params, $deadline);

        foreach ($bulkVersionsByProjectId as $projectId => $payload) {
            if (!isset($pending[$projectId])) {
                continue;
            }

            $versions = $this->normalizeVersions($payload, $type);
            if ($versions !== []) {
                $versionsByProjectId[$projectId] = $versions;
            }
            unset($pending[$projectId]);
        }

        if ($pending === []) {
            return $versionsByProjectId;
        }

        try {
            $requestTimeout = $this->remainingTimeout($deadline);
            $responses = Http::pool(function (Pool $pool) use ($pending, $params, $requestTimeout) {
                $requests = [];

                foreach (array_keys($pending) as $projectId) {
                    $requests[] = $pool->as((string) $projectId)
                        ->asJson()
                        ->withHeaders([
                            'Accept-Encoding' => 'gzip, deflate',
                            'x-api-key' => $this->apiKey(),
                        ])
                        ->withOptions(['decode_content' => true])
                        ->timeout($requestTimeout)
                        ->connectTimeout(min(1.0, $requestTimeout))
                        ->get(self::BASE_URL."/mods/$projectId/files", $params);
                }

                return $requests;
            });
        } catch (Exception $exception) {
            report($exception);
            $responses = [];
        }

        foreach ($pending as $projectId => $_pending) {
            try {
                $response = $responses[$projectId] ?? null;

                if ($response === null || !$response->successful()) {
                    throw new Exception("CurseForge versions lookup failed for project $projectId");
                }

                $payload = $response->json();

                if (!is_array($payload)) {
                    throw new Exception("Invalid CurseForge versions response for project $projectId");
                }

                $versions = $this->normalizeVersions($payload, $type);
            } catch (Exception $exception) {
                report($exception);
                $this->lastWarmVersionFailures[(string) $projectId] = $exception->getMessage();

                continue;
            }

            // Do not negative-cache a transient API failure or an empty result.
            // A later request should be able to retry without waiting 30 minutes.
            if ($versions !== []) {
                $versionsByProjectId[$projectId] = $versions;
            }
        }

        return $versionsByProjectId;
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function lookupLatestVersions(
        array $requests,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        $serializedRequests = $this->serializeLatestRequests($requests);

        if ($serializedRequests === []) {
            return LatestVersionLookupResult::empty();
        }

        $params = $this->getVersionRequestParams($server, $type);
        if ($params === null) {
            return LatestVersionLookupResult::failed(
                $requests,
                'No compatible CurseForge loader is configured.',
            );
        }

        $result = $this->cache()->swr(new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'latest',
            arguments: [
                'params' => $params,
                'requests' => $serializedRequests,
                'project_type' => $type->value,
            ],
        ), CacheProfile::InstalledLatest);

        return $result instanceof LatestVersionLookupResult
            ? $result
            : LatestVersionLookupResult::empty();
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function peekLatestVersions(
        array $requests,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        $validRequests = array_values(array_filter(
            $requests,
            fn ($request) => $request instanceof LatestVersionLookupRequest,
        ));
        $serializedRequests = $this->serializeLatestRequests($validRequests);

        if ($serializedRequests === []) {
            return LatestVersionLookupResult::empty();
        }

        $params = $this->getVersionRequestParams($server, $type);
        if ($params === null) {
            return LatestVersionLookupResult::failed(
                $validRequests,
                'No compatible CurseForge loader is configured.',
            );
        }

        $spec = new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'latest',
            arguments: [
                'params' => $params,
                'requests' => $serializedRequests,
                'project_type' => $type->value,
            ],
        );
        $peeked = $this->cache()->swrDeferred($spec, CacheProfile::InstalledLatest);

        if ($peeked['pending']) {
            return new LatestVersionLookupResult(pendingKeys: array_map(
                fn (LatestVersionLookupRequest $request): string => $request->key(),
                $validRequests,
            ));
        }

        return $peeked['data'] instanceof LatestVersionLookupResult
            ? $peeked['data']
            : LatestVersionLookupResult::empty();
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    protected function fetchLatestVersions(
        array $requests,
        array $params,
        ProjectType $type,
        float $timeoutSeconds,
    ): LatestVersionLookupResult {
        $debugTiming = (bool) config('pelican-mod-manager.debug_timing', false);
        $startedAt = $debugTiming ? microtime(true) : 0.0;
        $validRequests = array_values(array_filter(
            $requests,
            fn ($request) => $request instanceof LatestVersionLookupRequest,
        ));

        if ($validRequests === []) {
            return LatestVersionLookupResult::empty();
        }

        $versionsByProjectId = $this->fetchWarmVersions(
            array_map(fn (LatestVersionLookupRequest $request) => $request->projectId, $validRequests),
            $params,
            $type,
            $timeoutSeconds,
        );

        $versionsByKey = [];
        $unresolvedKeys = [];
        $failuresByKey = [];

        foreach ($validRequests as $request) {
            $version = $versionsByProjectId[$request->projectId][0] ?? null;

            if (is_array($version)) {
                $versionsByKey[$request->key()] = $version;
            } elseif (isset($this->lastWarmVersionFailures[$request->projectId])) {
                $failuresByKey[$request->key()] = $this->lastWarmVersionFailures[$request->projectId];
            } else {
                $unresolvedKeys[] = $request->key();
            }
        }

        if ($debugTiming) {
            logger()->info('Mod manager timing', [
                'stage' => 'curseforge_latest_lookup_batch',
                'request_id' => request()->attributes->get('mmr_timing_request_id'),
                'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'requested_project_count' => count($validRequests),
                'resolved_project_count' => count($versionsByKey),
                'unresolved_project_count' => count($unresolvedKeys),
                'failed_project_count' => count($failuresByKey),
            ]);
        }

        $result = new LatestVersionLookupResult(
            versionsByKey: $versionsByKey,
            unresolvedKeys: $unresolvedKeys,
            failuresByKey: $failuresByKey,
        );

        if ($failuresByKey !== []) {
            throw new PartialSourceFetchException(
                'CurseForge latest-version batch completed with partial failures.',
                $result,
            );
        }

        return $result;
    }

    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed>
     */
    protected function getBulkMods(array $projectIds, float $timeoutSeconds): array
    {
        $response = $this->http($timeoutSeconds)
            ->throw()
            ->post(self::BASE_URL.'/mods', ['modIds' => array_map('intval', $projectIds)])
            ->json();

        if (!is_array($response)) {
            throw new Exception('Invalid CurseForge bulk mods response');
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, int|string> $params
     * @return array<string, array<string, array<int, mixed>>>
     */
    protected function extractBulkLatestVersions(array $response, array $params, float $deadline): array
    {
        $fileIdsByProjectId = [];
        $filesById = [];

        foreach ($response['data'] ?? [] as $mod) {
            if (!is_array($mod) || !isset($mod['id']) || !is_array($mod['latestFiles'] ?? null) || !is_array($mod['latestFilesIndexes'] ?? null)) {
                continue;
            }

            $fileIds = [];
            foreach ($mod['latestFilesIndexes'] as $index) {
                if (!is_array($index) || ($index['gameVersion'] ?? null) !== $params['gameVersion']) {
                    continue;
                }

                if (isset($params['modLoaderType']) && (int) ($index['modLoader'] ?? -1) !== (int) $params['modLoaderType']) {
                    continue;
                }

                $fileIds[] = (string) ($index['fileId'] ?? '');
            }

            if ($fileIds === []) {
                continue;
            }

            $fileIdsByProjectId[(string) $mod['id']] = array_values(array_unique($fileIds));

            foreach ($mod['latestFiles'] as $file) {
                if (is_array($file) && isset($file['id'])) {
                    $filesById[(string) $file['id']] = $file;
                }
            }
        }

        $requiredFileIds = array_values(array_unique(array_merge(...array_values($fileIdsByProjectId))));
        $missingFileIds = array_values(array_diff($requiredFileIds, array_keys($filesById)));

        if ($missingFileIds !== []) {
            $bulkFilesStartedAt = (bool) config('pelican-mod-manager.debug_timing', false)
                ? microtime(true)
                : 0.0;
            $bulkFilesResponse = $this->getBulkFiles(
                $missingFileIds,
                $this->remainingTimeout($deadline),
            );
            $returnedFiles = is_array($bulkFilesResponse['data'] ?? null) ? $bulkFilesResponse['data'] : [];

            $this->logBulkFilesTiming(
                $bulkFilesStartedAt,
                count($missingFileIds),
                count($returnedFiles),
                (int) ceil(count($missingFileIds) / self::BULK_FILES_CHUNK_SIZE),
            );

            foreach ($returnedFiles as $file) {
                if (is_array($file) && isset($file['id'])) {
                    $filesById[(string) $file['id']] = $file;
                }
            }
        }

        $versionsByProjectId = [];

        foreach ($fileIdsByProjectId as $projectId => $fileIds) {
            $files = [];
            foreach ($fileIds as $fileId) {
                if (!isset($filesById[$fileId])) {
                    continue 2;
                }

                $files[] = $filesById[$fileId];
            }

            $versionsByProjectId[$projectId] = ['data' => $files];
        }

        return $versionsByProjectId;
    }

    /**
     * @param array<int, string> $fileIds
     * @return array<string, mixed>
     */
    protected function getBulkFiles(array $fileIds, float $timeoutSeconds): array
    {
        $files = [];
        $deadline = microtime(true) + $timeoutSeconds;

        foreach (array_chunk(array_values(array_unique($fileIds)), self::BULK_FILES_CHUNK_SIZE) as $chunk) {
            $response = $this->postJson('/mods/files', [
                'fileIds' => array_map('intval', $chunk),
            ], $this->remainingTimeout($deadline));

            foreach ($response['data'] ?? [] as $file) {
                if (is_array($file)) {
                    $files[] = $file;
                }
            }
        }

        return ['data' => $files];
    }

    protected function logBulkVersionsTiming(float $startedAt, int $requestedProjectCount, int $returnedProjectCount): void
    {
        if (!(bool) config('pelican-mod-manager.debug_timing', false)) {
            return;
        }

        logger()->info('Mod manager timing', [
            'stage' => 'curseforge_versions_bulk_request',
            'request_id' => request()->attributes->get('mmr_timing_request_id'),
            'endpoint' => '/mods',
            'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
            'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'requested_project_count' => $requestedProjectCount,
            'returned_project_count' => $returnedProjectCount,
        ]);
    }

    protected function logBulkFilesTiming(float $startedAt, int $requestedFileCount, int $returnedFileCount, int $requestCount): void
    {
        if (!(bool) config('pelican-mod-manager.debug_timing', false)) {
            return;
        }

        logger()->info('Mod manager timing', [
            'stage' => 'curseforge_files_bulk_request',
            'request_id' => request()->attributes->get('mmr_timing_request_id'),
            'endpoint' => '/mods/files',
            'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
            'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'requested_file_count' => $requestedFileCount,
            'returned_file_count' => $returnedFileCount,
            'request_count' => $requestCount,
        ]);
    }

    protected function getModManagerTimingElapsedMs(?float $timestamp = null): ?int
    {
        if (!(bool) config('pelican-mod-manager.debug_timing', false)) {
            return null;
        }

        $startedAt = request()->attributes->get('mmr_timing_started_at');

        if (!is_float($startedAt)) {
            return null;
        }

        return (int) round((($timestamp ?? microtime(true)) - $startedAt) * 1000);
    }

    /** @return array<string, int|string>|null */
    protected function getVersionRequestParams(Server $server, ProjectType $type): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $params = [
            'gameVersion' => MinecraftVersionResolver::resolve($server),
            'pageSize' => 50,
        ];

        if ($this->classIdFor($type) === null) {
            return null;
        }

        if ($type === ProjectType::Mod) {
            $modLoaderType = $this->modLoaderTypeFor($server);

            if ($modLoaderType === null) {
                return null;
            }

            $params['modLoaderType'] = $modLoaderType;
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, mixed>
     */
    protected function normalizeVersions(array $response, ?ProjectType $type = null): array
    {
        $versions = collect($response['data'] ?? [])
            ->filter(fn ($file) => is_array($file)
                && ($file['isAvailable'] ?? true)
                && $this->isCompatibleFileForType($file, $type))
            ->map(fn (array $file) => $this->normalizeVersion($file))
            ->values()
            ->all();

        usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));

        return $versions;
    }

    /**
     * @param array<string, string> $hashesByFilename [filename => murmur2 fingerprint as a decimal string]
     * @return array<string, mixed> [fingerprint => normalized version data]
     */
    public function findVersionsByHash(array $hashesByFilename): array
    {
        return $this->findVersionsByHashUsingCache($hashesByFilename, authoritative: false);
    }

    public function findVersionsByHashAuthoritatively(array $hashesByFilename): array
    {
        return $this->findVersionsByHashUsingCache($hashesByFilename, authoritative: true);
    }

    /**
     * @param array<string, string> $hashesByFilename
     * @return array<string, mixed>
     */
    protected function findVersionsByHashUsingCache(array $hashesByFilename, bool $authoritative): array
    {
        if (empty($hashesByFilename) || !$this->isConfigured()) {
            return [];
        }

        $fingerprints = [];
        foreach ($hashesByFilename as $hash) {
            if (ctype_digit((string) $hash)) {
                $fingerprints[] = (int) $hash;
            }
        }

        $fingerprints = array_values(array_unique($fingerprints));

        if (empty($fingerprints)) {
            return [];
        }

        sort($fingerprints);

        $spec = new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'hashes',
            arguments: ['fingerprints' => $fingerprints],
        );
        $versions = $authoritative
            ? $this->cache()->swrRequired($spec, CacheProfile::HashMatch)
            : $this->cache()->swr($spec, CacheProfile::HashMatch);

        return is_array($versions) ? $versions : [];
    }

    /** @param array<int, int> $fingerprints */
    protected function fetchVersionsByHash(array $fingerprints, float $timeoutSeconds): array
    {
        $response = $this->postJson(
            '/fingerprints',
            ['fingerprints' => $fingerprints],
            $timeoutSeconds,
        );

        $exactMatches = $response['data']['exactMatches'] ?? [];

        if (!is_array($exactMatches)) {
            return [];
        }

        $results = [];
        foreach ($exactMatches as $match) {
            $file = is_array($match) ? ($match['file'] ?? null) : null;

            if (!is_array($file) || !isset($file['fileFingerprint'])) {
                continue;
            }

            $version = $this->normalizeVersion($file);
            $version['project_id'] = (string) ($file['modId'] ?? '');

            $results[(string) $file['fileFingerprint']] = $version;
        }

        return $results;
    }

    /** @return array<string, mixed>|null */
    public function resolveProjectByIdentifier(string $identifier): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        if (ctype_digit($identifier)) {
            return $this->getProject($identifier);
        }

        return $this->cachedProjectMetadata->get(new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'resolve_identifier',
            arguments: ['identifier' => $identifier],
        ));
    }

    /** @return array<string, string> */
    public function catalogCategoryOptions(ProjectType $type): array
    {
        if (!$this->isConfigured() || $type === ProjectType::Datapack) {
            return [];
        }

        $classId = $this->classIdFor($type);
        if ($classId === null) {
            return [];
        }

        try {
            $categories = Cache::remember(
                "pelican-mod-manager:curseforge-categories:$classId",
                now()->addDay(),
                fn (): array => $this->getJson('/categories', ['gameId' => self::GAME_ID, 'classId' => $classId])['data'] ?? [],
            );
        } catch (Throwable) {
            return [];
        }

        $options = [];
        foreach (is_array($categories) ? $categories : [] as $category) {
            if (!is_array($category)) {
                continue;
            }

            $id = (int) ($category['id'] ?? 0);
            $name = trim((string) ($category['name'] ?? ''));
            if ($id <= 0 || $name === '' || ($type === ProjectType::ResourcePack && $id === self::CATEGORY_ID_DATAPACK)) {
                continue;
            }

            $options[(string) $id] = $name;
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /** @return array<string, string> */
    public function catalogVersionOptions(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            $versions = Cache::remember(
                'pelican-mod-manager:curseforge-catalog-game-versions',
                now()->addDay(),
                fn (): array => $this->getJson('/minecraft/version')['data'] ?? [],
            );
        } catch (Throwable) {
            return [];
        }

        $options = [];
        foreach (is_array($versions) ? $versions : [] as $version) {
            if (!is_array($version)) {
                continue;
            }

            $value = trim((string) ($version['versionString'] ?? ''));
            if ($value !== '') {
                $options[$value] = $value;
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    public function catalogLoaderOptions(): array
    {
        return [
            '1' => 'Forge',
            '2' => 'Cauldron',
            '3' => 'LiteLoader',
            '4' => 'Fabric',
            '5' => 'Quilt',
            '6' => 'NeoForge',
        ];
    }

    /** @return array<int, string> */
    private function versionFilterValues(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $values,
        ), static fn (string $version): bool => preg_match('/^[0-9A-Za-z._+\-]{1,32}$/', $version) === 1)));
    }

    protected function fetchProjectByIdentifier(string $identifier, float $timeoutSeconds): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        foreach ([self::CLASS_ID_MOD, self::CLASS_ID_PLUGIN, self::CLASS_ID_DATAPACK] as $classId) {
            $response = $this->getJson('/mods/search', [
                'gameId' => self::GAME_ID,
                'classId' => $classId,
                'slug' => $identifier,
                'pageSize' => 1,
            ], $this->remainingTimeout($deadline));

            $mod = $response['data'][0] ?? null;

            if (is_array($mod) && ($mod['slug'] ?? null) === $identifier) {
                return $this->normalizeProject($mod);
            }
        }

        return null;
    }

    public function fetchSourceData(SourceFetchSpec $spec, float $timeoutSeconds): mixed
    {
        return match ($spec->operation) {
            'search' => $this->fetchSearch(
                (array) ($spec->arguments['params'] ?? []),
                ProjectType::from((string) ($spec->arguments['project_type'] ?? '')),
                $timeoutSeconds,
            ),
            'project' => $this->fetchProject(
                (string) ($spec->arguments['project_id'] ?? ''),
                $timeoutSeconds,
            ),
            'projects' => $this->fetchProjectsByIds(
                array_values(array_map('intval', (array) ($spec->arguments['project_ids'] ?? []))),
                $timeoutSeconds,
            ),
            'versions' => $this->normalizeVersions($this->getJson(
                '/mods/'.(string) ($spec->arguments['project_id'] ?? '').'/files',
                (array) ($spec->arguments['params'] ?? []),
                $timeoutSeconds,
            ), ProjectType::from((string) ($spec->arguments['project_type'] ?? 'mod'))),
            'latest' => $this->fetchLatestVersions(
                $this->hydrateLatestRequests((array) ($spec->arguments['requests'] ?? [])),
                (array) ($spec->arguments['params'] ?? []),
                ProjectType::from((string) ($spec->arguments['project_type'] ?? 'mod')),
                $timeoutSeconds,
            ),
            'hashes' => $this->fetchVersionsByHash(
                array_values(array_map('intval', (array) ($spec->arguments['fingerprints'] ?? []))),
                $timeoutSeconds,
            ),
            'resolve_identifier' => $this->fetchProjectByIdentifier(
                (string) ($spec->arguments['identifier'] ?? ''),
                $timeoutSeconds,
            ),
            default => throw new Exception("Unsupported CurseForge source-cache operation [{$spec->operation}]."),
        };
    }

    public function emptySourceData(SourceFetchSpec $spec): mixed
    {
        return match ($spec->operation) {
            'search' => ['hits' => [], 'total_hits' => 0],
            'project', 'resolve_identifier' => null,
            'latest' => LatestVersionLookupResult::empty(),
            default => [],
        };
    }

    protected function fetchProject(string $projectId, float $timeoutSeconds): ?array
    {
        $response = $this->getJson("/mods/$projectId", timeoutSeconds: $timeoutSeconds);
        $mod = $response['data'] ?? null;

        return is_array($mod) ? $this->normalizeProject($mod) : null;
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     * @return array<int, array<string, mixed>>
     */
    protected function serializeLatestRequests(array $requests): array
    {
        $serialized = [];

        foreach ($requests as $request) {
            if (!$request instanceof LatestVersionLookupRequest) {
                continue;
            }

            $serialized[$request->key()] = [
                'source' => $request->source,
                'project_id' => $request->projectId,
            ];
        }

        $serialized = array_values($serialized);
        usort($serialized, fn (array $left, array $right): int => strcmp(
            $left['source'].':'.$left['project_id'],
            $right['source'].':'.$right['project_id'],
        ));

        return $serialized;
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     * @return array<int, LatestVersionLookupRequest>
     */
    protected function hydrateLatestRequests(array $requests): array
    {
        $hydrated = [];

        foreach ($requests as $request) {
            if (!is_array($request)) {
                continue;
            }

            $hydrated[] = new LatestVersionLookupRequest(
                source: (string) ($request['source'] ?? $this->getKey()->value),
                projectId: (string) ($request['project_id'] ?? ''),
                installedVersionId: (string) ($request['installed_version_id'] ?? ''),
                filename: (string) ($request['filename'] ?? ''),
                hashes: array_filter(
                    (array) ($request['hashes'] ?? []),
                    fn (mixed $hash, mixed $algorithm): bool => is_string($algorithm) && is_string($hash),
                    ARRAY_FILTER_USE_BOTH,
                ),
            );
        }

        return $hydrated;
    }

    protected function classIdFor(ProjectType $type): ?int
    {
        return match ($type) {
            ProjectType::Mod => self::CLASS_ID_MOD,
            ProjectType::Plugin => self::CLASS_ID_PLUGIN,
            ProjectType::Datapack => self::CLASS_ID_DATAPACK,
            ProjectType::ResourcePack => self::CLASS_ID_RESOURCE_PACK,
        };
    }

    /** @param array<string, mixed> $file */
    protected function isCompatibleFileForType(array $file, ?ProjectType $type): bool
    {
        return !in_array($type, [ProjectType::Datapack, ProjectType::ResourcePack], true)
            || str_ends_with(strtolower((string) ($file['fileName'] ?? '')), '.zip');
    }

    /** @param array<string, mixed> $mod */
    protected function includeSearchHit(array $mod, ProjectType $type): bool
    {
        if ($type !== ProjectType::ResourcePack) {
            return true;
        }

        if (!$this->isResourcePackProject($mod)) {
            return false;
        }

        // Search already returns this field on each hit. Only drop projects
        // CurseForge explicitly marks as undistributable; null/absent stays.
        return ($mod['allowModDistribution'] ?? null) !== false;
    }

    /** @param array<string, mixed> $mod */
    protected function isResourcePackProject(array $mod): bool
    {
        foreach ((array) ($mod['categories'] ?? []) as $category) {
            $categoryId = is_array($category) ? ($category['id'] ?? null) : $category;

            if ((int) $categoryId === self::CATEGORY_ID_DATAPACK) {
                return false;
            }
        }

        return true;
    }

    /**
     * Maps the server's (source-agnostic) loader to CurseForge's ModLoaderType enum.
     * Only meaningful for classId=6 (Mods); Bukkit Plugins (classId=5) aren't
     * filtered by loader on CurseForge.
     */
    protected function modLoaderTypeFor(Server $server): ?int
    {
        return match (MinecraftLoader::fromServer($server)) {
            MinecraftLoader::Forge => 1,
            MinecraftLoader::Fabric => 4,
            MinecraftLoader::Quilt => 5,
            MinecraftLoader::NeoForge => 6,
            default => null,
        };
    }

    /** @param array<string, mixed> $mod */
    protected function normalizeProject(array $mod, ?ProjectType $type = null): array
    {
        $logo = $mod['logo'] ?? null;
        $author = $mod['authors'][0]['name'] ?? null;

        return [
            'project_id' => (string) ($mod['id'] ?? ''),
            'slug' => $mod['slug'] ?? '',
            'title' => $mod['name'] ?? '',
            'description' => CatalogFields::description($mod['summary'] ?? ''),
            'icon_url' => ProjectIconUrl::curseForgeThumbnail($logo['thumbnailUrl'] ?? $logo['url'] ?? null),
            'author' => (is_string($author) && $author !== '') ? $author : null,
            'downloads' => (int) ($mod['downloadCount'] ?? 0),
            'date_modified' => $mod['dateModified'] ?? null,
            'project_type' => $type?->value ?? '',
            'minecraft_versions' => $this->extractProjectMinecraftVersions($mod),
            'loaders' => $this->extractProjectLoaders($mod),
        ];
    }

    /**
     * @param  array<string, mixed>  $mod
     * @return list<string>
     */
    private function extractProjectMinecraftVersions(array $mod): array
    {
        $versions = [];

        foreach (is_array($mod['latestFilesIndexes'] ?? null) ? $mod['latestFilesIndexes'] : [] as $index) {
            if (!is_array($index) || !is_string($index['gameVersion'] ?? null)) {
                continue;
            }
            $version = trim($index['gameVersion']);
            if (preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $version) === 1) {
                $versions[$version] = $version;
            }
        }

        foreach (is_array($mod['latestFiles'] ?? null) ? $mod['latestFiles'] : [] as $file) {
            if (!is_array($file) || !is_array($file['gameVersions'] ?? null)) {
                continue;
            }
            foreach ($file['gameVersions'] as $gv) {
                if (!is_string($gv)) {
                    continue;
                }
                $version = trim($gv);
                if (preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $version) === 1) {
                    $versions[$version] = $version;
                }
            }
        }

        $list = array_values($versions);
        usort($list, static fn (string $a, string $b): int => version_compare($b, $a));

        return $list;
    }

    /**
     * @param  array<string, mixed>  $mod
     * @return list<string>
     */
    private function extractProjectLoaders(array $mod): array
    {
        $loaders = [];

        foreach (is_array($mod['latestFilesIndexes'] ?? null) ? $mod['latestFilesIndexes'] : [] as $index) {
            if (!is_array($index)) {
                continue;
            }
            $loader = match ($index['modLoader'] ?? null) {
                1 => 'forge',
                4 => 'fabric',
                5 => 'quilt',
                6 => 'neoforge',
                default => null,
            };
            if ($loader !== null) {
                $loaders[$loader] = $loader;
            }
        }

        foreach (is_array($mod['latestFiles'] ?? null) ? $mod['latestFiles'] : [] as $file) {
            if (!is_array($file) || !is_array($file['gameVersions'] ?? null)) {
                continue;
            }
            foreach ($file['gameVersions'] as $gv) {
                if (!is_string($gv)) {
                    continue;
                }
                $slug = strtolower(trim($gv));
                if (in_array($slug, ['forge', 'neoforge', 'fabric', 'quilt'], true)) {
                    $loaders[$slug] = $slug;
                }
            }
        }

        return array_values($loaders);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    protected function normalizeVersion(array $file): array
    {
        $releaseType = match ($file['releaseType'] ?? null) {
            2 => 'beta',
            3 => 'alpha',
            default => 'release',
        };

        $hashes = [];
        foreach (($file['hashes'] ?? []) as $hash) {
            if (!is_array($hash) || !isset($hash['value'])) {
                continue;
            }

            $algo = match ($hash['algo'] ?? null) {
                1 => 'sha1',
                2 => 'md5',
                default => null,
            };

            if ($algo) {
                $hashes[$algo] = $hash['value'];
            }
        }

        $downloadUrl = $file['downloadUrl'] ?? null;
        $distributionDisabled = array_key_exists('downloadUrl', $file)
            && (!is_string($downloadUrl) || $downloadUrl === '');
        $version = [
            'id' => (string) ($file['id'] ?? ''),
            'version_number' => $file['displayName'] ?? $file['fileName'] ?? '',
            'version_type' => $releaseType,
            // CurseForge's file listing only exposes a mod-level downloadCount, not
            // a per-file one, so this is always 0.
            'downloads' => 0,
            'date_published' => $file['fileDate'] ?? null,
            // Changelogs require a separate request per file
            // (GET /mods/{modId}/files/{fileId}/changelog); not fetched here to
            // avoid an N+1 request per version when listing.
            'changelog' => null,
            'featured' => false,
            'files' => $distributionDisabled ? [] : [
                [
                    'primary' => true,
                    'filename' => $file['fileName'] ?? '',
                    'url' => is_string($downloadUrl) ? $downloadUrl : null,
                    'hashes' => $hashes,
                ],
            ],
        ];
        if ($distributionDisabled) {
            $version['download_unavailable'] = DownloadUnavailableException::DISTRIBUTION;
        }

        return $version;
    }

    private function http(float $timeoutSeconds): PendingRequest
    {
        $timeoutSeconds = max(0.1, $timeoutSeconds);

        return UpstreamHttp::json(['x-api-key' => $this->apiKey()])
            ->timeout($timeoutSeconds)
            ->connectTimeout(min(1.0, $timeoutSeconds));
    }

    /** @param array<string, mixed> $query */
    protected function getJson(string $path, array $query = [], float $timeoutSeconds = 10.0): array
    {
        $response = $this->http($timeoutSeconds)
            ->throw()
            ->get(self::BASE_URL.$path, $query)
            ->json();

        if (!is_array($response)) {
            throw new Exception("Invalid CurseForge response for [$path]");
        }

        return $response;
    }

    /** @param array<string, mixed> $body */
    protected function postJson(string $path, array $body, float $timeoutSeconds = 10.0): array
    {
        $response = $this->http($timeoutSeconds)
            ->throw()
            ->post(self::BASE_URL.$path, $body)
            ->json();

        if (!is_array($response)) {
            throw new Exception("Invalid CurseForge response for [$path]");
        }

        return $response;
    }

    protected function remainingTimeout(float $deadline): float
    {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0.05) {
            throw new Exception('CurseForge source fetch exceeded its time budget.');
        }

        return $remaining;
    }

    protected function cache(): SourceCache
    {
        return $this->sourceCache;
    }

    private function projectSpec(string $projectId): SourceFetchSpec
    {
        return new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'project',
            arguments: ['project_id' => $projectId],
        );
    }

    protected function apiKey(): string
    {
        return (string) config('pelican-mod-manager.curseforge_api_key');
    }
}
