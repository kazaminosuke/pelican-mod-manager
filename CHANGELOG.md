# Changelog

This file records the user-facing changes included in each release. The release workflow publishes
the complete matching version section and adds the comparison information to the release notes.

## [Unreleased]

### 日本語

#### 変更

- Mod サーバーに Modrinth（.mrpack）と CurseForge の Modpack を検索してインストールできるようにした。クライアント専用ファイルは入れず、ワールド、server.properties、BAN リスト、サーバー JAR は変更しない。既存の設定ファイルは残し、同じパスの Mod は置き換える。失敗したときは、その操作で追加したファイルを戻す
- サーバー作成時に Modpack を選べるようにした。対応する Egg が一つに定まるときだけ自動選択し、曖昧なときは候補だけを表示する。既存サーバーでは、必要な場合に確認のうえで Egg を切り替え、ワールドなどのファイルは削除しない
- FTB は FTB Server Egg の起動変数で導入する。Technic は、サーバー配布物の URL が既存のパック専用 Egg と一致するときだけ導入する。ATLauncher は公開 API にローダーもサーバー配布物もないため、導入しない
- Spigot の最新の無料ホスト版は SpiGet CDN から取得する。選んだ過去バージョンは公式の Spigot URL のままにする
- 管理者向けの診断コマンド `mod-manager:diagnose` を追加した
- ダウンロードした JAR / ZIP が正しいアーカイブでない場合は、既存ファイルを置き換える前に失敗するようにした
- 更新時に、すでに消えている古いファイルの削除が失敗しても、新しいファイルを消さないようにした。古いファイルがまだ残っているときは、両方読み込まれないよう新しいファイルを戻す
- 現在の Paper egg と同じ起動変数を持つサーバーを Folia と誤認しないようにした
- Velocity の Hangar 検索に Minecraft のバージョンを渡して一覧が空になる問題を修正した
- Spigot のダウンロード先が不正な場合と、CurseForge で第三者配布が無効なファイルを、通常のダウンロード失敗と区別して表示するようにした
- ファイルに変更がないのに「インストール済みファイルのスキャンをキューに追加しました」が繰り返し表示される問題を修正（スキャン結果を 10 分で破棄せず保持し、古くなった結果は表示したままバックグラウンドで静かに再検証。自動スキャンは間隔を空けて再試行し、失敗したスキャンが繰り返しキューに入らないように変更）
- インストール・更新・削除のたびにフォルダ全体を再スキャンしないように変更（変更したファイルだけをスキャン結果に反映し、インストール時にファイルのサイズ・更新日時を記録して再ダウンロード・再ハッシュを省略）
- 変更のないフォルダの再検証では、既知のファイルと最近確認済みの未識別ファイルを再ハッシュ・再照会しないように変更（未識別ファイルの再確認は 1 日 1 回、または手動スキャン時）
- スキャン待ちの間にインストール・更新・削除が「A managed file operation is already running.」で失敗する問題を修正（スキャンは実行開始時にロックを取得し、他の操作中はスケジューラ内で待機せずに後で再実行）
- 別の操作が実行中の場合は、インストール失敗ではなく既存の「別の操作が実行中」通知を表示するように変更
- ソースの応答が遅い・一時的に失敗した場合に「No compatible versions found」でインストール・更新に失敗する問題を修正（操作時はキャッシュの短い表示用タイムアウトではなく通常のタイムアウトで取得し、取得失敗を「互換バージョンなし」と扱わない）
- 最新バージョンにダウンロード可能なファイルがない場合（Spiget 未反映、CurseForge の外部配布無効、JAR のない GitHub Release）、ダウンロード可能な最新バージョンをインストールするように変更
- Spiget のダウンロード先確認が一時的に失敗しただけで、Spigot のバージョン一覧が「ダウンロード可能なファイルなし」として最大 30 分キャッシュされる問題を修正
- Spigot / Hangar の最新バージョン確認の失敗が別のプロジェクトとして扱われる問題を修正
- Mod Manager の外で置き換えられた Spigot プラグイン JAR を、以前のバージョンのまま表示し続ける問題を修正

### English

#### Changed

- Mod servers can search and install Modrinth (.mrpack) and CurseForge modpacks. Client-only files are skipped. Worlds, server.properties, ban lists, and the server JAR are left untouched. Existing config files are kept, while pack mods replace a file at the same path. A failed install removes the files added by that attempt
- Server creation can select a modpack. An egg is chosen automatically only when one high-confidence match exists; otherwise the compatible eggs are listed. An existing server can change eggs after confirmation when a pack requires it, without deleting worlds or other server files
- FTB packs are provisioned by the FTB Server egg variables. Technic packs are provisioned only when the server archive URL matches a pack-specific egg. ATLauncher packs are not installed because the public API has no loader or server archive and there is no ATLauncher egg
- The current hosted-free Spigot file is downloaded from the SpiGet CDN. An explicitly selected older version keeps the official Spigot URL
- Added the administrator command `mod-manager:diagnose` for non-destructive provider and egg checks
- A downloaded JAR or ZIP that is not a valid archive now fails before it replaces an existing file
- An update no longer deletes the new file when removal of an already-missing previous filename fails. If that previous file is still present, the new file is rolled back so both copies are not loaded
- A server with the current Paper egg variables is no longer identified as Folia
- Hangar searches on Velocity no longer send a Minecraft version, which had emptied the catalog
- An unusable Spigot download address and a CurseForge file with third-party downloads disabled are reported separately from an ordinary download failure
- Fixed "Scanning installed files queued" appearing repeatedly although no file changed. Scan results are kept instead of expiring after ten minutes; a stale result stays on screen while a background scan quietly revalidates it, and automatic scans are spaced out so a failing scan is not queued again on every page load
- Installing, updating, or removing a project no longer rescans the whole folder. Only the changed file is applied to the scan result, and an install records the file's size and modification time so it is not downloaded and hashed again
- Revalidating an unchanged folder no longer re-hashes or re-identifies tracked files or recently checked unknown files (unknown files are rechecked once a day or on a manual scan)
- Fixed installs, updates, and removals failing with "A managed file operation is already running." while a scan was waiting for the scheduler. A scan now takes the lock when it starts and, if another operation is running, is retried later instead of waiting inside the scheduler
- Another operation in progress is now reported with the existing "already running" notice instead of an install failure
- Fixed installs and updates failing with "No compatible versions found" when a source was slow or briefly failing. Actions fetch versions with the regular timeout instead of the short render budget and never treat a failed fetch as "no compatible versions"
- When the newest version has no downloadable file (not yet mirrored by Spiget, CurseForge third-party downloads disabled, or a GitHub release without a JAR), the newest downloadable version is installed
- Fixed a transient Spiget download-lookup failure caching a Spigot version list without any downloadable file for up to 30 minutes
- Fixed Spigot and Hangar latest-version failures being attributed to the wrong project
- Fixed a Spigot plugin JAR replaced outside Mod Manager keeping its previous version in the Installed list

## [0.2.0-pre.2] - 2026-09-26

### 日本語

#### 変更

- Spigot の Catalog 一覧が表示されない問題を修正（Minecraft バージョン絞り込み時の Spiget 応答形式とパッチバージョンの 404 に対応）
- Spigot の Catalog と Installed でダウンロード数・最終更新日が 0 / 空になる問題を修正（Spigot 公式 API と Spiget の実際のフィールド・秒単位の日時に合わせた）
- Spigot の無料プラグインをインストール / 更新できなかった問題を修正（Spiget CDN にミラーされた現行バージョンのファイルのみ使用。premium / 外部ホストは引き続きダウンロードしない）
- Spigot の更新確認が常に未解決になる問題を修正し、インストールできない更新（premium / 外部ホスト / CDN 未反映）は表示しないように変更
- Spiget の古いエッジキャッシュにより新しいバージョンが反映されない問題を修正
- 0.2.0-pre.1 の変更（schedule:run によるバックグラウンド処理、Spigot ソースの追加）をすべて含む
- このプレリリースは GitHub Releases の Prerelease としてのみ公開し、パネルのアップデート一覧（update.json）には出さない

### English

#### Changed

- Fixed the Spigot catalog showing no rows when filtered by Minecraft version (Spiget's response envelope and 404 for patch versions)
- Fixed Spigot download counts and last-updated dates showing zero or blank in the catalog and Installed tab (matched the official API and Spiget fields and their second-based timestamps)
- Fixed free Spigot plugins failing to install or update; only the current version mirrored on Spiget's CDN is downloaded, and premium or externally hosted files still are not
- Fixed Spigot update checks never resolving; updates that cannot be installed (premium, external, or not yet mirrored) are not offered
- Fixed stale Spiget edge-cache responses hiding new versions
- Includes every change from 0.2.0-pre.1 (background work drained by schedule:run and the Spigot source)
- Published this build only as a GitHub Prerelease. It is not offered in the panel update list (update.json)

## [0.2.0-pre.1] - 2026-09-23

### 日本語

#### 変更

- Installed scan / catalog warm / bulk update などの Plugin 固有処理を、`popen` / `proc_open` によるプロセス起動から外し、pending work を永続化して Pelican 既存の短命な `schedule:run`（`mod-manager:process-jobs`）で処理するよう変更
- Plugin 更新後も Laravel の長寿命 `queue:work` に Plugin クラスを載せない設計は維持（次の `schedule:run` がディスク上の新しい Plugin を読み込む）
- 既存の one-time `queue:restart` migration（v0.1.6）は変更せず、以後の Plugin 更新でも再実行しない
- Plugin の Catalog / Installed に Spigot ソースを追加（UI 名は常に Spigot。公式 Simple API を canonical metadata に使い、検索・バージョン・公開ファイルのダウンロードは不足分のみ Spiget を補助利用。premium / 外部ホストはダウンロードしない）
- このプレリリースは GitHub Releases の Prerelease としてのみ公開し、パネルのアップデート一覧（update.json）には出さない

### English

#### Changed

- Replaced subprocess spawning (`popen` / `proc_open`) with persisted pending work drained by Pelican's existing short-lived `schedule:run` (`mod-manager:process-jobs`)
- Plugin updates still take effect without `queue:restart`; the next scheduler run loads the Plugin from disk instead of a long-running queue worker
- Left the v0.1.6 one-time `queue:restart` migration unchanged so later Plugin updates do not recycle workers again
- Added Spigot as a Plugin catalog source (UI label is always Spigot). Canonical metadata uses the official Simple API; Spiget fills search, versions, and public-file download gaps. Premium and externally hosted files are not downloaded.
- Published this build only as a GitHub Prerelease. It is not offered in the panel update list (update.json)

## [0.1.6] - 2026-09-21

### 日本語

#### 変更

- Installed scan / catalog warm / bulk update などの Plugin 固有処理を Laravel Queue から外し、毎回最新 Plugin コードを読み込む短命な `php artisan mod-manager:run-job` プロセスで実行するよう変更
- Plugin 更新後に `queue:restart` しなくても Hangar 404 hotfix や scan / warm が新しいコードで動くように修正
- Queue 脱却版への移行時だけ、旧 `queue:work` を掃除する一回限りの migration を追加（`queue:restart` を一度発行し、以後の Plugin 更新では再実行しない）
- CurseForge の Resource Pack Catalog から、API が `allowModDistribution === false` と明示している project を除外するよう修正（`null` / 未設定は除外しない。Mod / Plugin / Datapack は変更なし）
- 既存の operation lease・重複防止・Hangar 404 を failure marker にしない挙動は維持

### English

#### Changed

- Moved Installed scan, catalog warm, bulk update, and related Plugin work off Laravel Queue into short-lived `php artisan mod-manager:run-job` processes that load the current Plugin from disk
- Plugin updates now take effect without `queue:restart`, including Hangar 404 handling, scans, and catalog warming
- Added a one-time migration that issues `queue:restart` only when updating to this Queue-exit release, so stale long-running workers are recycled once
- Filtered CurseForge Resource Pack catalog hits that explicitly set `allowModDistribution` to `false`, without dropping null/absent values or changing Mod, Plugin, or Datapack catalogs
- Preserved operation leases, duplicate prevention, and Hangar 404s that must not write a failure marker

## [0.1.6-rc.1] - 2026-09-21

### 日本語

#### 変更

- Installed scan / catalog warm / bulk update などの Plugin 固有処理を Laravel Queue から外し、毎回最新 Plugin コードを読み込む短命な `php artisan mod-manager:run-job` プロセスで実行するよう変更
- Plugin 更新後に `queue:restart` しなくても Hangar 404 hotfix や scan / warm が新しいコードで動くように修正
- Queue 脱却版への移行時だけ、旧 `queue:work` を掃除する一回限りの migration を追加（`queue:restart` を一度発行し、以後の Plugin 更新では再実行しない）
- 既存の operation lease・重複防止・Hangar 404 を failure marker にしない挙動は維持

### English

#### Changed

- Moved Installed scan, catalog warm, bulk update, and related Plugin work off Laravel Queue into short-lived `php artisan mod-manager:run-job` processes that load the current Plugin from disk
- Plugin updates now take effect without `queue:restart`, including Hangar 404 handling, scans, and catalog warming
- Added a one-time migration that issues `queue:restart` only when updating to this Queue-exit release, so stale long-running workers are recycled once
- Preserved operation leases, duplicate prevention, and Hangar 404s that must not write a failure marker

## [0.1.5] - 2026-09-17

### 日本語

#### 変更

- Hangar の hash lookup が返す 404 / project not found を source 障害ではなく正常な unmatched として扱うよう修正
- SpigotMC-only など Hangar に存在しない Plugin JAR でも installed-file scan が失敗しないよう修正
- 429 / 5xx / timeout / connection error など本当の障害は従来どおり failure 扱いを維持

### English

#### Changed

- Fixed Hangar hash lookup treating a 404 / project not found as a source outage instead of a normal unmatched result
- Fixed installed-file scans failing on Plugin JARs that Hangar does not host, such as SpigotMC-only uploads
- Preserved existing failure handling for real outages such as 429, 5xx, timeouts, and connection errors

## [0.1.4] - 2026-08-31

### 日本語

#### 変更

- `.codex`をplugin release filesから除外
- Pelican Hub review compatibility fixを適用

### English

#### Changed

- Excluded `.codex` from plugin release files
- Applied the Pelican Hub review compatibility fix

## [0.1.3] - 2026-08-30

### 日本語

#### 変更

- Marketplaceの禁止ファイル・プロセス操作検出に対応
- 挙動を変更せず、既存のFilesystem・Wings API抽象化へ置換

### English

#### Changed

- Addressed Marketplace detections for prohibited file and process operations
- Preserved behavior while using the existing Filesystem and Wings API abstractions

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
