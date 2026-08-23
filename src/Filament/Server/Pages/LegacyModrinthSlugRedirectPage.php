<?php

namespace Kazaminosuke\ModManager\Filament\Server\Pages;

use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Support\ServerModManagerSettings;

/**
 * Kept at the plugin's original "modrinth" URL slug so links or bookmarks made
 * before the multi-source rename keep working, redirecting straight to
 * ModManagerPage (now at "mod-manager"). Hidden from
 * navigation - only reachable by visiting the old URL directly.
 */
class LegacyModrinthSlugRedirectPage extends Page
{
    protected static ?string $slug = 'modrinth';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $settings = app(ServerModManagerSettings::class);

        if (!$settings->isTypeEnabled($server, ProjectType::Mod)
            && !$settings->isTypeEnabled($server, ProjectType::Plugin)) {
            return false;
        }

        $type = ProjectType::fromServer($server);

        return parent::canAccess()
            && $settings->isTypeEnabled($server, $type ?? ProjectType::Mod)
            && $type !== null;
    }

    public function mount(): void
    {
        $this->redirect(ModManagerPage::getUrl());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
