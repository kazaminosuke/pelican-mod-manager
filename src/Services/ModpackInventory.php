<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Server;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kazaminosuke\ModManager\Exceptions\ModpackException;

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

    public function begin(Server $server, ?string $revision, string $token, ?string $backup): void
    {
        DB::table('mod_manager_modpacks')->insertOrIgnore(['server_id' => $server->getKey()]);
        if ($this->row($server)->where('revision', $revision)->whereNull('mutation')->update([
            'mutation' => $token,
            'recovery' => json_encode(['transaction' => $token, 'backup' => $backup], JSON_THROW_ON_ERROR),
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

    private function row(Server $server): Builder
    {
        return DB::table('mod_manager_modpacks')->where('server_id', $server->getKey());
    }
}
