<?php

namespace Kazaminosuke\ModManager\Exceptions;

use Exception;
use Kazaminosuke\ModManager\Support\CurseForgeFile;

final class CurseForgeManualFileException extends Exception
{
    public function __construct(public readonly CurseForgeFile $dependency, public readonly string $reason)
    {
        parent::__construct("CurseForge file [{$dependency->projectId}/{$dependency->fileId}]: {$reason}.");
    }
}
