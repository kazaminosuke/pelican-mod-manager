<?php

namespace Kazaminosuke\ModManager\Jobs;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kazaminosuke\ModManager\Enums\ProjectOperation;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\ProjectOperationAuthorizer;
use Throwable;

final class ScanInstalledProjects
{
    /**
     * How long a queued scan waits for another managed operation (an
     * install, update, removal, bulk update, or reset) before it gives up.
     */
    public const BUSY_TIMEOUT_SECONDS = 600;

    /** Delay before a scan that found the lease busy is claimed again. */
    public const BUSY_RETRY_SECONDS = 15;

    private ?int $releaseAfterSeconds = null;

    public function __construct(
        public readonly int $serverId,
        public readonly string $projectType,
        private ?string $leaseToken = null,
        public readonly bool $force = false,
        public readonly ?int $actorUserId = null,
        public readonly ?string $dispatchToken = null,
    ) {}

    /**
     * Seconds after which the queue should start this job again, or null
     * once the job reached a terminal outcome.
     */
    public function releaseAfterSeconds(): ?int
    {
        return $this->releaseAfterSeconds;
    }

    public function handle(
        DaemonFileRepository $fileRepository,
        InstalledProjectService $service,
        InstalledOperationManager $operations,
        InstalledOperationLease $leases,
        CacheRepository $cache,
        ProjectOperationAuthorizer $authorizer,
    ): void {
        $this->releaseAfterSeconds = null;
        $type = ProjectType::tryFrom($this->projectType);

        if (!$type) {
            return;
        }

        $force = $this->force;

        if ($this->dispatchToken === null) {
            // A job queued by an older release held its lease from dispatch.
            // It must never run under a replacement owner's lease.
            if ($this->leaseToken !== null && $leases->refresh($this->serverId, $type, $this->leaseToken)) {
                $this->scan($fileRepository, $service, $operations, $leases, $cache, $authorizer, $type, $force);
            }

            return;
        }

        $claim = $operations->claimQueuedScan($this->serverId, $type, $this->dispatchToken);

        if ($claim['status'] === 'stale') {
            return;
        }

        try {
            if ($claim['status'] === 'busy') {
                $this->waitForLease($operations, $type, $claim['state']?->secondsSinceQueued() ?? 0);

                return;
            }

            $this->leaseToken = $claim['lease_token'];
            // An explicit rescan may have taken over this queued dispatch.
            $force = $force || ($claim['state']?->result['force'] ?? false) === true;
            $this->scan($fileRepository, $service, $operations, $leases, $cache, $authorizer, $type, $force);
        } finally {
            if ($this->releaseAfterSeconds === null) {
                $operations->releasePendingDispatch(
                    $this->serverId,
                    $type,
                    InstalledOperationManager::OPERATION_SCAN,
                    $this->dispatchToken,
                );
            }
        }
    }

    private function scan(
        DaemonFileRepository $fileRepository,
        InstalledProjectService $service,
        InstalledOperationManager $operations,
        InstalledOperationLease $leases,
        CacheRepository $cache,
        ProjectOperationAuthorizer $authorizer,
        ProjectType $type,
        bool $force,
    ): void {
        /** @var Server|null $server */
        $server = Server::query()->with('egg')->find($this->serverId);

        if (!$server) {
            $operations->fail(
                $this->serverId,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                'server_not_found',
                leaseToken: $this->leaseToken,
            );

            return;
        }

        $actor = $this->actorUserId !== null && $this->actorUserId > 0
            ? User::query()->find($this->actorUserId)
            : null;

        if (!$authorizer->allows($actor, $server, ProjectOperation::Scan)) {
            $operations->fail(
                $server,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                'scan_unauthorized',
                leaseToken: $this->leaseToken,
            );

            return;
        }

        $operations->start(
            $server,
            $type,
            InstalledOperationManager::OPERATION_SCAN,
        );

        try {
            if ($force) {
                $cache->forget($service->getHashScanCacheKey($server, $type));
            }

            $result = $service->scanAndImportModsResult($server, $fileRepository, $type);

            if (!$result->successful && $result->failure === 'scan_in_progress') {
                // Another process holds the scan lock. Give the lease back so
                // foreground operations are not blocked while this job waits.
                if ($this->leaseToken !== null) {
                    $leases->release($this->serverId, $type, $this->leaseToken);
                    $this->leaseToken = null;
                }

                $this->waitForLease(
                    $operations,
                    $type,
                    $operations->state($server, $type, InstalledOperationManager::OPERATION_SCAN)?->secondsSinceQueued() ?? 0,
                );

                return;
            }

            $summary = [
                'disk_file_count' => $result->diskFileCount,
                'unknown_files_count' => count($result->unknownFiles),
                'cache_hit' => $result->cacheHit,
            ];

            if (!$result->successful) {
                $operations->fail(
                    $server,
                    $type,
                    InstalledOperationManager::OPERATION_SCAN,
                    $result->failure ?? 'scan_failed',
                    $summary,
                    $this->leaseToken,
                );

                return;
            }

            $operations->progress(
                $server,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                $result->diskFileCount,
                $result->diskFileCount,
            );
            $operations->complete(
                $server,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                $summary,
                $this->leaseToken,
            );
        } catch (Throwable $exception) {
            report($exception);

            $operations->fail(
                $server,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                'scan_exception',
                leaseToken: $this->leaseToken,
            );
        }
    }

    /**
     * Keep the scan queued while another managed operation owns the lease.
     * The scheduler starts it again later instead of sleeping in-process.
     */
    private function waitForLease(InstalledOperationManager $operations, ProjectType $type, int $waitedSeconds): void
    {
        if ($waitedSeconds >= self::BUSY_TIMEOUT_SECONDS) {
            $operations->fail(
                $this->serverId,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                'scan_busy_timeout',
            );

            return;
        }

        $operations->defer(
            $this->serverId,
            $type,
            InstalledOperationManager::OPERATION_SCAN,
            ['reason' => 'operation_busy'],
        );
        $this->releaseAfterSeconds = self::BUSY_RETRY_SECONDS;
    }

    public function failed(?Throwable $exception): void
    {
        $type = ProjectType::tryFrom($this->projectType);

        if (!$type) {
            return;
        }

        $container = Container::getInstance();
        $leases = $container->make(InstalledOperationLease::class);
        $operations = $container->make(InstalledOperationManager::class);
        $ownsLease = $this->leaseToken !== null && $leases->owns($this->serverId, $type, $this->leaseToken);
        $ownsDispatch = $this->dispatchToken !== null && $operations->ownsPendingDispatch(
            $this->serverId,
            $type,
            InstalledOperationManager::OPERATION_SCAN,
            $this->dispatchToken,
        );

        if (!$ownsLease && !$ownsDispatch) {
            return;
        }

        try {
            $operations->fail(
                $this->serverId,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                $exception === null ? 'scan_job_failed' : 'scan_job_exception',
                leaseToken: $ownsLease ? $this->leaseToken : null,
            );
        } finally {
            if ($this->dispatchToken !== null) {
                $operations->releasePendingDispatch(
                    $this->serverId,
                    $type,
                    InstalledOperationManager::OPERATION_SCAN,
                    $this->dispatchToken,
                );
            }
        }
    }
}
