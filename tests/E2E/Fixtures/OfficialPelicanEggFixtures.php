<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Fixtures;

use App\Models\Egg;
use Illuminate\Support\Facades\DB;
use Kazaminosuke\ModManager\Support\Compatibility\ClassifiedEgg;
use Kazaminosuke\ModManager\Support\Compatibility\EggCompatibilityEngine;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;

/**
 * Verbatim official Pelican Minecraft Egg definitions pinned to
 * pelican-eggs/minecraft@c6f2262eb351054d1be44d3f6a3a605ae163d682.
 */
final class OfficialPelicanEggFixtures
{
    public const YOLKS_ALL = [
        'Java 25' => 'ghcr.io/pelican-eggs/yolks:java_25',
        'Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21',
        'Java 17' => 'ghcr.io/pelican-eggs/yolks:java_17',
        'Java 16' => 'ghcr.io/pelican-eggs/yolks:java_16',
        'Java 11' => 'ghcr.io/pelican-eggs/yolks:java_11',
        'Java 8' => 'ghcr.io/pelican-eggs/yolks:java_8',
    ];

    /** @return array<string, mixed> */
    public static function forge(int $id = 1): array
    {
        return [
            'id' => $id,
            'uuid' => 'ed072427-f209-4603-875c-f540c6dd5a65',
            'name' => 'Forge Minecraft',
            'update_url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/main/java/forge/egg-forge-minecraft.yaml',
            'tags' => ['minecraft', 'forge'],
            'docker_images' => self::YOLKS_ALL,
            'startup_commands' => [
                'Default' => 'java -Xms128M -XX:MaxRAMPercentage=95.0 -Dterminal.jline=false -Dterminal.ansi=true $( [[  ! -f unix_args.txt ]] && printf %s "-jar {{SERVER_JARFILE}}" || printf %s "@unix_args.txt" )',
            ],
            'variables' => [
                [
                    'name' => 'Minecraft Version',
                    'env_variable' => 'MC_VERSION',
                    'default_value' => 'latest',
                    'rules' => ['required', 'string', 'max:9'],
                ],
                [
                    'name' => 'Forge Version',
                    'env_variable' => 'FORGE_VERSION',
                    'default_value' => '',
                    'rules' => ['nullable', 'regex:/^[0-9\.\-]+$/'],
                ],
                [
                    'name' => 'Build Type',
                    'env_variable' => 'BUILD_TYPE',
                    'default_value' => 'recommended',
                    'rules' => ['required', 'string', 'in:recommended,latest'],
                ],
                [
                    'name' => 'Server Jar File',
                    'env_variable' => 'SERVER_JARFILE',
                    'default_value' => 'server.jar',
                    'rules' => ['required', 'regex:/^([\w\d._-]+)(\.jar)$/'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function neoforge(int $id = 2): array
    {
        return [
            'id' => $id,
            'uuid' => 'e23e092f-b803-4f34-82cf-2d6518c6351a',
            'name' => 'NeoForge',
            'update_url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/main/java/neoforge/egg-neo-forge.json',
            'tags' => ['minecraft', 'neoforge'],
            'docker_images' => self::YOLKS_ALL,
            'startup_commands' => [
                'Default' => 'java -Xms128M -XX:MaxRAMPercentage=95.0 -Dterminal.jline=false -Dterminal.ansi=true @unix_args.txt',
            ],
            'variables' => [
                [
                    'name' => 'Minecraft Version',
                    'env_variable' => 'MC_VERSION',
                    'default_value' => 'latest',
                    'rules' => ['required', 'string', 'max:9'],
                ],
                [
                    'name' => 'NeoForge Version',
                    'env_variable' => 'NEOFORGE_VERSION',
                    'default_value' => '',
                    'rules' => ['nullable', 'regex:/^[0-9\.\-]+$/'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function fabric(int $id = 3): array
    {
        return [
            'id' => $id,
            'uuid' => '78b02ebb-fec8-49c5-943c-ca4aa117b693',
            'name' => 'Fabric',
            'update_url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/main/java/fabric/egg-fabric.yaml',
            'tags' => ['minecraft', 'fabric'],
            'docker_images' => self::YOLKS_ALL,
            'startup_commands' => [
                'Default' => 'java -Xms128M -Xmx{{SERVER_MEMORY}}M -jar {{SERVER_JARFILE}}',
            ],
            'variables' => [
                [
                    'name' => 'Minecraft Version',
                    'env_variable' => 'MC_VERSION',
                    'default_value' => 'latest',
                    'rules' => ['required', 'string', 'between:3,15'],
                ],
                [
                    'name' => 'Fabric Loader Version',
                    'env_variable' => 'LOADER_VERSION',
                    'default_value' => 'latest',
                    'rules' => ['required', 'string', 'between:3,15'],
                ],
                [
                    'name' => 'Fabric Version',
                    'env_variable' => 'FABRIC_VERSION',
                    'default_value' => 'latest',
                    'rules' => ['required', 'string', 'between:3,15'],
                ],
                [
                    'name' => 'Server Jar File',
                    'env_variable' => 'SERVER_JARFILE',
                    'default_value' => 'server.jar',
                    'rules' => ['required', 'regex:/^([\w\d._-]+)(\.jar)$/'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function quilt(int $id = 4): array
    {
        return [
            'id' => $id,
            'uuid' => 'dff33655-6e6a-4430-accf-e5aea04c2912',
            'name' => 'Quilt',
            'update_url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/main/java/quilt/egg-quilt.yaml',
            'tags' => ['minecraft', 'quilt'],
            'docker_images' => self::YOLKS_ALL,
            'startup_commands' => [
                'Default' => 'java -Xms128M -XX:MaxRAMPercentage=95.0 -jar {{SERVER_JARFILE}} nogui',
            ],
            'variables' => [
                [
                    'name' => 'Minecraft Version',
                    'env_variable' => 'MC_VERSION',
                    'default_value' => '1.20.2',
                    'rules' => ['required', 'string', 'between:3,15'],
                ],
                [
                    'name' => 'Server Jar File',
                    'env_variable' => 'SERVER_JARFILE',
                    'default_value' => 'server.jar',
                    'rules' => ['required', 'regex:/^([\w\d._-]+)(\.jar)$/'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function tekkit2(int $id = 5): array
    {
        return [
            'id' => $id,
            'uuid' => '81bf842b-5647-4c83-9254-523b6f8308fb',
            'name' => 'Tekkit 2',
            'update_url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/main/java/technic/Tekkit-2/egg-tekkit2.json',
            'tags' => ['minecraft', 'technic'],
            'docker_images' => [
                'Java8' => 'ghcr.io/pelican-eggs/yolks:java_8',
                'Java11' => 'ghcr.io/pelican-eggs/yolks:java_11',
            ],
            'startup_commands' => [
                'Default' => 'java -server  -Xms128M -Xmx{{SERVER_MEMORY}}M -Dfml.queryResult=confirm -jar forge.jar nogui',
            ],
            'variables' => [
                [
                    'name' => 'Modpack Version',
                    'env_variable' => 'MODPACK_VERSION',
                    'default_value' => 'v1.0.5',
                    'rules' => ['required', 'string', 'max:10'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function tekkitLegends(int $id = 6): array
    {
        return [
            'id' => $id,
            'uuid' => '12643d14-fca6-4f3e-93ed-048d82d711ce',
            'name' => 'Tekkit Legends',
            'update_url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/main/java/technic/tekkit-legends/egg-tekkit-legends.json',
            'tags' => ['minecraft', 'technic'],
            'docker_images' => [
                'ghcr.io/pelican-eggs/yolks:java_8' => 'ghcr.io/pelican-eggs/yolks:java_8',
            ],
            'startup_commands' => [
                'Default' => 'java -Xms128M -Xmx{{SERVER_MEMORY}}M -jar TekkitLegends.jar',
            ],
            'variables' => [
                [
                    'name' => 'Modpack Version',
                    'env_variable' => 'MODPACK_VERSION',
                    'default_value' => '1.1.1',
                    'rules' => ['required', 'string', 'max:20'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function paper(int $id = 7): array
    {
        return [
            'id' => $id,
            'uuid' => '5da37ef6-58da-4169-90a6-e683e1721247',
            'name' => 'Paper',
            'update_url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/main/java/paper/egg-paper.yaml',
            'tags' => ['minecraft'],
            'docker_images' => self::YOLKS_ALL,
            'startup_commands' => [
                'Default' => 'java -Xms128M -XX:MaxRAMPercentage=95.0 -Dterminal.jline=false -Dterminal.ansi=true -jar {{SERVER_JARFILE}}',
            ],
            'variables' => [
                [
                    'name' => 'Minecraft Version',
                    'env_variable' => 'MINECRAFT_VERSION',
                    'default_value' => 'latest',
                    'rules' => ['nullable', 'string', 'max:20'],
                ],
                [
                    'name' => 'Build Number',
                    'env_variable' => 'BUILD_NUMBER',
                    'default_value' => 'latest',
                    'rules' => ['required', 'string', 'max:20'],
                ],
                [
                    'name' => 'Download Path',
                    'env_variable' => 'DL_PATH',
                    'default_value' => '',
                    'user_viewable' => false,
                    'user_editable' => false,
                    'rules' => ['nullable', 'string'],
                ],
                [
                    'name' => 'Project',
                    'env_variable' => 'PROJECT',
                    'default_value' => 'paper',
                    'user_viewable' => false,
                    'user_editable' => false,
                    'rules' => [],
                ],
                [
                    'name' => 'Server Jar File',
                    'env_variable' => 'SERVER_JARFILE',
                    'default_value' => 'server.jar',
                    'rules' => ['required', 'regex:/^([\w\d._-]+)(\.jar)$/'],
                ],
                [
                    'name' => 'User Agent',
                    'env_variable' => 'USER_AGENT',
                    'default_value' => '',
                    'user_viewable' => false,
                    'user_editable' => false,
                    'rules' => ['required'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function vanilla(int $id = 8): array
    {
        return [
            'id' => $id,
            'uuid' => '9ac39f3d-0c34-4d93-8174-c52ab9e6c57b',
            'name' => 'Vanilla Minecraft',
            'update_url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/main/java/vanilla/egg-vanilla-minecraft.yaml',
            'tags' => ['minecraft', 'vanilla'],
            'docker_images' => self::YOLKS_ALL,
            'startup_commands' => [
                'Default' => 'java -Xms128M -XX:MaxRAMPercentage=95.0 -jar {{SERVER_JARFILE}}',
            ],
            'variables' => [
                [
                    'name' => 'Server Jar File',
                    'env_variable' => 'SERVER_JARFILE',
                    'default_value' => 'server.jar',
                    'rules' => ['required', 'regex:/^([\w\d._-]+)(\.jar)$/'],
                ],
                [
                    'name' => 'Vanilla Version',
                    'env_variable' => 'VANILLA_VERSION',
                    'default_value' => 'latest',
                    'rules' => ['required', 'string', 'between:3,15'],
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function allStandard(): array
    {
        return [
            self::forge(1),
            self::neoforge(2),
            self::fabric(3),
            self::quilt(4),
            self::tekkit2(5),
            self::tekkitLegends(6),
            self::paper(7),
            self::vanilla(8),
        ];
    }

    /**
     * @param  array<string, mixed>  $eggDef
     */
    public static function classify(EggCompatibilityEngine $engine, array $eggDef): ClassifiedEgg
    {
        $variableNames = array_values(array_map(
            static fn (array $v): string => (string) $v['env_variable'],
            $eggDef['variables'] ?? [],
        ));

        return $engine->classify(
            id: $eggDef['id'],
            name: (string) $eggDef['name'],
            uuid: isset($eggDef['uuid']) ? (string) $eggDef['uuid'] : null,
            updateUrl: isset($eggDef['update_url']) ? (string) $eggDef['update_url'] : null,
            variableNames: $variableNames,
        );
    }

    /**
     * @param  list<array<string, mixed>>|null  $defs
     * @return list<ClassifiedEgg>
     */
    public static function classifyAll(EggCompatibilityEngine $engine, ?array $defs = null): array
    {
        $defs ??= self::allStandard();

        return array_values(array_map(
            static fn (array $def): ClassifiedEgg => self::classify($engine, $def),
            $defs,
        ));
    }

    /**
     * @param  array<string, mixed>  $eggDef
     */
    public static function seedIntoDatabase(array $eggDef): Egg
    {
        $now = now()->toDateTimeString();
        $startupCommands = $eggDef['startup_commands'] ?? ['Default' => 'java -jar server.jar'];
        $defaultStartup = (string) (reset($startupCommands) ?: 'java -jar server.jar');

        $row = [
            'id' => (int) $eggDef['id'],
            'uuid' => (string) $eggDef['uuid'],
            'name' => (string) $eggDef['name'],
            'author' => 'eggs@pelican.dev',
            'description' => 'Official Pelican Egg fixture',
            'update_url' => $eggDef['update_url'] ?? null,
            'tags' => json_encode($eggDef['tags'] ?? [], JSON_THROW_ON_ERROR),
            'features' => json_encode([], JSON_THROW_ON_ERROR),
            'docker_images' => json_encode($eggDef['docker_images'] ?? self::YOLKS_ALL, JSON_THROW_ON_ERROR),
            'startup_commands' => json_encode($startupCommands, JSON_THROW_ON_ERROR),
            'startup' => $defaultStartup,
            'file_denylist' => json_encode([], JSON_THROW_ON_ERROR),
            'config_files' => json_encode([], JSON_THROW_ON_ERROR),
            'config_startup' => json_encode([], JSON_THROW_ON_ERROR),
            'config_logs' => json_encode([], JSON_THROW_ON_ERROR),
            'config_stop' => 'stop',
            'script_install' => '#!/bin/bash',
            'script_container' => 'ghcr.io/pelican-eggs/installers:alpine',
            'script_entry' => 'bash',
            'script_is_privileged' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('eggs')->insert($row);

        foreach ($eggDef['variables'] ?? [] as $index => $var) {
            DB::table('egg_variables')->insert([
                'egg_id' => (int) $eggDef['id'],
                'sort' => $index,
                'name' => (string) $var['name'],
                'description' => (string) ($var['description'] ?? ''),
                'env_variable' => (string) $var['env_variable'],
                'default_value' => (string) ($var['default_value'] ?? ''),
                'user_viewable' => (bool) ($var['user_viewable'] ?? true),
                'user_editable' => (bool) ($var['user_editable'] ?? true),
                'rules' => json_encode($var['rules'] ?? ['nullable', 'string'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        /** @var Egg $egg */
        $egg = Egg::query()->with('variables')->findOrFail((int) $eggDef['id']);

        return $egg;
    }

    public static function seedOfficialEggProfiles(): void
    {
        $path = dirname(__DIR__, 3).'/resources/egg-profiles.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        EggProfileRegistry::seed($decoded['profiles']);
    }

    /**
     * Validates proposed variable values against the pinned Pelican Egg rules.
     *
     * @param  array<string, mixed>  $eggDef
     * @param  array<string, string>  $values
     * @return list<string> Failing env_variable names
     */
    public static function validateVariables(array $eggDef, array $values): array
    {
        $failures = [];
        foreach ($eggDef['variables'] ?? [] as $var) {
            $env = (string) $var['env_variable'];
            $value = array_key_exists($env, $values) ? (string) $values[$env] : (string) ($var['default_value'] ?? '');
            $rules = (array) ($var['rules'] ?? []);

            if ($value === '') {
                if (in_array('required', $rules, true)) {
                    $failures[] = $env;
                }

                continue;
            }

            foreach ($rules as $rule) {
                $rule = (string) $rule;
                if ($rule === 'required' || $rule === 'nullable' || $rule === 'string') {
                    continue;
                }
                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (mb_strlen($value) > $max) {
                        $failures[] = $env;
                        break;
                    }
                } elseif (str_starts_with($rule, 'between:')) {
                    [$min, $max] = array_map('intval', explode(',', substr($rule, 8), 2));
                    $len = mb_strlen($value);
                    if ($len < $min || $len > $max) {
                        $failures[] = $env;
                        break;
                    }
                } elseif (str_starts_with($rule, 'in:')) {
                    $allowed = explode(',', substr($rule, 3));
                    if (!in_array($value, $allowed, true)) {
                        $failures[] = $env;
                        break;
                    }
                } elseif (str_starts_with($rule, 'regex:')) {
                    $pattern = substr($rule, 6);
                    if (@preg_match($pattern, $value) !== 1) {
                        $failures[] = $env;
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($failures));
    }
}
