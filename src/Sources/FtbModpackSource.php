<?php

namespace Kazaminosuke\ModManager\Sources;

use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDescriptor;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackLoader;
use Kazaminosuke\ModManager\Support\UpstreamHttp;
use Throwable;

/**
 * FTB packs installed by the current FTB Server egg.
 *
 * The egg runs api.feed-the-beast.com's server installer from FTB_MODPACK_ID
 * and FTB_MODPACK_VERSION_ID during installation. Search hits that exist only
 * as CurseForge ids are ignored, because that installer does not accept them.
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

        $ids = is_array($response['packs'] ?? null) ? $response['packs'] : [];
        $descriptors = [];
        foreach (array_slice($ids, 0, 6) as $id) {
            if (!is_numeric($id)) {
                continue;
            }

            try {
                $pack = $this->describe((string) (int) $id);
            } catch (Throwable) {
                continue;
            }

            if ($pack !== null) {
                $descriptors[] = $pack;
            }
        }

        return $descriptors;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function versions(string $packId): array
    {
        $pack = $this->fetchPack($packId);
        $versions = [];
        foreach ($this->publicVersions($pack) as $version) {
            $versions[] = [
                'id' => (string) $version['id'],
                'label' => (string) $version['name'],
            ];
        }

        return $versions;
    }

    public function describe(string $packId, ?string $versionId = null): ?ModpackDescriptor
    {
        $pack = $this->fetchPack($packId);
        if (!is_numeric($pack['id'] ?? null) || ($pack['private'] ?? false) === true) {
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

        return $this->descriptor($pack, $selected);
    }

    /** @return array<string, mixed> */
    private function fetchPack(string $packId): array
    {
        if (preg_match('/^[1-9]\d*$/', $packId) !== 1) {
            return [];
        }

        $response = UpstreamHttp::json()
            ->timeout(8)
            ->connectTimeout(3)
            ->get(self::PACK.$packId)
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    /**
     * @param  array<string, mixed>  $pack
     * @return list<array<string, mixed>>
     */
    private function publicVersions(array $pack): array
    {
        $versions = [];
        foreach (is_array($pack['versions'] ?? null) ? $pack['versions'] : [] as $version) {
            if (!is_array($version) || ($version['private'] ?? false) === true || !is_numeric($version['id'] ?? null)) {
                continue;
            }

            $versions[] = $version;
        }

        usort($versions, static function (array $left, array $right): int {
            return ((int) ($right['released'] ?? 0)) <=> ((int) ($left['released'] ?? 0));
        });

        return $versions;
    }

    /**
     * @param  array<string, mixed>  $pack
     * @param  array<string, mixed>  $version
     */
    private function descriptor(array $pack, array $version): ModpackDescriptor
    {
        [$loader, $loaderVersion, $minecraft] = $this->targets($version);
        $id = (string) (int) $pack['id'];
        $versionId = (string) (int) $version['id'];

        return new ModpackDescriptor(
            provider: ModpackProvider::Ftb->value,
            id: $id,
            title: (string) ($pack['name'] ?? $id),
            versionId: $versionId,
            versionName: (string) ($version['name'] ?? $versionId),
            minecraftVersion: $minecraft,
            loader: $loader,
            loaderVersion: $loaderVersion,
            iconUrl: $this->icon($pack),
            provisioningProfileId: 'ftb-server',
            variables: [
                'FTB_MODPACK_ID' => $id,
                'FTB_MODPACK_VERSION_ID' => $versionId,
                'FTB_SEARCH_TERM' => '',
                'FTB_VERSION_STRING' => '',
            ],
            archiveAllowed: false,
            unsupportedReason: null,
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
