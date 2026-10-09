<?php

namespace Kazaminosuke\ModManager\Support\Compatibility;

use Kazaminosuke\ModManager\Support\EggProfile;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;

/**
 * Decides which installed egg can provision a modpack.
 *
 * New server creation and an install onto an existing server both call
 * select(). Name similarity never outranks a variable signature that belongs
 * to a different profile, and a medium-confidence match is never applied
 * until the operator picks it.
 */
final class EggCompatibilityEngine
{
    public const CONTEXT_CREATE = 'create';

    public const CONTEXT_EXISTING = 'existing';

    /**
     * @param  list<string>  $variableNames
     */
    public function classify(
        int|string $id,
        string $name,
        ?string $uuid,
        ?string $updateUrl,
        array $variableNames,
        ?string $manualLoader = null,
        ?string $manualProjectType = null,
        ?string $taggedLoader = null,
    ): ClassifiedEgg {
        [$profile, $source] = $this->match($uuid, $updateUrl, $name, $variableNames);
        $signature = EggProfileRegistry::normalizeSignature($variableNames);
        $owners = $signature === [] ? [] : $this->signatureOwners($signature);
        $contradicts = false;
        $confidence = 'none';

        if ($profile !== null) {
            $ownsSignature = $this->profileOwns($profile, $owners);
            $confidence = match ($source) {
                'uuid', 'update_url', 'name_signature' => 'high',
                'signature' => 'medium',
                'name' => 'high',
                default => 'none',
            };

            if ($source === 'name' && $signature !== []) {
                $otherOwners = array_values(array_filter(
                    $owners,
                    static fn (EggProfile $owner): bool => $owner->id !== $profile->id,
                ));
                if ($otherOwners !== [] && !$ownsSignature) {
                    $contradicts = true;
                    $confidence = 'none';
                } elseif (!$ownsSignature) {
                    $confidence = 'medium';
                }
            }
        } elseif ($manualLoader !== null && $manualLoader !== '') {
            $profile = null;
            $source = 'manual';
            $confidence = 'medium';
        }

        $loader = $profile?->loader ?? ($manualLoader !== null && $manualLoader !== '' ? $manualLoader : null);
        $projectType = $profile?->projectType ?? ($manualProjectType !== null && $manualProjectType !== '' ? $manualProjectType : null);
        if ($taggedLoader !== null && $taggedLoader !== '') {
            $loader = $taggedLoader;
            $confidence = 'high';
            $contradicts = false;
            if ($source === 'none') {
                $source = 'tag';
            }
        }

        return new ClassifiedEgg(
            id: $id,
            name: $name,
            profileId: $profile?->id,
            loader: $loader,
            projectType: $projectType,
            isProxy: $profile?->isProxy ?? false,
            matchSource: $source,
            confidence: $confidence,
            contradicts: $contradicts,
        );
    }

    /**
     * @param  list<ClassifiedEgg>  $eggs
     * @param  array<string, string>  $currentVariables
     */
    public function select(
        PackRequirement $requirement,
        array $eggs,
        string $context,
        int|string|null $currentEggId = null,
        array $currentVariables = [],
        int|string|null $explicitEggId = null,
        bool $automatic = true,
    ): CompatibilityResult {
        if ($requirement->unsupportedReason !== null) {
            return $this->result(
                $requirement,
                CompatibilityDisposition::Unsupported,
                ProvisioningMode::None,
                null,
                [],
                'unsupported',
                $requirement->unsupportedReason,
            );
        }

        $provisioning = $this->candidates($eggs, function (ClassifiedEgg $egg) use ($requirement): bool {
            return $requirement->provisioningProfileId !== null
                && $egg->profileId === $requirement->provisioningProfileId;
        });
        $loaders = $this->candidates($eggs, function (ClassifiedEgg $egg) use ($requirement): bool {
            return $requirement->archiveAllowed
                && $requirement->loader !== null
                && $egg->loader === $requirement->loader
                && $egg->projectType === 'mod'
                && ($requirement->loaderProfileIds === []
                    || in_array((string) $egg->profileId, $requirement->loaderProfileIds, true)
                    || in_array($egg->matchSource, ['manual', 'tag'], true));
        });

        if ($explicitEggId !== null && $explicitEggId !== '') {
            $explicit = $this->find($eggs, $explicitEggId);
            $allowed = array_merge($provisioning, $loaders);
            if ($explicit !== null && $this->contains($allowed, $explicit)) {
                $mode = $explicit->profileId !== null && $explicit->profileId === $requirement->provisioningProfileId
                    ? ProvisioningMode::EggInstall
                    : ProvisioningMode::Archive;

                return $this->chosen(
                    $requirement,
                    $explicit,
                    $mode,
                    $context,
                    $currentEggId,
                    $currentVariables,
                    'explicit_kept',
                    'The selected egg is compatible with this modpack.',
                    $automatic,
                );
            }

            return $this->result(
                $requirement,
                CompatibilityDisposition::Unsupported,
                ProvisioningMode::None,
                null,
                $this->rank($allowed),
                'explicit_incompatible',
                'The selected egg does not match this modpack.',
            );
        }

        $current = $currentEggId !== null && $currentEggId !== '' ? $this->find($eggs, $currentEggId) : null;
        if ($context === self::CONTEXT_EXISTING && $current !== null && $current->isSelectable()) {
            if ($requirement->provisioningProfileId !== null && $current->profileId === $requirement->provisioningProfileId) {
                return $this->chosen(
                    $requirement,
                    $current,
                    ProvisioningMode::EggInstall,
                    $context,
                    $currentEggId,
                    $currentVariables,
                    'current_provisioning_egg',
                    'This server already uses the egg that installs this modpack.',
                    true,
                );
            }

            if ($requirement->archiveAllowed
                && $requirement->loader !== null
                && $current->loader === $requirement->loader
                && $current->projectType === 'mod'
                && !$current->isProxy) {
                return $this->chosen(
                    $requirement,
                    $current,
                    ProvisioningMode::Archive,
                    $context,
                    $currentEggId,
                    [],
                    'compatible_loader',
                    'The current egg uses the loader this modpack requires.',
                    true,
                );
            }
        }

        $pool = $provisioning !== [] ? $provisioning : $loaders;
        $mode = $provisioning !== [] ? ProvisioningMode::EggInstall : ProvisioningMode::Archive;
        if ($pool === []) {
            $detail = $requirement->provisioningProfileId !== null
                ? 'No installed egg matches the '.$requirement->provisioningProfileId.' provisioning profile.'
                : 'No installed egg matches this modpack loader.';

            return $this->result(
                $requirement,
                CompatibilityDisposition::Unsupported,
                ProvisioningMode::None,
                null,
                [],
                'no_compatible_egg',
                $detail,
            );
        }

        $high = array_values(array_filter(
            $pool,
            static fn (ClassifiedEgg $egg): bool => $egg->confidence === 'high',
        ));

        if ($automatic && count($high) === 1) {
            return $this->chosen(
                $requirement,
                $high[0],
                $mode,
                $context,
                $currentEggId,
                $currentVariables,
                'automatic',
                'One high-confidence egg matches this modpack.',
                true,
            );
        }

        $options = $high !== [] ? $high : $pool;

        return $this->result(
            $requirement,
            CompatibilityDisposition::Ambiguous,
            $mode,
            null,
            $this->rank($options),
            'ambiguous',
            count($high) > 1
                ? 'More than one high-confidence egg matches this modpack.'
                : 'The matching egg is not confident enough to select automatically.',
        );
    }

    /**
     * @param  list<string>  $variableNames
     * @return array{0: ?EggProfile, 1: string}
     */
    private function match(?string $uuid, ?string $updateUrl, string $name, array $variableNames): array
    {
        if (($profile = EggProfileRegistry::findByUuid($uuid)) !== null) {
            return [$profile, 'uuid'];
        }

        if (($profile = EggProfileRegistry::findByUpdateUrl($updateUrl)) !== null) {
            return [$profile, 'update_url'];
        }

        $normalizedName = EggProfileRegistry::normalizeName($name);
        $signature = EggProfileRegistry::normalizeSignature($variableNames);

        if ($signature !== [] && ($profile = EggProfileRegistry::findByNameAndSignature($normalizedName, $signature)) !== null) {
            return [$profile, 'name_signature'];
        }

        if (($profile = EggProfileRegistry::findByNameUnique($normalizedName)) !== null) {
            return [$profile, 'name'];
        }

        if ($signature !== [] && ($profile = EggProfileRegistry::findByNonCollidingSignature($signature)) !== null) {
            return [$profile, 'signature'];
        }

        return [null, 'none'];
    }

    /**
     * @param  list<string>  $signature
     * @return list<EggProfile>
     */
    private function signatureOwners(array $signature): array
    {
        $owners = [];
        foreach ($this->profiles() as $profile) {
            foreach ($profile->variableSignatures as $candidate) {
                if (EggProfileRegistry::normalizeSignature($candidate) === $signature) {
                    $owners[] = $profile;
                    break;
                }
            }
        }

        return $owners;
    }

    /**
     * @param  list<EggProfile>  $owners
     */
    private function profileOwns(EggProfile $profile, array $owners): bool
    {
        foreach ($owners as $owner) {
            if ($owner->id === $profile->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<EggProfile>
     */
    private function profiles(): array
    {
        return EggProfileRegistry::all();
    }

    /**
     * @param  list<ClassifiedEgg>  $eggs
     * @param  callable(ClassifiedEgg): bool  $predicate
     * @return list<ClassifiedEgg>
     */
    private function candidates(array $eggs, callable $predicate): array
    {
        $matched = [];
        foreach ($eggs as $egg) {
            if ($egg->isSelectable() && $predicate($egg)) {
                $matched[] = $egg;
            }
        }

        return $matched;
    }

    /**
     * @param  list<ClassifiedEgg>  $eggs
     */
    private function find(array $eggs, int|string $id): ?ClassifiedEgg
    {
        foreach ($eggs as $egg) {
            if ((string) $egg->id === (string) $id) {
                return $egg;
            }
        }

        return null;
    }

    /**
     * @param  list<ClassifiedEgg>  $eggs
     */
    private function contains(array $eggs, ClassifiedEgg $needle): bool
    {
        foreach ($eggs as $egg) {
            if ((string) $egg->id === (string) $needle->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ClassifiedEgg>  $eggs
     * @return list<ClassifiedEgg>
     */
    private function rank(array $eggs): array
    {
        usort($eggs, static function (ClassifiedEgg $left, ClassifiedEgg $right): int {
            $confidence = ['high' => 0, 'medium' => 1, 'none' => 2];

            return [$confidence[$left->confidence] ?? 3, $left->name, (string) $left->id]
                <=> [$confidence[$right->confidence] ?? 3, $right->name, (string) $right->id];
        });

        return $eggs;
    }

    /**
     * @param  array<string, string>  $currentVariables
     */
    private function chosen(
        PackRequirement $requirement,
        ClassifiedEgg $selected,
        ProvisioningMode $mode,
        string $context,
        int|string|null $currentEggId,
        array $currentVariables,
        string $reasonCode,
        string $reasonDetail,
        bool $automatic,
    ): CompatibilityResult {
        $sameEgg = $currentEggId !== null && (string) $currentEggId === (string) $selected->id;
        $variables = $mode === ProvisioningMode::EggInstall ? $requirement->variables : [];
        $variablesDiffer = false;
        foreach ($variables as $name => $value) {
            if ((string) ($currentVariables[$name] ?? '') !== (string) $value) {
                $variablesDiffer = true;
                break;
            }
        }

        if ($context === self::CONTEXT_EXISTING && $sameEgg && $mode === ProvisioningMode::EggInstall && $variablesDiffer) {
            return $this->result(
                $requirement,
                CompatibilityDisposition::Variables,
                $mode,
                $selected,
                [$selected],
                'variables',
                'The current egg can install this modpack after its startup variables change.',
                variables: $variables,
                requiresConfirmation: true,
                reinstall: true,
            );
        }

        if ($context === self::CONTEXT_EXISTING && $sameEgg) {
            return $this->result(
                $requirement,
                CompatibilityDisposition::Compatible,
                $mode,
                $selected,
                [$selected],
                $reasonCode,
                $reasonDetail,
                variables: $variables,
            );
        }

        if ($context === self::CONTEXT_CREATE && ($automatic || $reasonCode === 'explicit_kept')) {
            return $this->result(
                $requirement,
                CompatibilityDisposition::Compatible,
                $mode,
                $selected,
                [$selected],
                $reasonCode,
                $reasonDetail,
                variables: $variables,
                reinstall: false,
            );
        }

        return $this->result(
            $requirement,
            CompatibilityDisposition::EggChange,
            $mode,
            $selected,
            [$selected],
            'egg_change',
            'This modpack needs a different egg than the server uses now.',
            variables: $variables,
            requiresConfirmation: $context === self::CONTEXT_EXISTING,
            reinstall: $context === self::CONTEXT_EXISTING,
        );
    }

    /**
     * @param  list<ClassifiedEgg>  $alternatives
     * @param  array<string, string>  $variables
     */
    private function result(
        PackRequirement $requirement,
        CompatibilityDisposition $disposition,
        ProvisioningMode $mode,
        ?ClassifiedEgg $selected,
        array $alternatives,
        string $reasonCode,
        string $reasonDetail,
        array $variables = [],
        bool $requiresConfirmation = false,
        bool $reinstall = false,
    ): CompatibilityResult {
        return new CompatibilityResult(
            disposition: $disposition,
            mode: $mode,
            selected: $selected,
            alternatives: $alternatives,
            reasonCode: $reasonCode,
            reasonDetail: $reasonDetail,
            variables: $variables,
            requiresConfirmation: $requiresConfirmation,
            reinstall: $reinstall,
            minecraftVersion: $requirement->minecraftVersion,
            loader: $requirement->loader,
            provider: $requirement->provider,
        );
    }
}
