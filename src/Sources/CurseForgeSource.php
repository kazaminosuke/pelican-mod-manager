<?php

namespace Kazaminosuke\ModManager\Sources;

use App\Models\Server;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
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
use Kazaminosuke\ModManager\Exceptions\PartialSourceFetchException;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\CatalogFields;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\ProjectIconUrl;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Kazaminosuke\ModManager\Support\UpstreamHttp;

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

    /** @var array<string, string> */
    protected array $lastWarmVersionFailures = [];

    public function __construct(
        private readonly SourceCache $sourceCache,
    ) {}

    public function getKey(): ProjectSourceKey
    {
        return ProjectSourceKey::CurseForge;
    }

    public function getLabel(): string
    {
        return 'CurseForge';
    }

    public function requiresApiKey(): bool
    {
        return true;
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

    public function supportsDirectIdentifier(): bool
    {
        return true;
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function search(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): array
    {
        $spec = $this->buildSearchSpec($server, $type, $page, $search, $filters);

        if ($spec === null) {
            return ['hits' => [], 'total_hits' => 0];
        }

        $result = $this->cache()->swr($spec, CacheProfile::Search);

        return $this->normalizeSearchResult($result);
    }

    public function hasCachedSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool
    {
        $spec = $this->buildSearchSpec($server, $type, $page, $search, $filters);

        return $spec === null || $this->cache()->peek($spec)['hit'];
    }

    public function hasFreshCachedSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool
    {
        $spec = $this->buildSearchSpec($server, $type, $page, $search, $filters);

        return $spec === null || $this->cache()->peek($spec)['fresh'];
    }

    public function warmSearch(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): bool
    {
        $spec = $this->buildSearchSpec($server, $type, $page, $search, $filters);
        if ($spec === null) {
            return false;
        }

        $peeked = $this->cache()->peek($spec);
        if ($peeked['hit'] && $peeked['fresh']) {
            return false;
        }

        return $this->cache()->revalidate($spec, CacheProfile::Search);
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

        $params = [
            'gameId' => self::GAME_ID,
            'classId' => $classId,
            'gameVersion' => MinecraftVersionResolver::resolve($server),
            'index' => ($page - 1) * self::SEARCH_PAGE_SIZE,
            'pageSize' => self::SEARCH_PAGE_SIZE,
            'sortField' => match ($filters['sort'] ?? 'downloads') {
                'updated' => self::SORT_FIELD_LAST_UPDATED,
                'popularity' => self::SORT_FIELD_POPULARITY,
                default => self::SORT_FIELD_TOTAL_DOWNLOADS,
            },
            'sortOrder' => 'desc',
        ];

        if ($type === ProjectType::Mod) {
            $modLoaderType = $this->modLoaderTypeFor($server);
            if ($modLoaderType === null) {
                return null;
            }
            $params['modLoaderType'] = $modLoaderType;
        }

        if ($type === ProjectType::Datapack) {
            $params['categoryId'] = self::CATEGORY_ID_DATAPACK;
        } elseif (!empty($filters['category'])) {
            $params['categoryId'] = (int) $filters['category'];
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

            return $this->normalizeSearchResult([
                'hits' => collect($payload['data'] ?? [])
                    ->filter(fn ($mod) => is_array($mod))
                    ->filter(fn (array $mod) => $type !== ProjectType::ResourcePack || $this->isResourcePackProject($mod))
                    ->map(fn (array $mod) => $this->normalizeProject($mod, $type))
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

        $project = $this->cache()->swr(new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'project',
            arguments: ['project_id' => $projectId],
        ), CacheProfile::ProjectMetadata);

        return is_array($project) ? $project : null;
    }

    /** @return array{data: array<string, mixed>|null, pending: bool, retry_delayed: bool} */
    public function peekProject(string $projectId, bool $dispatchOnMiss = true): array
    {
        if (!$this->isConfigured()) {
            return ['data' => null, 'pending' => false, 'retry_delayed' => false];
        }

        $spec = new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'project',
            arguments: ['project_id' => $projectId],
        );

        if (!$dispatchOnMiss) {
            $peeked = $this->cache()->peek($spec);

            return [
                'data' => is_array($peeked['data']) ? $peeked['data'] : null,
                'pending' => !$peeked['hit'] && !$peeked['retry_delayed'],
                'retry_delayed' => $peeked['retry_delayed'],
            ];
        }

        $peeked = $this->cache()->swrDeferred($spec, CacheProfile::ProjectMetadata);

        return [
            'data' => is_array($peeked['data']) ? $peeked['data'] : null,
            'pending' => $peeked['pending'],
            'retry_delayed' => $peeked['retry_delayed'],
        ];
    }

    /** @return array<string, array{data: array<string, mixed>|null, pending: bool, retry_delayed: bool}> */
    public function peekProjects(array $projectIds): array
    {
        $projectIds = array_values(array_unique($projectIds));

        if (!$this->isConfigured()) {
            return array_fill_keys($projectIds, ['data' => null, 'pending' => false, 'retry_delayed' => false]);
        }

        $specs = [];
        foreach ($projectIds as $projectId) {
            $projectId = (string) $projectId;
            $specs[$projectId] = new SourceFetchSpec(
                sourceKey: $this->getKey()->value,
                operation: 'project',
                arguments: ['project_id' => $projectId],
            );
        }

        $results = [];
        foreach ($this->cache()->peekMany($specs) as $projectId => $peeked) {
            $results[$projectId] = [
                'data' => is_array($peeked['data']) ? $peeked['data'] : null,
                'pending' => !$peeked['hit'] && !$peeked['retry_delayed'],
                'retry_delayed' => $peeked['retry_delayed'],
            ];
        }

        return $results;
    }

    public function primeProjects(array $dataByProjectId): void
    {
        $entries = [];

        foreach ($dataByProjectId as $projectId => $data) {
            $entries[] = [
                'spec' => new SourceFetchSpec(
                    sourceKey: $this->getKey()->value,
                    operation: 'project',
                    arguments: ['project_id' => (string) $projectId],
                ),
                'data' => $data,
            ];
        }

        $this->cache()->primeMany($entries, CacheProfile::ProjectMetadata);
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

        $specs = [];

        foreach (array_values(array_unique($projectIds)) as $projectId) {
            $projectId = trim((string) $projectId);

            if ($projectId !== '') {
                $specs[] = new SourceFetchSpec(
                    sourceKey: $this->getKey()->value,
                    operation: 'project',
                    arguments: ['project_id' => $projectId],
                );
            }
        }

        $this->cache()->markRetryDelayedMany($specs, CacheProfile::ProjectMetadata);
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

        $modIds = array_values(array_unique(array_map('intval', $projectIds)));
        sort($modIds);

        $spec = new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'projects',
            arguments: ['project_ids' => $modIds],
        );
        $projects = $freshRequired
            ? $this->cache()->swrRequiredFresh($spec, CacheProfile::ProjectMetadata)
            : ($authoritative
                ? $this->cache()->swrRequired($spec, CacheProfile::ProjectMetadata)
                : $this->cache()->swr($spec, CacheProfile::ProjectMetadata));

        return is_array($projects) ? $projects : [];
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
        $params = $this->getVersionRequestParams($server, $type);

        if ($params === null) {
            return [];
        }

        $versions = $this->cache()->swr(new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'versions',
            arguments: [
                'project_id' => $projectId,
                'params' => $params,
                'project_type' => $type->value,
            ],
        ), CacheProfile::InstalledLatest);

        return is_array($versions) ? $versions : [];
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

        $project = $this->cache()->swr(new SourceFetchSpec(
            sourceKey: $this->getKey()->value,
            operation: 'resolve_identifier',
            arguments: ['identifier' => $identifier],
        ), CacheProfile::ProjectMetadata);

        return is_array($project) ? $project : null;
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
        ];
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

        return [
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
            'files' => [
                [
                    'primary' => true,
                    'filename' => $file['fileName'] ?? '',
                    // Null when the mod author disabled third-party download links.
                    'url' => $file['downloadUrl'] ?? null,
                    'hashes' => $hashes,
                ],
            ],
        ];
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

    protected function apiKey(): string
    {
        return (string) config('pelican-mod-manager.curseforge_api_key');
    }
}
