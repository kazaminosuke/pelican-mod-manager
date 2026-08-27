# Changelog

This file records the user-facing changes included in each release. The release workflow publishes
the complete matching version section verbatim, including its `Full Changelog` line.

## [Unreleased]

## [0.1.0]

### 日本語

#### 追加

- Pelican Panel用Minecraft Mod Manager pluginの初回公開版。catalogとinstalled-projectページで、Minecraftのmod、plugin、datapack、resource packを検索・インストール・更新・削除できる。
- Modrinth、CurseForge、Hangar、GitHub Releasesのsource integration。ModrinthとCurseForgeは4種類すべてのproject type、Hangarはplugin、GitHub Releasesはmodとplugin向けに設定した`owner/repo`を追跡する。
- インストール済みprojectの照合に、providerごとのversionとhash処理を追加。ModrinthはSHA-512、CurseForgeはMurmurHash2、HangarはSHA-256を使用する。
- Resource Pack専用管理を追加。provider URLとSHA-1を`server.properties`へ保存し、通常のinstalled archiveとは分離したresource-pack metadataを使用する。
- installed-project metadata tracking、incremental file hashing、rescan/reset操作、archive transaction、latest-version check、bulk updateを、queued jobとoperation leaseで実行する。
- upstream API operation向けに、stale-while-revalidate source cache、deferred metadata loading、scheduled catalog/cache warming、request performance profilingを追加する。
- サーバーごとのproject type・source設定、source credential、navigation order、cache clear、operation permission、manual egg profileを追加する。
- 対応するMinecraft eggとPterodactyl ecosystem相当のeggを自動検出し、明示的なegg feature/tag overrideとdatapack support設定を維持する。

### English

#### Added

- Initial public release of the Pelican Panel Minecraft Mod Manager plugin, with catalog and installed-project pages for searching, installing, updating, and removing Minecraft mods, plugins, datapacks, and resource packs.
- Source integrations for Modrinth, CurseForge, Hangar, and GitHub Releases. Modrinth and CurseForge support all four project types; Hangar supports plugins; GitHub Releases tracks a configured `owner/repo` for mods and plugins.
- Provider-specific version and hash handling for installed-project matching, including Modrinth SHA-512, CurseForge MurmurHash2, and Hangar SHA-256.
- Dedicated Resource Pack management that stores the provider URL and SHA-1 in `server.properties`, with separate resource-pack metadata instead of treating the pack as a normal installed archive.
- Installed-project metadata tracking, incremental file hashing, rescan/reset operations, archive transactions, latest-version checks, and bulk updates backed by queued jobs and operation leases.
- Stale-while-revalidate source caching, deferred metadata loading, scheduled catalog/cache warming, and request performance profiling for upstream API operations.
- Per-server project-type and source settings, source credentials, navigation ordering, cache clearing, operation permissions, and manual egg profiles.
- Automatic detection for supported Minecraft eggs and their Pterodactyl-ecosystem equivalents, while preserving explicit egg feature/tag overrides and datapack support settings.

**Full Changelog**: https://github.com/kazaminosuke/pelican-mod-manager/commits/v0.1.0
