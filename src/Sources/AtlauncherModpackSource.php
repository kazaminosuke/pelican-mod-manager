<?php

namespace Kazaminosuke\ModManager\Sources;

use Kazaminosuke\ModManager\Enums\ModpackProvider;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackDescriptor;
use Kazaminosuke\ModManager\Support\UpstreamHttp;

/**
 * ATLauncher's public pack list. Versions expose a Minecraft version, but not
 * a loader or a server archive, and pelican-eggs has no ATLauncher egg.
 * Packs can be looked up and are then reported as unsupported.
 */
final class AtlauncherModpackSource
{
    public const UNSUPPORTED = 'ATLauncher has no Pelican egg, and the public version API does not include a loader or a server archive. The pack cannot be provisioned without guessing.';

    private const SIMPLE = 'https://api.atlauncher.com/v1/packs/simple';

    private const PACK = 'https://api.atlauncher.com/v1/pack/';

    /**
     * @return list<ModpackDescriptor>
     */
    public function search(string $query): array
    {
        $query = mb_strtolower(trim($query));
        if (mb_strlen($query) < 2) {
            return [];
        }

        $response = UpstreamHttp::json()
            ->timeout(12)
            ->connectTimeout(3)
            ->get(self::SIMPLE)
            ->throw()
            ->json();

        $packs = is_array($response['data'] ?? null) ? $response['data'] : [];
        $descriptors = [];
        foreach ($packs as $pack) {
            if (!is_array($pack) || ($pack['type'] ?? '') !== 'public') {
                continue;
            }

            $name = (string) ($pack['name'] ?? '');
            $safeName = trim((string) ($pack['safeName'] ?? ''));
            if ($safeName === '' || !preg_match('/^[A-Za-z0-9]+$/', $safeName)) {
                continue;
            }

            if (!str_contains(mb_strtolower($name), $query) && !str_contains(mb_strtolower($safeName), $query)) {
                continue;
            }

            $descriptors[] = $this->unsupported($safeName, $name !== '' ? $name : $safeName, null, null);
            if (count($descriptors) >= 12) {
                break;
            }
        }

        return $descriptors;
    }

    /**
     * @return list<array{id: string, label: string, minecraft: ?string}>
     */
    public function versions(string $safeName): array
    {
        $pack = $this->fetch($safeName);
        $versions = [];
        foreach (is_array($pack['versions'] ?? null) ? $pack['versions'] : [] as $version) {
            if (!is_array($version)) {
                continue;
            }

            $id = trim((string) ($version['version'] ?? ''));
            if ($id === '') {
                continue;
            }

            $minecraft = trim((string) ($version['minecraft'] ?? '')) ?: null;
            $versions[] = [
                'id' => $id,
                'label' => $minecraft !== null ? $id.' (Minecraft '.$minecraft.')' : $id,
                'minecraft' => $minecraft,
            ];
        }

        return $versions;
    }

    public function describe(string $safeName, ?string $versionId = null): ?ModpackDescriptor
    {
        $safeName = trim($safeName);
        if (preg_match('/^[A-Za-z0-9]+$/', $safeName) !== 1) {
            return null;
        }

        $pack = $this->fetch($safeName);
        if ($pack === []) {
            return null;
        }

        $minecraft = null;
        $selected = null;
        foreach ($this->versions($safeName) as $version) {
            if ($versionId === null || $versionId === '' || $version['id'] === $versionId) {
                $selected = $version;
                $minecraft = $version['minecraft'];
                break;
            }
        }

        if ($versionId !== null && $versionId !== '' && $selected === null) {
            return null;
        }

        return $this->unsupported(
            $safeName,
            (string) ($pack['name'] ?? $safeName),
            $selected['id'] ?? null,
            $minecraft,
        );
    }

    /** @return array<string, mixed> */
    private function fetch(string $safeName): array
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $safeName) !== 1) {
            return [];
        }

        $response = UpstreamHttp::json()
            ->timeout(8)
            ->connectTimeout(3)
            ->get(self::PACK.$safeName.'/')
            ->throw()
            ->json();

        $data = $response['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    private function unsupported(string $id, string $title, ?string $versionId, ?string $minecraft): ModpackDescriptor
    {
        return new ModpackDescriptor(
            provider: ModpackProvider::Atlauncher->value,
            id: $id,
            title: $title,
            versionId: $versionId,
            versionName: $versionId,
            minecraftVersion: $minecraft,
            loader: null,
            loaderVersion: null,
            iconUrl: null,
            provisioningProfileId: null,
            variables: [],
            archiveAllowed: false,
            unsupportedReason: self::UNSUPPORTED,
        );
    }
}
