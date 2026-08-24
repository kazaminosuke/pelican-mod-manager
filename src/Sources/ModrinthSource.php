<?php

namespace Kazaminosuke\ModManager\Sources;

use App\Models\Server;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Kazaminosuke\ModManager\Contracts\AuthoritativeBatchProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\BatchLatestVersionSourceInterface;
use Kazaminosuke\ModManager\Contracts\ProjectMetadataPeekManyInterface;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchAuthoritativeInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchHandlerInterface;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\CatalogFields;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Kazaminosuke\ModManager\Support\UpstreamHttp;

class ModrinthSource implements AuthoritativeBatchProjectSourceInterface, BatchLatestVersionSourceInterface, ProjectMetadataPeekManyInterface, ProjectSourceInterface, SourceFetchAuthoritativeInterface, SourceFetchHandlerInterface
{
    protected const BASE_URL = 'https://api.modrinth.com/v2';

    private const OPERATION_HASH_MATCH = 'hash_match';

    private const OPERATION_LATEST = 'latest';

    private const OPERATION_PROJECT = 'project';

    private const OPERATION_PROJECTS = 'projects';

    private const OPERATION_SEARCH = 'search';

    private const OPERATION_TEAM = 'team';

    private const OPERATION_USER = 'user';

    private const OPERATION_VERSIONS = 'versions';

    public function __construct(
        private readonly SourceCache $sourceCache,
    ) {}

    public function getKey(): ProjectSourceKey
    {
        return ProjectSourceKey::Modrinth;
    }

    public function getLabel(): string
    {
        return 'Modrinth';
    }

    public function requiresApiKey(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsProjectType(ProjectType $type): bool
    {
        return true;
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
        return 'sha512';
    }

    public function supportsDirectIdentifier(): bool
    {
        return true;
    }

    public function fetchSourceData(SourceFetchSpec $spec, float $timeoutSeconds): mixed
    {
        if ($spec->sourceKey !== $this->getKey()->value) {
            throw new Exception("Invalid source key [{$spec->sourceKey}] for Modrinth.");
        }

        return match ($spec->operation) {
            self::OPERATION_HASH_MATCH => $this->requestVersionsByHash($spec, $timeoutSeconds),
            self::OPERATION_LATEST => $this->requestLatestVersions($spec, $timeoutSeconds),
            self::OPERATION_PROJECT => $this->requestProject($spec, $timeoutSeconds),
            self::OPERATION_PROJECTS => $this->requestProjects($spec, $timeoutSeconds),
            self::OPERATION_SEARCH => $this->requestSearch($spec, $timeoutSeconds),
            self::OPERATION_TEAM => $this->requestTeamPrimaryUsername($spec, $timeoutSeconds),
            self::OPERATION_USER => $this->requestUsername($spec, $timeoutSeconds),
            self::OPERATION_VERSIONS => $this->requestVersions($spec, $timeoutSeconds),
            default => throw new Exception("Unsupported Modrinth cache operation [{$spec->operation}]."),
        };
    }

    public function emptySourceData(SourceFetchSpec $spec): mixed
    {
        return match ($spec->operation) {
            self::OPERATION_LATEST => $this->emptyLatestVersionResult($spec),
            self::OPERATION_PROJECT, self::OPERATION_TEAM, self::OPERATION_USER => null,
            self::OPERATION_SEARCH => ['hits' => [], 'total_hits' => 0],
            self::OPERATION_HASH_MATCH, self::OPERATION_PROJECTS, self::OPERATION_VERSIONS => [],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{hits: array<int, array<string, mixed>>, total_hits: int}
     */
    public function search(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): array
    {
        $spec = $this->buildSearchSpec($server, $type, $page, $search, $filters);

        if ($spec === null) {
            return ['hits' => [], 'total_hits' => 0];
        }

        $result = $this->sourceCache->swr($spec, CacheProfile::Search);

        return is_array($result) ? $result : ['hits' => [], 'total_hits' => 0];
    }

    public function hasCachedSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool
    {
        $spec = $this->buildSearchSpec($server, $type, $page, $search, $filters);

        return $spec === null || $this->sourceCache->peek($spec)['hit'];
    }

    public function hasFreshCachedSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool
    {
        $spec = $this->buildSearchSpec($server, $type, $page, $search, $filters);

        return $spec === null || $this->sourceCache->peek($spec)['fresh'];
    }

    public function warmSearch(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): bool
    {
        $spec = $this->buildSearchSpec($server, $type, $page, $search, $filters);
        if ($spec === null) {
            return false;
        }

        $peeked = $this->sourceCache->peek($spec);
        if ($peeked['hit'] && $peeked['fresh']) {
            return false;
        }

        return $this->sourceCache->revalidate($spec, CacheProfile::Search);
    }

    /** @param array<string, mixed> $filters */
    private function buildSearchSpec(Server $server, ProjectType $type, int $page, ?string $search, array $filters): ?SourceFetchSpec
    {
        $minecraftLoader = $type->getLoaderSlug($server);
        $projectType = $type->value;
        $minecraftVersion = MinecraftVersionResolver::resolve($server);

        if (in_array($type, [ProjectType::Datapack, ProjectType::ResourcePack], true)) {
            $facetGroups = [["versions:$minecraftVersion"], ["project_type:{$projectType}"]];
        } else {
            if (!$minecraftLoader) {
                return null;
            }

            $facetGroups = [["categories:$minecraftLoader"], ["versions:$minecraftVersion"], ["project_type:{$projectType}"]];
        }

        if (!empty($filters['category'])) {
            $facetGroups[] = ['categories:'.$filters['category']];
        }
        if (!empty($filters['environment'])) {
            $facetGroups[] = $filters['environment'] === 'server'
                ? ['server_side:required', 'server_side:optional']
                : ['server_side:unsupported'];
        }

        $data = [
            'offset' => ($page - 1) * 20,
            'limit' => 20,
            'facets' => json_encode($facetGroups),
            'index' => match ($filters['sort'] ?? 'downloads') {
                'updated' => 'updated',
                'popularity' => 'relevance',
                default => 'downloads',
            },
        ];

        if ($search) {
            $data['query'] = $search;
        }

        return $this->spec(self::OPERATION_SEARCH, ['query' => $data]);
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    protected function normalizeSearchProject(array $project): array
    {
        return [
            'project_id' => (string) ($project['project_id'] ?? $project['id'] ?? ''),
            'slug' => $project['slug'] ?? '',
            'title' => $project['title'] ?? '',
            'description' => CatalogFields::description($project['description'] ?? ''),
            'icon_url' => $project['icon_url'] ?? null,
            'author' => $project['author'] ?? null,
            'downloads' => (int) ($project['downloads'] ?? 0),
            'date_modified' => $project['date_modified'] ?? $project['updated'] ?? null,
            'project_type' => $project['project_type'] ?? '',
        ];
    }

    /** @return array<int, mixed> */
    public function getVersions(string $projectId, Server $server, ProjectType $type): array
    {
        $minecraftLoader = $type->getLoaderSlug($server);

        if (!$minecraftLoader && $type !== ProjectType::ResourcePack) {
            return [];
        }

        $minecraftVersion = MinecraftVersionResolver::resolve($server);
        $arguments = [
            'project_id' => $projectId,
            'game_version' => $minecraftVersion,
        ];
        if ($minecraftLoader !== null) {
            $arguments['loader'] = $minecraftLoader;
        }

        $versions = $this->sourceCache->swr(
            $this->spec(self::OPERATION_VERSIONS, $arguments),
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
        $debugTiming = (bool) config('pelican-mod-manager.debug_timing', false);
        $startedAt = $debugTiming ? microtime(true) : 0.0;
        $validRequests = array_values(array_filter(
            $requests,
            fn ($request) => $request instanceof LatestVersionLookupRequest,
        ));

        if ($validRequests === []) {
            return LatestVersionLookupResult::empty();
        }

        $minecraftLoader = $type->getLoaderSlug($server);
        if (!$minecraftLoader && $type !== ProjectType::ResourcePack) {
            return LatestVersionLookupResult::failed($validRequests, 'No compatible Modrinth loader is configured.');
        }

        $minecraftVersion = MinecraftVersionResolver::resolve($server);
        [$hashesByKey, $unresolvedRequests] = $this->prepareLatestHashRequests($validRequests);

        if ($hashesByKey === []) {
            $result = new LatestVersionLookupResult(unresolvedKeys: $unresolvedRequests);
        } else {
            $spec = $this->spec(self::OPERATION_LATEST, [
                'hashes_by_key' => $hashesByKey,
                'game_version' => $minecraftVersion,
                ...($minecraftLoader !== null ? ['loader' => $minecraftLoader] : []),
            ]);
            $cachedResult = $this->sourceCache->swr($spec, CacheProfile::InstalledLatest);
            $result = $cachedResult instanceof LatestVersionLookupResult
                ? $cachedResult
                : $this->emptyLatestVersionResult($spec);

            if ($unresolvedRequests !== []) {
                $result = $result->merge(new LatestVersionLookupResult(unresolvedKeys: $unresolvedRequests));
            }
        }

        if ($debugTiming) {
            logger()->info('Mod manager timing', [
                'stage' => 'modrinth_latest_lookup_batch',
                'request_id' => request()->attributes->get('mmr_timing_request_id'),
                'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'requested_project_count' => count($validRequests),
                'resolved_project_count' => count($result->versions()),
                'unresolved_project_count' => count($result->unresolvedKeys()),
                'failed_project_count' => count($result->failures()),
            ]);
        }

        return $result;
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

        if ($validRequests === []) {
            return LatestVersionLookupResult::empty();
        }

        $minecraftLoader = $type->getLoaderSlug($server);
        if (!$minecraftLoader && $type !== ProjectType::ResourcePack) {
            return LatestVersionLookupResult::failed($validRequests, 'No compatible Modrinth loader is configured.');
        }

        $minecraftVersion = MinecraftVersionResolver::resolve($server);
        [$hashesByKey, $unresolvedRequests] = $this->prepareLatestHashRequests($validRequests);

        if ($hashesByKey === []) {
            return new LatestVersionLookupResult(unresolvedKeys: $unresolvedRequests);
        }

        $spec = $this->spec(self::OPERATION_LATEST, [
            'hashes_by_key' => $hashesByKey,
            'game_version' => $minecraftVersion,
            ...($minecraftLoader !== null ? ['loader' => $minecraftLoader] : []),
        ]);
        $peeked = $this->sourceCache->swrDeferred($spec, CacheProfile::InstalledLatest);

        $result = $peeked['pending']
            ? new LatestVersionLookupResult(pendingKeys: array_keys($hashesByKey))
            : ($peeked['data'] instanceof LatestVersionLookupResult ? $peeked['data'] : $this->emptyLatestVersionResult($spec));

        if ($unresolvedRequests !== []) {
            $result = $result->merge(new LatestVersionLookupResult(unresolvedKeys: $unresolvedRequests));
        }

        return $result;
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $validRequests
     * @return array{0: array<string, string>, 1: array<int, string>} [hashesByKey, unresolvedRequestKeys]
     */
    private function prepareLatestHashRequests(array $validRequests): array
    {
        $hashesByKey = [];
        $unresolvedRequests = [];

        foreach ($validRequests as $request) {
            $sha512 = $request->hash('sha512');

            if ($sha512 === null) {
                $unresolvedRequests[] = $request->key();

                continue;
            }

            $hashesByKey[$request->key()] = $sha512;
        }

        ksort($hashesByKey);

        return [$hashesByKey, $unresolvedRequests];
    }

    /**
     * @param array<string, string> $hashesByFilename [filename => sha512hash]
     * @return array<string, mixed> [sha512hash => versionData]
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
    private function findVersionsByHashUsingCache(array $hashesByFilename, bool $authoritative): array
    {
        if (empty($hashesByFilename)) {
            return [];
        }

        $hashes = array_values(array_unique($hashesByFilename));
        sort($hashes);
        $spec = $this->spec(self::OPERATION_HASH_MATCH, ['hashes' => $hashes]);
        $result = $authoritative
            ? $this->sourceCache->swrRequired($spec, CacheProfile::HashMatch)
            : $this->sourceCache->swr($spec, CacheProfile::HashMatch);

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed> [projectId => projectData]
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
        $specs = [];

        foreach (array_values(array_unique($projectIds)) as $projectId) {
            $projectId = trim((string) $projectId);

            if ($projectId !== '') {
                $specs[] = $this->spec(self::OPERATION_PROJECT, ['project_id' => $projectId]);
            }
        }

        $this->sourceCache->markRetryDelayedMany($specs, CacheProfile::ProjectMetadata);
    }

    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed>
     */
    private function getProjectsByIdsUsingCache(array $projectIds, bool $authoritative, bool $freshRequired = false): array
    {
        if (empty($projectIds)) {
            return [];
        }

        $projectIds = array_values(array_unique($projectIds));
        sort($projectIds);
        $spec = $this->spec(self::OPERATION_PROJECTS, ['project_ids' => $projectIds]);
        $projects = $freshRequired
            ? $this->sourceCache->swrRequiredFresh($spec, CacheProfile::ProjectMetadata)
            : ($authoritative
                ? $this->sourceCache->swrRequired($spec, CacheProfile::ProjectMetadata)
                : $this->sourceCache->swr($spec, CacheProfile::ProjectMetadata));

        return is_array($projects) ? $projects : [];
    }

    /** @return array<string, mixed>|null */
    public function getProject(string $projectId): ?array
    {
        $project = $this->sourceCache->swr(
            $this->spec(self::OPERATION_PROJECT, ['project_id' => $projectId]),
            CacheProfile::ProjectMetadata,
        );

        return is_array($project) ? $project : null;
    }

    /** @return array{data: array<string, mixed>|null, pending: bool, retry_delayed: bool} */
    public function peekProject(string $projectId, bool $dispatchOnMiss = true): array
    {
        $spec = $this->spec(self::OPERATION_PROJECT, ['project_id' => $projectId]);

        if (!$dispatchOnMiss) {
            $peeked = $this->sourceCache->peek($spec);

            return [
                'data' => is_array($peeked['data']) ? $peeked['data'] : null,
                'pending' => !$peeked['hit'] && !$peeked['retry_delayed'],
                'retry_delayed' => $peeked['retry_delayed'],
            ];
        }

        $peeked = $this->sourceCache->swrDeferred($spec, CacheProfile::ProjectMetadata);

        return [
            'data' => is_array($peeked['data']) ? $peeked['data'] : null,
            'pending' => $peeked['pending'],
            'retry_delayed' => $peeked['retry_delayed'],
        ];
    }

    /** @return array<string, array{data: array<string, mixed>|null, pending: bool, retry_delayed: bool}> */
    public function peekProjects(array $projectIds): array
    {
        $specs = [];
        foreach (array_values(array_unique($projectIds)) as $projectId) {
            $projectId = (string) $projectId;
            $specs[$projectId] = $this->spec(self::OPERATION_PROJECT, ['project_id' => $projectId]);
        }

        $results = [];
        foreach ($this->sourceCache->peekMany($specs) as $projectId => $peeked) {
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
                'spec' => $this->spec(self::OPERATION_PROJECT, ['project_id' => (string) $projectId]),
                'data' => $data,
            ];
        }

        $this->sourceCache->primeMany($entries, CacheProfile::ProjectMetadata);
    }

    /** @return array<string, mixed>|null */
    public function resolveProjectByIdentifier(string $identifier): ?array
    {
        return $this->getProject($identifier);
    }

    /**
     * @param array<string, mixed>|null $project
     * @param array<string, mixed> $versionData
     */
    public function resolveAuthor(?array $project, array $versionData): ?string
    {
        if (is_string($project['author'] ?? null) && $project['author'] !== '') {
            return $project['author'];
        }

        if (is_string($project['team'] ?? null) && $project['team'] !== '') {
            $teamUsername = $this->fetchTeamPrimaryUsername($project['team']);
            if ($teamUsername !== null) {
                return $teamUsername;
            }
        }

        if (is_string($versionData['author_id'] ?? null) && $versionData['author_id'] !== '') {
            return $this->fetchUsernameByUserId($versionData['author_id']);
        }

        return null;
    }

    protected function fetchTeamPrimaryUsername(string $teamId): ?string
    {
        $username = $this->sourceCache->swr(
            $this->spec(self::OPERATION_TEAM, ['team_id' => $teamId]),
            CacheProfile::Identity,
        );

        return is_string($username) && $username !== '' ? $username : null;
    }

    protected function fetchUsernameByUserId(string $userId): ?string
    {
        $username = $this->sourceCache->swr(
            $this->spec(self::OPERATION_USER, ['user_id' => $userId]),
            CacheProfile::Identity,
        );

        return is_string($username) && $username !== '' ? $username : null;
    }

    /**
     * @return array{hits: array<int, array<string, mixed>>, total_hits: int}
     *
     * @throws Exception
     */
    private function requestSearch(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $query = $spec->arguments['query'] ?? null;
        if (!is_array($query)) {
            throw new Exception('Invalid Modrinth search arguments.');
        }

        $debugTiming = (bool) config('pelican-mod-manager.debug_timing', false);
        $startedAt = microtime(true);
        $responseBytes = null;

        try {
            $response = $this->http($timeoutSeconds)
                ->throw()
                ->get(self::BASE_URL.'/search', $query);
            if ($debugTiming) {
                $responseBytes = strlen($response->body());
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                throw new Exception('Invalid Modrinth search response.');
            }

            $hits = $payload['hits'] ?? [];
            if (!is_array($hits)) {
                throw new Exception('Invalid Modrinth search hits response.');
            }

            $normalizedHits = [];
            foreach ($hits as $project) {
                if (is_array($project)) {
                    $normalizedHits[] = $this->normalizeSearchProject($project);
                }
            }

            return [
                'hits' => $normalizedHits,
                'total_hits' => (int) ($payload['total_hits'] ?? 0),
            ];
        } finally {
            if ($debugTiming) {
                logger()->debug('Catalog search API timing', [
                    'source' => 'modrinth',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'response_bytes' => $responseBytes,
                ]);
            }
        }
    }

    /**
     * @return array<int, mixed>
     *
     * @throws Exception
     */
    private function requestVersions(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $projectId = $this->stringArgument($spec, 'project_id');
        $minecraftVersion = $this->stringArgument($spec, 'game_version');
        $minecraftLoader = isset($spec->arguments['loader']) && is_string($spec->arguments['loader'])
            ? $spec->arguments['loader']
            : null;
        $query = [
            'game_versions' => json_encode([$minecraftVersion], JSON_THROW_ON_ERROR),
            'include_changelog' => 'false',
        ];
        if ($minecraftLoader !== null && $minecraftLoader !== '') {
            $query['loaders'] = json_encode([$minecraftLoader], JSON_THROW_ON_ERROR);
        }
        $versions = $this->http($timeoutSeconds)
            ->throw()
            ->get(self::BASE_URL."/project/$projectId/version", $query)
            ->json();

        if (!is_array($versions)) {
            throw new Exception('Invalid Modrinth versions response.');
        }

        if (isset($versions[0]['date_published'])) {
            usort($versions, function ($a, $b) {
                return strcmp($b['date_published'] ?? '', $a['date_published'] ?? '');
            });
        }

        return $versions;
    }

    /**
     * @return array<string, array<string, mixed>>
     *
     * @throws Exception
     */
    private function requestProjects(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $projectIds = $this->stringListArgument($spec, 'project_ids');
        $projects = $this->http($timeoutSeconds)
            ->throw()
            ->get(self::BASE_URL.'/projects', [
                'ids' => json_encode($projectIds, JSON_THROW_ON_ERROR),
            ])
            ->json();

        if (!is_array($projects)) {
            throw new Exception('Invalid Modrinth projects response.');
        }

        $map = [];
        foreach ($projects as $project) {
            if (!is_array($project) || !is_string($project['id'] ?? null) || $project['id'] === '') {
                continue;
            }

            $map[$project['id']] = [
                'project_id' => $project['id'],
                'slug' => $project['slug'] ?? '',
                'title' => $project['title'] ?? '',
                'description' => $project['description'] ?? '',
                'icon_url' => $project['icon_url'] ?? null,
                'author' => null,
                'downloads' => (int) ($project['downloads'] ?? 0),
                'date_modified' => $project['updated'] ?? null,
                'project_type' => $project['project_type'] ?? '',
            ];
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function requestProject(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $projectId = $this->stringArgument($spec, 'project_id');
        $project = $this->http($timeoutSeconds)
            ->throw()
            ->get(self::BASE_URL."/project/$projectId")
            ->json();

        if (!is_array($project)) {
            throw new Exception('Invalid Modrinth project response.');
        }

        return $project;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function requestVersionsByHash(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $hashes = $this->stringListArgument($spec, 'hashes');
        $result = $this->http($timeoutSeconds)
            ->throw()
            ->post(self::BASE_URL.'/version_files', [
                'hashes' => $hashes,
                'algorithm' => 'sha512',
            ])
            ->json();

        if (!is_array($result)) {
            throw new Exception('Invalid Modrinth hash lookup response.');
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    private function requestLatestVersions(SourceFetchSpec $spec, float $timeoutSeconds): LatestVersionLookupResult
    {
        $hashesByKey = $this->stringMapArgument($spec, 'hashes_by_key');
        $minecraftVersion = $this->stringArgument($spec, 'game_version');
        $minecraftLoader = isset($spec->arguments['loader']) && is_string($spec->arguments['loader'])
            ? $spec->arguments['loader']
            : null;
        $requestsByHash = [];

        foreach ($hashesByKey as $key => $hash) {
            $requestsByHash[$hash][] = $key;
        }

        $debugTiming = (bool) config('pelican-mod-manager.debug_timing', false);
        $startedAt = microtime(true);
        $returnedHashCount = 0;
        $request = [
            'hashes' => array_keys($requestsByHash),
            'algorithm' => 'sha512',
            'game_versions' => [$minecraftVersion],
        ];
        if ($minecraftLoader !== null && $minecraftLoader !== '') {
            $request['loaders'] = [$minecraftLoader];
        }

        try {
            $payload = $this->http($timeoutSeconds)
                ->throw()
                ->post(self::BASE_URL.'/version_files/update', $request)
                ->json();

            if (!is_array($payload)) {
                throw new Exception('Invalid Modrinth bulk latest-version response.');
            }

            $returnedHashCount = count($payload);
            $versionsByKey = [];
            $unresolvedKeys = [];

            foreach ($requestsByHash as $hash => $keys) {
                $version = $payload[$hash] ?? null;

                if (is_array($version) && $version !== []) {
                    foreach ($keys as $key) {
                        $versionsByKey[$key] = $version;
                    }

                    continue;
                }

                array_push($unresolvedKeys, ...$keys);
            }

            return new LatestVersionLookupResult(
                versionsByKey: $versionsByKey,
                unresolvedKeys: $unresolvedKeys,
            );
        } finally {
            if ($debugTiming) {
                logger()->info('Mod manager timing', [
                    'stage' => 'modrinth_versions_bulk_request',
                    'request_id' => request()->attributes->get('mmr_timing_request_id'),
                    'endpoint' => '/version_files/update',
                    'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
                    'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'requested_hash_count' => count($requestsByHash),
                    'returned_hash_count' => $returnedHashCount,
                ]);
            }
        }
    }

    /**
     * @throws Exception
     */
    private function requestTeamPrimaryUsername(SourceFetchSpec $spec, float $timeoutSeconds): ?string
    {
        $teamId = $this->stringArgument($spec, 'team_id');
        $members = $this->http($timeoutSeconds)
            ->throw()
            ->get(self::BASE_URL."/team/{$teamId}/members")
            ->json();

        if (!is_array($members)) {
            throw new Exception('Invalid Modrinth team response.');
        }

        foreach ($members as $member) {
            $username = is_array($member) ? ($member['user']['username'] ?? null) : null;
            if (is_string($username) && $username !== '') {
                return $username;
            }
        }

        return null;
    }

    /**
     * @throws Exception
     */
    private function requestUsername(SourceFetchSpec $spec, float $timeoutSeconds): ?string
    {
        $userId = $this->stringArgument($spec, 'user_id');
        $user = $this->http($timeoutSeconds)
            ->throw()
            ->get(self::BASE_URL."/user/{$userId}")
            ->json();

        if (!is_array($user)) {
            throw new Exception('Invalid Modrinth user response.');
        }

        $username = $user['username'] ?? null;

        return is_string($username) && $username !== '' ? $username : null;
    }

    private function emptyLatestVersionResult(SourceFetchSpec $spec): LatestVersionLookupResult
    {
        $hashesByKey = $spec->arguments['hashes_by_key'] ?? [];
        $failures = [];

        if (is_array($hashesByKey)) {
            foreach (array_keys($hashesByKey) as $key) {
                if (is_string($key) && $key !== '') {
                    $failures[$key] = 'Modrinth latest-version lookup is unavailable.';
                }
            }
        }

        return new LatestVersionLookupResult(failuresByKey: $failures);
    }

    /** @param array<int|string, mixed> $arguments */
    private function spec(string $operation, array $arguments = []): SourceFetchSpec
    {
        return new SourceFetchSpec($this->getKey()->value, $operation, $arguments);
    }

    /**
     * @throws Exception
     */
    private function stringArgument(SourceFetchSpec $spec, string $key): string
    {
        $value = $spec->arguments[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new Exception("Invalid Modrinth [{$spec->operation}] argument [$key].");
        }

        return $value;
    }

    /**
     * @return array<int, string>
     *
     * @throws Exception
     */
    private function stringListArgument(SourceFetchSpec $spec, string $key): array
    {
        $value = $spec->arguments[$key] ?? null;

        if (!is_array($value) || !array_is_list($value)) {
            throw new Exception("Invalid Modrinth [{$spec->operation}] argument [$key].");
        }

        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new Exception("Invalid Modrinth [{$spec->operation}] argument [$key].");
            }
        }

        return $value;
    }

    /**
     * @return array<string, string>
     *
     * @throws Exception
     */
    private function stringMapArgument(SourceFetchSpec $spec, string $key): array
    {
        $value = $spec->arguments[$key] ?? null;

        if (!is_array($value)) {
            throw new Exception("Invalid Modrinth [{$spec->operation}] argument [$key].");
        }

        foreach ($value as $mapKey => $item) {
            if (!is_string($mapKey) || $mapKey === '' || !is_string($item) || $item === '') {
                throw new Exception("Invalid Modrinth [{$spec->operation}] argument [$key].");
            }
        }

        return $value;
    }

    private function http(float $timeoutSeconds): PendingRequest
    {
        return UpstreamHttp::json()
            ->timeout($timeoutSeconds)
            ->connectTimeout(min(1.0, $timeoutSeconds));
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
}
