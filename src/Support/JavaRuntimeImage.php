<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * Picks a Java image the egg already offers for a known Minecraft version.
 * An image that is not in the egg's list is never invented.
 */
final class JavaRuntimeImage
{
    public static function preferredLabel(?string $minecraftVersion, ?int $minimumJava = null): ?string
    {
        $required = max(self::minecraftJava($minecraftVersion) ?? 0, $minimumJava ?? 0);

        return $required === 0 ? null : 'Java '.$required;
    }

    private static function minecraftJava(?string $minecraftVersion): ?int
    {
        $version = strtolower(trim((string) $minecraftVersion));
        if ($version === '') {
            return null;
        }

        if (preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $version, $matches) !== 1) {
            return null;
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        $patch = isset($matches[3]) ? (int) $matches[3] : 0;

        if ($major >= 26) {
            return 25;
        }

        if ($minor > 20 || ($minor === 20 && $patch >= 5)) {
            return 21;
        }

        if ($minor >= 18) {
            return 17;
        }

        if ($minor === 17) {
            return 16;
        }

        return 8;
    }

    public static function isTooOld(?string $image, ?string $minecraftVersion, ?int $minimumJava = null): bool
    {
        $actual = self::imageJava($image);
        $required = max(self::minecraftJava($minecraftVersion) ?? 0, $minimumJava ?? 0);

        return $actual === null ? $minimumJava !== null : $actual < $required;
    }

    private static function imageJava(?string $image): ?int
    {
        return preg_match('~(?:^|[/:])java[_-](\d+)(?:$|[.:_@-])~i', (string) $image, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * @param  array<string, string>  $dockerImages
     */
    public static function fromEggImages(array $dockerImages, ?string $minecraftVersion, ?int $minimumJava = null): ?string
    {
        $label = self::preferredLabel($minecraftVersion, $minimumJava);
        if ($label === null) {
            return null;
        }

        foreach ($dockerImages as $name => $image) {
            if (!is_string($image) || $image === '') {
                continue;
            }

            if (is_string($name) && strcasecmp($name, $label) === 0) {
                return $image;
            }
        }

        $version = (int) substr($label, strlen('Java '));
        foreach ($dockerImages as $image) {
            if (is_string($image) && self::imageJava($image) === $version) {
                return $image;
            }
        }

        return null;
    }
}
