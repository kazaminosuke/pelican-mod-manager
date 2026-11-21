<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\ModpackDeployment;
use Kazaminosuke\ModManager\Services\ModpackInstaller;
use Kazaminosuke\ModManager\Services\ModpackInventory;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Support\Modpacks\CurseForgeFile;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use ZipArchive;

final class ModpackDeploymentTest extends TestCase
{
    public function test_updates_only_owned_files_and_keeps_custom_world_and_changed_config(): void
    {
        $wings = new DeploymentWings();
        $store = new DeploymentInventory();
        $service = new ModpackDeployment($wings, $store);
        $server = new Server();
        $repo = $this->createStub(DaemonFileRepository::class);
        $wings->files = ['server.properties' => 'level-name=config/custom', 'config/custom/keep' => 'map', 'mods/personal.bin' => 'personal'];

        $old = $this->release('1');
        $new = $this->release('2');
        $service->run($server, $repo, $old, [
            'mods/old.bin' => $this->file('outdated'), 'mods/same.bin' => $this->file('stable'),
            'config/edit.toml' => $this->file('initial'), 'config/default.toml' => $this->file('v1'),
        ], null, false, null, static fn () => null);
        $wings->files['config/edit.toml'] = 'operator changed this';
        // The scanner identifies installed pack mods by hash, too.
        $wings->files['mods/.pelican-mod-manager.json'] = '{"installed_mods":[{"filename":"old.bin"}]}';
        $newFiles = [
            'mods/new.bin' => $this->file('replacement'), 'mods/same.bin' => $this->file('stable'),
            'config/edit.toml' => $this->file('new template'), 'config/default.toml' => $this->file('v2'),
            'config/custom/keep' => $this->file('wrong'),
        ];
        $preview = $service->run($server, $repo, $new, $newFiles, null, true, null, static fn () => null);
        self::assertSame(1, $preview['mods']['add']);
        self::assertSame(1, $preview['mods']['delete']);
        self::assertSame(['config/edit.toml'], $preview['conflicts']);
        self::assertSame(['config/custom/keep'], $preview['protected']);
        $wings->uploads = 0;
        $service->run($server, $repo, $new, $newFiles, $preview['digest'], false, 'test backup', static fn () => null);
        self::assertSame(2, $wings->uploads);
        self::assertSame('2', $store->data['inventory']['version_id']);
        self::assertArrayNotHasKey('mods/old.bin', $wings->files);
        self::assertSame('replacement', $wings->files['mods/new.bin']);
        self::assertSame('personal', $wings->files['mods/personal.bin']);
        self::assertSame('operator changed this', $wings->files['config/edit.toml']);
        self::assertSame('map', $wings->files['config/custom/keep']);
        self::assertSame('v2', $wings->files['config/default.toml']);
    }

    public function test_legacy_mod_conflicts_are_preserved_and_changed_preview_is_rejected(): void
    {
        $wings = new DeploymentWings();
        $store = new DeploymentInventory();
        $service = new ModpackDeployment($wings, $store);
        $server = new Server();
        $repo = $this->createStub(DaemonFileRepository::class);
        $target = ['mods/unowned.bin' => $this->file('pack')];
        $release = $this->release('2');
        $preview = $service->run($server, $repo, $release, $target, null, true, null, static fn () => null);
        $wings->files['mods/unowned.bin'] = 'untouched';
        try {
            $service->run($server, $repo, $release, $target, $preview['digest'], false, 'test backup', static fn () => null);
            self::fail('An approved preview must be rechecked.');
        } catch (ModpackException $error) {
            self::assertStringContainsString('changed since the preview', $error->getMessage());
        }
        $conflict = $service->run($server, $repo, $release, $target, null, true, null, static fn () => null);
        self::assertSame(['mods/unowned.bin'], $conflict['mod_conflicts']);
        self::assertSame('untouched', $wings->files['mods/unowned.bin']);
        self::assertSame(0, $wings->uploads);
    }

    public function test_explicit_catalog_install_is_protected_even_if_bytes_match_the_pack_inventory(): void
    {
        $wings = new DeploymentWings();
        $store = new DeploymentInventory();
        $service = new ModpackDeployment($wings, $store);
        $server = new Server();
        $repo = $this->createStub(DaemonFileRepository::class);
        $service->run($server, $repo, $this->release('1'), ['mods/same.bin' => $this->file('old')], null, false, null, static fn () => null);
        $wings->files['mods/.pelican-mod-manager.json'] = '{"installed_mods":[{"filename":"same.bin","managed_by":"catalog"}]}';
        $plan = $service->run($server, $repo, $this->release('2'), [], null, true, null, static fn () => null);
        self::assertSame(['mods/same.bin'], $plan['mod_conflicts']);
        self::assertSame(0, $plan['mods']['delete']);
    }

    public function test_later_activation_failure_rolls_back_staged_removals(): void
    {
        $wings = new DeploymentWings();
        $store = new DeploymentInventory();
        $service = new ModpackDeployment($wings, $store);
        $server = new Server();
        $repo = $this->createStub(DaemonFileRepository::class);
        $service->run($server, $repo, $this->release('1'), ['mods/a.bin' => $this->file('old')], null, false, null, static fn () => null);
        $wings->failMove = 'z.bin';
        try {
            $service->run($server, $repo, $this->release('2'), ['mods/z.bin' => $this->file('new')], null, false, 'test backup', static fn () => null);
            self::fail('Wings failure expected.');
        } catch (ModpackException $error) {
            self::assertSame('simulated move failure', $error->getMessage());
        }
        self::assertSame(['mods/a.bin' => 'old'], $wings->files);
        self::assertSame('1', $store->data['inventory']['version_id']);
        self::assertNull($store->data['recovery']);
    }

    public function test_crash_journal_can_only_be_cleared_after_restoration_is_verified(): void
    {
        $server = new Server();
        $repo = $this->createStub(DaemonFileRepository::class);
        $wings = new DeploymentWings();
        $store = new DeploymentInventory();
        $store->begin($server, null, 'token', 'backup', ['mods/old.bin' => hash('sha256', 'old'), 'mods/new.bin' => null]);
        $wings->files = ['mods/old.bin' => 'wrong', 'mods/new.bin' => 'unfinished update'];
        $service = new ModpackDeployment($wings, $store);
        try {
            $service->confirmRestored($server, $repo, 'token');
            self::fail('Incomplete restoration must be refused.');
        } catch (ModpackException $error) {
            self::assertStringContainsString('before clearing', $error->getMessage());
        }
        self::assertNotNull($store->data['recovery']);

        $wings->files = ['mods/old.bin' => 'old'];
        $service->confirmRestored($server, $repo, 'token');

        self::assertNull($store->data['recovery']);
        self::assertSame(['mods/old.bin' => 'old'], $wings->files);
    }

    #[DataProvider('formats')]
    public function test_managed_archive_formats_reconcile_version_two(string $format, bool $manual): void
    {
        $server = new Server();
        $repo = $this->createStub(DaemonFileRepository::class);
        $wings = new DeploymentWings();
        $store = new DeploymentInventory();
        $oldContainer = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $container->instance(ModpackDeployment::class, new ModpackDeployment($wings, $store));

        $jar = $this->zip(['fixture/Main.class' => 'test bytes']);
        $bytes = file_get_contents($jar);
        unlink($jar);
        $dependency = $this->createStub(CurseForgeSource::class);
        $dependency->method('dependencyFiles')->willReturnCallback(function (array $pairs) use ($manual, $bytes): array {
            $pair = $pairs[0];
            $id = $pair['file_id'];
            $name = 'mod-'.$id.'.jar';

            return ["1/$id" => new CurseForgeFile(1, $id, $name, $manual ? null : "https://files.example.test/$name",
                strlen($bytes), ['sha1' => sha1($bytes)], reason: $manual ? 'distribution' : null)];
        });
        $installer = new ModpackInstaller($wings, $dependency);
        try {
            foreach ([1, 2] as $number) {
                $name = "mod-$number.jar";
                $entries = match ($format) {
                    'modrinth' => ['modrinth.index.json' => json_encode([
                        'formatVersion' => 1, 'game' => 'minecraft', 'dependencies' => ['minecraft' => '1.20.1', 'fabric-loader' => '0.15.0'],
                        'files' => [['path' => "mods/$name", 'downloads' => ["https://files.example.test/$name"],
                            'fileSize' => strlen($bytes), 'hashes' => ['sha1' => sha1($bytes)]]],
                    ], JSON_THROW_ON_ERROR)],
                    'server' => ["wrapped/mods/$name" => $bytes, 'wrapped/config/default.toml' => "value=$number"],
                    default => ['manifest.json' => json_encode([
                        'manifestType' => 'minecraftModpack', 'minecraft' => ['version' => '1.20.1', 'modLoaders' => [['id' => 'fabric-0.15.0', 'primary' => true]]],
                        'files' => [['projectID' => 1, 'fileID' => $number, 'required' => true]],
                    ], JSON_THROW_ON_ERROR)],
                };
                $wings->downloadFiles["https://files.example.test/$name"] = $bytes;
                if ($manual) {
                    $wings->files[".mod-manager-curseforge/1-$number.jar"] = $bytes;
                }
                $path = $this->zip($entries);
                $release = [...$this->release((string) $number), 'loader' => 'fabric', 'minecraft_version' => '1.20.1',
                    'provider' => $format === 'modrinth' ? 'modrinth' : 'curseforge', 'server_pack' => $format === 'server'];
                try {
                    $args = [$server, $repo, $path, $release, 'fabric', '1.20.1'];
                    $preview = $installer->deploy(...[...$args, true, null, null, static fn () => null]);
                    $result = $installer->deploy(...[...$args, false, $preview['digest'], 'test backup', static fn () => null]);
                    self::assertSame(1, $result['mods']['add']);
                    self::assertSame($number - 1, $result['mods']['delete']);
                    self::assertSame($bytes, $wings->files["mods/$name"]);
                } finally {
                    unlink($path);
                }
            }
            self::assertArrayNotHasKey('mods/mod-1.jar', $wings->files);
            self::assertSame('2', $store->data['inventory']['version_id']);
            if ($manual || $format === 'curseforge') {
                self::assertSame('2', $store->data['inventory']['files']['mods/mod-2.jar']['file_id']);
            }
        } finally {
            Container::setInstance($oldContainer);
        }
    }

    public static function formats(): array
    {
        return [['modrinth', false], ['server', false], ['curseforge', false], ['curseforge', true]];
    }

    public function test_optional_file_that_could_not_be_resolved_is_not_obsolete(): void
    {
        $wings = new DeploymentWings();
        $store = new DeploymentInventory();
        $server = new Server();
        $repo = $this->createStub(DaemonFileRepository::class);
        $service = new ModpackDeployment($wings, $store);
        $service->run($server, $repo, $this->release('1'), [
            'mods/optional.bin' => array_replace($this->file('old'), ['project_id' => '32', 'file_id' => '1']),
        ], null, false, null, static fn () => null);
        $plan = $service->run($server, $repo, $this->release('2'), [], null, false, 'backup', static fn () => null,
            ['unavailable:32/2:api_forbidden']);
        self::assertSame(0, $plan['mods']['delete']);
        self::assertSame(1, $plan['mods']['preserve']);
        self::assertSame('old', $wings->files['mods/optional.bin']);
        self::assertSame('1', $store->data['inventory']['files']['mods/optional.bin']['file_id']);
    }

    public function test_mirror_fallback_recovers_from_wrong_primary_file_and_optional_failures_skip_cleanly(): void
    {
        $wings = new DeploymentWings();
        $store = new DeploymentInventory();
        $server = new Server();
        $repo = $this->createStub(DaemonFileRepository::class);
        $service = new ModpackDeployment($wings, $store);

        $expectedMod = "PK\x03\x04valid-rftools";
        $wrongMod = "PK\x03\x04wrong-l2artifacts";
        $primaryUrl = 'https://edge.forgecdn.net/files/4069/974/l2artifacts.jar';
        $mirrorUrl = 'https://files.feed-the-beast.com/blob/right.jar';
        $brokenOptionalUrl = 'https://cdn.example/missing-optional.jar';

        $wings->downloadFiles[$primaryUrl] = $wrongMod;
        $wings->downloadFiles[$mirrorUrl] = $expectedMod;
        $wings->missingDownload = $brokenOptionalUrl;
        $wings->downloadFiles[$brokenOptionalUrl] = "PK\x03\x04unused";

        $target = [
            'mods/rftools.jar' => [
                'identity' => hash('sha256', $expectedMod),
                'sha256' => hash('sha256', $expectedMod),
                'size' => strlen($expectedMod),
                'hashes' => ['sha1' => sha1($expectedMod), 'sha256' => hash('sha256', $expectedMod)],
                'file_id' => null,
                'project_id' => null,
                'open' => null,
                'url' => $primaryUrl,
                'fallback_urls' => [$mirrorUrl],
                'required' => true,
            ],
            'mods/optional.jar' => [
                'identity' => hash('sha256', 'opt'),
                'sha256' => hash('sha256', 'opt'),
                'size' => 10,
                'hashes' => ['sha1' => sha1('opt')],
                'file_id' => null,
                'project_id' => null,
                'open' => null,
                'url' => $brokenOptionalUrl,
                'fallback_urls' => [],
                'required' => false,
            ],
        ];

        $result = $service->run($server, $repo, $this->release('1'), $target, null, false, null, static fn () => null);
        self::assertSame($expectedMod, $wings->files['mods/rftools.jar']);
        self::assertArrayNotHasKey('mods/optional.jar', $wings->files);
        self::assertSame(['unavailable-optional:mods/optional.jar'], $result['warnings']);
        self::assertArrayHasKey('mods/rftools.jar', $store->data['inventory']['files']);
        self::assertArrayNotHasKey('mods/optional.jar', $store->data['inventory']['files']);
    }

    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'managed-modpack-test');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function file(string $contents): array
    {
        $hash = hash('sha256', $contents);

        return ['identity' => $hash, 'sha256' => $hash, 'size' => strlen($contents), 'hashes' => [],
            'file_id' => null, 'project_id' => null, 'open' => static fn () => Utils::streamFor($contents), 'url' => null];
    }

    private function release(string $version): array
    {
        return ['provider' => 'curseforge', 'pack_id' => '123', 'version_id' => $version];
    }
}

final class DeploymentInventory extends ModpackInventory
{
    public array $data = ['revision' => null, 'inventory' => null, 'recovery' => null];

    public function read(Server $server): array
    {
        return $this->data;
    }

    public function begin(Server $server, ?string $revision, string $token, ?string $backup, array $originals = [], bool $unlockBackup = false): void
    {
        $this->data['recovery'] = ['backup' => $backup, 'transaction' => $token, 'originals' => $originals, 'unlock_backup' => $unlockBackup];
    }

    public function finish(Server $server, string $token, array $inventory): void
    {
        $this->data = ['revision' => $token, 'inventory' => $inventory, 'recovery' => null];
    }

    public function abort(Server $server, string $token): void
    {
        $this->data['recovery'] = null;
    }
}

final class DeploymentWings extends WingsRemoteFilesystem
{
    public array $files = [];

    public array $downloadFiles = [];

    public ?string $missingDownload = null;

    public ?string $interruptedDownload = null;

    public function pullForeground(DaemonFileRepository $fileRepository, Server $server, string $url, string $directory, string $filename): array
    {
        $bytes = $this->downloadFiles[$url];
        if ($this->missingDownload === $url) {
            return ['name' => $filename, 'size' => strlen($bytes)];
        }
        if ($this->interruptedDownload === $url) {
            $this->files[$directory.'/'.$filename] = substr($bytes, 0, 4);
            throw new ConnectionException('simulated interrupted Wings pull');
        }
        $this->files[$directory.'/'.$filename] = $bytes;

        return ['name' => $filename, 'size' => strlen($bytes)];
    }

    public int $uploads = 0;

    public ?string $failMove = null;

    public function listDirectory(DaemonFileRepository $fileRepository, Server $server, string $directory): array
    {
        $prefix = $directory === '' ? '' : $directory.'/';
        $result = [];
        foreach ($this->files as $path => $contents) {
            if (!str_starts_with($path, $prefix)) {
                continue;
            }
            $parts = explode('/', substr($path, strlen($prefix)));
            $result[$parts[0]] = ['name' => $parts[0], 'file' => count($parts) === 1,
                'directory' => count($parts) > 1, 'symlink' => false, 'size' => strlen($contents)];
        }

        return array_values($result);
    }

    public function readStream(DaemonFileRepository $fileRepository, Server $server, string $path): StreamInterface
    {
        if (!isset($this->files[$path])) {
            throw new ModpackException("Missing file $path");
        }

        return Utils::streamFor($this->files[$path]);
    }

    public function put(DaemonFileRepository $fileRepository, Server $server, string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    public function putStream(DaemonFileRepository $fileRepository, Server $server, string $path, StreamInterface $contents): void
    {
        $this->uploads++;
        $this->files[$path] = $contents->getContents();
    }

    public function move(DaemonFileRepository $fileRepository, Server $server, string $directory, string $from, string $to): void
    {
        if ($this->failMove === $to) {
            throw new ModpackException('simulated move failure');
        }
        $this->files[$directory.'/'.$to] = $this->files[$directory.'/'.$from];
        unset($this->files[$directory.'/'.$from]);
    }

    public function delete(DaemonFileRepository $fileRepository, Server $server, string $directory, string $filename): void
    {
        unset($this->files[$directory.'/'.$filename]);
    }

    public function deleteManyQuietly(DaemonFileRepository $fileRepository, Server $server, string $directory, array $filenames): void
    {
        foreach ($filenames as $filename) {
            $this->delete($fileRepository, $server, $directory, $filename);
        }
    }

    public function deleteQuietly(DaemonFileRepository $fileRepository, Server $server, string $directory, string $filename): void
    {
        $this->delete($fileRepository, $server, $directory, $filename);
    }
}
