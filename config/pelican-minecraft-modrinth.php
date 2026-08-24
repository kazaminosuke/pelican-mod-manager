<?php

return [
    'latest_minecraft_version' => env('LATEST_MINECRAFT_VERSION', '26.1.2'),
    'navigation_sort' => [
        'mod' => env('MINECRAFT_MODRINTH_MOD_NAV_SORT', 11),
        'plugin' => env('MINECRAFT_MODRINTH_PLUGIN_NAV_SORT', 11),
        'datapack' => env('MINECRAFT_MODRINTH_DATAPACK_NAV_SORT', 12),
    ],
    'curseforge_api_key' => env('CURSEFORGE_API_KEY'),
    'github_token' => env('GITHUB_TOKEN'),
    // Local-only Performance Profiler + existing timing logs. Leave false
    // in production: the Mod Manager overlay and Livewire/image hooks are
    // not registered unless this is true.
    'debug_timing' => env('MOD_MANAGER_DEBUG_TIMING', false),

    // Operator kill switch for the catalog warm system (Stage 5): the
    // per-visit dispatch from ModManagerPage::mount() and the scheduled
    // mod-manager:warm-catalog command both check this before dispatching
    // anything.
    'warm_catalog_enabled' => env('MOD_MANAGER_WARM_CATALOG_ENABLED', true),

    // Upper bound on how many (loader, Minecraft version, project type)
    // combinations mod-manager:warm-catalog acts on per scheduled run,
    // prioritized by how many servers actually use each combination.
    'warm_max_targets' => env('MOD_MANAGER_WARM_MAX_TARGETS', 50),

    // Self-imposed per-minute request ceilings applied only to warm-job-
    // originated upstream requests (see Support\WarmRequestThrottle). A
    // source key mapping to null/omitted here falls back to
    // WarmRequestThrottle's own conservative defaults.
    //
    // Modrinth's 300 req/min public limit is officially documented; this
    // plugin self-limits warm traffic to 70% of it (210), the same margin
    // GDLauncher-Carbon uses for its background cache loop. GitHub's is
    // also officially documented (5,000 req/hr authenticated - warming is
    // skipped entirely for GitHub Releases when no token is configured,
    // since the unauthenticated 60 req/hr limit is too scarce to spend on
    // background warming), scaled the same way (~83/min x 70% = 55/min).
    // CurseForge and Hangar publish no rate limit as of this writing, so
    // those two are a conservative guess rather than a measured figure -
    // raise them here if warm traffic turns out to be unnecessarily slow
    // in practice.
    'warm_rate_limit' => [
        'modrinth' => env('MOD_MANAGER_WARM_RATE_LIMIT_MODRINTH', 210),
        'curseforge' => env('MOD_MANAGER_WARM_RATE_LIMIT_CURSEFORGE', 60),
        'hangar' => env('MOD_MANAGER_WARM_RATE_LIMIT_HANGAR', 30),
        'github_releases' => env('MOD_MANAGER_WARM_RATE_LIMIT_GITHUB_RELEASES', 55),
    ],

    // Stage 8: overall kill switch for egg auto-detection (Support\
    // EggProfileResolver). False reverts every one of the five wired
    // methods (ProjectType::fromServer()/supportsDatapacks(),
    // MinecraftLoader::fromServer(), MinecraftVersionResolver::resolve())
    // to exactly their pre-Stage-8 behavior - explicit egg features/tags/
    // variables only, no profile database, no manual-profile fallback.
    'egg_autodetect_enabled' => env('MOD_MANAGER_EGG_AUTODETECT', true),

    // Optional path to an operator-supplied JSON file in the same shape as
    // resources/egg-profiles.json (see Support\EggProfileRegistry), merged
    // in after the bundled profiles - for a private/community egg this
    // plugin doesn't ship a profile for.
    'egg_profiles_extra_path' => env('MOD_MANAGER_EGG_PROFILES_PATH'),

    // Stage 8's GUI fallback (a manual egg profile form shown when
    // auto-detection can't place an egg) is admin-only by default. Setting
    // this true also allows it for any user who holds
    // SubuserPermission::StartupUpdate on a server using that egg - the
    // same permission that already lets them edit that server's
    // MINECRAFT_VERSION/MC_VERSION startup variable, which is what egg
    // profile fields conceptually sit alongside. Saved profiles are keyed
    // by egg, not by server, so a save here can affect every other server
    // sharing that egg - the form warns about this when the toggle is on.
    'allow_user_egg_profile_edit' => env('MOD_MANAGER_ALLOW_USER_EGG_PROFILE_EDIT', false),

    // Project write operations remain root-admin and explicitly authorized
    // role-only by default. Each flag can additionally allow ordinary server
    // users who hold the matching native file permission(s): create for an
    // install, create+delete for an update, and delete for a removal.
    'allow_user_project_install' => env('MOD_MANAGER_ALLOW_USER_PROJECT_INSTALL', false),
    'allow_user_project_update' => env('MOD_MANAGER_ALLOW_USER_PROJECT_UPDATE', false),
    'allow_user_project_delete' => env('MOD_MANAGER_ALLOW_USER_PROJECT_DELETE', false),
];
