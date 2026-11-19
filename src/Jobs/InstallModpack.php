<?php

namespace Kazaminosuke\ModManager\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\CurseForgeDependenciesPending;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Throwable;

/**
 * Downloads one modpack on the panel, then places its files through Wings.
 * The browser request only queues this job.
 */
final class InstallModpack
{
    public int $uniqueFor = 1200;

    private const LEASE_REFRESH_SECONDS = 60;

    /** @param array<string, mixed>|null $selection */
    public function __construct(
        public readonly int $serverId,
        public readonly string $projectType,
        public readonly string $source,
        public readonly string $projectId,
        public readonly string $leaseToken,
        public readonly string $versionId = '',
        public readonly int $deferUntil = 0,
        public readonly ?array $selection = null,
    ) {}

    public function uniqueId(): string
    {
        return "mod-manager:modpack:{$this->serverId}:{$this->projectType}:{$this->source}:{$this->projectId}";
    }

    public function handle(
        DaemonFileRepository $fileRepository,
        ModpackCatalog $catalog,
        ModpackInstaller $installer,
        InstalledOperationManager $operations,
        InstalledOperationLease $leases,
    ): ?int {
        $type = ProjectType::tryFrom($this->projectType);
        if ($type !== ProjectType::Mod) {
            return null;
        }

        if (!$leases->refresh($this->serverId, $type, $this->leaseToken)) {
            return null;
        }

        /** @var Server|null $server */
        $server = Server::query()->with('egg')->find($this->serverId);
        if (!$server) {
            $operations->fail(
                $this->serverId,
                $type,
                InstalledOperationManager::OPERATION_MODPACK,
                'server_not_found',
                leaseToken: $this->leaseToken,
            );

            return null;
        }

        $status = $server->status instanceof \BackedEnum ? (string) $server->status->value : (string) ($server->status ?? '');
        if ($this->deferUntil > 0 && in_array($status, ['installing', 'install_failed', 'reinstall_failed'], true)) {
            if ($status !== 'installing' || time() > $this->deferUntil) {
                $operations->fail(
                    $server,
                    $type,
                    InstalledOperationManager::OPERATION_MODPACK,
                    'server_install_unfinished',
                    ['message' => 'The server install did not finish, so the modpack files were not written.'],
                    $this->leaseToken,
                );

                return null;
            }

            return 30;
        }

        $operations->start($server, $type, InstalledOperationManager::OPERATION_MODPACK);
        $temp = tempnam(sys_get_temp_dir(), 'modpack');
        if ($temp === false) {
            $operations->fail($server, $type, InstalledOperationManager::OPERATION_MODPACK, 'temp_file', leaseToken: $this->leaseToken);

            return null;
        }

        $leaseRefreshedAt = microtime(true);
        $isCurseForge = $this->source === 'curseforge';
        $resume = $this->selection['manual_token'] ?? null;
        $archiveHash = null;
        $file = null;

        try {
            if ($resume !== null && ($operations->waitingCurseForgeModpack($server)['token'] ?? null) !== $resume) {
                throw new ModpackException('This pending modpack has expired or was cancelled.');
            }
            if ($isCurseForge) {
                $operations->modpackPhase($this->serverId, 'downloading');
            }
            $this->validateSelection($server);
            $file = $catalog->file($server, $this->source, $this->projectId, $this->versionId !== '' ? $this->versionId : null);
            if ($file === null) {
                throw new ModpackException('No installable modpack file was found for this server.');
            }

            if ($this->selection !== null && (
                $file['version_id'] !== $this->versionId
                || (isset($this->selection['server_pack']) && (bool) $this->selection['server_pack'] !== (bool) ($file['server_pack'] ?? false))
                || (isset($this->selection['artifact_id']) && ($file['artifact_id'] ?? $file['version_id']) !== $this->selection['artifact_id'])
                || (isset($file['minecraft'], $this->selection['minecraft']) && $file['minecraft'] !== $this->selection['minecraft'])
                || (isset($file['loader'], $this->selection['loader']) && $file['loader'] !== $this->selection['loader'])
            )) {
                throw new ModpackException('The selected modpack file or its runtime requirements changed after server creation.');
            }

            try {
                $response = Http::timeout(180)
                    ->withOptions(['sink' => $temp, 'allow_redirects' => ['protocols' => ['https']]])
                    ->get($file['url']);
                $response->throw();
            } finally {
                if (microtime(true) - $leaseRefreshedAt >= self::LEASE_REFRESH_SECONDS) {
                    $leases->refresh($this->serverId, $type, $this->leaseToken);
                    $leaseRefreshedAt = microtime(true);
                }
            }

            if ($isCurseForge && !($file['server_pack'] ?? false)) {
                $archiveHash = hash_file('sha256', $temp);
                if (!is_string($archiveHash) || ($resume !== null && $archiveHash !== ($this->selection['archive_sha256'] ?? null))) {
                    throw new ModpackException('The downloaded CurseForge archive no longer matches the selected file.');
                }
                $operations->modpackPhase($this->serverId, 'verifying');
            }

            $progress = function (int $progress, int $total) use ($operations, $leases, $server, $type, &$leaseRefreshedAt): void {
                if (microtime(true) - $leaseRefreshedAt >= self::LEASE_REFRESH_SECONDS) {
                    $leases->refresh($this->serverId, $type, $this->leaseToken);
                    $leaseRefreshedAt = microtime(true);
                }

                $operations->progress(
                    $server,
                    $type,
                    InstalledOperationManager::OPERATION_MODPACK,
                    $progress,
                    $total,
                );
            };
            $loader = MinecraftLoader::fromServer($server)?->value;
            $minecraft = MinecraftVersionResolver::resolve($server);
            $result = ($file['server_pack'] ?? false)
                ? $installer->installServerPack(
                    $server,
                    $fileRepository,
                    $temp,
                    $this->projectId,
                    $file['version_number'],
                    $file['loader'] ?? null,
                    $file['minecraft'] ?? null,
                    $loader,
                    $minecraft,
                    $progress,
                )
                : $installer->installFromZip(
                    $server, $fileRepository, $temp,
                    $loader ?? ($this->selection['loader'] ?? null),
                    $this->selection['minecraft'] ?? $minecraft,
                    $progress,
                    strictMinecraftVersion: $this->selection !== null && ($this->selection['strict_minecraft'] ?? true),
                    prepared: $isCurseForge ? fn () => $operations->modpackPhase($this->serverId, 'installing') : null,
                );

            // The Installed list is a cached directory scan. Drop it so the
            // next view queues a scan instead of showing the pre-install files.
            Cache::forget(app(InstalledProjectService::class)->getHashScanCacheKey($server, $type));

            if ($resume !== null) {
                $operations->clearCurseForgeModpack($this->serverId, $this->leaseToken);
            }
            $operations->complete(
                $server,
                $type,
                InstalledOperationManager::OPERATION_MODPACK,
                $result->toArray(),
                $this->leaseToken,
            );
            $this->rememberPack($server, $fileRepository, $file);
        } catch (CurseForgeDependenciesPending $pending) {
            if (!$isCurseForge || $file === null || !is_string($archiveHash) || $file['version_id'] === '') {
                $operations->fail($server, $type, InstalledOperationManager::OPERATION_MODPACK, 'modpack_rejected', leaseToken: $this->leaseToken);

                return null;
            }
            $selection = $this->selection ?? [
                'egg_id' => (string) $server->egg_id,
                'image' => $server->image,
                'variables' => app(InstalledEggCatalog::class)->variableValues($server),
                'strict_minecraft' => false,
            ];
            $selection['archive_sha256'] = $archiveHash;
            $selection['server_pack'] = false;
            $selection['artifact_id'] = $file['artifact_id'] ?? $file['version_id'];
            $operations->pauseCurseForgeModpack($this->serverId, $this->leaseToken, $this->projectId, $file['version_id'], $selection, $pending->files);
        } catch (Throwable $exception) {
            report($exception);
            $operations->fail(
                $server,
                $type,
                InstalledOperationManager::OPERATION_MODPACK,
                $exception instanceof ModpackException ? 'modpack_rejected' : 'modpack_failed',
                ['message' => $exception->getMessage()],
                $this->leaseToken,
            );
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }

        return null;
    }

    private function validateSelection(Server $server): void
    {
        if ($this->selection === null) {
            return;
        }

        if ((string) $server->egg_id !== ($this->selection['egg_id'] ?? null)) {
            throw new ModpackException('The server Egg changed after the modpack was queued.');
        }

        if (isset($this->selection['image']) && $server->image !== $this->selection['image']) {
            throw new ModpackException('The server image changed after the modpack was queued.');
        }

        $expected = $this->selection['variables'] ?? [];
        if ($expected !== []) {
            $actual = app(InstalledEggCatalog::class)->variableValues($server);
            foreach ($expected as $name => $value) {
                // Pelican persists Egg variables as strings.
                if ((string) ($actual[$name] ?? '') !== (string) $value) {
                    throw new ModpackException('Server startup variables changed after the modpack was queued.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function rememberPack(Server $server, DaemonFileRepository $fileRepository, array $file): void
    {
        try {
            $payload = json_encode([
                'provider' => $this->source,
                'pack_id' => $this->projectId,
                'version_id' => (string) ($file['version_id'] ?? $this->versionId),
                'version_name' => (string) ($file['version_number'] ?? ''),
                'minecraft_version' => $file['minecraft'] ?? null,
                'loader' => $file['loader'] ?? null,
                'mode' => 'archive',
                'installed_at' => gmdate('c'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            app(WingsRemoteFilesystem::class)->put(
                $fileRepository,
                $server,
                '.mod-manager-modpack.json',
                $payload,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $type = ProjectType::tryFrom($this->projectType);
        if ($type === null) {
            return;
        }

        $container = Container::getInstance();
        $leases = $container->make(InstalledOperationLease::class);
        if (!$leases->owns($this->serverId, $type, $this->leaseToken)) {
            return;
        }

        $container->make(InstalledOperationManager::class)->fail(
            $this->serverId,
            $type,
            InstalledOperationManager::OPERATION_MODPACK,
            $exception === null ? 'modpack_job_failed' : 'modpack_job_exception',
            leaseToken: $this->leaseToken,
        );
    }
}
