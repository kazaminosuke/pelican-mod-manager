<?php

namespace Kazaminosuke\ModManager\Support;

use Kazaminosuke\ModManager\Models\ModManagerSpigotFileIndex;
use Throwable;

/**
 * Local SHA-256 → Spigot resource/version mapping.
 */
final class SpigotFileIndex
{
    /**
     * @return array{resource_id: string, version_id: string, version_number: string, plugin_name: ?string}|null
     */
    public function findBySha256(string $sha256): ?array
    {
        $sha256 = strtolower($sha256);
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return null;
        }

        try {
            $row = ModManagerSpigotFileIndex::query()->where('sha256', $sha256)->first();
        } catch (Throwable) {
            return null;
        }

        if (!$row instanceof ModManagerSpigotFileIndex) {
            return null;
        }

        return [
            'resource_id' => (string) $row->resource_id,
            'version_id' => (string) $row->version_id,
            'version_number' => (string) $row->version_number,
            'plugin_name' => is_string($row->plugin_name) && $row->plugin_name !== '' ? $row->plugin_name : null,
        ];
    }

    public function remember(
        string $sha256,
        string $resourceId,
        string $versionId,
        string $versionNumber,
        ?string $pluginName = null,
    ): void {
        $sha256 = strtolower($sha256);
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || $resourceId === '' || $versionId === '') {
            return;
        }

        try {
            ModManagerSpigotFileIndex::query()->updateOrCreate(
                ['sha256' => $sha256],
                [
                    'resource_id' => $resourceId,
                    'version_id' => $versionId,
                    'version_number' => $versionNumber,
                    'plugin_name' => $pluginName,
                ],
            );
        } catch (Throwable) {
            // Identification still succeeded; the next scan can persist this.
        }
    }
}
