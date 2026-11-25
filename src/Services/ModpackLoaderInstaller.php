<?php

namespace Kazaminosuke\ModManager\Services;

use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Support\JavaRuntimeImage;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackLoader;

/** Builds a Wings installer request from pinned loader coordinates. */
final class ModpackLoaderInstaller
{
    /** @return array{container_image: string, entrypoint: string, script: string} */
    public static function forRelease(string $loader, ?string $minecraft, ?string $build, string $jar): array
    {
        if ($minecraft === null || !preg_match('/^[0-9]{1,2}\.[0-9]{1,2}(?:\.[0-9]{1,3})?$/D', $minecraft)
            || $build === null || !preg_match('/^[0-9][A-Za-z0-9.+_-]{0,63}$/D', $build)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.jar$/D', $jar)
            || in_array($jar, ['minecraft-server.jar', 'minecraft.jar'], true)) {
            throw new ModpackException('Choose a fixed Minecraft version, loader build and valid server JAR name.');
        }

        $build = ModpackLoader::pinBuild($loader, $build, $minecraft);
        $modern = self::usesArgumentFile($loader, $minecraft);
        $java = (int) substr(JavaRuntimeImage::preferredLabel($minecraft) ?? 'Java 8', 5);
        $steps = match ($loader) {
            'fabric' => [
                11,
                'https://maven.fabricmc.net/net/fabricmc/fabric-installer/1.1.2/fabric-installer-1.1.2.jar',
                'java -jar "$installer" server -mcversion "$minecraft" -loader "$build" -downloadMinecraft',
                <<<'SH'
mv server.jar minecraft-server.jar
[[ "$jar" == fabric-server-launch.jar ]] || mv fabric-server-launch.jar "$jar"
printf 'serverJar=minecraft-server.jar\n' > fabric-server-launcher.properties
SH,
            ],
            'quilt' => [
                max(21, $java),
                'https://maven.quiltmc.org/repository/release/org/quiltmc/quilt-installer/0.15.1/quilt-installer-0.15.1.jar',
                'java -jar "$installer" install server "$minecraft" "$build" --download-server',
                <<<'SH'
mv server/server.jar minecraft.jar
mv server/quilt-server-launch.jar "$jar"
mv server/libraries libraries
rmdir server 2>/dev/null || true
printf 'serverJar=minecraft.jar\n' > quilt-server-launcher.properties
SH,
            ],
            'forge', 'neoforge' => self::forge($loader, $minecraft, $build, $modern, $java),
            default => throw new ModpackException('This mod loader has no managed installer.'),
        };
        [$java, $url, $install, $setup] = $steps;
        $checksum = match ($loader) {
            'fabric' => '61e035bf7bf70153e127440ce34de47c9036f0a2d0c65d1529454bd35ceefe4f',
            'quilt' => '0a229138caa1b87fd8f5622038410696f98bb85871a279640e7002404c4d0dc2',
            default => null,
        };

        return [
            'container_image' => 'ghcr.io/pelican-eggs/installers:java_'.$java,
            'entrypoint' => 'bash',
            'script' => implode("\n", [
                '#!/usr/bin/env bash', 'set -euo pipefail', 'cd /mnt/server',
                'minecraft='.escapeshellarg($minecraft), 'build='.escapeshellarg($build), 'jar='.escapeshellarg($jar),
                'installer="$(mktemp /tmp/mod-manager-loader-XXXXXX.jar)"', "trap 'rm -f -- \"\$installer\"' EXIT",
                'if ! command -v curl >/dev/null; then apt-get update && apt-get install -y --no-install-recommends ca-certificates curl; fi',
                "curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --max-time 180 --output \"\$installer\" ".escapeshellarg($url),
                ...($checksum === null ? [] : ["printf '%s  %s\\n' '$checksum' \"\$installer\" | sha256sum -c -"]),
                $install, $setup, $modern ? 'test -s unix_args.txt' : 'test -s "$jar"',
            ])."\n",
        ];
    }

    public static function startup(string $loader, string $minecraft, string $jar): string
    {
        $args = self::usesArgumentFile($loader, $minecraft) ? '@unix_args.txt' : '-jar '.$jar;

        return 'java -Xms128M -XX:MaxRAMPercentage=95.0 '.$args.' nogui';
    }

    private static function usesArgumentFile(string $loader, string $minecraft): bool
    {
        return $loader === 'neoforge' || ($loader === 'forge' && version_compare($minecraft, '1.17', '>='));
    }

    /** @return array{int, string, string, string} */
    private static function forge(string $loader, string $minecraft, string $build, bool $modern, int $java): array
    {
        if ($loader === 'forge' && !str_starts_with($build, $minecraft.'-')) {
            throw new ModpackException('The Forge build does not match the Minecraft version.');
        }

        $artifact = $loader === 'neoforge' && $minecraft !== '1.20.1' ? 'neoforge' : 'forge';
        $repository = $loader === 'forge' ? 'https://maven.minecraftforge.net' : 'https://maven.neoforged.net/releases';
        $group = $loader === 'forge' ? 'minecraftforge' : 'neoforged';
        $path = "net/$group/$artifact";
        $setup = $modern
            ? 'cp '.escapeshellarg("libraries/$path/$build/unix_args.txt").' unix_args.txt'
            : <<<'SH'
if [[ -s "forge-${build}.jar" ]]; then
    output="forge-${build}.jar"
else
    output="forge-${build}-universal.jar"
fi
[[ "$output" == "$jar" ]] || mv "$output" "$jar"
SH;

        return [
            $java === 16 ? 17 : $java,
            "$repository/$path/$build/$artifact-$build-installer.jar",
            'java -jar "$installer" --installServer',
            $setup,
        ];
    }
}
