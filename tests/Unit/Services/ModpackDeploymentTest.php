<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use GuzzleHttp\Psr7\Utils;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\ModpackDeployment;
use Kazaminosuke\ModManager\Services\ModpackInventory;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

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

    public function begin(Server $server, ?string $revision, string $token, ?string $backup): void
    {
        $this->data['recovery'] = ['backup' => $backup, 'token' => $token];
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

    public function deleteQuietly(DaemonFileRepository $fileRepository, Server $server, string $directory, string $filename): void
    {
        $this->delete($fileRepository, $server, $directory, $filename);
    }
}
