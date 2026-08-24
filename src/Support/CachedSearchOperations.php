<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * Applies the shared search-cache lifecycle after a source has built its
 * provider-specific fetch specification.
 *
 * A null specification is a conclusive, immediately available empty result
 * (for example, an unsupported type or a source without catalog search).
 * Keeping that rule here ensures search(), the cache probes, and warming do
 * not drift apart across source implementations.
 */
final class CachedSearchOperations
{
    public function __construct(
        private readonly SourceCache $cache,
    ) {}

    /** @return array{hits: array<int, mixed>, total_hits: int}|array<string, mixed> */
    public function search(?SourceFetchSpec $spec): array
    {
        if ($spec === null) {
            return $this->emptyResult();
        }

        $result = $this->cache->swr($spec, CacheProfile::Search);

        return is_array($result) ? $result : $this->emptyResult();
    }

    public function hasCached(?SourceFetchSpec $spec): bool
    {
        return $spec === null || $this->cache->peek($spec)['hit'];
    }

    public function hasFreshCached(?SourceFetchSpec $spec): bool
    {
        return $spec === null || $this->cache->peek($spec)['fresh'];
    }

    public function warm(?SourceFetchSpec $spec): bool
    {
        if ($spec === null) {
            return false;
        }

        $peeked = $this->cache->peek($spec);
        if ($peeked['hit'] && $peeked['fresh']) {
            return false;
        }

        return $this->cache->revalidate($spec, CacheProfile::Search);
    }

    /** @return array{hits: array<int, mixed>, total_hits: int} */
    private function emptyResult(): array
    {
        return ['hits' => [], 'total_hits' => 0];
    }
}
