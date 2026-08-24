<?php

namespace Kazaminosuke\ModManager\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Kazaminosuke\ModManager\Contracts\AuthoritativeBatchProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\ProjectMetadataPeekManyInterface;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Throwable;

/**
 * Fills many projects' cache entries for one source from a single upstream
 * call, instead of each project's own SourceCache miss queuing its own
 * individual revalidation job (see ProjectSourceRegistry::peekInstalled(),
 * which collects a render pass's misses and dispatches this once per
 * source rather than once per project).
 *
 * getProjectsByIds() already fetches in bulk where the source actually has
 * a bulk endpoint (Modrinth, CurseForge) and loops individually where it
 * doesn't (Hangar, GitHub Releases) - either way, this reduces N queued
 * jobs down to one.
 *
 * Deliberately NOT throttled by WarmRequestThrottle: unlike
 * WarmCatalogSearch/WarmCatalogCacheCommand's speculative, nobody-may-be-
 * watching warming, this only ever runs because a user is actively
 * viewing their Installed tab right now (see peekInstalled()) - exactly
 * the "user-triggered" traffic the design calls out as staying
 * unthrottled, and the same treatment Stage 4's original per-project
 * dispatch already gave this work before this batching existed.
 */
final class WarmProjectMetadata implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * Short: this exact set of misses is only meaningful until the next
     * Installed-tab render collects a (likely different, as items resolve)
     * set. A stale duplicate-suppression window here would just delay a
     * legitimately new batch.
     */
    public int $uniqueFor = 120;

    /** @param array<int, string> $projectIds */
    public function __construct(
        public readonly string $sourceKey,
        public readonly array $projectIds,
    ) {}

    public function uniqueId(): string
    {
        $ids = $this->normalizedProjectIds();
        sort($ids);

        return "warm_project_metadata:{$this->sourceKey}:".hash('sha256', implode(',', $ids));
    }

    public function handle(ProjectSourceRegistry $registry): void
    {
        $projectIds = $this->normalizedProjectIds();

        if ($projectIds === []) {
            return;
        }

        $source = $registry->getByValue($this->sourceKey);

        if (!$source) {
            return;
        }

        // Configuration may have changed between the browser's miss and this
        // queued job. An unavailable source is not evidence that its projects
        // were removed, so retain the previous positive-only no-op behavior.
        if (!$source->isConfigured()) {
            return;
        }

        // Overlapping exact-set jobs can share some ids without sharing a
        // ShouldBeUnique key. Re-peek after leaving the queue so a preceding
        // job's completed entries (and retry-delayed failures) are removed
        // before any upstream call. Fully concurrent starts may still overlap,
        // but no already-visible result is fetched again later in the queue.
        if ($source instanceof ProjectMetadataPeekManyInterface) {
            $peeked = $source->peekProjects($projectIds);
            $projectIds = array_values(array_filter(
                $projectIds,
                static fn (string $projectId): bool => ($peeked[$projectId]['pending'] ?? false) === true,
            ));

            if ($projectIds === []) {
                return;
            }
        }

        try {
            if ($source instanceof AuthoritativeBatchProjectSourceInterface) {
                // Only the sources with a real batch endpoint make a missing
                // id conclusive. Their fresh-only method prevents stale batch
                // cache data from becoming a newly fresh negative entry.
                $map = $source->getProjectsByIdsForMetadataWarm($projectIds);
                $source->primeProjects($this->includeConfirmedMissingProjects($projectIds, $map));

                return;
            }

            $map = $source->getProjectsByIds($projectIds);
        } catch (Throwable $exception) {
            report($exception);

            // Modrinth and CurseForge warm several individual metadata keys
            // through one authoritative batch cache key. The batch-level
            // failure marker alone is invisible to the Installed render path,
            // which reads those individual keys; fan its short retry cooldown
            // out without treating the projects as definitively missing.
            if ($source instanceof AuthoritativeBatchProjectSourceInterface) {
                $source->deferProjectMetadataRetries($projectIds);
            }

            return;
        }

        if ($map !== []) {
            $source->primeProjects($map);
        }
    }

    /** @return array<int, string> */
    private function normalizedProjectIds(): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $projectId): string => trim((string) $projectId), $this->projectIds),
            static fn (string $projectId): bool => $projectId !== '',
        )));
    }

    /**
     * @param array<int, string> $projectIds
     * @param array<string, mixed> $projects
     * @return array<string, array<string, mixed>|null>
     */
    private function includeConfirmedMissingProjects(array $projectIds, array $projects): array
    {
        $primed = [];

        foreach ($projectIds as $projectId) {
            if (array_key_exists($projectId, $projects)) {
                $primed[$projectId] = $projects[$projectId];

                continue;
            }

            // CurseForge canonicalizes numeric ids for its batch endpoint;
            // retain the id used by the per-project cache spec on the left.
            $canonicalNumericId = ctype_digit($projectId) ? (string) (int) $projectId : null;
            $primed[$projectId] = $canonicalNumericId !== null && array_key_exists($canonicalNumericId, $projects)
                ? $projects[$canonicalNumericId]
                : null;
        }

        return $primed;
    }
}
