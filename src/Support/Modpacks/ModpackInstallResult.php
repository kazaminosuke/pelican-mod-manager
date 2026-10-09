<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

final class ModpackInstallResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly int $installed,
        public readonly int $skipped,
        public readonly array $warnings,
    ) {}

    /** @return array{name: string, version: string, installed: int, skipped: int, warnings: list<string>} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'installed' => $this->installed,
            'skipped' => $this->skipped,
            'warnings' => $this->warnings,
        ];
    }
}
