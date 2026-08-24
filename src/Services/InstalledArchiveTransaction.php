<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Closure;
use Exception;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Throwable;

/**
 * Single install/update file+metadata transaction.
 *
 * Order:
 * 1. foreground pull into a non-loadable temp name (Wings writes in place)
 * 2. confirm completion (HTTP 200, not 202) and size/existence
 * 3. rename/swap into the final archive name
 * 4. commit installed metadata
 * 5. delete the previous archive (and any backup) only after metadata commit
 */
class InstalledArchiveTransaction
{
    public function __construct(
        private readonly InstalledProjectService $projects,
        private readonly WingsRemoteFilesystem $wings,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $versionData
     * @param  array<string, mixed>  $primaryFile
     * @param  array<string, mixed>|null  $installedMod
     * @param  (Closure(array<string, mixed>): bool)|null  $commitMetadata
     */
    public function installOrUpdate(
        Server $server,
        DaemonFileRepository $fileRepository,
        ProjectType $type,
        array $record,
        array $versionData,
        array $primaryFile,
        ?array $installedMod = null,
        ?Closure $commitMetadata = null,
    ): void {
        $newFilename = $this->safeFilename($this->requiredString($primaryFile, 'filename'));
        $oldFilename = is_array($installedMod)
            ? $this->safeFilename($this->requiredString($installedMod, 'filename'))
            : null;
        $url = $this->requiredString($primaryFile, 'url');
        $expectedSize = $this->optionalPositiveInt($primaryFile['size'] ?? $primaryFile['filesize'] ?? null);
        $folder = $this->projects->getProjectFolder($server, $fileRepository, $type);
        $source = ProjectSourceKey::tryFrom((string) ($record['source'] ?? ($installedMod['source'] ?? '')))
            ?? ProjectSourceKey::Modrinth;
        $projectId = $this->requiredString($record, 'project_id');
        $projectSlug = $this->firstRequiredString($record, ['slug', 'project_slug']);
        $projectTitle = $this->firstRequiredString($record, ['title', 'project_title']);
        $versionId = $this->requiredString($versionData, 'id');
        $versionNumber = $this->firstRequiredString($versionData, ['version_number', 'versionNumber']);
        $author = $this->optionalString($record['author'] ?? ($installedMod['author'] ?? null));
        $document = $this->authoritativeDocument($server, $fileRepository, $type);

        $this->assertFilenameAvailable($document, $source, $projectId, $newFilename);

        $destinationExists = $this->wings->findListedFile($fileRepository, $server, $folder, $newFilename) !== null;
        $this->assertDestinationNotOrphaned($destinationExists, $document, $newFilename, $source, $projectId, $oldFilename);

        set_time_limit(WingsRemoteFilesystem::FOREGROUND_PULL_TIMEOUT_SECONDS);

        $tempFilename = $this->uniqueHiddenName('pull');
        $backupFilename = null;
        $activated = false;

        try {
            $stat = $this->wings->pullForeground($fileRepository, $server, $url, $folder, $tempFilename);
            $this->assertCompletedPull($fileRepository, $server, $folder, $tempFilename, $stat, $expectedSize);

            if ($destinationExists) {
                $backupFilename = $this->uniqueHiddenName('prev');
                $this->wings->rename($fileRepository, $server, $folder, $newFilename, $backupFilename);
            }

            try {
                $this->wings->rename($fileRepository, $server, $folder, $tempFilename, $newFilename);
            } catch (Throwable $renameException) {
                if ($backupFilename !== null) {
                    $this->wings->rename($fileRepository, $server, $folder, $backupFilename, $newFilename);
                    $backupFilename = null;
                }

                throw $renameException;
            }

            $activated = true;

            $saved = $this->commitMetadataEntry(
                $server,
                $fileRepository,
                $projectId,
                $projectSlug,
                $projectTitle,
                $versionId,
                $versionNumber,
                $newFilename,
                $author,
                $type,
                $source,
                $commitMetadata,
            );

            if (!$saved) {
                $this->rollbackActivatedArchive(
                    $fileRepository,
                    $server,
                    $folder,
                    $newFilename,
                    $backupFilename,
                );
                $backupFilename = null;

                throw new Exception('Failed to save mod metadata');
            }

            if ($oldFilename !== null && strtolower($oldFilename) !== strtolower($newFilename)) {
                try {
                    $this->wings->delete($fileRepository, $server, $folder, $oldFilename);
                } catch (Throwable $deleteException) {
                    $this->rollbackActivatedArchive(
                        $fileRepository,
                        $server,
                        $folder,
                        $newFilename,
                        $backupFilename,
                    );
                    $backupFilename = null;

                    $this->restoreInstalledMetadata($server, $fileRepository, $installedMod, $type, $commitMetadata);

                    throw $deleteException;
                }
            }

            if ($backupFilename !== null) {
                $this->wings->deleteQuietly($fileRepository, $server, $folder, $backupFilename);
                $backupFilename = null;
            }
        } catch (Throwable $exception) {
            $this->wings->deleteQuietly($fileRepository, $server, $folder, $tempFilename);

            if (!$activated && $backupFilename !== null) {
                try {
                    $this->wings->rename($fileRepository, $server, $folder, $backupFilename, $newFilename);
                } catch (Throwable $restoreException) {
                    report($restoreException);
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $stat
     */
    private function assertCompletedPull(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $folder,
        string $tempFilename,
        array $stat,
        ?int $expectedSize,
    ): void {
        $listed = $this->wings->findListedFile($fileRepository, $server, $folder, $tempFilename);

        if ($listed === null) {
            throw new Exception('Pulled archive was not present after foreground completion.');
        }

        $size = $this->wings->listedFileSize($listed) ?? $this->wings->listedFileSize($stat);

        if ($size === null || $size <= 0) {
            throw new Exception('Pulled archive is empty.');
        }

        if ($expectedSize !== null && $size !== $expectedSize) {
            throw new Exception("Pulled archive size [{$size}] does not match expected size [{$expectedSize}].");
        }
    }

    private function rollbackActivatedArchive(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $folder,
        string $newFilename,
        ?string $backupFilename,
    ): void {
        $this->wings->deleteQuietly($fileRepository, $server, $folder, $newFilename);

        if ($backupFilename === null) {
            return;
        }

        try {
            $this->wings->rename($fileRepository, $server, $folder, $backupFilename, $newFilename);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>|null  $installedMod
     * @param  (Closure(array<string, mixed>): bool)|null  $commitMetadata
     */
    private function restoreInstalledMetadata(
        Server $server,
        DaemonFileRepository $fileRepository,
        ?array $installedMod,
        ProjectType $type,
        ?Closure $commitMetadata,
    ): void {
        if ($installedMod === null) {
            return;
        }

        $source = ProjectSourceKey::tryFrom((string) ($installedMod['source'] ?? '')) ?? ProjectSourceKey::Modrinth;

        if (!$this->commitMetadataEntry(
            $server,
            $fileRepository,
            $this->requiredString($installedMod, 'project_id'),
            $this->firstRequiredString($installedMod, ['project_slug', 'slug']),
            $this->firstRequiredString($installedMod, ['project_title', 'title']),
            $this->requiredString($installedMod, 'version_id'),
            $this->firstRequiredString($installedMod, ['version_number', 'versionNumber']),
            $this->safeFilename($this->requiredString($installedMod, 'filename')),
            $this->optionalString($installedMod['author'] ?? null),
            $type,
            $source,
            $commitMetadata,
        )) {
            report(new Exception('Failed to restore old mod metadata during rollback'));
        }
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $commitMetadata
     */
    private function commitMetadataEntry(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $projectId,
        string $projectSlug,
        string $projectTitle,
        string $versionId,
        string $versionNumber,
        string $filename,
        ?string $author,
        ProjectType $type,
        ProjectSourceKey $source,
        ?Closure $commitMetadata,
    ): bool {
        $entry = [
            'source' => $source->value,
            'project_id' => $projectId,
            'project_slug' => $projectSlug,
            'project_title' => $projectTitle,
            'version_id' => $versionId,
            'version_number' => $versionNumber,
            'filename' => $filename,
            'installed_at' => gmdate('c'),
        ];

        if ($author !== null) {
            $entry['author'] = $author;
        }

        if ($commitMetadata !== null) {
            return $commitMetadata($entry);
        }

        return $this->projects->saveModMetadata(
            $server,
            $fileRepository,
            $projectId,
            $projectSlug,
            $projectTitle,
            $versionId,
            $versionNumber,
            $filename,
            $author,
            $type,
            $source,
        );
    }

    private function authoritativeDocument(
        Server $server,
        DaemonFileRepository $fileRepository,
        ProjectType $type,
    ): InstalledMetadataDocument {
        $result = $this->projects->getInstalledMetadataReadResult($server, $fileRepository, $type);

        if (!$result->isAuthoritative() && $result->status !== InstalledMetadataReadStatus::Missing) {
            throw new Exception('Installed metadata is unavailable.');
        }

        return $result->document;
    }

    private function assertFilenameAvailable(
        InstalledMetadataDocument $document,
        ProjectSourceKey $source,
        string $projectId,
        string $newFilename,
    ): void {
        $owner = $document->installedModOwningFilename($newFilename);

        if ($owner === null) {
            return;
        }

        if (($owner['source'] ?? '') === $source->value && ($owner['project_id'] ?? '') === $projectId) {
            return;
        }

        throw new Exception('Filename is already used by another installed project.');
    }

    private function assertDestinationNotOrphaned(
        bool $destinationExists,
        InstalledMetadataDocument $document,
        string $newFilename,
        ProjectSourceKey $source,
        string $projectId,
        ?string $oldFilename,
    ): void {
        if (!$destinationExists) {
            return;
        }

        if ($oldFilename !== null && strtolower($oldFilename) === strtolower($newFilename)) {
            return;
        }

        $owner = $document->installedModOwningFilename($newFilename);

        if ($owner !== null && ($owner['source'] ?? '') === $source->value && ($owner['project_id'] ?? '') === $projectId) {
            return;
        }

        throw new Exception('Filename already exists on disk.');
    }

    private function uniqueHiddenName(string $kind): string
    {
        return '.mod-manager-'.$kind.'-'.bin2hex(random_bytes(8));
    }

    private function safeFilename(string $filename): string
    {
        if ($filename === '' || $filename === '.' || str_contains($filename, "\0") || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new Exception('Invalid filename.');
        }

        return basename($filename);
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new Exception("Missing required value [{$key}].");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function firstRequiredString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        throw new Exception('Missing required value ['.$keys[0].'].');
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $size = (int) $value;

        return $size > 0 ? $size : null;
    }
}
