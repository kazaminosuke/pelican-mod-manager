<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * JAR, ZIP, and mrpack files are ZIP archives. A short header check rejects
 * HTML error pages and other non-archives before they replace a live file.
 */
final class ArchiveSignature
{
    public static function isZip(string $header): bool
    {
        return str_starts_with($header, "PK\x03\x04")
            || str_starts_with($header, "PK\x05\x06")
            || str_starts_with($header, "PK\x07\x08");
    }
}
