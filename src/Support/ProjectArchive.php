<?php

namespace Kazaminosuke\ModManager\Support;

final class ProjectArchive
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
     * Validate that a project download URL uses HTTPS and does not target
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
