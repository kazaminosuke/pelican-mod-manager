<?php

namespace Kazaminosuke\ModManager\Enums;

use App\Enums\SubuserPermission;

enum ProjectOperation: string
{
    case Install = 'install';
    case Update = 'update';
    case Delete = 'delete';
    case Scan = 'scan';

    public function roleAbility(): string
    {
        return match ($this) {
            self::Install => 'create minecraftModManager',
            self::Update => 'update minecraftModManager',
            self::Delete => 'delete minecraftModManager',
            // Scan is not a distinct role permission. Root admins and users
            // with any manager role ability are allowed in the authorizer.
            self::Scan => 'update minecraftModManager',
        };
    }

    public function allowsUserConfigKey(): string
    {
        return 'allow_user_project_'.$this->value;
    }

    /** @return array<int, SubuserPermission> */
    public function requiredFilePermissions(): array
    {
        return match ($this) {
            self::Install => [SubuserPermission::FileCreate],
            // Updating writes the new archive before deleting the old one.
            self::Update => [SubuserPermission::FileCreate, SubuserPermission::FileDelete],
            self::Delete => [SubuserPermission::FileDelete],
            // Listing the project directory, hashing file contents, and
            // writing installed metadata.
            self::Scan => [
                SubuserPermission::FileRead,
                SubuserPermission::FileReadContent,
                SubuserPermission::FileCreate,
            ],
        };
    }
}
