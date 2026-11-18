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

    /** @param  array<string, mixed>  $file */
    public static function isCurseForgeAdditionalFile(array $file): bool
    {
        return ($file['isServerPack'] ?? false) === true || (int) ($file['parentProjectFileId'] ?? 0) > 0;
    }

    /**
     * @param  list<mixed>  $files
     * @return list<array<string, mixed>>
     */
    public static function curseForgeZips(array $files): array
    {
        $zips = array_values(array_filter($files, static fn (mixed $file): bool => is_array($file)
            && (int) ($file['id'] ?? 0) > 0
            && str_ends_with(strtolower((string) ($file['fileName'] ?? '')), '.zip')));
        usort($zips, static fn (array $left, array $right): int =>
            strcmp((string) ($right['fileDate'] ?? ''), (string) ($left['fileDate'] ?? '')));

        return $zips;
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $candidate
     */
    public static function isCurseForgeServerPackFor(array $release, array $candidate): bool
    {
        $id = (int) ($candidate['id'] ?? 0);
        $releaseId = (int) ($release['id'] ?? 0);
        $parent = (int) ($candidate['parentProjectFileId'] ?? 0);
        if ($id <= 0 || $releaseId <= 0 || $id === $releaseId || ($parent > 0 && $parent !== $releaseId)) {
            return false;
        }

        return (int) ($release['serverPackFileId'] ?? 0) === $id
            || ($parent === $releaseId && ($candidate['isServerPack'] ?? false) === true);
    }
}
