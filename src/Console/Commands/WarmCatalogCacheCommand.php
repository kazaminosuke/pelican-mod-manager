<?php

namespace Kazaminosuke\ModManager\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\WarmCatalogSearch;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;

/**
 * Discovers the (loader, Minecraft version, project type) combinations
 * actually in use across every server and dispatches a WarmCatalogSearch
 * for each one's page 1, across every source that server has enabled. This is
 * what actually prevents a cold first visit to the catalog tab - the
 * per-visit dispatch in ModManagerPage::mount() only ever helps a later
 * visit, since it can't land before the request that triggered it finishes.
 *
 * Registered on the Laravel scheduler (see ModManagerServiceProvider::
 * boot()) rather than run ad hoc; can also be run manually
 * (php artisan mod-manager:warm-catalog), which is the quickest way to
 * warm the cache before a manual "clear cache, then load the page" check.
 */
final class WarmCatalogCacheCommand extends Command
{
    private const SERVER_CHUNK_SIZE = 250;

    /** @var array<string, array<string, Server>> */
    private array $sourceRepresentatives = [];

    protected $signature = 'mod-manager:warm-catalog';

    protected $description = 'Warm the mod-manager catalog cache for every (loader, Minecraft version, project type) combination actually in use.';

    public function handle(
        InstalledOperationManager $operations,
        ProjectSourceRegistry $registry,
        ServerModManagerSettings $settings,
    ): int {
        if (!(bool) config('pelican-mod-manager.warm_catalog_enabled', true)) {
            $this->comment('Catalog warming is disabled (pelican-mod-manager.warm_catalog_enabled).');

            return self::SUCCESS;
        }

        if (!$operations->supportsAsyncDispatch()) {
            $this->comment('Skipping: no async queue driver is configured. Dispatching warm jobs here would run them inline on the scheduler instead of in the background.');

            return self::SUCCESS;
        }

        $combos = $this->discoverCombos($settings);

        if ($combos === []) {
            $this->info('No server currently has a supported mod/plugin manager egg configured; nothing to warm.');

            return self::SUCCESS;
        }

        // Prioritize combinations used by more servers: each warm job
        // dispatched benefits more visitors that way. Mirrors GDLauncher-
        // Carbon's "priority = currently viewed instance" idea, adapted for
        // a scheduled, cross-server job with no single "currently viewed"
        // server to prioritize.
        usort($combos, static fn (array $left, array $right): int => $right['server_count'] <=> $left['server_count']);

        $maxTargets = max(0, (int) config('pelican-mod-manager.warm_max_targets', 50));
        $selected = array_slice($combos, 0, $maxTargets);
        $skipped = count($combos) - count($selected);

        $dispatched = 0;

        foreach ($selected as $combo) {
            $type = ProjectType::from($combo['project_type']);
            $comboKey = $this->comboKey($combo['loader'], $combo['mc_version'], $type);
            $representatives = $this->sourceRepresentatives[$comboKey] ?? [];
            $servers = [];

            foreach ($representatives as $server) {
                $servers[(int) $server->getKey()] = $server;
            }

            $available = [];
            foreach ($servers as $server) {
                foreach ($registry->availableFor($server, $type) as $source) {
                    $sourceKey = $source->getKey()->value;
                    if (isset($representatives[$sourceKey])) {
                        $available[$sourceKey] ??= [$source, $server];
                    }
                }
            }

            foreach ($available as [$source, $server]) {
                if (!$source->isConfigured() || !$source->supportsSearch()) {
                    continue;
                }

                if ($source->hasFreshCachedSearch($server, $type, 1, null, ['sort' => 'downloads'])) {
                    continue;
                }

                WarmCatalogSearch::dispatch(
                    $server->id,
                    $source->getKey()->value,
                    $type->value,
                    1,
                    $combo['loader'],
                    $combo['mc_version'],
                );
                $dispatched++;
            }
        }

        if ($skipped > 0) {
            $this->comment("Skipped {$skipped} lower-priority combination(s) past warm_max_targets ({$maxTargets}).");
        }

        $this->info(sprintf(
            'Dispatched %d catalog warm job(s) across %d combination(s).',
            $dispatched,
            count($selected),
        ));

        return self::SUCCESS;
    }

    /**
     * Loader and project type are resolved from the server's egg (including
     * Stage 8's UUID/name/signature profile fallback), while Minecraft
     * version is genuinely per-server. Eager-load the complete egg shape
     * plus its variable names: selecting only features/tags would make an
     * auto-detected official egg look unresolved to EggProfileResolver.
     *
     * It's read here via a direct query against server_variables joined to
     * egg_variables, rather than through Server::variables() (as
     * MinecraftVersionResolver::resolve() does): that relationship's join
     * closure captures $this->id from whichever single Server instance
     * first built it, so it cannot be eager-loaded correctly across many
     * servers at once - Server::with('variables') would silently scope
     * every row to one server's id instead of each row's own.
     *
     * Work is chunked so Server/Egg models and settings memoization remain
     * bounded on large Panels. Each chunk performs eager-load, settings, and
     * direct server-variable queries; selected representative Server objects
     * are retained in memory so handle() never re-queries them.
     *
     * @return array<int, array{loader: string, mc_version: string, project_type: string, server_id: int, server_count: int}>
     */
    protected function discoverCombos(?ServerModManagerSettings $settings = null): array
    {
        $settings ??= app(ServerModManagerSettings::class);
        $this->sourceRepresentatives = [];
        $combos = [];

        Server::query()
            ->with([
                'egg:id,uuid,name,update_url,features,tags',
                'egg.variables:id,egg_id,env_variable',
            ])
            ->select(['id', 'egg_id'])
            ->chunkById(self::SERVER_CHUNK_SIZE, function (Collection $servers) use ($settings, &$combos): void {
                $settings->preload($servers);

                try {
                    $mcVersionsByServerId = $this->minecraftVersionsFor($servers);
                    $defaultMcVersion = config('pelican-mod-manager.latest_minecraft_version');

                    foreach ($servers as $server) {
                        if (!$settings->hasAnyManagerTypeEnabled($server)) {
                            continue;
                        }

                        $profile = EggProfileResolver::resolve($server);
                        $mcVersion = $this->minecraftVersionFor(
                            $server,
                            $profile->minecraftVersionOverride,
                            $profile->minecraftVersionVariables,
                            $mcVersionsByServerId,
                            $defaultMcVersion,
                        );

                        if ($mcVersion === null) {
                            continue;
                        }

                        $types = [];
                        $primaryType = ProjectType::fromServer($server);
                        if ($primaryType !== null && $settings->isTypeEnabled($server, $primaryType)) {
                            $types[$primaryType->value] = $primaryType;
                        }
                        if ($settings->isTypeEnabled($server, ProjectType::Datapack)
                            && ProjectType::supportsDatapacks($server)) {
                            $types[ProjectType::Datapack->value] = ProjectType::Datapack;
                        }
                        if ($settings->isTypeEnabled($server, ProjectType::ResourcePack)) {
                            $types[ProjectType::ResourcePack->value] = ProjectType::ResourcePack;
                        }

                        foreach ($types as $type) {
                            $loader = $type->getLoaderSlug($server);
                            if ($loader === null && $type === ProjectType::ResourcePack) {
                                $loader = $type->value;
                            }
                            if ($loader === null) {
                                continue;
                            }

                            $key = $this->comboKey($loader, $mcVersion, $type);
                            $combos[$key] ??= [
                                'loader' => $loader,
                                'mc_version' => $mcVersion,
                                'project_type' => $type->value,
                                'server_id' => (int) $server->getKey(),
                                'server_count' => 0,
                            ];
                            $combos[$key]['server_count']++;

                            foreach (ProjectSourceKey::cases() as $sourceKey) {
                                if ($settings->isSourceEnabled($server, $sourceKey)) {
                                    $this->sourceRepresentatives[$key][$sourceKey->value] ??= $server;
                                }
                            }
                        }
                    }
                } finally {
                    $settings->clearRuntimeCache();
                    EggProfileResolver::clear();
                }
            }, 'servers.id', 'id');

        return array_values($combos);
    }

    /**
     * @param Collection<int, Server> $servers
     * @return array<int, array<string, string|null>>
     */
    private function minecraftVersionsFor(Collection $servers): array
    {
        /** @var Collection<int, object{server_id: int, env_variable: string, variable_value: string|null}> $rows */
        $rows = DB::table('server_variables')
            ->join('egg_variables', 'egg_variables.id', '=', 'server_variables.variable_id')
            ->whereIn('egg_variables.env_variable', ['MINECRAFT_VERSION', 'MC_VERSION', 'DL_VERSION', 'VANILLA_VERSION'])
            ->whereIn('server_variables.server_id', $servers->pluck('id'))
            ->get([
                'server_variables.server_id',
                'egg_variables.env_variable',
                'server_variables.variable_value',
            ]);

        $versions = [];
        foreach ($rows as $row) {
            $versions[(int) $row->server_id][$row->env_variable] = $row->variable_value;
        }

        return $versions;
    }

    /**
     * @param array<int, string> $profileVariables
     * @param array<int, array<string, string|null>> $valuesByServer
     */
    private function minecraftVersionFor(
        Server $server,
        ?string $profileOverride,
        array $profileVariables,
        array $valuesByServer,
        mixed $defaultVersion,
    ): ?string {
        $version = $profileOverride;

        if (!is_string($version) || $version === '') {
            $names = array_values(array_unique(array_merge(
                $profileVariables,
                ['MINECRAFT_VERSION', 'MC_VERSION'],
            )));

            foreach ($names as $name) {
                $candidate = $valuesByServer[(int) $server->getKey()][$name] ?? null;
                if (is_string($candidate) && $candidate !== '' && $candidate !== 'latest') {
                    $version = $candidate;

                    break;
                }
            }
        }

        if (!is_string($version) || $version === '' || $version === 'latest') {
            $version = $defaultVersion;
        }

        return is_string($version) && $version !== '' ? $version : null;
    }

    private function comboKey(string $loader, string $minecraftVersion, ProjectType $type): string
    {
        return "{$loader}:{$minecraftVersion}:{$type->value}";
    }
}
