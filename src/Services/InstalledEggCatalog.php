<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Egg;
use App\Models\Server;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Models\ModManagerEggProfile;
use Kazaminosuke\ModManager\Support\Compatibility\ClassifiedEgg;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;

/**
 * Classifies the eggs installed on this panel with the shared compatibility engine.
 */
final class InstalledEggCatalog
{
    public function __construct(
        private readonly EggCompatibilityEngine $engine,
    ) {}

    /**
     * @return list<ClassifiedEgg>
     */
    public function all(): array
    {
        $classified = [];
        foreach (Egg::query()->with('variables')->get() as $egg) {
            $classified[] = $this->one($egg);
        }

        return $classified;
    }

    public function one(Egg $egg): ClassifiedEgg
    {
        $names = [];
        foreach ($egg->variables ?? [] as $variable) {
            $names[] = (string) ($variable->env_variable ?? '');
        }

        $manual = null;
        try {
            $manual = ModManagerEggProfile::query()->where('egg_id', $egg->getKey())->first();
        } catch (\Throwable) {
            $manual = null;
        }

        return $this->engine->classify(
            id: $egg->getKey(),
            name: (string) ($egg->name ?? ''),
            uuid: is_string($egg->uuid ?? null) ? $egg->uuid : null,
            updateUrl: is_string($egg->update_url ?? null) ? $egg->update_url : null,
            variableNames: $names,
            manualLoader: is_string($manual?->loader) ? $manual->loader : null,
            manualProjectType: is_string($manual?->project_type) ? $manual->project_type : null,
            taggedLoader: MinecraftLoader::fromTags(is_array($egg->tags ?? null) ? $egg->tags : [])?->value,
        );
    }

    /**
     * @return array<string, string>
     */
    public function variableValues(Server $server): array
    {
        $server->loadMissing('serverVariables.variable');
        $values = [];
        foreach ($server->serverVariables as $variable) {
            $name = (string) ($variable->variable->env_variable ?? '');
            if ($name !== '') {
                $values[$name] = (string) $variable->variable_value;
            }
        }

        return $values;
    }
}
