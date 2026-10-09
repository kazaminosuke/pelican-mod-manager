<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

/**
 * Picks the pack archive to download. CurseForge prefers a server pack so
 * client-only mods are not sent to the dedicated server.
 */
final class ModpackFileSelector
{
    /**
     * @param  list<mixed>  $versions  Modrinth version payloads, newest first
     * @return array{version_id: string, version_number: string, filename: string, url: string}|null
     */
    public static function modrinthMrpack(array $versions): ?array
    {
        foreach ($versions as $version) {
            if (!is_array($version)) {
                continue;
            }

            $versionId = trim((string) ($version['id'] ?? ''));
            $versionNumber = trim((string) ($version['version_number'] ?? ''));
            if ($versionId === '' || $versionNumber === '' || !is_array($version['files'] ?? null)) {
                continue;
            }

            $fallback = null;
            foreach ($version['files'] as $file) {
                if (!is_array($file)) {
                    continue;
                }

                $filename = trim((string) ($file['filename'] ?? ''));
                $url = $file['url'] ?? null;
                if ($filename === '' || !str_ends_with(strtolower($filename), '.mrpack') || !is_string($url) || !str_starts_with($url, 'https://')) {
                    continue;
                }

                $chosen = [
                    'version_id' => $versionId,
                    'version_number' => $versionNumber,
                    'filename' => $filename,
                    'url' => $url,
                ];
                if (($file['primary'] ?? false) === true) {
                    return $chosen;
                }

                $fallback ??= $chosen;
            }

            if ($fallback !== null) {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $files  CurseForge file payloads
     * @return array<string, mixed>|null the zip to download, or a lookup stub when the server pack is not on this page
     */
    public static function curseForgeServerPack(array $files): ?array
    {
        $zips = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $name = strtolower((string) ($file['fileName'] ?? ''));
            if ($name !== '' && str_ends_with($name, '.zip')) {
                $zips[] = $file;
            }
        }

        if ($zips === []) {
            return null;
        }

        usort($zips, static function (array $left, array $right): int {
            return strcmp((string) ($right['fileDate'] ?? ''), (string) ($left['fileDate'] ?? ''));
        });

        foreach ($zips as $file) {
            if (($file['isServerPack'] ?? false) === true && self::httpsUrl($file['downloadUrl'] ?? null) !== null) {
                return $file;
            }
        }

        $newest = $zips[0];
        $serverPackId = is_numeric($newest['serverPackFileId'] ?? null) ? (int) $newest['serverPackFileId'] : 0;
        if ($serverPackId > 0) {
            foreach ($zips as $file) {
                if ((int) ($file['id'] ?? 0) === $serverPackId && self::httpsUrl($file['downloadUrl'] ?? null) !== null) {
                    return $file;
                }
            }

            return [
                'id' => $serverPackId,
                'lookup' => true,
            ];
        }

        return self::httpsUrl($newest['downloadUrl'] ?? null) !== null ? $newest : null;
    }

    private static function httpsUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
