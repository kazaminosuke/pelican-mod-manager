<?php

namespace Kazaminosuke\ModManager\Support\Compatibility;

/**
 * What a modpack needs from the panel, independent of which eggs are installed.
 */
final class PackRequirement
{
    /**
     * @param  array<string, string>  $variables  env values the provisioning egg must receive
     * @param  list<string>  $loaderProfileIds  profile ids whose loader may receive an archive install
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $packId,
        public readonly ?string $versionId,
        public readonly ?string $minecraftVersion,
        public readonly ?string $loader,
        public readonly ?string $provisioningProfileId,
        public readonly array $variables,
        public readonly bool $archiveAllowed,
        public readonly array $loaderProfileIds,
        public readonly ?string $unsupportedReason = null,
    ) {}
}
