<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use InvalidArgumentException;
use Kazaminosuke\ModManager\Enums\ProjectOperation;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\ProjectOperationAuthorizer;
use Throwable;

/**
 * Coordinates destructive installed-metadata resets. The operation lease is
 * intentionally long-lived and separate from InstalledMetadataRepository's
 * short document lock; the latter is acquired by clearInstalledModsMetadata().
 */
final class InstalledMetadataResetService
{
    public const STATUS_CLEARED = 'cleared';

    public const STATUS_BUSY = 'busy';

    public const STATUS_UNAUTHORIZED = 'unauthorized';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNSUPPORTED = 'unsupported';

    public function __construct(
        private readonly InstalledProjectService $projects,
        private readonly InstalledOperationLease $leases,
        private readonly ProjectOperationAuthorizer $authorizer,
    ) {}

    /**
     * Best-effort clear used by the all-server settings action. It never
     * scans synchronously, and it never waits for a busy managed operation.
     *
     * @param  array<int, ProjectType>  $projectTypes
     * @return array{status: string, cleared_types: array<int, string>}
     */
    public function clearWithoutScan(
        Server $server,
        DaemonFileRepository $fileRepository,
        array $projectTypes,
        ?User $actor,
    ): array {
        try {
            $types = $this->normalizeArchiveTypes($projectTypes);
        } catch (InvalidArgumentException) {
            return ['status' => self::STATUS_UNSUPPORTED, 'cleared_types' => []];
        }

        if ($types === []) {
            return ['status' => self::STATUS_CLEARED, 'cleared_types' => []];
        }

        // This action lives behind PluginResource's existing `update plugin`
        // authorization. Preserve that admin contract for metadata deletion;
        // a queued re-scan is checked separately against its existing file
        // permission contract below.
        if (!$this->canManagePlugin($actor)) {
            return ['status' => self::STATUS_UNAUTHORIZED, 'cleared_types' => []];
        }

        $tokens = $this->leases->tryAcquireMany(
            (int) $server->getKey(),
            array_values($types),
            InstalledOperationLease::OPERATION_CLEAR,
        );

        if ($tokens === null) {
            return ['status' => self::STATUS_BUSY, 'cleared_types' => []];
        }

        $clearedTypes = [];
        $status = self::STATUS_CLEARED;

        try {
            foreach ($types as $typeValue => $type) {
                // A very old request whose lease expired must not delete
                // metadata after another owner has claimed this type.
                if (!$this->leases->owns((int) $server->getKey(), $type, $tokens[$typeValue])) {
                    $status = self::STATUS_BUSY;
                    break;
                }

                $this->projects->clearInstalledModsMetadata($server, $fileRepository, $type);
                $clearedTypes[] = $typeValue;
            }
        } catch (Throwable $exception) {
            report($exception);
            $status = self::STATUS_FAILED;
        } finally {
            $this->leases->releaseMany((int) $server->getKey(), $tokens);
        }

        return ['status' => $status, 'cleared_types' => $clearedTypes];
    }

    /**
     * Authorization, locked deletion, and fresh scan for the dedicated reset
     * job. The exact dispatch-time lease token remains held through each
     * type's complete scan (or terminal failure).
     *
     * @param  array<int, ProjectType>  $projectTypes
     * @param  array<string, string>  $leaseTokens
     */
    public function resetAndScan(
        Server $server,
        DaemonFileRepository $fileRepository,
        array $projectTypes,
        array $leaseTokens,
        ?User $actor,
        InstalledOperationManager $operations,
    ): void {
        $types = $this->normalizeArchiveTypes($projectTypes);
        $serverId = (int) $server->getKey();

        if (!$this->canManagePlugin($actor)) {
            $this->failOwnedTypes($serverId, $types, $leaseTokens, $operations, 'scan_unauthorized');

            return;
        }

        foreach ($types as $typeValue => $type) {
            $token = $leaseTokens[$typeValue] ?? null;

            if (!is_string($token) || !$this->leases->refresh($serverId, $type, $token)) {
                $this->failOwnedTypes($serverId, $types, $leaseTokens, $operations, 'operation_lease_lost');

                return;
            }
        }

        foreach ($types as $type) {
            $operations->start($server, $type, InstalledOperationManager::OPERATION_SCAN);
        }

        foreach ($types as $typeValue => $type) {
            $token = $leaseTokens[$typeValue];

            if (!$this->leases->owns($serverId, $type, $token)) {
                continue;
            }

            try {
                $this->projects->clearInstalledModsMetadata($server, $fileRepository, $type);

                // The old settings flow always allowed an `update plugin`
                // admin to clear metadata, but the follow-up scan still used
                // ProjectOperationAuthorizer. Keep those two permissions
                // distinct while the same lease prevents intervening writes.
                if (!$this->authorizer->allows($actor, $server, ProjectOperation::Scan)) {
                    $operations->fail(
                        $server,
                        $type,
                        InstalledOperationManager::OPERATION_SCAN,
                        'scan_unauthorized',
                        ['metadata_reset' => true],
                        $token,
                    );

                    continue;
                }

                $result = $this->projects->scanAndImportModsResult($server, $fileRepository, $type);
                $summary = [
                    'metadata_reset' => true,
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
                        $token,
                    );

                    continue;
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
                    $token,
                );
            } catch (Throwable $exception) {
                report($exception);
                $operations->fail(
                    $server,
                    $type,
                    InstalledOperationManager::OPERATION_SCAN,
                    'metadata_reset_exception',
                    leaseToken: $token,
                );
            }
        }
    }

    /**
     * @param  array<int, ProjectType>  $projectTypes
     * @return array<string, ProjectType>
     */
    private function normalizeArchiveTypes(array $projectTypes): array
    {
        $types = [];

        foreach ($projectTypes as $type) {
            if (!$type instanceof ProjectType || !$type->usesArchiveMetadata()) {
                throw new InvalidArgumentException('Installed metadata reset only supports managed archive project types.');
            }

            $types[$type->value] = $type;
        }

        ksort($types, SORT_STRING);

        return $types;
    }

    /**
     * @param  array<string, ProjectType>  $types
     * @param  array<string, string>  $leaseTokens
     */
    private function failOwnedTypes(
        int $serverId,
        array $types,
        array $leaseTokens,
        InstalledOperationManager $operations,
        string $error,
    ): void {
        foreach ($types as $typeValue => $type) {
            $token = $leaseTokens[$typeValue] ?? null;

            if (!is_string($token) || !$this->leases->owns($serverId, $type, $token)) {
                continue;
            }

            $operations->fail(
                $serverId,
                $type,
                InstalledOperationManager::OPERATION_SCAN,
                $error,
                leaseToken: $token,
            );
        }
    }

    private function canManagePlugin(?User $actor): bool
    {
        return $actor !== null
            && ($actor->isRootAdmin() || $actor->can('update plugin'));
    }
}
