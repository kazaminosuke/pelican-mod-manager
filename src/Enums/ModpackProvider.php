<?php

namespace Kazaminosuke\ModManager\Enums;

enum ModpackProvider: string
{
    case Modrinth = 'modrinth';
    case CurseForge = 'curseforge';
    case Ftb = 'ftb';
    case Technic = 'technic';
    case Atlauncher = 'atlauncher';

    public function getLabel(): string
    {
        return match ($this) {
            self::Modrinth => 'Modrinth',
            self::CurseForge => 'CurseForge',
            self::Ftb => 'FTB',
            self::Technic => 'Technic',
            self::Atlauncher => 'ATLauncher',
        };
    }
}
