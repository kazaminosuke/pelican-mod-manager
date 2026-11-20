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

/** Checks the files produced by the loader Egg before Mod Manager deploys a pack. */
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
        $jpms = $loader === 'neoforge' || ($loader === 'forge' && version_compare($minecraft, '1.17', '>='));
        if ($jpms) {
            $argsStream = $this->read($repo, $server, 'unix_args.txt');
            try {
                $args = Utils::copyToString($argsStream, 65536);
            } finally {
                $argsStream->close();
            }
            $group = $loader === 'forge' ? 'minecraftforge/forge' : 'neoforged/(?:neoforge|forge)';
            if (!preg_match('~libraries/net/'.$group.'/([A-Za-z0-9.+_-]+)/[^\s:;]+\.jar~', $args, $match)
                || ($build !== null && !ModpackLoader::matchesBuild($loader, $build, $match[1], $minecraft))
                || (preg_match('/--fml\.mcVersion\s+(\S+)/', $args, $version) && $version[1] !== $minecraft)
                || ($startup !== '' && !str_contains($startup, '@unix_args.txt'))) {
                throw new ModpackException('The installed Forge or NeoForge startup arguments do not match this release.');
            }
            $this->requireJar($repo, $server, $match[0]);

            return;
        }

        if (!is_string($jar) || ModpackPaths::safeRelative($jar) !== $jar || str_contains($jar, '/')
            || ($startup !== '' && !str_contains($startup, '{{SERVER_JARFILE}}') && !str_contains($startup, '-jar '.$jar))) {
            throw new ModpackException('The Egg does not expose a verifiable startup JAR for this release.');
        }
        $entry = match ($loader) {
            'fabric' => 'fabric.mod.json',
            'quilt' => 'quilt.mod.json',
            default => null,
        };
        [$manifest, $embedded] = $this->launcherMetadata($repo, $server, $jar, $entry);
        if ($loader !== 'fabric') {
            $main = $loader === 'forge' ? 'net\.minecraftforge\.' : 'org\.quiltmc\.';
            if (!preg_match('/^Main-Class: '.$main.'\S+/m', $manifest)) {
                throw new ModpackException("The installed JAR does not contain a $loader launcher.");
            }

            if ($build !== null) {
                if ($loader === 'quilt') {
                    if (preg_match('~libraries/org/quiltmc/quilt-loader/([A-Za-z0-9.+_-]+)/quilt-loader-\1\.jar~', $manifest, $match)
                        && $match[1] === $build) {
                        $this->requireJar($repo, $server, $match[0]);
                    } elseif (($embedded['quilt_loader']['version'] ?? null) !== $build) {
                        throw new ModpackException('The installed Quilt launcher does not identify the selected loader build.');
                    }
                } elseif (!preg_match('/^Implementation-Version: (\S+)/m', $manifest, $version)
                    || !ModpackLoader::matchesBuild($loader, $build, $version[1], $minecraft)) {
                    throw new ModpackException('The legacy Forge launcher does not identify the selected loader build.');
                }
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

    private function requireJar(DaemonFileRepository $repo, Server $server, string $path): void
    {
        $stream = $this->read($repo, $server, $path);
        try {
            if (!ArchiveSignature::isZip(Utils::copyToString($stream, 4))) {
                throw new ModpackException("The loader Egg did not produce a readable JAR at [$path].");
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
            throw new ModpackException("Pelican marked the loader install complete, but [$path] could not be read. Check the Egg installation log.", 0, $error);
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
                $manifest = preg_replace('/\r?\n /', '', (string) $archive->getFromName('META-INF/MANIFEST.MF', 65536));
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
