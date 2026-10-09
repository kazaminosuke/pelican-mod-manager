<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Egg;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityDisposition;
use Kazaminosuke\ModManager\Support\Compatibility\CompatibilityResult;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\Compatibility\ProvisioningMode;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDescriptor;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Throwable;

/**
 * Plans and starts a modpack install on an existing server.
 */
final class ModpackInstallCoordinator
{
    public function __construct(
        private readonly ModpackCatalog $catalog,
        private readonly ModpackProvisioningPlanner $planner,
        private readonly InstalledEggCatalog $eggs,
        private readonly ServerEggMigration $migration,
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
            $this->curseForgeKey(),
        );

        return ['descriptor' => $descriptor, 'result' => $result];
    }

    /**
     * @return array{message: string, dispatched: bool}
     */
    public function install(
        Server $server,
        string $selection,
        ?string $versionId,
        int|string|null $explicitEggId,
        bool $confirmed,
    ): array {
        $preview = $this->preview($server, $selection, $versionId, $explicitEggId);
        $descriptor = $preview['descriptor'];
        $result = $preview['result'];

        if ($result->disposition === CompatibilityDisposition::Unsupported) {
            throw new ModpackException($result->reasonDetail);
        }

        if ($result->disposition === CompatibilityDisposition::Ambiguous) {
            throw new ModpackException($result->reasonDetail);
        }

        if ($result->requiresConfirmation && !$confirmed) {
            throw new ModpackException('Confirm the egg change before installing this modpack.');
        }

        $changedEgg = $result->disposition === CompatibilityDisposition::EggChange
            || $result->disposition === CompatibilityDisposition::Variables;
        if ($changedEgg) {
            if ($result->selected === null) {
                throw new ModpackException('No target egg was selected.');
            }

            $this->migration->apply(
                $server,
                $result->selected->id,
                $result->variables,
                $this->imageFor($result->selected->id, $descriptor->minecraftVersion),
                $result->reinstall,
            );
            $server->refresh();
        }

        if ($result->mode === ProvisioningMode::EggInstall) {
            $this->remember($server, $descriptor, $result);

            return [
                'message' => 'The server egg will install this modpack during installation.',
                'dispatched' => false,
            ];
        }

        $dispatch = $this->operations->dispatchModpackInstall(
            $server,
            ProjectType::Mod,
            $descriptor->provider,
            $descriptor->id,
            $descriptor->versionId,
            $changedEgg ? 1200 : null,
        );
        if (!$dispatch['dispatched']) {
            throw new ModpackException('The modpack install could not be queued.');
        }

        return [
            'message' => 'Modpack installation was queued.',
            'dispatched' => true,
        ];
    }

    private function imageFor(int|string $eggId, ?string $minecraftVersion): ?string
    {
        $egg = Egg::query()->find($eggId);
        $images = is_array($egg?->docker_images) ? $egg->docker_images : [];

        return JavaRuntimeImage::fromEggImages($images, $minecraftVersion);
    }

    private function remember(Server $server, ModpackDescriptor $descriptor, CompatibilityResult $result): void
    {
        try {
            $payload = json_encode([
                'provider' => $descriptor->provider,
                'pack_id' => $descriptor->id,
                'version_id' => $descriptor->versionId,
                'version_name' => $descriptor->versionName,
                'minecraft_version' => $descriptor->minecraftVersion,
                'loader' => $descriptor->loader,
                'mode' => $result->mode->value,
                'egg_profile_id' => $result->selected?->profileId,
                'installed_at' => gmdate('c'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            app(WingsRemoteFilesystem::class)->put(
                app(DaemonFileRepository::class),
                $server,
                '.mod-manager-modpack.json',
                $payload,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function curseForgeKey(): ?string
    {
        $key = config('pelican-mod-manager.curseforge_api_key');

        return is_string($key) && trim($key) !== '' && strlen(trim($key)) <= 60 ? trim($key) : null;
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
