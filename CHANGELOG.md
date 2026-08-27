# Changelog

This file records the user-facing changes included in each release. The release workflow publishes
the complete matching version section and adds the comparison information to the release notes.

## [Unreleased]

## [0.1.0] - 2026-08-27

### 日本語

#### 変更

- Mod / Plugin / Datapack / Resource Pack の検索・インストール・更新・削除に対応
- Modrinth、CurseForge、Hangar、GitHub Releases に対応
- インストール済みプロジェクトの検出・再スキャン・一括更新に対応
- Resource Pack の URL / SHA-1 を `server.properties` へ自動設定
- サーバーごとのプロジェクト種別・ソース・権限・Egg プロファイル設定に対応
- SWR キャッシュ、遅延読み込み、バックグラウンド処理によるパフォーマンス改善
- 対応する Minecraft Egg の自動検出に対応

### English

#### Changed

- Added search, install, update, and removal support for Mods, Plugins, Datapacks, and Resource Packs
- Added support for Modrinth, CurseForge, Hangar, and GitHub Releases
- Added installed-project detection, rescanning, and bulk updates
- Added automatic Resource Pack URL and SHA-1 configuration in `server.properties`
- Added per-server project type, source, permission, and Egg profile settings
- Improved performance with SWR caching, deferred loading, and background processing
- Added automatic detection for supported Minecraft Eggs
