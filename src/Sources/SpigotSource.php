<?php

namespace Kazaminosuke\ModManager\Sources;

use App\Models\Server;
use Exception;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Contracts\ArchiveMetadataIdentificationInterface;
use Kazaminosuke\ModManager\Contracts\BatchLatestVersionSourceInterface;
use Kazaminosuke\ModManager\Contracts\ProjectMetadataPeekManyInterface;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchAuthoritativeInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchHandlerInterface;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\PartialSourceFetchException;
use Kazaminosuke\ModManager\Exceptions\SourceFetchNotFoundException;
use Kazaminosuke\ModManager\Support\BukkitPluginDescriptor;
use Kazaminosuke\ModManager\Support\CachedProjectMetadata;
use Kazaminosuke\ModManager\Support\CachedSearchOperations;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\CatalogFields;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Kazaminosuke\ModManager\Support\SpigotFileIndex;
use Throwable;

/**
 * Plugin catalog source for SpigotMC resources.
 *
 * Canonical resource metadata comes from the official Spigot Simple API.
 * Search, version listings, and public-file download redirects come from
 * Spiget because those operations are not exposed by the official API.
 * Premium, buyer-only, and externally hosted files are never downloaded.
 */
class SpigotSource implements ArchiveMetadataIdentificationInterface, BatchLatestVersionSourceInterface, ProjectMetadataPeekManyInterface, ProjectSourceInterface, SourceFetchAuthoritativeInterface, SourceFetchHandlerInterface
{
    protected const OFFICIAL_API = 'https://api.spigotmc.org/simple/0.2/index.php';

    protected const SPIGET_API = 'https://api.spiget.org/v2';

    protected const CDN_HOST = 'cdn.spiget.org';

    protected const USER_AGENT = 'pelican-mod-manager (https://github.com/kazaminosuke/pelican-mod-manager)';

    protected const CATALOG_PAGE_SIZE = 20;

    protected const VERSION_PAGE_SIZE = 25;

    protected const VERSION_MAX_PAGES = 4;

    protected const LATEST_VERSION_POOL_SIZE = 4;

    protected const PROJECT_POOL_SIZE = 10;

    /**
     * Part of every Spigot cache key. Bump it when a normalized payload
     * changes shape or meaning so entries written by an older release are
     * refetched instead of served for their remaining TTL.
     */
    private const CACHE_SCHEMA = 2;

    private const OPERATION_LATEST = 'latest';

    private const OPERATION_PROJECT = 'project';

    private const OPERATION_SEARCH = 'search';

    private const OPERATION_VERSIONS = 'versions';

    private readonly CachedProjectMetadata $cachedProjectMetadata;

    private readonly CachedSearchOperations $cachedSearch;

    public function __construct(
        private readonly SourceCache $sourceCache,
        private readonly SpigotFileIndex $fileIndex = new SpigotFileIndex(),
    ) {
        $this->cachedProjectMetadata = new CachedProjectMetadata($sourceCache);
        $this->cachedSearch = new CachedSearchOperations($sourceCache);
    }

    public function getKey(): ProjectSourceKey
    {
        return ProjectSourceKey::Spigot;
    }

    public function getLabel(): string
    {
        return 'Spigot';
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
            throw new Exception("Invalid source key [{$spec->sourceKey}] for Spigot.");
        }

        return match ($spec->operation) {
            self::OPERATION_LATEST => $this->fetchLatestVersions($spec, $timeoutSeconds),
            self::OPERATION_PROJECT => $this->fetchProject($spec, $timeoutSeconds),
            self::OPERATION_SEARCH => $this->fetchSearch($spec, $timeoutSeconds),
            self::OPERATION_VERSIONS => $this->fetchVersions($spec, $timeoutSeconds),
            default => throw new Exception("Unsupported Spigot cache operation [{$spec->operation}]."),
        };
    }

    public function emptySourceData(SourceFetchSpec $spec): mixed
    {
        return match ($spec->operation) {
            self::OPERATION_PROJECT => null,
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

        $requestedVersions = $this->versionFilterValues(
            array_key_exists('versions', $filters) ? $filters['versions'] : ($filters['version'] ?? []),
        );
        $version = count($requestedVersions) === 1
            ? $requestedVersions[0]
            : (count($requestedVersions) === 0 ? MinecraftVersionResolver::resolve($server) : null);

        $sort = (string) ($filters['sort'] ?? 'downloads');
        if (!in_array($sort, ['downloads', 'updated', 'newest'], true)) {
            $sort = 'downloads';
        }

        return $this->spec(self::OPERATION_SEARCH, [
            'page' => max(1, $page),
            'query' => is_string($search) ? trim($search) : '',
            'sort' => $sort,
            'version' => $version,
        ]);
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
        if ($type !== ProjectType::Plugin || $this->normalizeResourceId($projectId) === null) {
            return [];
        }

        $versions = $this->sourceCache->swr(
            $this->versionsSpec($projectId, resolveDownloads: true),
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

        if ($requests === [] || $type !== ProjectType::Plugin) {
            return $requests === []
                ? LatestVersionLookupResult::empty()
                : new LatestVersionLookupResult(unresolvedKeys: array_map(
                    fn (LatestVersionLookupRequest $request): string => $request->key(),
                    $requests,
                ));
        }

        $spec = $this->latestSpec($requests);
        if ($spec === null) {
            return new LatestVersionLookupResult(unresolvedKeys: array_map(
                fn (LatestVersionLookupRequest $request): string => $request->key(),
                $requests,
            ));
        }

        return $this->distributeLatestPayload(
            $this->groupLatestRequests($requests),
            $this->sourceCache->swr($spec, CacheProfile::InstalledLatest),
        );
    }

    /**
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

        if ($requests === [] || $type !== ProjectType::Plugin) {
            return $requests === []
                ? LatestVersionLookupResult::empty()
                : new LatestVersionLookupResult(unresolvedKeys: array_map(
                    fn (LatestVersionLookupRequest $request): string => $request->key(),
                    $requests,
                ));
        }

        $spec = $this->latestSpec($requests);
        if ($spec === null) {
            return new LatestVersionLookupResult(unresolvedKeys: array_map(
                fn (LatestVersionLookupRequest $request): string => $request->key(),
                $requests,
            ));
        }

        $peeked = $this->sourceCache->swrDeferred($spec, CacheProfile::InstalledLatest);
        if ($peeked['pending']) {
            return new LatestVersionLookupResult(pendingKeys: array_map(
                fn (LatestVersionLookupRequest $request): string => $request->key(),
                $requests,
            ));
        }

        return $this->distributeLatestPayload($this->groupLatestRequests($requests), $peeked['data']);
    }

    /**
     * @param array<string, string> $hashesByFilename
     * @return array<string, mixed>
     */
    public function findVersionsByHash(array $hashesByFilename): array
    {
        return $this->findVersionsByHashUsingIndex($hashesByFilename);
    }

    public function findVersionsByHashAuthoritatively(array $hashesByFilename): array
    {
        return $this->findVersionsByHashUsingIndex($hashesByFilename);
    }

    /**
     * @param array<string, string> $hashesByFilename
     * @return array<string, mixed>
     */
    protected function findVersionsByHashUsingIndex(array $hashesByFilename): array
    {
        $matched = [];

        foreach ($hashesByFilename as $hash) {
            if (!is_string($hash) || $hash === '') {
                continue;
            }

            $indexed = $this->fileIndex->findBySha256($hash);
            if ($indexed === null) {
                continue;
            }

            $matched[$hash] = $this->indexedVersionPayload($indexed);
        }

        return $matched;
    }

    /** @return array<string, mixed>|null */
    public function resolveProjectByIdentifier(string $identifier): ?array
    {
        $projectId = $this->normalizeResourceId($identifier);

        return $projectId !== null ? $this->getProject($projectId) : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, string>  $hashes
     * @return array<string, mixed>|null
     */
    public function identifyFromArchiveMetadata(array $metadata, array $hashes): ?array
    {
        $descriptor = BukkitPluginDescriptor::fromArray($metadata);
        $knownProjectId = $this->normalizeResourceId((string) ($metadata['known_project_id'] ?? ''));
        $resourceId = $knownProjectId ?? $descriptor->spigotResourceId();

        if ($resourceId === null) {
            $resourceId = $this->identifyResourceId($descriptor);
        }

        if ($resourceId === null) {
            return null;
        }

        $version = $this->knownVersionPayload($resourceId, $metadata)
            ?? $this->identifyVersion($resourceId, $descriptor);
        if ($version === null) {
            return null;
        }

        $this->rememberHash($hashes, $version, $descriptor->name);

        return $version;
    }

    /**
     * Persist a high-confidence mapping after an install or scan match.
     *
     * @param  array<string, mixed>  $entry
     */
    public function rememberInstalledEntry(array $entry): void
    {
        $hashes = is_array($entry['hashes'] ?? null) ? $entry['hashes'] : [];
        $this->rememberHash($hashes, [
            'project_id' => (string) ($entry['project_id'] ?? ''),
            'id' => (string) ($entry['version_id'] ?? ''),
            'version_number' => (string) ($entry['version_number'] ?? ''),
        ], is_string($entry['project_title'] ?? null) ? $entry['project_title'] : null);
    }

    /** @param array<string, mixed> $filters */
    private function versionFilterValues(mixed $values): array
    {
        $values = is_array($values) ? $values : (is_string($values) && $values !== '' ? [$values] : []);

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $values),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * Spiget decides which resources appear on a catalog page and in what
     * order; each row is then overlaid with the canonical official metadata.
     *
     * @return array{hits: array<int, array<string, mixed>>, total_hits: int}
     */
    protected function fetchSearch(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $page = max(1, (int) ($spec->arguments['page'] ?? 1));
        $query = trim((string) ($spec->arguments['query'] ?? ''));
        $version = trim((string) ($spec->arguments['version'] ?? ''));
        $deadline = microtime(true) + max(0.1, $timeoutSeconds);

        $params = [
            'size' => self::CATALOG_PAGE_SIZE,
            'page' => $page,
            'sort' => $this->spigetSort((string) ($spec->arguments['sort'] ?? 'downloads')),
        ];

        if ($query !== '') {
            $path = '/search/resources/'.rawurlencode($query);
            $params['field'] = 'name';
        } elseif ($version !== '') {
            $path = '/resources/for/'.implode(',', array_map('rawurlencode', $this->testedVersionCandidates($version)));
            $params['method'] = 'any';
        } else {
            $path = '/resources';
        }

        try {
            $response = $this->request(self::SPIGET_API.$path, $params, $timeoutSeconds);
        } catch (SourceFetchNotFoundException) {
            // Spiget answers 404 for a query or version without any match.
            return ['hits' => [], 'total_hits' => 0];
        }

        $payload = $response->json();
        // /resources/for wraps its rows as {check, method, match}.
        $resources = is_array($payload) && !array_is_list($payload) ? ($payload['match'] ?? null) : $payload;
        if (!is_array($resources)) {
            throw new Exception('Invalid Spigot catalog response.');
        }

        $hits = [];
        foreach ($resources as $resource) {
            if (!is_array($resource)) {
                continue;
            }

            // Spiget search has no version filter, so apply it to the page.
            if ($query !== '' && $version !== '' && !$this->testedVersionsInclude($resource, $version)) {
                continue;
            }

            $normalized = $this->normalizeSpigetProject($resource);
            if ($normalized !== null) {
                $hits[] = $normalized;
            }
        }

        $total = (int) $response->header('X-Total');
        if ($total < 1) {
            $pages = (int) $response->header('X-Page-Count');
            $total = $pages > 0 ? $pages * self::CATALOG_PAGE_SIZE : (($page - 1) * self::CATALOG_PAGE_SIZE) + count($hits);
        }

        return [
            'hits' => $this->withOfficialMetadata($hits, $deadline),
            'total_hits' => $total,
        ];
    }

    /**
     * Overlay catalog rows with official metadata. Rows from /resources/for
     * carry only id, name, and tested versions, and Spiget's own statistics
     * lag behind SpigotMC, so the official API stays the canonical source.
     * Cached projects are reused; the rest are fetched within the remaining
     * budget and primed for the Installed tab. A row keeps its Spiget values
     * when the official lookup does not complete.
     *
     * @param  array<int, array<string, mixed>>  $hits
     * @return array<int, array<string, mixed>>
     */
    private function withOfficialMetadata(array $hits, float $deadline): array
    {
        if ($hits === []) {
            return [];
        }

        $projectIds = array_map(static fn (array $hit): string => (string) $hit['project_id'], $hits);
        $official = [];
        $missing = [];

        foreach ($this->cachedProjectMetadata->peekMany($projectIds, $this->projectSpec(...)) as $projectId => $peeked) {
            if (is_array($peeked['data'])) {
                $official[$projectId] = $peeked['data'];
            } else {
                $missing[] = (string) $projectId;
            }
        }

        $remaining = $deadline - microtime(true);
        if ($missing !== [] && $remaining > 0.1) {
            $fetched = $this->fetchProjectsByIdsWithPool($missing, $remaining);
            $this->primeProjects($fetched);
            $official += $fetched;
        }

        return array_map(static function (array $hit) use ($official): array {
            $project = $official[$hit['project_id']] ?? null;

            return is_array($project)
                ? array_merge($hit, array_filter($project, static fn (mixed $value): bool => $value !== null))
                : $hit;
        }, $hits);
    }

    /** @return array<string, mixed> */
    protected function fetchProject(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $projectId = $this->normalizeResourceId((string) ($spec->arguments['project_id'] ?? ''));
        if ($projectId === null) {
            throw new Exception('Invalid Spigot resource identifier.');
        }

        $fetched = $this->fetchProjectsByIdsWithPool([$projectId], $timeoutSeconds);
        $project = $fetched[$projectId] ?? null;

        if (!is_array($project)) {
            throw new SourceFetchNotFoundException("Spigot resource [$projectId] was not found.");
        }

        return $project;
    }

    /**
     * @param array<int, string> $projectIds
     * @return array<string, array<string, mixed>>
     */
    protected function fetchProjectsByIdsWithPool(array $projectIds, ?float $timeoutSeconds = null): array
    {
        $projectIds = array_values(array_filter(
            array_map(fn (mixed $projectId): ?string => $this->normalizeResourceId((string) $projectId), $projectIds),
        ));

        if ($projectIds === []) {
            return [];
        }

        $timeoutSeconds ??= CacheProfile::ProjectMetadata->backgroundTimeoutSeconds();
        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $resolved = [];

        foreach (array_chunk($projectIds, self::PROJECT_POOL_SIZE) as $chunk) {
            try {
                $remaining = $this->remainingTimeout($deadline);
            } catch (Throwable $exception) {
                report($exception);

                break;
            }

            $aliases = [];

            try {
                $headers = $this->headers();
                $responses = Http::pool(function (Pool $pool) use ($chunk, $remaining, $headers, &$aliases) {
                    $poolRequests = [];

                    foreach (array_values($chunk) as $index => $projectId) {
                        $alias = "spigot_project_$index";
                        $aliases[$alias] = $projectId;
                        $poolRequests[] = $pool->as($alias)
                            ->asJson()
                            ->withHeaders($headers)
                            ->timeout($remaining)
                            ->connectTimeout(min(1.0, $remaining))
                            ->get(self::OFFICIAL_API, [
                                'action' => 'getResource',
                                'id' => $projectId,
                            ]);
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
                    $payload = $response instanceof Response ? $response->json() : null;

                    if (!$response instanceof Response
                        || !$response->successful()
                        || !is_array($payload)
                        || isset($payload['error'])) {
                        throw new SourceFetchNotFoundException("Spigot resource [$projectId] was not found.");
                    }

                    $normalized = $this->normalizeOfficialProject($payload);
                    if ($normalized === null) {
                        throw new SourceFetchNotFoundException("Spigot resource [$projectId] was not found.");
                    }

                    $resolved[$projectId] = $normalized;
                } catch (SourceFetchNotFoundException) {
                    // A definitive miss is left out of the batch map.
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
        $projectId = $this->normalizeResourceId((string) ($spec->arguments['project_id'] ?? ''));
        if ($projectId === null) {
            throw new Exception('Invalid Spigot versions parameters.');
        }

        $resolveDownloads = (bool) ($spec->arguments['resolve_downloads'] ?? true);
        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $resource = $this->fetchSpigetResource($projectId, $this->remainingTimeout($deadline));
        $downloadable = $resource !== null && $this->isDirectlyDownloadable($resource);
        $versions = [];

        for ($page = 1; $page <= self::VERSION_MAX_PAGES; $page++) {
            $payload = $this->getJson(
                self::SPIGET_API."/resources/{$projectId}/versions",
                [
                    'size' => self::VERSION_PAGE_SIZE,
                    'page' => $page,
                    'sort' => '-releaseDate',
                ],
                $this->remainingTimeout($deadline),
            );

            if ($payload === []) {
                break;
            }

            foreach ($payload as $version) {
                if (!is_array($version)) {
                    continue;
                }

                $normalized = $this->normalizeSpigetVersion($projectId, $version, $resource);
                if ($normalized === null) {
                    continue;
                }

                if ($resolveDownloads && $downloadable) {
                    try {
                        $url = $this->resolveDownloadUrl(
                            $projectId,
                            (string) $normalized['id'],
                            $this->remainingTimeout($deadline),
                        );
                    } catch (Throwable) {
                        $url = null;
                    }

                    if (is_string($url) && $url !== '') {
                        $normalized['files'][0]['url'] = $url;
                    } else {
                        $normalized['files'] = [];
                    }
                } else {
                    $normalized['files'] = [];
                }

                $versions[] = $normalized;
            }

            if (count($payload) < self::VERSION_PAGE_SIZE) {
                break;
            }
        }

        return $versions;
    }

    /**
     * @return array{versions: array<string, array<string, mixed>>, unresolved: array<int, string>, failures?: array<string, string>}
     */
    protected function fetchLatestVersions(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $projectIds = is_array($spec->arguments['project_ids'] ?? null) ? $spec->arguments['project_ids'] : [];
        $projectIds = array_values(array_filter(
            array_map(fn (mixed $projectId): ?string => $this->normalizeResourceId((string) $projectId), $projectIds),
        ));

        if ($projectIds === []) {
            return ['versions' => [], 'unresolved' => []];
        }

        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $resolved = [];
        $unresolved = [];
        $failures = [];

        foreach (array_chunk($projectIds, self::LATEST_VERSION_POOL_SIZE) as $chunk) {
            try {
                $remaining = $this->remainingTimeout($deadline);
            } catch (Throwable $exception) {
                report($exception);
                foreach ($chunk as $projectId) {
                    $failures[$projectId] = $exception->getMessage();
                    $unresolved[] = $projectId;
                }

                break;
            }

            [$chunkResolved, $chunkFailures] = $this->fetchLatestVersionChunk($chunk, $remaining);
            $failures = array_merge($failures, $chunkFailures);

            foreach ($chunk as $projectId) {
                if (isset($chunkResolved[$projectId])) {
                    $resolved[$projectId] = $chunkResolved[$projectId];
                } else {
                    $unresolved[] = $projectId;
                }
            }
        }

        $result = ['versions' => $resolved, 'unresolved' => $unresolved];

        if ($failures !== []) {
            $result['failures'] = $failures;

            throw new PartialSourceFetchException(
                'Spigot latest-version batch completed with partial failures: '.implode('; ', $failures),
                $result,
            );
        }

        return $result;
    }

    /**
     * @param array<int, string> $projectIds
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>}
     */
    protected function fetchLatestVersionChunk(array $projectIds, float $timeoutSeconds): array
    {
        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $aliases = [];

        try {
            $headers = $this->headers();
            $remaining = $this->remainingTimeout($deadline);
            $responses = Http::pool(function (Pool $pool) use ($projectIds, $remaining, $headers, &$aliases) {
                $poolRequests = [];

                foreach (array_values($projectIds) as $index => $projectId) {
                    $alias = "spigot_latest_$index";
                    $aliases[$alias] = $projectId;
                    $poolRequests[] = $pool->as($alias)
                        ->asJson()
                        ->withHeaders($headers)
                        ->timeout($remaining)
                        ->connectTimeout(min(1.0, $remaining))
                        ->get(self::SPIGET_API."/resources/{$projectId}");
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
                $payload = $response instanceof Response ? $response->json() : null;

                if (!$response instanceof Response || !$response->successful() || !is_array($payload)) {
                    throw new Exception("Spigot resource [$projectId] was not found.");
                }

                $versionPayload = is_array($payload['version'] ?? null) ? $payload['version'] : [];
                $normalized = $this->normalizeSpigetVersion($projectId, [
                    'id' => $versionPayload['id'] ?? null,
                    'name' => $payload['current_version'] ?? ($versionPayload['name'] ?? null),
                    'releaseDate' => $payload['updateDate'] ?? $payload['releaseDate'] ?? null,
                    'downloads' => $payload['downloads'] ?? 0,
                ], $payload);

                if ($normalized === null) {
                    continue;
                }

                if ($this->isDirectlyDownloadable($payload)) {
                    $url = $this->resolveDownloadUrl(
                        $projectId,
                        (string) $normalized['id'],
                        $this->remainingTimeout($deadline),
                    );
                    if (is_string($url) && $url !== '') {
                        $normalized['files'][0]['url'] = $url;
                    } else {
                        $normalized['files'] = [];
                    }
                } else {
                    $normalized['files'] = [];
                }

                $resolved[$projectId] = $normalized;
            } catch (Throwable $exception) {
                report($exception);
                $failures[$projectId] = $exception->getMessage();
            }
        }

        return [$resolved, $failures];
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

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     * @return array<string, array<int, LatestVersionLookupRequest>>
     */
    private function groupLatestRequests(array $requests): array
    {
        $grouped = [];
        foreach ($requests as $request) {
            $grouped[$request->projectId][] = $request;
        }

        return $grouped;
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    private function latestSpec(array $requests): ?SourceFetchSpec
    {
        $projectIds = [];
        foreach ($requests as $request) {
            $projectId = $this->normalizeResourceId($request->projectId);
            if ($projectId !== null) {
                $projectIds[] = $projectId;
            }
        }

        $projectIds = array_values(array_unique($projectIds));
        sort($projectIds, SORT_STRING);

        if ($projectIds === []) {
            return null;
        }

        return $this->spec(self::OPERATION_LATEST, ['project_ids' => $projectIds]);
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
        $versionsByKey = [];
        $unresolved = [];
        $failures = is_array($payload) && is_array($payload['failures'] ?? null)
            ? $payload['failures']
            : [];

        foreach ($requestsByProject as $projectId => $grouped) {
            $version = $resolved[$projectId] ?? null;

            foreach ($grouped as $request) {
                if (is_array($version)) {
                    $versionsByKey[$request->key()] = $version;
                } elseif (isset($unresolvedProjects[$projectId]) || $version === null) {
                    $unresolved[] = $request->key();
                }
            }
        }

        $failuresByKey = [];
        foreach ($failures as $projectId => $message) {
            foreach ($requestsByProject[$projectId] ?? [] as $request) {
                $failuresByKey[$request->key()] = (string) $message;
            }
        }

        return new LatestVersionLookupResult(
            versionsByKey: $versionsByKey,
            unresolvedKeys: $unresolved,
            failuresByKey: $failuresByKey,
        );
    }

    /** @return array<string, mixed>|null */
    private function identifyResourceId(BukkitPluginDescriptor $descriptor): ?string
    {
        $name = $descriptor->name ?? $this->filenamePluginName($descriptor->filename);
        if ($name === null || $name === '') {
            return null;
        }

        $payload = $this->sourceCache->swrRequired(
            $this->spec(self::OPERATION_SEARCH, [
                'page' => 1,
                'query' => $name,
                'sort' => 'downloads',
                'version' => null,
            ]),
            CacheProfile::Search,
        );

        $hits = is_array($payload) && is_array($payload['hits'] ?? null) ? $payload['hits'] : [];
        $wantedName = BukkitPluginDescriptor::normalizeIdentity($name);
        $candidates = [];

        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $hitName = BukkitPluginDescriptor::normalizeIdentity((string) ($hit['title'] ?? ''));
            if ($wantedName !== null && $hitName === $wantedName) {
                $candidates[] = $hit;
            }
        }

        if ($candidates === []) {
            return null;
        }

        if (count($candidates) > 1 && $descriptor->authors !== []) {
            $wantedAuthors = array_map(
                static fn (string $author): string => strtolower($author),
                $descriptor->authors,
            );
            $candidates = array_values(array_filter(
                $candidates,
                static function (array $hit) use ($wantedAuthors): bool {
                    $author = strtolower(trim((string) ($hit['author'] ?? '')));

                    return $author !== '' && in_array($author, $wantedAuthors, true);
                },
            ));
        }

        if (count($candidates) !== 1) {
            return null;
        }

        return $this->normalizeResourceId((string) ($candidates[0]['project_id'] ?? ''));
    }

    /** @return array<string, mixed>|null */
    private function identifyVersion(string $resourceId, BukkitPluginDescriptor $descriptor): ?array
    {
        $wanted = $descriptor->normalizedVersion();
        if ($wanted === null) {
            return null;
        }

        $versions = $this->sourceCache->swrRequired(
            $this->versionsSpec($resourceId, resolveDownloads: false),
            CacheProfile::InstalledLatest,
        );
        if (!is_array($versions)) {
            return null;
        }

        $matches = [];
        foreach ($versions as $version) {
            if (!is_array($version)) {
                continue;
            }

            $number = BukkitPluginDescriptor::normalizeVersion((string) ($version['version_number'] ?? ''));
            if ($number === $wanted) {
                $matches[] = $version;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        $match = $matches[0];
        $match['project_id'] = $resourceId;

        return $match;
    }

    /**
     * @param  array<string, mixed>  $hashes
     * @param  array<string, mixed>  $version
     */
    private function rememberHash(array $hashes, array $version, ?string $pluginName): void
    {
        $sha256 = strtolower((string) ($hashes['sha256'] ?? ''));
        $resourceId = (string) ($version['project_id'] ?? '');
        $versionId = (string) ($version['id'] ?? '');
        $versionNumber = (string) ($version['version_number'] ?? '');

        if ($sha256 === '' || $resourceId === '' || $versionId === '') {
            return;
        }

        $this->fileIndex->remember($sha256, $resourceId, $versionId, $versionNumber, $pluginName);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function knownVersionPayload(string $resourceId, array $metadata): ?array
    {
        $versionId = trim((string) ($metadata['known_version_id'] ?? ''));
        $versionNumber = trim((string) ($metadata['known_version_number'] ?? ''));
        if ($versionId === '' || $versionNumber === '') {
            return null;
        }

        return [
            'id' => $versionId,
            'project_id' => $resourceId,
            'version_number' => $versionNumber,
            'version_type' => 'release',
            'downloads' => 0,
            'date_published' => null,
            'changelog' => null,
            'featured' => false,
            'files' => [],
        ];
    }

    /**
     * @param  array{resource_id: string, version_id: string, version_number: string, plugin_name: ?string}  $indexed
     * @return array<string, mixed>
     */
    private function indexedVersionPayload(array $indexed): array
    {
        return [
            'id' => $indexed['version_id'],
            'project_id' => $indexed['resource_id'],
            'version_number' => $indexed['version_number'],
            'version_type' => 'release',
            'downloads' => 0,
            'date_published' => null,
            'changelog' => null,
            'featured' => false,
            'files' => [],
        ];
    }

    /** @return array<string, mixed>|null */
    private function fetchSpigetResource(string $projectId, float $timeoutSeconds): ?array
    {
        try {
            $payload = $this->getJson(self::SPIGET_API."/resources/{$projectId}", [], $timeoutSeconds);
        } catch (SourceFetchNotFoundException) {
            return null;
        }

        return $payload;
    }

    private function resolveDownloadUrl(string $resourceId, string $versionId, float $timeoutSeconds): ?string
    {
        if ($resourceId === '' || $versionId === '') {
            return null;
        }

        $response = Http::withHeaders($this->headers())
            ->withOptions(['allow_redirects' => false])
            ->timeout(max(0.1, $timeoutSeconds))
            ->connectTimeout(min(1.0, $timeoutSeconds))
            ->get(self::SPIGET_API."/resources/{$resourceId}/versions/{$versionId}/download");

        $source = strtolower((string) $response->header('X-Spiget-File-Source'));
        if ($source === 'external') {
            return null;
        }

        if (in_array($response->status(), [401, 403, 404], true)) {
            return null;
        }

        $location = $response->header('Location');
        if (!is_string($location) || $location === '') {
            return null;
        }

        if (str_starts_with($location, '//')) {
            $location = 'https:'.$location;
        } elseif (str_starts_with($location, '/')) {
            $location = 'https://api.spiget.org'.$location;
        }

        return $this->isAllowedDownloadUrl($location) ? $location : null;
    }

    private function isAllowedDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $scheme === 'https' && $host === self::CDN_HOST;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>|null
     */
    private function normalizeOfficialProject(array $resource): ?array
    {
        $projectId = $this->normalizeResourceId((string) ($resource['id'] ?? ''));
        if ($projectId === null) {
            return null;
        }

        // Simple API 0.2 nests the author and download count, and reports
        // Unix timestamps in seconds as `last_update` / `first_release`.
        $author = $resource['author']['username'] ?? null;
        $downloads = $resource['stats']['downloads'] ?? null;

        return [
            'project_id' => $projectId,
            'slug' => $projectId,
            'title' => (string) ($resource['title'] ?? $projectId),
            'description' => CatalogFields::description($resource['tag'] ?? ''),
            'icon_url' => $this->stringOrNull($resource['icon_link'] ?? null),
            'author' => is_string($author) && $author !== '' ? $author : null,
            'downloads' => is_numeric($downloads) ? (int) $downloads : null,
            'date_modified' => $this->timestampToIso($resource['last_update'] ?? $resource['first_release'] ?? null),
            'project_type' => ProjectType::Plugin->value,
            'source' => ProjectSourceKey::Spigot->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>|null
     */
    private function normalizeSpigetProject(array $resource): ?array
    {
        $projectId = $this->normalizeResourceId((string) ($resource['id'] ?? ''));
        if ($projectId === null) {
            return null;
        }

        $icon = $resource['icon']['url'] ?? null;
        if (is_string($icon) && $icon !== '' && !str_starts_with($icon, 'http')) {
            $icon = 'https://www.spigotmc.org/'.ltrim($icon, '/');
        }

        $downloads = $resource['downloads'] ?? null;

        return [
            'project_id' => $projectId,
            'slug' => $projectId,
            'title' => (string) ($resource['name'] ?? $projectId),
            'description' => CatalogFields::description($resource['tag'] ?? ''),
            'icon_url' => $this->stringOrNull($icon),
            // Spiget exposes only the author id; the official overlay names it.
            'author' => null,
            'downloads' => is_numeric($downloads) ? (int) $downloads : null,
            'date_modified' => $this->timestampToIso($resource['updateDate'] ?? $resource['releaseDate'] ?? null),
            'project_type' => ProjectType::Plugin->value,
            'source' => ProjectSourceKey::Spigot->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $version
     * @param  array<string, mixed>|null  $resource
     * @return array<string, mixed>|null
     */
    private function normalizeSpigetVersion(string $projectId, array $version, ?array $resource): ?array
    {
        $versionId = trim((string) ($version['id'] ?? ''));
        $versionNumber = trim((string) ($version['name'] ?? ''));
        if ($versionId === '' || $versionNumber === '') {
            return null;
        }

        $filename = $this->jarFilename(
            (string) ($resource['name'] ?? 'plugin'),
            $versionNumber,
        );

        return [
            'id' => $versionId,
            'project_id' => $projectId,
            'version_number' => $versionNumber,
            'version_type' => 'release',
            'downloads' => (int) ($version['downloads'] ?? 0),
            'date_published' => $this->timestampToIso($version['releaseDate'] ?? null),
            'changelog' => null,
            'featured' => false,
            'files' => [
                [
                    'primary' => true,
                    'filename' => $filename,
                    'url' => '',
                    'hashes' => [],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function isDirectlyDownloadable(array $resource): bool
    {
        return !$this->isPremium($resource) && !$this->isExternal($resource);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function isPremium(array $resource): bool
    {
        $premium = $resource['premium'] ?? false;
        if ($premium === true) {
            return true;
        }

        return is_array($premium) && (float) ($premium['price'] ?? 0) > 0;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function isExternal(array $resource): bool
    {
        if (($resource['external'] ?? false) === true) {
            return true;
        }

        $file = $resource['file'] ?? null;

        return is_array($file) && ($file['external'] ?? false) === true;
    }

    /**
     * SpigotMC authors tick "tested versions" from a list of mostly
     * major.minor releases (1.20, 1.21, 26.1, ...), and Spiget returns 404
     * for a version nobody ticked. Match the exact server version or its
     * release line, e.g. 1.21.4 matches 1.21.4 or 1.21.
     *
     * @return array<int, string>
     */
    private function testedVersionCandidates(string $version): array
    {
        $version = strtolower(trim($version));
        if (preg_match('/^(\d+\.\d+)\.\d+$/', $version, $matches) === 1) {
            return [$version, $matches[1]];
        }

        return $version !== '' ? [$version] : [];
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function testedVersionsInclude(array $resource, string $wanted): bool
    {
        $tested = $resource['testedVersions'] ?? [];
        if (!is_array($tested) || $tested === []) {
            return true;
        }

        $wanted = strtolower(trim($wanted));
        foreach ($tested as $version) {
            if (!is_scalar($version)) {
                continue;
            }

            $candidate = strtolower(trim((string) $version));
            if ($candidate === $wanted
                || str_starts_with($wanted, $candidate.'.')
                || str_starts_with($candidate, $wanted.'.')) {
                return true;
            }
        }

        return false;
    }

    private function spigetSort(string $sort): string
    {
        return match ($sort) {
            'updated' => '-updateDate',
            'newest' => '-releaseDate',
            default => '-downloads',
        };
    }

    private function jarFilename(string $name, string $version): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'plugin';
        $version = preg_replace('/[^A-Za-z0-9._+-]+/', '-', $version) ?? 'version';
        $name = trim($name, '-._') ?: 'plugin';
        $version = trim($version, '-._') ?: 'version';

        return $name.'-'.$version.'.jar';
    }

    private function filenamePluginName(?string $filename): ?string
    {
        if ($filename === null || $filename === '') {
            return null;
        }

        $base = preg_replace('/\.jar$/i', '', basename($filename)) ?? '';
        $base = preg_replace('/[-_.]?v?\d+(?:\.\d+)*[A-Za-z0-9._+-]*$/', '', $base) ?? '';
        $base = trim($base, '-_. ');

        return $base !== '' ? $base : null;
    }

    private function normalizeResourceId(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('~(?:spigotmc\.org/resources/(?:[^/]+\.)?)?(\d+)~i', $value, $matches) === 1) {
            return $matches[1];
        }

        return ctype_digit($value) ? $value : null;
    }

    private function timestampToIso(mixed $value): ?string
    {
        if (is_numeric($value) && (int) $value > 0) {
            return gmdate('c', (int) $value);
        }

        return $this->stringOrNull($value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'application/json',
        ];
    }

    /** @param array<string, mixed> $query */
    private function request(string $url, array $query, float $timeoutSeconds): Response
    {
        $timeoutSeconds = max(0.1, $timeoutSeconds);
        $response = Http::asJson()
            ->withHeaders($this->headers())
            ->timeout($timeoutSeconds)
            ->connectTimeout(min(1.0, $timeoutSeconds))
            ->get($url, $query);

        if ($response->status() === 404) {
            throw new SourceFetchNotFoundException('Spigot resource was not found.');
        }

        $response->throw();

        return $response;
    }

    /** @param array<string, mixed> $query */
    private function getJson(string $url, array $query, float $timeoutSeconds): array
    {
        $payload = $this->request($url, $query, $timeoutSeconds)->json();
        if (!is_array($payload)) {
            throw new Exception('Invalid Spigot API response.');
        }

        return $payload;
    }

    private function remainingTimeout(float $deadline): float
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0.0) {
            throw new Exception('Spigot source fetch exceeded its time budget.');
        }

        return max(0.1, $remaining);
    }

    /** @param array<int|string, mixed> $arguments */
    protected function spec(string $operation, array $arguments = []): SourceFetchSpec
    {
        return new SourceFetchSpec($this->getKey()->value, $operation, $arguments + ['schema' => self::CACHE_SCHEMA]);
    }

    private function projectSpec(?string $projectId): ?SourceFetchSpec
    {
        $projectId = $this->normalizeResourceId((string) $projectId);

        return $projectId !== null
            ? $this->spec(self::OPERATION_PROJECT, ['project_id' => $projectId])
            : null;
    }

    private function versionsSpec(string $projectId, bool $resolveDownloads): SourceFetchSpec
    {
        return $this->spec(self::OPERATION_VERSIONS, [
            'project_id' => $projectId,
            'resolve_downloads' => $resolveDownloads,
        ]);
    }
}
