<?php

namespace Kazaminosuke\ModManager\Support;

use InvalidArgumentException;

/**
 * Resolves the manager's self-hosted, content-addressed browser assets.
 *
 * Filament's published-asset pipeline is intentionally not used here: plugin
 * installation does not run `filament:assets`. Keeping the small allowlist in
 * the plugin directory makes a normal plugin install sufficient while the
 * content hash makes the response safe to cache indefinitely.
 */
final class ModManagerAssets
{
    /** @var array<string, array{path: string, content_type: string}> */
    private const ASSETS = [
        'mod-manager.css' => [
            'path' => 'resources/assets/mod-manager.css',
            'content_type' => 'text/css; charset=UTF-8',
        ],
        'mod-manager-runtime.js' => [
            'path' => 'resources/assets/mod-manager-runtime.js',
            'content_type' => 'text/javascript; charset=UTF-8',
        ],
        'table-layout.js' => [
            'path' => 'resources/assets/table-layout.js',
            'content_type' => 'text/javascript; charset=UTF-8',
        ],
        'table-swr-cache.js' => [
            'path' => 'resources/assets/table-swr-cache.js',
            'content_type' => 'text/javascript; charset=UTF-8',
        ],
        'catalog-url-history.js' => [
            'path' => 'resources/assets/catalog-url-history.js',
            'content_type' => 'text/javascript; charset=UTF-8',
        ],
    ];

    /** @return array{path: string, content_type: string, version: string} */
    public static function get(string $asset): array
    {
        $definition = self::ASSETS[$asset] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException("Unknown Mod Manager asset: {$asset}");
        }

        $path = plugin_path('pelican-mod-manager', $definition['path']);
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("Unreadable Mod Manager asset: {$asset}");
        }

        $version = hash_file('sha256', $path);
        if (!is_string($version)) {
            throw new InvalidArgumentException("Unable to hash Mod Manager asset: {$asset}");
        }

        return [
            'path' => $path,
            'content_type' => $definition['content_type'],
            'version' => $version,
        ];
    }

    public static function url(string $asset): string
    {
        $definition = self::get($asset);

        return route('pelican-mod-manager.asset', [
            'version' => $definition['version'],
            'asset' => $asset,
        ]);
    }
}
