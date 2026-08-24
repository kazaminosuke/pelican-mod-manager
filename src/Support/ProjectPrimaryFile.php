<?php

namespace Kazaminosuke\ModManager\Support;

use Exception;

/**
 * Shared selector for a version's downloadable primary file.
 *
 * Source/cache payloads may contain a scalar `files` value or scalar
 * elements. Callers must not pass that payload to a typed `array` parameter.
 */
final class ProjectPrimaryFile
{
    /**
     * @return array<string, mixed>|null
     */
    public static function fromFiles(mixed $files): ?array
    {
        if (!is_array($files)) {
            return null;
        }

        $firstDownloadable = null;

        foreach ($files as $file) {
            if (!self::isDownloadable($file)) {
                continue;
            }

            if ($firstDownloadable === null) {
                $firstDownloadable = $file;
            }

            if (($file['primary'] ?? false) === true) {
                return $file;
            }
        }

        return $firstDownloadable;
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>|null
     */
    public static function fromVersion(array $version): ?array
    {
        return self::fromFiles($version['files'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireFromFiles(mixed $files): array
    {
        $file = self::fromFiles($files);

        if ($file === null) {
            throw new Exception('Latest version has no primary file.');
        }

        return $file;
    }

    /**
     * @param  array<string, mixed>  $file
     */
    public static function isDownloadable(mixed $file): bool
    {
        if (!is_array($file)) {
            return false;
        }

        $url = $file['url'] ?? null;
        $filename = $file['filename'] ?? null;

        return is_string($url) && $url !== '' && is_string($filename) && $filename !== '';
    }
}
