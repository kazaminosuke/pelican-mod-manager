# Changelog

This file records the user-facing changes included in each release.

## [Unreleased]

## [0.1.0] - 2026-08-27

### Added

- Initial public release of the Pelican Panel Minecraft Mod Manager plugin, with catalog and installed-project pages for searching, installing, updating, and removing Minecraft mods, plugins, datapacks, and resource packs.
- Source integrations for Modrinth, CurseForge, Hangar, and GitHub Releases. Modrinth and CurseForge support all four project types; Hangar supports plugins; GitHub Releases tracks a configured `owner/repo` for mods and plugins.
- Provider-specific version and hash handling for installed-project matching, including Modrinth SHA-512, CurseForge MurmurHash2, and Hangar SHA-256.
- Dedicated Resource Pack management that stores the provider URL and SHA-1 in `server.properties`, with separate resource-pack metadata instead of treating the pack as a normal installed archive.
- Installed-project metadata tracking, incremental file hashing, rescan/reset operations, archive transactions, latest-version checks, and bulk updates backed by queued jobs and operation leases.
- Stale-while-revalidate source caching, deferred metadata loading, scheduled catalog/cache warming, and request performance profiling for upstream API operations.
- Per-server project-type and source settings, source credentials, navigation ordering, cache clearing, operation permissions, and manual egg profiles.
- Automatic detection for supported Minecraft eggs and their Pterodactyl-ecosystem equivalents, while preserving explicit egg feature/tag overrides and datapack support settings.
