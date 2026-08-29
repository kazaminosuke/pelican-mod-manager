<?php

namespace Kazaminosuke\ModManager\Sources;

use App\Models\Server;
use Exception;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Contracts\BatchLatestVersionSourceInterface;
use Kazaminosuke\ModManager\Contracts\ProjectMetadataPeekManyInterface;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchAuthoritativeInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchHandlerInterface;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\PartialSourceFetchException;
use Kazaminosuke\ModManager\Support\CachedProjectMetadata;
use Kazaminosuke\ModManager\Support\CachedSearchOperations;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\CacheVersion;
use Kazaminosuke\ModManager\Support\CatalogFields;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Throwable;

class HangarSource implements BatchLatestVersionSourceInterface, ProjectMetadataPeekManyInterface, ProjectSourceInterface, SourceFetchAuthoritativeInterface, SourceFetchHandlerInterface
{
    protected const BASE_URL = 'https://hangar.papermc.io/api/v1';

    /** Hangar's version-listing endpoint caps `limit` at 25. */
    protected const PAGE_SIZE = 25;

    /** Must match ModManagerPage's catalog paginator page size. */
    protected const CATALOG_PAGE_SIZE = 20;

    /**
     * Hangar's hash lookup only tells you which *project* matched, not which
     * version/file - this bounds how many recent-versions pages we scan (per
     * matched project) to resolve the exact file. Older files outside this
     * window won't be found; that's an API limitation, not a shortcut.
     */
    protected const HASH_SCAN_MAX_PAGES = 4;

    /** Hangar has no bulk endpoint, so bound the concurrent fallbacks. */
    protected const LATEST_VERSION_POOL_SIZE = 4;

    private const OPERATION_HASH_MATCH = 'hash_match';

    private const OPERATION_LATEST = 'latest';

    private const OPERATION_PROJECT = 'project';

    private const OPERATION_SEARCH = 'search';

    private const OPERATION_VERSIONS = 'versions';

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
        return ProjectSourceKey::Hangar;
    }

    public function getLabel(): string
    {
        return 'Hangar';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsProjectType(ProjectType $type): bool
    {
        return $type === ProjectType::Plugin;
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
        return 'sha256';
    }

    public function fetchSourceData(SourceFetchSpec $spec, float $timeoutSeconds): mixed
    {
        if ($spec->sourceKey !== $this->getKey()->value) {
            throw new Exception("Invalid source key [{$spec->sourceKey}] for Hangar.");
        }

        return match ($spec->operation) {
            self::OPERATION_HASH_MATCH => $this->fetchHashMatch($spec, $timeoutSeconds),
            self::OPERATION_LATEST => $this->fetchLatestVersions($spec, $timeoutSeconds),
            self::OPERATION_PROJECT => $this->fetchProject($spec, $timeoutSeconds),
            self::OPERATION_SEARCH => $this->fetchSearch($spec, $timeoutSeconds),
            self::OPERATION_VERSIONS => $this->fetchVersions($spec, $timeoutSeconds),
            default => throw new Exception("Unsupported Hangar cache operation [{$spec->operation}]."),
        };
    }

    public function emptySourceData(SourceFetchSpec $spec): mixed
    {
        return match ($spec->operation) {
            self::OPERATION_HASH_MATCH, self::OPERATION_PROJECT => null,
            self::OPERATION_LATEST => $this->emptyLatestVersions($spec),
            self::OPERATION_SEARCH => ['hits' => [], 'total_hits' => 0],
            self::OPERATION_VERSIONS => [],
            default => [],
        };
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function search(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): array
    {
        return $this->cachedSearch->search($this->buildSearchSpec($server, $type, $page, $search, $filters));
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

    /** @param array<string, mixed> $filters */
    private function buildSearchSpec(Server $server, ProjectType $type, int $page, ?string $search, array $filters): ?SourceFetchSpec
    {
        if ($type !== ProjectType::Plugin) {
            return null;
        }

        $platform = strtoupper(trim((string) ($filters['platform'] ?? ''))) ?: $this->platformFor($server);

        if (!in_array($platform, ['PAPER', 'WATERFALL', 'VELOCITY'], true)) {
            return null;
        }

        $requestedVersion = trim((string) ($filters['version'] ?? ''));
        $params = [
            'platform' => $platform,
            'version' => preg_match('/^[0-9A-Za-z._+\-]{1,32}$/', $requestedVersion) === 1
                ? $requestedVersion
                : MinecraftVersionResolver::resolve($server),
            // The table paginator renders 20 records per page. Hangar accepts
            // that limit even though its version-listing endpoint permits up
            // to 25; using PAGE_SIZE here made its offset skip five records
            // per UI page and exposed empty pages before the displayed total.
            'limit' => self::CATALOG_PAGE_SIZE,
            'offset' => ($page - 1) * self::CATALOG_PAGE_SIZE,
            // Hangar's ProjectSortingStrategy enum (confirmed against its
            // official OpenAPI spec): each value already bakes in a fixed
            // direction (e.g. "downloads" is always most-downloaded-first),
            // same as Modrinth's index and CurseForge's sortField - no
            // separate ascending/descending option. "stars" is Hangar's own
            // community-rating signal, the closest match to the catalog
            // dropdown's "popularity" preset.
            'sort' => in_array(($filters['sort'] ?? null), ['stars', 'recent_downloads', 'downloads', 'updated', 'newest'], true)
                ? $filters['sort']
                : 'downloads',
        ];

        if ($search) {
            $params['query'] = $search;
        }
        if (array_key_exists((string) ($filters['category'] ?? ''), $this->catalogCategoryOptions())) {
            $params['category'] = $filters['category'];
        }
        if (array_key_exists((string) ($filters['tag'] ?? ''), $this->catalogTagOptions())) {
            $params['tag'] = $filters['tag'];
        }

        return $this->spec(self::OPERATION_SEARCH, ['params' => $params]);
    }

    /** @return array<string, string> */
    public function catalogPlatformOptions(): array
    {
        return [
            'PAPER' => 'Paper',
            'WATERFALL' => 'Waterfall',
            'VELOCITY' => 'Velocity',
        ];
    }

    /** @return array<string, string> */
    public function catalogTagOptions(): array
    {
        return [
            'ADDON' => 'Addon',
            'LIBRARY' => 'Library',
            'SUPPORTS_FOLIA' => 'Supports Folia',
        ];
    }

    /** @return array<string, string> */
    public function catalogCategoryOptions(): array
    {
        return [
            'admin_tools' => 'Admin Tools',
            'chat' => 'Chat',
            'dev_tools' => 'Developer Tools',
            'economy' => 'Economy',
            'gameplay' => 'Gameplay',
            'games' => 'Games',
            'protection' => 'Protection',
            'role_playing' => 'Role Playing',
            'world_management' => 'World Management',
            'misc' => 'Miscellaneous',
        ];
    }

    /** @return array<string, string> */
    public function catalogVersionOptions(string $platform): array
    {
        $platform = strtoupper($platform);
        if (!array_key_exists($platform, $this->catalogPlatformOptions())) {
            return [];
        }

        try {
            $groups = Cache::remember(
                'pelican-mod-manager:hangar-catalog-versions:'.strtolower($platform),
                now()->addDay(),
                fn (): array => $this->getJson(
                    '/platforms/'.$platform.'/versions',
                    [],
                    CacheProfile::Search->backgroundTimeoutSeconds(),
                ),
            );
        } catch (Throwable) {
            return [];
        }

        $options = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ([...(array) ($group['subVersions'] ?? []), $group['version'] ?? null] as $version) {
                $value = trim((string) $version);
                if ($value !== '') {
                    $options[$value] = $value;
                }
            }
        }

        return $options;
    }

    /** @return array<string, mixed>|null */
    public function getProject(string $projectId): ?array
    {
        return $this->getProjectUsingCache($projectId, authoritative: false);
    }

    /** @return array<string, mixed>|null */
    protected function getProjectUsingCache(string $projectId, bool $authoritative): ?array
    {
        return $this->cachedProjectMetadata->get($this->projectSpec($projectId), $authoritative);
    }

    /** @return array{data: array<string, mixed>|null, pending: bool, retry_delayed: bool} */
    public function peekProject(string $projectId, bool $dispatchOnMiss = true): array
    {
        return $this->cachedProjectMetadata->peek($this->projectSpec($projectId), $dispatchOnMiss);
    }

    /** @return array<string, array{data: array<string, mixed>|null, pending: bool, retry_delayed: bool}> */
    public function peekProjects(array $projectIds): array
    {
        return $this->cachedProjectMetadata->peekMany($projectIds, $this->projectSpec(...));
    }

    public function primeProjects(array $dataByProjectId): void
    {
        $this->cachedProjectMetadata->primeMany($dataByProjectId, $this->projectSpec(...));
    }

    /**
     * Hangar has no bulk project-lookup endpoint, so this loops getProject()
     * per id; each call already degrades gracefully on its own.
     *
     * @param array<int, string> $projectIds
     * @return array<string, mixed>
     */
    public function getProjectsByIds(array $projectIds): array
    {
        return $this->getProjectsByIdsUsingCache($projectIds, authoritative: false);
    }

    public function getProjectsByIdsAuthoritatively(array $projectIds): array
    {
        return $this->getProjectsByIdsUsingCache($projectIds, authoritative: true);
    }

    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed>
     */
    protected function getProjectsByIdsUsingCache(array $projectIds, bool $authoritative): array
    {
        return $this->cachedProjectMetadata->getMany(
            $projectIds,
            $this->projectSpec(...),
            $authoritative,
            fn (array $pendingIds): array => $this->fetchProjectsByIdsWithPool($pendingIds),
        );
    }

    /** @return array<int, mixed> */
    public function getVersions(string $projectId, Server $server, ProjectType $type): array
    {
        if ($type !== ProjectType::Plugin) {
            return [];
        }

        $platform = $this->platformFor($server);

        if ($platform === null) {
            return [];
        }

        $params = [
            'platform' => $platform,
            'platformVersion' => MinecraftVersionResolver::resolve($server),
            'limit' => self::PAGE_SIZE,
        ];

        $versions = $this->sourceCache->swr(
            $this->spec(self::OPERATION_VERSIONS, [
                'project_id' => $projectId,
                'platform' => $platform,
                'params' => $params,
            ]),
            CacheProfile::InstalledLatest,
        );

        return is_array($versions) ? $versions : [];
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function lookupLatestVersions(
        array $requests,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        $requests = array_values(array_filter(
            $requests,
            fn ($request): bool => $request instanceof LatestVersionLookupRequest,
        ));

        if ($requests === []) {
            return LatestVersionLookupResult::empty();
        }

        $platform = $this->platformFor($server);

        if ($type !== ProjectType::Plugin || $platform === null) {
            return new LatestVersionLookupResult(unresolvedKeys: array_map(
                fn (LatestVersionLookupRequest $request): string => $request->key(),
                $requests,
            ));
        }

        $params = [
            'platform' => $platform,
            'platformVersion' => MinecraftVersionResolver::resolve($server),
            'limit' => self::PAGE_SIZE,
        ];
        $requestsByProject = [];

        foreach ($requests as $request) {
            $requestsByProject[$request->projectId][] = $request;
        }

        $projectIds = array_keys($requestsByProject);
        sort($projectIds, SORT_STRING);
        $spec = $this->spec(self::OPERATION_LATEST, [
            'project_ids' => $projectIds,
            'platform' => $platform,
            'params' => $params,
        ]);
        $payload = $this->sourceCache->swr($spec, CacheProfile::InstalledLatest);

        return $this->distributeLatestPayload($requestsByProject, $payload);
    }

    /**
     * Non-blocking counterpart to lookupLatestVersions(). See
     * BatchLatestVersionSourceInterface::peekLatestVersions().
     *
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function peekLatestVersions(
        array $requests,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        $requests = array_values(array_filter(
            $requests,
            fn ($request): bool => $request instanceof LatestVersionLookupRequest,
        ));

        if ($requests === []) {
            return LatestVersionLookupResult::empty();
        }

        $platform = $this->platformFor($server);

        if ($type !== ProjectType::Plugin || $platform === null) {
            return new LatestVersionLookupResult(unresolvedKeys: array_map(
                fn (LatestVersionLookupRequest $request): string => $request->key(),
                $requests,
            ));
        }

        $params = [
            'platform' => $platform,
            'platformVersion' => MinecraftVersionResolver::resolve($server),
            'limit' => self::PAGE_SIZE,
        ];
        $requestsByProject = [];

        foreach ($requests as $request) {
            $requestsByProject[$request->projectId][] = $request;
        }

        $projectIds = array_keys($requestsByProject);
        sort($projectIds, SORT_STRING);
        $spec = $this->spec(self::OPERATION_LATEST, [
            'project_ids' => $projectIds,
            'platform' => $platform,
            'params' => $params,
        ]);
        $peeked = $this->sourceCache->swrDeferred($spec, CacheProfile::InstalledLatest);

        if ($peeked['pending']) {
            return new LatestVersionLookupResult(pendingKeys: array_map(
                fn (LatestVersionLookupRequest $request): string => $request->key(),
                $requests,
            ));
        }

        return $this->distributeLatestPayload($requestsByProject, $peeked['data']);
    }

    /**
     * @param array<string, array<int, LatestVersionLookupRequest>> $requestsByProject
     */
    private function distributeLatestPayload(array $requestsByProject, mixed $payload): LatestVersionLookupResult
    {
        $resolved = is_array($payload) && is_array($payload['versions'] ?? null)
            ? $payload['versions']
            : [];
        $unresolvedProjects = is_array($payload) && is_array($payload['unresolved'] ?? null)
            ? array_fill_keys($payload['unresolved'], true)
            : [];
        $failuresByProject = is_array($payload) && is_array($payload['failures'] ?? null)
            ? $payload['failures']
            : [];
        $versions = [];
        $unresolved = [];
        $failures = [];

        foreach ($requestsByProject as $projectId => $projectRequests) {
            foreach ($projectRequests as $request) {
                if (is_array($resolved[$projectId] ?? null)) {
                    $versions[$request->key()] = $resolved[$projectId];
                } elseif (is_string($failuresByProject[$projectId] ?? null) && $failuresByProject[$projectId] !== '') {
                    $failures[$request->key()] = $failuresByProject[$projectId];
                } elseif (isset($unresolvedProjects[$projectId]) || !isset($resolved[$projectId])) {
                    $unresolved[] = $request->key();
                }
            }
        }

        return new LatestVersionLookupResult(
            versionsByKey: $versions,
            unresolvedKeys: $unresolved,
            failuresByKey: $failures,
        );
    }

    /**
     * @param array<string, string> $hashesByFilename [filename => sha256hash]
     * @return array<string, mixed> [sha256hash => normalized version data]
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
        if (empty($hashesByFilename)) {
            return [];
        }

        $results = [];
        $generation = CacheVersion::hangarHash();

        foreach (array_unique(array_values($hashesByFilename)) as $hash) {
            $hash = strtolower((string) $hash);

            if (!preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                continue;
            }

            $spec = $this->spec(self::OPERATION_HASH_MATCH, [
                'generation' => $generation,
                'hash' => $hash,
            ]);
            $entry = $authoritative
                ? $this->sourceCache->swrRequired($spec, CacheProfile::HashMatch)
                : $this->sourceCache->swr($spec, CacheProfile::HashMatch);

            if (is_array($entry)) {
                $results[$hash] = $entry;
            }
        }

        return $results;
    }

    /** @return array<string, mixed>|null */
    public function resolveProjectByIdentifier(string $identifier): ?array
    {
        return $this->getProject($identifier);
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    protected function fetchSearch(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $params = $spec->arguments['params'] ?? null;
        if (!is_array($params)) {
            throw new Exception('Invalid Hangar search parameters.');
        }

        $debugTiming = (bool) config('pelican-mod-manager.debug_timing', false);
        $startedAt = $debugTiming ? microtime(true) : 0.0;
        $responseBytes = null;

        try {
            $response = $this->getJson('/projects', $params, $timeoutSeconds);
            if ($debugTiming) {
                $responseBytes = strlen(json_encode($response) ?: '');
            }

            if (!is_array($response['result'] ?? null) || !is_array($response['pagination'] ?? null)) {
                throw new Exception('Invalid Hangar search response.');
            }

            $hits = collect($response['result'])
                ->filter(fn ($project) => is_array($project))
                ->map(fn (array $project) => $this->normalizeProject($project))
                ->values()
                ->all();

            return [
                'hits' => $hits,
                'total_hits' => (int) ($response['pagination']['count'] ?? count($hits)),
            ];
        } finally {
            if ($debugTiming) {
                logger()->debug('Catalog search API timing', [
                    'source' => 'hangar',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'response_bytes' => $responseBytes,
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    protected function fetchProject(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $projectId = $spec->arguments['project_id'] ?? null;
        if (!is_string($projectId) || $projectId === '') {
            throw new Exception('Invalid Hangar project identifier.');
        }

        $fetched = $this->fetchProjectsByIdsWithPool([$projectId], $timeoutSeconds);
        $project = $fetched[$projectId] ?? null;

        if (!is_array($project)) {
            throw new Exception("Hangar project [$projectId] was not found.");
        }

        return $project;
    }

    /**
     * Hangar has no bulk project endpoint, so bound the concurrent fallback
     * the same way latest-version lookup already does.
     *
     * @param array<int, string> $projectIds
     * @return array<string, array<string, mixed>>
     */
    protected function fetchProjectsByIdsWithPool(array $projectIds, ?float $timeoutSeconds = null): array
    {
        $projectIds = array_values(array_filter(
            $projectIds,
            static fn (mixed $projectId): bool => is_string($projectId) && $projectId !== '',
        ));

        if ($projectIds === []) {
            return [];
        }

        $timeoutSeconds ??= CacheProfile::ProjectMetadata->backgroundTimeoutSeconds();
        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $resolved = [];

        foreach (array_chunk($projectIds, self::LATEST_VERSION_POOL_SIZE) as $chunk) {
            try {
                $remaining = $this->remainingTimeout($deadline);
            } catch (Throwable $exception) {
                report($exception);

                break;
            }

            $aliases = [];

            try {
                $headers = $this->authHeaders();
                $responses = Http::pool(function (Pool $pool) use ($chunk, $remaining, $headers, &$aliases) {
                    $poolRequests = [];

                    foreach (array_values($chunk) as $index => $projectId) {
                        $alias = "hangar_project_$index";
                        $aliases[$alias] = $projectId;
                        $poolRequests[] = $pool->as($alias)
                            ->asJson()
                            ->withHeaders($headers)
                            ->timeout($remaining)
                            ->connectTimeout(min(1.0, $remaining))
                            ->get(self::BASE_URL."/projects/$projectId");
                    }

                    return $poolRequests;
                });
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($aliases as $alias => $projectId) {
                try {
                    $response = $responses[$alias] ?? null;

                    if (!$response instanceof Response || !$response->successful()) {
                        throw new Exception("Hangar project [$projectId] was not found.");
                    }

                    $payload = $response->json();

                    if (!is_array($payload) || !isset($payload['id'])) {
                        throw new Exception("Hangar project [$projectId] was not found.");
                    }

                    $resolved[$projectId] = $this->normalizeProject($payload);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $resolved;
    }

    /** @return array<int, array<string, mixed>> */
    protected function fetchVersions(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $projectId = $spec->arguments['project_id'] ?? null;
        $platform = $spec->arguments['platform'] ?? null;
        $params = $spec->arguments['params'] ?? null;

        if (!is_string($projectId) || $projectId === '' || !is_string($platform) || $platform === '' || !is_array($params)) {
            throw new Exception('Invalid Hangar versions parameters.');
        }

        $response = $this->getJson("/projects/$projectId/versions", $params, $timeoutSeconds);
        if (!is_array($response['result'] ?? null)) {
            throw new Exception("Invalid Hangar versions response for project [$projectId].");
        }

        return collect($response['result'])
            ->filter(fn ($version) => is_array($version))
            ->map(fn (array $version) => $this->normalizeVersion($version, $platform))
            ->filter(fn ($version) => $version !== null)
            ->values()
            ->all();
    }

    /**
     * @return array{versions: array<string, array<string, mixed>>, unresolved: array<int, string>, failures?: array<string, string>}
     */
    protected function fetchLatestVersions(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $projectIds = $spec->arguments['project_ids'] ?? null;
        $platform = $spec->arguments['platform'] ?? null;
        $params = $spec->arguments['params'] ?? null;

        if (!is_array($projectIds) || !is_string($platform) || $platform === '' || !is_array($params)) {
            throw new Exception('Invalid Hangar latest-version parameters.');
        }

        $projectIds = array_values(array_filter(
            $projectIds,
            fn ($projectId): bool => is_string($projectId) && $projectId !== '',
        ));
        $startedAt = (bool) config('pelican-mod-manager.debug_timing', false)
            ? microtime(true)
            : 0.0;
        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $resolved = [];
        $unresolved = [];
        $failures = [];
        $requestCount = 0;

        foreach (array_chunk($projectIds, self::LATEST_VERSION_POOL_SIZE) as $chunk) {
            [$chunkResolved, $chunkFailures] = $this->fetchLatestVersionChunk(
                $chunk,
                $params,
                $platform,
                $this->remainingTimeout($deadline),
            );
            $requestCount += count($chunk);

            $failures = array_merge($failures, $chunkFailures);

            foreach ($chunk as $projectId) {
                if (isset($chunkResolved[$projectId])) {
                    $resolved[$projectId] = $chunkResolved[$projectId];
                } else {
                    $unresolved[] = $projectId;
                }
            }
        }

        if ($projectIds !== []) {
            $this->logLatestVersionsTiming(
                $startedAt,
                count($projectIds),
                count($resolved),
                $requestCount,
                0,
                count($failures),
            );
        }

        $result = ['versions' => $resolved, 'unresolved' => $unresolved];

        if ($failures !== []) {
            $result['failures'] = $failures;

            throw new PartialSourceFetchException(
                'Hangar latest-version batch completed with partial failures: '.implode('; ', $failures),
                $result,
            );
        }

        return $result;
    }

    /** @return array{versions: array<never, never>, unresolved: array<int, string>} */
    protected function emptyLatestVersions(SourceFetchSpec $spec): array
    {
        $projectIds = $spec->arguments['project_ids'] ?? [];

        return [
            'versions' => [],
            'unresolved' => is_array($projectIds)
                ? array_values(array_filter($projectIds, fn ($projectId): bool => is_string($projectId) && $projectId !== ''))
                : [],
        ];
    }

    /** @return array<string, mixed> */
    protected function fetchHashMatch(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $hash = $spec->arguments['hash'] ?? null;
        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new Exception('Invalid Hangar SHA-256 hash.');
        }

        // The generation argument intentionally participates in SourceFetchSpec's
        // cache key. CacheVersion::bumpHangarHash() therefore invalidates even
        // indefinitely retained stale matches without deleting old keys.
        if (!is_int($spec->arguments['generation'] ?? null)) {
            throw new Exception('Invalid Hangar hash-cache generation.');
        }

        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $project = $this->getJson("/versions/hash/$hash", [], $this->remainingTimeout($deadline));
        if (!isset($project['id'])) {
            throw new Exception("No Hangar project matched hash [$hash].");
        }

        $entry = $this->fetchVersionEntryByHash((string) $project['id'], $hash, $deadline);
        if ($entry === null) {
            // Do not persist a negative match indefinitely: the matching
            // release may be published after this scan.
            throw new Exception("No Hangar version matched hash [$hash].");
        }

        return $entry;
    }

    protected function fetchVersionEntryByHash(string $projectId, string $hash, float $deadline): ?array
    {
        $entry = null;

        for ($page = 0; $page < self::HASH_SCAN_MAX_PAGES; $page++) {
            $response = $this->getJson("/projects/$projectId/versions", [
                'limit' => self::PAGE_SIZE,
                'offset' => $page * self::PAGE_SIZE,
            ], $this->remainingTimeout($deadline));

            $versions = $response['result'] ?? [];

            if (!is_array($versions) || empty($versions)) {
                break;
            }

            foreach ($versions as $version) {
                if (!is_array($version)) {
                    continue;
                }

                foreach (($version['downloads'] ?? []) as $platform => $download) {
                    $fileHash = strtolower((string) ($download['fileInfo']['sha256Hash'] ?? ''));

                    if ($fileHash !== '' && $fileHash === $hash) {
                        $entry = $this->normalizeVersion($version, $platform);

                        if ($entry !== null) {
                            $entry['project_id'] = $projectId;
                        }

                        break 3;
                    }
                }
            }

            if (count($versions) < self::PAGE_SIZE) {
                break;
            }
        }

        return $entry;
    }

    /**
     * Maps the source-agnostic MinecraftLoader to a Hangar Platform. Hangar only
     * covers the PaperMC ecosystem (PAPER/WATERFALL/VELOCITY); Paper-API-compatible
     * forks (Purpur, Folia) are treated as PAPER. Spigot/Bukkit/Bungeecord and
     * other loaders have no Hangar equivalent.
     */
    protected function platformFor(Server $server): ?string
    {
        return match (MinecraftLoader::fromServer($server)) {
            MinecraftLoader::Paper, MinecraftLoader::Purpur, MinecraftLoader::Folia => 'PAPER',
            MinecraftLoader::Waterfall => 'WATERFALL',
            MinecraftLoader::Velocity => 'VELOCITY',
            default => null,
        };
    }

    /** @param array<string, mixed> $project */
    protected function normalizeProject(array $project): array
    {
        $namespace = $project['namespace'] ?? [];

        return [
            'project_id' => (string) ($project['id'] ?? ''),
            'slug' => $namespace['slug'] ?? '',
            'title' => $project['name'] ?? '',
            'description' => CatalogFields::description($project['description'] ?? ''),
            'icon_url' => $project['avatarUrl'] ?? null,
            'author' => $namespace['owner'] ?? null,
            'downloads' => (int) ($project['stats']['downloads'] ?? 0),
            'date_modified' => $project['lastUpdated'] ?? null,
            'project_type' => ProjectType::Plugin->value,
        ];
    }

    /**
     * @param array<string, mixed> $version
     * @return array<string, mixed>|null  null when this version has no file for the given platform
     */
    protected function normalizeVersion(array $version, string $platform): ?array
    {
        $download = $version['downloads'][$platform] ?? null;

        if (!is_array($download)) {
            return null;
        }

        $fileInfo = $download['fileInfo'] ?? [];
        $url = $download['downloadUrl'] ?? $download['externalUrl'] ?? null;

        // Channels are free-text and admin-configurable per project (e.g. "Release",
        // "Beta", "Snapshot", "Dev Build"), so this is a best-effort heuristic rather
        // than an exact enum match like Modrinth/CurseForge have.
        $channelName = strtolower($version['channel']['name'] ?? '');
        $versionType = match (true) {
            str_contains($channelName, 'beta') => 'beta',
            str_contains($channelName, 'alpha'), str_contains($channelName, 'snapshot'), str_contains($channelName, 'dev') => 'alpha',
            default => 'release',
        };

        $hashes = [];
        if (!empty($fileInfo['sha256Hash'])) {
            $hashes['sha256'] = $fileInfo['sha256Hash'];
        }

        return [
            'id' => (string) ($version['id'] ?? ''),
            'version_number' => $version['name'] ?? '',
            'version_type' => $versionType,
            'downloads' => (int) ($version['stats']['totalDownloads'] ?? 0),
            'date_published' => $version['createdAt'] ?? null,
            'changelog' => $version['description'] ?? null,
            'featured' => false,
            'files' => [
                [
                    'primary' => true,
                    'filename' => $fileInfo['name'] ?? '',
                    'url' => $url,
                    'hashes' => $hashes,
                ],
            ],
        ];
    }

    /**
     * @param array<int, string> $projectIds
     * @param array<string, int|string> $params
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>}
     */
    protected function fetchLatestVersionChunk(
        array $projectIds,
        array $params,
        string $platform,
        float $timeoutSeconds,
    ): array {
        $aliases = [];

        try {
            $headers = $this->authHeaders();
            $responses = Http::pool(function (Pool $pool) use ($projectIds, $params, $timeoutSeconds, $headers, &$aliases) {
                $poolRequests = [];

                foreach ($projectIds as $index => $projectId) {
                    $alias = "hangar_$index";
                    $aliases[$alias] = $projectId;
                    $poolRequests[] = $pool->as($alias)
                        ->asJson()
                        ->withHeaders($headers)
                        ->timeout($timeoutSeconds)
                        ->connectTimeout(min(1.0, $timeoutSeconds))
                        ->get(self::BASE_URL."/projects/$projectId/versions", $params);
                }

                return $poolRequests;
            });
        } catch (Throwable $exception) {
            report($exception);

            return [[], array_fill_keys($projectIds, $exception->getMessage())];
        }

        $resolved = [];
        $failures = [];

        foreach ($aliases as $alias => $projectId) {
            try {
                $response = $responses[$alias] ?? null;

                if (!$response instanceof Response || !$response->successful()) {
                    throw new Exception("Hangar versions lookup failed for project $projectId");
                }

                $payload = $response->json();

                if (!is_array($payload)) {
                    throw new Exception("Invalid Hangar versions response for project $projectId");
                }

                $version = $this->latestNormalizedVersion($payload, $platform);
                if ($version !== null) {
                    $resolved[$projectId] = $version;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failures[$projectId] = $exception->getMessage();
            }
        }

        return [$resolved, $failures];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>|null
     */
    protected function latestNormalizedVersion(array $response, string $platform): ?array
    {
        foreach ($response['result'] ?? [] as $version) {
            if (!is_array($version)) {
                continue;
            }

            $normalized = $this->normalizeVersion($version, $platform);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    protected function logLatestVersionsTiming(
        float $startedAt,
        int $requestedProjectCount,
        int $returnedProjectCount,
        int $requestCount,
        int $cacheHitCount,
        int $failureCount,
    ): void {
        if (!(bool) config('pelican-mod-manager.debug_timing', false)) {
            return;
        }

        logger()->info('Mod manager timing', [
            'stage' => 'hangar_versions_parallel_request',
            'request_id' => request()->attributes->get('mmr_timing_request_id'),
            'endpoint' => '/projects/{project}/versions',
            'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
            'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'requested_project_count' => $requestedProjectCount,
            'returned_project_count' => $returnedProjectCount,
            'request_count' => $requestCount,
            'pool_size' => self::LATEST_VERSION_POOL_SIZE,
            'cache_hit_count' => $cacheHitCount,
            'failure_count' => $failureCount,
        ]);
    }

    protected function getModManagerTimingElapsedMs(?float $timestamp = null): ?int
    {
        if (!(bool) config('pelican-mod-manager.debug_timing', false)) {
            return null;
        }

        $requestStartedAt = request()->attributes->get('mmr_timing_started_at');

        if (!is_float($requestStartedAt)) {
            return null;
        }

        return (int) round((($timestamp ?? microtime(true)) - $requestStartedAt) * 1000);
    }

    /** @param array<string, mixed> $query */
    protected function getJson(string $path, array $query, float $timeoutSeconds): array
    {
        $timeoutSeconds = max(0.1, $timeoutSeconds);
        $response = Http::asJson()
            ->withHeaders($this->authHeaders())
            ->timeout($timeoutSeconds)
            ->connectTimeout(min(1.0, $timeoutSeconds))
            ->throw()
            ->get(self::BASE_URL.$path, $query)
            ->json();

        if (!is_array($response)) {
            throw new Exception("Invalid Hangar response for [$path].");
        }

        return $response;
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        $apiKey = trim((string) config('pelican-mod-manager.hangar_api_key', ''));
        if ($apiKey === '') {
            return [];
        }

        $cacheKey = 'pelican-mod-manager:hangar-jwt:'.hash('sha256', $apiKey);
        $token = Cache::get($cacheKey);

        if (!is_string($token) || $token === '') {
            try {
                $response = Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(1)
                    ->withOptions(['query' => ['apiKey' => $apiKey]])
                    ->post(self::BASE_URL.'/authenticate')
                    ->throw()
                    ->json();
            } catch (Throwable) {
                // The official endpoint requires the key in the query string.
                // Never retain its request exception, whose URL may contain it.
                throw new Exception('Hangar API authentication failed.');
            }

            $token = is_array($response) ? ($response['token'] ?? null) : null;
            if (!is_string($token) || $token === '') {
                throw new Exception('Hangar API authentication failed.');
            }

            $expiresInMs = is_array($response) ? (int) ($response['expiresIn'] ?? 0) : 0;
            Cache::put($cacheKey, $token, max(60, intdiv($expiresInMs, 1000) - 60));
        }

        return ['Authorization' => 'HangarAuth '.$token];
    }

    protected function remainingTimeout(float $deadline): float
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0.0) {
            throw new Exception('Hangar source fetch exceeded its time budget.');
        }

        return max(0.1, $remaining);
    }

    /** @param array<int|string, mixed> $arguments */
    protected function spec(string $operation, array $arguments = []): SourceFetchSpec
    {
        return new SourceFetchSpec($this->getKey()->value, $operation, $arguments);
    }

    private function projectSpec(string $projectId): SourceFetchSpec
    {
        return $this->spec(self::OPERATION_PROJECT, ['project_id' => $projectId]);
    }
}
