<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use GuzzleHttp\Psr7\Utils;
use Kazaminosuke\ModManager\Exceptions\CurseForgeDependenciesPending;
use Kazaminosuke\ModManager\Exceptions\CurseForgeManualFileException;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Support\ArchiveSignature;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackArchive;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackInstallResult;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackLoader;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackOverrideFile;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackPaths;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackRemoteFile;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Psr\Http\Message\StreamInterface;
use Throwable;
use ZipArchive;

/**
 * Installs a modpack onto an existing server without deleting worlds or
 * replacing files the operator already has in override paths.
 *
 * Downloaded mods are swapped through a backup and restored if a later file
 * fails. Config overrides are written only when that relative path is absent.
 */
final class ModpackInstaller
{
    /** @var list<array{directory: string, filename: string, backup: ?string}> */
    private array $placed = [];

    /** @var array<string, array<string, true>> */
    private array $overrideDirectoryListings = [];

    private readonly CurseForgeManualFiles $manualFiles;

    public function __construct(
        private readonly WingsRemoteFilesystem $wings,
        private readonly CurseForgeSource $curseForge,
    ) {
        $this->manualFiles = new CurseForgeManualFiles($wings);
    }

    /**
     * @param  callable(int, int): void|null  $progress
     * @param  callable(): void|null  $prepared
     */
    public function installFromZip(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $zipPath,
        ?string $serverLoader = null,
        ?string $serverMinecraftVersion = null,
        ?callable $progress = null,
        bool $strictMinecraftVersion = false,
        ?callable $prepared = null,
    ): ModpackInstallResult {
        $this->placed = [];
        $this->overrideDirectoryListings = [];
        $manifest = ModpackArchive::read($zipPath);
        $this->assertLoaderCompatible($manifest->loader, $serverLoader);
        $warnings = $manifest->skipped;
        if ($manifest->minecraftVersion !== null
            && $serverMinecraftVersion !== null
            && $manifest->minecraftVersion !== $serverMinecraftVersion) {
            if ($strictMinecraftVersion) {
                throw new ModpackException("This modpack requires Minecraft [{$manifest->minecraftVersion}] but the server uses [{$serverMinecraftVersion}].");
            }
            $warnings[] = 'minecraft-version:'.$manifest->minecraftVersion;
        }

        $staged = [];
        $installed = 0;
        $completed = 0;

        try {
            $remoteFiles = $this->resolveDownloads($server, $fileRepository, $manifest, $warnings, $staged);
            if ($prepared !== null) {
                $prepared();
            }
            $steps = count($remoteFiles) + count($manifest->overrides);
            $skipped = count($manifest->remoteFiles) - count($remoteFiles);
            foreach ($remoteFiles as $file) {
                if ($this->placeRemoteFile($server, $fileRepository, $file, $staged[$file->path] ?? null)) {
                    $installed++;
                } else {
                    $skipped++;
                }
                $completed++;
                if ($progress !== null) {
                    $progress($completed, $steps);
                }
            }

            foreach ($manifest->overrides as $override) {
                if ($this->placeOverride($server, $fileRepository, $override)) {
                    $installed++;
                } else {
                    $skipped++;
                }
                $completed++;
                if ($progress !== null) {
                    $progress($completed, $steps);
                }
            }
        } catch (Throwable $exception) {
            $this->rollback($server, $fileRepository);

            throw $exception;
        } finally {
            foreach ($staged as $stream) {
                $stream->close();
            }
        }

        $this->discardBackups($server, $fileRepository);

        return new ModpackInstallResult(
            name: $manifest->name,
            version: $manifest->version,
            installed: $installed,
            skipped: $skipped,
            warnings: $warnings,
        );
    }

    /**
     * @param  callable(int, int): void  $progress
     */
    public function installServerPack(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $zipPath,
        string $name,
        string $version,
        ?string $loader,
        ?string $minecraftVersion,
        ?string $serverLoader,
        ?string $serverMinecraftVersion,
        callable $progress,
    ): ModpackInstallResult {
        $this->placed = [];
        $this->overrideDirectoryListings = [];
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ModpackException('The CurseForge server pack could not be opened.');
        }

        $installed = 0;
        $warnings = [];
        try {
            $this->assertLoaderCompatible($loader, $serverLoader);
            if ($minecraftVersion !== null && $serverMinecraftVersion !== null && $minecraftVersion !== $serverMinecraftVersion) {
                $warnings[] = 'minecraft-version:'.$minecraftVersion;
            }

            $files = ModpackArchive::serverFiles($zip);
            foreach ($files as $i => $file) {
                $path = $file['path'];
                [$directory, $filename] = ModpackPaths::split($path);
                if (!$this->overrideExists($server, $fileRepository, $directory, $filename)) {
                    $this->placeServerPackFile($server, $fileRepository, $zip, $file);
                    $this->overrideDirectoryListings[$directory][strtolower($filename)] = true;
                    $this->placed[] = ['directory' => $directory, 'filename' => $filename, 'backup' => null];
                    $installed++;
                }
                $progress($i + 1, count($files));
            }
        } catch (Throwable $exception) {
            $this->rollback($server, $fileRepository);

            throw $exception;
        } finally {
            $zip->close();
        }

        return new ModpackInstallResult($name, $version, $installed, count($files) - $installed, $warnings);
    }

    /** @param array{path: string, index: int, size: int} $file */
    private function placeServerPackFile(Server $server, DaemonFileRepository $fileRepository, ZipArchive $zip, array $file): void
    {
        [$directory, $filename] = ModpackPaths::split($file['path']);
        $resource = $zip->getStreamIndex($file['index']);
        if ($resource === false) {
            throw new ModpackException("Server pack entry [{$file['path']}] could not be opened.");
        }

        $temp = '.mod-manager-'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (str_starts_with($file['path'], 'mods/')) {
                // The stream is forward-only. Reopen it after checking the
                // signature so the upload includes the complete JAR.
                $signature = fread($resource, 4);
                if (!is_string($signature) || !ArchiveSignature::isZip($signature)) {
                    throw new ModpackException("Server pack mod [{$file['path']}] is not a valid JAR.");
                }
                fclose($resource);
                $resource = $zip->getStreamIndex($file['index']);
                if ($resource === false) {
                    throw new ModpackException("Server pack entry [{$file['path']}] could not be reopened.");
                }
            }

            $stream = Utils::streamFor($resource, ['size' => $file['size']]);
            try {
                $this->wings->putStream($fileRepository, $server, $directory.'/'.$temp, $stream);
                $this->wings->move($fileRepository, $server, $directory, $temp, $filename);
            } finally {
                $stream->close();
            }
        } catch (Throwable $exception) {
            $this->wings->deleteQuietly($fileRepository, $server, $directory, $temp);

            throw $exception;
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * Build the same target for preview and apply. The shared parser and
     * dependency resolver preserve the manual CurseForge workflow.
     *
     * @return array<string, mixed>
     */
    public function deploy(
        Server $server,
        DaemonFileRepository $repo,
        string $archive,
        array $release,
        ?string $loader,
        ?string $minecraft,
        bool $preview,
        ?string $approved,
        ?string $backup,
        callable $guard,
        ?array $runtime = null,
    ): array {
        $snapshots = [];
        $zip = null;
        $target = $warnings = [];
        try {
            if ($release['server_pack']) {
                $this->assertLoaderCompatible($release['loader'], $loader);
                $this->assertMinecraftCompatible($release['minecraft_version'], $minecraft);
                $zip = new ZipArchive();
                if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
                    $zip = null;
                    throw new ModpackException('The server pack could not be opened.');
                }
                foreach (ModpackArchive::serverFiles($zip) as $entry) {
                    $open = static function () use ($zip, $entry): StreamInterface {
                        $stream = $zip->getStreamIndex($entry['index']);
                        if ($stream === false) {
                            throw new ModpackException('A server pack entry could not be opened.');
                        }

                        return Utils::streamFor($stream, ['size' => $entry['size']]);
                    };
                    $ctx = hash_init('sha256');
                    $stream = $zip->getStreamIndex($entry['index']);
                    if ($stream === false) {
                        throw new ModpackException('A server pack entry could not be read.');
                    }
                    try {
                        if (hash_update_stream($ctx, $stream) !== $entry['size']) {
                            throw new ModpackException('A server pack entry is truncated.');
                        }
                    } finally {
                        fclose($stream);
                    }
                    $hash = hash_final($ctx);
                    $target[$entry['path']] = $this->managedEntry($hash, $hash, $entry['size'], [], null, null, $open, null);
                }
            } else {
                $manifest = ModpackArchive::read($archive, strict: true);
                if ($manifest->format !== $release['provider']) {
                    throw new ModpackException('The archive manifest belongs to another provider.');
                }
                $this->assertLoaderCompatible($manifest->loader, $loader);
                $this->assertMinecraftCompatible($manifest->minecraftVersion, $minecraft);
                if ($runtime !== null && $manifest->loader !== null && $manifest->loaderVersion !== null
                    && !ModpackLoader::hasBuild($manifest->loader, $manifest->loaderVersion, $manifest->minecraftVersion, $runtime)) {
                    throw new ModpackException("The archive requires {$manifest->loader} {$manifest->loaderVersion}. Verify the existing runtime before updating.");
                }
                $warnings = $manifest->skipped;
                $files = $this->resolveDownloads($server, $repo, $manifest, $warnings, $snapshots);
                foreach ($files as $file) {
                    $path = $file->path;
                    if ($path === null || isset($target[$path])) {
                        throw new ModpackException('A modpack dependency is missing its path or maps to a duplicate path.');
                    }
                    $hashes = array_filter($file->hashes, static fn ($hash, $algorithm) => is_string($hash)
                        && strlen($hash) === (['sha256' => 64, 'sha512' => 128, 'sha1' => 40, 'md5' => 32][$algorithm] ?? 0)
                        && ctype_xdigit($hash), ARRAY_FILTER_USE_BOTH);
                    ksort($hashes);
                    $identity = $file->fileId === null ? null : "curseforge:{$file->projectId}/{$file->fileId}";
                    if ($hashes !== []) {
                        $identity = hash('sha256', json_encode([$identity, $hashes], JSON_THROW_ON_ERROR));
                    }
                    $snapshot = $snapshots[$path] ?? null;
                    $open = $snapshot === null ? null : static function () use ($snapshot): StreamInterface {
                        $snapshot->rewind();

                        return $snapshot;
                    };
                    $target[$path] = $this->managedEntry($identity, $hashes['sha256'] ?? null, $file->size, $hashes,
                        $file->fileId === null ? null : (string) $file->fileId, $file->projectId === null ? null : (string) $file->projectId, $open, $file->url);
                }
                foreach ($manifest->overrides as $override) {
                    if (isset($target[$override->path])) {
                        throw new ModpackException("An override collides with a dependency [{$override->path}].");
                    }
                    $hash = hash('sha256', $override->contents);
                    $target[$override->path] = $this->managedEntry($hash, $hash, strlen($override->contents), [], null, null,
                        static fn () => Utils::streamFor($override->contents), null);
                }
            }

            $result = app(ModpackDeployment::class)->run($server, $repo, $release, $target, $approved, $preview, $backup, $guard, $warnings);

            return [...$result, 'warnings' => $warnings];
        } finally {
            foreach ($snapshots as $snapshot) {
                $snapshot->close();
            }
            $zip?->close();
        }
    }

    private function managedEntry(?string $identity, ?string $sha256, ?int $size, array $hashes, ?string $fileId, ?string $projectId, ?callable $open, ?string $url): array
    {
        return ['identity' => $identity, 'sha256' => $sha256, 'size' => $size, 'hashes' => $hashes, 'file_id' => $fileId,
            'project_id' => $projectId, 'open' => $open, 'url' => $url];
    }

    private function assertMinecraftCompatible(?string $required, ?string $actual): void
    {
        if ($required !== null && $actual !== null && $required !== $actual) {
            throw new ModpackException("The modpack requires Minecraft [$required], but the server uses [$actual].");
        }
    }

    private function assertLoaderCompatible(?string $packLoader, ?string $serverLoader): void
    {
        if ($serverLoader === null || $packLoader === null || $serverLoader === $packLoader) {
            return;
        }

        throw new ModpackException("This modpack requires [{$packLoader}] but the server uses [{$serverLoader}].");
    }

    /**
     * @param list<string> $warnings
     * @param array<string, StreamInterface> $staged
     * @return list<ModpackRemoteFile>
     */
    private function resolveDownloads(Server $server, DaemonFileRepository $repository, ModpackManifest $manifest, array &$warnings, array &$staged): array
    {
        if (count($manifest->remoteFiles) > 2000) {
            throw new ModpackException('This modpack lists more files than can be installed safely.');
        }

        $pairs = [];
        foreach ($manifest->remoteFiles as $file) {
            if ($file->url === null || $file->path === null) {
                if ($file->projectId === null || $file->fileId === null) {
                    throw new ModpackException('A modpack file is missing its download.');
                }
                $key = "{$file->projectId}/{$file->fileId}";
                $pairs[$key] = ['project_id' => $file->projectId, 'file_id' => $file->fileId];
            }
        }

        $dependencies = $pairs === [] ? [] : $this->curseForge->dependencyFiles(array_values($pairs));
        $resolved = [];
        $missing = [];
        $requiredMissing = false;
        $remainingBytes = CurseForgeManualFiles::MAX_BATCH_BYTES;
        $targets = [];
        foreach ($manifest->remoteFiles as $file) {
            if ($file->url !== null && $file->path !== null) {
                $this->assertHttps($file->url);
                $resolved[] = $file;

                continue;
            }

            $key = "{$file->projectId}/{$file->fileId}";
            $dependency = $dependencies[$key];
            $path = $dependency->filename === null ? null : 'mods/'.$dependency->filename;
            if ($path !== null) {
                $target = strtolower($path);
                if (isset($targets[$target])) {
                    if ($targets[$target] === $key && !isset($missing[$key])) {
                        continue;
                    }
                    if ($targets[$target] !== $key) {
                        throw new ModpackException("CurseForge dependencies target the same file [$path].");
                    }
                }
                $targets[$target] = $key;
            }
            if ($dependency->url !== null && $path !== null) {
                $resolved[] = $file->withDownload($path, $dependency->url, $dependency->size, $dependency->hashes);

                continue;
            }

            try {
                if ($path === null) {
                    throw new CurseForgeManualFileException($dependency, $dependency->reason ?? 'metadata_missing');
                }
                $snapshot = $this->manualFiles->verifiedSnapshot($server, $repository, $dependency, $remainingBytes);
                $staged[$path] = $snapshot;
                $remainingBytes -= $snapshot->getSize() ?? 0;
                $resolved[] = $file->withPath($path, $snapshot->getSize());
            } catch (CurseForgeManualFileException $exception) {
                $missing[$key] = $dependency->details($file->required || ($missing[$key]['required'] ?? false), $exception->reason);
                $requiredMissing = $requiredMissing || $file->required;
                $warnings[] = "unavailable:$key:{$exception->reason}";
            }
        }

        if ($requiredMissing) {
            throw new CurseForgeDependenciesPending(array_values($missing));
        }

        return $resolved;
    }

    private function placeRemoteFile(
        Server $server,
        DaemonFileRepository $fileRepository,
        ModpackRemoteFile $file,
        ?StreamInterface $snapshot = null,
    ): bool {
        $path = $file->path;
        $url = $file->url;
        if (!is_string($path) || ($snapshot === null && !is_string($url))) {
            throw new ModpackException('A modpack download is incomplete.');
        }

        if (ModpackPaths::isProtected($path)) {
            return false;
        }

        [$directory, $filename] = ModpackPaths::split($path);
        $temp = '.mod-manager-pull-'.bin2hex(random_bytes(8));
        $backup = null;
        $activated = false;

        try {
            if ($snapshot !== null) {
                $this->wings->putStream($fileRepository, $server, $directory.'/'.$temp, $snapshot);
            } else {
                $this->assertHttps($url);
                $this->wings->pullForeground($fileRepository, $server, $url, $directory, $temp);
            }
            $listed = $this->wings->findListedFile($fileRepository, $server, $directory, $temp);
            if ($listed === null) {
                throw new ModpackException("Downloaded file [{$path}] was not stored.");
            }

            $size = $this->wings->listedFileSize($listed);
            if ($size === null || $size <= 0) {
                throw new ModpackException("Downloaded file [{$path}] is empty.");
            }
            if ($file->size !== null && $size !== $file->size) {
                throw new ModpackException("Downloaded file [{$path}] size [{$size}] does not match expected size [{$file->size}].");
            }

            if ($this->requiresZip($filename)) {
                $header = $this->wings->readPrefix($fileRepository, $server, $directory, $temp);
                if (!ArchiveSignature::isZip($header)) {
                    throw new ModpackException("Downloaded file [{$path}] is not a valid ZIP or JAR.");
                }
            }

            $exists = $this->wings->findListedFile($fileRepository, $server, $directory, $filename) !== null;
            if ($exists) {
                $backup = '.mod-manager-prev-'.bin2hex(random_bytes(8));
                $this->wings->move($fileRepository, $server, $directory, $filename, $backup);
            }

            $this->wings->move($fileRepository, $server, $directory, $temp, $filename);
            $activated = true;
            // Keep the previous file until every later file succeeds.
            $this->placed[] = ['directory' => $directory, 'filename' => $filename, 'backup' => $backup];

            return true;
        } catch (Throwable $exception) {
            $this->wings->deleteQuietly($fileRepository, $server, $directory, $temp);
            if (!$activated && $backup !== null) {
                try {
                    $this->wings->move($fileRepository, $server, $directory, $backup, $filename);
                } catch (Throwable $restore) {
                    report($restore);
                }
            }

            if ($exception instanceof ModpackException) {
                throw $exception;
            }

            throw new ModpackException("Failed to install [{$path}]: {$exception->getMessage()}", 0, $exception);
        }
    }

    private function placeOverride(
        Server $server,
        DaemonFileRepository $fileRepository,
        ModpackOverrideFile $override,
    ): bool {
        if (ModpackPaths::isProtected($override->path)) {
            return false;
        }

        [$directory, $filename] = ModpackPaths::split($override->path);
        if ($this->overrideExists($server, $fileRepository, $directory, $filename)) {
            return false;
        }

        $this->wings->put($fileRepository, $server, $override->path, $override->contents);
        $this->overrideDirectoryListings[$directory][strtolower($filename)] = true;
        $this->placed[] = ['directory' => $directory, 'filename' => $filename, 'backup' => null];

        return true;
    }

    private function overrideExists(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $directory,
        string $filename,
    ): bool {
        $target = strtolower($filename);
        if (array_key_exists($directory, $this->overrideDirectoryListings)) {
            return isset($this->overrideDirectoryListings[$directory][$target]);
        }

        try {
            $listing = $this->wings->listDirectory($fileRepository, $server, $directory);
            $names = [];
            foreach ($listing as $item) {
                if (is_array($item) && is_string($item['name'] ?? null) && ($item['directory'] ?? false) !== true) {
                    $names[strtolower($item['name'])] = true;
                }
            }
            $this->overrideDirectoryListings[$directory] = $names;

            return isset($names[$target]);
        } catch (Throwable) {
            // Fall back to findListedFile when listDirectory is overridden by a test double.
            try {
                return $this->wings->findListedFile($fileRepository, $server, $directory, $filename) !== null;
            } catch (Throwable) {
                return false;
            }
        }
    }

    private function discardBackups(Server $server, DaemonFileRepository $fileRepository): void
    {
        foreach ($this->placed as $placed) {
            if ($placed['backup'] !== null) {
                $this->wings->deleteQuietly($fileRepository, $server, $placed['directory'], $placed['backup']);
            }
        }
    }

    private function rollback(Server $server, DaemonFileRepository $fileRepository): void
    {
        foreach (array_reverse($this->placed) as $placed) {
            $this->wings->deleteQuietly($fileRepository, $server, $placed['directory'], $placed['filename']);
            if ($placed['backup'] !== null) {
                try {
                    $this->wings->move(
                        $fileRepository,
                        $server,
                        $placed['directory'],
                        $placed['backup'],
                        $placed['filename'],
                    );
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        $this->placed = [];
    }

    private function requiresZip(string $filename): bool
    {
        $filename = strtolower($filename);

        return str_ends_with($filename, '.jar') || str_ends_with($filename, '.zip');
    }

    private function assertHttps(string $url): void
    {
        if (!ModpackPaths::isSafeHttpsDownloadUrl($url)) {
            throw new ModpackException('Modpack downloads must use an HTTPS URL.');
        }
    }
}
