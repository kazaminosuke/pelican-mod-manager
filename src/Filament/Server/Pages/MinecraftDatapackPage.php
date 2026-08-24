<?php

namespace Kazaminosuke\ModManager\Filament\Server\Pages;

use App\Models\Server;
use Filament\Facades\Filament;
use Kazaminosuke\ModManager\Enums\ProjectType;

class MinecraftDatapackPage extends ModManagerPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-file-zip';

    protected static ?string $slug = 'mod-manager-datapacks';

    public static function getNavigationSort(): ?int
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return static::navigationSortFor(ProjectType::Datapack, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('pelican-mod-manager::strings.minecraft_datapacks');
    }

    /** @return array<int, ProjectType> */
    protected static function enabledProjectTypesForAccess(): array
    {
        return [ProjectType::Datapack];
    }

    protected static function detectProjectType(Server $server): ?ProjectType
    {
        if (!ProjectType::supportsDatapacks($server)) {
            return null;
        }

        return ProjectType::Datapack;
    }

    /**
     * The Stage 8 manual-setup prompt (see ModManagerPage::canAccess())
     * appears only on the base mod/plugin manager page, never duplicated
     * here - resolving a manual profile there (or via auto-detection) is
     * what makes supportsDatapacks() above start returning true, which is
     * this page's own, sufficient trigger to appear.
     */
    protected static function needsManualEggSetup(Server $server): bool
    {
        return false;
    }
}
