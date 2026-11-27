<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use GuzzleHttp\Psr7\Utils;
use Kazaminosuke\ModManager\Exceptions\CurseForgeDependenciesPending;
use Kazaminosuke\ModManager\Exceptions\CurseForgeManualFileException;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Kazaminosuke\ModManager\Support\Modpacks\FtbReleaseManifest;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackArchive;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackLoader;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackPaths;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackRemoteFile;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Psr\Http\Message\StreamInterface;
use ZipArchive;

/**
 * Resolves one selected release into the target consumed by ModpackDeployment.
 */
final class ModpackInstaller
{
    private readonly CurseForgeManualFiles $manualFiles;

    public function __construct(
        private readonly WingsRemoteFilesystem $wings,
        private readonly CurseForgeSource $curseForge,
    ) {
        $this->manualFiles = new CurseForgeManualFiles($wings);
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
        bool $verifyInstalledRuntime = false,
        bool $unlockBackup = false,
        ?ModpackProvisioning $provisioning = null,
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
                $entries = ModpackArchive::serverFiles($zip);
                $metadata = ModpackArchive::serverPackRuntime($zip, $entries[0]);
                if ($metadata !== null) {
                    $this->assertLoaderCompatible($metadata['loader'], $loader);
                    $this->assertMinecraftCompatible($metadata['minecraft'], $minecraft);
                    $this->assertBuild($metadata['loader'], $metadata['build'], $metadata['minecraft'], $runtime);
                    $selectedBuild = $release['loader_version'] ?? null;
                    if ($selectedBuild !== null && !ModpackLoader::matchesBuild($metadata['loader'], $metadata['build'], $selectedBuild, $metadata['minecraft'])) {
                        throw new ModpackException('The server ZIP requires a different loader build from the selected release.');
                    }
                } else {
                    $this->assertBuild($release['loader'], $release['loader_version'] ?? null, $release['minecraft_version'], $runtime);
                }
                $verifiedBuild = $metadata['build'] ?? $release['loader_version'] ?? null;
                if ($verifyInstalledRuntime) {
                    $this->assertInstalledRuntime($server, $repo, $loader, $minecraft, $verifiedBuild, $runtime);
                }
                foreach ($entries as $entry) {
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
                if ($release['provider'] === ModpackManifest::FORMAT_FTB) {
                    $manifest = $this->readFtbRelease($archive, $release, $server->image);
                    $release['version_name'] = $manifest->version;
                    $release['java'] = JavaRuntimeImage::preferredLabel($manifest->minecraftVersion, $manifest->minimumJava);
                } else {
                    $manifest = ModpackArchive::read($archive, strict: true);
                }
                if ($manifest->format !== $release['provider']) {
                    throw new ModpackException('The archive manifest belongs to another provider.');
                }
                $this->assertLoaderCompatible($manifest->loader, $loader);
                $this->assertMinecraftCompatible($manifest->minecraftVersion, $minecraft);
                $this->assertBuild($manifest->loader, $manifest->loaderVersion, $manifest->minecraftVersion, $runtime);
                $verifiedBuild = $manifest->loaderVersion ?? $release['loader_version'] ?? null;
                if ($verifyInstalledRuntime) {
                    $this->assertInstalledRuntime($server, $repo, $loader, $minecraft, $verifiedBuild, $runtime);
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
                    if ($manifest->format === ModpackManifest::FORMAT_FTB && $file->size === 0) {
                        $open = static fn (): StreamInterface => Utils::streamFor('');
                    }
                    $target[$path] = $this->managedEntry($identity, $hashes['sha256'] ?? null, $file->size, $hashes,
                        $file->fileId === null ? null : (string) $file->fileId, $file->projectId === null ? null : (string) $file->projectId, $open, $file->url, $file->required, $file->fallbackUrls);
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

            $release['loader_version'] = $verifiedBuild;
            $result = app(ModpackDeployment::class)->run($server, $repo, $release, $target, $approved, $preview, $backup, $guard, $warnings, $unlockBackup, $provisioning);

            return [...$result, 'warnings' => array_values(array_unique([...$warnings, ...($result['warnings'] ?? [])])), 'runtime_build' => $verifiedBuild,
                'version_name' => $release['version_name'] ?? null, 'java' => $release['java'] ?? null];
        } finally {
            foreach ($snapshots as $snapshot) {
                $snapshot->close();
            }
            $zip?->close();
        }
    }

    private function readFtbRelease(string $path, array $release, ?string $image): ModpackManifest
    {
        $json = file_get_contents($path, length: FtbReleaseManifest::MAX_BYTES + 1);
        if ($json === false) {
            throw new ModpackException(trans('pelican-mod-manager::strings.modpacks.ftb_manifest_unavailable'));
        }
        $manifest = FtbReleaseManifest::read($json, $release['pack_id'], $release['version_id']);
        $fingerprint = $release['manifest_fingerprint'] ?? null;
        if (!is_string($fingerprint) || !hash_equals($fingerprint, FtbReleaseManifest::fingerprint($manifest))) {
            throw new ModpackException(trans('pelican-mod-manager::strings.modpacks.ftb_manifest_changed'));
        }
        if (JavaRuntimeImage::isTooOld($image, $manifest->minecraftVersion, $manifest->minimumJava)) {
            throw new ModpackException(trans('pelican-mod-manager::strings.modpacks.java_too_old', [
                'java' => JavaRuntimeImage::preferredLabel($manifest->minecraftVersion, $manifest->minimumJava),
            ]));
        }
        if (JavaRuntimeImage::isTooNew($image, $manifest->minecraftVersion, $manifest->loader)) {
            throw new ModpackException(trans('pelican-mod-manager::strings.modpacks.java_too_new', [
                'loader' => $manifest->loader ?? 'Minecraft',
                'java' => JavaRuntimeImage::maximumLabel($manifest->minecraftVersion, $manifest->loader),
            ]));
        }

        return $manifest;
    }

    private function managedEntry(?string $identity, ?string $sha256, ?int $size, array $hashes, ?string $fileId, ?string $projectId, ?callable $open, ?string $url, bool $required = false, array $fallbackUrls = []): array
    {
        return ['identity' => $identity, 'sha256' => $sha256, 'size' => $size, 'hashes' => $hashes, 'file_id' => $fileId,
            'project_id' => $projectId, 'open' => $open, 'url' => $url, 'fallback_urls' => $fallbackUrls, 'required' => $required];
    }

    private function assertInstalledRuntime(Server $server, DaemonFileRepository $repo, ?string $loader, ?string $minecraft, ?string $build, ?array $runtime): void
    {
        if ($loader === null || $minecraft === null || $runtime === null) {
            throw new ModpackException('The loader installation cannot be verified.');
        }
        (new ModpackRuntime($this->wings))->assertInstalled($server, $repo, $loader, $minecraft, $build, $runtime);
    }

    private function assertBuild(?string $loader, ?string $build, ?string $minecraft, ?array $runtime): void
    {
        if ($runtime !== null && $loader !== null && $build !== null && !ModpackLoader::hasBuild($loader, $build, $minecraft, $runtime)) {
            throw new ModpackException("The archive requires $loader $build. Verify the installed loader and its configured build.");
        }
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
        if (count($manifest->remoteFiles) > ModpackManifest::MAX_REMOTE_FILES) {
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
        $uploads = null;
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
                if ($dependency->canUpload()) {
                    $uploads ??= $this->manualFiles->uploads($server, $repository);
                }
                $snapshot = $this->manualFiles->verifiedSnapshot($server, $repository, $dependency, $remainingBytes, $uploads);
                $staged[$path] = $snapshot;
                $remainingBytes -= $snapshot->getSize() ?? 0;
                $resolved[] = $file->withPath($path, $snapshot->getSize(), $dependency->hashes);
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

    private function assertHttps(string $url): void
    {
        if (!ModpackPaths::isSafeHttpsDownloadUrl($url)) {
            throw new ModpackException('Modpack downloads must use an HTTPS URL.');
        }
    }
}
