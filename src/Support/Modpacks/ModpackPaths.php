<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

use Kazaminosuke\ModManager\Support\CurseForgeFile;
use Kazaminosuke\ModManager\Support\ProjectArchive;

/**
 * Relative paths inside a modpack, and the server files an install must
 * leave alone.
 */
final class ModpackPaths
{
    public static function safeRelative(string $path): ?string
    {
        return ProjectArchive::safeRelative($path);
    }

    /**
     * Worlds, operator files, panel metadata, and the egg's root server JAR
     * are never created or replaced by a modpack.
     */
    public static function isProtected(string $relativePath): bool
    {
        $path = strtolower($relativePath);
        $base = basename($path);

        if (str_starts_with($path, CurseForgeFile::STAGING_DIRECTORY.'/') || in_array($base, [
            '.pelican-mod-manager.json',
            '.pelican-mod-manager-resource-pack.json',
            'server.properties',
            'eula.txt',
            'ops.json',
            'whitelist.json',
            'banned-players.json',
            'banned-ips.json',
            'usercache.json',
        ], true) || str_starts_with($base, '.mod-manager-')) {
            return true;
        }

        foreach (['world/', 'world_nether/', 'world_the_end/', 'logs/', 'crash-reports/'] as $prefix) {
            $root = rtrim($prefix, '/');
            if ($path === $root || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return !str_contains($path, '/') && str_ends_with($path, '.jar');
    }

    /** @var list<string> */
    public const MANAGED_ROOT_DIRECTORIES = [
        'mods',
        'config',
        'configureddefaults',
        'defaultconfigs',
        'kubejs',
        'scripts',
        'groovy',
        'bansoukou',
        'datapacks',
        'global_packs',
        'openloader',
        'ftbteambases',
        'ftbteamdimensions',
        'schematics',
        'resourcepacks',
        'resources',
        'structures',
    ];

    /** A managed deployment may only change known pack directories. */
    public static function isManaged(string $path, string $world): bool
    {
        if (self::safeRelative($path) !== $path || preg_match('/[\x00-\x1f\x7f:]/', $path) === 1) {
            return false;
        }

        $path = strtolower($path);
        $world = strtolower($world);
        $parts = explode('/', $path);
        if (count($parts) < 2 || self::isProtected($path) || $path === $world || str_starts_with($path, $world.'/')) {
            return false;
        }
        foreach ($parts as $index => $part) {
            if (str_starts_with($part, '.') || in_array($part, ['level.dat', 'level.dat_old', 'session.lock', 'uid.dat'], true)) {
                return false;
            }
            if ($index > 0 && in_array($part, ['playerdata', 'players', 'region', 'entities', 'poi', 'advancements', 'stats', 'dimensions'], true)
                && !self::allowsWorldLikeSubfolder($parts[0], $part)) {
                return false;
            }
        }

        return in_array($parts[0], self::MANAGED_ROOT_DIRECTORIES, true)
            || str_starts_with($path, 'local/kubejs/');
    }

    private static function allowsWorldLikeSubfolder(string $root, string $segment): bool
    {
        if (in_array($root, ['ftbteambases', 'ftbteamdimensions'], true)
            && in_array($segment, ['region', 'entities', 'poi', 'dimensions'], true)) {
            return true;
        }

        return in_array($root, ['datapacks', 'global_packs', 'openloader', 'kubejs', 'resources', 'resourcepacks'], true)
            && in_array($segment, ['entities', 'advancements', 'dimensions', 'stats', 'poi'], true);
    }

    public static function isExecutablePackContent(string $path): bool
    {
        return preg_match('~^(?:mods|scripts|groovy|bansoukou|datapacks|global_packs|openloader|(?:local/)?kubejs/(?:server_scripts|startup_scripts|data))/~i', $path) === 1;
    }

    /** @return array{0: string, 1: string} directory, filename */
    public static function split(string $relativePath): array
    {
        $slash = strrpos($relativePath, '/');
        if ($slash === false) {
            return ['', $relativePath];
        }

        return [substr($relativePath, 0, $slash), substr($relativePath, $slash + 1)];
    }

    public static function isSafeHttpsDownloadUrl(mixed $url): bool
    {
        return ProjectArchive::isSafeHttpsDownloadUrl($url);
    }
}
