<?php

namespace Kazaminosuke\ModManager\Http\Controllers;

use App\Http\Controllers\Api\Remote\Servers\ServerDetailsController;
use App\Models\Server;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kazaminosuke\ModManager\Services\ModpackProvisioning;

final class ModpackDaemonServerController extends ServerDetailsController
{
    public function __invoke(Request $request, Server $server): JsonResponse
    {
        $response = parent::__invoke($request, $server);
        if (Container::getInstance()->make(ModpackProvisioning::class)->needsInstaller($server)) {
            $data = $response->getData(true);
            // Allow Wings' temporary installer container. The separate
            // install endpoint supplies Mod Manager's script for this server.
            $data['settings']['skip_egg_scripts'] = false;
            $response->setData($data);
        }

        return $response;
    }

    public function resetState(Request $request): JsonResponse
    {
        return Container::getInstance()->make(ModpackProvisioning::class)->duringNodeReset(
            (int) $request->attributes->get('node')->getKey(),
            fn (): JsonResponse => parent::resetState($request),
        );
    }
}
