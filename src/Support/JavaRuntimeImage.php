<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * Picks a Java image the egg already offers for a known Minecraft version.
 * An image that is not in the egg's list is never invented.
 */
final class JavaRuntimeImage
{
    public static function preferredLabel(?string $minecraftVersion): ?string
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

        if ($major >= 26 || $minor > 20 || ($minor === 20 && $patch >= 5)) {
            return 'Java 21';
        }

        if ($minor >= 18) {
            return 'Java 17';
        }

        if ($minor === 17) {
            return 'Java 16';
        }

        return 'Java 8';
    }

    /**
     * @param  array<string, string>  $dockerImages
     */
    public static function fromEggImages(array $dockerImages, ?string $minecraftVersion): ?string
    {
        $label = self::preferredLabel($minecraftVersion);
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

        $needle = 'java_'.substr($label, strlen('Java '));
        foreach ($dockerImages as $image) {
            if (is_string($image) && str_contains(strtolower($image), $needle)) {
                return $image;
            }
        }

        return null;
    }
}
