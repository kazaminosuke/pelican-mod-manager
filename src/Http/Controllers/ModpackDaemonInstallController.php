<?php

namespace Kazaminosuke\ModManager\Http\Controllers;

use App\Exceptions\Http\HttpForbiddenException;
use App\Http\Controllers\Api\Remote\Servers\ServerInstallController;
use App\Http\Requests\Api\Remote\InstallationDataRequest;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kazaminosuke\ModManager\Exceptions\ModpackException;
use Kazaminosuke\ModManager\Services\ModpackProvisioning;

final class ModpackDaemonInstallController extends ServerInstallController
{
    public function __construct(private readonly ModpackProvisioning $provisioning) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        if ($this->provisioning->get($server) === null) {
            return parent::index($request, $server);
        }

        throw_unless($server->node->is($request->attributes->get('node')), new HttpForbiddenException('The requesting node cannot access this server.'));
        try {
            return new JsonResponse($this->provisioning->requestInstaller($server));
        } catch (ModpackException $error) {
            return new JsonResponse(['error' => $error->getMessage()], 409);
        }
    }

    public function store(InstallationDataRequest $request, Server $server): JsonResponse
    {
        $state = $this->provisioning->get($server)?->state;
        if ($state === null || ($state === ModpackProvisioning::COMPLETE && $request->boolean('reinstall'))) {
            return parent::store($request, $server);
        }

        throw_unless($server->node->is($request->attributes->get('node')), new HttpForbiddenException('The requesting node cannot access this server.'));
        if ($state === ModpackProvisioning::FAILED && $request->boolean('reinstall')) {
            $this->provisioning->finish($server, false);
        } else {
            $this->provisioning->loaderFinished($server, !$request->boolean('reinstall') && $request->boolean('successful'));
        }

        return new JsonResponse(null, 204);
    }
}
