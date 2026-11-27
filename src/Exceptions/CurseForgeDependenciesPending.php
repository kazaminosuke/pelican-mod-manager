<?php

namespace Kazaminosuke\ModManager\Exceptions;

use Exception;

final class CurseForgeDependenciesPending extends Exception
{
    /** @param list<array<string, mixed>> $files */
    public function __construct(public readonly array $files)
    {
        parent::__construct('Required CurseForge dependencies are not ready.');
    }
}
