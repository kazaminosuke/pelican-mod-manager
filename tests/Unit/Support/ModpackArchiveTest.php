<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackArchive;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackFileSelector;
use Kazaminosuke\ModManager\Support\Modpacks\ModpackManifest;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ModpackArchiveTest extends TestCase
{
    public function test_modrinth_index_skips_client_files_and_reads_overrides(): void
    {
        $path = $this->zip([
            'modrinth.index.json' => json_encode([
                'formatVersion' => 1,
                'game' => 'minecraft',
                'versionId' => '1.2.3',
                'name' => 'Example Pack',
                'dependencies' => [
                    'minecraft' => '1.20.1',
                    'fabric-loader' => '0.15.11',
                ],
                'files' => [
                    [
                        'path' => 'mods/server.jar',
                        'downloads' => ['https://cdn.modrinth.com/data/server.jar'],
                        'fileSize' => 12,
                        'env' => ['client' => 'required', 'server' => 'required'],
                    ],
                    [
                        'path' => 'mods/optional.jar',
                        'downloads' => ['https://cdn.modrinth.com/data/optional.jar'],
                        'env' => ['client' => 'required', 'server' => 'optional'],
                    ],
                    [
                        'path' => 'mods/client.jar',
                        'downloads' => ['https://cdn.modrinth.com/data/client.jar'],
                        'env' => ['client' => 'required', 'server' => 'unsupported'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'overrides/config/example.toml' => "hello\n",
            'client-overrides/config/client.toml' => "nope\n",
            'overrides/world/level.dat' => 'world',
        ]);

        $manifest = ModpackArchive::read($path);

        self::assertSame(ModpackManifest::FORMAT_MODRINTH, $manifest->format);
        self::assertSame('Example Pack', $manifest->name);
        self::assertSame('1.20.1', $manifest->minecraftVersion);
        self::assertSame('fabric', $manifest->loader);
        self::assertSame('0.15.11', $manifest->loaderVersion);
        self::assertSame(['mods/server.jar', 'mods/optional.jar'], array_map(
            static fn ($file) => $file->path,
            $manifest->remoteFiles,
        ));
        self::assertTrue($manifest->remoteFiles[0]->required);
        self::assertFalse($manifest->remoteFiles[1]->required);
        self::assertSame(['config/example.toml', 'world/level.dat'], array_map(
            static fn ($file) => $file->path,
            $manifest->overrides,
        ));
        self::assertSame(['client-only:mods/client.jar'], $manifest->skipped);
    }

    public function test_unsafe_modrinth_path_is_rejected(): void
    {
        $path = $this->zip([
            'modrinth.index.json' => json_encode([
                'formatVersion' => 1,
                'game' => 'minecraft',
                'versionId' => '1',
                'name' => 'Bad',
                'files' => [[
                    'path' => '../server.jar',
                    'downloads' => ['https://cdn.modrinth.com/data/server.jar'],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(ModpackException::class);
        $this->expectExceptionMessage('unsafe file path');

        ModpackArchive::read($path);
    }

    public function test_curseforge_manifest_keeps_project_file_ids_and_primary_loader(): void
    {
        $path = $this->zip([
            'manifest.json' => json_encode([
                'manifestType' => 'minecraftModpack',
                'manifestVersion' => 1,
                'name' => 'Forge Pack',
                'version' => '2.0',
                'minecraft' => [
                    'version' => '1.19.2',
                    'modLoaders' => [
                        ['id' => 'forge-43.2.0', 'primary' => true],
                    ],
                ],
                'files' => [
                    ['projectID' => 10, 'fileID' => 20, 'required' => true],
                    ['projectID' => 11, 'fileID' => 21, 'required' => false],
                ],
                'overrides' => 'overrides',
            ], JSON_THROW_ON_ERROR),
            'overrides/config/keep.toml' => 'cfg',
        ]);

        $manifest = ModpackArchive::read($path);

        self::assertSame(ModpackManifest::FORMAT_CURSEFORGE, $manifest->format);
        self::assertSame('forge', $manifest->loader);
        self::assertSame('43.2.0', $manifest->loaderVersion);
        self::assertSame(10, $manifest->remoteFiles[0]->projectId);
        self::assertSame(20, $manifest->remoteFiles[0]->fileId);
        self::assertNull($manifest->remoteFiles[0]->url);
        self::assertFalse($manifest->remoteFiles[1]->required);
        self::assertSame('config/keep.toml', $manifest->overrides[0]->path);
    }

    public function test_modrinth_selector_prefers_the_primary_mrpack(): void
    {
        $selected = ModpackFileSelector::modrinthMrpack([
            [
                'id' => 'old',
                'version_number' => '1.0.0',
                'files' => [[
                    'filename' => 'pack.mrpack',
                    'url' => 'https://cdn.modrinth.com/old.mrpack',
                    'primary' => true,
                ]],
            ],
        ]);

        self::assertSame('https://cdn.modrinth.com/old.mrpack', $selected['url']);
        self::assertSame('1.0.0', $selected['version_number']);
    }

    public function test_curseforge_selector_prefers_a_server_pack_over_a_newer_client_zip(): void
    {
        $selected = ModpackFileSelector::curseForgeServerPack([
            [
                'id' => 2,
                'fileName' => 'client.zip',
                'fileDate' => '2026-02-01T00:00:00Z',
                'downloadUrl' => 'https://edge.forgecdn.net/client.zip',
                'isServerPack' => false,
            ],
            [
                'id' => 1,
                'fileName' => 'server.zip',
                'fileDate' => '2026-01-01T00:00:00Z',
                'downloadUrl' => 'https://edge.forgecdn.net/server.zip',
                'isServerPack' => true,
            ],
        ]);

        self::assertSame(1, $selected['id']);
    }

    public function test_curseforge_selector_asks_for_a_server_pack_that_is_not_on_the_page(): void
    {
        $selected = ModpackFileSelector::curseForgeServerPack([
            [
                'id' => 5,
                'fileName' => 'client.zip',
                'fileDate' => '2026-02-01T00:00:00Z',
                'downloadUrl' => 'https://edge.forgecdn.net/client.zip',
                'serverPackFileId' => 99,
            ],
        ]);

        self::assertSame(99, $selected['id']);
        self::assertTrue($selected['lookup']);
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mrpack');
        self::assertNotFalse($path);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }
}
