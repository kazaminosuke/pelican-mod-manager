<?php

namespace Kazaminosuke\ModManager\Support;

final class InstalledScanResult
{
    /**
     * A cached result younger than this is trusted as-is. An older one is
     * still displayed, while a background scan compares the Wings directory
     * listing with the recorded file signatures to pick up external changes.
     */
    public const FRESH_SECONDS = 600;

    /** @param array<int, string> $unknownFiles */
    public function __construct(
        public readonly bool $successful,
        public readonly array $unknownFiles = [],
        public readonly int $diskFileCount = 0,
        public readonly bool $cacheHit = false,
        public readonly ?string $failure = null,
        public readonly ?int $checkedAt = null,
    ) {}

    /** @param array<int, string> $unknownFiles */
    public static function success(array $unknownFiles, int $diskFileCount, ?int $checkedAt = null): self
    {
        return new self(true, array_values($unknownFiles), $diskFileCount, checkedAt: $checkedAt ?? time());
    }

    /** @param array<int, string> $unknownFiles */
    public static function failed(string $failure, array $unknownFiles = [], int $diskFileCount = 0): self
    {
        return new self(false, array_values($unknownFiles), $diskFileCount, failure: $failure);
    }

    public function asCacheHit(): self
    {
        return new self(
            successful: $this->successful,
            unknownFiles: $this->unknownFiles,
            diskFileCount: $this->diskFileCount,
            cacheHit: true,
            failure: $this->failure,
            checkedAt: $this->checkedAt,
        );
    }

    /**
     * A copy that describes the same files but must be revalidated soon,
     * e.g. after a scan could only partly identify them.
     */
    public function asStale(): self
    {
        return new self(
            successful: true,
            unknownFiles: $this->unknownFiles,
            diskFileCount: $this->diskFileCount,
            cacheHit: $this->cacheHit,
            checkedAt: null,
        );
    }

    public function isFresh(?int $now = null): bool
    {
        return $this->checkedAt !== null
            && ($now ?? time()) - $this->checkedAt < self::FRESH_SECONDS;
    }

    /** @return array<string, mixed> */
    public function toCachePayload(): array
    {
        return [
            'schema_version' => 2,
            'successful' => $this->successful,
            'unknown_files' => $this->unknownFiles,
            'disk_file_count' => $this->diskFileCount,
            'checked_at' => $this->checkedAt,
        ];
    }

    public static function fromCache(mixed $payload): ?self
    {
        if (!is_array($payload)
            || ($payload['schema_version'] ?? null) !== 2
            || ($payload['successful'] ?? null) !== true
            || !isset($payload['unknown_files'], $payload['disk_file_count'])
            || !is_array($payload['unknown_files'])
            || !is_int($payload['disk_file_count'])) {
            return null;
        }

        $unknownFiles = [];
        foreach ($payload['unknown_files'] as $filename) {
            if (is_string($filename) && $filename !== '') {
                $unknownFiles[] = $filename;
            }
        }

        // Results written before checked_at existed are valid but stale.
        $checkedAt = $payload['checked_at'] ?? null;

        return (new self(
            successful: true,
            unknownFiles: $unknownFiles,
            diskFileCount: $payload['disk_file_count'],
            checkedAt: is_int($checkedAt) ? $checkedAt : null,
        ))->asCacheHit();
    }
}
