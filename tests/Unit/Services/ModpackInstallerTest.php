<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Mockery;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ModpackInstallerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_install_places_mods_keeps_existing_config_and_skips_protected_paths(): void
    {
        $wings = new MemoryModpackWings();
        $wings->files['config/example.toml'] = 'local';
        $installer = new ModpackInstaller($wings, Mockery::mock(CurseForgeSource::class));
        $zip = $this->pack([
            'modrinth.index.json' => json_encode([
                'formatVersion' => 1,
                'game' => 'minecraft',
                'versionId' => '1.0.0',
                'name' => 'Example',
                'dependencies' => ['minecraft' => '1.20.1', 'fabric-loader' => '0.15.0'],
                'files' => [[
                    'path' => 'mods/example.jar',
                    'downloads' => ['https://cdn.modrinth.com/example.jar'],
                    'env' => ['server' => 'required'],
                ]],
            ], JSON_THROW_ON_ERROR),
            'overrides/config/example.toml' => 'pack',
            'overrides/config/new.toml' => 'new',
            'overrides/server.properties' => 'pack-properties',
            'overrides/world/level.dat' => 'world',
        ]);

        $result = $installer->installFromZip($this->server(), Mockery::mock(DaemonFileRepository::class), $zip, 'fabric', '1.21.1');

        self::assertSame(2, $result->installed);
        self::assertSame(3, $result->skipped);
        self::assertSame("PK\x03\x04example", $wings->files['mods/example.jar']);
        self::assertSame('local', $wings->files['config/example.toml']);
        self::assertSame('new', $wings->files['config/new.toml']);
        self::assertArrayNotHasKey('server.properties', $wings->files);
        self::assertArrayNotHasKey('world/level.dat', $wings->files);
        self::assertContains('minecraft-version:1.20.1', $result->warnings);
    }

    public function test_loader_mismatch_writes_nothing(): void
    {
        $wings = new MemoryModpackWings();
        $installer = new ModpackInstaller($wings, Mockery::mock(CurseForgeSource::class));
        $zip = $this->pack([
            'modrinth.index.json' => json_encode([
                'formatVersion' => 1,
                'game' => 'minecraft',
                'versionId' => '1',
                'name' => 'Forge Pack',
                'dependencies' => ['forge' => '47.1.0'],
                'files' => [[
                    'path' => 'mods/example.jar',
                    'downloads' => ['https://cdn.modrinth.com/example.jar'],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            $installer->installFromZip($this->server(), Mockery::mock(DaemonFileRepository::class), $zip, 'fabric', '1.20.1');
            self::fail('A forge pack must not be installed on a fabric server.');
        } catch (ModpackException $exception) {
            self::assertStringContainsString('forge', $exception->getMessage());
        }

        self::assertSame([], $wings->files);
    }

    public function test_failed_later_download_removes_files_added_by_the_attempt_and_restores_replaced_mods(): void
    {
        $wings = new MemoryModpackWings();
        $wings->files['mods/old.jar'] = 'PK..old';
        $wings->failUrls[] = 'https://cdn.modrinth.com/second.jar';
        $installer = new ModpackInstaller($wings, Mockery::mock(CurseForgeSource::class));
        $zip = $this->pack([
            'modrinth.index.json' => json_encode([
                'formatVersion' => 1,
                'game' => 'minecraft',
                'versionId' => '2',
                'name' => 'Partial',
                'files' => [
                    [
                        'path' => 'mods/old.jar',
                        'downloads' => ['https://cdn.modrinth.com/old.jar'],
                    ],
                    [
                        'path' => 'mods/second.jar',
                        'downloads' => ['https://cdn.modrinth.com/second.jar'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            $installer->installFromZip($this->server(), Mockery::mock(DaemonFileRepository::class), $zip, null, null);
            self::fail('The second download should fail the install.');
        } catch (ModpackException) {
            self::assertSame('PK..old', $wings->files['mods/old.jar']);
            self::assertArrayNotHasKey('mods/second.jar', $wings->files);
            foreach (array_keys($wings->files) as $path) {
                self::assertStringNotContainsString('.mod-manager-', (string) $path);
            }
        }
    }

    public function test_html_download_does_not_replace_a_mod(): void
    {
        $wings = new MemoryModpackWings();
        $wings->files['mods/example.jar'] = 'PK..real';
        $wings->body = '<html>denied</html>';
        $installer = new ModpackInstaller($wings, Mockery::mock(CurseForgeSource::class));
        $zip = $this->pack([
            'modrinth.index.json' => json_encode([
                'formatVersion' => 1,
                'game' => 'minecraft',
                'versionId' => '1',
                'name' => 'HTML',
                'files' => [[
                    'path' => 'mods/example.jar',
                    'downloads' => ['https://cdn.modrinth.com/example.jar'],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(ModpackException::class);
        $this->expectExceptionMessage('not a valid ZIP or JAR');

        try {
            $installer->installFromZip($this->server(), Mockery::mock(DaemonFileRepository::class), $zip);
        } finally {
            self::assertSame('PK..real', $wings->files['mods/example.jar']);
        }
    }

    public function test_curseforge_modpack_resolves_dependencies_in_bulk_and_caches_override_directory_listings(): void
    {
        $wings = new MemoryModpackWings();
        $wings->files['config/existing.toml'] = 'keep';
        $curseForge = Mockery::mock(CurseForgeSource::class);
        $curseForge->shouldReceive('modpackDependencyDownloads')
            ->once()
            ->with([
                ['project_id' => 10, 'file_id' => 20],
                ['project_id' => 11, 'file_id' => 21],
            ])
            ->andReturn([
                '10/20' => [
                    'path' => 'mods/first.jar',
                    'url' => 'https://edge.forgecdn.net/files/10/20/first.jar',
                    'size' => strlen($wings->body),
                ],
                '11/21' => [
                    'path' => 'mods/second.jar',
                    'url' => 'https://edge.forgecdn.net/files/11/21/second.jar',
                    'size' => strlen($wings->body),
                ],
            ]);
        $curseForge->shouldNotReceive('modpackDependencyDownload');

        $installer = new ModpackInstaller($wings, $curseForge);
        $zip = $this->pack([
            'manifest.json' => json_encode([
                'manifestType' => 'minecraftModpack',
                'manifestVersion' => 1,
                'name' => 'CF Bulk Pack',
                'version' => '1.0',
                'minecraft' => [
                    'version' => '1.20.1',
                    'modLoaders' => [['id' => 'forge-47.2.0', 'primary' => true]],
                ],
                'files' => [
                    ['projectID' => 10, 'fileID' => 20, 'required' => true],
                    ['projectID' => 11, 'fileID' => 21, 'required' => true],
                ],
                'overrides' => 'overrides',
            ], JSON_THROW_ON_ERROR),
            'overrides/config/existing.toml' => 'replace',
            'overrides/config/one.toml' => '1',
            'overrides/config/two.toml' => '2',
        ]);

        $result = $installer->installFromZip($this->server(), Mockery::mock(DaemonFileRepository::class), $zip, 'forge', '1.20.1');

        self::assertSame(4, $result->installed);
        self::assertSame(1, $result->skipped);
        self::assertSame(1, $wings->listDirectoryCalls['config'] ?? 0);
        self::assertSame('keep', $wings->files['config/existing.toml']);
        self::assertSame('1', $wings->files['config/one.toml']);
        self::assertSame('2', $wings->files['config/two.toml']);
    }

    /** @param array<string, string> $entries */
    private function pack(array $entries): string
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mrpack');
        self::assertNotFalse($path);
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function server(): Server
    {
        $server = new Server();
        $server->forceFill(['id' => 7, 'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        return $server;
    }
}

final class MemoryModpackWings extends WingsRemoteFilesystem
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, int> */
    public array $listDirectoryCalls = [];

    /** @var list<string> */
    public array $failUrls = [];

    public string $body = "PK\x03\x04example";

    public function pullForeground(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $url,
        string $directory,
        string $filename,
    ): array {
        if (in_array($url, $this->failUrls, true)) {
            throw new Exception('download failed');
        }

        $path = $this->path($directory, $filename);
        $this->files[$path] = $this->body;

        return ['name' => $filename, 'size' => strlen($this->body)];
    }

    public function readPrefix(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $filename,
        int $bytes = 4,
    ): string {
        return substr($this->files[$this->path($directory, $filename)] ?? '', 0, $bytes);
    }

    public function listDirectory(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
    ): array {
        $directory = trim($directory, '/');
        $this->listDirectoryCalls[$directory] = ($this->listDirectoryCalls[$directory] ?? 0) + 1;
        $prefix = $directory === '' ? '' : $directory.'/';
        $items = [];

        foreach ($this->files as $path => $contents) {
            if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }
            $relative = $prefix === '' ? $path : substr($path, strlen($prefix));
            if ($relative === '' || str_contains($relative, '/')) {
                continue;
            }
            $items[] = [
                'name' => $relative,
                'size' => strlen($contents),
                'directory' => false,
            ];
        }

        return $items;
    }

    public function findListedFile(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $filename,
    ): ?array {
        $path = $this->path($directory, $filename);
        if (!array_key_exists($path, $this->files)) {
            return null;
        }

        return ['name' => $filename, 'size' => strlen($this->files[$path])];
    }

    public function move(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $from,
        string $to,
    ): void {
        $source = $this->path($directory, $from);
        if (!array_key_exists($source, $this->files)) {
            throw new Exception("missing {$source}");
        }

        $this->files[$this->path($directory, $to)] = $this->files[$source];
        unset($this->files[$source]);
    }

    public function delete(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $filename,
    ): void {
        unset($this->files[$this->path($directory, $filename)]);
    }

    public function deleteQuietly(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $directory,
        string $filename,
    ): void {
        unset($this->files[$this->path($directory, $filename)]);
    }

    public function put(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $path,
        string $contents,
    ): void {
        $this->files[ltrim($path, '/')] = $contents;
    }

    private function path(string $directory, string $filename): string
    {
        $directory = trim($directory, '/');

        return $directory === '' ? $filename : $directory.'/'.$filename;
    }
}
