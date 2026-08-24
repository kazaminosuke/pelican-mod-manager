<?php

namespace Kazaminosuke\ModManager\Support;

use App\Models\Server;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;

/**
 * Generation-scoped, derived membership index for catalog row badges.
 *
 * The Wings metadata document remains the source of truth. A cold generation
 * is decoded once, then catalog requests read only their visible identities.
 * Installed-tab workflows continue to load the complete document.
 */
final class InstalledMetadataIndex
{
    private const CACHE_SECONDS = 3600;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @param array<int, string> $identities
     * @param Closure(): InstalledMetadataReadResult $load
     * @return array<string, array<string, mixed>>
     */
    public function getMany(
        Server $server,
        ProjectType $type,
        int $generation,
        array $identities,
        Closure $load,
    ): array {
        $identities = $this->normalizeIdentities($identities);
        if ($identities === []) {
            return [];
        }

        $prefix = $this->prefix($server, $type, $generation);
        if ($this->cache->get($prefix.':ready') === true) {
            return $this->readEntries($prefix, $identities);
        }

        $result = $load();
        if (!$result->isAuthoritative()) {
            return [];
        }

        $entries = $this->entriesByIdentity($result->document->installedMods());
        $this->publish($prefix, $entries);

        return array_intersect_key($entries, array_fill_keys($identities, true));
    }

    /**
     * Publish a document already loaded for another purpose, such as the
     * Installed tab, without reading Wings again.
     *
     * @param array<int, array<string, mixed>> $installedMods
     */
    public function prime(
        Server $server,
        ProjectType $type,
        int $generation,
        array $installedMods,
    ): void {
        $this->publish(
            $this->prefix($server, $type, $generation),
            $this->entriesByIdentity($installedMods),
        );
    }

    public static function identity(string|ProjectSourceKey $source, string $projectId): string
    {
        $source = $source instanceof ProjectSourceKey ? $source->value : $source;

        return $source.':'.$projectId;
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     */
    private function publish(string $prefix, array $entries): void
    {
        $values = [];
        foreach ($entries as $identity => $entry) {
            $values[$this->entryKey($prefix, $identity)] = $entry;
        }

        if ($values !== [] && !$this->cache->putMany($values, self::CACHE_SECONDS)) {
            return;
        }

        // The marker is deliberately last. Readers never observe a partially
        // published generation as complete.
        $this->cache->put($prefix.':ready', true, self::CACHE_SECONDS);
    }

    /**
     * @param array<int, string> $identities
     * @return array<string, array<string, mixed>>
     */
    private function readEntries(string $prefix, array $identities): array
    {
        $keysByIdentity = [];
        foreach ($identities as $identity) {
            $keysByIdentity[$identity] = $this->entryKey($prefix, $identity);
        }

        $cached = $this->cache->many(array_values($keysByIdentity));
        $entries = [];

        foreach ($keysByIdentity as $identity => $key) {
            if (is_array($cached[$key] ?? null)) {
                $entries[$identity] = $cached[$key];
            }
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $installedMods
     * @return array<string, array<string, mixed>>
     */
    private function entriesByIdentity(array $installedMods): array
    {
        $entries = [];

        foreach ($installedMods as $entry) {
            $projectId = $entry['project_id'] ?? null;
            $source = $entry['source'] ?? null;

            if (!is_string($projectId) || $projectId === ''
                || !is_string($source) || ProjectSourceKey::tryFrom($source) === null) {
                continue;
            }

            $entries[self::identity($source, $projectId)] = $entry;
        }

        return $entries;
    }

    /** @param array<int, string> $identities */
    private function normalizeIdentities(array $identities): array
    {
        return array_values(array_unique(array_filter(
            $identities,
            static fn (mixed $identity): bool => is_string($identity) && $identity !== '',
        )));
    }

    private function prefix(Server $server, ProjectType $type, int $generation): string
    {
        return "installed_metadata_index:v1:{$server->id}:{$type->value}:{$generation}";
    }

    private function entryKey(string $prefix, string $identity): string
    {
        return $prefix.':entry:'.hash('sha256', $identity);
    }
}
