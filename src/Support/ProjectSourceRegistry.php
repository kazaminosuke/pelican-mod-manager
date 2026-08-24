<?php

namespace Kazaminosuke\ModManager\Support;

use App\Models\Server;
use Exception;
use Kazaminosuke\ModManager\Contracts\ProjectMetadataPeekManyInterface;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\WarmProjectMetadata;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\GitHubReleasesSource;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;

class ProjectSourceRegistry
{
    /** @var array<string, ProjectSourceInterface> */
    protected array $sources;

    public function __construct(
        ModrinthSource $modrinth,
        CurseForgeSource $curseForge,
        HangarSource $hangar,
        GitHubReleasesSource $githubReleases,
        protected readonly InstalledOperationManager $operations,
        protected readonly ServerModManagerSettings $settings,
    ) {
        $this->sources = [
            ProjectSourceKey::Modrinth->value => $modrinth,
            ProjectSourceKey::CurseForge->value => $curseForge,
            ProjectSourceKey::Hangar->value => $hangar,
            ProjectSourceKey::GitHubReleases->value => $githubReleases,
        ];
    }

    public function get(ProjectSourceKey $key): ?ProjectSourceInterface
    {
        return $this->sources[$key->value] ?? null;
    }

    public function getByValue(?string $key): ?ProjectSourceInterface
    {
        return $key !== null ? ($this->sources[$key] ?? null) : null;
    }

    /**
     * Sources enabled for this server, filtered to those supporting the given
     * project type.
     *
     * Source switches are explicit per-server settings. The source itself
     * remains responsible for its capability matrix via
     * supportsProjectType(); this class only combines that capability with the
     * server's source switch and configuration state.
     *
     * CurseForge additionally requires a configured API key. Do not expose a
     * catalog tab, filter choice, or hash-lookup candidate that cannot be
     * used; the other optional sources have no required catalog credential.
     *
     * @return array<int, ProjectSourceInterface>
     */
    public function availableFor(Server $server, ProjectType $type): array
    {
        $enabled = [];

        $curseForge = $this->sources[ProjectSourceKey::CurseForge->value];
        if ($this->settings->isSourceEnabled($server, ProjectSourceKey::CurseForge) && $curseForge->isConfigured()) {
            $enabled[] = $this->sources[ProjectSourceKey::CurseForge->value];
        }

        if ($this->settings->isSourceEnabled($server, ProjectSourceKey::Modrinth)) {
            $enabled[] = $this->sources[ProjectSourceKey::Modrinth->value];
        }

        if ($this->settings->isSourceEnabled($server, ProjectSourceKey::Hangar)) {
            $enabled[] = $this->sources[ProjectSourceKey::Hangar->value];
        }

        if ($this->settings->isSourceEnabled($server, ProjectSourceKey::GitHubReleases)) {
            $enabled[] = $this->sources[ProjectSourceKey::GitHubReleases->value];
        }

        return array_values(array_filter(
            $enabled,
            fn (ProjectSourceInterface $source) => $source->supportsProjectType($type)
        ));
    }

    /**
     * Hydrates installed-mod metadata entries (each tagged with a `source`,
     * per Phase 3) with live display data from each entry's actual source.
     * Blocks on whatever each source's getProject() takes to resolve, so
     * this is for synchronous/authoritative callers only - a render path
     * should use peekInstalled() instead. An entry whose source has no
     * live match (removed upstream, or an unimplemented/unrecognized
     * source) falls back to an "unavailable" placeholder built from the
     * stored metadata.
     *
     * @param  array<int, array<string, mixed>>  $installedMods
     * @return array<int, array<string, mixed>>
     */
    public function hydrateInstalled(array $installedMods, Server $server): array
    {
        $bySource = [];
        foreach ($installedMods as $mod) {
            $bySource[$mod['source'] ?? ProjectSourceKey::Modrinth->value][] = $mod;
        }

        $results = [];

        foreach ($bySource as $sourceKey => $mods) {
            $source = $this->getByValue($sourceKey);
            $projectsMap = $source ? $this->fetchProjectsMap($source, $mods) : [];

            foreach ($mods as $mod) {
                $project = $projectsMap[$mod['project_id']] ?? null;

                if ($project === null) {
                    $results[] = $this->unavailableEntry($mod, $sourceKey);

                    continue;
                }

                $project['project_id'] = $mod['project_id'];
                $project['source'] = $sourceKey;

                if (empty($project['author']) && !empty($mod['author'])) {
                    $project['author'] = $mod['author'];
                }

                $results[] = $project;
            }
        }

        return $results;
    }

    /**
     * Non-blocking counterpart to hydrateInstalled(), for the Installed
     * tab's render path. Never performs an upstream fetch: a project whose
     * cache entry is a miss comes back with `enrichment_pending` true and
     * only the fields already known from the installed-mod metadata
     * document filled in.
     *
     * Rather than letting each miss queue its own individual
     * ProjectSourceInterface::peekProject() revalidation, every source's
     * misses for this render pass are collected and handed to one
     * Jobs\WarmProjectMetadata dispatch per source - one batched (or, for
     * a source with no bulk endpoint, looped-but-single-job) upstream call
     * instead of up to one job per project. A large modpack's first cold
     * view this way costs one extra queued job per source, not one per
     * mod.
     *
     * @param  array<int, array<string, mixed>>  $installedMods
     * @return array<int, array<string, mixed>>
     */
    public function peekInstalled(array $installedMods, Server $server): array
    {
        $bySource = [];
        foreach ($installedMods as $mod) {
            $bySource[$mod['source'] ?? ProjectSourceKey::Modrinth->value][] = $mod;
        }

        $results = [];

        foreach ($bySource as $sourceKey => $mods) {
            $source = $this->getByValue($sourceKey);
            $peekedByProjectId = [];
            $missingIds = [];

            if ($source instanceof ProjectMetadataPeekManyInterface) {
                $projectIds = array_values(array_unique(array_filter(
                    array_column($mods, 'project_id'),
                    static fn (mixed $projectId): bool => is_string($projectId) && $projectId !== '',
                )));

                try {
                    $peekedByProjectId = $source->peekProjects($projectIds);
                } catch (Exception $exception) {
                    report($exception);
                    $peekedByProjectId = [];
                }
            }

            foreach ($mods as $mod) {
                $projectId = $mod['project_id'] ?? null;

                if ($source === null || !is_string($projectId) || $projectId === '') {
                    continue;
                }

                if (!array_key_exists($projectId, $peekedByProjectId)) {
                    try {
                        $peekedByProjectId[$projectId] = $source->peekProject($projectId, dispatchOnMiss: false);
                    } catch (Exception $exception) {
                        report($exception);
                        $peekedByProjectId[$projectId] = ['data' => null, 'pending' => true];
                    }
                }

                $peeked = $peekedByProjectId[$projectId];

                // `pending` differentiates a genuine cache miss from a
                // definitive negative cache hit (for example, a project that
                // was removed upstream or a source that is now unavailable).
                // Only cache misses need a warming job and enrichment poll.
                if ($peeked['data'] === null && ($peeked['pending'] ?? false)) {
                    $missingIds[] = $projectId;
                }
            }

            // A sync/null queue driver would run this inline, blocking the
            // very render path peekInstalled() exists to keep non-blocking
            // - see SourceCache::revalidateAsync(), which individual
            // peekProject() misses respect via the same check. Left
            // unthrottled here (unlike Jobs\WarmCatalogSearch/
            // WarmCatalogCacheCommand): this batch exists only because a
            // user is actively looking at their Installed tab, not as
            // speculative background warming.
            if ($source !== null && $missingIds !== [] && $this->operations->supportsAsyncDispatch()) {
                WarmProjectMetadata::dispatch($sourceKey, array_values(array_unique($missingIds)));
            }

            foreach ($mods as $mod) {
                $projectId = $mod['project_id'] ?? null;
                $peeked = is_string($projectId) ? ($peekedByProjectId[$projectId] ?? null) : null;

                if ($source === null || $peeked === null) {
                    $results[] = $this->unavailableEntry($mod, $sourceKey);

                    continue;
                }

                if ($peeked['data'] !== null) {
                    $project = $peeked['data'];
                    $project['project_id'] = $projectId;
                    $project['source'] = $sourceKey;

                    if (empty($project['author']) && !empty($mod['author'])) {
                        $project['author'] = $mod['author'];
                    }

                    $results[] = $project;

                    continue;
                }

                if ($peeked['pending'] ?? false) {
                    $results[] = $this->pendingEntry($mod, $sourceKey);

                    continue;
                }

                if ($peeked['retry_delayed'] ?? false) {
                    // The source-cache failure cooldown is not evidence that
                    // this installed project disappeared. Keep the locally
                    // known metadata visible, but do not keep the 500 ms
                    // enrichment poll or warm job alive until the cooldown
                    // expires.
                    $results[] = $this->metadataOnlyEntry($mod, $sourceKey);

                    continue;
                }

                $results[] = $this->unavailableEntry($mod, $sourceKey);
            }
        }

        return $results;
    }

    /**
     * @param  array<int, array<string, mixed>>  $mods
     * @return array<string, mixed>
     */
    protected function fetchProjectsMap(ProjectSourceInterface $source, array $mods): array
    {
        // One cache entry per project id (see ProjectSourceInterface::getProject())
        // rather than the previous 100-id chunked entry: installing or
        // updating one mod no longer invalidates every other project's
        // cached metadata by shifting which ids happen to share a chunk.
        // The trade-off is a request-per-miss instead of one bulk request
        // for a fully cold cache; nothing currently calls this method from
        // a latency-sensitive render path (see peekInstalled() for that).
        $projectIds = array_values(array_unique(array_column($mods, 'project_id')));
        $projectsMap = [];

        foreach ($projectIds as $projectId) {
            try {
                $project = $source->getProject($projectId);
            } catch (Exception $exception) {
                report($exception);
                $project = null;
            }

            if ($project !== null) {
                $projectsMap[$projectId] = $project;
            }
        }

        return $projectsMap;
    }

    /** @param array<string, mixed> $mod */
    protected function unavailableEntry(array $mod, string $sourceKey): array
    {
        return [
            'project_id' => $mod['project_id'] ?? '',
            'slug' => $mod['project_slug'] ?? '',
            'title' => $mod['project_title'] ?? '',
            'description' => trans('pelican-mod-manager::strings.page.mod_unavailable'),
            'icon_url' => null,
            'author' => $mod['author'] ?? '',
            'downloads' => 0,
            'date_modified' => $mod['installed_at'] ?? null,
            'project_type' => '',
            'source' => $sourceKey,
            'unavailable' => true,
        ];
    }

    /**
     * A metadata-document-only row for peekInstalled(): everything the
     * installed-mod metadata document already knows is real, but the
     * source-provided display fields (icon/downloads/date_modified) are
     * still being fetched in the background - distinct from
     * unavailableEntry(), which means the source has no live match at all.
     *
     * @param  array<string, mixed>  $mod
     */
    protected function pendingEntry(array $mod, string $sourceKey): array
    {
        $entry = $this->metadataOnlyEntry($mod, $sourceKey);
        $entry['enrichment_pending'] = true;

        return $entry;
    }

    /**
     * A metadata-document-only row for either a pending enrichment or a
     * short retry cooldown. The latter must not set enrichment_pending, since
     * no job can make progress until SourceCache's failure marker expires.
     *
     * @param  array<string, mixed>  $mod
     */
    protected function metadataOnlyEntry(array $mod, string $sourceKey): array
    {
        return [
            'project_id' => $mod['project_id'] ?? '',
            'slug' => $mod['project_slug'] ?? '',
            'title' => $mod['project_title'] ?? '',
            'description' => null,
            'icon_url' => null,
            'author' => $mod['author'] ?? '',
            'downloads' => null,
            'date_modified' => null,
            'project_type' => '',
            'source' => $sourceKey,
        ];
    }
}
