<?php

namespace Kazaminosuke\ModManager\Services;

use App\Enums\ContainerStatus;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Eggs\EggChangerService;
use App\Services\Servers\ReinstallServerService;
use BackedEnum;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Throwable;

/**
 * Switches an existing server to the egg a modpack requires.
 *
 * Worlds and other server files are not deleted. The previous egg, startup
 * command, image, and variable rows are restored if the panel update fails
 * before a reinstall has been accepted by Wings.
 */
final class ServerEggMigration
{
    /**
     * @param  array<string, string>  $variables
     */
    public function apply(
        Server $server,
        int|string $targetEggId,
        array $variables,
        ?string $image = null,
        bool $reinstall = false,
    ): void {
        $this->assertTransferable($server);
        $this->stopIfRunning($server);
        $snapshot = $this->snapshot($server);
        $reinstallStarted = false;

        try {
            if ((string) $server->egg_id !== (string) $targetEggId) {
                app(EggChangerService::class)->handle($server, (int) $targetEggId, true);
                $server->refresh();
            }

            $this->writeVariables($server, $variables);
            if (is_string($image) && $image !== '') {
                $server->forceFill(['image' => $image])->save();
            }

            // EggChangerService updates the panel only. Wings reads the synced
            // configuration for the next start and for the install script.
            app(DaemonServerRepository::class)->setServer($server->refresh())->sync();

            if ($reinstall) {
                app(ReinstallServerService::class)->handle($server->refresh());
                $reinstallStarted = true;
            }
        } catch (Throwable $exception) {
            if (!$reinstallStarted) {
                $this->restore($server, $snapshot);
            }

            if ($exception instanceof ModpackException) {
                throw $exception;
            }

            throw new ModpackException('The server egg could not be changed: '.$exception->getMessage(), 0, $exception);
        }
    }

    private function assertTransferable(Server $server): void
    {
        $status = $this->statusValue($server);
        if (in_array($status, ['installing', 'suspended', 'restoring_backup'], true)) {
            throw new ModpackException('This server cannot change eggs while its status is '.$status.'.');
        }
    }

    private function stopIfRunning(Server $server): void
    {
        $repository = app(DaemonServerRepository::class)->setServer($server);
        $state = (string) ($repository->getDetails()['state'] ?? '');
        if (!in_array($state, [
            ContainerStatus::Running->value,
            ContainerStatus::Starting->value,
            ContainerStatus::Restarting->value,
            ContainerStatus::Paused->value,
        ], true)) {
            return;
        }

        app(DaemonServerRepository::class)->setServer($server)->power('stop');
        $deadline = time() + 45;
        do {
            sleep(1);
            $state = (string) (app(DaemonServerRepository::class)->setServer($server)->getDetails()['state'] ?? '');
        } while (time() < $deadline && !in_array($state, [
            ContainerStatus::Offline->value,
            ContainerStatus::Exited->value,
            ContainerStatus::Dead->value,
            ContainerStatus::Missing->value,
        ], true));

        if (!in_array($state, [
            ContainerStatus::Offline->value,
            ContainerStatus::Exited->value,
            ContainerStatus::Dead->value,
            ContainerStatus::Missing->value,
        ], true)) {
            throw new ModpackException('The server did not stop, so its egg was not changed.');
        }
    }

    /**
     * @return array{egg_id: mixed, image: mixed, startup: mixed, status: mixed, variables: list<array{server_id: int, variable_id: int, variable_value: string}>}
     */
    private function snapshot(Server $server): array
    {
        $server->loadMissing('serverVariables');
        $variables = [];
        foreach ($server->serverVariables as $variable) {
            $variables[] = [
                'server_id' => (int) $server->getKey(),
                'variable_id' => (int) $variable->variable_id,
                'variable_value' => (string) $variable->variable_value,
            ];
        }

        return [
            'egg_id' => $server->egg_id,
            'image' => $server->image,
            'startup' => $server->startup,
            'status' => $server->status,
            'variables' => $variables,
        ];
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function writeVariables(Server $server, array $variables): void
    {
        $server->load('serverVariables.variable');
        foreach ($server->serverVariables as $serverVariable) {
            $name = (string) ($serverVariable->variable->env_variable ?? '');
            if ($name !== '' && array_key_exists($name, $variables)) {
                $serverVariable->variable_value = $variables[$name];
                $serverVariable->save();
            }
        }
    }

    /**
     * @param  array{egg_id: mixed, image: mixed, startup: mixed, status: mixed, variables: list<array{server_id: int, variable_id: int, variable_value: string}>}  $snapshot
     */
    private function restore(Server $server, array $snapshot): void
    {
        try {
            $server->forceFill([
                'egg_id' => $snapshot['egg_id'],
                'image' => $snapshot['image'],
                'startup' => $snapshot['startup'],
                'status' => $snapshot['status'],
            ])->save();
            ServerVariable::query()->where('server_id', $server->getKey())->delete();
            foreach ($snapshot['variables'] as $variable) {
                ServerVariable::query()->create($variable);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function statusValue(Server $server): string
    {
        $status = $server->status;

        return $status instanceof BackedEnum ? (string) $status->value : (string) ($status ?? '');
    }
}
