<?php

namespace Kazaminosuke\ModManager\Exceptions;

use Exception;

/**
 * Another managed file operation owns the server/type lease.
 *
 * This is normal duplicate prevention, not a failure: callers show the
 * "already running" notice instead of reporting an error.
 */
final class InstalledOperationBusyException extends Exception
{
    public function __construct()
    {
        parent::__construct('A managed file operation is already running.');
    }
}
