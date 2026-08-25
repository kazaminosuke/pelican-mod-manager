<?php

namespace Kazaminosuke\ModManager\Sources;

use App\Models\Server;
use Exception;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Contracts\BatchLatestVersionSourceInterface;
use Kazaminosuke\ModManager\Contracts\ProjectMetadataPeekManyInterface;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchAuthoritativeInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchHandlerInterface;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\PartialSourceFetchException;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\CachedProjectMetadata;
use Kazaminosuke\ModManager\Support\CachedSearchOperations;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Kazaminosuke\ModManager\Support\ProjectIconUrl;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Throwable;

/**
 * Lightweight "direct tracking" source: unlike Modrinth/CurseForge/Hangar, GitHub
 * Releases has no searchable catalog, so a project is identified and added by its
 * "owner/repo" identifier rather than found via search().
 *
 * It also has no Minecraft-version/loader compatibility metadata, so every
 * non-draft release is returned as-is by getVersions() - the admin is responsible
 * for picking the right one, same as they would on the repo's Releases page.
 */
class GitHubReleasesSource implements BatchLatestVersionSourceInterface, ProjectMetadataPeekManyInterface, ProjectSourceInterface, SourceFetchAuthoritativeInterface, SourceFetchHandlerInterface
{
    protected const BASE_URL = 'https://api.github.com';

    protected const GRAPHQL_BATCH_SIZE = 20;

    protected const REST_POOL_SIZE = 4;

    private const OPERATION_LATEST = 'latest';

    private const OPERATION_PROJECT = 'project';

    private const OPERATION_RELEASES = 'releases';

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
        return ProjectSourceKey::GitHubReleases;
    }

    public function getLabel(): string
    {
        return 'GitHub Releases';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsProjectType(ProjectType $type): bool
    {
        return in_array($type, [ProjectType::Mod, ProjectType::Plugin], true);
    }

    public function supportsSearch(): bool
    {
        return false;
    }

    public function supportsHashLookup(): bool
    {
        // GitHub release assets do carry a sha256 `digest` field, but there is no
        // API to search *across* GitHub for a matching hash - you can only check
        // assets of a repo you already know. That's the reverse-lookup capability
        // findVersionsByHash() needs, so it isn't usable here.
        return false;
    }

    public function getHashAlgorithm(): ?string
    {
        return null;
    }

    public function fetchSourceData(SourceFetchSpec $spec, float $timeoutSeconds): mixed
    {
        if ($spec->sourceKey !== $this->getKey()->value) {
            throw new Exception("Invalid source key [{$spec->sourceKey}] for GitHub Releases.");
        }

        return match ($spec->operation) {
            self::OPERATION_LATEST => $this->fetchLatestReleases($spec, $timeoutSeconds),
            self::OPERATION_PROJECT => $this->fetchProject($spec, $timeoutSeconds),
            self::OPERATION_RELEASES => $this->fetchReleases($spec, $timeoutSeconds),
            default => throw new Exception("Unsupported GitHub Releases cache operation [{$spec->operation}]."),
        };
    }

    public function emptySourceData(SourceFetchSpec $spec): mixed
    {
        return match ($spec->operation) {
            self::OPERATION_LATEST => $this->emptyLatestReleases($spec),
            self::OPERATION_PROJECT => null,
            self::OPERATION_RELEASES => [],
            default => [],
        };
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function search(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): array
    {
        return $this->cachedSearch->search(null);
    }

    // search() never touches the cache (see supportsSearch(), which is
    // always false for this source) - so it always resolves instantly with
    // no I/O, and there is nothing a deferred round trip would be hiding.
    public function hasCachedSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool
    {
        return $this->cachedSearch->hasCached(null);
    }

    public function hasFreshCachedSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool
    {
        return $this->cachedSearch->hasFreshCached(null);
    }

    public function warmSearch(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): bool
    {
        return $this->cachedSearch->warm(null);
    }

    /** @return array<string, mixed>|null */
    public function getProject(string $projectId): ?array
    {
        return $this->resolveProjectByIdentifier($projectId);
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
        $repo = $this->parseIdentifier($projectId);

        if ($repo === null) {
            return [];
        }

        [$owner, $name] = array_map('strtolower', $repo);
        $versions = $this->sourceCache->swr(
            $this->spec(self::OPERATION_RELEASES, [
                'name' => $name,
                'owner' => $owner,
            ]),
            CacheProfile::InstalledLatest,
        );

        return is_array($versions) ? $versions : [];
    }

    /**
     * Resolve only the latest release containing a JAR. This deliberately uses
     * a cache separate from the full release history used by the modal.
     *
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

        if (!$this->supportsProjectType($type)) {
            return new LatestVersionLookupResult(unresolvedKeys: array_map(
                fn (LatestVersionLookupRequest $request): string => $request->key(),
                $requests,
            ));
        }

        [$requestsByRepository, $repositories, $unresolved] = $this->groupRequestsByRepository($requests);
        $versions = [];
        $failures = [];

        if ($repositories !== []) {
            ksort($repositories);
            $payload = $this->sourceCache->swr(
                $this->spec(self::OPERATION_LATEST, [
                    'repositories' => array_values($repositories),
                ]),
                CacheProfile::InstalledLatest,
            );
            $result = $this->distributeLatestPayload($requestsByRepository, $payload);
            $versions = $result->versions();
            $unresolved = array_merge($unresolved, $result->unresolvedKeys());
            $failures = $result->failures();
        }

        return new LatestVersionLookupResult(
            versionsByKey: $versions,
            unresolvedKeys: $unresolved,
            failuresByKey: $failures,
        );
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

        if (!$this->supportsProjectType($type)) {
            return new LatestVersionLookupResult(unresolvedKeys: array_map(
                fn (LatestVersionLookupRequest $request): string => $request->key(),
                $requests,
            ));
        }

        [$requestsByRepository, $repositories, $unresolved] = $this->groupRequestsByRepository($requests);

        if ($repositories === []) {
            return new LatestVersionLookupResult(unresolvedKeys: $unresolved);
        }

        ksort($repositories);
        $spec = $this->spec(self::OPERATION_LATEST, [
            'repositories' => array_values($repositories),
        ]);
        $peeked = $this->sourceCache->swrDeferred($spec, CacheProfile::InstalledLatest);

        if ($peeked['pending']) {
            $pendingKeys = array_map(
                fn (LatestVersionLookupRequest $request): string => $request->key(),
                array_merge(...array_values($requestsByRepository)),
            );

            return new LatestVersionLookupResult(unresolvedKeys: $unresolved, pendingKeys: $pendingKeys);
        }

        $result = $this->distributeLatestPayload($requestsByRepository, $peeked['data']);

        return $unresolved !== []
            ? $result->merge(new LatestVersionLookupResult(unresolvedKeys: $unresolved))
            : $result;
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     * @return array{
     *     0: array<string, array<int, LatestVersionLookupRequest>>,
     *     1: array<string, array{key: string, owner: string, name: string}>,
     *     2: array<int, string>
     * }
     */
    private function groupRequestsByRepository(array $requests): array
    {
        $requestsByRepository = [];
        $repositories = [];
        $unresolved = [];

        foreach ($requests as $request) {
            $parsed = $this->parseIdentifier($request->projectId);

            if ($parsed === null) {
                $unresolved[] = $request->key();

                continue;
            }

            [$owner, $name] = $parsed;
            $repositoryKey = strtolower("$owner/$name");
            $requestsByRepository[$repositoryKey][] = $request;
            $repositories[$repositoryKey] = [
                'key' => $repositoryKey,
                'owner' => strtolower($owner),
                'name' => strtolower($name),
            ];
        }

        return [$requestsByRepository, $repositories, $unresolved];
    }

    /**
     * @param array<string, array<int, LatestVersionLookupRequest>> $requestsByRepository
     */
    private function distributeLatestPayload(array $requestsByRepository, mixed $payload): LatestVersionLookupResult
    {
        $versionsByRepository = is_array($payload) && is_array($payload['versions'] ?? null)
            ? $payload['versions']
            : [];
        $unresolvedRepositories = is_array($payload) && is_array($payload['unresolved'] ?? null)
            ? array_fill_keys($payload['unresolved'], true)
            : [];
        $failuresByKey = is_array($payload) && is_array($payload['failures'] ?? null)
            ? $payload['failures']
            : [];
        $versions = [];
        $unresolved = [];
        $failures = [];

        foreach ($requestsByRepository as $repositoryKey => $repositoryRequests) {
            foreach ($repositoryRequests as $request) {
                if (is_array($versionsByRepository[$repositoryKey] ?? null)) {
                    $versions[$request->key()] = $versionsByRepository[$repositoryKey];
                } elseif (is_string($failuresByKey[$request->key()] ?? null) && $failuresByKey[$request->key()] !== '') {
                    $failures[$request->key()] = $failuresByKey[$request->key()];
                } elseif (isset($unresolvedRepositories[$repositoryKey]) || !isset($versionsByRepository[$repositoryKey])) {
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
     * @param array<string, string> $hashesByFilename
     * @return array<string, mixed>
     */
    public function findVersionsByHash(array $hashesByFilename): array
    {
        return [];
    }

    public function findVersionsByHashAuthoritatively(array $hashesByFilename): array
    {
        return [];
    }

    /** @return array<string, mixed>|null */
    public function resolveProjectByIdentifier(string $identifier): ?array
    {
        return $this->resolveProjectByIdentifierUsingCache($identifier, authoritative: false);
    }

    /** @return array<string, mixed>|null */
    protected function resolveProjectByIdentifierUsingCache(string $identifier, bool $authoritative): ?array
    {
        return $this->cachedProjectMetadata->get($this->projectSpec($identifier), $authoritative);
    }

    /** @return array<int, array<string, mixed>> */
    protected function fetchReleases(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        [$owner, $name] = $this->repositoryArguments($spec);
        $response = $this->getJson(
            "/repos/$owner/$name/releases",
            ['per_page' => 30],
            $timeoutSeconds,
        );

        return collect($response)
            ->filter(fn ($release) => is_array($release) && !($release['draft'] ?? false))
            ->map(fn (array $release) => $this->normalizeVersion($release))
            ->filter(fn ($version) => $version !== null)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function fetchProject(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        [$owner, $name] = $this->repositoryArguments($spec);
        $projectId = $owner.'/'.$name;
        $fetched = $this->fetchProjectsByIdsWithPool([$projectId], $timeoutSeconds);
        $project = $fetched[$projectId] ?? null;

        if (!is_array($project)) {
            throw new Exception("GitHub repository [$owner/$name] was not found.");
        }

        return $project;
    }

    /**
     * GitHub has no bulk repository endpoint for this metadata, so bound the
     * concurrent fallback the same way latest-release REST lookup already does.
     *
     * @param array<int, string> $projectIds
     * @return array<string, array<string, mixed>>
     */
    protected function fetchProjectsByIdsWithPool(array $projectIds, ?float $timeoutSeconds = null): array
    {
        $repositories = [];

        foreach ($projectIds as $projectId) {
            $projectId = (string) $projectId;
            $repo = $this->parseIdentifier($projectId);

            if ($repo === null) {
                continue;
            }

            [$owner, $name] = array_map('strtolower', $repo);
            $repositories[$projectId] = ['owner' => $owner, 'name' => $name];
        }

        if ($repositories === []) {
            return [];
        }

        $timeoutSeconds ??= CacheProfile::ProjectMetadata->backgroundTimeoutSeconds();
        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $resolved = [];
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        $token = $this->token();

        foreach (array_chunk($repositories, self::REST_POOL_SIZE, true) as $chunk) {
            try {
                $remaining = $this->remainingTimeout($deadline);
            } catch (Throwable $exception) {
                report($exception);

                break;
            }

            $aliases = [];

            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk, $remaining, $headers, $token, &$aliases) {
                    $poolRequests = [];

                    foreach ($chunk as $repositoryKey => $repository) {
                        $alias = 'github_project_'.sha1($repositoryKey);
                        $aliases[$alias] = $repositoryKey;
                        $request = $pool->as($alias)
                            ->asJson()
                            ->withHeaders($headers)
                            ->timeout($remaining)
                            ->connectTimeout(min(1.0, $remaining));

                        if ($token !== '') {
                            $request = $request->withToken($token);
                        }

                        $poolRequests[] = $request->get(
                            self::BASE_URL."/repos/{$repository['owner']}/{$repository['name']}",
                        );
                    }

                    return $poolRequests;
                });
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($aliases as $alias => $repositoryKey) {
                try {
                    $response = $responses[$alias] ?? null;

                    if (!$response instanceof Response || !$response->successful()) {
                        throw new Exception("GitHub repository [$repositoryKey] was not found.");
                    }

                    $payload = $response->json();

                    if (!is_array($payload) || !isset($payload['id'])) {
                        throw new Exception("GitHub repository [$repositoryKey] was not found.");
                    }

                    $resolved[$repositoryKey] = $this->normalizeProject($payload);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $resolved;
    }

    /**
     * @return array{versions: array<string, array<string, mixed>>, unresolved: array<int, string>, failures?: array<string, string>}
     */
    protected function fetchLatestReleases(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $repositories = $this->repositoriesArgument($spec);
        if ($repositories === []) {
            return ['versions' => [], 'unresolved' => []];
        }

        $requestsByRepository = [];
        foreach ($repositories as $repositoryKey => $repository) {
            $requestsByRepository[$repositoryKey] = [
                new LatestVersionLookupRequest(
                    source: $this->getKey()->value,
                    projectId: $repositoryKey,
                ),
            ];
        }

        $resolved = $this->token() !== ''
            ? $this->lookupLatestVersionsWithGraphQl($repositories, $requestsByRepository, $timeoutSeconds)
            : $this->lookupLatestVersionsWithRestPool($repositories, $requestsByRepository, $timeoutSeconds);

        $versions = [];
        $unresolved = [];
        $unresolvedKeys = array_fill_keys($resolved->unresolvedKeys(), true);

        foreach (array_keys($repositories) as $repositoryKey) {
            $lookupKey = $this->getKey()->value.':'.$repositoryKey;
            $version = $resolved->version($lookupKey);

            if (is_array($version)) {
                $versions[$repositoryKey] = $version;
            } elseif (isset($unresolvedKeys[$lookupKey]) || $version === null) {
                $unresolved[] = $repositoryKey;
            }
        }

        $result = ['versions' => $versions, 'unresolved' => $unresolved];

        if ($resolved->failures() !== []) {
            $result['failures'] = $resolved->failures();

            throw new PartialSourceFetchException(
                'GitHub latest-release batch completed with partial failures: '.implode('; ', $resolved->failures()),
                $result,
            );
        }

        return $result;
    }

    /** @return array{versions: array<never, never>, unresolved: array<int, string>} */
    protected function emptyLatestReleases(SourceFetchSpec $spec): array
    {
        return [
            'versions' => [],
            'unresolved' => array_keys($this->repositoriesArgument($spec)),
        ];
    }

    /** @return array{0: string, 1: string} */
    protected function repositoryArguments(SourceFetchSpec $spec): array
    {
        $owner = $spec->arguments['owner'] ?? null;
        $name = $spec->arguments['name'] ?? null;

        if (!is_string($owner) || $owner === '' || !is_string($name) || $name === '') {
            throw new Exception('Invalid GitHub repository parameters.');
        }

        return [$owner, $name];
    }

    /**
     * @return array<string, array{key: string, owner: string, name: string}>
     */
    protected function repositoriesArgument(SourceFetchSpec $spec): array
    {
        $repositories = $spec->arguments['repositories'] ?? null;
        if (!is_array($repositories)) {
            throw new Exception('Invalid GitHub latest-release parameters.');
        }

        $result = [];
        foreach ($repositories as $repository) {
            if (!is_array($repository)) {
                throw new Exception('Invalid GitHub latest-release repository.');
            }

            $key = $repository['key'] ?? null;
            $owner = $repository['owner'] ?? null;
            $name = $repository['name'] ?? null;
            if (!is_string($key) || $key === '' || !is_string($owner) || $owner === '' || !is_string($name) || $name === '') {
                throw new Exception('Invalid GitHub latest-release repository.');
            }

            $result[$key] = ['key' => $key, 'owner' => $owner, 'name' => $name];
        }

        ksort($result);

        return $result;
    }

    /** @return array{0: string, 1: string}|null [owner, repo] */
    protected function parseIdentifier(string $identifier): ?array
    {
        if (!preg_match('#^([\w.\-]+)/([\w.\-]+)$#', trim($identifier), $matches)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    /** @param array<string, mixed> $repo */
    protected function normalizeProject(array $repo): array
    {
        return [
            'project_id' => $repo['full_name'] ?? '',
            'slug' => $repo['full_name'] ?? '',
            'title' => $repo['name'] ?? '',
            'description' => $repo['description'] ?? '',
            'icon_url' => ProjectIconUrl::githubAvatar($repo['owner']['avatar_url'] ?? null),
            'author' => $repo['owner']['login'] ?? null,
            // GitHub doesn't expose a repo-level download counter.
            'downloads' => 0,
            'date_modified' => $repo['pushed_at'] ?? $repo['updated_at'] ?? null,
            'project_type' => '',
        ];
    }

    /**
     * @param array<string, mixed> $release
     * @return array<string, mixed>|null  null when the release has no .jar assets
     */
    protected function normalizeVersion(array $release): ?array
    {
        $assets = collect($release['assets'] ?? [])
            ->filter(fn ($asset) => is_array($asset) && str_ends_with(strtolower($asset['name'] ?? ''), '.jar'))
            ->values();

        if ($assets->isEmpty()) {
            return null;
        }

        $files = $assets->map(function (array $asset, int $index) {
            $hashes = [];
            $digest = $asset['digest'] ?? null;

            if (is_string($digest) && str_starts_with($digest, 'sha256:')) {
                $hashes['sha256'] = substr($digest, 7);
            }

            return [
                'primary' => $index === 0,
                'filename' => $asset['name'] ?? '',
                'url' => $asset['browser_download_url'] ?? null,
                'hashes' => $hashes,
            ];
        })->values()->all();

        $totalDownloads = collect($release['assets'] ?? [])->sum(fn ($asset) => is_array($asset) ? ($asset['download_count'] ?? 0) : 0);

        return [
            // The release tag is the update-detection identifier.
            'id' => (string) ($release['tag_name'] ?? $release['id'] ?? ''),
            'version_number' => $release['tag_name'] ?? ($release['name'] ?? ''),
            'version_type' => ($release['prerelease'] ?? false) ? 'beta' : 'release',
            'downloads' => (int) $totalDownloads,
            'date_published' => $release['published_at'] ?? $release['created_at'] ?? null,
            'changelog' => $release['body'] ?? null,
            'featured' => false,
            'files' => $files,
        ];
    }

    /**
     * @param array<string, array{key: string, owner: string, name: string}> $pending
     * @param array<string, array<int, LatestVersionLookupRequest>> $requestsByRepository
     */
    protected function lookupLatestVersionsWithGraphQl(
        array $pending,
        array $requestsByRepository,
        float $timeoutSeconds,
    ): LatestVersionLookupResult {
        $startedAt = (bool) config('pelican-mod-manager.debug_timing', false)
            ? microtime(true)
            : 0.0;
        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $versions = [];
        $unresolved = [];
        $failures = [];
        $requestCount = 0;
        $returnedProjects = 0;

        foreach (array_chunk($pending, self::GRAPHQL_BATCH_SIZE, true) as $chunk) {
            [$query, $variables, $aliases] = $this->buildGraphQlReleaseQuery($chunk);
            $requestCount++;

            try {
                $remaining = $this->remainingTimeout($deadline);
                $response = Http::asJson()
                    ->withHeaders([
                        'Accept' => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->withToken($this->token())
                    ->timeout($remaining)
                    ->connectTimeout(min(1.0, $remaining))
                    ->throw()
                    ->post(self::BASE_URL.'/graphql', [
                        'query' => $query,
                        'variables' => $variables,
                    ])
                    ->json();

                if (!is_array($response) || !is_array($response['data'] ?? null)) {
                    $message = is_array($response) ? ($response['errors'][0]['message'] ?? null) : null;

                    throw new Exception(is_string($message) ? $message : 'Invalid GitHub GraphQL response');
                }
            } catch (Throwable $exception) {
                report($exception);

                foreach (array_keys($chunk) as $repositoryKey) {
                    $this->recordFailure($failures, $requestsByRepository[$repositoryKey], $exception->getMessage());
                }

                continue;
            }

            $errorsByAlias = $this->graphQlErrorsByAlias($response['errors'] ?? []);

            foreach ($aliases as $alias => $repositoryKey) {
                if (isset($errorsByAlias[$alias])) {
                    $this->recordFailure($failures, $requestsByRepository[$repositoryKey], $errorsByAlias[$alias]);

                    continue;
                }

                $repository = $response['data'][$alias] ?? null;
                if (!is_array($repository)) {
                    $this->recordUnresolved($unresolved, $requestsByRepository[$repositoryKey]);

                    continue;
                }

                $version = $this->latestNormalizedGraphQlVersion($repository);
                if ($version === null) {
                    $this->recordUnresolved($unresolved, $requestsByRepository[$repositoryKey]);

                    continue;
                }

                $returnedProjects++;

                foreach ($requestsByRepository[$repositoryKey] as $request) {
                    $versions[$request->key()] = $version;
                }
            }
        }

        $this->logLatestVersionsTiming(
            'github_versions_graphql_request',
            '/graphql',
            $startedAt,
            count($pending),
            $returnedProjects,
            $requestCount,
            count($failures),
        );

        return new LatestVersionLookupResult(
            versionsByKey: $versions,
            unresolvedKeys: $unresolved,
            failuresByKey: $failures,

        );
    }

    /**
     * @param array<string, array{key: string, owner: string, name: string}> $pending
     * @param array<string, array<int, LatestVersionLookupRequest>> $requestsByRepository
     */
    protected function lookupLatestVersionsWithRestPool(
        array $pending,
        array $requestsByRepository,
        float $timeoutSeconds,
    ): LatestVersionLookupResult {
        $startedAt = (bool) config('pelican-mod-manager.debug_timing', false)
            ? microtime(true)
            : 0.0;
        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $versions = [];
        $unresolved = [];
        $failures = [];
        $requestCount = 0;
        $returnedProjects = 0;

        foreach (array_chunk($pending, self::REST_POOL_SIZE, true) as $chunk) {
            $aliases = [];

            try {
                $remaining = $this->remainingTimeout($deadline);
                $responses = Http::pool(function (Pool $pool) use ($chunk, $remaining, &$aliases) {
                    $poolRequests = [];

                    foreach ($chunk as $repositoryKey => $repository) {
                        $alias = 'github_'.sha1($repositoryKey);
                        $aliases[$alias] = $repositoryKey;
                        $poolRequests[] = $pool->as($alias)
                            ->asJson()
                            ->withHeaders([
                                'Accept' => 'application/vnd.github+json',
                                'X-GitHub-Api-Version' => '2022-11-28',
                            ])
                            ->timeout($remaining)
                            ->connectTimeout(min(1.0, $remaining))
                            ->get(self::BASE_URL."/repos/{$repository['owner']}/{$repository['name']}/releases", [
                                'per_page' => 30,
                            ]);
                    }

                    return $poolRequests;
                });
                $requestCount += count($chunk);
            } catch (Throwable $exception) {
                report($exception);

                foreach (array_keys($chunk) as $repositoryKey) {
                    $this->recordFailure($failures, $requestsByRepository[$repositoryKey], $exception->getMessage());
                }

                continue;
            }

            foreach ($aliases as $alias => $repositoryKey) {
                try {
                    $response = $responses[$alias] ?? null;

                    if (!$response instanceof Response || !$response->successful()) {
                        throw new Exception("GitHub releases lookup failed for repository $repositoryKey");
                    }

                    $payload = $response->json();
                    if (!is_array($payload)) {
                        throw new Exception("Invalid GitHub releases response for repository $repositoryKey");
                    }

                    $version = $this->latestNormalizedRestVersion($payload);
                } catch (Throwable $exception) {
                    report($exception);
                    $this->recordFailure($failures, $requestsByRepository[$repositoryKey], $exception->getMessage());

                    continue;
                }

                if ($version === null) {
                    $this->recordUnresolved($unresolved, $requestsByRepository[$repositoryKey]);

                    continue;
                }

                $returnedProjects++;

                foreach ($requestsByRepository[$repositoryKey] as $request) {
                    $versions[$request->key()] = $version;
                }
            }
        }

        $this->logLatestVersionsTiming(
            'github_versions_rest_parallel_request',
            '/repos/{owner}/{repo}/releases',
            $startedAt,
            count($pending),
            $returnedProjects,
            $requestCount,
            count($failures),
        );

        return new LatestVersionLookupResult(
            versionsByKey: $versions,
            unresolvedKeys: $unresolved,
            failuresByKey: $failures,

        );
    }

    /**
     * @param array<string, array{key: string, owner: string, name: string}> $repositories
     * @return array{0: string, 1: array<string, string>, 2: array<string, string>}
     */
    protected function buildGraphQlReleaseQuery(array $repositories): array
    {
        $definitions = [];
        $variables = [];
        $fields = [];
        $aliases = [];

        foreach (array_keys($repositories) as $index => $repositoryKey) {
            $repository = $repositories[$repositoryKey];
            $alias = "repository_$index";
            $ownerVariable = "owner_$index";
            $nameVariable = "name_$index";
            $definitions[] = '$'.$ownerVariable.': String!';
            $definitions[] = '$'.$nameVariable.': String!';
            $variables[$ownerVariable] = $repository['owner'];
            $variables[$nameVariable] = $repository['name'];
            $aliases[$alias] = $repositoryKey;
            $fields[] = $alias.': repository(owner: $'.$ownerVariable.', name: $'.$nameVariable.') {
                releases(first: 30, orderBy: {field: CREATED_AT, direction: DESC}) {
                    nodes {
                        databaseId
                        name
                        tagName
                        description
                        isDraft
                        isPrerelease
                        createdAt
                        publishedAt
                        releaseAssets(first: 100) {
                            nodes {
                                name
                                downloadUrl
                                downloadCount
                                digest
                            }
                        }
                    }
                }
            }';
        }

        return [
            'query LatestReleases('.implode(', ', $definitions).') { '.implode("\n", $fields).' }',
            $variables,
            $aliases,
        ];
    }

    /**
     * @param array<int, mixed> $errors
     * @return array<string, string>
     */
    protected function graphQlErrorsByAlias(array $errors): array
    {
        $messages = [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $alias = $error['path'][0] ?? null;
            if (is_string($alias)) {
                $messages[$alias] = (string) ($error['message'] ?? 'GitHub GraphQL lookup failed');
            }
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $repository
     * @return array<string, mixed>|null
     */
    protected function latestNormalizedGraphQlVersion(array $repository): ?array
    {
        foreach ($repository['releases']['nodes'] ?? [] as $release) {
            if (!is_array($release) || ($release['isDraft'] ?? false)) {
                continue;
            }

            $assets = [];
            foreach ($release['releaseAssets']['nodes'] ?? [] as $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $assets[] = [
                    'name' => $asset['name'] ?? '',
                    'browser_download_url' => $asset['downloadUrl'] ?? null,
                    'download_count' => $asset['downloadCount'] ?? 0,
                    'digest' => $asset['digest'] ?? null,
                ];
            }

            $normalized = $this->normalizeVersion([
                'id' => $release['databaseId'] ?? '',
                'tag_name' => $release['tagName'] ?? '',
                'name' => $release['name'] ?? '',
                'body' => $release['description'] ?? null,
                'draft' => $release['isDraft'] ?? false,
                'prerelease' => $release['isPrerelease'] ?? false,
                'created_at' => $release['createdAt'] ?? null,
                'published_at' => $release['publishedAt'] ?? null,
                'assets' => $assets,
            ]);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $releases
     * @return array<string, mixed>|null
     */
    protected function latestNormalizedRestVersion(array $releases): ?array
    {
        foreach ($releases as $release) {
            if (!is_array($release) || ($release['draft'] ?? false)) {
                continue;
            }

            $normalized = $this->normalizeVersion($release);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $unresolved
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    protected function recordUnresolved(array &$unresolved, array $requests): void
    {
        foreach ($requests as $request) {
            $unresolved[] = $request->key();
        }
    }

    /**
     * @param array<string, string> $failures
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    protected function recordFailure(array &$failures, array $requests, string $message): void
    {
        foreach ($requests as $request) {
            $failures[$request->key()] = $message;

        }
    }

    protected function logLatestVersionsTiming(
        string $stage,
        string $endpoint,
        float $startedAt,
        int $requestedProjectCount,
        int $returnedProjectCount,
        int $requestCount,
        int $failureCount,
    ): void {
        if (!(bool) config('pelican-mod-manager.debug_timing', false)) {
            return;
        }

        logger()->info('Mod manager timing', [
            'stage' => $stage,
            'request_id' => request()->attributes->get('mmr_timing_request_id'),
            'endpoint' => $endpoint,
            'started_after_ms' => $this->getModManagerTimingElapsedMs($startedAt),
            'finished_after_ms' => $this->getModManagerTimingElapsedMs(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'requested_project_count' => $requestedProjectCount,
            'returned_project_count' => $returnedProjectCount,
            'request_count' => $requestCount,
            'pool_size' => $stage === 'github_versions_rest_parallel_request' ? self::REST_POOL_SIZE : null,
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
        $request = Http::asJson()->withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ]);

        $token = $this->token();

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $timeoutSeconds = max(0.1, $timeoutSeconds);
        $response = $request
            ->timeout($timeoutSeconds)
            ->connectTimeout(min(1.0, $timeoutSeconds))
            ->throw()
            ->get(self::BASE_URL.$path, $query)
            ->json();

        if (!is_array($response)) {
            throw new Exception("Invalid GitHub response for [$path].");
        }

        return $response;
    }

    protected function remainingTimeout(float $deadline): float
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0.0) {
            throw new Exception('GitHub source fetch exceeded its time budget.');
        }

        return max(0.1, $remaining);
    }

    /** @param array<int|string, mixed> $arguments */
    protected function spec(string $operation, array $arguments = []): SourceFetchSpec
    {
        return new SourceFetchSpec($this->getKey()->value, $operation, $arguments);
    }

    private function projectSpec(string $projectId): ?SourceFetchSpec
    {
        $repo = $this->parseIdentifier($projectId);
        if ($repo === null) {
            return null;
        }

        [$owner, $name] = array_map('strtolower', $repo);

        return $this->spec(self::OPERATION_PROJECT, [
            'name' => $name,
            'owner' => $owner,
        ]);
    }

    protected function token(): string
    {
        return (string) config('pelican-mod-manager.github_token');
    }
}
