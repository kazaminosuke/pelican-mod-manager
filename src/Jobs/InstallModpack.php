<?php

namespace Kazaminosuke\ModManager\Jobs;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\MinecraftLoader;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Exceptions\CurseForgeDependenciesPending;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\InstalledEggCatalog;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Services\ModpackCatalog;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackInventory;
use Kazaminosuke\ModManager\Services\ModpackReviewArchive;
use Kazaminosuke\ModManager\Services\ModpackUpdateWorkflow;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
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
        $isCurseForge = $this->source === ModpackProvider::CurseForge->value;
        $isFtb = $this->source === ModpackProvider::Ftb->value;
        $resume = $this->selection['manual_token'] ?? null;
        $archiveHash = null;
        $file = null;

        try {
            if ($resume !== null && ($operations->waitingCurseForgeModpack($server)['token'] ?? null) !== $resume) {
                throw new ModpackException('This pending modpack has expired or was cancelled.');
            }
            if ($isCurseForge || $isFtb) {
                $operations->modpackPhase($this->serverId, 'downloading');
            }
            $this->validateSelection($server);
            $phase = $this->selection['managed_phase'] ?? ($this->selection !== null
                && ($this->selection['strict_minecraft'] ?? true) ? 'initial' : 'preview');
            if (!in_array($phase, ['initial', 'preview', 'apply'], true)) {
                throw new ModpackException('Unsupported modpack job phase.');
            }
            $workflow = app(ModpackUpdateWorkflow::class);
            $review = $phase === 'apply' ? $workflow->requiredReview($server, $this->selection['review_token']) : null;
            $backup = $review === null ? null : $workflow->requireBackup($server, $review);
            if ($review !== null) {
                if ($review['release']['provider'] !== $this->source || $review['release']['pack_id'] !== $this->projectId
                    || $review['release']['version_id'] !== $this->versionId) {
                    throw new ModpackException('The job does not match the approved release.');
                }
                $workflow->assertStopped($server);
            }

            $reviewArchive = app(ModpackReviewArchive::class);
            $cached = isset($review['artifact'], $review['release']['archive_sha256'])
                && $reviewArchive->restore($review['release']['archive_sha256'], $temp);
            $archiveHash = $cached ? $review['release']['archive_sha256'] : null;
            $file = $cached ? $review['artifact'] : $catalog->file($server, $this->source, $this->projectId, $this->versionId !== '' ? $this->versionId : null);
            if ($file === null) {
                throw new ModpackException('No installable modpack file was found for this server.');
            }

            if ($this->selection !== null && (
                $file['version_id'] !== $this->versionId
                || (isset($this->selection['server_pack']) && (bool) $this->selection['server_pack'] !== (bool) ($file['server_pack'] ?? false))
                || (isset($this->selection['artifact_id']) && ($file['artifact_id'] ?? $file['version_id']) !== $this->selection['artifact_id'])
            )) {
                throw new ModpackException('The selected modpack file or its runtime requirements changed after server creation.');
            }

            if (!$cached) {
                try {
                    $response = Http::timeout(180)
                        ->withOptions(['sink' => $temp, 'allow_redirects' => ['protocols' => ['https']]])
                        ->get($file['url']);
                    $response->throw();
                } catch (Throwable $exception) {
                    if ($isFtb) {
                        throw new ModpackException(trans('pelican-mod-manager::strings.modpacks.ftb_manifest_unavailable'), previous: $exception);
                    }

                    throw $exception;
                } finally {
                    if (microtime(true) - $leaseRefreshedAt >= self::LEASE_REFRESH_SECONDS) {
                        $leases->refresh($this->serverId, $type, $this->leaseToken);
                        $leaseRefreshedAt = microtime(true);
                    }
                }
            }

            if ($isCurseForge && !($file['server_pack'] ?? false)) {
                $archiveHash ??= hash_file('sha256', $temp);
                if (!is_string($archiveHash) || ($resume !== null && $archiveHash !== ($this->selection['archive_sha256'] ?? null))) {
                    throw new ModpackException('The downloaded CurseForge archive no longer matches the selected file.');
                }
                $operations->modpackPhase($this->serverId, 'verifying');
            }

            $current = app(ModpackInventory::class)->read($server);
            if ($phase === 'initial' && $current['inventory'] !== null) {
                throw new ModpackException('This server already has a modpack. Generate an update preview.');
            }
            $archiveHash ??= hash_file('sha256', $temp);
            if (!is_string($archiveHash)) {
                throw new ModpackException('The modpack archive could not be verified.');
            }
            if ($review !== null && !hash_equals($review['release']['archive_sha256'], $archiveHash)) {
                throw new ModpackException('The archive differs from the reviewed release.');
            }
            $previous = $current['inventory'] ?? ($phase === 'initial' ? null : app(ModpackInventory::class)->legacy($server, $fileRepository));
            $minimumJava = isset($this->selection['minimum_java']) && is_int($this->selection['minimum_java'])
                ? $this->selection['minimum_java']
                : null;
            $release = [
                'provider' => $this->source, 'pack_id' => $this->projectId, 'version_id' => $file['version_id'],
                'version_name' => $file['version_number'], 'title' => $this->selection['title'] ?? $this->projectId,
                'artifact_id' => $file['artifact_id'] ?? $file['version_id'],
                'server_pack' => (bool) ($file['server_pack'] ?? false),
                'minecraft_version' => $this->selection['minecraft'] ?? $file['minecraft'] ?? null,
                'loader' => $this->selection['loader'] ?? $file['loader'] ?? null,
                'loader_version' => $this->selection['loader_version'] ?? null,
                'egg' => $server->egg?->name, 'image' => $server->image,
                'java' => JavaRuntimeImage::preferredLabel($this->selection['minecraft'] ?? $file['minecraft'] ?? null, $minimumJava),
                'archive_sha256' => $archiveHash,
                'legacy' => $phase !== 'initial' && $current['inventory'] === null,
                'switch' => $previous !== null && ($previous['provider'] !== $this->source || $previous['pack_id'] !== $this->projectId),
            ];
            if ($isFtb) {
                $release['manifest_fingerprint'] = $this->selection['manifest_fingerprint'] ?? null;
            }
            $loader = MinecraftLoader::fromServer($server)?->value;
            $runtime = app(InstalledEggCatalog::class)->variableValues($server);
            $minecraft = $runtime['MC_VERSION'] ?? $runtime['MINECRAFT_VERSION'] ?? null;
            if (is_string($minecraft) && preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $minecraft) !== 1) {
                $minecraft = null;
            }
            if ($loader === null || $minecraft === null || ($release['minecraft_version'] !== null && $minecraft !== $release['minecraft_version'])
                || JavaRuntimeImage::isTooOld($server->image, $release['minecraft_version'], $minimumJava)) {
                throw new ModpackException('Set a supported, fixed Minecraft version and loader in the Egg before installing the modpack.');
            }
            if (JavaRuntimeImage::isTooNew($server->image, $release['minecraft_version'], $loader)) {
                throw new ModpackException(trans('pelican-mod-manager::strings.modpacks.java_too_new', [
                    'loader' => $loader,
                    'java' => JavaRuntimeImage::maximumLabel($release['minecraft_version'], $loader),
                ]));
            }
            $lastCheck = 0.0;
            $guard = function (bool $force = true) use ($server, $type, $leases, $workflow, &$lastCheck, $backup, $review): void {
                if (!$force && microtime(true) - $lastCheck < 5) {
                    return;
                }
                if (!$leases->refresh($this->serverId, $type, $this->leaseToken)) {
                    throw new ModpackException('The server operation lease expired.');
                }
                $workflow->assertStopped($server);
                if ($force) {
                    $this->validateSelection($server->fresh() ?? $server);
                    if ($review !== null && $workflow->requireBackup($server, $review)->getKey() !== $backup?->getKey()) {
                        throw new ModpackException('The approved Pelican backup changed.');
                    }
                }
                $lastCheck = microtime(true);
            };
            $operations->modpackPhase($this->serverId, $phase === 'preview' ? 'verifying' : 'installing');
            $lockedForApply = $backup !== null && $workflow->lockForApply($server, $backup);
            try {
                $result = $installer->deploy(
                    $server, $fileRepository, $temp, $release, $loader,
                    $minecraft, $phase === 'preview',
                    $review['summary']['digest'] ?? null, $backup?->uuid, $guard,
                    $runtime, verifyInstalledRuntime: true, unlockBackup: $lockedForApply,
                );
            } finally {
                if ($lockedForApply) {
                    try {
                        $recovery = app(ModpackInventory::class)->read($server)['recovery'];
                        if (($recovery['backup'] ?? null) !== $backup->uuid) {
                            $workflow->unlockAfterApply($server, $backup->uuid);
                        }
                    } catch (Throwable $cleanupError) {
                        report($cleanupError);
                    }
                }
            }
            $release['loader_version'] = $result['runtime_build'];
            $release['version_name'] = $result['version_name'];
            $release['java'] = $result['java'];
            if ($phase === 'preview') {
                $reviewArchive->remember($temp, $archiveHash);
                if ($resume !== null) {
                    $operations->clearCurseForgeModpack($this->serverId, $this->leaseToken);
                }
                $workflow->publish($server, $this->leaseToken, $release, $this->selection ?? [
                    'egg_id' => (string) $server->egg_id, 'image' => $server->image,
                ], $result, $file);

                return null;
            }

            if ($phase === 'apply') {
                $workflow->done($server);
                $startId = $this->selection['start_user'] ?? null;
                if (is_int($startId)) {
                    try {
                        $actor = User::query()->find($startId);
                        if ($actor === null || !$actor->can(SubuserPermission::ControlStart, $server)) {
                            throw new ModpackException('The requested automatic start is no longer authorized.');
                        }
                        app(DaemonServerRepository::class)->setServer($server)->power('start')->throw();
                    } catch (Throwable $startError) {
                        report($startError);
                        $result['warnings'][] = 'power-start-failed';
                    }
                }
            }
            $result = [...$result, 'name' => $release['title'], 'version' => $release['version_name'],
                'installed' => $result['counts']['add'] + $result['counts']['replace'],
                'skipped' => $result['counts']['keep'] + $result['counts']['preserve'] + count($result['protected']),
                'backup' => $backup?->uuid];

            // The Installed list is a cached directory scan. Drop it so the
            // next view queues a scan instead of showing the pre-install files.
            Cache::forget(app(InstalledProjectService::class)->getHashScanCacheKey($server, $type));

            if ($resume !== null) {
                $operations->clearCurseForgeModpack($this->serverId, $this->leaseToken);
            }
            $this->rememberPack($server, $fileRepository, $release);
            $operations->complete(
                $server,
                $type,
                InstalledOperationManager::OPERATION_MODPACK,
                $result,
                $this->leaseToken,
            );
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
                'managed_phase' => 'preview',
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

        if (isset($this->selection['startup']) && (string) $server->startup !== $this->selection['startup']) {
            throw new ModpackException('The server startup command changed after the modpack was queued.');
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
     * @param  array<string, mixed>  $release
     */
    private function rememberPack(Server $server, DaemonFileRepository $fileRepository, array $release): void
    {
        try {
            $payload = json_encode([
                'provider' => $this->source,
                'pack_id' => $this->projectId,
                'version_id' => (string) $release['version_id'],
                'version_name' => (string) $release['version_name'],
                'minecraft_version' => $release['minecraft_version'],
                'loader' => $release['loader'],
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
