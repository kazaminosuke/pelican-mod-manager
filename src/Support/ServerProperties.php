<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * Small, format-preserving editor for the two resource-pack properties.
 * Server.properties is line-oriented rather than a strict parser format, so
 * comments, ordering, unrelated keys, and the file's newline style remain
 * untouched.
 */
final class ServerProperties
{
    public static function withResourcePack(string $content, ?string $url, ?string $sha1): string
    {
        $newline = str_contains($content, "\r\n")
            ? "\r\n"
            : (str_contains($content, "\n") ? "\n" : (str_contains($content, "\r") ? "\r" : "\n"));
        $hasTrailingNewline = preg_match('/(?:\r\n|\n|\r)$/', $content) === 1;
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];

        if ($hasTrailingNewline) {
            array_pop($lines);
        }

        if ($content === '') {
            $lines = [];
        }

        $values = [
            'resource-pack' => $url ?? '',
            'resource-pack-sha1' => $sha1 ?? '',
        ];
        $seen = [];

        foreach ($lines as $index => $line) {
            if (!is_string($line)
                || preg_match('/^(\s*)(resource-pack(?:-sha1)?)(\s*=\s*)(.*)$/i', $line, $matches) !== 1) {
                continue;
            }

            $key = strtolower($matches[2]);
            $lines[$index] = $matches[1].$matches[2].$matches[3].$values[$key];
            $seen[$key] = true;
        }

        foreach ($values as $key => $value) {
            if (isset($seen[$key])) {
                continue;
            }

            $lines[] = $key.'='.$value;
        }

        $result = implode($newline, $lines);

        return $hasTrailingNewline ? $result.$newline : $result;
    }
}
