<?php

namespace Kazaminosuke\ModManager\Support;

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

    private const WHITESPACE_BYTES = ["\x09", "\x0A", "\x0D", "\x20"];

    /** Keep small/medium archives in RAM and spill larger ones to disk automatically. */
    private const TEMP_STREAM_MAX_MEMORY_BYTES = 4 * 1024 * 1024;

    private const STREAM_CHUNK_BYTES = 65_536;

    public static function hash(string $content): int
    {
        $filtered = str_replace(self::WHITESPACE_BYTES, '', $content);

        return self::murmurHash2($filtered, self::SEED);
    }

    /**
     * Computes a fingerprint with one read of the source stream.
     *
     * MurmurHash2 needs the filtered length before hashing. Filtered bytes are
     * spooled to a php://temp stream (in-memory up to 4 MiB, spilled to disk
     * above that) instead of repeatedly opening/closing a temp file per chunk.
     * The optional callback receives each raw chunk during the single source
     * read so callers can calculate cryptographic hashes or spool the archive
     * at the same time.
     */
    public static function hashStream(callable $openStream, ?callable $consumeRawChunk = null): int
    {
        $filteredStream = fopen('php://temp/maxmemory:'.self::TEMP_STREAM_MAX_MEMORY_BYTES, 'w+b');

        if ($filteredStream === false) {
            throw new \RuntimeException('Unable to open temporary stream for CurseForge fingerprint');
        }

        try {
            $length = 0;
            $sourceStream = $openStream();

            try {
                while (!$sourceStream->eof()) {
                    $chunk = $sourceStream->read(self::STREAM_CHUNK_BYTES);

                    if ($chunk === '') {
                        continue;
                    }

                    if ($consumeRawChunk !== null) {
                        $consumeRawChunk($chunk);
                    }

                    $filtered = str_replace(self::WHITESPACE_BYTES, '', $chunk);
                    $filteredLen = strlen($filtered);

                    if ($filteredLen > 0) {
                        $length += $filteredLen;
                        if (fwrite($filteredStream, $filtered) !== $filteredLen) {
                            throw new \RuntimeException('Unable to write spooled CurseForge fingerprint data');
                        }
                    }
                }
            } finally {
                $sourceStream->close();
            }

            if (!rewind($filteredStream)) {
                throw new \RuntimeException('Unable to rewind CurseForge fingerprint data');
            }

            return self::murmurHash2Stream($filteredStream, $length);
        } finally {
            fclose($filteredStream);
        }
    }

    /**
     * Computes a fingerprint from an existing local file in one read pass,
     * optionally forwarding raw chunks to compute cryptographic hashes at the
     * same time.
     */
    public static function hashFile(string $path, ?callable $consumeRawChunk = null): int
    {
        $source = fopen($path, 'rb');

        if ($source === false) {
            throw new \RuntimeException("Unable to open [{$path}] for CurseForge fingerprint");
        }

        $filteredStream = fopen('php://temp/maxmemory:'.self::TEMP_STREAM_MAX_MEMORY_BYTES, 'w+b');

        if ($filteredStream === false) {
            fclose($source);

            throw new \RuntimeException('Unable to open temporary stream for CurseForge fingerprint');
        }

        try {
            $length = 0;

            while (!feof($source)) {
                $chunk = fread($source, self::STREAM_CHUNK_BYTES);

                if ($chunk === false) {
                    throw new \RuntimeException("Unable to read [{$path}] for CurseForge fingerprint");
                }

                if ($chunk === '') {
                    continue;
                }

                if ($consumeRawChunk !== null) {
                    $consumeRawChunk($chunk);
                }

                $filtered = str_replace(self::WHITESPACE_BYTES, '', $chunk);
                $filteredLen = strlen($filtered);

                if ($filteredLen > 0) {
                    $length += $filteredLen;
                    if (fwrite($filteredStream, $filtered) !== $filteredLen) {
                        throw new \RuntimeException('Unable to write spooled CurseForge fingerprint data');
                    }
                }
            }

            if (!rewind($filteredStream)) {
                throw new \RuntimeException('Unable to rewind CurseForge fingerprint data');
            }

            return self::murmurHash2Stream($filteredStream, $length);
        } finally {
            fclose($source);
            fclose($filteredStream);
        }
    }

    /** @param resource $stream */
    private static function murmurHash2Stream($stream, int $length): int
    {
        $h = (self::SEED ^ $length) & 0xFFFFFFFF;
        $remainder = '';

        while (!feof($stream)) {
            $chunk = fread($stream, self::STREAM_CHUNK_BYTES);

            if ($chunk === false) {
                throw new \RuntimeException('Unable to read spooled CurseForge fingerprint data');
            }

            if ($chunk === '') {
                continue;
            }

            $data = $remainder.$chunk;
            $dataLen = strlen($data);
            $processableLength = $dataLen - ($dataLen & 3);

            if ($processableLength > 0) {
                /** @var array<int, int>|false $words */
                $words = unpack('V*', $processableLength === $dataLen ? $data : substr($data, 0, $processableLength));

                if ($words !== false) {
                    foreach ($words as $k) {
                        $k = ($k * self::M) & 0xFFFFFFFF;
                        $k ^= $k >> self::R;
                        $k = ($k * self::M) & 0xFFFFFFFF;
                        $h = ($h * self::M) & 0xFFFFFFFF;
                        $h ^= $k;
                    }
                }
            }

            $remainder = $processableLength === $dataLen ? '' : substr($data, $processableLength);
        }

        return self::finalizeMurmurHash2($h, $remainder);
    }

    private static function murmurHash2(string $data, int $seed): int
    {
        $length = strlen($data);
        $h = ($seed ^ $length) & 0xFFFFFFFF;
        $processableLength = $length - ($length & 3);

        for ($offset = 0; $offset < $processableLength; $offset += self::STREAM_CHUNK_BYTES) {
            $sliceLen = min(self::STREAM_CHUNK_BYTES, $processableLength - $offset);
            /** @var array<int, int>|false $words */
            $words = unpack('V*', substr($data, $offset, $sliceLen));

            if ($words !== false) {
                foreach ($words as $k) {
                    $k = ($k * self::M) & 0xFFFFFFFF;
                    $k ^= $k >> self::R;
                    $k = ($k * self::M) & 0xFFFFFFFF;
                    $h = ($h * self::M) & 0xFFFFFFFF;
                    $h ^= $k;
                }
            }
        }

        $remainder = $processableLength === $length ? '' : substr($data, $processableLength);

        return self::finalizeMurmurHash2($h, $remainder);
    }

    private static function finalizeMurmurHash2(int $h, string $remainder): int
    {
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
}
