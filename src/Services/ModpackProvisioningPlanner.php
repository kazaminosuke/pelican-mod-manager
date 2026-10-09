<?php

namespace Kazaminosuke\ModManager\Services;

use Kazaminosuke\ModManager\Enums\ModpackProvider;
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

    public function requirement(ModpackDescriptor $pack, ?string $curseForgeApiKey = null): PackRequirement
    {
        $profile = $pack->provisioningProfileId;
        $variables = $pack->variables;
        $archive = $pack->archiveAllowed;
        $unsupported = $pack->unsupportedReason;

        if ($pack->provider === ModpackProvider::CurseForge->value && $profile === 'curseforge-generic') {
            if (!is_string($curseForgeApiKey) || trim($curseForgeApiKey) === '') {
                $profile = null;
            } else {
                $variables['API_KEY'] = trim($curseForgeApiKey);
            }
        }

        if ($pack->provider === ModpackProvider::Modrinth->value && $profile === 'modrinth-generic') {
            $projectOk = preg_match('/^[A-Za-z0-9]{1,8}$/', $pack->id) === 1;
            $versionOk = is_string($pack->versionId) && preg_match('/^[A-Za-z0-9]{1,8}$/', $pack->versionId) === 1;
            if (!$projectOk || !$versionOk) {
                $profile = null;
            } else {
                $variables = [
                    'PROJECT_ID' => $pack->id,
                    'VERSION_ID' => $pack->versionId,
                ];
            }
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
        );
    }

    /**
     * @param  list<\Kazaminosuke\ModManager\Support\Compatibility\ClassifiedEgg>  $eggs
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
        ?string $curseForgeApiKey = null,
    ): CompatibilityResult {
        return $this->engine->select(
            $this->requirement($pack, $curseForgeApiKey),
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
