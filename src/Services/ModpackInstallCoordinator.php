<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityDisposition;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityResult;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\Compatibility\ProvisioningMode;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDescriptor;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackLoader;

/**
 * Plans and starts a modpack install on an existing server.
 */
final class ModpackInstallCoordinator
{
    public function __construct(
        private readonly ModpackCatalog $catalog,
        private readonly ModpackProvisioningPlanner $planner,
        private readonly InstalledEggCatalog $eggs,
        private readonly InstalledOperationManager $operations,
    ) {}

    /**
     * @return array{descriptor: ModpackDescriptor, result: CompatibilityResult}
     */
    public function preview(Server $server, string $selection, ?string $versionId, int|string|null $explicitEggId = null): array
    {
        [$source, $projectId] = $this->split($selection);
        $descriptor = $this->catalog->describe($server, $source, $projectId, $versionId);
        if ($descriptor === null) {
            throw new ModpackException('That modpack could not be loaded.');
        }

        $result = $this->planner->select(
            $descriptor,
            $this->eggs->all(),
            EggCompatibilityEngine::CONTEXT_EXISTING,
            $server->egg_id,
            $this->eggs->variableValues($server),
            $explicitEggId,
            $explicitEggId === null || $explicitEggId === '',
        );

        return ['descriptor' => $descriptor, 'result' => $result];
    }

    public function queuePreview(Server $server, string $selection, string $versionId, bool $confirmedCompatibility = false): void
    {
        if ($this->operations->waitingCurseForgeModpack($server) !== null) {
            throw new ModpackException(trans('pelican-mod-manager::strings.curseforge_manual.pending_selection'));
        }
        $preview = $this->preview($server, $selection, $versionId);
        $descriptor = $preview['descriptor'];
        $result = $preview['result'];
        if ($versionId === '' || $descriptor->versionId !== $versionId
            || $result->disposition !== CompatibilityDisposition::Compatible
            || $result->mode !== ProvisioningMode::Archive) {
            throw new ModpackException('This release cannot use the current Egg, runtime or archive installer. Review the compatibility details. An in-place reinstall cannot be rolled back.');
        }
        if (JavaRuntimeImage::isTooOld($server->image, $descriptor->minecraftVersion, $descriptor->minimumJava)) {
            throw new ModpackException(trans('pelican-mod-manager::strings.modpacks.java_too_old', [
                'java' => JavaRuntimeImage::preferredLabel($descriptor->minecraftVersion, $descriptor->minimumJava),
            ]));
        }
        if ($result->compatibilityWarning !== null && !$confirmedCompatibility) {
            throw new ModpackException(trans('pelican-mod-manager::strings.modpacks.'.$result->compatibilityWarning));
        }
        if (!$this->operations->supportsAsyncDispatch()) {
            throw new ModpackException(trans('pelican-mod-manager::strings.operations.queue_required'));
        }
        if (app(ModpackUpdateWorkflow::class)->review($server) !== null) {
            throw new ModpackException('Apply or cancel the existing modpack preview before selecting another release.');
        }
        $variables = $this->eggs->variableValues($server);
        if ($descriptor->loader !== null && $descriptor->loaderVersion !== null
            && !ModpackLoader::hasBuild($descriptor->loader, $descriptor->loaderVersion, $descriptor->minecraftVersion, $variables)) {
            throw new ModpackException("This release requires {$descriptor->loader} {$descriptor->loaderVersion}. Configure and verify that runtime before requesting an in-place update.");
        }
        $selection = [
            'managed_phase' => 'preview', 'egg_id' => (string) $server->egg_id, 'image' => $server->image,
            'variables' => $variables, 'startup' => (string) $server->startup, 'minecraft' => $descriptor->minecraftVersion,
            'loader' => $descriptor->loader, 'loader_version' => $descriptor->loaderVersion,
            'server_pack' => $descriptor->usesServerPack, 'artifact_id' => $descriptor->artifactId,
            'title' => $descriptor->title,
            ...($descriptor->manifestFingerprint === null ? [] : ['manifest_fingerprint' => $descriptor->manifestFingerprint]),
        ];
        $dispatch = $this->operations->dispatchModpackInstall($server, ProjectType::Mod, $descriptor->provider, $descriptor->id,
            $descriptor->versionId, selection: $selection);
        if (!$dispatch['dispatched']) {
            throw new ModpackException('The modpack preview could not be queued.');
        }
    }

    /** @return array{0: string, 1: string} */
    private function split(string $selection): array
    {
        $parts = explode(':', $selection, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new ModpackException('Choose a modpack before installing.');
        }

        return [$parts[0], $parts[1]];
    }
}
