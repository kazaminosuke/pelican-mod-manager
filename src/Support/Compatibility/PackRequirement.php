<?php

namespace Kazaminosuke\ModManager\Support\Compatibility;

/**
 * What a modpack needs from the panel, independent of which eggs are installed.
 */
final class PackRequirement
{
    /**
     * @param  array<string, string>  $variables  env values the provisioning egg must receive
     * @param  array<string, string>  $archiveVariables  env values for a new loader installation
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
        public readonly array $archiveVariables = [],
        public readonly bool $allowUnknownLoader = false,
        public readonly ?string $compatibilityWarning = null,
        public readonly ?string $serverFileStatus = null,
        public readonly ?string $archiveCompatibilityWarning = null,
        public readonly ?string $artifactId = null,
        public readonly ?int $minimumJava = null,
        public readonly ?string $manifestFingerprint = null,
    ) {}
}
