<?php

namespace Kazaminosuke\ModManager\Jobs;

use Illuminate\Container\Container;
use Kazaminosuke\ModManager\Contracts\AuthoritativeBatchProjectSourceInterface;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\SourceCache;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;

final class RevalidateSourceCache
{
    public int $uniqueFor = 300;

    public function __construct(
        public readonly SourceFetchSpec $spec,
        public readonly CacheProfile $profile,
    ) {}

    public function uniqueId(): string
    {
        return $this->spec->cacheKey();
    }

    public function handle(SourceCache $cache, ProjectSourceRegistry $registry): void
    {
        if (!$cache->revalidate($this->spec, $this->profile)) {
            return;
        }

        // A cold Modrinth/CurseForge metadata batch can have failed while an
        // Installed page was open. Its retry marker stops the page poll, but
        // this successful background revalidation only fills the batch key.
        // Re-run the lightweight warmer so it primes the individual project
        // keys the visible table reads, without another upstream request.
        if ($this->profile !== CacheProfile::ProjectMetadata || $this->spec->operation !== 'projects') {
            return;
        }

        $projectIds = $this->normalizedProjectIds();
        $source = $registry->getByValue($this->spec->sourceKey);

        if ($projectIds === [] || !$source instanceof AuthoritativeBatchProjectSourceInterface) {
            return;
        }

        $payload = [
            'source_key' => $this->spec->sourceKey,
            'project_ids' => $projectIds,
        ];
        $warm = new WarmProjectMetadata($this->spec->sourceKey, $projectIds);
        $container = Container::getInstance();

        if ($container->bound(PluginBackgroundRunner::class)) {
            $runner = $container->make(PluginBackgroundRunner::class);
            if ($runner->canSpawn()) {
                $runner->run(
                    BackgroundJob::WARM_PROJECT_METADATA,
                    $payload,
                    $warm->uniqueId(),
                    $warm->uniqueFor,
                );

                return;
            }
        }

        BackgroundJob::execute(BackgroundJob::WARM_PROJECT_METADATA, $payload);
    }

    /** @return array<int, string> */
    private function normalizedProjectIds(): array
    {
        $projectIds = $this->spec->arguments['project_ids'] ?? [];

        if (!is_array($projectIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $projectId): string => trim((string) $projectId), $projectIds),
            static fn (string $projectId): bool => $projectId !== '',
        )));
    }
}
