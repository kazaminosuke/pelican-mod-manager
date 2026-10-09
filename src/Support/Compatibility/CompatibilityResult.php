<?php

namespace Kazaminosuke\ModManager\Support\Compatibility;

/**
 * The shared decision for new-server selection and existing-server installs.
 */
final class CompatibilityResult
{
    /**
     * @param  list<ClassifiedEgg>  $alternatives
     * @param  array<string, string>  $variables
     */
    public function __construct(
        public readonly CompatibilityDisposition $disposition,
        public readonly ProvisioningMode $mode,
        public readonly ?ClassifiedEgg $selected,
        public readonly array $alternatives,
        public readonly string $reasonCode,
        public readonly string $reasonDetail,
        public readonly array $variables,
        public readonly bool $requiresConfirmation,
        public readonly bool $reinstall,
        public readonly ?string $minecraftVersion,
        public readonly ?string $loader,
        public readonly string $provider,
    ) {}

    /**
     * Structured view for tests and diagnostics. Startup secrets are omitted.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'disposition' => $this->disposition->value,
            'mode' => $this->mode->value,
            'reason_code' => $this->reasonCode,
            'reason_detail' => $this->reasonDetail,
            'requires_confirmation' => $this->requiresConfirmation,
            'reinstall' => $this->reinstall,
            'minecraft_version' => $this->minecraftVersion,
            'loader' => $this->loader,
            'provider' => $this->provider,
            'selected' => $this->selected === null ? null : [
                'id' => $this->selected->id,
                'name' => $this->selected->name,
                'profile_id' => $this->selected->profileId,
                'loader' => $this->selected->loader,
                'confidence' => $this->selected->confidence,
                'match_source' => $this->selected->matchSource,
            ],
            'alternatives' => array_map(static fn (ClassifiedEgg $egg): array => [
                'id' => $egg->id,
                'name' => $egg->name,
                'profile_id' => $egg->profileId,
                'loader' => $egg->loader,
                'confidence' => $egg->confidence,
                'match_source' => $egg->matchSource,
            ], $this->alternatives),
            'variable_names' => array_keys($this->redactedVariables()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function redactedVariables(): array
    {
        $redacted = [];
        foreach ($this->variables as $name => $value) {
            $redacted[$name] = self::isSecret((string) $name) ? '[redacted]' : $value;
        }

        return $redacted;
    }

    public static function isSecret(string $name): bool
    {
        $upper = strtoupper($name);

        return str_contains($upper, 'KEY')
            || str_contains($upper, 'TOKEN')
            || str_contains($upper, 'SECRET')
            || str_contains($upper, 'PASSWORD');
    }
}
