<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use GuzzleHttp\Psr7\Utils;
use Kazaminosuke\ModManager\Exceptions\CurseForgeManualFileException;
use Kazaminosuke\ModManager\Support\CurseForgeFile;
use Kazaminosuke\ModManager\Support\ProjectArchive;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Psr\Http\Message\StreamInterface;
use ZipArchive;

final class CurseForgeManualFiles
{
    public const MAX_FILE_BYTES = 128 * 1024 * 1024;

    public const MAX_BATCH_BYTES = 512 * 1024 * 1024;

    public function __construct(private readonly WingsRemoteFilesystem $wings) {}

    /** @return array<string, array<string, mixed>> */
    public function uploads(Server $server, DaemonFileRepository $repository): array
    {
        $indexed = [];
        foreach ($this->wings->listDirectory($repository, $server, CurseForgeFile::STAGING_DIRECTORY) as $entry) {
            $name = $entry['name'] ?? null;
            if (is_string($name) && ($entry['file'] ?? false) === true && ($entry['symlink'] ?? true) === false) {
                $indexed[$name] = $entry;
            }
        }

        return $indexed;
    }

    /**
     * Return a private snapshot of the verified file. The caller closes it.
     *
     * @param  array<string, array<string, mixed>>|null  $uploads
     */
    public function verifiedSnapshot(Server $server, DaemonFileRepository $repository, CurseForgeFile $file, int $remainingBytes = self::MAX_FILE_BYTES, ?array $uploads = null): StreamInterface
    {
        if (!$file->canUpload()) {
            throw new CurseForgeManualFileException($file, $file->reason === 'distribution' ? 'unverifiable' : ($file->reason ?? 'metadata_missing'));
        }
        $uploads ??= $this->uploads($server, $repository);
        $listed = $uploads[basename($file->stagingPath())] ?? null;
        if ($listed === null) {
            throw new CurseForgeManualFileException($file, 'missing_upload');
        }
        $size = $this->checkedSize($file, $this->wings->listedFileSize($listed), $remainingBytes);

        $remote = $this->wings->readStream($repository, $server, $file->stagingPath());
        try {
            return $this->verifiedCopy($file, $remote, $size);
        } finally {
            $remote->close();
        }
    }

    public function stageUpload(Server $server, DaemonFileRepository $repository, CurseForgeFile $file, StreamInterface $source, int $size): void
    {
        if (!$file->canUpload()) {
            throw new CurseForgeManualFileException($file, 'unverifiable');
        }
        $copy = $this->verifiedCopy($file, $source, $this->checkedSize($file, $size, self::MAX_FILE_BYTES));
        try {
            $this->wings->putStream($repository, $server, $file->stagingPath(), $copy);
        } finally {
            $copy->close();
        }
    }

    private function checkedSize(CurseForgeFile $file, ?int $size, int $limit): int
    {
        if ($size === null || $size < 1 || $size > min(self::MAX_FILE_BYTES, $limit)) {
            throw new CurseForgeManualFileException($file, 'upload_size_limit');
        }
        if ($file->size !== null && $size !== $file->size) {
            throw new CurseForgeManualFileException($file, 'size_mismatch');
        }

        return $size;
    }

    private function verifiedCopy(CurseForgeFile $file, StreamInterface $source, int $size): StreamInterface
    {
        $local = tmpfile();
        if ($local === false) {
            throw new CurseForgeManualFileException($file, 'storage_error');
        }
        $copy = Utils::streamFor($local);

        try {
            if (Utils::copyToStream($source, $copy, $size + 1) !== $size) {
                throw new CurseForgeManualFileException($file, 'size_mismatch');
            }
            $path = stream_get_meta_data($local)['uri'];
            foreach ($file->hashes as $algorithm => $expected) {
                $digest = hash_file($algorithm, $path);
                if (!is_string($digest) || !hash_equals($expected, $digest)) {
                    throw new CurseForgeManualFileException($file, 'hash_mismatch');
                }
            }
            if (!$this->isJar($path)) {
                throw new CurseForgeManualFileException($file, 'invalid_jar');
            }

            $copy->rewind();

            return $copy;
        } catch (\Throwable $exception) {
            $copy->close();

            throw $exception;
        }
    }

    private function isJar(string $path): bool
    {
        $jar = new ZipArchive();
        if ($jar->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            return false;
        }

        try {
            $java = false;
            if ($jar->numFiles < 1 || $jar->numFiles > 100000) {
                return false;
            }
            for ($i = 0; $i < $jar->numFiles; $i++) {
                $name = $jar->getNameIndex($i);
                if ($name === false || preg_match('/[\x00-\x1f\x7f:]/', $name) === 1
                    || ProjectArchive::safeRelative(rtrim($name, '/')) !== rtrim($name, '/')) {
                    return false;
                }
                if ($jar->getExternalAttributesIndex($i, $system, $attributes)
                    && $system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                    return false;
                }
                if (str_ends_with($name, '.class') || in_array($name, [
                    'META-INF/mods.toml', 'META-INF/neoforge.mods.toml', 'fabric.mod.json', 'quilt.mod.json', 'mcmod.info',
                ], true)) {
                    $java = true;
                }
            }

            return $java;
        } finally {
            $jar->close();
        }
    }
}
