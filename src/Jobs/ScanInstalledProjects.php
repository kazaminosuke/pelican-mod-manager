<?php

namespace Kazaminosuke\ModManager\Jobs;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Kazaminosuke\ModManager\Enums\ProjectOperation;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Services\InstalledProjectService;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\ProjectOperationAuthorizer;
use Throwable;

final class ScanInstalledProjects implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 5;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $serverId,
        public readonly string $projectType,
        public readonly string $leaseToken,
        public readonly bool $force = false,
        public readonly ?int $actorUserId = null,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function uniqueId(): string
    {
        return "mod-manager:scan:{$this->serverId}:{$this->projectType}";
    }

    public function handle(
        DaemonFileRepository $fileRepository,
        InstalledProjectService $service,
        InstalledOperationManager $operations,
        InstalledOperationLease $leases,
        CacheRepository $cache,
        ProjectOperationAuthorizer $authorizer,
    ): void {
        $type = ProjectType::tryFrom($this->projectType);

        if (!$type) {
            return;
        }

        // A lease can expire while a job waits in the queue. An older job
        // must never perform work under a replacement owner's lease.
        if (!$leases->refresh($this->serverId, $type, $this->leaseToken)) {
            return;
        }

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
            // A released job keeps force=true, so only the first attempt should
            // invalidate a completed scan produced by another worker.
            if ($this->force && $this->attempts() <= 1) {
                $cache->forget($service->getHashScanCacheKey($server, $type));
            }

            $result = $service->scanAndImportModsResult($server, $fileRepository, $type);

            if (!$result->successful && $result->failure === 'scan_in_progress') {
                if ($this->attempts() >= $this->tries) {
                    $operations->fail(
                        $server,
                        $type,
                        InstalledOperationManager::OPERATION_SCAN,
                        'scan_busy_timeout',
                        leaseToken: $this->leaseToken,
                    );

                    return;
                }

                $operations->defer(
                    $server,
                    $type,
                    InstalledOperationManager::OPERATION_SCAN,
                    ['reason' => 'scan_in_progress'],
                );
                $this->release($this->retryDelay());

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
            InstalledOperationManager::OPERATION_SCAN,
            $exception === null ? 'scan_job_failed' : 'scan_job_exception',
            leaseToken: $this->leaseToken,
        );
    }

    private function retryDelay(): int
    {
        $backoff = $this->backoff();

        return $backoff[min(max(0, $this->attempts() - 1), count($backoff) - 1)];
    }
}
