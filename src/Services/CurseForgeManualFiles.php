<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use GuzzleHttp\Psr7\Utils;
use Kazaminosuke\ModManager\Exceptions\CurseForgeManualFileException;
use Kazaminosuke\ModManager\Support\Modpacks\CurseForgeFile;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackPaths;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Psr\Http\Message\StreamInterface;
use ZipArchive;

final class CurseForgeManualFiles
{
    public const MAX_FILE_BYTES = 128 * 1024 * 1024;

    public const MAX_BATCH_BYTES = 512 * 1024 * 1024;

    public function __construct(private readonly WingsRemoteFilesystem $wings) {}

    /** Return a private snapshot of the verified file. The caller closes it. */
    public function verifiedSnapshot(Server $server, DaemonFileRepository $repository, CurseForgeFile $file, int $remainingBytes = self::MAX_FILE_BYTES): StreamInterface
    {
        if (!$file->canUpload()) {
            throw new CurseForgeManualFileException($file, $file->reason === 'distribution' ? 'unverifiable' : ($file->reason ?? 'metadata_missing'));
        }
        [$directory, $filename] = ModpackPaths::split($file->stagingPath());
        $listed = $this->wings->findListedFile($repository, $server, $directory, $filename);
        if ($listed === null) {
            throw new CurseForgeManualFileException($file, 'missing_upload');
        }
        $size = $this->wings->listedFileSize($listed);
        if ($size === null || $size < 1 || $size > min(self::MAX_FILE_BYTES, $remainingBytes)) {
            throw new CurseForgeManualFileException($file, 'upload_size_limit');
        }
        if ($file->size !== null && $size !== $file->size) {
            throw new CurseForgeManualFileException($file, 'size_mismatch');
        }

        $local = tmpfile();
        if ($local === false) {
            throw new CurseForgeManualFileException($file, 'storage_error');
        }
        try {
            $remote = $this->wings->readStream($repository, $server, $file->stagingPath());
            try {
                $copy = Utils::streamFor($local);
                Utils::copyToStream($remote, $copy, $size + 1);
            } finally {
                $remote->close();
            }

            if (ftell($local) !== $size) {
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
            fclose($local);

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
                    || ModpackPaths::safeRelative(rtrim($name, '/')) !== rtrim($name, '/')) {
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
