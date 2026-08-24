<?php

namespace Kazaminosuke\ModManager\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Jobs\WarmCatalogSearch;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\EggProfileResolver;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;

/**
 * Discovers the (loader, Minecraft version, project type) combinations
 * actually in use across every server and dispatches a WarmCatalogSearch
 * for each one's page 1, across every source that egg has enabled. This is
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
    protected $signature = 'mod-manager:warm-catalog';

    protected $description = 'Warm the mod-manager catalog cache for every (loader, Minecraft version, project type) combination actually in use.';

    public function handle(
        InstalledOperationManager $operations,
        ProjectSourceRegistry $registry,
        ServerModManagerSettings $settings,
    ): int {
        if (!(bool) config('pelican-minecraft-modrinth.warm_catalog_enabled', true)) {
            $this->comment('Catalog warming is disabled (pelican-minecraft-modrinth.warm_catalog_enabled).');

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

        $maxTargets = max(0, (int) config('pelican-minecraft-modrinth.warm_max_targets', 50));
        $selected = array_slice($combos, 0, $maxTargets);
        $skipped = count($combos) - count($selected);

        $dispatched = 0;

        foreach ($selected as $combo) {
            /** @var Server|null $server */
            $server = Server::query()->find($combo['server_id']);

            if (!$server) {
                continue;
            }

            $type = ProjectType::from($combo['project_type']);

            foreach ($registry->availableFor($server, $type) as $source) {
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
     * Four total queries (servers+eggs, egg variable names, server settings,
     * then direct server-variable values) rather than one query per server.
     *
     * @return array<int, array{loader: string, mc_version: string, project_type: string, server_id: int, server_count: int}>
     */
    protected function discoverCombos(?ServerModManagerSettings $settings = null): array
    {
        $settings ??= app(ServerModManagerSettings::class);

        $servers = Server::query()
            ->with([
                'egg:id,uuid,name,update_url,features,tags',
                'egg.variables:id,egg_id,env_variable',
            ])
            ->get(['id', 'egg_id']);

        if ($servers->isEmpty()) {
            return [];
        }

        // The resolver checks settings several times per server. Prime its
        // request-local repository once so scheduled warming avoids a query
        // per server/type combination.
        $settings->preload($servers);

        /** @var Collection<int, object{server_id: int, env_variable: string, variable_value: string|null}> $serverVariableRows */
        $serverVariableRows = DB::table('server_variables')
            ->join('egg_variables', 'egg_variables.id', '=', 'server_variables.variable_id')
            ->whereIn('egg_variables.env_variable', ['MINECRAFT_VERSION', 'MC_VERSION', 'DL_VERSION', 'VANILLA_VERSION'])
            ->whereIn('server_variables.server_id', $servers->pluck('id'))
            ->get([
                'server_variables.server_id',
                'egg_variables.env_variable',
                'server_variables.variable_value',
            ]);

        /** @var array<int, array<string, string|null>> $mcVersionsByServerId */
        $mcVersionsByServerId = [];
        foreach ($serverVariableRows as $row) {
            $mcVersionsByServerId[(int) $row->server_id][$row->env_variable] = $row->variable_value;
        }

        $defaultMcVersion = config('pelican-minecraft-modrinth.latest_minecraft_version');
        $combos = [];

        foreach ($servers as $server) {
            // Do the cheap server/type gate before resolving an egg. A
            // completely disabled server must not spend time in Stage 8
            // detection just to discover that no page could be warmed.
            if (!$settings->hasAnyManagerTypeEnabled($server)) {
                continue;
            }

            $type = ProjectType::fromServer($server);

            if ($type === null || !$settings->isTypeEnabled($server, $type)) {
                continue;
            }

            $loader = $type->getLoaderSlug($server);

            if ($loader === null) {
                continue;
            }

            $profile = EggProfileResolver::resolve($server);
            $mcVersion = $profile->minecraftVersionOverride;

            if (!is_string($mcVersion) || $mcVersion === '') {
                $versionNames = array_values(array_unique(array_merge(
                    $profile->minecraftVersionVariables,
                    ['MINECRAFT_VERSION', 'MC_VERSION'],
                )));

                foreach ($versionNames as $name) {
                    $candidate = $mcVersionsByServerId[$server->id][$name] ?? null;
                    if (is_string($candidate) && $candidate !== '' && $candidate !== 'latest') {
                        $mcVersion = $candidate;

                        break;
                    }
                }
            }

            if (!is_string($mcVersion) || $mcVersion === '' || $mcVersion === 'latest') {
                $mcVersion = $defaultMcVersion;
            }

            if (!is_string($mcVersion) || $mcVersion === '') {
                continue;
            }

            $key = "$loader:$mcVersion:{$type->value}";

            $combos[$key] ??= [
                'loader' => $loader,
                'mc_version' => $mcVersion,
                'project_type' => $type->value,
                'server_id' => $server->id,
                'server_count' => 0,
            ];
            $combos[$key]['server_count']++;
        }

        return array_values($combos);
    }
}
