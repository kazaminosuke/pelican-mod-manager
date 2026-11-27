<?php

namespace Kazaminosuke\ModManager\Services;

use App\Models\Objects\DeploymentObject;
use App\Models\Server;
use App\Services\Servers\ServerCreationService;
use Closure;
use Illuminate\Container\Container;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Support\Compatibility\ProvisioningMode;
use ReflectionMethod;

final class CreateModpackServer extends ServerCreationService
{
    public static function canStageBeforeWings(): bool
    {
        $parameters = (new ReflectionMethod(ServerCreationService::class, 'handle'))->getParameters();

        return ($parameters[2] ?? null)?->getName() === 'beforeDaemon';
    }

    public function handle(array $data, ?DeploymentObject $deployment = null, ?Closure $beforeDaemon = null): Server
    {
        $plan = $data['mm_install_plan'] ?? null;
        if (!is_array($plan) || ($plan['mode'] ?? null) !== ProvisioningMode::Archive->value) {
            return $beforeDaemon === null ? parent::handle($data, $deployment) : parent::handle($data, $deployment, $beforeDaemon);
        }

        if ($beforeDaemon !== null || !self::canStageBeforeWings()) {
            throw new ModpackException('Managed Modpack creation requires a compatible Pelican Panel and a fresh server.');
        }

        $selection = $plan['selection'];
        $installer = ModpackLoaderInstaller::forRelease(
            $selection['loader'], $selection['minecraft'], $selection['loader_version'], $selection['variables']['SERVER_JARFILE'] ?? 'server.jar',
        );
        $data['skip_scripts'] = true;
        $data['start_on_completion'] = false;
        $data['startup'] = $selection['startup'];

        return parent::handle($data, $deployment, function (Server $server) use ($installer): void {
            Container::getInstance()->make(ModpackProvisioning::class)->stage($server, $installer);
        });
    }
}
