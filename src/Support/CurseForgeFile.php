<?php

namespace Kazaminosuke\ModManager\Support;

final readonly class CurseForgeFile
{
    public const STAGING_DIRECTORY = '.mod-manager-curseforge';

    /** @param array<string, string> $hashes */
    public function __construct(
        public int $projectId,
        public int $fileId,
        public ?string $filename = null,
        public ?string $url = null,
        public ?int $size = null,
        public array $hashes = [],
        public ?string $name = null,
        public ?string $page = null,
        public ?string $reason = null,
    ) {}

    public function canUpload(): bool
    {
        return $this->reason === 'distribution'
            && $this->filename !== null
            && str_ends_with(strtolower($this->filename), '.jar')
            && $this->hashes !== [];
    }

    public function stagingPath(): string
    {
        return self::STAGING_DIRECTORY."/{$this->projectId}-{$this->fileId}.jar";
    }

    /** @return array<string, mixed> */
    public function details(bool $required, ?string $reason = null): array
    {
        return [
            'project_id' => $this->projectId,
            'file_id' => $this->fileId,
            'filename' => $this->filename,
            'name' => $this->name,
            'size' => $this->size,
            'hashes' => $this->hashes,
            'page' => $this->page,
            'required' => $required,
            'automatic' => $this->url !== null,
            'reason' => $reason ?? $this->reason,
            'staging_path' => $this->canUpload() ? $this->stagingPath() : null,
        ];
    }
}
