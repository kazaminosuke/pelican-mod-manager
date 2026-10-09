<?php

namespace Kazaminosuke\ModManager\Support\Compatibility;

enum ProvisioningMode: string
{
    /** The egg install script downloads and installs the pack. */
    case EggInstall = 'egg_install';

    /** Mod Manager places the pack archive after the loader egg is in place. */
    case Archive = 'archive';

    /** Nothing can be provisioned. */
    case None = 'none';
}
