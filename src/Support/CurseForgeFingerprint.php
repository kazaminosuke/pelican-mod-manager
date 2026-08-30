<?php

namespace Kazaminosuke\ModManager\Support;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Computes CurseForge's file "fingerprint": a MurmurHash2 (32-bit, seed 1) over
 * the file's bytes with whitespace bytes (tab, LF, CR, space) stripped out first.
 *
 * This matches the algorithm CurseForge itself uses to identify files (see
 * POST /v1/fingerprints), independently confirmed against community references
 * (e.g. packwiz's curseforge/murmur2 package) and the core MurmurHash2 mixing
 * function verified against Apache Commons Codec's published test vectors.
 */
class CurseForgeFingerprint
{
    private const SEED = 1;

    private const M = 0x5BD1E995;

    private const R = 24;

    public static function hash(string $content): int
    {
        $filtered = str_replace(["\x09", "\x0A", "\x0D", "\x20"], '', $content);

        return self::murmurHash2($filtered, self::SEED);
    }

    /**
     * Computes a fingerprint with one read of the source stream.
     *
     * MurmurHash2 needs the filtered length before hashing. Filtered bytes are
     * therefore spooled to a Symfony-managed temporary file for the local
     * second pass instead of buffering a complete JAR in PHP memory. The
     * optional callback receives each raw chunk during the single source read
     * so callers can calculate cryptographic hashes at the same time.
     */
    public static function hashStream(callable $openStream, ?callable $consumeRawChunk = null): int
    {
        $filesystem = new Filesystem();
        $filteredPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'mmr-cf-fingerprint-'.bin2hex(random_bytes(16));
        $filesystem->dumpFile($filteredPath, '');
        $filteredStream = null;

        try {
            $length = 0;
            $sourceStream = $openStream();

            try {
                while (!$sourceStream->eof()) {
                    $chunk = $sourceStream->read(1024 * 1024);

                    if ($chunk === '') {
                        continue;
                    }

                    if ($consumeRawChunk !== null) {
                        $consumeRawChunk($chunk);
                    }

                    $filtered = str_replace(["\x09", "\x0A", "\x0D", "\x20"], '', $chunk);
                    $length += strlen($filtered);

                    if ($filtered !== '') {
                        $filesystem->appendToFile($filteredPath, $filtered);
                    }
                }
            } finally {
                $sourceStream->close();
            }

            $filteredStream = fopen($filteredPath, 'rb');

            if ($filteredStream === false) {
                throw new \RuntimeException('Unable to open temporary stream for CurseForge fingerprint');
            }

            if (!rewind($filteredStream)) {
                throw new \RuntimeException('Unable to rewind CurseForge fingerprint data');
            }

            return self::murmurHash2Stream($filteredStream, $length);
        } finally {
            if (is_resource($filteredStream)) {
                fclose($filteredStream);
            }

            $filesystem->remove($filteredPath);
        }
    }

    /** @param resource $stream */
    private static function murmurHash2Stream($stream, int $length): int
    {
        $h = (self::SEED ^ $length) & 0xFFFFFFFF;
        $remainder = '';

        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);

            if ($chunk === false) {
                throw new \RuntimeException('Unable to read spooled CurseForge fingerprint data');
            }

            if ($chunk === '') {
                continue;
            }

            $data = $remainder . $chunk;
            $processableLength = strlen($data) - (strlen($data) % 4);

            for ($i = 0; $i < $processableLength; $i += 4) {
                $k = (ord($data[$i]) | (ord($data[$i + 1]) << 8) | (ord($data[$i + 2]) << 16) | (ord($data[$i + 3]) << 24)) & 0xFFFFFFFF;
                $k = ($k * self::M) & 0xFFFFFFFF;
                $k ^= $k >> self::R;
                $k = ($k * self::M) & 0xFFFFFFFF;
                $h = ($h * self::M) & 0xFFFFFFFF;
                $h ^= $k;
            }

            $remainder = substr($data, $processableLength);
        }

        $remaining = strlen($remainder);

        if ($remaining === 3) {
            $h ^= ord($remainder[2]) << 16;
        }
        if ($remaining >= 2) {
            $h ^= ord($remainder[1]) << 8;
        }
        if ($remaining >= 1) {
            $h ^= ord($remainder[0]);
            $h = ($h * self::M) & 0xFFFFFFFF;
        }

        $h ^= $h >> 13;
        $h = ($h * self::M) & 0xFFFFFFFF;
        $h ^= $h >> 15;

        return $h;
    }

    private static function murmurHash2(string $data, int $seed): int
    {
        $length = strlen($data);
        $h = ($seed ^ $length) & 0xFFFFFFFF;

        $i = 0;
        while ($length - $i >= 4) {
            $k = (ord($data[$i]) | (ord($data[$i + 1]) << 8) | (ord($data[$i + 2]) << 16) | (ord($data[$i + 3]) << 24)) & 0xFFFFFFFF;

            $k = ($k * self::M) & 0xFFFFFFFF;
            $k ^= $k >> self::R;
            $k = ($k * self::M) & 0xFFFFFFFF;

            $h = ($h * self::M) & 0xFFFFFFFF;
            $h ^= $k;

            $i += 4;
        }

        $remaining = $length - $i;

        if ($remaining === 3) {
            $h ^= ord($data[$i + 2]) << 16;
        }
        if ($remaining >= 2) {
            $h ^= ord($data[$i + 1]) << 8;
        }
        if ($remaining >= 1) {
            $h ^= ord($data[$i]);
            $h = ($h * self::M) & 0xFFFFFFFF;
        }

        $h ^= $h >> 13;
        $h = ($h * self::M) & 0xFFFFFFFF;
        $h ^= $h >> 15;

        return $h;
    }
}
