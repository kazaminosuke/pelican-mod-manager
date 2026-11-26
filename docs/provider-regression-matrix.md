# 配布元横断回帰試験

PR #2で既存サーバーのMod Managerを隔離Panel・Wingsで検証した際の記録です。実†は、公開配布ファイルの取得だけを検証用にPanel経由とした実Wings試験です。認証付きCurseForgeと標準のWings取得は引き続き実環境での確認が必要です。 [PR #2の実行環境と全記録](https://github.com/kazaminosuke/pelican-mod-manager/blob/58b73c3900ea4babeb509f8d9e7c4f6360929b61/docs/provider-regression-matrix.md) · [CurseForgeの単体手動導入](curseforge-manual-install.md)

## 検証結果

| 配布元・機能 | 検索・ページ・絞り込み | メタデータ | バージョン指定 | ダウンロード | 導入 | 更新 | 削除 | 実Panel / Wings |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Modrinth Mod | 実。検索、ページ、Fabric・MC 1.19.3 | 実。説明・アイコン・DL数 | 実。旧版と対応最新版 | 実。両版JAR | 実† | 実†。版変更 | 実。Chromiumで削除、実†で再導入 | 実†。Installed表示と実ファイル・管理情報を確認。個別Modを入れたサーバーの起動は未検証 |
| CurseForge Mod | モック。実Coreは外部制限 | モック。公開Additional Files・ZIPの別試験は実 | モック。認証付き実APIは外部制限 | モック。Coreの認証付きURLは外部制限 | モック。隔離Panelの認証情報なし | Modの共通処理はモック。認証付き操作は外部制限 | Modの共通処理はモック。実導入が前提のため未検証 | 外部制限。Core 403。単体手動導入は別試験を参照 |
| SpigotMC / SpiGet Plugin | 実。検索・ページとPaperフィルター。価格不明・非JARの境界はモック | 実。SpiGet説明・DL数・アイコンURL。導入済み行の欠落を確認し修正 | 実。Vault `344916`・`271004` | 実。最新版JAR。旧版は外部制限（公式403） | 実†。Vault最新 | 実。旧版失敗通知と現ファイル保護。旧版の取得・適用は外部制限 | 共通処理はモック。Spigot行の実削除は未検証 | 実†。現行版を配置。Chromeで旧版の手動導入案内。Paper起動は未検証 |
| Hangar Plugin | 実。2ページ・プラットフォーム/MC指定 | 実。説明・DL数・アイコン | 実。スナップショット2版を固定 | 実。2版と参考の公開安定版 | 実† | 実†。同名JARの置換 | 共通処理はモック。Hangar行の実削除は未検証 | 実†。Wings配置。Paper起動は未検証 |
| GitHub Releases Mod / Plugin | カタログ対象外。実リポジトリ指定でPluginを確認 | 実。リポジトリ・リリース・アセット | 実。タグ `5.12.1`・`5.12.2` | 実。両版JAR | 実†。Plugin。GitHub由来Modの実試験は未検証 | 実†。旧タグ・最新タグへ置換 | 共通処理はモック。GitHub行の実削除は未検証 | 実†。Wings配置。Paper起動は未検証 |

## 使用した配布リリース

| ケース | 選択リリースと手順 | 観測結果・ログ |
| --- | --- | --- |
| Modrinth Lithium | `gvQqBUqZ`、`sMWkk4VU` → `53cwYYb1`。1.19.3/Fabricで検索し、旧版から更新。InstalledでUninstall → Confirm、再導入 | 629,249 → 638,431 bytes。Chromiumから削除したJARの実ディレクトリ消失を確認。再導入後のWingsのJARと管理情報は `53cwYYb1`。`modrinth-transactions.log` / `modrinth-update.log` / `browser-uninstall-final.log` / `modrinth-reinstall.log` |
| Spigot Vault / LuckPerms | Vault `34315`、最新 `344916`（1.7.3）、旧 `271004`（1.7.2）。LuckPerms `28140` / `648014`（5.5.71）。SpiGetメタデータ、CDN、`plugin.yml`、実Installedの版選択を照合 | Vault最新 272,259 bytes、JAR内部 `1.7.3-b131`。LuckPermsのCDNも200、1,501,521 bytes。Vault旧版のSpiGetダウンロードAPIは302、SpigotMCの版指定URLは403。旧版クリック後に手動アップロード案内と `?version=271004` を表示。既存の `Vault-1.7.3.jar` を維持。`downloads.log` / `spigot-catalog.log` / `spigot-latest.log` / `browser-spigot-old-ui-after.log` |
| Hangar ViaVersion | プロジェクト `31`、`33522` → `33541`。Paper 1.20.4で対応スナップショットを取得、同名JARを更新 | `ViaVersion-5.12.3-SNAPSHOT.jar` が6,519,506 → 6,519,553 bytes、更新先SHA-256 `5d0ee19b454ba1484a6f877e4028211ac397bc45b45b1d599aabb7a0703395fc`。`hangar-snapshot-old.log` / `hangar-snapshot-update.log` |
| GitHub Releases ViaVersion | `ViaVersion/ViaVersion`。`5.12.1`（公開ID `406802912`）と `5.12.2`（`425626612`）をリポジトリ指定で取得。旧タグに変更してから最新版へ更新 | 最新のWings上のJARは6,508,813 bytes、SHA-256 `24e4876fefe73e08a4c87cd6c457ee0916e62dd69656631caf41e26f909d504b`。旧JARを削除して管理情報を更新。`github-downgrade.log` / `github-update.log` |

## 共通処理とSpigot

- 無料のLuckPermsを実カタログで確認しました。実検索結果に現れた明確な有料のItemsAdder `73355`、外部配布のEssentialsX `9089` とViaVersion `19254` は除外されました。価格欠損・非JAR・配布エラーの分岐はモックで再現しました。[Spigotの回帰テスト](../tests/Unit/Sources/SpigotSourceTest.php)
- Spigotでは実APIでページ送りと更新済みキャッシュからの行の表示を確認し、Chromiumの版選択を実行しました。可視行のIDがキャッシュ更新でページ外に移る条件は、`SpigotCatalogRecordTest` で更新前後のスナップショットを決定的に再現しました。Modrinth・CurseForge・Hangar・GitHub Releasesのソースキャッシュテストと共通SWRテストも実行しました。共有の429、タイムアウト、再検証失敗、再試行はモックで確認しました。外部APIには429を誘発する負荷をかけていません。[キャッシュ競合のテスト](../tests/Unit/Filament/SpigotCatalogRecordTest.php) · [SWRのテスト](../tests/Unit/Support/SourceCacheTest.php)
- `74462e4`。空のFabricサーバーへの初回導入で、Wingsの「ディレクトリがまだ存在しない」404を既存ファイル検査が致命的と扱っていました。既知のディレクトリ404に限って空と解釈します。別の404、403、ルートの404は引き続き失敗として扱います。[回帰テスト](../tests/Unit/Support/WingsRemoteFilesystemTest.php)
- `0c076ae`。実PanelのInstalledで、公式Spigot Simple APIの403により、タイトル以外のデータが欠落しました。単件と一括で403の場合だけ、同一IDの公開SpiGetメタデータを所定の時間枠内で補います。修正後、実Vaultの説明、アイコンURL、DL数3,655,890を取得し、ChromiumのInstalledに説明とDL数を表示しました。公式404と認証外の配布制限は従来通り扱います。旧版のダウンロード経路自体は変更していません。[修正](../src/Sources/SpigotSource.php) · [回帰テスト](../tests/Unit/Sources/SpigotSourceTest.php)
