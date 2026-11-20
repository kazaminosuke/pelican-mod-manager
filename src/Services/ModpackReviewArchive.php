<?php

namespace Kazaminosuke\ModManager\Services;

/** A bounded, local cache of the archive bytes used to produce an update preview. */
final class ModpackReviewArchive
{
    private const MAX_FILE_BYTES = 64 * 1024 ** 2;

    private const MAX_CACHE_BYTES = 256 * 1024 ** 2;

    private const TTL = 86400;

    public function restore(string $hash, string $destination): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            return false;
        }
        $cached = $this->directory().'/'.$hash.'.zip';
        $mtime = is_file($cached) ? @filemtime($cached) : false;

        if ($mtime === false || $mtime <= time() - self::TTL || !@copy($cached, $destination)) {
            return false;
        }
        if (hash_file('sha256', $destination) === $hash) {
            return true;
        }
        @unlink($cached);

        return false;
    }

    public function remember(string $archive, string $hash): void
    {
        $size = filesize($archive);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return;
        }
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        $lock = @fopen($directory.'/.lock', 'c');
        if ($lock === false) {
            return;
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }
            foreach (glob($directory.'/pending-*') ?: [] as $abandoned) {
                @unlink($abandoned);
            }
            $path = $directory.'/'.$hash.'.zip';
            $files = [];
            $total = 0;
            foreach (glob($directory.'/*.zip') ?: [] as $file) {
                $mtime = @filemtime($file);
                $bytes = @filesize($file);
                if ($mtime === false || $bytes === false) {
                    continue;
                }
                if ($mtime <= time() - self::TTL && @unlink($file)) {
                    continue;
                }
                if ($file === $path) {
                    return;
                }
                $files[$file] = ['mtime' => $mtime, 'bytes' => $bytes];
                $total += $bytes;
            }
            uasort($files, static fn (array $left, array $right) => $left['mtime'] <=> $right['mtime']);
            foreach ($files as $file => $item) {
                if ($total + $size <= self::MAX_CACHE_BYTES) {
                    break;
                }
                if (@unlink($file)) {
                    $total -= $item['bytes'];
                }
            }
            if ($total + $size > self::MAX_CACHE_BYTES) {
                return;
            }
            $temp = tempnam($directory, 'pending-');
            if ($temp !== false && dirname($temp) === $directory) {
                try {
                    if (@copy($archive, $temp)) {
                        @rename($temp, $path);
                    }
                } finally {
                    if (is_file($temp)) {
                        @unlink($temp);
                    }
                }
            } elseif ($temp !== false) {
                @unlink($temp);
            }
        } finally {
            fclose($lock);
        }
    }

    private function directory(): string
    {
        return storage_path('app/private/mod-manager-review');
    }
}
