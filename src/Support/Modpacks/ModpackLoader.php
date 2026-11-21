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

    /** Check the existing Egg's pinned startup variable against the selected release. */
    public static function hasBuild(string $loader, string $build, ?string $minecraft, array $variables): bool
    {
        return self::matchesBuild($loader, $build, (string) ($variables[self::buildVariable($loader)] ?? ''), $minecraft);
    }

    public static function buildVariable(string $loader): string
    {
        return $loader === 'fabric' ? 'LOADER_VERSION' : strtoupper($loader).'_VERSION';
    }

    /**
     * Format a loader build for the official Pelican Egg variable.
     */
    public static function pinBuild(string $loader, string $build, ?string $minecraft): string
    {
        if ($minecraft === null) {
            return $build;
        }

        if ($loader === 'forge' && !str_contains($build, '-')) {
            // The official Forge Egg uses a full Maven version when FORGE_VERSION is set.
            $coordinate = $minecraft.'-'.$build;

            return in_array($minecraft, ['1.7.10', '1.8.9'], true)
                ? $coordinate.'-'.$minecraft
                : $coordinate;
        }

        if ($loader === 'neoforge' && $minecraft === '1.20.1' && !str_starts_with($build, '1.20.1-')) {
            // Official NeoForge Egg switches to net/neoforged/forge only when 1.20.1- is prefixed.
            return '1.20.1-'.$build;
        }

        return $build;
    }

    public static function matchesBuild(string $loader, string $build, string $actual, ?string $minecraft): bool
    {
        if ($actual === $build) {
            return true;
        }
        if ($minecraft === null) {
            return false;
        }
        if ($loader === 'neoforge' && $minecraft === '1.20.1') {
            $strip = static fn (string $value): string => str_starts_with($value, '1.20.1-')
                ? substr($value, strlen('1.20.1-'))
                : $value;

            return $strip($actual) === $strip($build);
        }
        if ($loader !== 'forge') {
            return false;
        }
        $normalizeForge = static function (string $value) use ($minecraft): string {
            if (str_starts_with($value, $minecraft.'-')) {
                $value = substr($value, strlen($minecraft) + 1);
            }

            return str_ends_with($value, '-'.$minecraft) ? substr($value, 0, -strlen($minecraft) - 1) : $value;
        };

        return $normalizeForge($actual) === $normalizeForge($build);
    }
}
