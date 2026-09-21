<?php

namespace Kazaminosuke\ModManager\Enums;

enum ProjectSourceKey: string
{
    case Modrinth = 'modrinth';
    case CurseForge = 'curseforge';
    case Hangar = 'hangar';
    case Spigot = 'spigot';
    case GitHubReleases = 'github_releases';

    public function getLabel(): string
    {
        return match ($this) {
            self::Modrinth => 'Modrinth',
            self::CurseForge => 'CurseForge',
            self::Hangar => 'Hangar',
            self::Spigot => 'Spigot',
            self::GitHubReleases => 'GitHub Releases',
        };
    }
}
