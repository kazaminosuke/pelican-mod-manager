<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

/**
 * The verified deployment requirements read from a modpack artifact.
 */
final class ModpackManifest
{
    public const FORMAT_MODRINTH = 'modrinth';

    public const FORMAT_CURSEFORGE = 'curseforge';

    public const FORMAT_FTB = 'ftb';

    public const MAX_REMOTE_FILES = 20000;

    /**
     * @param  list<ModpackRemoteFile>  $remoteFiles
     * @param  list<ModpackOverrideFile>  $overrides
     * @param  list<string>  $skipped
     */
    public function __construct(
        public readonly string $format,
        public readonly string $name,
        public readonly string $version,
        public readonly ?string $minecraftVersion,
        public readonly ?string $loader,
        public readonly ?string $loaderVersion,
        public readonly array $remoteFiles,
        public readonly array $overrides,
        public readonly array $skipped,
        public readonly ?int $minimumJava = null,
    ) {}
}
