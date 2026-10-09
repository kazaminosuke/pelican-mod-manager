<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

use Kazaminosuke\ModManager\Exceptions\ModpackException;
use ZipArchive;

/**
 * Reads a Modrinth .mrpack or a CurseForge modpack zip.
 *
 * Client-only files and client-overrides are omitted. Paths that escape the
 * archive are rejected. Override bodies are kept only up to a size cap so a
 * hostile archive cannot exhaust memory; larger overrides are skipped.
 */
final class ModpackArchive
{
    private const MAX_OVERRIDE_BYTES = 2_000_000;

    private const MAX_OVERRIDE_TOTAL_BYTES = 32_000_000;

    public static function read(string $zipPath): ModpackManifest
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ModpackException('PHP ZipArchive is required to read a modpack.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new ModpackException('The modpack archive could not be opened.');
        }

        try {
            $modrinth = $zip->getFromName('modrinth.index.json');
            if (is_string($modrinth) && $modrinth !== '') {
                return self::fromModrinth($zip, $modrinth);
            }

            $curse = $zip->getFromName('manifest.json');
            if (is_string($curse) && $curse !== '') {
                return self::fromCurseForge($zip, $curse);
            }

            throw new ModpackException('The archive does not contain a Modrinth or CurseForge modpack manifest.');
        } finally {
            $zip->close();
        }
    }

    private static function fromModrinth(ZipArchive $zip, string $json): ModpackManifest
    {
        $index = self::decodeObject($json, 'modrinth.index.json');
        if ((int) ($index['formatVersion'] ?? 0) !== 1 || ($index['game'] ?? null) !== 'minecraft') {
            throw new ModpackException('Unsupported Modrinth modpack index.');
        }

        $dependencies = is_array($index['dependencies'] ?? null) ? $index['dependencies'] : [];
        [$loader, $loaderVersion] = self::modrinthLoader($dependencies);
        $remoteFiles = [];
        $skipped = [];

        foreach (is_array($index['files'] ?? null) ? $index['files'] : [] as $file) {
            if (!is_array($file)) {
                continue;
            }

            $env = is_array($file['env'] ?? null) ? $file['env'] : [];
            $server = $env['server'] ?? null;
            if ($server === 'unsupported') {
                $skipped[] = 'client-only:'.(string) ($file['path'] ?? '');

                continue;
            }

            $path = ModpackPaths::safeRelative((string) ($file['path'] ?? ''));
            if ($path === null) {
                throw new ModpackException('The modpack contains an unsafe file path.');
            }

            $url = self::firstHttpsUrl($file['downloads'] ?? null);
            if ($url === null) {
                if (($server ?? 'required') === 'required') {
                    throw new ModpackException("Modpack file [{$path}] has no HTTPS download.");
                }
                $skipped[] = 'no-download:'.$path;

                continue;
            }

            $remoteFiles[] = new ModpackRemoteFile(
                path: $path,
                url: $url,
                projectId: null,
                fileId: null,
                required: ($server ?? 'required') !== 'optional',
                size: self::positiveInt($file['fileSize'] ?? null),
                hashes: self::stringMap($file['hashes'] ?? null),
            );
        }

        [$overrides, $overrideSkips] = self::overrides($zip, 'overrides/');

        return new ModpackManifest(
            format: ModpackManifest::FORMAT_MODRINTH,
            name: self::label($index['name'] ?? null, 'Modpack'),
            version: self::label($index['versionId'] ?? null, 'unknown'),
            minecraftVersion: self::nullableString($dependencies['minecraft'] ?? null),
            loader: $loader,
            loaderVersion: $loaderVersion,
            remoteFiles: $remoteFiles,
            overrides: $overrides,
            skipped: [...$skipped, ...$overrideSkips],
        );
    }

    private static function fromCurseForge(ZipArchive $zip, string $json): ModpackManifest
    {
        $manifest = self::decodeObject($json, 'manifest.json');
        if (($manifest['manifestType'] ?? null) !== 'minecraftModpack') {
            throw new ModpackException('The archive manifest is not a Minecraft modpack.');
        }

        $minecraft = is_array($manifest['minecraft'] ?? null) ? $manifest['minecraft'] : [];
        [$loader, $loaderVersion] = self::curseForgeLoader($minecraft['modLoaders'] ?? null);
        $remoteFiles = [];

        foreach (is_array($manifest['files'] ?? null) ? $manifest['files'] : [] as $file) {
            if (!is_array($file)) {
                continue;
            }

            $projectId = self::positiveInt($file['projectID'] ?? null);
            $fileId = self::positiveInt($file['fileID'] ?? null);
            if ($projectId === null || $fileId === null) {
                throw new ModpackException('The modpack manifest contains a file without a project or file id.');
            }

            $remoteFiles[] = new ModpackRemoteFile(
                path: null,
                url: null,
                projectId: $projectId,
                fileId: $fileId,
                required: ($file['required'] ?? true) !== false,
                size: null,
            );
        }

        $overrideRoot = self::nullableString($manifest['overrides'] ?? null) ?? 'overrides';
        $overrideRoot = trim(str_replace('\\', '/', $overrideRoot), '/');
        if ($overrideRoot === '' || str_contains($overrideRoot, '..')) {
            throw new ModpackException('The modpack override directory is unsafe.');
        }

        [$overrides, $skipped] = self::overrides($zip, $overrideRoot.'/');

        return new ModpackManifest(
            format: ModpackManifest::FORMAT_CURSEFORGE,
            name: self::label($manifest['name'] ?? null, 'Modpack'),
            version: self::label($manifest['version'] ?? null, 'unknown'),
            minecraftVersion: self::nullableString($minecraft['version'] ?? null),
            loader: $loader,
            loaderVersion: $loaderVersion,
            remoteFiles: $remoteFiles,
            overrides: $overrides,
            skipped: $skipped,
        );
    }

    /**
     * @param  array<string, mixed>  $dependencies
     * @return array{0: ?string, 1: ?string}
     */
    private static function modrinthLoader(array $dependencies): array
    {
        foreach (['fabric-loader' => 'fabric', 'neoforge' => 'neoforge', 'forge' => 'forge', 'quilt-loader' => 'quilt'] as $key => $loader) {
            $version = self::nullableString($dependencies[$key] ?? null);
            if ($version !== null) {
                return [$loader, $version];
            }
        }

        return [null, null];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function curseForgeLoader(mixed $modLoaders): array
    {
        if (!is_array($modLoaders)) {
            return [null, null];
        }

        $chosen = null;
        foreach ($modLoaders as $loader) {
            if (!is_array($loader)) {
                continue;
            }

            if (($loader['primary'] ?? false) === true || $chosen === null) {
                $chosen = $loader;
                if (($loader['primary'] ?? false) === true) {
                    break;
                }
            }
        }

        $id = self::nullableString(is_array($chosen) ? ($chosen['id'] ?? null) : null);
        if ($id === null || !str_contains($id, '-')) {
            return [null, null];
        }

        [$name, $version] = explode('-', $id, 2);
        $name = strtolower($name);
        if (!in_array($name, ['fabric', 'forge', 'neoforge', 'quilt'], true)) {
            return [null, null];
        }

        return [$name, $version !== '' ? $version : null];
    }

    /**
     * @return array{0: list<ModpackOverrideFile>, 1: list<string>}
     */
    private static function overrides(ZipArchive $zip, string $prefix): array
    {
        $overrides = [];
        $skipped = [];
        $total = 0;
        $prefix = str_replace('\\', '/', $prefix);

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name)) {
                continue;
            }

            $name = str_replace('\\', '/', $name);
            if (!str_starts_with($name, $prefix) || str_ends_with($name, '/')) {
                continue;
            }

            $relative = ModpackPaths::safeRelative(substr($name, strlen($prefix)));
            if ($relative === null) {
                throw new ModpackException('The modpack contains an unsafe override path.');
            }

            $stat = $zip->statIndex($index);
            $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
            if ($size > self::MAX_OVERRIDE_BYTES || $total + $size > self::MAX_OVERRIDE_TOTAL_BYTES) {
                $skipped[] = 'override-too-large:'.$relative;

                continue;
            }

            $contents = $zip->getFromIndex($index);
            if (!is_string($contents)) {
                throw new ModpackException("Override [{$relative}] could not be read.");
            }

            $total += strlen($contents);
            $overrides[] = new ModpackOverrideFile($relative, $contents);
        }

        return [$overrides, $skipped];
    }

    /** @return array<string, mixed> */
    private static function decodeObject(string $json, string $name): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ModpackException("Modpack manifest [{$name}] is not valid JSON.");
        }

        if (!is_array($decoded)) {
            throw new ModpackException("Modpack manifest [{$name}] is not an object.");
        }

        return $decoded;
    }

    private static function firstHttpsUrl(mixed $downloads): ?string
    {
        if (!is_array($downloads)) {
            return null;
        }

        foreach ($downloads as $url) {
            if (!is_string($url) || !str_starts_with($url, 'https://')) {
                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? $url : null;
        }

        return null;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $algorithm => $hash) {
            if (is_string($algorithm) && is_string($hash) && $algorithm !== '' && $hash !== '') {
                $map[strtolower($algorithm)] = strtolower($hash);
            }
        }

        return $map;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private static function label(mixed $value, string $fallback): string
    {
        return self::nullableString($value) ?? $fallback;
    }
}
