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

        if (in_array($base, [
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

    /** @return array{0: string, 1: string} directory, filename */
    public static function split(string $relativePath): array
    {
        $slash = strrpos($relativePath, '/');
        if ($slash === false) {
            return ['', $relativePath];
        }

        return [substr($relativePath, 0, $slash), substr($relativePath, $slash + 1)];
    }
}
