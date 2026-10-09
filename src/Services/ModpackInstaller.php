<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Support\ArchiveSignature;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackArchive;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackInstallResult;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackOverrideFile;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackPaths;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackRemoteFile;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Throwable;

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

    public function __construct(
        private readonly WingsRemoteFilesystem $wings,
        private readonly CurseForgeSource $curseForge,
    ) {}

    /**
     * @param  callable(int, int): void|null  $progress
     */
    public function installFromZip(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $zipPath,
        ?string $serverLoader = null,
        ?string $serverMinecraftVersion = null,
        ?callable $progress = null,
    ): ModpackInstallResult {
        $this->placed = [];
        $manifest = ModpackArchive::read($zipPath);
        $this->assertLoaderCompatible($manifest, $serverLoader);
        $warnings = $manifest->skipped;
        if ($manifest->minecraftVersion !== null
            && $serverMinecraftVersion !== null
            && $manifest->minecraftVersion !== $serverMinecraftVersion) {
            $warnings[] = 'minecraft-version:'.$manifest->minecraftVersion;
        }

        $remoteFiles = $this->resolveDownloads($manifest, $warnings);
        $steps = count($remoteFiles) + count($manifest->overrides);
        $installed = 0;
        $skipped = 0;
        $completed = 0;

        try {
            foreach ($remoteFiles as $file) {
                if ($this->placeRemoteFile($server, $fileRepository, $file)) {
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

    private function assertLoaderCompatible(ModpackManifest $manifest, ?string $serverLoader): void
    {
        if ($serverLoader === null || $manifest->loader === null || $serverLoader === $manifest->loader) {
            return;
        }

        throw new ModpackException("This modpack requires [{$manifest->loader}] but the server uses [{$serverLoader}].");
    }

    /**
     * @param  list<string>  $warnings
     * @return list<ModpackRemoteFile>
     */
    private function resolveDownloads(ModpackManifest $manifest, array &$warnings): array
    {
        if (count($manifest->remoteFiles) > 2000) {
            throw new ModpackException('This modpack lists more files than can be installed safely.');
        }

        $resolved = [];
        foreach ($manifest->remoteFiles as $file) {
            if (is_string($file->url) && is_string($file->path)) {
                $this->assertHttps($file->url);
                $resolved[] = $file;

                continue;
            }

            if ($file->projectId === null || $file->fileId === null) {
                throw new ModpackException('A modpack file is missing its download.');
            }

            $download = $this->curseForge->modpackDependencyDownload($file->projectId, $file->fileId);
            if ($download === null) {
                if ($file->required) {
                    throw new ModpackException("CurseForge file [{$file->projectId}/{$file->fileId}] cannot be downloaded.");
                }
                $warnings[] = "unavailable:{$file->projectId}/{$file->fileId}";

                continue;
            }

            $resolved[] = $file->withDownload($download['path'], $download['url'], $download['size']);
        }

        return $resolved;
    }

    private function placeRemoteFile(
        Server $server,
        DaemonFileRepository $fileRepository,
        ModpackRemoteFile $file,
    ): bool {
        $path = $file->path;
        $url = $file->url;
        if (!is_string($path) || !is_string($url)) {
            throw new ModpackException('A modpack download is incomplete.');
        }

        if (ModpackPaths::isProtected($path)) {
            return false;
        }

        $this->assertHttps($url);
        [$directory, $filename] = ModpackPaths::split($path);
        $temp = '.mod-manager-pull-'.bin2hex(random_bytes(8));
        $backup = null;
        $activated = false;

        try {
            $this->wings->pullForeground($fileRepository, $server, $url, $directory, $temp);
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
        try {
            $exists = $this->wings->findListedFile($fileRepository, $server, $directory, $filename) !== null;
        } catch (Throwable) {
            $exists = false;
        }
        if ($exists) {
            return false;
        }

        $this->wings->put($fileRepository, $server, $override->path, $override->contents);
        $this->placed[] = ['directory' => $directory, 'filename' => $filename, 'backup' => null];

        return true;
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
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if ($scheme !== 'https' || !is_string($host) || $host === '') {
            throw new ModpackException('Modpack downloads must use an HTTPS URL.');
        }
    }
}
