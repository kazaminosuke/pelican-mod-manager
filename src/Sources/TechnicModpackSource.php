<?php

namespace Kazaminosuke\ModManager\Sources;

use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDescriptor;
use Kazaminosuke\ModManager\Support\Modpacks\TechnicEggBinding;
use Kazaminosuke\ModManager\Support\UpstreamHttp;

/**
 * Technic platform search. A pack is installable only when its serverPackUrl
 * matches the download URL of a dedicated Pelican Technic egg. There is no
 * generic Technic egg, and a server zip is not installed by hand.
 */
final class TechnicModpackSource
{
    private const SEARCH = 'https://api.technicpack.net/search';

    private const PACK = 'https://api.technicpack.net/modpack/';

    /**
     * @return list<ModpackDescriptor>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $response = UpstreamHttp::json()
            ->timeout(8)
            ->connectTimeout(3)
            ->get(self::SEARCH, ['q' => $query, 'build' => 'stable'])
            ->throw()
            ->json();

        $descriptors = [];
        foreach (array_slice(is_array($response['modpacks'] ?? null) ? $response['modpacks'] : [], 0, 12) as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $slug = trim((string) ($hit['slug'] ?? ''));
            if ($slug === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $slug)) {
                continue;
            }

            $descriptors[] = new ModpackDescriptor(
                provider: ModpackProvider::Technic->value,
                id: $slug,
                title: (string) ($hit['name'] ?? $slug),
                versionId: null,
                versionName: null,
                minecraftVersion: null,
                loader: null,
                loaderVersion: null,
                iconUrl: is_string($hit['iconUrl'] ?? null) && str_starts_with($hit['iconUrl'], 'https://') ? $hit['iconUrl'] : null,
                provisioningProfileId: null,
                variables: [],
                archiveAllowed: false,
                unsupportedReason: null,
            );
        }

        return $descriptors;
    }

    public function describe(string $slug): ?ModpackDescriptor
    {
        $slug = trim($slug);
        if ($slug === '' || preg_match('/^[A-Za-z0-9._-]+$/', $slug) !== 1) {
            return null;
        }

        $pack = UpstreamHttp::json()
            ->timeout(8)
            ->connectTimeout(3)
            ->get(self::PACK.$slug, ['build' => 'recommended'])
            ->throw()
            ->json();

        if (!is_array($pack) || trim((string) ($pack['name'] ?? '')) === '') {
            return null;
        }

        $serverPackUrl = is_string($pack['serverPackUrl'] ?? null) ? $pack['serverPackUrl'] : '';
        $minecraft = trim((string) ($pack['minecraft'] ?? '')) ?: null;
        $displayVersion = trim((string) ($pack['version'] ?? '')) ?: null;
        $title = (string) ($pack['displayName'] ?? $pack['name']);
        $icon = $pack['icon']['url'] ?? null;
        $binding = $serverPackUrl !== '' ? TechnicEggBinding::match($serverPackUrl) : null;

        if ($serverPackUrl === '') {
            return new ModpackDescriptor(
                provider: ModpackProvider::Technic->value,
                id: $slug,
                title: $title,
                versionId: 'recommended',
                versionName: $displayVersion,
                minecraftVersion: $minecraft,
                loader: null,
                loaderVersion: null,
                iconUrl: is_string($icon) && str_starts_with($icon, 'https://') ? $icon : null,
                provisioningProfileId: null,
                variables: [],
                archiveAllowed: false,
                unsupportedReason: 'This Technic pack does not publish a server archive.',
            );
        }

        if ($binding === null) {
            return new ModpackDescriptor(
                provider: ModpackProvider::Technic->value,
                id: $slug,
                title: $title,
                versionId: 'recommended',
                versionName: $displayVersion,
                minecraftVersion: $minecraft,
                loader: null,
                loaderVersion: null,
                iconUrl: is_string($icon) && str_starts_with($icon, 'https://') ? $icon : null,
                provisioningProfileId: null,
                variables: [],
                archiveAllowed: false,
                unsupportedReason: 'No Pelican Technic egg installs this server archive. Technic eggs are pack-specific and there is no generic Technic egg.',
            );
        }

        [$matched, $version] = $binding;

        return new ModpackDescriptor(
            provider: ModpackProvider::Technic->value,
            id: $slug,
            title: $title,
            versionId: $version,
            versionName: $version,
            minecraftVersion: $minecraft,
            loader: null,
            loaderVersion: null,
            iconUrl: is_string($icon) && str_starts_with($icon, 'https://') ? $icon : null,
            provisioningProfileId: $matched->profileId,
            variables: ['MODPACK_VERSION' => $version],
            archiveAllowed: false,
            unsupportedReason: null,
        );
    }
}
