<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

/**
 * A config or data file stored inside the pack zip.
 */
final class ModpackOverrideFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $contents,
    ) {}
}
