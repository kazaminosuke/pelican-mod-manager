<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use Kazaminosuke\ModManager\Contracts\BatchLatestVersionSourceInterface;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\LatestVersionLookupRequest;
use Kazaminosuke\ModManager\Support\LatestVersionLookupResult;
use Kazaminosuke\ModManager\Support\ProjectSourceRegistry;
use Throwable;

class VersionLookupCoordinator
{
    public function __construct(
        protected ProjectSourceRegistry $registry,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $installedMods
     */
    public function lookupInstalled(
        array $installedMods,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        return $this->lookup($this->requestsFromInstalledMods($installedMods), $server, $type);
    }

    /**
     * Non-blocking counterpart to lookupInstalled(): never performs an
     * inline fetch. See BatchLatestVersionSourceInterface::peekLatestVersions().
     *
     * @param array<int, array<string, mixed>> $installedMods
     */
    public function peekInstalled(
        array $installedMods,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        return $this->peek($this->requestsFromInstalledMods($installedMods), $server, $type);
    }

    /**
     * @param array<int, array<string, mixed>> $installedMods
     * @return array<int, LatestVersionLookupRequest>
     */
    protected function requestsFromInstalledMods(array $installedMods): array
    {
        $requests = [];

        foreach ($installedMods as $installedMod) {
            $request = LatestVersionLookupRequest::fromInstalledMod($installedMod);

            if ($request !== null) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function lookup(
        array $requests,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        return $this->execute($requests, $server, $type, blocking: true);
    }

    /**
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    private function execute(
        array $requests,
        Server $server,
        ProjectType $type,
        bool $blocking,
    ): LatestVersionLookupResult {
        $bySource = [];

        foreach ($requests as $request) {
            if ($request instanceof LatestVersionLookupRequest) {
                $bySource[$request->source][] = $request;
            }
        }

        $result = LatestVersionLookupResult::empty();

        foreach ($bySource as $sourceKey => $sourceRequests) {
            $source = $this->registry->getByValue($sourceKey);

            if (!$source instanceof BatchLatestVersionSourceInterface || !$source->isConfigured()) {
                $result = $result->merge(LatestVersionLookupResult::failed(
                    $sourceRequests,
                    "Latest-version lookup is unavailable for source [$sourceKey]",
                ));

                continue;
            }

            try {
                $latest = $blocking
                    ? $source->lookupLatestVersions($sourceRequests, $server, $type)
                    : $source->peekLatestVersions($sourceRequests, $server, $type);
                $result = $result->merge($latest);
            } catch (Throwable $exception) {
                report($exception);
                $result = $result->merge(LatestVersionLookupResult::failed(
                    $sourceRequests,
                    "Latest-version lookup failed for source [$sourceKey]",
                ));
            }
        }

        return $result;
    }

    /**
     * Non-blocking counterpart to lookup(): never performs an inline fetch.
     * A source whose batch is a cold cache miss contributes its request
     * keys to the merged result's pendingKeys() instead of blocking.
     *
     * @param array<int, LatestVersionLookupRequest> $requests
     */
    public function peek(
        array $requests,
        Server $server,
        ProjectType $type,
    ): LatestVersionLookupResult {
        return $this->execute($requests, $server, $type, blocking: false);
    }
}
