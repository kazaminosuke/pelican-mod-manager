<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Container\Container;
use Kazaminosuke\ModManager\Exceptions\CurseForgeDependenciesPending;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\ModpackDeployment;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Support\Modpacks\CurseForgeFile;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use ZipArchive;

require_once __DIR__.'/ModpackDeploymentTest.php';

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

        $result = $this->deploy($installer, $wings, $this->server(), Mockery::mock(DaemonFileRepository::class), $zip, 'fabric', '1.20.1');

        self::assertSame(2, $result['counts']['add'] + $result['counts']['replace']);
        self::assertSame(3, $result['counts']['conflict'] + count($result['protected']) + count($result['warnings']));
        self::assertSame($wings->body, $wings->files['mods/example.jar']);
        self::assertSame('local', $wings->files['config/example.toml']);
        self::assertSame('new', $wings->files['config/new.toml']);
        self::assertArrayNotHasKey('server.properties', $wings->files);
        self::assertArrayNotHasKey('world/level.dat', $wings->files);
        self::assertSame([], $result['warnings']);
    }

    public function test_creation_job_rejects_a_manifest_for_the_wrong_minecraft_version(): void
    {
        $wings = new MemoryModpackWings();
        $installer = new ModpackInstaller($wings, Mockery::mock(CurseForgeSource::class));
        $zip = $this->pack([
            'modrinth.index.json' => json_encode([
                'formatVersion' => 1, 'game' => 'minecraft', 'dependencies' => ['minecraft' => '1.20.1'], 'files' => [],
            ], JSON_THROW_ON_ERROR),
            'overrides/config/new.toml' => 'new',
        ]);

        $this->expectException(ModpackException::class);
        $this->expectExceptionMessage('requires Minecraft [1.20.1]');
        try {
            $this->deploy($installer, $wings,
                $this->server(), Mockery::mock(DaemonFileRepository::class), $zip,
                serverMinecraftVersion: '1.21.1',
            );
        } finally {
            self::assertSame([], $wings->files);
        }
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
            $this->deploy($installer, $wings, $this->server(), Mockery::mock(DaemonFileRepository::class), $zip, 'fabric', '1.20.1');
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
            $this->deploy($installer, $wings, $this->server(), Mockery::mock(DaemonFileRepository::class), $zip, owned: ['mods/old.jar']);
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
        $this->expectExceptionMessage('is not an archive');

        try {
            $this->deploy($installer, $wings, $this->server(), Mockery::mock(DaemonFileRepository::class), $zip, owned: ['mods/example.jar']);
        } finally {
            self::assertSame('PK..real', $wings->files['mods/example.jar']);
        }
    }

    public function test_curseforge_modpack_resolves_dependencies_in_bulk_and_caches_override_directory_listings(): void
    {
        $wings = new MemoryModpackWings();
        $wings->files['config/existing.toml'] = 'keep';
        $curseForge = Mockery::mock(CurseForgeSource::class);
        $curseForge->shouldReceive('dependencyFiles')
            ->once()
            ->with([
                ['project_id' => 10, 'file_id' => 20],
                ['project_id' => 11, 'file_id' => 21],
            ])
            ->andReturn([
                '10/20' => new CurseForgeFile(10, 20, 'first.jar', 'https://edge.forgecdn.net/files/10/20/first.jar', strlen($wings->body)),
                '11/21' => new CurseForgeFile(11, 21, 'second.jar', 'https://edge.forgecdn.net/files/11/21/second.jar', strlen($wings->body)),
            ]);

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

        $result = $this->deploy($installer, $wings, $this->server(), Mockery::mock(DaemonFileRepository::class), $zip, 'forge', '1.20.1');

        self::assertSame(4, $result['counts']['add'] + $result['counts']['replace']);
        self::assertSame(1, $result['counts']['conflict'] + count($result['protected']) + count($result['warnings']));
        self::assertSame(2, $wings->listDirectoryCalls['config'] ?? 0);
        self::assertSame('keep', $wings->files['config/existing.toml']);
        self::assertSame('1', $wings->files['config/one.toml']);
        self::assertSame('2', $wings->files['config/two.toml']);
    }

    public function test_required_manual_files_are_aggregated_before_writes_and_resume_uses_verified_uploads(): void
    {
        $wings = new MemoryModpackWings();
        $path = $this->pack(['mod/First.class' => 'first']);
        $first = file_get_contents($path);
        $second = file_get_contents($this->pack(['mod/Second.class' => 'second']));
        $one = new CurseForgeFile(264231, 2693547, 'first.jar', size: strlen($first), hashes: ['sha1' => sha1($first)], reason: 'distribution');
        $two = new CurseForgeFile(238222, 3043174, 'second.jar', size: strlen($second), hashes: ['sha1' => sha1($second)], reason: 'distribution');
        $optional = new CurseForgeFile(229060, 2801262, 'optional.jar', reason: 'distribution');
        $curseForge = Mockery::mock(CurseForgeSource::class);
        $curseForge->shouldReceive('dependencyFiles')->andReturn([
            '264231/2693547' => $one, '238222/3043174' => $two, '229060/2801262' => $optional,
        ]);
        $installer = new ModpackInstaller($wings, $curseForge);
        $server = $this->server();
        $repo = Mockery::mock(DaemonFileRepository::class);
        $pack = $this->curseForgePack([
            ['projectID' => 264231, 'fileID' => 2693547, 'required' => true],
            ['projectID' => 238222, 'fileID' => 3043174, 'required' => true],
            ['projectID' => 238222, 'fileID' => 3043174, 'required' => true],
            ['projectID' => 229060, 'fileID' => 2801262, 'required' => false],
        ]);
        $wings->files['world/level.dat'] = 'preserve';
        $wings->files['config/keep.txt'] = 'preserve';

        try {
            $this->deploy($installer, $wings, $server, $repo, $pack);
            self::fail('The required uploads were absent.');
        } catch (CurseForgeDependenciesPending $exception) {
            self::assertSame([2693547, 3043174, 2801262], array_column($exception->files, 'file_id'));
            self::assertSame([true, true, false], array_column($exception->files, 'required'));
            self::assertSame(['missing_upload', 'missing_upload', 'unverifiable'], array_column($exception->files, 'reason'));
            self::assertSame(['world/level.dat', 'config/keep.txt'], array_keys($wings->files));
        }

        $wings->files[$one->stagingPath()] = $first;
        try {
            $this->deploy($installer, $wings, $server, $repo, $pack);
            self::fail('The second upload was absent.');
        } catch (CurseForgeDependenciesPending $exception) {
            self::assertSame([3043174, 2801262], array_column($exception->files, 'file_id'));
            self::assertArrayNotHasKey('mods/first.jar', $wings->files);
        }
        $wings->files[$two->stagingPath()] = $second;
        $result = $this->deploy($installer, $wings, $server, $repo, $pack);
        self::assertSame(2, $result['counts']['add'] + $result['counts']['replace']);
        self::assertSame($first, $wings->files['mods/first.jar']);
        self::assertSame($second, $wings->files['mods/second.jar']);
        self::assertSame('preserve', $wings->files['world/level.dat']);
        self::assertSame('preserve', $wings->files['config/keep.txt']);
        self::assertSame($first, $wings->files[$one->stagingPath()]);
        self::assertSame(3, $wings->listDirectoryCalls['.mod-manager-curseforge']);
        self::assertSame(2, count(array_filter(array_keys($wings->files), fn (string $name) => str_starts_with($name, 'mods/'))));
        self::assertContains('unavailable:229060/2801262:unverifiable', $result['warnings']);
    }

    #[DataProvider('rejectedUploads')]
    public function test_bad_required_uploads_leave_the_server_unchanged(string $kind, string $reason): void
    {
        $wings = new MemoryModpackWings();
        $valid = file_get_contents($this->pack(['mods/Main.class' => 'one']));
        $upload = match ($kind) {
            'html' => '<html>download failed</html>',
            'traversal' => file_get_contents($this->pack(['../Main.class' => 'one'])),
            'wrong-version' => file_get_contents($this->pack(['mods/Main.class' => 'two'])),
            default => $valid,
        };
        $expected = $kind === 'wrong-version' ? $valid : $upload;
        $file = new CurseForgeFile(264231, 2693547, 'iceandfire.jar', size: strlen($expected) + ($kind === 'wrong-size' ? 1 : 0), hashes: $kind === 'no-hash' ? [] : ['sha1' => sha1($expected)], reason: 'distribution');
        $forge = Mockery::mock(CurseForgeSource::class);
        $forge->shouldReceive('dependencyFiles')->once()->andReturn(['264231/2693547' => $file]);
        $wings->files = [$file->stagingPath() => $upload, 'mods/iceandfire.jar' => 'original'];
        $before = $wings->files;
        try {
            $this->deploy(new ModpackInstaller($wings, $forge), $wings, $this->server(), Mockery::mock(DaemonFileRepository::class), $this->curseForgePack([
                ['projectID' => 264231, 'fileID' => 2693547, 'required' => true],
            ]));
            self::fail('The upload should have been rejected.');
        } catch (CurseForgeDependenciesPending $exception) {
            self::assertSame($reason, $exception->files[0]['reason']);
            self::assertSame($before, $wings->files);
        }
    }

    public static function rejectedUploads(): array
    {
        return [['wrong-version', 'hash_mismatch'], ['html', 'invalid_jar'], ['traversal', 'invalid_jar'], ['wrong-size', 'size_mismatch'], ['no-hash', 'unverifiable']];
    }

    public function test_an_unavailable_optional_mod_does_not_block_an_automatic_required_mod(): void
    {
        $wings = new MemoryModpackWings();
        $forge = Mockery::mock(CurseForgeSource::class);
        $forge->shouldReceive('dependencyFiles')->once()->andReturn([
            '264231/2693547' => new CurseForgeFile(264231, 2693547, 'required.jar', 'https://edge.forgecdn.net/required.jar'),
            '238222/3043174' => new CurseForgeFile(238222, 3043174, 'optional.jar', reason: 'distribution'),
        ]);
        $result = $this->deploy(new ModpackInstaller($wings, $forge), $wings,
            $this->server(), Mockery::mock(DaemonFileRepository::class), $this->curseForgePack([
                ['projectID' => 264231, 'fileID' => 2693547, 'required' => true],
                ['projectID' => 238222, 'fileID' => 3043174, 'required' => false],
            ]),
        );

        self::assertSame(2, $result['counts']['add'] + $result['counts']['replace']);
        self::assertSame(1, $result['counts']['conflict'] + count($result['protected']) + count($result['warnings']));
        self::assertContains('unavailable:238222/3043174:unverifiable', $result['warnings']);
        self::assertArrayHasKey('mods/required.jar', $wings->files);
        self::assertArrayNotHasKey('mods/optional.jar', $wings->files);
    }

    public function test_failure_after_manual_upload_restores_the_original_and_keeps_the_staged_upload(): void
    {
        $wings = new MemoryModpackWings();
        $jar = file_get_contents($this->pack(['mod/Main.class' => 'verified']));
        $manual = new CurseForgeFile(264231, 2693547, 'first.jar', hashes: ['sha1' => sha1($jar)], reason: 'distribution');
        $later = new CurseForgeFile(238222, 3043174, 'second.jar', 'https://edge.forgecdn.net/error.jar');
        $forge = Mockery::mock(CurseForgeSource::class);
        $forge->shouldReceive('dependencyFiles')->once()->andReturn(['264231/2693547' => $manual, '238222/3043174' => $later]);
        $wings->files = ['mods/first.jar' => 'old', $manual->stagingPath() => $jar];
        $wings->failUrls = [$later->url];
        $pack = $this->curseForgePack([
            ['projectID' => 264231, 'fileID' => 2693547, 'required' => true],
            ['projectID' => 238222, 'fileID' => 3043174, 'required' => true],
        ]);
        $this->expectException(ModpackException::class);
        try {
            $this->deploy(new ModpackInstaller($wings, $forge), $wings, $this->server(), Mockery::mock(DaemonFileRepository::class), $pack, owned: ['mods/first.jar']);
        } finally {
            self::assertSame('old', $wings->files['mods/first.jar']);
            self::assertSame($jar, $wings->files[$manual->stagingPath()]);
            self::assertCount(2, $wings->files);
        }
    }

    private function curseForgePack(array $files): string
    {
        return $this->pack([
            'manifest.json' => json_encode([
                'manifestType' => 'minecraftModpack', 'manifestVersion' => 1,
                'name' => 'Dependency tests', 'version' => '2.9.3',
                'minecraft' => ['version' => '1.12.2', 'modLoaders' => [['id' => 'forge-14.23.5.2860', 'primary' => true]]],
                'files' => $files, 'overrides' => 'overrides',
            ], JSON_THROW_ON_ERROR),
            'overrides/config/keep.txt' => 'do not replace existing file',
        ]);
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

    public function test_bundled_server_pack_streams_mods_and_keeps_server_files(): void
    {
        $wings = new MemoryModpackWings();
        $wings->files['config/keep.toml'] = 'local';
        $zip = $this->pack([
            'ServerFiles/mods/example.jar' => $wings->body,
            'ServerFiles/local/kubejs/dev.json' => '{}',
            'ServerFiles/mods/README.txt' => 'notes',
            'ServerFiles/config/keep.toml' => 'pack',
            'ServerFiles/defaultconfigs/new.toml' => 'new',
            'ServerFiles/server.properties' => 'protected',
            'ServerFiles/world/level.dat' => 'world',
            'ServerFiles/start.sh' => '#!/bin/sh',
            'ServerFiles/server.jar' => 'bundled launcher',
        ]);
        $installer = new ModpackInstaller($wings, Mockery::mock(CurseForgeSource::class));
        $result = $this->deploy($installer, $wings, $this->server(), Mockery::mock(DaemonFileRepository::class), $zip, 'neoforge', '1.21.1', serverPack: true);

        self::assertSame(3, $result['counts']['add'] + $result['counts']['replace']);
        self::assertSame(1, $result['counts']['conflict'] + count($result['protected']) + count($result['warnings']));
        self::assertEquals([
            'config/keep.toml' => 'local',
            'mods/example.jar' => $wings->body,
            'local/kubejs/dev.json' => '{}',
            'defaultconfigs/new.toml' => 'new',
        ], $wings->files);
    }

    public function test_bundled_server_pack_rolls_back_when_a_later_jar_is_invalid(): void
    {
        $wings = new MemoryModpackWings();
        $wings->files['mods/local.jar'] = 'existing';
        $zip = $this->pack([
            'mods/example.jar' => $wings->body,
            'mods/bad.jar' => '<html>not a JAR</html>',
        ]);
        $installer = new ModpackInstaller($wings, Mockery::mock(CurseForgeSource::class));

        $this->expectException(ModpackException::class);
        $this->expectExceptionMessage('is not an archive');
        try {
            $this->deploy($installer, $wings, $this->server(), Mockery::mock(DaemonFileRepository::class), $zip, 'neoforge', '1.21.1', serverPack: true);
        } finally {
            self::assertSame(['mods/local.jar' => 'existing'], $wings->files);
        }
    }

    public function test_bundled_server_pack_rejects_unsafe_paths_before_writing(): void
    {
        $wings = new MemoryModpackWings();
        $zip = $this->pack(['mods/example.jar' => $wings->body, 'config/../../bad.toml' => 'unsafe']);
        $installer = new ModpackInstaller($wings, Mockery::mock(CurseForgeSource::class));

        $this->expectException(ModpackException::class);
        $this->expectExceptionMessage('unsafe file path');
        try {
            $this->deploy($installer, $wings, $this->server(), Mockery::mock(DaemonFileRepository::class), $zip, 'forge', null, serverPack: true);
        } finally {
            self::assertSame([], $wings->files);
        }
    }

    private function deploy(
        ModpackInstaller $installer, MemoryModpackWings $wings, Server $server, DaemonFileRepository $repo,
        string $zip, ?string $loader = null, ?string $serverMinecraftVersion = null, bool $serverPack = false,
        array $owned = [],
    ): array {
        $oldContainer = Container::getInstance();
        $container = new Container();
        $inventory = new DeploymentInventory();
        foreach ($owned as $path) {
            $inventory->data['inventory']['files'][$path] = ['sha256' => hash('sha256', $wings->files[$path]), 'identity' => null];
        }
        $container->instance(ModpackDeployment::class, new ModpackDeployment($wings, $inventory));
        Container::setInstance($container);
        try {
            $archive = new ZipArchive();
            $archive->open($zip, ZipArchive::RDONLY);
            $provider = $archive->locateName('modrinth.index.json') !== false ? 'modrinth' : 'curseforge';
            $archive->close();

            return $installer->deploy($server, $repo, $zip, [
                'provider' => $provider, 'pack_id' => 'test', 'version_id' => '2', 'server_pack' => $serverPack,
                'loader' => $loader, 'minecraft_version' => $serverMinecraftVersion,
            ], $loader, $serverMinecraftVersion, false, null, null, static fn () => null);
        } finally {
            Container::setInstance($oldContainer);
        }
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

    public string $body;

    public function __construct()
    {
        $temp = tmpfile();
        $name = stream_get_meta_data($temp)['uri'];
        $zip = new ZipArchive();
        $zip->open($name, ZipArchive::OVERWRITE);
        $zip->addFromString('mod/Main.class', 'test');
        $zip->close();
        $this->body = file_get_contents($name);
        fclose($temp);
    }

    public function pullForeground(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $url,
        string $directory,
        string $filename,
    ): array {
        if (in_array($url, $this->failUrls, true)) {
            throw new ModpackException('download failed');
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

    public function readStream(DaemonFileRepository $fileRepository, Server $server, string $path): StreamInterface
    {
        return Utils::streamFor($this->files[$path]);
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
            [$name, $nested] = array_pad(explode('/', $relative, 2), 2, null);
            if ($name === '') {
                continue;
            }
            $items[$name] = [
                'name' => $name, 'size' => strlen($contents), 'directory' => $nested !== null,
                'file' => $nested === null, 'symlink' => false,
            ];
        }

        return array_values($items);
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

    public function deleteManyQuietly(DaemonFileRepository $fileRepository, Server $server, string $directory, array $filenames): void
    {
        foreach ($filenames as $filename) {
            $this->delete($fileRepository, $server, $directory, $filename);
        }
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

    public function putStream(
        DaemonFileRepository $fileRepository,
        Server $server,
        string $path,
        StreamInterface $contents,
    ): void {
        $this->put($fileRepository, $server, $path, $contents->getContents());
    }

    private function path(string $directory, string $filename): string
    {
        $directory = trim($directory, '/');

        return $directory === '' ? $filename : $directory.'/'.$filename;
    }
}
