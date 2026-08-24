<?php

namespace Kazaminosuke\ModManager\Support;

use App\Models\Server;
use Kazaminosuke\ModManager\Enums\ProjectOperation;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Models\ModManagerServerSetting;
use Kazaminosuke\ModManager\Repositories\ServerModManagerSettingRepository;

/**
 * Resolves global and optional server-specific Mod Manager settings.
 *
 * This class contains no per-server state. Request-local memoization belongs
 * to ServerModManagerSettingRepository, while this resolver only applies the
 * nullable-override rule (null inherits, false remains false).
 */
final class ServerModManagerSettings
{
    public function __construct(
        private readonly ServerModManagerSettingRepository $repository,
    ) {}

    /**
     * Resolve the independent page switch for one project type.
     *
     * Missing rows remain enabled, while an operator can enable or disable
     * each type independently.
     */
    public function isTypeEnabled(Server|int $server, ProjectType $type): bool
    {
        return $this->configuredTypeEnabled($server, $type);
    }

    /**
     * Return the stored type switch for the admin form.
     */
    public function configuredTypeEnabled(Server|int $server, ProjectType $type): bool
    {
        $setting = $this->repository->forServer($server);

        return $setting?->{$this->typeEnabledField($type)} ?? true;
    }

    /**
     * Resolve the server-level source switch.
     *
     * Source capability remains source-owned (ProjectSourceInterface), while
     * this switch controls whether an administrator exposes that source on a
     * particular server. A missing row preserves the pre-settings defaults;
     * GitHub Releases intentionally defaults to false because it was formerly
     * an explicit opt-in.
     */
    public function isSourceEnabled(Server|int $server, ProjectSourceKey $source): bool
    {
        return $this->configuredSourceEnabled($server, $source);
    }

    public function configuredSourceEnabled(Server|int $server, ProjectSourceKey $source): bool
    {
        $field = $this->sourceEnabledField($source);

        $setting = $this->repository->forServer($server);

        return $setting?->{$field} ?? $this->defaultSourceEnabled($source);
    }

    public function hasAnyManagerTypeEnabled(Server|int $server): bool
    {
        foreach (ProjectType::cases() as $type) {
            if ($this->configuredTypeEnabled($server, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prime per-server settings before a bulk resolver pass.
     *
     * @param iterable<Server> $servers
     */
    public function preload(iterable $servers): void
    {
        $this->repository->preload($servers);
    }

    public function allowsEggProfileEdit(Server|int $server): bool
    {
        return $this->resolve($server, 'allow_user_egg_profile_edit');
    }

    public function allowsProjectOperation(Server|int $server, ProjectOperation $operation): bool
    {
        return $this->resolve($server, $operation->allowsUserConfigKey());
    }

    public function navigationSort(Server|int $server, ProjectType $type): int
    {
        $override = $this->navigationSortOverride($server, $type);
        $default = match ($type) {
            ProjectType::Datapack => 12,
            ProjectType::ResourcePack => 13,
            default => 11,
        };

        return $override
            ?? NavigationSort::nullable(config('pelican-mod-manager.navigation_sort.'.$type->value, $default))
            ?? $default;
    }

    public function navigationSortOverride(Server|int $server, ProjectType $type): ?int
    {
        return NavigationSort::nullable(
            $this->repository->forServer($server)?->{$this->navigationSortField($type)},
        );
    }

    /**
     * Resolve one global permission key through its server override.
     *
     * @param string $globalConfigKey A key under pelican-mod-manager.
     */
    public function resolve(Server|int $server, string $globalConfigKey): bool
    {
        $override = $this->override($server, $globalConfigKey);

        return $override ?? $this->global($globalConfigKey);
    }

    public function global(string $globalConfigKey): bool
    {
        return (bool) config('pelican-mod-manager.'.$globalConfigKey, false);
    }

    public function override(Server|int $server, string $globalConfigKey): ?bool
    {
        $setting = $this->repository->forServer($server);

        if (!$setting instanceof ModManagerServerSetting) {
            return null;
        }

        $field = match ($globalConfigKey) {
            'allow_user_egg_profile_edit' => 'allow_user_egg_profile_edit',
            'allow_user_project_install' => 'allow_user_project_install',
            'allow_user_project_update' => 'allow_user_project_update',
            'allow_user_project_delete' => 'allow_user_project_delete',
            default => throw new \InvalidArgumentException("Unknown Mod Manager setting: {$globalConfigKey}"),
        };

        return $setting->{$field};
    }

    private function typeEnabledField(ProjectType $type): string
    {
        return $type->value.'_enabled';
    }

    private function sourceEnabledField(ProjectSourceKey $source): string
    {
        return $source->value.'_enabled';
    }

    private function defaultSourceEnabled(ProjectSourceKey $source): bool
    {
        return $source !== ProjectSourceKey::GitHubReleases;
    }

    private function navigationSortField(ProjectType $type): string
    {
        return $type->value.'_navigation_sort';
    }
}
