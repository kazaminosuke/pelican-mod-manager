<?php

namespace Kazaminosuke\ModManager\Support\Compatibility;

enum CompatibilityDisposition: string
{
    /** The current egg can run this pack without an egg change. */
    case Compatible = 'compatible';

    /** The current egg is the right one, but its startup variables must change. */
    case Variables = 'variables';

    /** A different egg has to be selected before the pack can be provisioned. */
    case EggChange = 'egg_change';

    /** More than one egg is plausible, or the only match is not high confidence. */
    case Ambiguous = 'ambiguous';

    /** No safe egg or provisioning path exists. */
    case Unsupported = 'unsupported';
}
