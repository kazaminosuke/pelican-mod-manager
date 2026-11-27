<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\CurseForgeFile;
use Kazaminosuke\ModManager\Support\InstalledOperationLease;
use Kazaminosuke\ModManager\Support\ProjectPrimaryFile;

/**
 * Application entry for a single install/update. The Page authorizes, then
 * this service acquires the operation lease and runs the shared file
 * transaction. Bulk update holds the lease at dispatch time and calls the
 * transaction directly.
 */
final class InstalledProjectMutationService
{
    public function __construct(
        private readonly InstalledArchiveTransaction $archives,
        private readonly InstalledOperationLease $leases,
        private readonly float $leaseWaitSeconds = InstalledOperationLease::FOREGROUND_WAIT_SECONDS,
        private readonly ?CurseForgeManualFiles $manualFiles = null,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $versionData
     * @param  array<string, mixed>|null  $primaryFile
     * @param  array<string, mixed>|null  $installedMod
     */
    public function installOrUpdate(
        Server $server,
        DaemonFileRepository $fileRepository,
        ProjectType $type,
        array $record,
        array $versionData,
        ?array $installedMod = null,
        ?array $primaryFile = null,
        ?CurseForgeFile $manualFile = null,
    ): string {
        if (!$type->usesArchiveMetadata()) {
            throw new Exception('Resource packs must use the dedicated direct URL and SHA-1 transaction.');
        }

        $file = $primaryFile ?? ProjectPrimaryFile::fromVersion($versionData);

        if ($manualFile !== null && ($type !== ProjectType::Mod
            || ($record['source'] ?? null) !== ProjectSourceKey::CurseForge->value
            || (string) ($record['project_id'] ?? '') !== (string) $manualFile->projectId
            || (string) ($versionData['id'] ?? '') !== (string) $manualFile->fileId
            || ($file['filename'] ?? null) !== $manualFile->filename)) {
            throw new Exception('The CurseForge upload does not match the selected file.');
        }
        if ($file === null || ($manualFile === null && !ProjectPrimaryFile::isDownloadable($file))) {
            throw new Exception('No downloadable file found');
        }

        $operation = $installedMod === null
            ? InstalledOperationLease::OPERATION_INSTALL
            : InstalledOperationLease::OPERATION_UPDATE;
        $serverId = (int) $server->getKey();

        return $this->leases->run(
            $serverId,
            $type,
            $operation,
            function () use ($server, $fileRepository, $type, $record, $versionData, $file, $installedMod, $manualFile): string {
                if ($manualFile === null) {
                    $this->archives->installOrUpdate($server, $fileRepository, $type, $record, $versionData, $file, $installedMod);
                } else {
                    $snapshot = ($this->manualFiles ?? app(CurseForgeManualFiles::class))->verifiedSnapshot($server, $fileRepository, $manualFile);
                    try {
                        $this->archives->installOrUpdate($server, $fileRepository, $type, $record, $versionData, $file, $installedMod, verifiedUpload: $snapshot);
                    } finally {
                        $snapshot->close();
                    }
                }

                return (string) $file['filename'];
            },
            $this->leaseWaitSeconds,
        );
    }
}
