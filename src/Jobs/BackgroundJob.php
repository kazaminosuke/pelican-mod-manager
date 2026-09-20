<?php

namespace Kazaminosuke\ModManager\Jobs;

use Illuminate\Container\Container;
use InvalidArgumentException;
use Kazaminosuke\ModManager\Support\CacheProfile;
use Kazaminosuke\ModManager\Support\SourceFetchSpec;
use Throwable;

/**
 * Runs one background operation inside a short-lived artisan process.
 *
 * Payloads are JSON (see PluginBackgroundRunner), never PHP-serialized
 * Plugin class instances. Laravel's queue worker therefore never has to
 * unserialize Kazaminosuke\ModManager\Jobs\* and cannot keep a stale copy
 * of those classes in memory across Plugin updates.
 */
final class BackgroundJob
{
    public const SCAN = 'scan';

    public const BULK_UPDATE = 'bulk_update';

    public const RESET_METADATA = 'reset_metadata';

    public const WARM_SEARCH = 'warm_search';

    public const WARM_PROJECT_METADATA = 'warm_project_metadata';

    public const REVALIDATE = 'revalidate';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function execute(string $type, array $payload): void
    {
        unset($payload['_unique_key']);

        match ($type) {
            self::SCAN => self::executeScan($payload),
            self::BULK_UPDATE => self::executeBulkUpdate($payload),
            self::RESET_METADATA => self::executeResetMetadata($payload),
            self::WARM_SEARCH => self::executeWarmSearch($payload),
            self::WARM_PROJECT_METADATA => self::executeWarmProjectMetadata($payload),
            self::REVALIDATE => self::executeRevalidate($payload),
            default => throw new InvalidArgumentException("Unknown Mod Manager background job [{$type}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function executeScan(array $payload): void
    {
        $job = new ScanInstalledProjects(
            serverId: self::int($payload, 'server_id'),
            projectType: self::string($payload, 'project_type'),
            leaseToken: self::string($payload, 'lease_token'),
            force: (bool) ($payload['force'] ?? false),
            actorUserId: isset($payload['actor_user_id']) ? (int) $payload['actor_user_id'] : null,
        );

        try {
            for ($attempt = 1; $attempt <= $job->tries; $attempt++) {
                $job->setAttemptNumber($attempt);
                Container::getInstance()->call([$job, 'handle']);

                if (!$job->retryRequested()) {
                    return;
                }

                if ($attempt >= $job->tries) {
                    return;
                }

                sleep($job->retryDelaySeconds());
            }
        } catch (Throwable $exception) {
            $job->failed($exception);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function executeBulkUpdate(array $payload): void
    {
        $job = new BulkUpdateInstalledProjects(
            serverId: self::int($payload, 'server_id'),
            projectType: self::string($payload, 'project_type'),
            leaseToken: self::string($payload, 'lease_token'),
        );

        try {
            Container::getInstance()->call([$job, 'handle']);
        } catch (Throwable $exception) {
            $job->failed($exception);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function executeResetMetadata(array $payload): void
    {
        $projectTypes = $payload['project_types'] ?? [];
        $leaseTokens = $payload['lease_tokens'] ?? [];

        if (!is_array($projectTypes) || !is_array($leaseTokens)) {
            throw new InvalidArgumentException('Reset metadata payload is invalid.');
        }

        $job = new ResetInstalledMetadata(
            serverId: self::int($payload, 'server_id'),
            projectTypes: array_values(array_map(strval(...), $projectTypes)),
            leaseTokens: array_map(strval(...), $leaseTokens),
            actorUserId: self::int($payload, 'actor_user_id'),
        );

        try {
            Container::getInstance()->call([$job, 'handle']);
        } catch (Throwable $exception) {
            $job->failed($exception);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function executeWarmSearch(array $payload): void
    {
        $job = new WarmCatalogSearch(
            serverId: self::int($payload, 'server_id'),
            sourceKey: self::string($payload, 'source_key'),
            projectType: self::string($payload, 'project_type'),
            page: self::int($payload, 'page'),
            loader: self::string($payload, 'loader'),
            mcVersion: self::string($payload, 'mc_version'),
            sort: isset($payload['sort']) ? (string) $payload['sort'] : 'downloads',
            usesCompatibilityOverride: (bool) ($payload['uses_compatibility_override'] ?? false),
        );

        Container::getInstance()->call([$job, 'handle']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function executeWarmProjectMetadata(array $payload): void
    {
        $projectIds = $payload['project_ids'] ?? [];

        if (!is_array($projectIds)) {
            throw new InvalidArgumentException('Warm project metadata payload is invalid.');
        }

        $job = new WarmProjectMetadata(
            self::string($payload, 'source_key'),
            array_values(array_map(strval(...), $projectIds)),
        );

        Container::getInstance()->call([$job, 'handle']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function executeRevalidate(array $payload): void
    {
        $arguments = $payload['arguments'] ?? [];

        if (!is_array($arguments)) {
            throw new InvalidArgumentException('Revalidate payload is invalid.');
        }

        $profile = CacheProfile::tryFrom(self::string($payload, 'profile'));

        if ($profile === null) {
            throw new InvalidArgumentException('Revalidate payload has an unknown cache profile.');
        }

        $job = new RevalidateSourceCache(
            new SourceFetchSpec(
                self::string($payload, 'source_key'),
                self::string($payload, 'operation'),
                $arguments,
            ),
            $profile,
        );

        Container::getInstance()->call([$job, 'handle']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("Background job payload missing [{$key}].");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function int(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (!is_int($value) && !is_numeric($value)) {
            throw new InvalidArgumentException("Background job payload missing [{$key}].");
        }

        return (int) $value;
    }
}
