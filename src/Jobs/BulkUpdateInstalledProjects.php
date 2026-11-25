<?php

namespace Kazaminosuke\ModManager\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Closure;
use Illuminate\Container\Container;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectUpdateService;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use RuntimeException;
use Throwable;

final class BulkUpdateInstalledProjects
{
    public int $uniqueFor = 1200;

    private const LEASE_REFRESH_SECONDS = 30;

    /**
     * @param  (Closure(): bool)|null  $heartbeat
     */
    public function __construct(
        public readonly int $serverId,
        public readonly string $projectType,
        public readonly string $leaseToken,
        public readonly ?int $claimEpoch = null,
        private readonly ?Closure $heartbeat = null,
    ) {}

    public function uniqueId(): string
    {
        return "mod-manager:bulk-update:{$this->serverId}:{$this->projectType}";
    }

    public function handle(
        DaemonFileRepository $fileRepository,
        InstalledProjectUpdateService $updates,
        InstalledOperationManager $operations,
        InstalledOperationLease $leases,
    ): void {
        $type = ProjectType::tryFrom($this->projectType);

        if (!$type) {
            return;
        }

        if (!$leases->refresh($this->serverId, $type, $this->leaseToken, epoch: $this->claimEpoch)) {
            return;
        }

        /** @var Server|null $server */
        $server = Server::query()->with('egg')->find($this->serverId);

        if (!$server) {
            $operations->fail(
                $this->serverId,
                $type,
                InstalledOperationManager::OPERATION_BULK_UPDATE,
                'server_not_found',
                leaseToken: $this->leaseToken,
                leaseEpoch: $this->claimEpoch,
            );

            return;
        }

        $operations->start(
            $server,
            $type,
            InstalledOperationManager::OPERATION_BULK_UPDATE,
        );

        $leaseRefreshedAt = microtime(true);

        try {
            $result = $updates->updateAll(
                $server,
                $fileRepository,
                $type,
                function (int $progress, int $total) use ($operations, $leases, $server, $type, &$leaseRefreshedAt): void {
                    // A large bulk update can outlive the lease's crash-recovery
                    // TTL. Renew both cache lease and DB reservation while
                    // progressing so another worker cannot reclaim a live job.
                    if (microtime(true) - $leaseRefreshedAt >= self::LEASE_REFRESH_SECONDS) {
                        if ($this->heartbeat !== null && !($this->heartbeat)()) {
                            throw new RuntimeException('The bulk-update background job reservation was superseded.');
                        }
                        if (!$leases->refresh($this->serverId, $type, $this->leaseToken, epoch: $this->claimEpoch)) {
                            throw new RuntimeException('The server operation lease expired.');
                        }
                        $leaseRefreshedAt = microtime(true);
                    }

                    $operations->progress(
                        $server,
                        $type,
                        InstalledOperationManager::OPERATION_BULK_UPDATE,
                        $progress,
                        $total,
                    );
                },
            );

            $operations->complete(
                $server,
                $type,
                InstalledOperationManager::OPERATION_BULK_UPDATE,
                $result,
                $this->leaseToken,
                $this->claimEpoch,
            );
        } catch (Throwable $exception) {
            report($exception);

            $operations->fail(
                $server,
                $type,
                InstalledOperationManager::OPERATION_BULK_UPDATE,
                'bulk_update_exception',
                leaseToken: $this->leaseToken,
                leaseEpoch: $this->claimEpoch,
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $type = ProjectType::tryFrom($this->projectType);

        if (!$type) {
            return;
        }

        $container = Container::getInstance();
        $leases = $container->make(InstalledOperationLease::class);

        if (!$leases->owns($this->serverId, $type, $this->leaseToken, $this->claimEpoch)) {
            return;
        }

        $container->make(InstalledOperationManager::class)->fail(
            $this->serverId,
            $type,
            InstalledOperationManager::OPERATION_BULK_UPDATE,
            $exception === null ? 'bulk_update_job_failed' : 'bulk_update_job_exception',
            leaseToken: $this->leaseToken,
            leaseEpoch: $this->claimEpoch,
        );
    }
}
