<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\ModpackLoaderInstaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModpackLoaderInstallerTest extends TestCase
{
    #[DataProvider('loaders')]
    public function test_pins_official_installer_and_runtime(string $loader, string $minecraft, string $build, string $url, string $output, string $startup, int $java): void
    {
        $installer = ModpackLoaderInstaller::forRelease($loader, $minecraft, $build, 'server.jar');

        self::assertSame('ghcr.io/pelican-eggs/installers:java_'.$java, $installer['container_image']);
        self::assertSame('bash', $installer['entrypoint']);
        self::assertStringContainsString($url, $installer['script']);
        self::assertStringContainsString($output, $installer['script']);
        self::assertStringContainsString($startup, ModpackLoaderInstaller::startup($loader, $minecraft, 'server.jar'));
    }

    public static function loaders(): array
    {
        return [
            'Fabric' => ['fabric', '1.20.1', '0.15.11', 'https://maven.fabricmc.net/net/fabricmc/fabric-installer/1.1.2/', 'minecraft-server.jar', '-jar server.jar', 11],
            'Quilt' => ['quilt', '1.20.1', '0.20.2', 'https://maven.quiltmc.org/repository/release/org/quiltmc/quilt-installer/0.15.1/', 'quilt-server-launch.jar', '-jar server.jar', 21],
            'legacy Forge' => ['forge', '1.7.10', '10.13.4.1614', '1.7.10-10.13.4.1614-1.7.10-installer.jar', '-universal.jar', '-jar server.jar', 8],
            'modern Forge' => ['forge', '1.20.1', '47.4.10', 'https://maven.minecraftforge.net/net/minecraftforge/forge/1.20.1-47.4.10/', 'libraries/net/minecraftforge/forge/1.20.1-47.4.10/unix_args.txt', '@unix_args.txt', 17],
            'early NeoForge' => ['neoforge', '1.20.1', '47.1.106', 'https://maven.neoforged.net/releases/net/neoforged/forge/1.20.1-47.1.106/', 'libraries/net/neoforged/forge/1.20.1-47.1.106/unix_args.txt', '@unix_args.txt', 17],
            'NeoForge' => ['neoforge', '1.21.1', '21.1.266', 'https://maven.neoforged.net/releases/net/neoforged/neoforge/21.1.266/', 'libraries/net/neoforged/neoforge/21.1.266/unix_args.txt', '@unix_args.txt', 21],
        ];
    }

    public function test_rejects_unpinned_or_unsafe_runtime(): void
    {
        $this->expectException(ModpackException::class);
        ModpackLoaderInstaller::forRelease('fabric', '1.20.1', 'latest', 'server.jar');
    }

    public function test_rejects_unsafe_server_jar(): void
    {
        $this->expectException(ModpackException::class);
        ModpackLoaderInstaller::forRelease('fabric', '1.20.1', '0.15.11', '../server.jar');
    }
}
