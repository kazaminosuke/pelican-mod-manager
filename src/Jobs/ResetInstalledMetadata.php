<?php

namespace Kazaminosuke\ModManager\Jobs;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Services\InstalledMetadataResetService;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Throwable;

final class ResetInstalledMetadata implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 1200;

    /**
     * @param  array<int, string>  $projectTypes
     * @param  array<string, string>  $leaseTokens
     */
    public function __construct(
        public readonly int $serverId,
        public readonly array $projectTypes,
        public readonly array $leaseTokens,
        public readonly int $actorUserId,
    ) {}

    public function uniqueId(): string
    {
        return 'mod-manager:metadata-reset:'.$this->serverId.':'.hash('sha256', implode(',', $this->projectTypes));
    }

    public function handle(
        DaemonFileRepository $fileRepository,
        InstalledMetadataResetService $resets,
        InstalledOperationManager $operations,
        InstalledOperationLease $leases,
    ): void {
        $types = $this->types();

        if ($types === []) {
            return;
        }

        foreach ($types as $type) {
            $token = $this->leaseTokens[$type->value] ?? null;

            if (!is_string($token) || !$leases->refresh($this->serverId, $type, $token)) {
                $this->failOwnedLeases($leases, $operations, 'operation_lease_lost');

                return;
            }
        }

        /** @var Server|null $server */
        $server = Server::query()->with('egg')->find($this->serverId);

        if ($server === null) {
            foreach ($types as $type) {
                $token = $this->leaseTokens[$type->value] ?? null;

                if (is_string($token)) {
                    $operations->fail(
                        $this->serverId,
                        $type,
                        InstalledOperationManager::OPERATION_SCAN,
                        'server_not_found',
                        leaseToken: $token,
                    );
                }
            }

            return;
        }

        $actor = User::query()->find($this->actorUserId);
        $resets->resetAndScan(
            $server,
            $fileRepository,
            $types,
            $this->leaseTokens,
            $actor,
            $operations,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $container = Container::getInstance();
        $leases = $container->make(InstalledOperationLease::class);
        $operations = $container->make(InstalledOperationManager::class);

        foreach ($this->types() as $type) {
            $token = $this->leaseTokens[$type->value] ?? null;

            if (!is_string($token) || !$leases->owns($this->serverId, $type, $token)) {
                continue;
            }

            $operations->fail(
                $this->serverId,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                $exception === null ? 'metadata_reset_job_failed' : 'metadata_reset_job_exception',
                leaseToken: $token,
            );
        }
    }

    /** @return array<int, ProjectType> */
    private function types(): array
    {
        $types = [];

        foreach ($this->projectTypes as $typeValue) {
            $type = ProjectType::tryFrom($typeValue);

            if ($type?->usesArchiveMetadata()) {
                $types[$type->value] = $type;
            }
        }

        ksort($types, SORT_STRING);

        return array_values($types);
    }

    private function failOwnedLeases(
        InstalledOperationLease $leases,
        InstalledOperationManager $operations,
        string $error,
    ): void {
        foreach ($this->types() as $type) {
            $token = $this->leaseTokens[$type->value] ?? null;

            if (!is_string($token) || !$leases->owns($this->serverId, $type, $token)) {
                continue;
            }

            $operations->fail(
                $this->serverId,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                $error,
                leaseToken: $token,
            );
        }
    }
}
