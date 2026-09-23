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
        self::assertSame('kazaminosuke', $manifest['author']);
        self::assertSame('Kazaminosuke\\ModManager', $manifest['namespace']);
        self::assertSame(
            'https://github.com/kazaminosuke/'.self::PLUGIN_ID,
            $manifest['url'],
        );
        self::assertSame(
            'https://raw.githubusercontent.com/kazaminosuke/'.self::PLUGIN_ID.'/refs/heads/main/update.json',
            $manifest['update_url'],
        );
        $version = $manifest['version'];
        self::assertIsString($version);
        $tag = str_starts_with($version, 'v') ? $version : 'v'.$version;
        $isPrerelease = preg_match('/-pre\./', $tag) === 1;
        $publishedVersion = $updates['*']['version'];
        $downloadUrl = $updates['*']['download_url'];
        self::assertIsString($publishedVersion);
        self::assertIsString($downloadUrl);

        if ($isPrerelease) {
            self::assertNotSame($version, $publishedVersion);
            self::assertDoesNotMatchRegularExpression('/-pre\./', $publishedVersion);
            self::assertStringNotContainsString('-pre.', $downloadUrl);
        } else {
            self::assertSame($version, $publishedVersion);
            self::assertSame(
                'https://github.com/kazaminosuke/'.self::PLUGIN_ID.'/releases/download/'
                    .$tag.'/'.self::PLUGIN_ID.'.zip',
                $downloadUrl,
            );
        }
    }

    public function test_readmes_separate_current_author_from_project_and_ui_research_credits(): void
    {
        foreach (['README.md', 'README.ja.md'] as $readme) {
            $contents = $this->contents($readme);

            self::assertStringContainsString('kazaminosuke', $contents);
            foreach (['Boy132', 'H1ghSyst3m', 'Yonn', 'JoanFo1456/resources', 'GPL-3.0'] as $credit) {
                self::assertStringContainsString($credit, $contents);
            }
            self::assertStringNotContainsString('adapted from JoanFo1456/resources', $contents);
            self::assertStringNotContainsString('contains code from JoanFo1456/resources', $contents);
        }
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
            'MOD_MANAGER_MODRINTH_TOKEN',
            'MOD_MANAGER_HANGAR_API_KEY',
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

    public function test_pre_release_schema_is_defined_by_baseline_migrations_plus_the_one_time_queue_restart(): void
    {
        $migrations = glob($this->path('database/migrations/*.php'));

        self::assertIsArray($migrations);
        self::assertCount(5, $migrations);

        $restart = $this->contents(
            'database/migrations/2026_09_21_000003_signal_queue_restart_after_leaving_laravel_queue.php',
        );
        self::assertStringContainsString("Artisan::call('queue:restart')", $restart);
        self::assertStringNotContainsString('posix_kill', $restart);

        $backgroundJobs = $this->contents(
            'database/migrations/2026_09_21_000004_create_mod_manager_background_jobs_table.php',
        );
        self::assertStringContainsString('mod_manager_background_jobs', $backgroundJobs);
        self::assertStringNotContainsString('popen', $backgroundJobs);
        self::assertStringNotContainsString('proc_open', $backgroundJobs);

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

        $spigotSource = $this->contents(
            'database/migrations/2026_09_21_000005_add_spigot_source_and_file_index.php',
        );
        self::assertStringContainsString('spigot_enabled', $spigotSource);
        self::assertStringContainsString('mod_manager_spigot_file_index', $spigotSource);
    }

    public function test_runtime_php_does_not_spawn_processes(): void
    {
        $root = $this->path('src');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            foreach (['popen(', 'pclose(', 'proc_open(', 'proc_close(', 'shell_exec(', 'passthru('] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $contents,
                    $file->getPathname().' contains '.$forbidden,
                );
            }
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
