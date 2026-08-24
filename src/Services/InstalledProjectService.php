<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use InvalidArgumentException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kazaminosuke\ModManager\Contracts\ProjectSourceInterface;
use Kazaminosuke\ModManager\Contracts\SourceFetchAuthoritativeInterface;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Repositories\InstalledMetadataRepository;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Support\CurseForgeFingerprint;
use Kazaminosuke\ModManager\Support\InstalledMetadataDocument;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadResult;
use Kazaminosuke\ModManager\Support\InstalledMetadataReadStatus;
use Kazaminosuke\ModManager\Support\InstalledScanResult;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;

class InstalledProjectService
{
    private const HASH_SCAN_CACHE_MINUTES = 10;

    private const HASH_SCAN_LOCK_SECONDS = 180;

    public function __construct(
        protected ProjectSourceRegistry $sourceRegistry,
        protected InstalledMetadataRepository $metadataRepository,
    ) {}

    /** @var array<int, array<string, string>|null> */
    protected array $serverPropertiesCache = [];

    protected int $hashScanWingsGetCount = 0;

    protected bool $debugTimingEnabled = false;

    /** @param array<string, mixed> $context */
    protected function logModManagerTiming(string $stage, float $startedAt, array $context = []): void
    {
        if (!$this->debugTimingEnabled) {
            return;
        }

        $requestId = request()->attributes->get('mmr_timing_request_id');
        $requestStartedAt = request()->attributes->get('mmr_timing_started_at');

        if (!is_string($requestId) || !is_float($requestStartedAt)) {
            return;
        }

        $finishedAt = microtime(true);

        Log::info('Mod manager timing', array_merge($context, [
            'stage' => $stage,
            'request_id' => $requestId,
            'started_after_ms' => (int) round(($startedAt - $requestStartedAt) * 1000),
            'finished_after_ms' => (int) round(($finishedAt - $requestStartedAt) * 1000),
            'duration_ms' => (int) round(($finishedAt - $startedAt) * 1000),
        ]));
    }

    public function clearRuntimeCaches(): void
    {
        $this->serverPropertiesCache = [];
        $this->hashScanWingsGetCount = 0;
        $this->debugTimingEnabled = false;
    }

    public function getMinecraftVersion(Server $server): ?string
    {
        return MinecraftVersionResolver::resolve($server);
    }

    public function scanAndImportModsResult(Server $server, DaemonFileRepository $fileRepository, ?ProjectType $type = null): InstalledScanResult
    {
        set_time_limit(240);

        $this->debugTimingEnabled = (bool) config('pelican-mod-manager.debug_timing', false);
        $startedAt = $this->debugTimingEnabled ? microtime(true) : 0.0;
        $resolvedType = $type ?? ProjectType::fromServer($server);

        if ($resolvedType === null) {
            return InstalledScanResult::failed('unsupported_project_type');
        }

        $this->assertArchiveMetadataType($resolvedType);
        $cacheKey = $this->getHashScanCacheKey($server, $resolvedType);
        $cachedResult = InstalledScanResult::fromCache(Cache::get($cacheKey));
        $scanExecuted = false;
        $this->hashScanWingsGetCount = 0;

        if ($cachedResult !== null) {
            $result = $cachedResult;
        } else {
            $lock = Cache::lock($cacheKey.':lock', self::HASH_SCAN_LOCK_SECONDS);

            if (!$lock->get()) {
                $result = InstalledScanResult::failed('scan_in_progress');
            } else {
                try {
                    $cachedAfterLock = InstalledScanResult::fromCache(Cache::get($cacheKey));

                    if ($cachedAfterLock !== null) {
                        $result = $cachedAfterLock;
                    } else {
                        $scanExecuted = true;
                        $result = $this->performScan($server, $fileRepository, $resolvedType);

                        // A normal empty folder is a successful, authoritative
                        // result and is cacheable. Transport errors and malformed
                        // Wings responses are failures and must never poison the
                        // Installed tab with a cached empty result.
                        if ($result->successful) {
                            Cache::put($cacheKey, $result->toCachePayload(), now()->addMinutes(self::HASH_SCAN_CACHE_MINUTES));
                        }
                    }
                } finally {
                    $lock->release();
                }
            }
        }

        if ($this->debugTimingEnabled) {
            $this->logModManagerTiming('installed_scan', $startedAt, [
                'cache_key' => $cacheKey,
                'cache_hit' => $result->cacheHit,
                'scan_executed' => $scanExecuted,
                'successful' => $result->successful,
                'failure' => $result->failure,
                'wings_get_count' => $this->hashScanWingsGetCount,
                'disk_file_count' => $result->diskFileCount,
                'unknown_files_count' => count($result->unknownFiles),
            ]);
        }

        return $result;
    }

    public function getHashScanCacheKey(Server $server, ?ProjectType $type = null): string
    {
        $resolvedType = $type ?? ProjectType::fromServer($server);

        if ($resolvedType !== null) {
            $this->assertArchiveMetadataType($resolvedType);
        }

        $typeKey = $resolvedType instanceof ProjectType ? $resolvedType->value : 'unknown';

        return "installed_scan:v2:{$server->id}:{$typeKey}";
    }

    /**
     * Deletes this server's current installed-mods metadata file and clears
     * the caches that would otherwise keep serving stale results
     * afterwards: the hydration display cache and the 10-minute
     * scanAndImportModsResult() cache. Without clearing the latter, the next
     * "Installed" tab load would silently reuse a cached pre-deletion scan
     * result instead of noticing every file is now unknown again, which is
     * exactly what was observed when this file was deleted by hand.
     *
     * Deleting the metadata file does not, by itself, cause anything to be
     * re-scanned. The settings reset workflow owns the long operation lease
     * and queues a fresh scan when that behavior is requested.
     *
     * @throws Exception
     */
    public function clearInstalledModsMetadata(Server $server, DaemonFileRepository $fileRepository, ?ProjectType $type = null): void
    {
        $type ??= ProjectType::fromServer($server);
        $folder = $this->resolveMetadataFolder($server, $fileRepository, $type);

        if (!$this->metadataRepository->delete($server, $fileRepository, $folder)) {
            throw new Exception('Failed to delete installed metadata.');
        }

        cache()->forget($this->getHashScanCacheKey($server, $type));
    }

    public function getProjectFolder(Server $server, DaemonFileRepository $fileRepository, ?ProjectType $type = null): string
    {
        $resolvedType = $type ?? ProjectType::fromServer($server);

        if ($resolvedType !== ProjectType::Datapack) {
            return $resolvedType?->getFolder() ?? 'mods';
        }

        return $this->getDatapackWorldName($server, $fileRepository).'/datapacks';
    }

    public function getDatapackWorldName(Server $server, DaemonFileRepository $fileRepository): string
    {
        $rawWorldName = (string) $this->getServerPropertiesValue($server, $fileRepository, 'level-name');

        // Check control characters before trim(), which would otherwise
        // discard a NUL at either edge and turn an invalid value into a valid
        // path segment.
        if (preg_match('/[\x00-\x1F\x7F]/', $rawWorldName) === 1) {
            throw new Exception('Invalid datapack world name.');
        }

        $worldName = trim($rawWorldName);

        if ($worldName === '') {
            return 'world';
        }

        if ($worldName === '.'
            || $worldName === '..'
            || strpbrk($worldName, '/\\') !== false) {
            throw new Exception('Invalid datapack world name.');
        }

        return $worldName;
    }

    protected function getServerPropertiesValue(Server $server, DaemonFileRepository $fileRepository, string $key): ?string
    {
        if (!array_key_exists($server->id, $this->serverPropertiesCache)) {
            $this->serverPropertiesCache[$server->id] = $this->getServerProperties($server, $fileRepository);
        }

        $properties = $this->serverPropertiesCache[$server->id];

        return $properties ? ($properties[$key] ?? null) : null;
    }

    /** @return array<string, string>|null */
    protected function getServerProperties(Server $server, DaemonFileRepository $fileRepository): ?array
    {
        try {
            $content = $fileRepository->setServer($server)->getContent('server.properties');
        } catch (Exception $exception) {
            return null;
        }

        if (empty($content)) {
            return null;
        }

        $properties = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$propertyKey, $value] = array_map('trim', explode('=', $line, 2));
            $properties[$propertyKey] = $value;
        }

        return $properties;
    }

    protected function performScan(Server $server, DaemonFileRepository $fileRepository, ?ProjectType $type = null): InstalledScanResult
    {
        set_time_limit(120);

        $type ??= ProjectType::fromServer($server);

        if (!$type) {
            return InstalledScanResult::failed('unsupported_project_type');
        }

        $this->assertArchiveMetadataType($type);

        try {
            $folder = $this->getProjectFolder($server, $fileRepository, $type);
            $directoryContents = $fileRepository->setServer($server)->getDirectory($folder);
        } catch (Exception $exception) {
            report($exception);

            return InstalledScanResult::failed('wings_directory_unavailable');
        }

        if (!is_array($directoryContents) || isset($directoryContents['error'])) {
            return InstalledScanResult::failed('wings_directory_invalid');
        }

        $extension = $type->getFileExtension();
        $diskFiles = [];

        foreach ($directoryContents as $item) {
            if (!is_array($item)
                || !is_string($item['name'] ?? null)
                || !str($item['name'])->lower()->endsWith($extension)
                || ($item['directory'] ?? false) === true
                || (array_key_exists('file', $item) && $item['file'] !== true)) {
                continue;
            }

            $filename = $item['name'];
            $key = strtolower($filename);
            $signature = $this->normalizeFileSignature($item);

            // A case-insensitive filename collision is not safe to identify by
            // the persisted index. Keep the first display name, but force a
            // fresh hash by discarding its reusable signature.
            if (isset($diskFiles[$key])) {
                $diskFiles[$key]['file_signature'] = null;

                continue;
            }

            $diskFiles[$key] = [
                'filename' => $filename,
                'file_signature' => $signature,
            ];
        }

        $metadataResult = $this->metadataRepository->read($server, $fileRepository, $folder);

        if (in_array($metadataResult->status, [InstalledMetadataReadStatus::Invalid, InstalledMetadataReadStatus::Unavailable], true)) {
            return InstalledScanResult::failed('metadata_unavailable');
        }

        $originalDocument = $metadataResult->document;
        $installedByFilename = [];
        foreach ($originalDocument->installedMods() as $entry) {
            $filename = $entry['filename'] ?? null;

            if (is_string($filename) && $filename !== '') {
                $installedByFilename[strtolower($filename)] = $entry;
            }
        }

        $unresolvedByFilename = [];
        foreach ($originalDocument->unresolvedFiles() as $entry) {
            $filename = $entry['filename'] ?? null;

            if (is_string($filename) && $filename !== '') {
                $unresolvedByFilename[strtolower($filename)] = $entry;
            }
        }

        $scannedInstalled = [];
        $filesToResolve = [];
        $hashesByFilename = [];
        $reusedHashCount = 0;

        foreach ($diskFiles as $key => $diskFile) {
            $existingInstalled = $installedByFilename[$key] ?? null;
            $indexed = $existingInstalled ?? ($unresolvedByFilename[$key] ?? null);
            $reusableHashes = is_array($indexed)
                ? $this->reusableHashes($indexed, $diskFile['file_signature'])
                : null;

            if ($existingInstalled !== null && $reusableHashes !== null) {
                $existingInstalled['file_signature'] = $diskFile['file_signature'];
                $existingInstalled['hashes'] = $reusableHashes;
                $scannedInstalled[] = $existingInstalled;

                continue;
            }

            $filename = $diskFile['filename'];
            $filesToResolve[$filename] = $diskFile;

            if ($reusableHashes !== null) {
                $hashesByFilename[$filename] = $reusableHashes;
                $reusedHashCount++;
            }
        }

        $hashResolutionStartedAt = $this->debugTimingEnabled ? microtime(true) : 0.0;
        $hashComputationStartedAt = $hashResolutionStartedAt;
        $hashFailures = [];

        foreach ($filesToResolve as $filename => $diskFile) {
            if (isset($hashesByFilename[$filename])) {
                continue;
            }

            try {
                $hashesByFilename[$filename] = $this->computeDaemonFileHashes(
                    $fileRepository,
                    $server,
                    "{$folder}/{$filename}",
                );
            } catch (Exception $exception) {
                report($exception);
                $hashFailures[] = $filename;
            }
        }

        if ($this->debugTimingEnabled) {
            $this->logModManagerTiming('hash_computation', $hashComputationStartedAt, [
                'source' => 'shared',
                'algorithms' => ['murmur2', 'sha512', 'sha256'],
                'files_count' => count($filesToResolve),
                'hashed_files_count' => count($hashesByFilename) - $reusedHashCount,
                'reused_hashes_count' => $reusedHashCount,
                'wings_get_count' => $this->hashScanWingsGetCount,
                'failed_files_count' => count($hashFailures),
            ]);
        }

        $remainingFilenames = array_keys($filesToResolve);
        $matchedEntries = [];
        $lookupFailures = [];

        foreach ($this->getHashLookupSourcesInPriorityOrder($server, $type) as $hashSource) {
            if ($remainingFilenames === []) {
                break;
            }

            if (!$hashSource->isConfigured() || !$hashSource->supportsHashLookup()) {
                continue;
            }

            $algorithm = $hashSource->getHashAlgorithm();

            if ($algorithm === null) {
                continue;
            }

            $hashMap = [];
            foreach ($remainingFilenames as $filename) {
                $hash = $hashesByFilename[$filename][$algorithm] ?? null;

                if (is_string($hash) && $hash !== '') {
                    $hashMap[$filename] = $hash;
                }
            }

            if ($hashMap === []) {
                continue;
            }

            $hashLookupStartedAt = $this->debugTimingEnabled ? microtime(true) : 0.0;
            try {
                $versionsByHash = $hashSource instanceof SourceFetchAuthoritativeInterface
                    ? $hashSource->findVersionsByHashAuthoritatively($hashMap)
                    : $hashSource->findVersionsByHash($hashMap);
            } catch (Exception $exception) {
                report($exception);
                $lookupFailures[] = $hashSource->getKey()->value;
                $versionsByHash = [];
            }

            if ($this->debugTimingEnabled) {
                $this->logModManagerTiming('hash_lookup', $hashLookupStartedAt, [
                    'source' => $hashSource->getKey()->value,
                    'hashes_count' => count($hashMap),
                    'matches_count' => count($versionsByHash),
                ]);
            }

            if ($versionsByHash === []) {
                continue;
            }

            $hashToFilenames = [];
            foreach ($hashMap as $filename => $hash) {
                $hashToFilenames[$hash][] = $filename;
            }

            $matchedVersions = [];
            $projectIds = [];
            foreach ($versionsByHash as $hash => $versionData) {
                if (!isset($hashToFilenames[$hash]) || !is_array($versionData) || !isset($versionData['project_id'])) {
                    continue;
                }

                foreach ($hashToFilenames[$hash] as $filename) {
                    $matchedVersions[$filename] = $versionData;
                }

                $projectIds[] = (string) $versionData['project_id'];
            }

            if ($matchedVersions === []) {
                continue;
            }

            $projectLookupStartedAt = $this->debugTimingEnabled ? microtime(true) : 0.0;
            try {
                $uniqueProjectIds = array_values(array_unique($projectIds));
                $projectsMap = $hashSource instanceof SourceFetchAuthoritativeInterface
                    ? $hashSource->getProjectsByIdsAuthoritatively($uniqueProjectIds)
                    : $hashSource->getProjectsByIds($uniqueProjectIds);
            } catch (Exception $exception) {
                report($exception);
                $projectsMap = [];
            }

            if ($this->debugTimingEnabled) {
                $this->logModManagerTiming('hash_project_lookup', $projectLookupStartedAt, [
                    'source' => $hashSource->getKey()->value,
                    'project_ids_count' => count(array_unique($projectIds)),
                    'projects_count' => count($projectsMap),
                ]);
            }

            foreach ($matchedVersions as $filename => $versionData) {
                if (!isset($versionData['project_id'], $versionData['id'], $versionData['version_number'])) {
                    continue;
                }

                $projectId = (string) $versionData['project_id'];
                $project = $projectsMap[$projectId] ?? null;
                $entry = [
                    'source' => $hashSource->getKey()->value,
                    'project_id' => $projectId,
                    'project_slug' => $project['slug'] ?? $projectId,
                    'project_title' => $project['title'] ?? $projectId,
                    'version_id' => (string) $versionData['id'],
                    'version_number' => (string) $versionData['version_number'],
                    'filename' => $filename,
                    'installed_at' => now()->toIso8601String(),
                    'file_signature' => $filesToResolve[$filename]['file_signature'],
                    'hashes' => $hashesByFilename[$filename],
                ];
                $author = $this->resolveMatchAuthor($hashSource, $project, $versionData);

                if ($author !== null) {
                    $entry['author'] = $author;
                }

                $matchedEntries[$filename] = $entry;
            }

            $remainingFilenames = array_values(array_diff($remainingFilenames, array_keys($matchedEntries)));
        }

        $scannedInstalled = $this->upsertInstalledEntries($scannedInstalled, array_values($matchedEntries));

        $resolvedUnmatched = $this->resolveUnmatchedScanFiles(
            $remainingFilenames,
            $hashFailures,
            $lookupFailures,
            $filesToResolve,
            $hashesByFilename,
            $installedByFilename,
            $unresolvedByFilename,
        );
        $scannedInstalled = array_merge($scannedInstalled, $resolvedUnmatched['installed']);
        $scannedUnresolved = $resolvedUnmatched['unresolved'];

        $metadataPersistenceStartedAt = $this->debugTimingEnabled ? microtime(true) : 0.0;
        $saved = $this->metadataRepository->mutate(
            $server,
            $fileRepository,
            $folder,
            fn (InstalledMetadataDocument $latest): InstalledMetadataDocument => $this->rebaseScanDocument(
                $originalDocument,
                $latest,
                $scannedInstalled,
                $scannedUnresolved,
            ),
        );

        if ($this->debugTimingEnabled) {
            $this->logModManagerTiming('hash_metadata_persistence', $metadataPersistenceStartedAt, [
                'source' => 'all',
                'matched_files_count' => count($matchedEntries),
                'saved_files_count' => $saved ? count($matchedEntries) : 0,
                'writes_count' => $saved ? 1 : 0,
            ]);
        }

        $failure = !$saved
            ? 'metadata_write_failed'
            : ($hashFailures !== []
                ? 'hash_computation_partial_failure'
                : ($lookupFailures !== [] ? 'hash_lookup_partial_failure' : null));

        if ($this->debugTimingEnabled) {
            $this->logModManagerTiming('hash_resolution', $hashResolutionStartedAt, [
                'unknown_files_count' => count($filesToResolve),
                'matched_files_count' => count($matchedEntries),
                'remaining_files_count' => count($remainingFilenames),
                'wings_get_count' => $this->hashScanWingsGetCount,
                'failure' => $failure,
            ]);
        }

        if ($failure !== null) {
            return InstalledScanResult::failed($failure, $remainingFilenames, count($diskFiles));
        }

        return InstalledScanResult::success($remainingFilenames, count($diskFiles));
    }

    /**
     * @param array<string, mixed> $item
     * @return array{size: int, modified_at: string}|null
     */
    protected function normalizeFileSignature(array $item): ?array
    {
        $size = $item['size'] ?? null;
        $modified = $item['modified'] ?? null;

        if (!is_numeric($size) || (!is_string($modified) && !is_numeric($modified))) {
            return null;
        }

        return [
            'size' => (int) $size,
            'modified_at' => (string) $modified,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array{size: int, modified_at: string}|null $signature
     * @return array{murmur2: string, sha512: string, sha256: string}|null
     */
    protected function reusableHashes(array $entry, ?array $signature): ?array
    {
        if ($signature === null || ($entry['file_signature'] ?? null) !== $signature || !is_array($entry['hashes'] ?? null)) {
            return null;
        }

        $hashes = [];
        foreach (['murmur2', 'sha512', 'sha256'] as $algorithm) {
            $hash = $entry['hashes'][$algorithm] ?? null;

            if (!is_string($hash) || $hash === '') {
                return null;
            }

            $hashes[$algorithm] = $hash;
        }

        return $hashes;
    }

    /**
     * Keep known installed entries when this scan could not authoritatively
     * identify a file. Hash/API transport failures are not the same as every
     * source confirming that the file is unknown.
     *
     * @param  array<int, string>  $remainingFilenames
     * @param  array<int, string>  $hashFailures
     * @param  array<int, string>  $lookupFailures
     * @param  array<string, array<string, mixed>>  $filesToResolve
     * @param  array<string, array{murmur2?: string, sha512?: string, sha256?: string}>  $hashesByFilename
     * @param  array<string, array<string, mixed>>  $installedByFilename
     * @param  array<string, array<string, mixed>>  $unresolvedByFilename
     * @return array{installed: array<int, array<string, mixed>>, unresolved: array<int, array<string, mixed>>}
     */
    protected function resolveUnmatchedScanFiles(
        array $remainingFilenames,
        array $hashFailures,
        array $lookupFailures,
        array $filesToResolve,
        array $hashesByFilename,
        array $installedByFilename,
        array $unresolvedByFilename,
    ): array {
        $installed = [];
        $unresolved = [];

        foreach ($remainingFilenames as $filename) {
            $key = strtolower($filename);
            $transient = $this->isTransientScanIdentificationFailure($filename, $hashFailures, $lookupFailures);
            $existingInstalled = $installedByFilename[$key] ?? null;

            if ($transient && is_array($existingInstalled)) {
                $existingInstalled['file_signature'] = $filesToResolve[$filename]['file_signature'];
                $installed[] = $existingInstalled;

                continue;
            }

            $existingUnresolved = $unresolvedByFilename[$key] ?? null;

            if ($transient && is_array($existingUnresolved)) {
                $unresolved[] = $existingUnresolved;

                continue;
            }

            $entry = [
                'filename' => $filename,
                'file_signature' => $filesToResolve[$filename]['file_signature'],
            ];

            if (isset($hashesByFilename[$filename])) {
                $entry['hashes'] = $hashesByFilename[$filename];
            }

            $entry['last_checked_at'] = $this->unresolvedLastCheckedAt($existingUnresolved, $entry);
            $unresolved[] = $entry;
        }

        return [
            'installed' => $installed,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * @param  array<int, string>  $hashFailures
     * @param  array<int, string>  $lookupFailures
     */
    protected function isTransientScanIdentificationFailure(string $filename, array $hashFailures, array $lookupFailures): bool
    {
        return in_array($filename, $hashFailures, true) || $lookupFailures !== [];
    }

    /**
     * Reuse last_checked_at when the unresolved file has not changed so a
     * cache-miss scan does not force a no-op Wings PUT.
     *
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $entry
     */
    protected function unresolvedLastCheckedAt(?array $existing, array $entry): string
    {
        if (is_array($existing)
            && ($existing['file_signature'] ?? null) === ($entry['file_signature'] ?? null)
            && ($existing['hashes'] ?? null) === ($entry['hashes'] ?? null)
            && is_string($existing['last_checked_at'] ?? null)
            && $existing['last_checked_at'] !== '') {
            return $existing['last_checked_at'];
        }

        return now()->toIso8601String();
    }

    /**
     * Rebase the scan onto metadata changes made while hashing. New installs,
     * updates, and removals win over the older directory snapshot.
     *
     * @param array<int, array<string, mixed>> $scannedInstalled
     * @param array<int, array<string, mixed>> $scannedUnresolved
     */
    protected function rebaseScanDocument(
        InstalledMetadataDocument $original,
        InstalledMetadataDocument $latest,
        array $scannedInstalled,
        array $scannedUnresolved,
    ): InstalledMetadataDocument {
        $originalInstalled = $this->indexInstalledEntries($original->installedMods());
        $latestInstalled = $this->indexInstalledEntries($latest->installedMods());
        $removedInstalledIdentities = [];
        $changedInstalledEntries = [];

        foreach ($originalInstalled as $identity => $entry) {
            if (!isset($latestInstalled[$identity])) {
                $removedInstalledIdentities[$identity] = true;
            } elseif ($latestInstalled[$identity] != $entry) {
                $changedInstalledEntries[] = $latestInstalled[$identity];
            }
        }

        foreach ($latestInstalled as $identity => $entry) {
            if (!isset($originalInstalled[$identity])) {
                $changedInstalledEntries[] = $entry;
            }
        }

        if ($removedInstalledIdentities !== []) {
            $scannedInstalled = array_values(array_filter(
                $scannedInstalled,
                fn (array $candidate): bool => !isset($removedInstalledIdentities[$this->installedEntryIdentity($candidate)]),
            ));
        }

        // Apply every concurrent add/update in one linear pass. Repeated
        // upsertInstalledEntry() calls rebuilt the complete scan array for
        // each changed entry, turning the rebase into O(K x N).
        $scannedInstalled = $this->upsertInstalledEntries($scannedInstalled, $changedInstalledEntries);

        $originalUnresolved = $this->indexEntriesByFilename($original->unresolvedFiles());
        $latestUnresolved = $this->indexEntriesByFilename($latest->unresolvedFiles());
        $scannedUnresolved = $this->indexEntriesByFilename($scannedUnresolved);

        foreach ($originalUnresolved as $filename => $entry) {
            if (!isset($latestUnresolved[$filename])) {
                unset($scannedUnresolved[$filename]);
            } elseif ($latestUnresolved[$filename] != $entry) {
                $scannedUnresolved[$filename] = $latestUnresolved[$filename];
            }
        }

        foreach ($latestUnresolved as $filename => $entry) {
            if (!isset($originalUnresolved[$filename])) {
                $scannedUnresolved[$filename] = $entry;
            }
        }

        return $latest
            ->withInstalledMods(array_values($scannedInstalled))
            ->withUnresolvedFiles(array_values($scannedUnresolved));
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    protected function indexInstalledEntries(array $entries): array
    {
        $indexed = [];

        foreach ($entries as $entry) {
            $indexed[$this->installedEntryIdentity($entry)] = $entry;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    protected function indexEntriesByFilename(array $entries): array
    {
        $indexed = [];

        foreach ($entries as $entry) {
            $filename = strtolower((string) ($entry['filename'] ?? ''));

            if ($filename !== '') {
                $indexed[$filename] = $entry;
            }
        }

        return $indexed;
    }

    /** @param array<string, mixed> $entry */
    protected function installedEntryIdentity(array $entry): string
    {
        return ($entry['source'] ?? ProjectSourceKey::Modrinth->value).':'.($entry['project_id'] ?? '').':'.strtolower((string) ($entry['filename'] ?? ''));
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, mixed> $entry
     * @return array<int, array<string, mixed>>
     */
    protected function upsertInstalledEntry(array $entries, array $entry): array
    {
        $identity = $this->installedEntryIdentity($entry);
        $filename = strtolower((string) ($entry['filename'] ?? ''));
        $entries = array_values(array_filter(
            $entries,
            fn (array $candidate): bool => $this->installedEntryIdentity($candidate) !== $identity
                && strtolower((string) ($candidate['filename'] ?? '')) !== $filename,
        ));
        $entries[] = $entry;

        return $entries;
    }

    /**
     * Add a scan batch while preserving upsertInstalledEntry()'s semantics:
     * every incoming entry removes a pre-existing identity/filename, and a
     * later incoming filename wins over an earlier one. Doing that in one
     * filter avoids repeatedly walking all prior scan results for every file.
     *
     * @param array<int, array<string, mixed>> $entries
     * @param array<int, array<string, mixed>> $incoming
     * @return array<int, array<string, mixed>>
     */
    protected function upsertInstalledEntries(array $entries, array $incoming): array
    {
        if ($incoming === []) {
            return $entries;
        }

        $removedIdentities = [];
        $removedFilenames = [];
        $winners = [];
        $winnerByIdentity = [];
        $winnerByFilename = [];

        foreach ($incoming as $position => $entry) {
            $identity = $this->installedEntryIdentity($entry);
            $filename = strtolower((string) ($entry['filename'] ?? ''));
            $removedIdentities[$identity] = true;
            $removedFilenames[$filename] = true;

            foreach ([$winnerByIdentity[$identity] ?? null, $winnerByFilename[$filename] ?? null] as $winnerPosition) {
                if ($winnerPosition === null || !isset($winners[$winnerPosition])) {
                    continue;
                }

                $winner = $winners[$winnerPosition];
                unset($winners[$winnerPosition]);

                if (($winnerByIdentity[$winner['identity']] ?? null) === $winnerPosition) {
                    unset($winnerByIdentity[$winner['identity']]);
                }
                if (($winnerByFilename[$winner['filename']] ?? null) === $winnerPosition) {
                    unset($winnerByFilename[$winner['filename']]);
                }
            }

            $winners[$position] = [
                'entry' => $entry,
                'identity' => $identity,
                'filename' => $filename,
            ];
            $winnerByIdentity[$identity] = $position;
            $winnerByFilename[$filename] = $position;
        }

        $entries = array_values(array_filter(
            $entries,
            fn (array $candidate): bool => !isset($removedIdentities[$this->installedEntryIdentity($candidate)])
                && !isset($removedFilenames[strtolower((string) ($candidate['filename'] ?? ''))]),
        ));

        foreach ($winners as $winner) {
            $entries[] = $winner['entry'];
        }

        return $entries;
    }

    /**
     * Sources to try, in priority order, when identifying unknown files by hash.
     * The registry applies the same per-server source enablement and project-type
     * rules used by catalog tabs, then this method removes sources without hash
     * lookup support. This avoids querying a disabled CurseForge source or the
     * Plugin-only Hangar source for Mod/Datapack scans.
     *
     * @return array<int, ProjectSourceInterface>
     */
    protected function getHashLookupSourcesInPriorityOrder(Server $server, ProjectType $type): array
    {
        return array_values(array_filter(
            $this->sourceRegistry->availableFor($server, $type),
            static fn (ProjectSourceInterface $source): bool => $source->supportsHashLookup(),
        ));
    }

    /**
     * Downloads a daemon file once and computes every hash needed by the
     * installed-source resolvers during that single streaming pass.
     *
     * @return array{murmur2: string, sha512: string, sha256: string}
     */
    protected function computeDaemonFileHashes(DaemonFileRepository $fileRepository, Server $server, string $path): array
    {
        $sha512 = hash_init('sha512');
        $sha256 = hash_init('sha256');

        $murmur2 = CurseForgeFingerprint::hashStream(
            fn () => $this->openDaemonFileStream($fileRepository, $server, $path),
            static function (string $chunk) use ($sha512, $sha256): void {
                hash_update($sha512, $chunk);
                hash_update($sha256, $chunk);
            },
        );

        return [
            'murmur2' => (string) $murmur2,
            'sha512' => hash_final($sha512),
            'sha256' => hash_final($sha256),
        ];
    }

    /** Opens a Wings response without converting its body into a string. */
    protected function openDaemonFileStream(DaemonFileRepository $fileRepository, Server $server, string $path): object
    {
        if ($this->debugTimingEnabled) {
            $this->hashScanWingsGetCount++;
        }

        $response = $fileRepository->setServer($server)->getHttpClient()->withOptions(['stream' => true])->get("/api/servers/{$server->uuid}/files/contents", ['file' => $path]);

        return $response->toPsrResponse()->getBody();
    }

    /**
     * Modrinth's raw project data doesn't reliably include an author, so
     * ModrinthSource resolves it separately via resolveAuthor(). The other
     * sources already bake author into their normalized project data.
     *
     * @param array<string, mixed>|null $project
     * @param array<string, mixed> $versionData
     */
    protected function resolveMatchAuthor(ProjectSourceInterface $hashSource, ?array $project, array $versionData): ?string
    {
        if ($hashSource instanceof ModrinthSource) {
            return $hashSource->resolveAuthor($project, $versionData);
        }

        $author = $project['author'] ?? null;

        return (is_string($author) && $author !== '') ? $author : null;
    }

    /**
     * @throws Exception
     */
    protected function resolveMetadataFolder(Server $server, DaemonFileRepository $fileRepository, ?ProjectType $type = null): string
    {
        $type ??= ProjectType::fromServer($server);

        if (!$type) {
            throw new Exception("Server {$server->id} does not support managed mods or plugins");
        }

        $this->assertArchiveMetadataType($type);

        return $this->getProjectFolder($server, $fileRepository, $type);
    }

    protected function assertArchiveMetadataType(ProjectType $type): void
    {
        if (!$type->usesArchiveMetadata()) {
            throw new InvalidArgumentException('Resource packs use dedicated URL and SHA-1 metadata, not installed archive metadata.');
        }
    }

    /**
     * Read the complete installed metadata document, including the persistent
     * file-signature and hash index used by incremental scans.
     */
    public function getInstalledMetadataReadResult(Server $server, DaemonFileRepository $fileRepository, ?ProjectType $type = null): InstalledMetadataReadResult
    {
        try {
            $folder = $this->resolveMetadataFolder($server, $fileRepository, $type);
        } catch (Exception) {
            return new InstalledMetadataReadResult(
                InstalledMetadataDocument::empty(),
                InstalledMetadataReadStatus::Unavailable,
            );
        }

        return $this->metadataRepository->read($server, $fileRepository, $folder);
    }

    public function saveModMetadata(
        Server $server,
        DaemonFileRepository $fileRepository,
        string $projectId,
        string $projectSlug,
        string $projectTitle,
        string $versionId,
        string $versionNumber,
        string $filename,
        ?string $author = null,
        ?ProjectType $type = null,
        ProjectSourceKey $source = ProjectSourceKey::Modrinth
    ): bool {
        try {
            $folder = $this->resolveMetadataFolder($server, $fileRepository, $type);
        } catch (Exception $exception) {
            report($exception);

            return false;
        }

        $entry = [
            'source' => $source->value,
            'project_id' => $projectId,
            'project_slug' => $projectSlug,
            'project_title' => $projectTitle,
            'version_id' => $versionId,
            'version_number' => $versionNumber,
            'filename' => $filename,
            'installed_at' => now()->toIso8601String(),
        ];

        if ($author !== null) {
            $entry['author'] = $author;
        }

        return $this->metadataRepository->mutate(
            $server,
            $fileRepository,
            $folder,
            fn (InstalledMetadataDocument $document): InstalledMetadataDocument => $document->withUpsertedInstalledMod($entry),
        );
    }

    public function saveInstalledMetadataDocument(Server $server, DaemonFileRepository $fileRepository, InstalledMetadataDocument $document, ?ProjectType $type = null): bool
    {
        try {
            $folder = $this->resolveMetadataFolder($server, $fileRepository, $type);
        } catch (Exception $exception) {
            report($exception);

            return false;
        }

        return $this->metadataRepository->replace($server, $fileRepository, $folder, $document);
    }

    public function removeModMetadata(Server $server, DaemonFileRepository $fileRepository, string $projectId, ?ProjectType $type = null, ProjectSourceKey $source = ProjectSourceKey::Modrinth): bool
    {
        try {
            $folder = $this->resolveMetadataFolder($server, $fileRepository, $type);
        } catch (Exception $exception) {
            report($exception);

            return false;
        }

        return $this->metadataRepository->mutate(
            $server,
            $fileRepository,
            $folder,
            function (InstalledMetadataDocument $document) use ($projectId, $source): InstalledMetadataDocument {
                $installedMods = array_values(array_filter(
                    $document->installedMods(),
                    fn (array $mod): bool => !(($mod['source'] ?? ProjectSourceKey::Modrinth->value) === $source->value && ($mod['project_id'] ?? null) === $projectId),
                ));

                return $document->withInstalledMods($installedMods);
            },
        );
    }

}
