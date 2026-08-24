<?php

namespace Kazaminosuke\ModManager\Jobs;

use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;
use Kazaminosuke\ModManager\Support\WarmRequestThrottle;
use Throwable;

/**
 * Warms one (source, project type, page) catalog search result so a later
 * visitor's request is a fresh-cache hit instead of a cold miss.
 *
 * The cache entry this populates is a pure function of (source, project
 * type, loader, Minecraft version, sort, page) - not server_id (see
 * ProjectSourceInterface::search() and its per-source SourceFetchSpec
 * construction) - so a single warm here serves every server that shares
 * that combination. $serverId only identifies a representative server to
 * resolve the loader/Minecraft version through search()'s normal
 * signature; it never becomes part of the cached data or its key.
 */
final class WarmCatalogSearch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    /**
     * Roughly the search fresh TTL (CacheProfile::Search, 10 minutes): once
     * a warm succeeds, a duplicate warm for the same combination is
     * redundant until the entry would need refreshing anyway.
     */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $serverId,
        public readonly string $sourceKey,
        public readonly string $projectType,
        public readonly int $page,
        public readonly string $loader,
        public readonly string $mcVersion,
        public readonly string $sort = 'downloads',
    ) {}

    /**
     * Deliberately excludes serverId: two different servers sharing the
     * same (source, type, loader, version, sort, page) must collapse into
     * one warm, since they'd both populate the exact same cache entry.
     */
    public function uniqueId(): string
    {
        return "warm_catalog:{$this->sourceKey}:{$this->projectType}:{$this->loader}:{$this->mcVersion}:{$this->sort}:{$this->page}";
    }

    public function handle(
        ProjectSourceRegistry $registry,
        WarmRequestThrottle $throttle,
        ?ServerModManagerSettings $settings = null,
    ): void
    {
        if (!$throttle->tryAcquire($this->sourceKey)) {
            // Skip rather than retry: nobody is waiting on this result, and
            // the next per-visit dispatch (deduplicated by uniqueId() once
            // it lands) or the next scheduled mod-manager:warm-catalog run
            // will pick this combination back up.
            return;
        }

        $server = Server::query()->find($this->serverId);

        if (!$server) {
            return;
        }

        $type = ProjectType::tryFrom($this->projectType);

        if (!$type) {
            return;
        }

        $settings ??= app(ServerModManagerSettings::class);
        $typeIsCurrent = match ($type) {
            ProjectType::Mod, ProjectType::Plugin => ProjectType::fromServer($server) === $type,
            ProjectType::Datapack => ProjectType::supportsDatapacks($server),
            ProjectType::ResourcePack => true,
        };

        if (!$typeIsCurrent || !$settings->isTypeEnabled($server, $type)) {
            return;
        }

        $currentLoader = $type->getLoaderSlug($server);
        if ($currentLoader === null && $type === ProjectType::ResourcePack) {
            $currentLoader = $type->value;
        }
        $currentVersion = MinecraftVersionResolver::resolve($server);

        // The unique key describes the snapshot selected by the scheduler.
        // If the representative server changed while this job waited, do not
        // warm a different cache entry under the stale unique lock; the next
        // scheduled/per-visit pass will enqueue the current combination.
        if ($currentLoader !== $this->loader || $currentVersion !== $this->mcVersion) {
            return;
        }

        $source = null;
        foreach ($registry->availableFor($server, $type) as $candidate) {
            if ($candidate->getKey()->value === $this->sourceKey) {
                $source = $candidate;

                break;
            }
        }

        if (!$source || !$source->supportsSearch()) {
            return;
        }

        try {
            $source->warmSearch($server, $type, $this->page, null, ['sort' => $this->sort]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
