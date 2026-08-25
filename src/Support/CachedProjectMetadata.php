<?php

namespace Kazaminosuke\ModManager\Support;

use RuntimeException;

/**
 * Shared per-project metadata cache mechanics.
 *
 * Sources retain responsibility for validating identifiers and constructing
 * provider-specific SourceFetchSpec instances. This object owns only the SWR,
 * authoritative-read, deferred-peek, batch-peek, priming, and retry-marker
 * lifecycle so those semantics remain identical between providers.
 */
final class CachedProjectMetadata
{
    public function __construct(
        private readonly SourceCache $cache,
    ) {}

    /** @return array<string, mixed>|null */
    public function get(?SourceFetchSpec $spec, bool $authoritative = false): ?array
    {
        if ($spec === null) {
            return null;
        }

        $project = $authoritative
            ? $this->cache->swrRequired($spec, CacheProfile::ProjectMetadata)
            : $this->cache->swr($spec, CacheProfile::ProjectMetadata);

        return is_array($project) ? $project : null;
    }

    /** @return array{data: array<string, mixed>|null, pending: bool, retry_delayed: bool} */
    public function peek(?SourceFetchSpec $spec, bool $dispatchOnMiss = true): array
    {
        if ($spec === null) {
            return $this->terminalMiss();
        }

        if (!$dispatchOnMiss) {
            return $this->normalizeProbe($this->cache->peek($spec));
        }

        $peeked = $this->cache->swrDeferred($spec, CacheProfile::ProjectMetadata);

        return [
            'data' => is_array($peeked['data']) ? $peeked['data'] : null,
            'pending' => $peeked['pending'],
            'retry_delayed' => $peeked['retry_delayed'],
        ];
    }

    /**
     * @param array<int, string|int> $projectIds
     * @param callable(string): ?SourceFetchSpec $specForProject
     * @return array<string, array{data: array<string, mixed>|null, pending: bool, retry_delayed: bool}>
     */
    public function peekMany(array $projectIds, callable $specForProject): array
    {
        $specs = [];
        $results = [];

        foreach (array_values(array_unique($projectIds)) as $projectId) {
            $projectId = (string) $projectId;
            $spec = $specForProject($projectId);

            if ($spec === null) {
                $results[$projectId] = $this->terminalMiss();

                continue;
            }

            $specs[$projectId] = $spec;
        }

        foreach ($this->cache->peekMany($specs) as $projectId => $peeked) {
            $results[$projectId] = $this->normalizeProbe($peeked);
        }

        return $results;
    }

    /**
     * @param array<string|int, mixed> $dataByProjectId
     * @param callable(string): ?SourceFetchSpec $specForProject
     */
    public function primeMany(array $dataByProjectId, callable $specForProject): void
    {
        $entries = [];

        foreach ($dataByProjectId as $projectId => $data) {
            $spec = $specForProject((string) $projectId);
            if ($spec === null) {
                continue;
            }

            $entries[] = ['spec' => $spec, 'data' => $data];
        }

        $this->cache->primeMany($entries, CacheProfile::ProjectMetadata);
    }

    /**
     * @param array<int, string|int> $projectIds
     * @param callable(string): ?SourceFetchSpec $specForProject
     */
    public function markRetryDelayedMany(array $projectIds, callable $specForProject): void
    {
        $specs = [];

        foreach (array_values(array_unique($projectIds)) as $projectId) {
            $projectId = trim((string) $projectId);
            if ($projectId === '') {
                continue;
            }

            $spec = $specForProject($projectId);
            if ($spec !== null) {
                $specs[] = $spec;
            }
        }

        $this->cache->markRetryDelayedMany($specs, CacheProfile::ProjectMetadata);
    }

    /** @return array<string, mixed> */
    public function getBatch(?SourceFetchSpec $spec, bool $authoritative, bool $freshRequired = false): array
    {
        if ($spec === null) {
            return [];
        }

        $projects = $freshRequired
            ? $this->cache->swrRequiredFresh($spec, CacheProfile::ProjectMetadata)
            : ($authoritative
                ? $this->cache->swrRequired($spec, CacheProfile::ProjectMetadata)
                : $this->cache->swr($spec, CacheProfile::ProjectMetadata));

        return is_array($projects) ? $projects : [];
    }

    /**
     * @param array<int, string|int> $projectIds
     * @param callable(string): ?SourceFetchSpec $specForProject
     * @param (callable(list<string>): array<string, mixed>)|null $fetchPending
     * @return array<string, mixed>
     */
    public function getMany(
        array $projectIds,
        callable $specForProject,
        bool $authoritative,
        ?callable $fetchPending = null,
    ): array {
        $projectIds = array_values(array_unique(array_map(
            static fn (mixed $projectId): string => (string) $projectId,
            $projectIds,
        )));

        if ($projectIds === [] || $fetchPending === null) {
            $projects = [];

            foreach ($projectIds as $projectId) {
                $project = $this->get($specForProject($projectId), $authoritative);

                if ($project !== null) {
                    $projects[$projectId] = $project;
                }
            }

            return $projects;
        }

        $peeked = $this->peekMany($projectIds, $specForProject);
        $projects = [];
        $pending = [];

        foreach ($projectIds as $projectId) {
            $probe = $peeked[$projectId] ?? $this->terminalMiss();

            if (is_array($probe['data'])) {
                $projects[$projectId] = $probe['data'];

                continue;
            }

            if ($probe['retry_delayed']) {
                if ($authoritative) {
                    throw new RuntimeException(
                        "Source project metadata [{$projectId}] is temporarily unavailable.",
                    );
                }

                continue;
            }

            if ($probe['pending']) {
                $pending[] = $projectId;
            }
        }

        if ($pending === []) {
            return $projects;
        }

        $fetched = $fetchPending($pending);

        if (!is_array($fetched)) {
            $fetched = [];
        }

        if ($fetched !== []) {
            $this->primeMany($fetched, $specForProject);
        }

        foreach ($pending as $projectId) {
            $project = $fetched[$projectId] ?? null;

            if (is_array($project)) {
                $projects[$projectId] = $project;
            }
        }

        return $projects;
    }

    /**
     * @param array{hit: bool, data: mixed, fresh: bool, retry_delayed: bool} $peeked
     * @return array{data: array<string, mixed>|null, pending: bool, retry_delayed: bool}
     */
    private function normalizeProbe(array $peeked): array
    {
        return [
            'data' => is_array($peeked['data']) ? $peeked['data'] : null,
            'pending' => !$peeked['hit'] && !$peeked['retry_delayed'],
            'retry_delayed' => $peeked['retry_delayed'],
        ];
    }

    /** @return array{data: null, pending: false, retry_delayed: false} */
    private function terminalMiss(): array
    {
        return ['data' => null, 'pending' => false, 'retry_delayed' => false];
    }
}
