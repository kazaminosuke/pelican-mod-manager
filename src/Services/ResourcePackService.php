<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use JsonException;
use Kazaminosuke\ModManager\Enums\ProjectSourceKey;
use Kazaminosuke\ModManager\Support\ServerProperties;
use Throwable;

/**
 * Persists the single active resource pack outside the mod/plugin metadata
 * document and keeps server.properties as the Minecraft-facing source of
 * truth for its direct URL and SHA-1.
 */
final class ResourcePackService
{
    public const METADATA_FILENAME = '.pelican-mod-manager-resource-pack.json';

    private const SCHEMA_VERSION = 1;

    /**
     * @return array<string, mixed>|null
     *
     * @throws Exception
     */
    public function getInstalled(Server $server, DaemonFileRepository $fileRepository): ?array
    {
        try {
            $content = $fileRepository->setServer($server)->getContent(self::METADATA_FILENAME);
        } catch (FileNotFoundException) {
            return null;
        }

        try {
            $metadata = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new Exception('Invalid resource pack metadata.', previous: $exception);
        }

        if (!is_array($metadata) || ($metadata['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new Exception('Invalid resource pack metadata.');
        }

        return $this->validatedMetadata($metadata);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $versionData
     * @param array<string, mixed> $primaryFile
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function installOrUpdate(
        Server $server,
        DaemonFileRepository $fileRepository,
        array $record,
        array $versionData,
        array $primaryFile,
    ): array {
        $metadata = $this->metadataFromVersion($record, $versionData, $primaryFile);
        $repository = $fileRepository->setServer($server);
        $oldProperties = $repository->getContent('server.properties');
        $newProperties = ServerProperties::withResourcePack(
            $oldProperties,
            $metadata['url'],
            $metadata['sha1'],
        );

        $this->assertSuccessful($repository->putContent('server.properties', $newProperties), 'server.properties');

        try {
            $content = json_encode(
                $metadata,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $this->assertSuccessful(
                $repository->putContent(self::METADATA_FILENAME, $content),
                self::METADATA_FILENAME,
            );
        } catch (Throwable $exception) {
            try {
                $this->assertSuccessful($repository->putContent('server.properties', $oldProperties), 'server.properties rollback');
            } catch (Throwable $rollbackException) {
                report($rollbackException);
            }

            throw $exception;
        }

        return $metadata;
    }

    /**
     * @throws Exception
     */
    public function uninstall(Server $server, DaemonFileRepository $fileRepository): void
    {
        $repository = $fileRepository->setServer($server);
        $oldProperties = $repository->getContent('server.properties');
        $newProperties = ServerProperties::withResourcePack($oldProperties, null, null);

        $this->assertSuccessful($repository->putContent('server.properties', $newProperties), 'server.properties');

        try {
            $this->assertSuccessful(
                $repository->deleteFiles('/', [self::METADATA_FILENAME]),
                self::METADATA_FILENAME,
            );
        } catch (Throwable $exception) {
            try {
                $this->assertSuccessful($repository->putContent('server.properties', $oldProperties), 'server.properties rollback');
            } catch (Throwable $rollbackException) {
                report($rollbackException);
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $versionData
     * @param array<string, mixed> $primaryFile
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function metadataFromVersion(array $record, array $versionData, array $primaryFile): array
    {
        $source = $record['source'] ?? null;
        if (!is_string($source) || !in_array($source, [ProjectSourceKey::Modrinth->value, ProjectSourceKey::CurseForge->value], true)) {
            throw new Exception('Resource packs are supported only from Modrinth or CurseForge.');
        }

        $url = $primaryFile['url'] ?? null;
        $filename = $primaryFile['filename'] ?? null;
        $sha1 = $primaryFile['hashes']['sha1'] ?? null;

        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new Exception('Resource pack provider returned an invalid direct URL.');
        }

        if (!is_string($sha1) || preg_match('/\A[0-9a-fA-F]{40}\z/', $sha1) !== 1) {
            throw new Exception('Resource pack provider did not return a valid SHA-1 hash.');
        }

        $hashes = [];
        foreach ((array) ($primaryFile['hashes'] ?? []) as $algorithm => $hash) {
            $algorithm = is_string($algorithm) ? strtolower($algorithm) : '';
            $expectedLength = match ($algorithm) {
                'sha1' => 40,
                'sha512' => 128,
                default => null,
            };

            if ($expectedLength !== null && is_string($hash)
                && preg_match('/\A[0-9a-fA-F]{'.$expectedLength.'}\z/', $hash) === 1) {
                $hashes[$algorithm] = strtolower($hash);
            }
        }
        $hashes['sha1'] = strtolower($sha1);

        if (!is_string($filename) || $filename === ''
            || $filename === '.' || str_contains($filename, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1
            || str_contains($filename, '..') || str_contains($filename, '/')
            || str_contains($filename, '\\') || !str_ends_with(strtolower($filename), '.zip')) {
            throw new Exception('Resource pack provider returned an invalid filename.');
        }

        $projectId = $record['project_id'] ?? null;
        $projectSlug = $record['slug'] ?? $record['project_slug'] ?? null;
        $projectTitle = $record['title'] ?? $record['project_title'] ?? null;
        $versionId = $versionData['id'] ?? $versionData['version_id'] ?? null;
        $versionNumber = $versionData['version_number'] ?? null;

        foreach ([$projectId, $projectSlug, $projectTitle, $versionId, $versionNumber] as $value) {
            if (!is_string($value) || $value === '') {
                throw new Exception('Resource pack metadata is incomplete.');
            }
        }

        $metadata = [
            'schema_version' => self::SCHEMA_VERSION,
            'project_type' => 'resourcepack',
            'source' => $source,
            'project_id' => $projectId,
            'project_slug' => $projectSlug,
            'project_title' => $projectTitle,
            'version_id' => $versionId,
            'version_number' => $versionNumber,
            'filename' => $filename,
            'url' => $url,
            'sha1' => strtolower($sha1),
            'hashes' => $hashes,
            'installed_at' => now()->toIso8601String(),
        ];

        if (is_string($record['author'] ?? null) && $record['author'] !== '') {
            $metadata['author'] = $record['author'];
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function validatedMetadata(array $metadata): array
    {
        if (($metadata['project_type'] ?? 'resourcepack') !== 'resourcepack') {
            throw new Exception('Invalid resource pack metadata.');
        }

        $required = [
            'source', 'project_id', 'project_slug', 'project_title',
            'version_id', 'version_number', 'filename', 'url', 'sha1', 'installed_at',
        ];
        foreach ($required as $key) {
            if (!is_string($metadata[$key] ?? null) || $metadata[$key] === '') {
                throw new Exception('Invalid resource pack metadata.');
            }
        }

        if (!in_array($metadata['source'], [ProjectSourceKey::Modrinth->value, ProjectSourceKey::CurseForge->value], true)
            || preg_match('/\A[0-9a-fA-F]{40}\z/', $metadata['sha1']) !== 1
            || !filter_var($metadata['url'], FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($metadata['url'], PHP_URL_SCHEME)), ['http', 'https'], true)
            || preg_match('/[\x00-\x1F\x7F]/', $metadata['url']) === 1
            || !str_ends_with(strtolower($metadata['filename']), '.zip')
            || preg_match('/[\x00-\x1F\x7F]/', $metadata['filename']) === 1
            || str_contains($metadata['filename'], '..')
            || str_contains($metadata['filename'], '/')
            || str_contains($metadata['filename'], '\\')) {
            throw new Exception('Invalid resource pack metadata.');
        }

        if (isset($metadata['author']) && !is_string($metadata['author'])) {
            unset($metadata['author']);
        }

        $hashes = $metadata['hashes'] ?? [];
        if (!is_array($hashes)) {
            throw new Exception('Invalid resource pack metadata.');
        }

        foreach ($hashes as $algorithm => $hash) {
            $expectedLength = match ($algorithm) {
                'sha1' => 40,
                'sha512' => 128,
                default => null,
            };

            if ($expectedLength === null || !is_string($hash)
                || preg_match('/\A[0-9a-fA-F]{'.$expectedLength.'}\z/', $hash) !== 1) {
                throw new Exception('Invalid resource pack metadata.');
            }
        }

        $hashes['sha1'] = strtolower($metadata['sha1']);
        if (isset($hashes['sha512'])) {
            $hashes['sha512'] = strtolower($hashes['sha512']);
        }
        $metadata['hashes'] = $hashes;

        $metadata['sha1'] = strtolower($metadata['sha1']);

        return $metadata;
    }

    private function assertSuccessful(mixed $response, string $path): void
    {
        if (!is_object($response) || !method_exists($response, 'failed') || $response->failed()) {
            throw new Exception("Failed to write resource pack file: {$path}");
        }
    }
}
