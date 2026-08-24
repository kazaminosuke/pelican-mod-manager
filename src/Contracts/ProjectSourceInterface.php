<?php

namespace Kazaminosuke\ModManager\Contracts;

use App\Models\Server;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;

/**
 * Contract implemented by every mod/plugin/datapack/resource-pack source
 * (Modrinth, CurseForge, Hangar, ...).
 *
 * Methods that depend on an optional capability (search, hash lookup, direct-identifier
 * resolution) must be guarded by the matching `supports*()` / `requiresApiKey()` /
 * `isConfigured()` check before being called. Unsupported calls should degrade gracefully
 * (empty array / null) rather than throw, except where noted otherwise.
 */
interface ProjectSourceInterface
{
    public function getKey(): ProjectSourceKey;

    public function getLabel(): string;

    public function requiresApiKey(): bool;

    public function isConfigured(): bool;

    public function supportsProjectType(ProjectType $type): bool;

    /**
     * Whether this source exposes a searchable catalog (browse/search UI).
     *
     * Sources without a catalog (e.g. GitHub Releases, which tracks a specific
     * "owner/repo" rather than browsing an index) return false here and instead
     * rely on `supportsDirectIdentifier()` / `resolveProjectByIdentifier()`.
     */
    public function supportsSearch(): bool;

    public function supportsHashLookup(): bool;

    /** @return string|null e.g. 'sha512', 'murmur2', or null when `supportsHashLookup()` is false */
    public function getHashAlgorithm(): ?string;

    /**
     * Whether a project can be tracked by a source-specific direct identifier
     * (e.g. "owner/repo" for GitHub Releases) instead of catalog search.
     */
    public function supportsDirectIdentifier(): bool;

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function search(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): array;

    /**
     * Whether search()'s cache entry for this exact (page, search, filters)
     * already exists - fresh or stale - without fetching or dispatching.
     *
     * Used by ModManagerPage::hasWarmRecordsCache() to decide whether the
     * catalog tab's deferred load can be skipped for the current request.
     * A request search() would resolve instantly with no cache lookup at
     * all (unsupported loader, unconfigured source, ...) counts as true
     * here too: there is nothing a deferred round trip would be hiding.
     */
    public function hasCachedSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool;

    /**
     * Whether search()'s cache entry is still within its fresh TTL.
     * Used to skip a Hangar after-response warm that would only repeat
     * an upstream call already paid for by this or another visitor.
     */
    public function hasFreshCachedSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool;

    /**
     * Populate search()'s cache using the background timeout rather than
     * the render-path 1.5s inline budget. A fresh entry is left untouched
     * so a WarmCatalogSearch job that lost the race to the visitor's own
     * search() does not repeat the upstream call. Returns whether a fetch
     * was attempted.
     */
    public function warmSearch(Server $server, ProjectType $type, int $page, ?string $search = null, array $filters = []): bool;

    /** @return array<string, mixed>|null normalized project data */
    public function getProject(string $projectId): ?array;

    /**
     * Non-blocking counterpart to getProject(): a fresh or stale cache hit
     * returns its data immediately; a miss queues a background refresh
     * (when the queue supports it) and comes back with pending true
     * instead of performing an inline fetch. Uses a per-project cache
     * entry, so one project's revalidation never invalidates another's.
     *
     * $dispatchOnMiss controls only whether THIS call queues that refresh
     * itself. Pass false when the caller is about to collect several
     * misses and batch them into one upstream call (see
     * ProjectSourceRegistry::peekInstalled() / Jobs\WarmProjectMetadata) -
     * a miss still comes back with pending true either way.
     *
     * retry_delayed is true only when a recent upstream failure has put this
     * entry into SourceCache's short retry cooldown. It is not a negative
     * cache hit, so callers should retain known installed metadata rather
     * than present the project as unavailable.
     *
     * @return array{data: array<string, mixed>|null, pending: bool, retry_delayed?: bool}
     */
    public function peekProject(string $projectId, bool $dispatchOnMiss = true): array;

    /**
     * Write already-fetched project data (typically from getProjectsByIds())
     * into this source's normal per-project cache entries - the same ones
     * getProject()/peekProject() read - without performing any fetch of
     * its own. Used to prime many entries at once after one batched (or,
     * for a source with no bulk endpoint, looped) upstream call, so a
     * later peekProject() for any of these ids is a hit instead of
     * queuing its own individual revalidation. See Jobs\WarmProjectMetadata.
     *
     * @param array<string, array<string, mixed>|null> $dataByProjectId
     */
    public function primeProjects(array $dataByProjectId): void;

    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed> [projectId => normalized project data]
     *
     * @throws \Exception implementations may throw on transport failure; callers performing
     *                     bulk lookups during scans are expected to catch and handle this
     */
    public function getProjectsByIds(array $projectIds): array;

    /** @return array<int, mixed> normalized versions, newest first */
    public function getVersions(string $projectId, Server $server, ProjectType $type): array;

    /**
     * @param array<string, string> $hashesByFilename [filename => hash]
     * @return array<string, mixed> [hash => normalized version data]
     */
    public function findVersionsByHash(array $hashesByFilename): array;

    /**
     * Resolve a project via a source-specific direct identifier
     * (e.g. "owner/repo" for GitHub Releases, or an ID/slug for catalog sources).
     *
     * @return array<string, mixed>|null normalized project data
     */
    public function resolveProjectByIdentifier(string $identifier): ?array;
}
