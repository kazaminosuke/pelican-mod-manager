<?php

namespace Kazaminosuke\ModManager\Sources;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Support\Modpacks\FtbReleaseManifest;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDescriptor;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackLoader;
use Kazaminosuke\ModManager\Support\UpstreamHttp;
use Throwable;

/**
 * Public FTB catalog and fixed-release manifests used by the managed installer.
 */
final class FtbModpackSource
{
    private const SEARCH = 'https://api.feed-the-beast.com/v1/modpacks/public/modpack/search/8';

    private const PACK = 'https://api.feed-the-beast.com/v1/modpacks/public/modpack/';

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @return list<ModpackDescriptor>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 4) {
            return [];
        }

        $response = UpstreamHttp::json()
            ->timeout(8)
            ->connectTimeout(3)
            ->get(self::SEARCH, ['term' => $query])
            ->throw()
            ->json();

        $ids = [];
        foreach (array_slice(is_array($response['packs'] ?? null) ? $response['packs'] : [], 0, 6) as $id) {
            if (self::isId($id)) {
                $ids[(string) $id] = (string) $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $packs = $pending = [];
        foreach ($ids as $id) {
            $pack = Cache::get(self::summaryKey($id));
            if (is_array($pack)) {
                $packs[$id] = $pack;
            } else {
                $pending[] = $id;
            }
        }
        if ($pending !== []) {
            $responses = Http::pool(static fn (Pool $pool): array => array_map(
                static fn (string $id) => $pool->as($id)->acceptJson()->timeout(8)->connectTimeout(3)->get(self::PACK.$id),
                $pending,
            ));
            foreach ($pending as $id) {
                $response = $responses[$id] ?? null;
                if ($response instanceof Response && $response->successful()) {
                    $pack = $this->publicPack($response->json(), $id);
                    if ($pack !== []) {
                        $packs[$id] = $pack;
                        Cache::put(self::summaryKey($id), $pack, 60);
                    }
                }
            }
        }

        $descriptors = [];
        foreach ($ids as $id) {
            $pack = $packs[$id] ?? [];
            $version = $this->publicVersions($pack)[0] ?? null;
            if ($version !== null) {
                $descriptors[] = $this->descriptor($pack, $version);
            }
        }

        return $descriptors;
    }

    /**
     * @return list<array{id: string, label: string, version_number: string, version_type: ?string, date_published: ?string, minecraft: ?string, loader: ?string}>
     */
    public function versions(string $packId): array
    {
        $pack = $this->fetchPack($packId);
        $versions = [];
        foreach ($this->publicVersions($pack) as $version) {
            [$loader, , $minecraft] = $this->targets($version);
            $timestamp = $version['released'] ?? $version['updated'] ?? null;
            $published = is_numeric($timestamp) && (int) $timestamp > 0 ? gmdate('c', (int) $timestamp) : null;
            $type = is_string($version['type'] ?? null) ? strtolower(trim($version['type'])) : null;

            $versions[] = [
                'id' => (string) $version['id'],
                'label' => (string) $version['name'],
                'version_number' => (string) $version['name'],
                'version_type' => $type !== '' ? $type : null,
                'date_published' => $published,
                'minecraft' => $minecraft,
                'loader' => $loader,
            ];
        }

        return $versions;
    }

    public function describe(string $packId, ?string $versionId = null): ?ModpackDescriptor
    {
        $pack = $this->fetchPack($packId);
        if ($pack === []) {
            return null;
        }

        $versions = $this->publicVersions($pack);
        $selected = null;
        if ($versionId !== null && $versionId !== '') {
            foreach ($versions as $version) {
                if ((string) $version['id'] === $versionId) {
                    $selected = $version;
                    break;
                }
            }
            if ($selected === null) {
                return null;
            }
        } else {
            $selected = $versions[0] ?? null;
        }

        if ($selected === null) {
            return null;
        }

        return $this->descriptor($pack, $selected, $versionId !== null && $versionId !== '');
    }

    public static function releaseUrl(string $packId, string $versionId): ?string
    {
        return self::isId($packId) && self::isId($versionId) ? self::PACK.$packId.'/'.$versionId : null;
    }

    private static function isId(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[1-9]\d*$/D', (string) $value) === 1;
    }

    private static function summaryKey(string $id): string
    {
        return 'mod-manager:ftb-summary:'.$id;
    }

    /** @return array<string, mixed> */
    private function fetchPack(string $packId): array
    {
        if (!self::isId($packId)) {
            return [];
        }

        $cached = Cache::get(self::summaryKey($packId));
        if (is_array($cached)) {
            return $cached;
        }

        $response = UpstreamHttp::json()->timeout(8)->connectTimeout(3)->get(self::PACK.$packId)->throw();
        $pack = $this->publicPack($response->json(), $packId);
        if ($pack !== []) {
            Cache::put(self::summaryKey($packId), $pack, 60);
        }

        return $pack;
    }

    /** @return array<string, mixed> */
    private function publicPack(mixed $pack, string $id): array
    {
        return is_array($pack) && self::isId($pack['id'] ?? null) && (string) $pack['id'] === $id
            && ($pack['private'] ?? false) === false ? $pack : [];
    }

    /**
     * @param  array<string, mixed>  $pack
     * @return list<array<string, mixed>>
     */
    private function publicVersions(array $pack): array
    {
        $versions = [];
        foreach (is_array($pack['versions'] ?? null) ? $pack['versions'] : [] as $version) {
            if (!is_array($version) || ($version['private'] ?? false) !== false || !self::isId($version['id'] ?? null)) {
                continue;
            }

            $versions[] = $version;
        }

        usort($versions, static function (array $left, array $right): int {
            return [(int) ($right['released'] ?? $right['updated'] ?? 0), (int) $right['id']]
                <=> [(int) ($left['released'] ?? $left['updated'] ?? 0), (int) $left['id']];
        });

        return $versions;
    }

    /**
     * @param  array<string, mixed>  $pack
     * @param  array<string, mixed>  $version
     */
    private function descriptor(array $pack, array $version, bool $inspect = false): ModpackDescriptor
    {
        [$loader, $loaderVersion, $minecraft] = $this->targets($version);
        $id = (string) $pack['id'];
        $versionId = (string) $version['id'];
        $url = self::releaseUrl($id, $versionId);
        $manifest = null;
        $unsupported = null;
        if ($inspect && $url !== null) {
            try {
                $response = UpstreamHttp::json()->timeout(12)->connectTimeout(3)
                    ->withOptions(['allow_redirects' => ['protocols' => ['https']]])
                    ->get($url)->throw();
                $manifest = FtbReleaseManifest::read($response->body(), $id, $versionId);
            } catch (ModpackException $exception) {
                $unsupported = $exception->getMessage();
            } catch (Throwable $exception) {
                report($exception);
                $unsupported = trans('pelican-mod-manager::strings.modpacks.ftb_manifest_unavailable');
            }
        }

        $synopsis = trim((string) ($pack['synopsis'] ?? ''));
        $downloads = isset($pack['plays']) && is_numeric($pack['plays'])
            ? (int) $pack['plays']
            : (isset($pack['installs']) && is_numeric($pack['installs']) ? (int) $pack['installs'] : null);
        $updated = isset($pack['updated']) && is_numeric($pack['updated']) && (int) $pack['updated'] > 0
            ? gmdate('c', (int) $pack['updated'])
            : null;
        $author = is_string($pack['authors'][0]['name'] ?? null) && trim($pack['authors'][0]['name']) !== ''
            ? trim($pack['authors'][0]['name'])
            : null;

        return new ModpackDescriptor(
            provider: ModpackProvider::Ftb->value,
            id: $id,
            title: (string) ($pack['name'] ?? $id),
            versionId: $versionId,
            versionName: (string) ($version['name'] ?? $versionId),
            minecraftVersion: $manifest?->minecraftVersion ?? $minecraft,
            loader: $manifest?->loader ?? $loader,
            loaderVersion: $manifest?->loaderVersion ?? $loaderVersion,
            iconUrl: $this->icon($pack),
            provisioningProfileId: null,
            variables: [],
            archiveAllowed: $manifest !== null,
            unsupportedReason: $unsupported,
            downloadUrl: $manifest === null ? null : $url,
            artifactId: $manifest === null ? null : $versionId,
            minimumJava: $manifest?->minimumJava,
            manifestFingerprint: $manifest === null ? null : FtbReleaseManifest::fingerprint($manifest),
            description: $synopsis !== '' ? $synopsis : null,
            downloads: $downloads,
            dateModified: $updated,
            author: $author,
        );
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function targets(array $version): array
    {
        $loader = null;
        $loaderVersion = null;
        $minecraft = null;
        $loaderCount = 0;
        $minecraftCount = 0;

        foreach (is_array($version['targets'] ?? null) ? $version['targets'] : [] as $target) {
            if (!is_array($target)) {
                continue;
            }

            $type = strtolower((string) ($target['type'] ?? ''));
            if ($type === 'game' && strtolower((string) ($target['name'] ?? '')) === 'minecraft') {
                $minecraftCount++;
                $minecraft = trim((string) ($target['version'] ?? '')) ?: null;
            }

            if ($type === 'modloader') {
                $normalized = ModpackLoader::normalize($target['name'] ?? null);
                if ($normalized !== null) {
                    $loaderCount++;
                    $loader = $normalized;
                    $loaderVersion = trim((string) ($target['version'] ?? '')) ?: null;
                }
            }
        }

        if ($loaderCount !== 1) {
            $loader = null;
            $loaderVersion = null;
        }

        if ($minecraftCount !== 1) {
            $minecraft = null;
        }

        return [$loader, $loaderVersion, $minecraft];
    }

    /** @param array<string, mixed> $pack */
    private function icon(array $pack): ?string
    {
        foreach (is_array($pack['art'] ?? null) ? $pack['art'] : [] as $art) {
            if (is_array($art) && is_string($art['url'] ?? null) && str_starts_with($art['url'], 'https://')) {
                return $art['url'];
            }
        }

        return null;
    }
}
