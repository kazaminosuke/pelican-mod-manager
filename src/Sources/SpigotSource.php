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
use Kazaminosuke\ModManager\Exceptions\DownloadUnavailableException;
use Kazaminosuke\ModManager\Exceptions\PartialSourceFetchException;
use Kazaminosuke\ModManager\Exceptions\SourceFetchNotFoundException;
use Kazaminosuke\ModManager\Support\ArchiveSignature;
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
 * Canonical per-resource metadata (Installed rows, identification, project
 * lookups) comes from the official Spigot Simple API. Catalog listings,
 * search, and version listings come from Spiget because those operations are
 * not exposed by the official API. The SpiGet CDN stores the resource's
 * current hosted file, so it is used only when the requested install is that
 * current version. An older version id keeps the official Spigot download
 * URL. Premium, buyer-only, and externally hosted files are never downloaded.
 */
class SpigotSource implements ArchiveMetadataIdentificationInterface, BatchLatestVersionSourceInterface, ProjectMetadataPeekManyInterface, ProjectSourceInterface, SourceFetchAuthoritativeInterface, SourceFetchHandlerInterface
{
    protected const OFFICIAL_API = 'https://api.spigotmc.org/simple/0.2/index.php';

    protected const SPIGET_API = 'https://api.spiget.org/v2';

    protected const SPIGET_MAX_AGE_SECONDS = 3600;

    protected const USER_AGENT = 'pelican-mod-manager (https://github.com/kazaminosuke/pelican-mod-manager)';

    protected const CATALOG_PAGE_SIZE = 20;

    protected const VERSION_PAGE_SIZE = 25;

    protected const VERSION_MAX_PAGES = 4;

    protected const LATEST_VERSION_POOL_SIZE = 4;

    /** The official API answers 429 to bursts of roughly 20 requests. */
    protected const PROJECT_POOL_SIZE = 4;

    /**
     * Part of every Spigot cache key. Bump it when a normalized payload
     * changes shape or meaning so entries written by an older release are
     * refetched instead of served for their remaining TTL.
     */
    private const CACHE_SCHEMA = 6;

    private const OPERATION_CURRENT = 'current';

    private const OPERATION_LATEST = 'latest';

    private const OPERATION_PROJECT = 'project';

    private const OPERATION_SEARCH = 'search';

    private const OPERATION_SPIGET_DETAIL = 'spiget_detail';

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
            self::OPERATION_CURRENT => $this->fetchCurrentInstallable($spec, $timeoutSeconds),
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
            self::OPERATION_CURRENT, self::OPERATION_VERSIONS => [],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{hits: array<int, array<string, mixed>>, total_hits: int}
     */
    public function search(Server $server, ProjectType $type, int $page = 1, ?string $search = null, array $filters = []): array
    {
        return $this->cachedSearch->search($this->buildSearchSpec($server, $type, $page, $search, $filters));
    }

    /** @param array<string, mixed> $filters */
    public function hasCachedSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool
    {
        return $this->cachedSearch->hasCached($this->buildSearchSpec($server, $type, $page, $search, $filters));
    }

    /** @param array<string, mixed> $filters */
    public function hasFreshCachedSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool
    {
        return $this->cachedSearch->hasFreshCached($this->buildSearchSpec($server, $type, $page, $search, $filters));
    }

    /** @param array<string, mixed> $filters */
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
        $resourceId = $this->normalizeResourceId($projectId);
        if ($type !== ProjectType::Plugin || $resourceId === null) {
            return [];
        }

        $versions = $this->sourceCache->swr(
            $this->versionsSpec($resourceId, resolveDownloads: true),
            CacheProfile::InstalledLatest,
        );

        return is_array($versions) ? $versions : [];
    }

    /**
     * The version an install or update can download.
     *
     * Built from the resource and /versions/latest. The file URL is the
     * official Spigot download for that version id. The historical version
     * list is not consulted, and Spiget's CDN is not used.
     *
     * @return array<int, mixed>
     */
    public function getVersionsAuthoritatively(string $projectId, Server $server, ProjectType $type): array
    {
        $resourceId = $this->normalizeResourceId($projectId);
        if ($type !== ProjectType::Plugin || $resourceId === null) {
            return [];
        }

        return $this->fetchCurrentInstallable(
            $this->currentSpec($resourceId),
            CacheProfile::InstalledLatest->backgroundTimeoutSeconds(),
        );
    }

    /**
     * Resolve and verify a specific version selected in the Versions modal at
     * click time. If the selected version matches the current latest release on
     * SpiGet, it is verified against the SpiGet CDN (and triggers requestUpdate
     * on a CDN miss). Older versions are verified directly against their
     * version-specific Spigot URL and never silently fall back to the latest
     * CDN JAR.
     *
     * @param  array<string, mixed>  $versionData
     * @return array<string, mixed>
     */
    public function resolveInstallableVersion(
        string $projectId,
        array $versionData,
        Server $server,
        ProjectType $type = ProjectType::Plugin,
    ): array {
        $resourceId = $this->normalizeResourceId($projectId);
        if ($type !== ProjectType::Plugin || $resourceId === null) {
            return $this->withoutDownloadUrl($versionData, DownloadUnavailableException::INVALID);
        }

        $selectedVersionId = trim((string) ($versionData['id'] ?? ''));
        if (preg_match('/^[1-9]\d*$/', $selectedVersionId) !== 1) {
            return $this->withoutDownloadUrl($versionData, DownloadUnavailableException::INVALID);
        }

        $timeoutSeconds = CacheProfile::InstalledLatest->backgroundTimeoutSeconds();
        $deadline = microtime(true) + max(0.1, $timeoutSeconds);

        try {
            $responses = $this->pool([
                'resource' => $this->spigetResourceUrl($resourceId),
                'latest' => $this->spigetResourceUrl($resourceId, '/versions/latest'),
            ], $this->remainingTimeout($deadline));
        } catch (Throwable $exception) {
            report($exception);

            return $this->withoutDownloadUrl($versionData, DownloadUnavailableException::FETCH_FAILED);
        }

        if ($this->isRateLimitedResponse($responses['resource'] ?? null)
            || $this->isRateLimitedResponse($responses['latest'] ?? null)) {
            return $this->withoutDownloadUrl($versionData, DownloadUnavailableException::RATE_LIMITED);
        }

        [$resource, $resourceFailed] = $this->decodeOptionalPoolJson($responses['resource'] ?? null);
        if (is_array($resource)) {
            $block = $this->downloadBlock($resource);
            if ($block !== null) {
                return $this->withoutDownloadUrl($versionData, $block);
            }
        }

        [$latest, $latestFailed] = $this->decodeOptionalPoolJson($responses['latest'] ?? null);
        if ($resourceFailed || $latestFailed) {
            return $this->withoutDownloadUrl($versionData, DownloadUnavailableException::FETCH_FAILED);
        }

        $latestVersionId = is_array($latest) ? trim((string) ($latest['id'] ?? '')) : '';
        if ($latestVersionId === '' && is_array($resource) && is_array($resource['version'] ?? null)) {
            $latestVersionId = trim((string) ($resource['version']['id'] ?? ''));
        }

        $versionNumber = trim((string) ($versionData['version_number'] ?? ''));
        if ($versionNumber === '' && is_array($latest) && $latestVersionId === $selectedVersionId) {
            $versionNumber = trim((string) ($latest['name'] ?? ''));
        }
        if ($versionNumber === '') {
            $versionNumber = $selectedVersionId;
        }

        $normalized = $this->normalizeSpigetVersion($resourceId, [
            'id' => $selectedVersionId,
            'name' => $versionNumber,
            'downloads' => $versionData['downloads'] ?? 0,
            'releaseDate' => $versionData['date_published'] ?? null,
        ], $resource);
        if ($normalized === null) {
            return $this->withoutDownloadUrl($versionData, DownloadUnavailableException::INVALID);
        }

        if ($latestVersionId !== '' && $selectedVersionId === $latestVersionId) {
            return $this->verifyAndAttachSpigetCdnDownload($normalized, $resourceId, $deadline);
        }

        return $this->verifyAndAttachHistoricalVersionDownload($normalized, $resourceId, $selectedVersionId, $deadline);
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function lookupLatestVersions(
        array $requests,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        [$requests, $spec] = $this->prepareLatestLookup($requests, $type);
        if ($spec === null) {
            return new LatestVersionLookupResult(unresolvedKeys: $this->latestRequestKeys($requests));
        }

        return $this->distributeLatestPayload($requests, $this->sourceCache->swr($spec, CacheProfile::InstalledLatest));
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function peekLatestVersions(
        array $requests,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        [$requests, $spec] = $this->prepareLatestLookup($requests, $type);
        if ($spec === null) {
            return new LatestVersionLookupResult(unresolvedKeys: $this->latestRequestKeys($requests));
        }

        $peeked = $this->sourceCache->swrDeferred($spec, CacheProfile::InstalledLatest);

        return $peeked['pending']
            ? new LatestVersionLookupResult(pendingKeys: $this->latestRequestKeys($requests))
            : $this->distributeLatestPayload($requests, $peeked['data']);
    }

    /**
     * Spigot has no hash lookup API. Only hashes remembered locally after an
     * install or a confirmed scan match are recognized.
     *
     * @param array<string, string> $hashesByFilename
     * @return array<string, mixed>
     */
    public function findVersionsByHash(array $hashesByFilename): array
    {
        $hashes = array_values(array_filter(
            $hashesByFilename,
            static fn (mixed $hash): bool => is_string($hash) && $hash !== '',
        ));
        if ($hashes === []) {
            return [];
        }

        $matched = [];
        foreach ($this->fileIndex->findManyBySha256($hashes) as $hash => $indexed) {
            $matched[$hash] = $this->identityVersion($indexed['resource_id'], $indexed['version_id'], $indexed['version_number']);
        }

        return $matched;
    }

    public function findVersionsByHashAuthoritatively(array $hashesByFilename): array
    {
        return $this->findVersionsByHash($hashesByFilename);
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

    /** @return array<int, string> */
    private function versionFilterValues(mixed $values): array
    {
        $values = is_array($values) ? $values : (is_string($values) && $values !== '' ? [$values] : []);

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $values),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
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
        $compactIds = [];
        foreach ($resources as $resource) {
            if (!is_array($resource)) {
                continue;
            }

            // Spiget search has no version filter, so apply it to the page.
            if ($query !== '' && $version !== '' && !$this->testedVersionsInclude($resource, $version)) {
                continue;
            }

            $normalized = $this->normalizeSpigetProject($resource);
            if ($normalized === null) {
                continue;
            }

            // /resources/for rows carry only id, name, and tested versions.
            if (!array_key_exists('downloads', $resource)) {
                $compactIds[] = $normalized['project_id'];
            }

            $hits[] = $normalized;
        }

        [$hits, $incompleteDetails] = $this->withSpigetDetails($hits, $compactIds, $deadline);
        $hits = $this->withCachedOfficialProjectOverlay($hits);

        $total = (int) $response->header('X-Total');
        if ($total < 1) {
            $pages = (int) $response->header('X-Page-Count');
            $total = $pages > 0 ? $pages * self::CATALOG_PAGE_SIZE : (($page - 1) * self::CATALOG_PAGE_SIZE) + count($hits);
        }

        $result = [
            'hits' => $hits,
            'total_hits' => $total,
        ];

        if ($incompleteDetails) {
            throw new PartialSourceFetchException(
                'Spigot catalog details were only partially resolved within the time budget.',
                $result,
            );
        }

        return $result;
    }

    /**
     * Fill compact catalog rows from Spiget's resource endpoint, which is
     * CDN-cached and serves a page of parallel requests. The official API
     * rate-limits bursts of that size, so it is kept for per-project
     * metadata. Resolved details are cached individually, and transient
     * detail failures signal PartialSourceFetchException so incomplete rows
     * are not cached as a fresh search hit.
     *
     * @param  array<int, array<string, mixed>>  $hits
     * @param  array<int, string>  $compactIds
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private function withSpigetDetails(array $hits, array $compactIds, float $deadline): array
    {
        if ($compactIds === []) {
            return [$hits, false];
        }

        $compactIds = array_values(array_unique($compactIds));
        $detailSpecs = [];
        foreach ($compactIds as $projectId) {
            $detailSpecs[$projectId] = $this->spigetDetailSpec($projectId);
        }

        $resolved = [];
        $missingIds = [];
        foreach ($this->sourceCache->peekMany($detailSpecs) as $projectId => $peeked) {
            if (is_array($peeked['data'])) {
                $resolved[(string) $projectId] = $peeked['data'];
            } else {
                $missingIds[] = (string) $projectId;
            }
        }

        $hadFailure = false;
        if ($missingIds !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.1) {
                $hadFailure = true;
            } else {
                $urls = [];
                foreach ($missingIds as $projectId) {
                    $urls[$projectId] = $this->spigetResourceUrl($projectId);
                }

                try {
                    $responses = $this->pool($urls, $remaining);
                } catch (Throwable $exception) {
                    report($exception);
                    $responses = [];
                    $hadFailure = true;
                }

                $toPrime = [];
                foreach ($missingIds as $projectId) {
                    $response = $responses[$projectId] ?? null;
                    if ($response instanceof Response && $response->successful()) {
                        $resource = $response->json();
                        if (is_array($resource) && $this->shouldExcludeFromCatalog($resource)) {
                            $excluded = ['excluded' => true];
                            $resolved[$projectId] = $excluded;
                            $toPrime[] = [
                                'spec' => $detailSpecs[$projectId],
                                'data' => $excluded,
                            ];

                            continue;
                        }

                        $normalized = is_array($resource) ? $this->normalizeSpigetProject($resource) : null;
                        if ($normalized !== null) {
                            $resolved[$projectId] = $normalized;
                            $toPrime[] = [
                                'spec' => $detailSpecs[$projectId],
                                'data' => $normalized,
                            ];

                            continue;
                        }
                    }

                    if ($response instanceof Response && $response->status() === 404) {
                        continue;
                    }

                    $hadFailure = true;
                }

                if ($toPrime !== []) {
                    $this->sourceCache->primeMany($toPrime, CacheProfile::Search);
                }
            }
        }

        $detailedHits = [];
        foreach ($hits as $hit) {
            $projectId = (string) ($hit['project_id'] ?? '');
            $detail = $resolved[$projectId] ?? null;
            if (is_array($detail) && ($detail['excluded'] ?? false) === true) {
                continue;
            }

            $detailedHits[] = $detail ?? $hit;
        }

        return [$detailedHits, $hadFailure];
    }

    /**
     * Overlay any already-cached official project metadata (such as author
     * username) without making upstream HTTP requests during catalog search.
     *
     * @param  array<int, array<string, mixed>>  $hits
     * @return array<int, array<string, mixed>>
     */
    private function withCachedOfficialProjectOverlay(array $hits): array
    {
        if ($hits === []) {
            return [];
        }

        $projectIds = array_values(array_unique(array_filter(array_map(
            static fn (array $hit): string => (string) ($hit['project_id'] ?? ''),
            $hits,
        ))));
        if ($projectIds === []) {
            return $hits;
        }

        $peeked = $this->cachedProjectMetadata->peekMany($projectIds, $this->projectSpec(...));

        return array_map(static function (array $hit) use ($peeked): array {
            $projectId = (string) ($hit['project_id'] ?? '');
            $official = $peeked[$projectId]['data'] ?? null;
            if (!is_array($official)) {
                return $hit;
            }

            if (($hit['author'] ?? null) === null && is_string($official['author'] ?? null) && $official['author'] !== '') {
                $hit['author'] = $official['author'];
            }
            if (($hit['downloads'] ?? null) === null && is_int($official['downloads'] ?? null)) {
                $hit['downloads'] = $official['downloads'];
            }
            if (($hit['date_modified'] ?? null) === null && is_string($official['date_modified'] ?? null)) {
                $hit['date_modified'] = $official['date_modified'];
            }

            return $hit;
        }, $hits);
    }

    /** @return array<string, mixed> */
    protected function fetchProject(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $projectId = $this->normalizeResourceId((string) ($spec->arguments['project_id'] ?? ''));
        if ($projectId === null) {
            throw new Exception('Invalid Spigot resource identifier.');
        }

        // A 404 becomes a definitive miss in request(); any other failure is
        // rethrown so the cache records an outage instead of a missing project.
        $project = $this->normalizeOfficialProject($this->getJson(self::OFFICIAL_API, [
            'action' => 'getResource',
            'id' => $projectId,
        ], $timeoutSeconds));

        if ($project === null) {
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
            $urls = [];
            foreach ($chunk as $projectId) {
                $urls[$projectId] = self::OFFICIAL_API.'?'.http_build_query(['action' => 'getResource', 'id' => $projectId]);
            }

            try {
                $remaining = $this->remainingTimeout($deadline);
            } catch (Throwable $exception) {
                report($exception);

                break;
            }

            try {
                $responses = $this->pool($urls, $remaining);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($chunk as $projectId) {
                try {
                    $normalized = $this->normalizeOfficialProject(
                        $this->poolJson($responses[$projectId] ?? null, $projectId),
                    );
                } catch (SourceFetchNotFoundException) {
                    // A definitive miss is left out of the batch map.
                    continue;
                } catch (Throwable $exception) {
                    report($exception);

                    continue;
                }

                if ($normalized !== null) {
                    $resolved[$projectId] = $normalized;
                }
            }
        }

        return $resolved;
    }

    /**
     * Current installable version. Premium and external resources are
     * returned without a URL so the caller rejects them.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchCurrentInstallable(SourceFetchSpec $spec, float $timeoutSeconds): array
    {
        $projectId = $this->normalizeResourceId((string) ($spec->arguments['project_id'] ?? ''));
        if ($projectId === null) {
            throw new Exception('Invalid Spigot versions parameters.');
        }

        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $responses = $this->pool([
            'resource' => $this->spigetResourceUrl($projectId),
            'latest' => $this->spigetResourceUrl($projectId, '/versions/latest'),
        ], $this->remainingTimeout($deadline));

        if ($this->isRateLimitedResponse($responses['resource'] ?? null)
            || $this->isRateLimitedResponse($responses['latest'] ?? null)) {
            return [$this->unavailableVersion($projectId, DownloadUnavailableException::RATE_LIMITED)];
        }

        [$resource, $resourceFailed] = $this->decodeOptionalPoolJson($responses['resource'] ?? null);
        [$latest, $latestFailed] = $this->decodeOptionalPoolJson($responses['latest'] ?? null);

        if (is_array($resource)) {
            $block = $this->downloadBlock($resource);
            if ($block !== null) {
                $version = is_array($latest) ? $this->normalizeSpigetVersion($projectId, $latest, $resource) : null;

                return [$version !== null
                    ? $this->withoutDownloadUrl($version, $block)
                    : $this->unavailableVersion($projectId, $block, $resource)];
            }
        }

        if ($resourceFailed || $latestFailed) {
            return [$this->unavailableVersion($projectId, DownloadUnavailableException::FETCH_FAILED, $resource)];
        }

        if (!is_array($latest) && is_array($resource) && is_array($resource['version'] ?? null)) {
            $fallbackId = trim((string) ($resource['version']['id'] ?? ''));
            if ($fallbackId !== '') {
                $latest = [
                    'id' => $fallbackId,
                    'name' => trim((string) ($resource['version']['name'] ?? '')) !== ''
                        ? (string) $resource['version']['name']
                        : $fallbackId,
                    'releaseDate' => $resource['updateDate'] ?? $resource['releaseDate'] ?? null,
                ];
            }
        }

        if (!is_array($latest)) {
            return [];
        }

        $version = $this->normalizeSpigetVersion($projectId, $latest, $resource);
        if ($version === null) {
            return [];
        }

        if ($this->officialDownloadUrl($projectId, (string) ($version['id'] ?? '')) === null) {
            return [$this->withoutDownloadUrl($version, DownloadUnavailableException::INVALID)];
        }

        return [$this->verifyAndAttachSpigetCdnDownload($version, $projectId, $deadline)];
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
        $versionsUrl = fn (int $page): string => $this->spigetResourceUrl($projectId, '/versions', [
            'size' => self::VERSION_PAGE_SIZE,
            'page' => $page,
            'sort' => '-releaseDate',
        ]);

        // The resource and the first version page are independent; one round
        // trip keeps a cold versions modal within the render budget.
        $responses = $this->pool([
            'resource' => $this->spigetResourceUrl($projectId),
            'versions' => $versionsUrl(1),
        ], $this->remainingTimeout($deadline));

        try {
            $resource = $this->poolJson($responses['resource'] ?? null, $projectId);
        } catch (Throwable) {
            $resource = null;
        }

        $firstPage = $this->poolJson($responses['versions'] ?? null, $projectId);
        $versions = [];

        for ($page = 1; $page <= self::VERSION_MAX_PAGES; $page++) {
            if ($page === 1) {
                $payload = $firstPage;
            } else {
                try {
                    $payload = $this->getJson($versionsUrl($page), [], $this->remainingTimeout($deadline));
                } catch (Throwable $exception) {
                    if ($versions !== []) {
                        report($exception);

                        break;
                    }

                    throw $exception;
                }
            }

            if ($payload === []) {
                break;
            }

            foreach ($payload as $version) {
                $normalized = is_array($version) ? $this->normalizeSpigetVersion($projectId, $version, $resource) : null;
                if ($normalized !== null) {
                    $versions[] = $resolveDownloads
                        ? $this->withModalDownloadCandidate($normalized)
                        : $this->withoutDownloadUrl($normalized);
                }
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
            $failures = array_replace($failures, $chunkFailures);

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
     * Resolve each resource's current version. Spiget's resource payload has
     * the premium/external flags but no version name, so it is paired with
     * /versions/latest in the same pool. The download URL is built from
     * The download URL is built from those ids. The CDN object is used only
     * because this call asked Spiget for /versions/latest.
     *
     * @param array<int, string> $projectIds
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>}
     */
    protected function fetchLatestVersionChunk(array $projectIds, float $timeoutSeconds): array
    {
        $deadline = microtime(true) + max(0.1, $timeoutSeconds);
        $urls = [];
        foreach ($projectIds as $projectId) {
            $urls["resource:$projectId"] = $this->spigetResourceUrl($projectId);
            $urls["latest:$projectId"] = $this->spigetResourceUrl($projectId, '/versions/latest');
        }

        try {
            $responses = $this->pool($urls, $this->remainingTimeout($deadline));
        } catch (Throwable $exception) {
            report($exception);

            return [[], array_fill_keys($projectIds, $exception->getMessage())];
        }

        $candidates = [];
        $failures = [];

        foreach ($projectIds as $projectId) {
            try {
                $resource = $this->poolJson($responses["resource:$projectId"] ?? null, $projectId);
                $latest = $this->poolJson($responses["latest:$projectId"] ?? null, $projectId);
            } catch (SourceFetchNotFoundException) {
                // A removed resource or one without versions stays unresolved.
                continue;
            } catch (Throwable $exception) {
                report($exception);
                $failures[$projectId] = $exception->getMessage();

                continue;
            }

            $version = $this->normalizeSpigetVersion($projectId, $latest, $resource);
            if ($version !== null) {
                $candidates[$projectId] = ['version' => $version, 'resource' => $resource];
            }
        }

        // The Installed tab offers an update for any resolved latest version,
        // so a premium or external file stays unresolved rather than
        // surfacing an update that cannot be installed.
        $resolved = [];
        foreach ($candidates as $projectId => $candidate) {
            $version = $this->withDownloadUrl($candidate['version'], $candidate['resource'], true);
            if ($version['files'] !== []) {
                $resolved[$projectId] = $version;
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
     * @param array<int, mixed> $requests
     * @return array{0: array<int, LatestVersionLookupRequest>, 1: ?SourceFetchSpec}
     */
    private function prepareLatestLookup(array $requests, ProjectType $type): array
    {
        $requests = array_values(array_filter(
            $requests,
            fn ($request): bool => $request instanceof LatestVersionLookupRequest,
        ));

        $projectIds = [];
        foreach ($type === ProjectType::Plugin ? $requests : [] as $request) {
            $projectId = $this->normalizeResourceId($request->projectId);
            if ($projectId !== null) {
                $projectIds[] = $projectId;
            }
        }

        $projectIds = array_values(array_unique($projectIds));
        sort($projectIds, SORT_STRING);

        return [
            $requests,
            $projectIds !== [] ? $this->spec(self::OPERATION_LATEST, ['project_ids' => $projectIds]) : null,
        ];
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     * @return array<int, string>
     */
    private function latestRequestKeys(array $requests): array
    {
        return array_map(fn (LatestVersionLookupRequest $request): string => $request->key(), $requests);
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    private function distributeLatestPayload(array $requests, mixed $payload): LatestVersionLookupResult
    {
        $requestsByProject = [];
        foreach ($requests as $request) {
            $requestsByProject[$request->projectId][] = $request;
        }

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

        return $versionId !== '' && $versionNumber !== ''
            ? $this->identityVersion($resourceId, $versionId, $versionNumber)
            : null;
    }

    /**
     * Version payload for an identity recorded locally, without upstream data.
     *
     * @return array<string, mixed>
     */
    private function identityVersion(string $resourceId, string $versionId, string $versionNumber): array
    {
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
     * Attach a download URL. The SpiGet CDN object is the resource's current
     * file, so it is used only for the version returned by /versions/latest.
     * An explicitly selected older version keeps the official Spigot URL.
     * Premium and external resources keep no file.
     *
     * @param  array<string, mixed>  $version
     * @param  array<string, mixed>|null  $resource
     * @return array<string, mixed>
     */
    private function withDownloadUrl(array $version, ?array $resource, bool $currentFile): array
    {
        if (is_array($resource) && !$this->isDirectlyDownloadable($resource)) {
            return $this->withoutDownloadUrl($version, $this->downloadBlock($resource));
        }

        $projectId = (string) ($version['project_id'] ?? '');
        $url = $this->officialDownloadUrl($projectId, (string) ($version['id'] ?? ''));
        if ($url === null) {
            return $this->withoutDownloadUrl($version, DownloadUnavailableException::INVALID);
        }

        $channel = 'spigot_official';
        if ($currentFile) {
            $cdn = $this->spigetCdnUrl($projectId, $resource);
            if ($cdn !== null) {
                $url = $cdn;
                $channel = 'spiget_cdn';
            }
        }

        unset($version['download_unavailable']);
        $version['files'][0]['url'] = $url;
        $version['files'][0]['download_channel'] = $channel;

        return $version;
    }

    /**
     * Keep a candidate file on every version in the Versions modal so the UI
     * button is never pre-disabled by missing or incomplete resource metadata.
     * Click-time verification validates the actual download before installing.
     *
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>
     */
    private function withModalDownloadCandidate(array $version): array
    {
        $projectId = (string) ($version['project_id'] ?? '');
        $url = $this->officialDownloadUrl($projectId, (string) ($version['id'] ?? ''));
        if ($url === null) {
            $url = 'https://www.spigotmc.org/resources/'.rawurlencode($projectId).'/download';
        }

        unset($version['download_unavailable']);
        $version['files'][0]['url'] = $url;
        $version['files'][0]['download_channel'] = 'spigot_official';

        return $version;
    }

    /**
     * @param  array<string, mixed>|null  $resource
     */
    private function spigetCdnUrl(string $projectId, ?array $resource = null): ?string
    {
        $resourceId = $this->normalizeResourceId($projectId);
        if ($resourceId === null) {
            return null;
        }

        if ($resource !== null && !$this->isDirectlyDownloadable($resource)) {
            return null;
        }

        return 'https://cdn.spiget.org/file/spiget-resources/'.$resourceId.'.jar';
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>
     */
    private function verifyAndAttachSpigetCdnDownload(array $version, string $resourceId, float $deadline): array
    {
        $cdnUrl = $this->spigetCdnUrl($resourceId);
        if ($cdnUrl === null) {
            return $this->withoutDownloadUrl($version, DownloadUnavailableException::INVALID);
        }

        $probe = $this->probeDownloadArchive($cdnUrl, $deadline);
        if ($probe === 'ok') {
            unset($version['download_unavailable']);
            $version['files'][0]['url'] = $cdnUrl;
            $version['files'][0]['download_channel'] = 'spiget_cdn';

            return $version;
        }

        if ($probe === 'not_found') {
            $updateResult = $this->requestSpigetUpdate($resourceId, $deadline);
            if ($updateResult === 'updated') {
                if ($this->probeDownloadArchive($cdnUrl, $deadline) === 'ok') {
                    unset($version['download_unavailable']);
                    $version['files'][0]['url'] = $cdnUrl;
                    $version['files'][0]['download_channel'] = 'spiget_cdn';

                    return $version;
                }

                return $this->withoutDownloadUrl($version, DownloadUnavailableException::UPDATE_REQUESTED);
            }

            if ($updateResult === 'queued') {
                return $this->withoutDownloadUrl($version, DownloadUnavailableException::UPDATE_REQUESTED);
            }

            if ($updateResult === 'rate_limited') {
                return $this->withoutDownloadUrl($version, DownloadUnavailableException::RATE_LIMITED);
            }

            return $this->withoutDownloadUrl($version, DownloadUnavailableException::FETCH_FAILED);
        }

        if ($probe === 'rate_limited') {
            return $this->withoutDownloadUrl($version, DownloadUnavailableException::RATE_LIMITED);
        }

        return $this->withoutDownloadUrl($version, DownloadUnavailableException::FETCH_FAILED);
    }

    /**
     * Verify a historical (non-latest) version's direct download without ever
     * substituting the unversioned SpiGet CDN latest JAR.
     *
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>
     */
    private function verifyAndAttachHistoricalVersionDownload(
        array $version,
        string $resourceId,
        string $versionId,
        float $deadline,
    ): array {
        $officialUrl = $this->officialDownloadUrl($resourceId, $versionId);
        if ($officialUrl === null) {
            return $this->withoutDownloadUrl($version, DownloadUnavailableException::INVALID);
        }

        $probe = $this->probeDownloadArchive($officialUrl, $deadline, allowRedirects: false);
        if ($probe === 'ok') {
            unset($version['download_unavailable']);
            $version['files'][0]['url'] = $officialUrl;
            $version['files'][0]['download_channel'] = 'spigot_official';

            return $version;
        }

        if ($probe === 'rate_limited') {
            return $this->withoutDownloadUrl($version, DownloadUnavailableException::RATE_LIMITED);
        }

        return $this->withoutDownloadUrl($version, DownloadUnavailableException::VERSION_UNAVAILABLE);
    }

    /**
     * Probe the first 4 bytes of a download URL to confirm a ZIP/JAR signature
     * and reject HTML error pages, Cloudflare challenges, or missing objects.
     *
     * @return 'ok'|'not_found'|'rate_limited'|'failed'
     */
    private function probeDownloadArchive(string $url, float $deadline, bool $allowRedirects = true): string
    {
        try {
            $timeoutSeconds = $this->remainingTimeout($deadline);
            $pending = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => '*/*',
                'Range' => 'bytes=0-3',
            ])
                ->timeout($timeoutSeconds)
                ->connectTimeout(min(1.0, $timeoutSeconds));

            if (!$allowRedirects) {
                $pending = $pending->withOptions(['allow_redirects' => false]);
            }

            $response = $pending->get($url);
        } catch (Throwable $exception) {
            report($exception);

            return 'failed';
        }

        if ($response->status() === 404) {
            return 'not_found';
        }

        if ($response->status() === 429) {
            return 'rate_limited';
        }

        if (!$response->successful()) {
            return 'failed';
        }

        return ArchiveSignature::isZip((string) $response->body()) ? 'ok' : 'failed';
    }

    /**
     * Ask SpiGet to crawl/cache the resource file when the CDN object is 404.
     *
     * @return 'updated'|'queued'|'rate_limited'|'failed'
     */
    private function requestSpigetUpdate(string $resourceId, float $deadline): string
    {
        try {
            $timeoutSeconds = $this->remainingTimeout($deadline);
            $response = Http::withHeaders($this->headers())
                ->timeout($timeoutSeconds)
                ->connectTimeout(min(1.0, $timeoutSeconds))
                ->post(self::SPIGET_API."/resources/{$resourceId}/requestUpdate", [
                    'versions' => false,
                    'updates' => false,
                    'reviews' => false,
                    'delete' => false,
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return 'failed';
        }

        if ($response->successful()) {
            return 'updated';
        }

        if ($response->status() === 400 && str_contains(strtolower((string) $response->body()), 'duplicate update request')) {
            return 'queued';
        }

        if ($response->status() === 429) {
            return 'rate_limited';
        }

        return 'failed';
    }

    private function isRateLimitedResponse(mixed $response): bool
    {
        return $response instanceof Response && $response->status() === 429;
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    private function decodeOptionalPoolJson(mixed $response): array
    {
        if ($response instanceof Throwable) {
            report($response);

            return [null, true];
        }

        if (!$response instanceof Response) {
            return [null, true];
        }

        if ($response->status() === 404) {
            return [null, false];
        }

        if (!$response->successful()) {
            return [null, true];
        }

        $payload = $response->json();

        return is_array($payload) ? [$payload, false] : [null, true];
    }

    /**
     * @param  array<string, mixed>|null  $resource
     * @return array<string, mixed>
     */
    private function unavailableVersion(string $projectId, string $reason, ?array $resource = null): array
    {
        $versionId = is_array($resource) && is_array($resource['version'] ?? null)
            ? trim((string) ($resource['version']['id'] ?? '0'))
            : '0';

        return $this->withoutDownloadUrl([
            'id' => $versionId !== '' ? $versionId : '0',
            'project_id' => $projectId,
            'version_number' => 'latest',
            'version_type' => 'release',
            'downloads' => 0,
            'date_published' => null,
            'changelog' => null,
            'featured' => false,
            'files' => [],
        ], $reason);
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>
     */
    private function withoutDownloadUrl(array $version, ?string $reason = null): array
    {
        $version['files'] = [];

        if (DownloadUnavailableException::isSupportedReason($reason)) {
            $version['download_unavailable'] = $reason;
        } else {
            unset($version['download_unavailable']);
        }

        return $version;
    }

    private function officialDownloadUrl(string $resourceId, string $versionId): ?string
    {
        $resourceId = $this->normalizeResourceId($resourceId);
        $versionId = trim($versionId);
        if ($resourceId === null || preg_match('/^[1-9]\d*$/', $versionId) !== 1) {
            return null;
        }

        return 'https://www.spigotmc.org/resources/'.$resourceId.'/download?version='.$versionId;
    }

    /**
     * Cloudflare serves Spiget's single-resource responses for weeks past
     * their one-hour max-age, which hides new releases from update checks
     * and installs. An hourly token keys the edge cache to that max-age.
     */
    /** @param array<string, scalar> $query */
    private function spigetResourceUrl(string $resourceId, string $path = '', array $query = []): string
    {
        return self::SPIGET_API."/resources/{$resourceId}{$path}?".http_build_query($query + [
            '_' => intdiv(time(), self::SPIGET_MAX_AGE_SECONDS) * self::SPIGET_MAX_AGE_SECONDS,
        ]);
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
        if ($projectId === null || $this->shouldExcludeFromCatalog($resource)) {
            return null;
        }

        $icon = $resource['icon']['url'] ?? null;
        if (is_string($icon) && $icon !== '' && !str_starts_with($icon, 'http')) {
            $icon = 'https://www.spigotmc.org/'.ltrim($icon, '/');
        }

        $downloads = $resource['downloads'] ?? null;
        $author = is_array($resource['author'] ?? null)
            ? ($resource['author']['name'] ?? $resource['author']['username'] ?? null)
            : null;

        return [
            'project_id' => $projectId,
            'slug' => $projectId,
            'title' => (string) ($resource['name'] ?? $projectId),
            'description' => CatalogFields::description($resource['tag'] ?? ''),
            'icon_url' => $this->stringOrNull($icon),
            'author' => $this->stringOrNull($author),
            'downloads' => is_numeric($downloads) ? (int) $downloads : null,
            'date_modified' => $this->timestampToIso($resource['updateDate'] ?? $resource['releaseDate'] ?? null),
            'project_type' => ProjectType::Plugin->value,
            'source' => ProjectSourceKey::Spigot->value,
        ];
    }

    /**
     * Confirmed premium resources, external distributions, and non-JAR
     * deliverables are omitted from catalog results. Incomplete metadata
     * remains visible without pre-disabling download buttons.
     *
     * @param  array<string, mixed>  $resource
     */
    private function shouldExcludeFromCatalog(array $resource): bool
    {
        return $this->isPremium($resource) || $this->isConfirmedExternal($resource) || $this->isConfirmedNonJar($resource);
    }

    /**
     * Why this resource must not receive an automatic file URL.
     *
     * Compact catalog rows omit the premium/external fields. Those stay
     * unmarked so the install action can resolve them instead of hiding a
     * download that might exist.
     *
     * @param  array<string, mixed>  $resource
     */
    private function downloadBlock(array $resource): ?string
    {
        if ($this->isPremium($resource)) {
            return DownloadUnavailableException::PREMIUM;
        }

        if ($this->isConfirmedExternal($resource)) {
            return DownloadUnavailableException::EXTERNAL;
        }

        if ($this->isConfirmedNonJar($resource)) {
            return DownloadUnavailableException::UNSUPPORTED_FILE;
        }

        return null;
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

        if (is_array($premium)) {
            $nestedPrice = $premium['price'] ?? 0;
            if (is_numeric($nestedPrice) && (float) $nestedPrice > 0) {
                return true;
            }
        }

        // SpiGet also publishes a top-level price. A positive price is premium
        // even when the boolean flag is missing or false.
        $price = $resource['price'] ?? 0;

        return is_numeric($price) && (float) $price > 0;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function isExternal(array $resource): bool
    {
        return $this->isConfirmedExternal($resource) || $this->isConfirmedNonJar($resource);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function isConfirmedExternal(array $resource): bool
    {
        $file = $resource['file'] ?? null;
        if (is_array($file)) {
            if (($file['external'] ?? false) === true) {
                return true;
            }

            $externalUrl = $file['externalUrl'] ?? null;
            if (is_string($externalUrl) && trim($externalUrl) !== '') {
                return true;
            }

            $type = strtolower(trim((string) ($file['type'] ?? '')));
            if ($type === 'external') {
                return true;
            }

            // A confirmed ".jar" without externalUrl/file.external is hosted on
            // Spigot/SpiGet even if the top-level external flag lags.
            if ($type !== '' && ltrim($type, '.') === 'jar') {
                return false;
            }
        }

        return ($resource['external'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function isConfirmedNonJar(array $resource): bool
    {
        $file = $resource['file'] ?? null;
        if (!is_array($file)) {
            return false;
        }

        $type = strtolower(trim((string) ($file['type'] ?? '')));
        if ($type === '' || $type === 'external') {
            return false;
        }

        return ltrim($type, '.') !== 'jar';
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

    /**
     * Send GET requests concurrently. A transport failure is returned in
     * place of its response, as Laravel's pool does.
     *
     * @param  array<string, string>  $urlsByAlias
     * @return array<string, Response|Throwable>
     */
    private function pool(array $urlsByAlias, float $timeoutSeconds, bool $followRedirects = true): array
    {
        if ($urlsByAlias === []) {
            return [];
        }

        $timeoutSeconds = max(0.1, $timeoutSeconds);
        $headers = $this->headers();

        return Http::pool(function (Pool $pool) use ($urlsByAlias, $timeoutSeconds, $headers, $followRedirects): array {
            $requests = [];
            foreach ($urlsByAlias as $alias => $url) {
                $request = $pool->as((string) $alias)
                    ->withHeaders($headers)
                    ->timeout($timeoutSeconds)
                    ->connectTimeout(min(1.0, $timeoutSeconds));

                if (!$followRedirects) {
                    $request = $request->withOptions(['allow_redirects' => false]);
                }

                $requests[] = $request->get($url);
            }

            return $requests;
        });
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SourceFetchNotFoundException when the resource does not exist
     */
    private function poolJson(mixed $response, string $projectId): array
    {
        if ($response instanceof Throwable) {
            throw $response;
        }

        if (!$response instanceof Response) {
            throw new Exception("No Spigot response for resource [$projectId].");
        }

        if ($response->status() === 404) {
            throw new SourceFetchNotFoundException("Spigot resource [$projectId] was not found.");
        }

        $payload = $response->throw()->json();
        if (!is_array($payload)) {
            throw new Exception("Invalid Spigot response for resource [$projectId].");
        }

        return $payload;
    }

    /** @param array<string, mixed> $query */
    private function request(string $url, array $query, float $timeoutSeconds): Response
    {
        $timeoutSeconds = max(0.1, $timeoutSeconds);
        $pending = Http::withHeaders($this->headers())
            ->timeout($timeoutSeconds)
            ->connectTimeout(min(1.0, $timeoutSeconds));
        // A query option replaces the URL's own query string.
        $response = $query === [] ? $pending->get($url) : $pending->get($url, $query);

        if ($response->status() === 404) {
            throw new SourceFetchNotFoundException('Spigot resource was not found.');
        }

        $response->throw();

        return $response;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
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

    private function currentSpec(string $projectId): SourceFetchSpec
    {
        return $this->spec(self::OPERATION_CURRENT, [
            'project_id' => $projectId,
        ]);
    }

    private function spigetDetailSpec(string $projectId): SourceFetchSpec
    {
        return $this->spec(self::OPERATION_SPIGET_DETAIL, [
            'project_id' => $projectId,
        ]);
    }
}
