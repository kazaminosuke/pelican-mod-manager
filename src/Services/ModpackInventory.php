<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;

class ModpackInventory
{
    /** @return array{revision: ?string, inventory: ?array, recovery: ?array} */
    public function read(Server $server): array
    {
        $row = $this->row($server)->first();

        return [
            'revision' => $row?->revision,
            'inventory' => $row?->inventory === null ? null : json_decode($row->inventory, true, 512, JSON_THROW_ON_ERROR),
            'recovery' => $row?->recovery === null ? null : json_decode($row->recovery, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    public function begin(Server $server, ?string $revision, string $token, ?string $backup, array $originals = [], bool $unlockBackup = false): void
    {
        DB::table('mod_manager_modpacks')->insertOrIgnore(['server_id' => $server->getKey()]);
        if ($this->row($server)->where('revision', $revision)->whereNull('mutation')->update([
            'mutation' => $token,
            'recovery' => json_encode(['transaction' => $token, 'backup' => $backup, 'originals' => $originals, 'unlock_backup' => $unlockBackup], JSON_THROW_ON_ERROR),
        ]) !== 1) {
            throw new ModpackException('The modpack inventory changed or a previous transaction still requires recovery.');
        }
    }

    public function finish(Server $server, string $token, array $inventory): void
    {
        if ($this->row($server)->where('mutation', $token)->update([
            'revision' => $token,
            'inventory' => json_encode($inventory, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'mutation' => null,
            'recovery' => null,
        ]) !== 1) {
            throw new ModpackException('The modpack inventory could not be committed.');
        }
    }

    public function abort(Server $server, string $token): void
    {
        $this->row($server)->where('mutation', $token)->update(['mutation' => null, 'recovery' => null]);
    }

    public function legacy(Server $server, DaemonFileRepository $repo): ?array
    {
        return Cache::remember('modpack-legacy:'.$server->uuid, 300, function () use ($server, $repo) {
            $wings = app(WingsRemoteFilesystem::class);
            $path = '.mod-manager-modpack.json';
            $entry = $wings->findListedFile($repo, $server, '', $path);
            if ($entry === null || ($entry['symlink'] ?? true) !== false || ($wings->listedFileSize($entry) ?? PHP_INT_MAX) > 8192) {
                return [];
            }
            $stream = $wings->readStream($repo, $server, $path);
            try {
                $value = json_decode($stream->read(8193), true);

                return is_array($value) && is_string($value['provider'] ?? null) && is_string($value['pack_id'] ?? null)
                    ? $value : [];
            } finally {
                $stream->close();
            }
        }) ?: null;
    }

    private function row(Server $server): Builder
    {
        return DB::table('mod_manager_modpacks')->where('server_id', $server->getKey());
    }
}
