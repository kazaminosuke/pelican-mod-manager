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
     * @var array<string, array{resource_id: string, version_id: string, version_number: string, plugin_name: ?string}>
     */
    private array $memo = [];

    /**
     * @return array{resource_id: string, version_id: string, version_number: string, plugin_name: ?string}|null
     */
    public function findBySha256(string $sha256): ?array
    {
        $sha256 = strtolower($sha256);
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return null;
        }

        if (isset($this->memo[$sha256])) {
            return $this->memo[$sha256];
        }

        try {
            $row = ModManagerSpigotFileIndex::query()->where('sha256', $sha256)->first();
        } catch (Throwable) {
            return null;
        }

        if (!$row instanceof ModManagerSpigotFileIndex) {
            return null;
        }

        return $this->memo[$sha256] = $this->normalizeRow($row);
    }

    /**
     * @param  array<int, string>  $hashes
     * @return array<string, array{resource_id: string, version_id: string, version_number: string, plugin_name: ?string}>
     */
    public function findManyBySha256(array $hashes): array
    {
        $valid = [];
        foreach ($hashes as $hash) {
            $normalized = strtolower(trim((string) $hash));
            if (preg_match('/^[a-f0-9]{64}$/', $normalized) === 1) {
                $valid[$normalized] = $normalized;
            }
        }

        if ($valid === []) {
            return [];
        }

        $results = [];
        $missing = [];
        foreach ($valid as $sha256) {
            if (isset($this->memo[$sha256])) {
                $results[$sha256] = $this->memo[$sha256];
            } else {
                $missing[] = $sha256;
            }
        }

        if ($missing !== []) {
            try {
                $rows = ModManagerSpigotFileIndex::query()->whereIn('sha256', $missing)->get();
                foreach ($rows as $row) {
                    if ($row instanceof ModManagerSpigotFileIndex) {
                        $sha256 = strtolower((string) $row->sha256);
                        $results[$sha256] = $this->memo[$sha256] = $this->normalizeRow($row);
                    }
                }
            } catch (Throwable) {
                // Keep any in-memory matches if the index table is unavailable.
            }
        }

        return $results;
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

        $normalizedPluginName = is_string($pluginName) && $pluginName !== '' ? $pluginName : null;
        $candidate = [
            'resource_id' => $resourceId,
            'version_id' => $versionId,
            'version_number' => $versionNumber,
            'plugin_name' => $normalizedPluginName,
        ];

        if (($this->memo[$sha256] ?? null) === $candidate) {
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
            $this->memo[$sha256] = $candidate;
        } catch (Throwable) {
            // Identification still succeeded; the next scan can persist this.
        }
    }

    /**
     * @return array{resource_id: string, version_id: string, version_number: string, plugin_name: ?string}
     */
    private function normalizeRow(ModManagerSpigotFileIndex $row): array
    {
        return [
            'resource_id' => (string) $row->resource_id,
            'version_id' => (string) $row->version_id,
            'version_number' => (string) $row->version_number,
            'plugin_name' => is_string($row->plugin_name) && $row->plugin_name !== '' ? $row->plugin_name : null,
        ];
    }
}
