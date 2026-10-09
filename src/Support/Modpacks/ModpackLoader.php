<?php

namespace Kazaminosuke\ModManager\Support\Modpacks;

/**
 * Maps provider loader names onto the loaders this plugin already understands.
 * An unrecognized name stays null instead of being guessed from the pack title.
 */
final class ModpackLoader
{
    public static function normalize(mixed $name): ?string
    {
        $value = strtolower(trim((string) $name));

        return match ($value) {
            'forge' => 'forge',
            'neoforge', 'neoforged' => 'neoforge',
            'fabric', 'fabric-loader', 'fabricloader' => 'fabric',
            'quilt', 'quilt-loader', 'quiltloader' => 'quilt',
            default => null,
        };
    }
}
