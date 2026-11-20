<?php

namespace Kazaminosuke\ModManager\Services;

use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Support\Compatibility\ClassifiedEgg;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityDisposition;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityResult;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\Compatibility\PackRequirement;
use Kazaminosuke\ModManager\Support\Compatibility\ProvisioningMode;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDescriptor;

/**
 * Turns pack metadata into the requirement the shared egg engine understands.
 */
final class ModpackProvisioningPlanner
{
    public function __construct(
        private readonly EggCompatibilityEngine $engine,
    ) {}

    public function requirement(ModpackDescriptor $pack): PackRequirement
    {
        $profile = $pack->provisioningProfileId;
        $variables = $pack->variables;
        $archive = $pack->archiveAllowed;
        $unsupported = $pack->unsupportedReason;

        if (in_array($pack->provider, [ModpackProvider::Modrinth->value, ModpackProvider::CurseForge->value], true)) {
            $profile = null;
            $variables = [];
        }
        if ($pack->provider === ModpackProvider::Ftb->value) {
            $unsupported = trans('pelican-mod-manager::strings.modpacks.ftb_install_unverified');
        }

        $loaderProfiles = match ($pack->loader) {
            'fabric' => ['fabric'],
            'forge' => ['forge'],
            'neoforge' => ['neoforge'],
            'quilt' => ['quilt'],
            default => [],
        };

        if ($unsupported === null && $profile === null && !$archive) {
            $unsupported = 'No provisioning egg or loader archive is available for this modpack.';
        }

        if ($unsupported !== null) {
            $archive = false;
            $profile = null;
        }

        $archiveVariables = [];
        if ($pack->minecraftVersion !== null) {
            $archiveVariables = ['MC_VERSION' => $pack->minecraftVersion, 'MINECRAFT_VERSION' => $pack->minecraftVersion];
        }
        if ($pack->loader !== null && $pack->loaderVersion !== null) {
            $variable = $pack->loader === 'fabric' ? 'LOADER_VERSION' : strtoupper($pack->loader).'_VERSION';
            $build = $pack->loaderVersion;
            if ($pack->loader === 'forge' && $pack->minecraftVersion !== null && !str_contains($build, '-')) {
                // The official Forge Egg uses a full Maven version when FORGE_VERSION is set.
                $build = $pack->minecraftVersion.'-'.$build;
                if (in_array($pack->minecraftVersion, ['1.7.10', '1.8.9'], true)) {
                    $build .= '-'.$pack->minecraftVersion;
                }
            }
            $archiveVariables[$variable] = $build;
        }

        $curseForge = $pack->provider === ModpackProvider::CurseForge->value;

        return new PackRequirement(
            provider: $pack->provider,
            packId: $pack->id,
            versionId: $pack->versionId,
            minecraftVersion: $pack->minecraftVersion,
            loader: $pack->loader,
            provisioningProfileId: $profile,
            variables: $variables,
            archiveAllowed: $archive && $unsupported === null,
            loaderProfileIds: $unsupported === null && $archive ? $loaderProfiles : [],
            unsupportedReason: $unsupported,
            archiveVariables: $archiveVariables,
            allowUnknownLoader: $curseForge && $archive && $pack->loader === null,
            compatibilityWarning: $pack->compatibilityWarning ?? match (true) {
                $curseForge && (!$pack->usesServerPack || $pack->loader === null || $pack->minecraftVersion === null) => 'server_compatibility_unknown',
                default => null,
            },
            archiveCompatibilityWarning: $pack->loaderVersion === null ? 'loader_build_unknown' : null,
            artifactId: $pack->artifactId,
            serverFileStatus: match (true) {
                $pack->usesServerPack => 'server_pack_selected',
                $pack->hasServerPack === true => 'server_pack_unavailable',
                $pack->hasServerPack === false => 'server_pack_unlisted',
                default => null,
            },
        );
    }

    /**
     * @param  list<ClassifiedEgg>  $eggs
     * @param  array<string, string>  $currentVariables
     */
    public function select(
        ModpackDescriptor $pack,
        array $eggs,
        string $context,
        int|string|null $currentEggId = null,
        array $currentVariables = [],
        int|string|null $explicitEggId = null,
        bool $automatic = true,
    ): CompatibilityResult {
        return $this->engine->select(
            $this->requirement($pack),
            $eggs,
            $context,
            $currentEggId,
            $currentVariables,
            $explicitEggId,
            $automatic,
        );
    }

    public function summary(ModpackDescriptor $pack, CompatibilityResult $result): string
    {
        $lines = [
            $pack->title,
            $pack->provider,
        ];
        if ($pack->versionName !== null && $pack->versionName !== '') {
            $lines[] = $pack->versionName;
        }
        if ($pack->minecraftVersion !== null) {
            $lines[] = 'Minecraft '.$pack->minecraftVersion;
        }
        if ($pack->loader !== null) {
            $lines[] = $pack->loader;
        }

        $lines[] = $result->reasonDetail;
        if ($result->selected !== null) {
            $lines[] = $result->selected->name.' ('.$result->selected->confidence.')';
        }
        if ($result->disposition === CompatibilityDisposition::Ambiguous) {
            $names = array_map(static fn ($egg): string => $egg->name, $result->alternatives);
            $lines[] = implode(', ', $names);
        }
        if ($result->mode === ProvisioningMode::EggInstall) {
            $lines[] = 'The egg install script installs this modpack when the server is installed.';
        } elseif ($result->mode === ProvisioningMode::Archive) {
            $lines[] = 'Mod Manager installs the pack files. Worlds and protected files are kept.';
        }

        return implode("\n", $lines);
    }
}
