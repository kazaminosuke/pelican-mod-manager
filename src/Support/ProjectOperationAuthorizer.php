<?php

namespace Kazaminosuke\ModManager\Support;

use App\Models\Server;
use App\Models\User;
use Kazaminosuke\ModManager\Enums\ProjectOperation;

final class ProjectOperationAuthorizer
{
    public function __construct(
        private readonly ?ServerModManagerSettings $settings = null,
    ) {}

    public function allows(?User $user, Server $server, ProjectOperation $operation): bool
    {
        if ($user === null) {
            return false;
        }

        // Do not use User::isAdmin(): Pelican reports true for any user with
        // any admin-role permission, which would bypass this operation-level
        // role control. Root Admin is the platform's unconditional admin.
        if ($user->isRootAdmin()) {
            return true;
        }

        if ($operation === ProjectOperation::Scan) {
            return $this->allowsScan($user, $server);
        }

        if ($user->can($operation->roleAbility())) {
            return true;
        }

        if (!$this->settings()->allowsProjectOperation($server, $operation)) {
            return false;
        }

        foreach ($operation->requiredFilePermissions() as $permission) {
            if (!$user->can($permission, $server)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scan is not gated by allow_user_project_* toggles. Those flags control
     * install/update/delete. Listing, hashing, and metadata writes follow
     * Panel file permissions, or any minecraftModManager role ability.
     */
    private function allowsScan(User $user, Server $server): bool
    {
        foreach ([ProjectOperation::Install, ProjectOperation::Update, ProjectOperation::Delete] as $operation) {
            if ($user->can($operation->roleAbility())) {
                return true;
            }
        }

        foreach (ProjectOperation::Scan->requiredFilePermissions() as $permission) {
            if (!$user->can($permission, $server)) {
                return false;
            }
        }

        return true;
    }

    private function settings(): ServerModManagerSettings
    {
        return $this->settings ?? app(ServerModManagerSettings::class);
    }
}
