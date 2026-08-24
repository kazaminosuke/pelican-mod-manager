<?php

namespace Kazaminosuke\ModManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PluginIdentityTest extends TestCase
{
    private const PLUGIN_ID = 'pelican-mod-manager';

    public function test_manifest_and_update_metadata_use_the_current_plugin_identity(): void
    {
        $manifest = $this->json('plugin.json');
        $updates = $this->json('update.json');

        self::assertSame(self::PLUGIN_ID, $manifest['id']);
        self::assertSame('Kazaminosuke\\ModManager', $manifest['namespace']);
        self::assertSame(
            'https://github.com/kazaminosuke/'.self::PLUGIN_ID,
            $manifest['url'],
        );
        self::assertSame(
            'https://raw.githubusercontent.com/kazaminosuke/'.self::PLUGIN_ID.'/refs/heads/main/update.json',
            $manifest['update_url'],
        );
        self::assertSame($manifest['version'], $updates['*']['version']);
        self::assertSame(
            'https://github.com/kazaminosuke/'.self::PLUGIN_ID.'/releases/download/'
                .$manifest['version'].'/'.self::PLUGIN_ID.'.zip',
            $updates['*']['download_url'],
        );
    }

    public function test_runtime_configuration_uses_only_namespaced_environment_keys(): void
    {
        $config = $this->contents('config/pelican-mod-manager.php');

        foreach ([
            'MOD_MANAGER_LATEST_MINECRAFT_VERSION',
            'MOD_MANAGER_MOD_NAV_SORT',
            'MOD_MANAGER_PLUGIN_NAV_SORT',
            'MOD_MANAGER_DATAPACK_NAV_SORT',
            'MOD_MANAGER_RESOURCEPACK_NAV_SORT',
            'MOD_MANAGER_CURSEFORGE_API_KEY',
            'MOD_MANAGER_GITHUB_TOKEN',
        ] as $key) {
            self::assertStringContainsString("env('{$key}'", $config);
        }

        foreach ([
            'MINECRAFT_MODRINTH_',
            "env('LATEST_MINECRAFT_VERSION'",
            "env('CURSEFORGE_API_KEY'",
            "env('GITHUB_TOKEN'",
        ] as $legacyKey) {
            self::assertStringNotContainsString($legacyKey, $config);
        }
    }

    public function test_pre_release_schema_is_defined_by_two_baseline_migrations(): void
    {
        $migrations = glob($this->path('database/migrations/*.php'));

        self::assertIsArray($migrations);
        self::assertCount(2, $migrations);

        $serverSettings = $this->contents(
            'database/migrations/2026_08_21_000002_create_mod_manager_server_settings_table.php',
        );

        foreach ([
            'resourcepack_enabled',
            'modrinth_enabled',
            'curseforge_enabled',
            'hangar_enabled',
            'github_releases_enabled',
            'resourcepack_navigation_sort',
        ] as $column) {
            self::assertStringContainsString("'{$column}'", $serverSettings);
        }
    }

    /** @return array<string, mixed> */
    private function json(string $relativePath): array
    {
        $decoded = json_decode($this->contents($relativePath), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }

    private function contents(string $relativePath): string
    {
        $contents = file_get_contents($this->path($relativePath));

        self::assertIsString($contents);

        return $contents;
    }

    private function path(string $relativePath): string
    {
        return dirname(__DIR__, 2).'/'.$relativePath;
    }
}
