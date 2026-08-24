<?php

namespace Kazaminosuke\ModManager\Support;

use App\Models\Server;

/**
 * @see EggProfileResolver for the Stage 8 fallback this delegates to when
 *      the server's own MINECRAFT_VERSION/MC_VERSION variable is absent.
 */
class MinecraftVersionResolver
{
    /** @var array<int|string, string|null> */
    private static array $resolvedVersions = [];

    public static function resolve(Server $server): ?string
    {
        $key = $server->getKey();
        $serverKey = is_int($key) || is_string($key)
            ? (string) $key
            : 'object:'.spl_object_id($server);

        if (array_key_exists($serverKey, self::$resolvedVersions)) {
            return self::$resolvedVersions[$serverKey];
        }

        // A manually saved egg profile's explicit override (Stage 8's GUI
        // fallback) is the most direct signal available - an operator typed
        // this in because nothing else could tell. It wins over every other
        // source below, including the server's own variables.
        $profile = (bool) config('pelican-mod-manager.egg_autodetect_enabled', true)
            ? EggProfileResolver::resolve($server)
            : null;

        if ($profile?->minecraftVersionOverride !== null && $profile->minecraftVersionOverride !== '') {
            return self::$resolvedVersions[$serverKey] = $profile->minecraftVersionOverride;
        }

        // Stage 8: some egg families (Spigot's DL_VERSION, Vanilla's
        // VANILLA_VERSION, ...) never define MINECRAFT_VERSION/MC_VERSION
        // at all, so the generic lookup below silently fell through to the
        // global default for them - not a missing value, a wrong one. The
        // resolved profile's own variable name(s), when there is one, are
        // checked first for exactly that reason. Most egg families (Paper,
        // Fabric, Forge, ...) resolve to MINECRAFT_VERSION/MC_VERSION here
        // too, so this is a no-op change for them - same name, same query,
        // same answer. One query (not one per candidate name), then a plain
        // PHP scan in priority order picks the winner.
        $names = array_values(array_unique(array_merge($profile->minecraftVersionVariables ?? [], ['MINECRAFT_VERSION', 'MC_VERSION'])));

        $valuesByName = $server->variables()
            ->whereIn('env_variable', $names)
            ->pluck('server_value', 'env_variable');

        $version = null;
        foreach ($names as $name) {
            if (filled($valuesByName[$name] ?? null)) {
                $version = $valuesByName[$name];

                break;
            }
        }

        if (!$version || $version === 'latest') {
            $version = config('pelican-mod-manager.latest_minecraft_version');
        }

        return self::$resolvedVersions[$serverKey] = is_string($version) ? $version : null;
    }

    public static function clear(): void
    {
        self::$resolvedVersions = [];
    }
}
