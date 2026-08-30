<?php

namespace Kazaminosuke\ModManager\Support;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;

/**
 * Thin Wings file operations used by install/update transactions.
 *
 * Panel's DaemonFileRepository::pull() uses the default Guzzle timeout (15s)
 * and does not wait for a background download. Wings only blocks until the
 * archive is fully written when `foreground` is true, and then returns 200
 * with the written file Stat. Rename refuses to overwrite an existing
 * destination, so callers must swap via a backup name.
 */
class WingsRemoteFilesystem
{
    /**
     * Match Wings' remote-download request timeout (15 minutes) and Panel's
     * archive compress/decompress daemon client timeout. The default Panel
     * Guzzle timeout of 15 seconds is shorter than a single foreground pull.
     */
    public const FOREGROUND_PULL_TIMEOUT_SECONDS = 900;

    /**
     * @return array<string, mixed> Wings file Stat for the completed download
     */
    public function pullForeground(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $url,
        string $directory,
        string $filename,
    ): array {
        $response = $fileRepository
            ->setServer($server)
            ->getHttpClient()
            ->timeout(self::FOREGROUND_PULL_TIMEOUT_SECONDS)
            ->post("/api/servers/{$server->uuid}/files/pull", array_filter([
                'url' => $url,
                'root' => $directory === '' ? '/' : $directory,
                'file_name' => $filename,
                'foreground' => true,
            ], fn (mixed $value): bool => $value !== null));

        if ($response->status() === 202) {
            throw new Exception('Wings accepted a background pull; foreground completion is required.');
        }

        $response->throw();

        $stat = $response->json();

        return is_array($stat) ? $stat : [];
    }

    public function move(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $from,
        string $to,
    ): void {
        $fileRepository
            ->setServer($server)
            ->renameFiles($directory === '' ? '/' : $directory, [
                ['from' => $from, 'to' => $to],
            ])
            ->throw();
    }

    public function delete(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $filename,
    ): void {
        $fileRepository
            ->setServer($server)
            ->deleteFiles($directory === '' ? '/' : $directory, [$filename])
            ->throw();
    }

    public function deleteQuietly(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $filename,
    ): void {
        try {
            $this->delete($fileRepository, $server, $directory, $filename);
        } catch (Exception $exception) {
            report($exception);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDirectory(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
    ): array {
        $listing = $fileRepository
            ->setServer($server)
            ->getDirectory($directory === '' ? '/' : $directory);

        return is_array($listing) ? array_values($listing) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findListedFile(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $filename,
    ): ?array {
        $target = strtolower($filename);

        foreach ($this->listDirectory($fileRepository, $server, $directory) as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null)) {
                continue;
            }

            if (strtolower($item['name']) === $target && ($item['directory'] ?? false) !== true) {
                return $item;
            }
        }

        return null;
    }

    public function listedFileSize(array $item): ?int
    {
        $size = $item['size'] ?? null;

        if (is_numeric($size) && (int) $size >= 0) {
            return (int) $size;
        }

        return null;
    }
}
