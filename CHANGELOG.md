# Changelog

This file records the user-facing changes included in each release. The release workflow publishes
the complete matching version section and adds the comparison information to the release notes.

## [Unreleased]

## [0.1.2] - 2026-08-30

### 日本語

#### 変更

- Catalog Filter UIをcompactなresponsiveレイアウトに改善
- Minecraft Versionの複数選択に対応
- server / Eggから自動検出したMinecraft Version・Loaderを初期選択に適用
- 自動検出値を除外したactive filter countを修正
- 日本語環境の日付表示を自然な年月日順に改善
- Filter dropdownのoverflow clippingをFilamentのfixed positioningで修正

### English

#### Changed

- Improved the Catalog filter UI with a compact responsive layout
- Added multiple Minecraft version selection
- Applied server/Egg-detected Minecraft version and loader as the initial selections
- Corrected the active filter count to ignore automatic defaults
- Improved Japanese date display with a natural year-month-day order
- Fixed Filter dropdown overflow clipping with Filament fixed positioning

## [0.1.1] - 2026-08-30

### 日本語

#### 変更

- プロジェクト種別・providerごとのCatalog filterと互換性判定を改善
- responsiveなList / Panel表示と表示状態の保持に対応
- Catalog toolbarの操作順・配置・Filament標準actionとの統一を改善
- Installed件数・scan cache・Catalog初回表示時のbackground warmを改善
- 現在のmaintainer表記と元プロジェクト・参考実装のCreditsを整理
- Hangarのplatform・category・tag filter処理を修正

### English

#### Changed

- Improved Catalog filters and compatibility handling across project types and providers
- Added responsive List / Panel views with persisted layout state
- Improved Catalog toolbar ordering, placement, and consistency with standard Filament actions
- Improved Installed counts, scan caching, and background warming on initial Catalog visits
- Clarified the current maintainer and separated original-project and implementation-reference credits
- Fixed Hangar platform, category, and tag filter handling

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
