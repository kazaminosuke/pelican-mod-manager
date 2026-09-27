<?php

namespace Kazaminosuke\ModManager\Contracts;

use App\Models\Server;
use Kazaminosuke\ModManager\Enums\ProjectType;

/**
 * Source lookups used by authoritative background operations such as an
 * installed-file scan.
 *
 * Unlike render-path SWR reads, a cold-cache upstream failure must propagate
 * so the caller does not persist "no match" as if it were a valid response.
 * Fresh or stale cached data may still be returned.
 */
interface SourceFetchAuthoritativeInterface
{
    /**
     * @param array<int, string> $projectIds
     * @return array<string, mixed>
     */
    public function getProjectsByIdsAuthoritatively(array $projectIds): array;

    /**
     * @param array<string, string> $hashesByFilename
     * @return array<string, mixed>
     */
    public function findVersionsByHashAuthoritatively(array $hashesByFilename): array;

    /**
     * The compatible versions an install or update is about to choose from.
     * Prefers fresh data and throws when the source cannot be reached and
     * nothing is cached, instead of returning an empty list.
     *
     * @return array<int, mixed>
     */
    public function getVersionsAuthoritatively(string $projectId, Server $server, ProjectType $type): array;
}
