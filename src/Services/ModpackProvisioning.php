<?php

namespace Kazaminosuke\ModManager\Services;

use App\Enums\ServerState;
use App\Events\Server\Installed;
use App\Models\Server;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use stdClass;
use Throwable;

/** Keeps the Panel installation open until both the loader and pack have been verified. */
final class ModpackProvisioning
{
    public const WAITING = 'waiting';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    private const TABLE = 'mod_manager_provisioning';

    /** @param array{container_image: string, entrypoint: string, script: string} $installer */
    public function stage(Server $server, array $installer): void
    {
        DB::table(self::TABLE)->insert([
            'server_id' => $server->getKey(),
            'state' => self::WAITING,
            'installer' => json_encode($installer, JSON_THROW_ON_ERROR),
            'deadline' => now()->addMinutes(20),
        ]);
    }

    public function get(Server $server): ?stdClass
    {
        if (!Schema::hasTable(self::TABLE)) {
            return null;
        }
        $row = $this->row($server)->first();

        return !$server->skip_scripts && in_array($row?->state, [self::COMPLETE, self::FAILED], true) ? null : $row;
    }

    public function needsInstaller(Server $server): bool
    {
        return $server->status === ServerState::Installing && $server->skip_scripts
            && in_array($this->get($server)?->state, [self::WAITING, self::RUNNING], true);
    }

    public function acceptsManualFiles(Server $server): bool
    {
        return $server->status === ServerState::Installing && $server->skip_scripts
            && $this->get($server)?->state === self::READY;
    }

    /** @return array<string, string> */
    public function requestInstaller(Server $server): array
    {
        return DB::transaction(function () use ($server): array {
            $fresh = Server::query()->lockForUpdate()->findOrFail($server->getKey());
            $row = $this->row($fresh)->lockForUpdate()->first();
            if ($fresh->status !== ServerState::Installing || !$fresh->skip_scripts || $row === null
                || !in_array($row->state, [self::WAITING, self::RUNNING], true) || now()->gte($row->deadline)) {
                throw new ModpackException('The managed loader installation is no longer available.');
            }
            $this->row($fresh)->update(['state' => self::RUNNING]);

            return json_decode($row->installer, true, 16, JSON_THROW_ON_ERROR);
        });
    }

    public function loaderFinished(Server $server, bool $successful): void
    {
        DB::transaction(function () use ($server, $successful): void {
            $fresh = Server::query()->lockForUpdate()->findOrFail($server->getKey());
            $row = $this->row($fresh)->lockForUpdate()->first();
            if ($row === null || !in_array($row->state, [self::WAITING, self::RUNNING], true)) {
                return;
            }

            if ($successful && $row->state === self::RUNNING && $fresh->status === ServerState::Installing && $fresh->skip_scripts) {
                $this->row($fresh)->update(['state' => self::READY]);

                return;
            }

            $this->finish($fresh, false);
        });
    }

    public function finish(Server $server, bool $success): void
    {
        DB::transaction(function () use ($server, $success): void {
            $fresh = Server::query()->lockForUpdate()->find($server->getKey());
            $row = $fresh === null ? null : $this->row($fresh)->lockForUpdate()->first();
            if ($success && ($row?->state !== self::READY || !$fresh->skip_scripts || $fresh->status !== ServerState::Installing)) {
                throw new ModpackException('The server is no longer awaiting managed provisioning.');
            }
            if ($row === null || $row->state === self::COMPLETE) {
                return;
            }

            if ($row->state !== self::FAILED) {
                $this->row($fresh)->update(['state' => $success ? self::COMPLETE : self::FAILED, 'installer' => '']);
            }
            if ($fresh->status !== ServerState::Installing) {
                return;
            }

            $fresh->status = $success ? null : ServerState::InstallFailed;
            if ($success) {
                $fresh->installed_at = now();
            }
            $fresh->saveOrFail();

            DB::afterCommit(static function () use ($fresh, $success): void {
                try {
                    event(new Installed($fresh, $success, true));
                } catch (Throwable $error) {
                    report($error);
                }
            });
        });
    }

    /**
     * Wings clears Panel's Installing status on restart. Preserve it for packs
     * whose loader completed, and fail interrupted loader installations.
     */
    public function duringNodeReset(int $nodeId, callable $reset): mixed
    {
        if (!Schema::hasTable(self::TABLE)) {
            return $reset();
        }

        return DB::transaction(function () use ($nodeId, $reset) {
            $servers = Server::query()->where('node_id', $nodeId)->where('status', ServerState::Installing)
                ->whereIn('id', DB::table(self::TABLE)->select('server_id')->whereIn('state', [self::WAITING, self::RUNNING, self::READY]))
                ->lockForUpdate()->get();
            foreach ($servers as $server) {
                if ($this->row($server)->value('state') !== self::READY) {
                    $this->finish($server, false);
                }
            }

            $result = $reset();
            Server::query()->whereKey($servers->modelKeys())->whereNull('status')->update(['status' => ServerState::Installing]);

            return $result;
        });
    }

    private function row(Server $server): Builder
    {
        return DB::table(self::TABLE)->where('server_id', $server->getKey());
    }
}
