<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

use Illuminate\Container\Container;
use JsonException;
use Kazaminosuke\ModManager\Exceptions\ModpackException;

final class FtbReleaseManifest
{
    public const MAX_BYTES = 24 * 1024 * 1024;

    private const PROTECTED_ROOT_FILES = ['server.properties', 'eula.txt', 'ops.json', 'whitelist.json',
        'banned-players.json', 'banned-ips.json', 'usercache.json'];

    /** @var list<string> */
    private const SKIPPABLE_NON_SERVER_DIRS = [
        'shaderpacks', 'animation', 'simple-rpc', 'screenshots', 'packmenu', 'fancymenu_data', 'patchouli_books',
    ];

    public static function read(string $json, string $packId, string $releaseId): ModpackManifest
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw self::error('ftb_manifest_invalid');
        }

        try {
            $release = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::error('ftb_manifest_invalid');
        }

        if (!is_array($release) || !self::matchesId($release['parent'] ?? null, $packId)
            || !self::matchesId($release['id'] ?? null, $releaseId) || ($release['private'] ?? false) !== false
            || ($release['status'] ?? 'success') !== 'success'
            || !is_array($release['files'] ?? null) || !array_is_list($release['files'])) {
            throw self::error('ftb_manifest_invalid');
        }

        [$minecraft, $loader, $build, $java] = self::targets($release['targets'] ?? null);
        $files = $paths = $skipped = [];
        foreach ($release['files'] as $file) {
            if (!is_array($file) || !is_bool($file['clientonly'] ?? false)) {
                throw self::error('ftb_manifest_invalid');
            }
            if ($file['clientonly'] ?? false) {
                continue;
            }

            $path = self::path($file);
            $key = strtolower($path);
            if (in_array($key, self::PROTECTED_ROOT_FILES, true)) {
                if (isset($paths[$key])) {
                    throw self::error('ftb_file_unsupported', $path);
                }
                $paths[$key] = true;
                $skipped[] = $path;

                continue;
            }
            if (self::isSkippableNonServerFile($key)) {
                if (isset($paths[$key])) {
                    throw self::error('ftb_file_unsupported', $path);
                }
                $paths[$key] = true;
                $skipped[] = 'non-server:'.$path;

                continue;
            }

            $size = $file['size'] ?? null;
            $hashes = self::hashes($file);
            $urls = self::downloadUrls($file);
            if (!ModpackPaths::isManaged($path, 'world') || $urls === [] || !is_int($size)
                || $size < 0 || $size > 1024 ** 3 || $hashes === []) {
                throw self::error('ftb_file_unsupported', $path);
            }
            if (isset($paths[$key])) {
                $existing = $files[$key] ?? null;
                if ($existing === null || $existing->size !== $size || $existing->hashes !== $hashes) {
                    throw self::error('ftb_file_unsupported', $path);
                }
                $mergedFallbacks = array_values(array_unique(array_filter(
                    [...$existing->fallbackUrls, ...$urls],
                    static fn (string $url): bool => $url !== $existing->url,
                )));
                $files[$key] = new ModpackRemoteFile(
                    path: $existing->path,
                    url: $existing->url,
                    projectId: null,
                    fileId: null,
                    required: true,
                    size: $existing->size,
                    hashes: $existing->hashes,
                    fallbackUrls: $mergedFallbacks,
                );

                continue;
            }
            $paths[$key] = true;
            $files[$key] = new ModpackRemoteFile(
                path: $path,
                url: $urls[0],
                projectId: null,
                fileId: null,
                required: true,
                size: $size,
                hashes: $hashes,
                fallbackUrls: array_slice($urls, 1),
            );
        }
        $files = array_values($files);

        if ($files === [] || count($files) > ModpackManifest::MAX_REMOTE_FILES) {
            throw self::error('ftb_manifest_invalid');
        }

        return new ModpackManifest(ModpackManifest::FORMAT_FTB, '', is_string($release['name'] ?? null) ? $release['name'] : $releaseId, $minecraft, $loader, $build, $files, [], $skipped,
            minimumJava: $java);
    }

    /** Bind the selected contents while allowing the provider to refresh download URLs. */
    public static function fingerprint(ModpackManifest $manifest): string
    {
        $files = [];
        foreach ($manifest->remoteFiles as $file) {
            $hashes = $file->hashes;
            ksort($hashes);
            $files[$file->path] = [$file->size, $hashes, $file->required];
        }
        ksort($files);
        $skipped = $manifest->skipped;
        sort($skipped);

        return hash('sha256', json_encode([$manifest->minecraftVersion, $manifest->loader, $manifest->loaderVersion,
            $manifest->minimumJava, $files, $skipped], JSON_THROW_ON_ERROR));
    }

    private static function matchesId(mixed $actual, string $expected): bool
    {
        return (is_int($actual) || is_string($actual)) && (string) $actual === $expected;
    }

    private static function isSkippableNonServerFile(string $lowerPath): bool
    {
        if (ModpackPaths::isProtected($lowerPath)) {
            return false;
        }

        $parts = explode('/', $lowerPath);
        if (count($parts) >= 2) {
            $top = $parts[0];
            if (in_array($top, self::SKIPPABLE_NON_SERVER_DIRS, true)
                || ($top === 'local' && !str_starts_with($lowerPath, 'local/kubejs/'))) {
                return true;
            }

            if (in_array($top, ModpackPaths::MANAGED_ROOT_DIRECTORIES, true)) {
                foreach ($parts as $part) {
                    if (str_starts_with($part, '.')) {
                        return true;
                    }
                }
            }

            return false;
        }

        // Benign pack-shipped root files (docs, default templates, launcher scripts)
        // must not abort the pack, while root binaries (.jar/.exe/etc.) remain blocked.
        return preg_match('/\.(?:jar|zip|tar|gz|exe|bat|cmd|ps1|sh|dll|so|dylib)$/i', $lowerPath) !== 1
            || preg_match('/^(?:start|run|launch|server)[^\/]*\.(?:sh|bat|cmd|ps1)$/i', $lowerPath) === 1;
    }

    /** @return array<string, string> */
    private static function hashes(array $file): array
    {
        $hashes = [];
        if (is_array($file['hashes'] ?? null)) {
            foreach (['sha256' => 64, 'sha512' => 128, 'sha1' => 40] as $algo => $len) {
                $value = $file['hashes'][$algo] ?? null;
                if (is_string($value) && strlen($value) === $len && ctype_xdigit($value)) {
                    $hashes[$algo] = strtolower($value);
                }
            }
        }
        $sha1 = $file['sha1'] ?? null;
        if (is_string($sha1) && preg_match('/\A[0-9a-f]{40}\z/i', $sha1) === 1) {
            $hashes['sha1'] ??= strtolower($sha1);
        }

        return $hashes;
    }

    /** @return array{string, string, string, ?int} */
    private static function targets(mixed $targets): array
    {
        if (!is_array($targets) || !array_is_list($targets)) {
            throw self::error('ftb_runtime_unsupported');
        }

        $minecraft = $loader = $build = $java = null;
        foreach ($targets as $target) {
            if (!is_array($target) || !is_string($target['name'] ?? null) || !is_string($target['version'] ?? null)) {
                throw self::error('ftb_runtime_unsupported');
            }
            $name = strtolower($target['name']);
            $version = $target['version'];
            switch ($target['type'] ?? null) {
                case 'game':
                    if ($minecraft !== null || $name !== 'minecraft' || preg_match('/\A\d+\.\d+(?:\.\d+)?\z/', $version) !== 1) {
                        throw self::error('ftb_runtime_unsupported');
                    }
                    $minecraft = $version;
                    break;
                case 'modloader':
                    if ($loader !== null || ModpackLoader::normalize($name) === null
                        || preg_match('/\A\d[\w.+-]*\z/', $version) !== 1) {
                        throw self::error('ftb_runtime_unsupported');
                    }
                    $loader = ModpackLoader::normalize($name);
                    $build = $version;
                    break;
                case 'runtime':
                    if ($java !== null || $name !== 'java' || preg_match('/\A(?:1\.)?([1-9]\d?)(?:[._+-]\w+)*\z/', $version, $match) !== 1) {
                        throw self::error('ftb_runtime_unsupported');
                    }
                    $java = (int) $match[1];
                    break;
            }
        }
        if ($minecraft === null || $loader === null || $build === null) {
            throw self::error('ftb_runtime_unsupported');
        }

        return [$minecraft, $loader, $build, $java];
    }

    private static function path(array $file): string
    {
        $directory = $file['path'] ?? null;
        $name = $file['name'] ?? null;
        if (!is_string($directory) || !is_string($name) || $name === '' || basename($name) !== $name
            || str_contains($directory.$name, '\\') || str_starts_with($directory, '/') || preg_match('/[\x00-\x1f\x7f:]/', $directory.$name) === 1) {
            throw self::error('ftb_manifest_invalid');
        }
        $directory = str_starts_with($directory, './') ? substr($directory, 2) : $directory;
        $directory = $directory === '.' ? '' : rtrim($directory, '/');
        $path = $directory === '' ? $name : $directory.'/'.$name;
        if (ModpackPaths::safeRelative($path) !== $path) {
            throw self::error('ftb_manifest_invalid');
        }

        return $path;
    }

    /** @return list<string> */
    private static function downloadUrls(array $file): array
    {
        $urls = [];
        if (ModpackPaths::isSafeHttpsDownloadUrl($file['url'] ?? null)) {
            $urls[] = $file['url'];
        }
        foreach (is_array($file['mirrors'] ?? null) ? $file['mirrors'] : [] as $mirror) {
            if (ModpackPaths::isSafeHttpsDownloadUrl($mirror) && !in_array($mirror, $urls, true)) {
                $urls[] = $mirror;
            }
        }

        return $urls;
    }

    private static function error(string $key, string $path = ''): ModpackException
    {
        if (Container::getInstance()->bound('translator')) {
            return new ModpackException(trans('pelican-mod-manager::strings.modpacks.'.$key, ['path' => $path]));
        }

        return new ModpackException(match ($key) {
            'ftb_file_unsupported' => "The FTB release requires an unsupported file [$path].",
            default => 'The selected FTB release manifest is invalid.',
        });
    }
}
