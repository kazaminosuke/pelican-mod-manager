<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Fixtures;

use RuntimeException;
use ZipArchive;

/**
 * Builds authentic Wings server file maps matching real official Pelican Egg
 * installation outputs for ModpackRuntime::assertInstalled().
 *
 * Includes RFC-style folded META-INF/MANIFEST.MF Class-Path lines (>72 chars)
 * so manifest unfolding is exercised realistically.
 */
final class LoaderRuntimeFixtures
{
    /**
     * Fabric <= 1.21.x (obfuscated): launcher JAR referencing fabric-loader AND
     * intermediary JAR plus vanilla minecraft-server.jar.
     *
     * @return array<string, string>
     */
    public static function fabricObfuscated(
        string $minecraft = '1.21.1',
        string $loaderVersion = '0.16.10',
        string $serverJar = 'server.jar',
        string $vanillaJar = 'minecraft-server.jar',
    ): array {
        $loaderRel = "libraries/net/fabricmc/fabric-loader/{$loaderVersion}/fabric-loader-{$loaderVersion}.jar";
        $intermediaryRel = "libraries/net/fabricmc/intermediary/{$minecraft}/intermediary-{$minecraft}.jar";

        $manifest = self::foldManifest([
            'Manifest-Version: 1.0',
            'Main-Class: net.fabricmc.loader.impl.launch.server.FabricServerLauncher',
            "Class-Path: {$loaderRel} {$intermediaryRel} libraries/org/ow2/asm/asm/9.7/asm-9.7.jar",
        ]);

        return [
            $serverJar => self::buildJarBytes(['META-INF/MANIFEST.MF' => $manifest]),
            $loaderRel => self::buildJarBytes(['fabric.mod.json' => json_encode(['id' => 'fabricloader', 'version' => $loaderVersion], JSON_THROW_ON_ERROR)]),
            $intermediaryRel => self::buildJarBytes(['mappings/mappings.tiny' => "tiny\t2\t0\tofficial\tintermediary\n"]),
            $vanillaJar => self::buildJarBytes(['version.json' => json_encode(['id' => $minecraft], JSON_THROW_ON_ERROR)]),
        ];
    }

    /**
     * Fabric MC 26.1+ (unobfuscated): launcher JAR referencing ONLY fabric-loader
     * (NO intermediary JAR exists in MC 26.1+ unobfuscated releases!).
     *
     * @return array<string, string>
     */
    public static function fabricUnobfuscated26(
        string $minecraft = '26.1.2',
        string $loaderVersion = '0.19.3',
        string $serverJar = 'server.jar',
        string $vanillaJar = 'minecraft-server.jar',
    ): array {
        $loaderRel = "libraries/net/fabricmc/fabric-loader/{$loaderVersion}/fabric-loader-{$loaderVersion}.jar";

        $manifest = self::foldManifest([
            'Manifest-Version: 1.0',
            'Main-Class: net.fabricmc.loader.impl.launch.server.FabricServerLauncher',
            "Class-Path: {$loaderRel} libraries/org/ow2/asm/asm/9.8/asm-9.8.jar",
        ]);

        return [
            $serverJar => self::buildJarBytes(['META-INF/MANIFEST.MF' => $manifest]),
            $loaderRel => self::buildJarBytes(['fabric.mod.json' => json_encode(['id' => 'fabricloader', 'version' => $loaderVersion], JSON_THROW_ON_ERROR)]),
            $vanillaJar => self::buildJarBytes(['version.json' => json_encode(['id' => $minecraft], JSON_THROW_ON_ERROR)]),
        ];
    }

    /**
     * Fabric server configured via fabric-server-launcher.properties `serverJar=custom-mc.jar`
     * (with NO `minecraft-server.jar` present on disk).
     *
     * @return array<string, string>
     */
    public static function fabricCustomServerJar(
        string $minecraft = '1.21.1',
        string $loaderVersion = '0.16.10',
        string $customServerJar = 'custom-mc.jar',
    ): array {
        $files = self::fabricObfuscated($minecraft, $loaderVersion, 'server.jar', $customServerJar);
        $files['fabric-server-launcher.properties'] = "# Fabric launcher configuration\nserverJar={$customServerJar}\n";

        return $files;
    }

    /**
     * Legacy shaded Fabric <= 0.12.5 with fabric.mod.json embedded inside server.jar.
     *
     * @return array<string, string>
     */
    public static function fabricShadedLegacy(string $loaderVersion = '0.12.5'): array
    {
        $manifest = self::foldManifest([
            'Manifest-Version: 1.0',
            'Main-Class: net.fabricmc.loader.launch.server.FabricServerLauncher',
        ]);

        return [
            'server.jar' => self::buildJarBytes([
                'META-INF/MANIFEST.MF' => $manifest,
                'fabric.mod.json' => json_encode(['id' => 'fabricloader', 'version' => $loaderVersion], JSON_THROW_ON_ERROR),
            ]),
            'minecraft-server.jar' => self::buildJarBytes(['version.json' => json_encode(['id' => '1.16.5'], JSON_THROW_ON_ERROR)]),
        ];
    }

    /**
     * Modern JPMS Forge (1.17+): unix_args.txt + Forge universal JAR.
     *
     * @return array<string, string>
     */
    public static function forgeModern(string $minecraft = '1.20.1', string $build = '47.4.20'): array
    {
        $jarRel = "libraries/net/minecraftforge/forge/{$minecraft}-{$build}/forge-{$minecraft}-{$build}-universal.jar";
        $args = "-Djava.net.preferIPv6Addresses=system\n-p libraries/cpw/mods/bootstraplauncher/1.1.2/bootstraplauncher-1.1.2.jar\n--add-modules ALL-MODULE-PATH\n-cp {$jarRel}\n--launchTarget forgeserver\n--fml.forgeVersion {$build}\n--fml.mcVersion {$minecraft}\n";

        return [
            'unix_args.txt' => $args,
            $jarRel => self::buildJarBytes(['META-INF/MANIFEST.MF' => "Manifest-Version: 1.0\r\n"]),
        ];
    }

    /**
     * Forge 1.16.5: server.jar with ServerLaunchArgs + Implementation-Version.
     *
     * @return array<string, string>
     */
    public static function forge1165(string $build = '36.2.42'): array
    {
        $manifest = self::foldManifest([
            'Manifest-Version: 1.0',
            'Main-Class: net.minecraftforge.server.ServerMain',
            "ServerLaunchArgs: --gameDir . --launchTarget fmlserver --fml.forgeVersion {$build} --fml.mcVersion 1.16.5",
            "Implementation-Version: {$build}",
        ]);

        return [
            'server.jar' => self::buildJarBytes(['META-INF/MANIFEST.MF' => $manifest]),
        ];
    }

    /**
     * Forge 1.12.2: server.jar with net.minecraftforge Main-Class & Implementation-Version.
     *
     * @return array<string, string>
     */
    public static function forge1122(string $build = '14.23.5.2860'): array
    {
        $manifest = self::foldManifest([
            'Manifest-Version: 1.0',
            'Main-Class: net.minecraftforge.fml.relauncher.ServerLaunchWrapper',
            "Implementation-Version: 1.12.2-{$build}",
        ]);

        return [
            'server.jar' => self::buildJarBytes(['META-INF/MANIFEST.MF' => $manifest]),
        ];
    }

    /**
     * NeoForge 1.20.1 (`libraries/net/neoforged/forge/1.20.1-<build>/...`).
     *
     * @return array<string, string>
     */
    public static function neoforge1201(string $build = '47.1.95'): array
    {
        $jarRel = "libraries/net/neoforged/forge/1.20.1-{$build}/forge-1.20.1-{$build}-universal.jar";
        $args = "-cp {$jarRel}\n--launchTarget forgeserver\n--fml.mcVersion 1.20.1\n";

        return [
            'unix_args.txt' => $args,
            $jarRel => self::buildJarBytes(['META-INF/MANIFEST.MF' => "Manifest-Version: 1.0\r\n"]),
        ];
    }

    /**
     * Modern NeoForge (1.21.x or 26.1.2): `libraries/net/neoforged/neoforge/<build>/...` + `--fml.mcVersion`.
     *
     * @return array<string, string>
     */
    public static function neoforgeModern(string $minecraft = '1.21.1', string $build = '21.1.266'): array
    {
        $jarRel = "libraries/net/neoforged/neoforge/{$build}/neoforge-{$build}-universal.jar";
        $args = "-Djava.net.preferIPv6Addresses=system\n-cp {$jarRel}\n--launchTarget forgeserver\n--fml.neoForgeVersion {$build}\n--fml.mcVersion {$minecraft}\n";

        return [
            'unix_args.txt' => $args,
            $jarRel => self::buildJarBytes(['META-INF/MANIFEST.MF' => "Manifest-Version: 1.0\r\n"]),
        ];
    }

    /**
     * Quilt launcher JAR with org.quiltmc Main-Class + quilt-loader JAR + quilt-server-launcher.properties.
     *
     * @return array<string, string>
     */
    public static function quilt(string $loaderVersion = '0.26.4', string $vanillaJar = 'minecraft.jar'): array
    {
        $loaderRel = "libraries/org/quiltmc/quilt-loader/{$loaderVersion}/quilt-loader-{$loaderVersion}.jar";
        $manifest = self::foldManifest([
            'Manifest-Version: 1.0',
            'Main-Class: org.quiltmc.loader.impl.launch.server.QuiltServerLauncher',
            "Class-Path: {$loaderRel} libraries/org/ow2/asm/asm/9.7/asm-9.7.jar",
        ]);

        return [
            'server.jar' => self::buildJarBytes([
                'META-INF/MANIFEST.MF' => $manifest,
                'quilt.mod.json' => json_encode(['quilt_loader' => ['id' => 'quilt_loader', 'version' => $loaderVersion]], JSON_THROW_ON_ERROR),
            ]),
            $loaderRel => self::buildJarBytes(['quilt.mod.json' => json_encode(['quilt_loader' => ['id' => 'quilt_loader', 'version' => $loaderVersion]], JSON_THROW_ON_ERROR)]),
            'quilt-server-launcher.properties' => "serverJar={$vanillaJar}\n",
            $vanillaJar => self::buildJarBytes(['version.json' => json_encode(['id' => '1.20.2'], JSON_THROW_ON_ERROR)]),
        ];
    }

    /**
     * Builds an authentic in-memory JAR/ZIP binary string containing $entries.
     *
     * @param  array<string, string>  $entries
     */
    public static function buildJarBytes(array $entries): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pmm-jar-');
        if ($tmp === false) {
            throw new RuntimeException('Unable to allocate temporary JAR file.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Unable to build JAR fixture at [{$tmp}].");
            }
            foreach ($entries as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();

            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new RuntimeException("Unable to read generated JAR fixture [{$tmp}].");
            }

            return $bytes;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Folds JAR MANIFEST.MF lines at 70 bytes with RFC "\r\n " continuations.
     *
     * @param  list<string>  $lines
     */
    private static function foldManifest(array $lines): string
    {
        $out = [];
        foreach ($lines as $line) {
            if (strlen($line) <= 70) {
                $out[] = $line;

                continue;
            }
            $chunks = [substr($line, 0, 70)];
            $rest = substr($line, 70);
            while ($rest !== '') {
                $chunks[] = ' '.substr($rest, 0, 69);
                $rest = substr($rest, 69);
            }
            $out[] = implode("\r\n", $chunks);
        }

        return implode("\r\n", $out)."\r\n\r\n";
    }
}
