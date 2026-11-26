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
