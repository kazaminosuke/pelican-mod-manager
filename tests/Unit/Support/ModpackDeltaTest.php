<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Support\Modpacks\ModpackDelta;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackPaths;
use PHPUnit\Framework\TestCase;

final class ModpackDeltaTest extends TestCase
{
    public function test_only_unchanged_owned_files_are_replaced_or_removed(): void
    {
        $owned = [];
        foreach (['mods/stable.jar', 'mods/old.jar', 'mods/edited.jar', 'config/edited.toml', 'config/old.toml', 'config/clean.toml'] as $path) {
            $owned[$path] = ['sha256' => hash('sha256', $path), 'identity' => 'old'];
        }
        $desired = [];
        foreach (['mods/stable.jar' => 'old', 'mods/new.jar' => 'new', 'mods/local.jar' => 'new', 'config/edited.toml' => 'new', 'config/clean.toml' => 'new'] as $path => $id) {
            $desired[$path] = ['identity' => $id, 'sha256' => null, 'size' => 8];
        }
        $plan = ModpackDelta::plan($owned, $desired, function (string $path) use ($owned) {
            return match ($path) {
                'mods/local.jar' => false,
                'mods/edited.jar', 'config/edited.toml' => hash('sha256', 'user bytes'),
                default => $owned[$path]['sha256'] ?? null,
            };
        });

        self::assertSame([
            'config/clean.toml' => 'replace', 'config/edited.toml' => 'conflict', 'config/old.toml' => 'preserve',
            'mods/edited.jar' => 'conflict', 'mods/local.jar' => 'conflict', 'mods/new.jar' => 'add',
            'mods/old.jar' => 'delete', 'mods/stable.jar' => 'keep',
        ], array_map(fn ($entry) => $entry['action'], $plan));
    }

    public function test_pack_directories_exclude_custom_worlds_and_sensitive_paths(): void
    {
        foreach (['config/shared-world/data/custom.dat', 'config/playerdata/player', 'world/data/map', 'ops.json',
            'server.properties', 'mods/.temp.jar', 'mods/../world/file', 'config/anything/session.lock',
            'ftbteambases/pregen_initial/level.dat', 'datapacks/ftb/session.lock'] as $path) {
            self::assertFalse(ModpackPaths::isManaged($path, 'config/shared-world'), $path);
        }
        self::assertTrue(ModpackPaths::isManaged('config/options.toml', 'config/shared-world'));
        self::assertTrue(ModpackPaths::isManaged('mods/test.jar', 'survival-2026'));
        self::assertTrue(ModpackPaths::isManaged('datapacks/ftb/data/ftb/loot_table/entities/slime.json', 'world'));
        self::assertTrue(ModpackPaths::isManaged('datapacks/ftb/data/ftb/tinkering/materials/stats/steel.json', 'world'));
        self::assertTrue(ModpackPaths::isManaged('ftbteambases/pregen_initial/region/r.0.0.mca', 'world'));
        self::assertTrue(ModpackPaths::isManaged('groovy/init.groovy', 'world'));
        self::assertTrue(ModpackPaths::isManaged('bansoukou/Mod/Patch.class', 'world'));
    }
}
