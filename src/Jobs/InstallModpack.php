<?php

namespace Kazaminosuke\ModManager\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
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

    public function __construct(
        public readonly int $serverId,
        public readonly string $projectType,
        public readonly string $source,
        public readonly string $projectId,
        public readonly string $leaseToken,
        public readonly string $versionId = '',
        public readonly int $deferUntil = 0,
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

        try {
            $file = $catalog->file($server, $this->source, $this->projectId, $this->versionId !== '' ? $this->versionId : null);
            if ($file === null) {
                throw new ModpackException('No installable modpack file was found for this server.');
            }

            $response = Http::timeout(180)
                ->withOptions(['sink' => $temp])
                ->get($file['url']);
            $response->throw();

            $result = $installer->installFromZip(
                $server,
                $fileRepository,
                $temp,
                MinecraftLoader::fromServer($server)?->value,
                MinecraftVersionResolver::resolve($server),
                function (int $progress, int $total) use ($operations, $leases, $server, $type, &$leaseRefreshedAt): void {
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
                },
            );

            // The Installed list is a cached directory scan. Drop it so the
            // next view queues a scan instead of showing the pre-install files.
            Cache::forget(app(InstalledProjectService::class)->getHashScanCacheKey($server, $type));

            $operations->complete(
                $server,
                $type,
                InstalledOperationManager::OPERATION_MODPACK,
                $result->toArray(),
                $this->leaseToken,
            );
            $this->rememberPack($server, $fileRepository, $file);
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
