<?php

namespace Kazaminosuke\ModManager\Enums;

use App\Models\Server;
use Filament\Support\Contracts\HasLabel;
use Kazaminosuke\ModManager\Support\EggProfileResolver;

enum ProjectType: string implements HasLabel
{
    case Mod = 'mod';
    case Plugin = 'plugin';
    case Datapack = 'datapack';
    case ResourcePack = 'resourcepack';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mod => trans('pelican-mod-manager::strings.minecraft_mods'),
            self::Plugin => trans('pelican-mod-manager::strings.minecraft_plugins'),
            self::Datapack => trans('pelican-mod-manager::strings.minecraft_datapacks'),
            self::ResourcePack => trans('pelican-mod-manager::strings.minecraft_resource_packs'),
        };
    }

    public function getFolder(): string
    {
        return match ($this) {
            self::Mod => 'mods',
            self::Plugin => 'plugins',
            self::Datapack => 'world/datapacks',
            self::ResourcePack => 'resourcepacks',
        };
    }

    public function getFileExtension(): string
    {
        return match ($this) {
            self::Mod, self::Plugin => '.jar',
            self::Datapack, self::ResourcePack => '.zip',
        };
    }

    public function getLoaderSlug(Server $server): ?string
    {
        return match ($this) {
            self::Datapack => 'datapack',
            self::ResourcePack => null,
            default => MinecraftLoader::fromServer($server)?->value,
        };
    }

    /**
     * Explicit-only detection: the plugin's own opt-in convention (the
     * mod_manager/plugin_manager feature, or minecraft + mods/plugins).
     * This is byte-for-byte the pre-Stage-8 logic, extracted unchanged so
     * fromServer() can try it first and never alter behavior for an egg
     * that already carries one of these - see EggProfileResolver's own
     * docblock for why the fallback below never runs when this succeeds.
     *
     * Reads $server->egg->inherit_features rather than ->features (Stage 8
     * fix): Egg::getInheritFeaturesAttribute() falls back to the parent
     * egg's features when config_from is set and this egg defines none of
     * its own, which the raw ->features access this replaced could not see
     * - a child egg inheriting mod_manager/plugin_manager from its parent
     * was silently undetectable before this.
     */
    public static function fromServerExplicit(Server $server): ?ProjectType
    {
        $server->loadMissing('egg');

        $features = $server->egg->inherit_features ?? [];
        $tags = $server->egg->tags ?? [];

        if (in_array('plugin_manager', $features) || (in_array('minecraft', $tags) && in_array('plugins', $features))) {
            return self::Plugin;
        }

        if (in_array('mod_manager', $features) || (in_array('minecraft', $tags) && in_array('mods', $features))) {
            return self::Mod;
        }

        return null;
    }

    /**
     * Explicit detection first (unchanged pre-Stage-8 behavior - an egg
     * already carrying mod_manager/plugin_manager never consults the
     * profile database at all). Only when that finds nothing does this
     * fall back to EggProfileResolver, which recognizes the eggs the
     * panel's own installer offers (and their Pterodactyl-ecosystem
     * equivalents) without requiring any manual egg editing - see Stage 8's
     * design doc for why that fallback exists at all: 0 of the 54 official
     * Minecraft eggs carry mod_manager/plugin_manager/datapack_manager out
     * of the box.
     */
    public static function fromServer(Server $server): ?ProjectType
    {
        $explicit = self::fromServerExplicit($server);

        if ($explicit !== null) {
            return $explicit;
        }

        if (!(bool) config('pelican-mod-manager.egg_autodetect_enabled', true)) {
            return null;
        }

        $type = EggProfileResolver::resolve($server)->projectType;

        // Only 'mod'/'plugin' ever come back from this method (matching its
        // pre-Stage-8 contract - Datapack was never a possible result
        // here). A profile resolving to 'datapack' (Vanilla Minecraft: no
        // mod/plugin loader exists for it, only worlds) means "no
        // mod/plugin manager page for this server", not "show the datapack
        // type in the mod/plugin manager page" - MinecraftDatapackPage's
        // own detectProjectType() override is the one that surfaces it, via
        // supportsDatapacks() below, independent of this method.
        return in_array($type, ['mod', 'plugin'], true) ? self::from($type) : null;
    }

    /**
     * Explicit opt-out/opt-in first (unchanged pre-Stage-8 behavior for any
     * egg already carrying either flag), then the profile database's own
     * default for the resolved egg family. datapack_manager_disabled is new
     * in Stage 8: since the profile database's default is "Java server
     * egg -> datapacks supported" (a Minecraft Java world always accepts a
     * datapack, so requiring a dedicated feature flag mostly added friction
     * - see the design doc's rationale), this is the flag an operator who
     * wants the old opt-in-only behavior for one specific egg sets instead.
     */
    public static function supportsDatapacks(Server $server): bool
    {
        $server->loadMissing('egg');

        $features = $server->egg->inherit_features ?? [];

        if (in_array('datapack_manager_disabled', $features, true)) {
            return false;
        }

        if (in_array('datapack_manager', $features, true)) {
            return true;
        }

        if (!(bool) config('pelican-mod-manager.egg_autodetect_enabled', true)) {
            return false;
        }

        return EggProfileResolver::resolve($server)->supportsDatapacks;
    }
}
