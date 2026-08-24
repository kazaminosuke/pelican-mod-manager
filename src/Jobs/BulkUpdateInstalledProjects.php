<?php

namespace Kazaminosuke\ModManager\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectUpdateService;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Throwable;

final class BulkUpdateInstalledProjects implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 1200;

    public function __construct(
        public readonly int $serverId,
        public readonly string $projectType,
        public readonly string $leaseToken,
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

        if (!$leases->refresh($this->serverId, $type, $this->leaseToken)) {
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
            );

            return;
        }

        $operations->start(
            $server,
            $type,
            InstalledOperationManager::OPERATION_BULK_UPDATE,
        );

        try {
            $result = $updates->updateAll(
                $server,
                $fileRepository,
                $type,
                function (int $progress, int $total) use ($operations, $server, $type): void {
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
            );
        } catch (Throwable $exception) {
            report($exception);

            $operations->fail(
                $server,
                $type,
                InstalledOperationManager::OPERATION_BULK_UPDATE,
                'bulk_update_exception',
                leaseToken: $this->leaseToken,
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

        if (!$leases->owns($this->serverId, $type, $this->leaseToken)) {
            return;
        }

        $container->make(InstalledOperationManager::class)->fail(
            $this->serverId,
            $type,
            InstalledOperationManager::OPERATION_BULK_UPDATE,
            $exception === null ? 'bulk_update_job_failed' : 'bulk_update_job_exception',
            leaseToken: $this->leaseToken,
        );
    }
}
