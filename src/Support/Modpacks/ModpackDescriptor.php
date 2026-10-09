<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

/**
 * Normalized modpack metadata. Provider clients fill this; egg selection does not.
 */
final class ModpackDescriptor
{
    /**
     * @param  array<string, string>  $variables
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $versionId,
        public readonly ?string $versionName,
        public readonly ?string $minecraftVersion,
        public readonly ?string $loader,
        public readonly ?string $loaderVersion,
        public readonly ?string $iconUrl,
        public readonly ?string $provisioningProfileId,
        public readonly array $variables,
        public readonly bool $archiveAllowed,
        public readonly ?string $unsupportedReason,
        public readonly ?string $downloadUrl = null,
        public readonly ?string $filename = null,
    ) {}

    public function optionKey(): string
    {
        return $this->provider.':'.$this->id;
    }

    public function optionLabel(): string
    {
        $provider = match ($this->provider) {
            'ftb' => 'FTB',
            'atlauncher' => 'ATLauncher',
            default => ucfirst($this->provider),
        };

        return $this->title.' ('.$provider.')';
    }
}
