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

    private const MAX_MANIFEST_BYTES = 8_388_608;

    private const MAX_OVERRIDE_TOTAL_BYTES = 32_000_000;

    public static function read(string $zipPath, bool $strict = false): ModpackManifest
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
            $modrinth = $zip->getFromName('modrinth.index.json', self::MAX_MANIFEST_BYTES + 1);
            if (is_string($modrinth) && $modrinth !== '') {
                return self::fromModrinth($zip, $modrinth, $strict);
            }

            $curse = $zip->getFromName('manifest.json', self::MAX_MANIFEST_BYTES + 1);
            if (is_string($curse) && $curse !== '') {
                return self::fromCurseForge($zip, $curse, $strict);
            }

            throw new ModpackException('The archive does not contain a Modrinth or CurseForge modpack manifest.');
        } finally {
            $zip->close();
        }
    }

    /**
     * Return the installable payload from a provider-identified server ZIP.
     * The egg supplies the launcher and loader. Pack scripts, bundled
     * runtimes, server configuration and worlds are left out.
     *
     * @return list<array{path: string, index: int, size: int}>
     */
    public static function serverFiles(ZipArchive $zip): array
    {
        $roots = ModpackPaths::MANAGED_ROOT_DIRECTORIES;
        $byRoot = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (!is_string($name)) {
                throw new ModpackException('A server pack entry could not be read.');
            }

            $name = str_replace('\\', '/', $name);
            $path = ModpackPaths::safeRelative(rtrim($name, '/'));
            if ($path === null || str_starts_with($name, '/') || str_contains($path, ':')) {
                throw new ModpackException('The server pack contains an unsafe file path.');
            }
            if ($zip->getExternalAttributesIndex($index, $os, $attributes)
                && $os === ZipArchive::OPSYS_UNIX
                && (($attributes >> 16) & 0170000) === 0120000) {
                throw new ModpackException('The server pack contains a symbolic link.');
            }
            if (str_ends_with($name, '/')) {
                continue;
            }

            $parts = explode('/', $path, 3);
            $root = '';
            if (!in_array($parts[0], $roots, true) && !str_starts_with($path, 'local/kubejs/')) {
                if (!isset($parts[2]) || (!in_array($parts[1], $roots, true)
                    && !($parts[1] === 'local' && str_starts_with($parts[2], 'kubejs/')))) {
                    continue;
                }

                $root = $parts[0];
                $path = substr($path, strlen($root) + 1);
            }
            if (!str_contains($path, '/') || ModpackPaths::isProtected($path)
                || (str_starts_with($path, 'mods/') && !str_ends_with(strtolower($path), '.jar'))) {
                continue;
            }

            $stat = $zip->statIndex($index);
            $key = strtolower($path);
            if ($stat === false || isset($byRoot[$root][$key]) || !empty($stat['encryption_method'])) {
                throw new ModpackException("Server pack entry [{$path}] is unreadable or duplicated.");
            }
            $byRoot[$root][$key] = ['path' => $path, 'index' => $index, 'size' => $stat['size']];
        }

        if (count($byRoot) !== 1) {
            throw new ModpackException('The server pack must contain one directory with modpack files.');
        }

        $files = array_values(reset($byRoot));
        if (count($files) > ModpackManifest::MAX_REMOTE_FILES || array_sum(array_column($files, 'size')) > 8 * 1024 ** 3) {
            throw new ModpackException('The server pack contains too many files or exceeds the unpacked size limit.');
        }

        return $files;
    }

    /** @return array{minecraft: string, loader: string, build: string}|null */
    public static function serverPackRuntime(ZipArchive $zip, array $firstFile): ?array
    {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($firstFile['index']));
        $root = substr($name, 0, strlen($name) - strlen($firstFile['path']));
        $runtime = null;
        $paths = array_fill_keys([$root.'variables.txt', 'variables.txt', $root.'manifest.json', 'manifest.json'], true);
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $path = str_replace('\\', '/', (string) $zip->getNameIndex($index));
            if (!array_key_exists($path, $paths)) {
                continue;
            }
            if (!$paths[$path]) {
                throw new ModpackException("Duplicate server ZIP runtime entry [$path].");
            }
            $paths[$path] = false;
            $variables = basename($path) === 'variables.txt';
            $limit = $variables ? 65536 : self::MAX_MANIFEST_BYTES;
            $contents = $zip->getFromIndex($index, $limit + 1);
            if (!is_string($contents) || strlen($contents) > $limit) {
                throw new ModpackException("Server ZIP runtime entry [$path] could not be read.");
            }
            $metadata = $variables ? self::curseForgeServerPackMetadata($contents) : self::curseForgeInstallMetadata($contents);
            if (!$variables && !isset($metadata['minecraft'], $metadata['loader'], $metadata['build'])) {
                continue;
            }
            if ($metadata === null || ($runtime !== null && (
                $runtime['minecraft'] !== $metadata['minecraft'] || $runtime['loader'] !== $metadata['loader']
                || !ModpackLoader::matchesBuild($metadata['loader'], $metadata['build'], $runtime['build'], $metadata['minecraft'])
            ))) {
                throw new ModpackException('The server ZIP has invalid or conflicting runtime metadata.');
            }
            $runtime = $metadata;
        }

        return $runtime;
    }

    /** @return array{minecraft: ?string, loader: ?string, loader_version: ?string} */
    public static function modrinthInstallMetadata(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ModpackException('The Modrinth archive could not be opened.');
        }

        try {
            $json = $zip->getFromName('modrinth.index.json', self::MAX_MANIFEST_BYTES + 1);
            if (!is_string($json)) {
                throw new ModpackException('The archive has no Modrinth index.');
            }
            $index = self::decodeObject($json, 'modrinth.index.json');
            self::assertModrinthIndex($index);
            $dependencies = (array) ($index['dependencies'] ?? []);
            [$loader, $loaderVersion] = self::modrinthLoader($dependencies);

            return [
                'minecraft' => self::nullableString($dependencies['minecraft'] ?? null),
                'loader' => $loader,
                'loader_version' => $loaderVersion,
            ];
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, mixed> $index */
    private static function assertModrinthIndex(array $index): void
    {
        if ((int) ($index['formatVersion'] ?? 0) !== 1 || ($index['game'] ?? null) !== 'minecraft') {
            throw new ModpackException('Unsupported Modrinth modpack index.');
        }
    }

    private static function fromModrinth(ZipArchive $zip, string $json, bool $strict): ModpackManifest
    {
        $index = self::decodeObject($json, 'modrinth.index.json');
        self::assertModrinthIndex($index);
        if ($strict) {
            self::assertFileList($index);
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

        [$overrides, $overrideSkips] = self::overrides($zip, 'server-overrides/', 'overrides/');

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

    /** @return array{minecraft: ?string, loader: ?string, build: ?string}|null */
    public static function curseForgeInstallMetadata(string $json): ?array
    {
        $manifest = self::decodeObject($json, 'manifest.json');
        if (($manifest['manifestType'] ?? null) !== 'minecraftModpack') {
            return null;
        }

        $minecraft = (array) ($manifest['minecraft'] ?? []);
        [$loader, $build] = self::curseForgeLoader($minecraft['modLoaders'] ?? null);

        return [
            'minecraft' => self::nullableString($minecraft['version'] ?? null),
            'loader' => $loader,
            'build' => $build !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]{0,63}$/D', $build) === 1 ? $build : null,
        ];
    }

    /** @return array{minecraft: string, loader: string, build: string}|null */
    public static function curseForgeServerPackMetadata(string $variables): ?array
    {
        // ServerPackCreator defines these values as literals in variables.txt.
        $fields = [];
        foreach (preg_split('/\r?\n/', $variables) as $line) {
            if (preg_match('/^\s*(MINECRAFT_VERSION|MODLOADER|MODLOADER_VERSION)\s*=([^\r\n]*)$/D', $line, $matches) !== 1) {
                continue;
            }
            if (isset($fields[$matches[1]])) {
                return null;
            }
            $value = trim($matches[2]);
            if (strlen($value) >= 2 && in_array($value[0], ["'", '"'], true) && $value[0] === $value[-1]) {
                $value = substr($value, 1, -1);
            }
            $fields[$matches[1]] = $value;
        }

        $minecraft = $fields['MINECRAFT_VERSION'] ?? '';
        $loader = ModpackLoader::normalize($fields['MODLOADER'] ?? '');
        $build = $fields['MODLOADER_VERSION'] ?? '';
        if ($loader === null || preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $minecraft) !== 1
            || preg_match('/^[0-9][A-Za-z0-9.+_-]{0,63}$/D', $build) !== 1) {
            return null;
        }

        return ['minecraft' => $minecraft, 'loader' => $loader, 'build' => $build];
    }

    private static function fromCurseForge(ZipArchive $zip, string $json, bool $strict): ModpackManifest
    {
        $manifest = self::decodeObject($json, 'manifest.json');
        if (($manifest['manifestType'] ?? null) !== 'minecraftModpack') {
            throw new ModpackException('The archive manifest is not a Minecraft modpack.');
        }

        if ($strict) {
            self::assertFileList($manifest);
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
    private static function overrides(ZipArchive $zip, string ...$prefixes): array
    {
        $overrides = [];
        $seen = [];
        $skipped = [];
        $total = 0;
        // Prefixes are ordered from most to least specific.
        foreach ($prefixes as $prefix) {
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

                $key = strtolower($relative);
                if (isset($seen[$key])) {
                    if ($seen[$key] === $prefix) {
                        throw new ModpackException("Duplicate override [$relative].");
                    }

                    continue;
                }
                $seen[$key] = $prefix;

                $stat = $zip->statIndex($index);
                $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
                if ($size > self::MAX_OVERRIDE_BYTES || $total + $size > self::MAX_OVERRIDE_TOTAL_BYTES) {
                    if ($prefix === 'server-overrides/' && !ModpackPaths::isProtected($relative)) {
                        throw new ModpackException("Server override [$relative] exceeds the placement size limit.");
                    }
                    $skipped[] = 'override-too-large:'.$relative;

                    continue;
                }

                $contents = $zip->getFromIndex($index);
                if (!is_string($contents)) {
                    throw new ModpackException("Override [{$relative}] could not be read.");
                }

                $total += strlen($contents);
                $overrides[$relative] = new ModpackOverrideFile($relative, $contents);
            }
        }

        return [array_values($overrides), $skipped];
    }

    private static function assertFileList(array $manifest): void
    {
        $files = $manifest['files'] ?? null;
        if (!is_array($files) || !array_is_list($files)) {
            throw new ModpackException('The archive does not explicitly declare a valid dependency list.');
        }
        foreach ($files as $file) {
            if (!is_array($file)) {
                throw new ModpackException('The archive contains an invalid dependency record.');
            }
        }
    }

    /** @return array<string, mixed> */
    private static function decodeObject(string $json, string $name): array
    {
        if (strlen($json) > self::MAX_MANIFEST_BYTES) {
            throw new ModpackException("Modpack manifest [$name] exceeds the size limit.");
        }

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
            if (ModpackPaths::isSafeHttpsDownloadUrl($url)) {
                return $url;
            }
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
