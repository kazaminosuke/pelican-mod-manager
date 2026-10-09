<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

/**
 * One mod (or other pack file) that is downloaded separately from the pack zip.
 */
final class ModpackRemoteFile
{
    /**
     * @param  array<string, string>  $hashes
     */
    public function __construct(
        public readonly ?string $path,
        public readonly ?string $url,
        public readonly ?int $projectId,
        public readonly ?int $fileId,
        public readonly bool $required,
        public readonly ?int $size,
        public readonly array $hashes = [],
    ) {}

    public function withDownload(string $path, string $url, ?int $size): self
    {
        return new self($path, $url, $this->projectId, $this->fileId, $this->required, $size ?? $this->size, $this->hashes);
    }
}
