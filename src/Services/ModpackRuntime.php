<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use GuzzleHttp\Psr7\Utils;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Support\ArchiveSignature;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackLoader;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackPaths;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Psr\Http\Message\StreamInterface;
use ZipArchive;

/** Checks the runtime before Mod Manager deploys a pack. */
final class ModpackRuntime
{
    public function __construct(private readonly WingsRemoteFilesystem $wings) {}

    public function assertInstalled(Server $server, DaemonFileRepository $repo, string $loader, string $minecraft, ?string $build, array $variables): void
    {
        if ($build === null) {
            $configured = $variables[ModpackLoader::buildVariable($loader)] ?? null;
            $build = is_string($configured) && preg_match('/^[0-9][A-Za-z0-9.+_-]{0,95}$/D', $configured) === 1
                ? $configured : null;
        }

        $jar = $variables['SERVER_JARFILE'] ?? 'server.jar';
        $startup = (string) ($server->startup ?? '');
        if (ModpackLoader::usesArgumentFile($loader, $minecraft)) {
            $this->assertForgeArgumentFile($server, $repo, $loader, $minecraft, $build, $startup);

            return;
        }

        if (!is_string($jar) || ModpackPaths::safeRelative($jar) !== $jar || str_contains($jar, '/')
            || ($startup !== '' && !str_contains($startup, '{{SERVER_JARFILE}}') && !str_contains($startup, '-jar '.$jar))) {
            throw new ModpackException('The Egg does not expose a verifiable startup JAR for this release.');
        }
        $entry = match ($loader) {
            'fabric' => 'fabric.mod.json',
            'quilt' => 'quilt.mod.json',
            'forge' => 'version.json',
            default => null,
        };
        [$manifest, $embedded] = $this->launcherMetadata($repo, $server, $jar, $entry);
        if ($loader !== 'fabric') {
            $main = $loader === 'forge'
                ? '(?:net\.minecraftforge\.|cpw\.mods\.fml\.relauncher\.)'
                : 'org\.quiltmc\.';
            if (!preg_match('/^Main-Class: '.$main.'\S+/m', $manifest)) {
                throw new ModpackException("The installed JAR does not contain a $loader launcher.");
            }

            if ($loader === 'quilt') {
                $this->assertQuiltLauncher($server, $repo, $manifest, $embedded, $minecraft, $build);
            } elseif ($build !== null && !$this->matchesLegacyForgeBuild($manifest, $embedded, $build, $minecraft)) {
                throw new ModpackException('The legacy Forge launcher does not identify the selected loader build.');
            }

            return;
        }

        if (!preg_match('/^Main-Class: net\.fabricmc\.loader\.(?:impl\.)?launch\.server\.FabricServerLauncher\s*$/m', $manifest)) {
            throw new ModpackException('The installed JAR is missing the Fabric server launcher.');
        }

        // Fabric Installer shades very old loader builds into the launcher.
        if ($build !== null && version_compare($build, '0.12.5', '<=')) {
            if (($embedded['id'] ?? null) !== 'fabricloader' || ($embedded['version'] ?? null) !== $build) {
                throw new ModpackException('The shaded Fabric launcher does not identify the selected loader build.');
            }
            $this->requireJar($repo, $server, 'minecraft-server.jar');

            return;
        }
        if (!preg_match('~libraries/net/fabricmc/fabric-loader/([A-Za-z0-9.+_-]+)/fabric-loader-\1\.jar~', $manifest, $match)
            || ($build !== null && $match[1] !== $build)
            || !str_contains($manifest, "libraries/net/fabricmc/intermediary/$minecraft/intermediary-$minecraft.jar")) {
            throw new ModpackException('The installed Fabric launcher does not reference this Minecraft and loader build.');
        }
        $this->requireJar($repo, $server, $match[0]);
        $this->requireJar($repo, $server, "libraries/net/fabricmc/intermediary/$minecraft/intermediary-$minecraft.jar");
        $this->requireJar($repo, $server, 'minecraft-server.jar');
    }

    private function assertForgeArgumentFile(Server $server, DaemonFileRepository $repo, string $loader, string $minecraft, ?string $build, string $startup): void
    {
        $mavenPath = 'libraries/'.ModpackLoader::forgeMavenPath($loader, $minecraft);
        $pathPattern = preg_quote($mavenPath, '~');
        $argsPath = 'unix_args.txt';
        if ($startup !== '') {
            if (!preg_match('~@((?:'.$pathPattern.'/[A-Za-z0-9.+_-]+/)?unix_args\.txt)(?=[\s\x22\x27]|$)~', $startup, $path)) {
                throw new ModpackException('The server startup command does not use a supported Forge or NeoForge argument file.');
            }
            $argsPath = $path[1];
        }
        $stream = $this->read($repo, $server, $argsPath);
        try {
            $args = Utils::copyToString($stream, 65536);
        } finally {
            $stream->close();
        }

        if (preg_match('/--fml\.mcVersion[ \t]+(\S+)/', $args, $version) && $version[1] !== $minecraft) {
            throw new ModpackException('The installed Forge or NeoForge startup arguments do not match this release.');
        }

        // Official Forge and NeoForge args identify the build with FML flags.
        // The patched server JAR is loaded by FML and is absent from the classpath.
        $artifact = basename($mavenPath);
        $versionFlag = $artifact === 'neoforge' ? 'neoForgeVersion' : 'forgeVersion';
        if (preg_match('/--fml\.'.$versionFlag.'[ \t]+([A-Za-z0-9.+_-]+)/', $args, $match)) {
            if (($build !== null && !ModpackLoader::matchesBuild($loader, $build, $match[1], $minecraft))
                || ($artifact === 'neoforge' && !isset($version[1]))) {
                throw new ModpackException('The installed Forge or NeoForge startup arguments do not match this release.');
            }
            $coordinate = ModpackLoader::pinBuild($loader, $match[1], $minecraft);
            $this->requireJar($repo, $server, "$mavenPath/$coordinate/$artifact-$coordinate-server.jar");

            return;
        }

        if (!preg_match('~'.$pathPattern.'/([A-Za-z0-9.+_-]+)/[^\s:;]+\.jar~', $args, $match)
            || ($build !== null && !ModpackLoader::matchesBuild($loader, $build, $match[1], $minecraft))) {
            throw new ModpackException('The installed Forge or NeoForge startup arguments do not match this release.');
        }
        $this->requireJar($repo, $server, $match[0]);
    }

    private function assertQuiltLauncher(Server $server, DaemonFileRepository $repo, string $manifest, array $embedded, string $minecraft, ?string $build): void
    {
        if (preg_match('~libraries/org/quiltmc/quilt-loader/([A-Za-z0-9.+_-]+)/quilt-loader-\1\.jar~', $manifest, $match)) {
            $mappings = "libraries/net/fabricmc/intermediary/$minecraft/intermediary-$minecraft.jar";
            if (!str_contains($manifest, $mappings)) {
                $mappings = "libraries/org/quiltmc/hashed/$minecraft/hashed-$minecraft.jar";
            }
            if (($build !== null && $match[1] !== $build) || !str_contains($manifest, $mappings)) {
                throw new ModpackException('The installed Quilt launcher does not reference this Minecraft and loader build.');
            }
            $this->requireJar($repo, $server, $match[0]);
            $this->requireJar($repo, $server, $mappings);
        } elseif ($build !== null && ($embedded['quilt_loader']['version'] ?? null) !== $build) {
            throw new ModpackException('The installed Quilt launcher does not identify the selected loader build.');
        }
    }

    private function matchesLegacyForgeBuild(string $manifest, array $versionJson, string $build, string $minecraft): bool
    {
        $candidates = [];
        if (preg_match('/^ServerLaunchArgs:[^\r\n]*--fml\.forgeVersion\s+(\S+)/m', $manifest, $match) === 1) {
            $candidates[] = $match[1];
        }
        if (preg_match_all('/^Implementation-Version: (\S+)/m', $manifest, $versions) > 0) {
            foreach ($versions[1] as $candidate) {
                $candidates[] = $candidate;
            }
        }
        $versionId = $versionJson['id'] ?? null;
        if (is_string($versionId) && preg_match('/Forge([A-Za-z0-9.+_-]+)/i', $versionId, $match) === 1) {
            $candidates[] = $match[1];
        }

        foreach (array_unique($candidates) as $candidate) {
            if (ModpackLoader::matchesBuild('forge', $build, $candidate, $minecraft)) {
                return true;
            }
        }

        return false;
    }

    private function requireJar(DaemonFileRepository $repo, Server $server, string $path): void
    {
        $stream = $this->read($repo, $server, $path);
        try {
            if (!ArchiveSignature::isZip(Utils::copyToString($stream, 4))) {
                throw new ModpackException("The loader did not produce a readable JAR at [$path].");
            }
        } finally {
            $stream->close();
        }
    }

    private function read(DaemonFileRepository $repo, Server $server, string $path): StreamInterface
    {
        try {
            return $this->wings->readStream($repo, $server, $path);
        } catch (Exception $error) {
            throw new ModpackException("The runtime file [$path] could not be read. Check the server installation log in Pelican.", 0, $error);
        }
    }

    /** @return array{string, array} */
    private function launcherMetadata(DaemonFileRepository $repo, Server $server, string $jar, ?string $entry): array
    {
        $file = tmpfile();
        if ($file === false) {
            throw new ModpackException('Cannot prepare loader verification.');
        }
        $sink = Utils::streamFor($file);
        try {
            $stream = $this->read($repo, $server, $jar);
            try {
                if (Utils::copyToStream($stream, $sink, 16 * 1024 ** 2 + 1) > 16 * 1024 ** 2) {
                    throw new ModpackException('The loader launcher exceeds the verification size limit.');
                }
            } finally {
                $stream->close();
            }
            $archive = new ZipArchive();
            if ($archive->open(stream_get_meta_data($file)['uri'], ZipArchive::RDONLY) !== true) {
                throw new ModpackException('The installed launcher is not a valid JAR.');
            }
            try {
                // Signed legacy Forge JAR manifests can exceed 64 KB.
                $manifest = preg_replace('/\r?\n /', '', (string) $archive->getFromName('META-INF/MANIFEST.MF', 512 * 1024));
                $loader = $entry === null ? null : json_decode((string) $archive->getFromName($entry, 65536), true);

                return [$manifest, is_array($loader) ? $loader : []];
            } finally {
                $archive->close();
            }
        } finally {
            $sink->close();
        }
    }
}
