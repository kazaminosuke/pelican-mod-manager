<?php

namespace Kazaminosuke\ModManager\Filament\Server\Pages;

use App\Models\Server;
use Filament\Facades\Filament;
use Kazaminosuke\ModManager\Enums\ProjectType;

/**
 * Resource packs are a server-level Minecraft capability, not an egg
 * loader/type detection result. The per-server Mod Manager switch controls
 * whether this page is registered; the provider registry controls its source
 * tabs.
 */
class MinecraftResourcePackPage extends ModManagerPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-photo';

    protected static ?string $slug = 'mod-manager-resource-packs';

    public static function getNavigationSort(): ?int
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return static::navigationSortFor(ProjectType::ResourcePack, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('pelican-mod-manager::strings.minecraft_resource_packs');
    }

    /** @return array<int, ProjectType> */
    protected static function enabledProjectTypesForAccess(): array
    {
        return [ProjectType::ResourcePack];
    }

    protected static function detectProjectType(Server $server): ?ProjectType
    {
        return ProjectType::ResourcePack;
    }

    protected static function needsManualEggSetup(Server $server): bool
    {
        return false;
    }
}
