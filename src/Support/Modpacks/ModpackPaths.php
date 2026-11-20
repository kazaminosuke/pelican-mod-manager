<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

/**
 * Relative paths inside a modpack, and the server files an install must
 * leave alone.
 */
final class ModpackPaths
{
    /**
     * Normalize a manifest path. Null means the path is absolute, empty,
     * or would escape the server root.
     */
    public static function safeRelative(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * Worlds, operator files, panel metadata, and the egg's root server JAR
     * are never created or replaced by a modpack.
     */
    public static function isProtected(string $relativePath): bool
    {
        $path = strtolower($relativePath);
        $base = basename($path);

        if (str_starts_with($path, '.mod-manager-curseforge/') || in_array($base, [
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
        foreach ($parts as $part) {
            if (str_starts_with($part, '.') || in_array($part, ['level.dat', 'level.dat_old', 'session.lock',
                'uid.dat', 'playerdata', 'players', 'region', 'entities', 'poi', 'advancements', 'stats', 'dimensions'], true)) {
                return false;
            }
        }

        return in_array($parts[0], ['mods', 'config', 'defaultconfigs', 'kubejs', 'scripts', 'datapacks',
            'global_packs', 'openloader', 'resourcepacks', 'resources', 'structures'], true)
            || str_starts_with($path, 'local/kubejs/');
    }

    public static function isExecutablePackContent(string $path): bool
    {
        return preg_match('~^(?:mods|scripts|datapacks|global_packs|openloader|(?:local/)?kubejs/(?:server_scripts|startup_scripts|data))/~i', $path) === 1;
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

    /**
     * Validate that a modpack download URL uses HTTPS and does not target
     * loopback, private/reserved IP literals, local domains, or userinfo.
     */
    public static function isSafeHttpsDownloadUrl(mixed $url): bool
    {
        if (!is_string($url)
            || !str_starts_with($url, 'https://')
            || preg_match('/[\x00-\x1F\x7F]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' || $host === 'localhost') {
            return false;
        }

        foreach (['.localhost', '.local', '.internal', '.home.arpa'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return true;
    }
}
