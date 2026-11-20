<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

use Illuminate\Support\Facades\Http;
use Throwable;

final class CurseForgeManifestReader
{
    private const LIMIT = 1048576;

    /** Read one root metadata file from the authorized download URL. */
    public function read(string $url, string $entryName = 'manifest.json', bool $allowWrapper = false): ?string
    {
        if (!ModpackPaths::isSafeHttpsDownloadUrl($url)
            || !in_array(parse_url($url, PHP_URL_HOST), ['edge.forgecdn.net', 'mediafilez.forgecdn.net', 'media.forgecdn.net'], true)
            || parse_url($url, PHP_URL_PORT) !== null) {
            return null;
        }

        try {
            $tail = $this->range($url, '-'.self::LIMIT);
            if ($tail === null || $tail['size'] !== $tail['start'] + strlen($tail['body'])) {
                return null;
            }

            $eocd = strrpos($tail['body'], "PK\x05\x06");
            if ($eocd === false || strlen($tail['body']) - $eocd < 22) {
                return null;
            }

            $end = unpack('vdisk/vcentral_disk/vdisk_entries/ventries/Vlength/Voffset/vcomment', $tail['body'], $eocd + 4);
            if ($end['disk'] !== 0 || $end['central_disk'] !== 0 || $end['disk_entries'] !== $end['entries']
                || $end['entries'] === 65535 || $end['length'] > self::LIMIT
                || $eocd + 22 + $end['comment'] !== strlen($tail['body'])
                || $end['offset'] + $end['length'] !== $tail['start'] + $eocd) {
                return null;
            }

            $directory = $end['offset'] >= $tail['start']
                ? substr($tail['body'], $end['offset'] - $tail['start'], $end['length'])
                : $this->slice($url, $end['offset'], $end['length'], $tail['size']);
            if ($directory === null) {
                return null;
            }

            $manifest = null;
            $offset = 0;
            for ($entry = 0; $entry < $end['entries']; $entry++) {
                if (strlen($directory) - $offset < 46 || substr($directory, $offset, 4) !== "PK\x01\x02") {
                    return null;
                }

                $file = unpack('vflags/vmethod/x4/Vcrc/Vcompressed/Vuncompressed/vname/vextra/vcomment/vdisk/x6/Voffset', $directory, $offset + 8);
                $name = substr($directory, $offset + 46, $file['name']);
                $offset += 46 + $file['name'] + $file['extra'] + $file['comment'];
                if ($name === $entryName || ($allowWrapper && substr_count($name, '/') === 1 && basename($name) === $entryName)) {
                    if ($manifest !== null) {
                        return null;
                    }
                    $manifest = [...$file, 'path' => $name];
                }
            }

            if ($offset !== strlen($directory) || $manifest === null || $manifest['disk'] !== 0
                || ($manifest['flags'] & 1) !== 0 || $manifest['uncompressed'] > self::LIMIT
                || $manifest['compressed'] > self::LIMIT - 65594 || $manifest['compressed'] < 1) {
                return null;
            }

            $length = min(65594 + $manifest['compressed'], $end['offset'] - $manifest['offset']);
            $local = $this->slice($url, $manifest['offset'], $length, $tail['size']);
            if ($local === null || strlen($local) < 30 || substr($local, 0, 4) !== "PK\x03\x04") {
                return null;
            }

            $header = unpack('vname/vextra', $local, 26);
            $compressed = substr($local, 30 + $header['name'] + $header['extra'], $manifest['compressed']);
            if (substr($local, 30, $header['name']) !== $manifest['path'] || strlen($compressed) !== $manifest['compressed']) {
                return null;
            }

            $json = match ($manifest['method']) {
                0 => $compressed,
                8 => @gzinflate($compressed, self::LIMIT),
                default => false,
            };

            return is_string($json) && strlen($json) === $manifest['uncompressed'] && crc32($json) === $manifest['crc']
                ? $json : null;
        } catch (Throwable) {
            // Metadata discovery is optional. The operator can set the build.
            return null;
        }
    }

    private function slice(string $url, int $offset, int $length, int $size): ?string
    {
        if ($offset < 0 || $length < 1 || $length > self::LIMIT || $offset + $length > $size) {
            return null;
        }

        $range = $this->range($url, $offset.'-'.($offset + $length - 1));

        return $range !== null && $range['start'] === $offset && $range['size'] === $size && strlen($range['body']) === $length
            ? $range['body'] : null;
    }

    /** @return array{start: int, size: int, body: string}|null */
    private function range(string $url, string $bytes): ?array
    {
        $response = Http::withHeaders(['Range' => 'bytes='.$bytes, 'Accept-Encoding' => 'identity'])
            ->timeout(4)->connectTimeout(2)
            ->withOptions(['stream' => true, 'read_timeout' => 3, 'allow_redirects' => false])->get($url);
        $stream = $response->toPsrResponse()->getBody();
        try {
            if ($response->status() !== 206
                || preg_match('/^bytes (\d{1,10})-(\d{1,10})\/(\d{1,10})$/D', $response->header('Content-Range'), $match) !== 1) {
                return null;
            }

            [, $start, $end, $size] = array_map('intval', $match);
            if ($end < $start || $end >= $size || $end - $start >= self::LIMIT) {
                return null;
            }

            $length = $end - $start + 1;
            $body = '';
            while (strlen($body) < $length) {
                $chunk = $stream->read(min(65536, $length - strlen($body)));
                if ($chunk === '') {
                    return null;
                }
                $body .= $chunk;
            }

            return ['start' => $start, 'size' => $size, 'body' => $body];
        } finally {
            $stream->close();
        }
    }
}
