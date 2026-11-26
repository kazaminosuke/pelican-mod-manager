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
        $required = self::minecraftJava($minecraftVersion);

        return $required === null ? null : 'Java '.$required;
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

    private static function maximumJava(?string $minecraftVersion, ?string $loader = null): ?int
    {
        $version = strtolower(trim((string) $minecraftVersion));
        if ($version === '' || preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $version, $matches) !== 1) {
            return null;
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        if ($major !== 1) {
            return null;
        }

        if ($minor <= 12 && ($loader === 'forge' || $loader === null)) {
            return 8;
        }

        if ($minor <= 16 && $loader === 'forge') {
            return 16;
        }

        return null;
    }

    private static function imageJava(?string $image): ?int
    {
        return preg_match('~(?:^|[/:])java[_-](\d+)(?:$|[.:_@-])~i', (string) $image, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * @param  array<string, string>  $dockerImages
     */
    public static function fromEggImages(array $dockerImages, ?string $minecraftVersion, ?string $loader = null): ?string
    {
        $required = self::minecraftJava($minecraftVersion);
        $maximum = self::maximumJava($minecraftVersion, $loader);
        if ($required === null || ($maximum !== null && $required > $maximum)) {
            return null;
        }

        $label = 'Java '.$required;
        foreach ($dockerImages as $name => $image) {
            if (!is_string($image) || $image === '') {
                continue;
            }

            if (is_string($name) && strcasecmp($name, $label) === 0) {
                return $image;
            }
        }

        $compatible = [];
        foreach ($dockerImages as $image) {
            if (!is_string($image) || $image === '') {
                continue;
            }
            $actual = self::imageJava($image);
            if ($actual === null) {
                continue;
            }
            if ($actual === $required) {
                return $image;
            }
            if ($actual >= $required && ($maximum === null || $actual <= $maximum)) {
                $compatible[] = ['java' => $actual, 'image' => $image];
            }
        }

        if ($compatible === []) {
            return null;
        }

        usort($compatible, static fn (array $left, array $right): int => $left['java'] <=> $right['java']);

        return $compatible[0]['image'];
    }
}
