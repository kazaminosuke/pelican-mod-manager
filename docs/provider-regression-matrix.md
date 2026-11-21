# PR #2 配布元横断回帰試験

この表は当時の検証結果を記録しています。FTBの現在の導入経路と対応条件は[Modpack更新ガイド](modpack-updates.md#ftbの対象と確認手順)に記載しています。

基点は `f9bc1c32d4e27b7a458cc9a97785691e0a79eace` です。検証対象は提供元API、実配布ファイル、既存サーバーのMod Manager、新規作成Wizardです。隔離環境でのみ実行しました。先行した実ZIP・専用Egg・OSS互換性監査は別記に残しています。[先行監査](modpack-compatibility-audit.md) · [PR #2](https://github.com/kazaminosuke/pelican-mod-manager/pull/2)

## テストマトリクス

**実**はその列の実API・実ファイル・隔離Panel/Wingsでの成功です。**実†**は実配布ファイルを実Wingsへ置いた成功です。取得の一段に限り隔離試験用のPanel経由を使用しました。**モック**は単体・モック試験で成功、**外部制限**は配布元の応答または認証による検証不可、**環境制限**はクラウド実行環境による検証不可を示します。**未実装**、**未検証**、機能のない**対象外**も区別します。

| 配布元・機能 | 検索・ページ・絞り込み | メタデータ | バージョン指定 | ダウンロード | 導入 | 更新 | 削除 | 実Panel / Wings |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Modrinth Mod | 実。検索、ページ、Fabric・MC 1.19.3 | 実。説明・アイコン・DL数 | 実。旧版と対応最新版 | 実。両版JAR | 実† | 実†。版変更 | 実。Chromiumで削除、実†で再導入 | 実†。Installed表示と実ファイル・管理情報を確認。個別Modを入れたサーバーの起動は未検証 |
| Modrinth Modpack | 実。Chromiumでカタログ、前後ページ、配布元・ローダー・Minecraftの絞り込み | 実。プロジェクト・依存・ローダー | 実。Wizard前後移動でも固定 | 実。`.mrpack`、依存11件 | 実†。Wizardと既存サーバー | 導入済みModpack全体の一括更新・削除は未実装 | 同左 | 実†。作成、ジョブ完了、Minecraft起動・正常停止。標準Wings取得は環境制限 |
| CurseForge Mod・Modpack | モック。実Coreは外部制限 | モック。公開Additional Files・ZIPの別試験は実 | モック。親・子の固定。認証付き実APIは外部制限 | モック。Coreの認証付きURLは外部制限 | モック。隔離Panelの認証情報なし | Modの共通処理はモック。認証付き操作は外部制限 | Modの共通処理はモック。実導入が前提のため未検証 | 外部制限。Core 403、WizardのCurseForge経路は認証情報を要する |
| SpigotMC / SpiGet Plugin | 実。検索・ページとPaperフィルター。価格不明・非JARの境界はモック | 実。SpiGet説明・DL数・アイコンURL。導入済み行の欠落を確認し修正 | 実。Vault `344916`・`271004` | 実。最新版JAR。旧版は外部制限（公式403） | 実†。Vault最新 | 実。旧版失敗通知と現ファイル保護。旧版の取得・適用は外部制限 | 共通処理はモック。Spigot行の実削除は未検証 | 実†。現行版を配置。Chromeで旧版の手動導入案内。Paper起動は未検証 |
| Hangar Plugin | 実。2ページ・プラットフォーム/MC指定 | 実。説明・DL数・アイコン | 実。スナップショット2版を固定 | 実。2版と参考の公開安定版 | 実† | 実†。同名JARの置換 | 共通処理はモック。Hangar行の実削除は未検証 | 実†。Wings配置。Paper起動は未検証 |
| GitHub Releases Mod / Plugin | カタログ対象外。実リポジトリ指定でPluginを確認 | 実。リポジトリ・リリース・アセット | 実。タグ `5.12.1`・`5.12.2` | 実。両版JAR | 実†。Plugin。GitHub由来Modの実試験は未検証 | 実†。旧タグ・最新タグへ置換 | 共通処理はモック。GitHub行の実削除は未検証 | 実†。Wings配置。Paper起動は未検証 |
| FTB Modpack | 実。名前検索。ページ・サーバー側の絞り込みは対象外 | 実。パック・リリース・MC | 実。`18` / `12169` とEgg変数 | 未検証。今回のFTB対象の専用Egg取得は実行せず | モック。今回の専用Eggプランは実 | 対象外 | 対象外 | 実Panelに公式Eggを取り込み、プランまで。Wings専用Egg実行・起動は未検証 |
| Technic Modpack | 実。名前検索。ページ・サーバー側の絞り込みは対象外 | 実。Platform＋Solder | 実。`tekkit-2` / `v1.2.6` とEgg変数 | 同じ公式ZIPの取得は先行監査で実 | モック。今回の専用Eggプランは実、同リリースの単独配置は先行監査で実 | 対象外 | 対象外 | 実Panelに公式Eggを取り込み、プランまで。Wings専用Egg実行は未検証。先行監査で単独起動・停止成功 |
| ATLauncher Modpack | 実。名前検索。ページ・サーバー側の絞り込みは対象外 | 実。パック・リリース・MC | 実。`SevTechAges` / `3.2.3` | 導入で使う配布経路は未実装 | 未実装。理由表示を確認 | 対象外 | 対象外 | 公式メタデータを読んで `none` まで。Egg・Wings導入は未実装 |

ModpackをOFFにした既存のサーバー作成は別のテストサーバーで実行しました。Chromiumで完了し、Fabric Eggで作成したサーバーをPanelで `Installing` 解消まで確認しました。その後、同じテストサーバーを使って、既存サーバー向けModpackのキューと配置を独立に確認しました。

## 実行手順と証跡

### 実行環境

1. 空のSQLite、ローカルのテストユーザーとアロケーションでPanel `1c3e7b33` とWings `v1.0.0-rc.2` を起動しました。HTTP通知を受けられるようPanelのワーカーを複数起動しました。Minecraftは `127.0.0.1` でのみ待ち受けました。rootless DockerのVFSで公式の大型イメージが複製時にディスクを使い切ったため、以前の検証用イメージと生成物を片付け、空きを約1.9 GBに戻しました。
2. 公式Fabric/Paper EggからUUIDと起動変数を維持したテスト用コピーを作成しました。Wingsの作成と起動には約79 MBの軽量イメージを使い、別途ハッシュ照合したTemurin 17.0.21.1+2、選択版のMinecraft 1.19.3とFabric 0.14.12を起動用に用意しました。公式のJavaイメージと初回インストールスクリプト自体はこの環境では未検証です。
3. 標準Wingsの外部取得はクラウドのHTTPSプロキシを内部宛先と判定し、安全に拒否しました。正常系では、隔離試験のプロセスだけで、許可済み公開先の小型配布ファイルをPanelが取得し、Wingsの書き込みAPIへ渡しました。アーカイブの検査、ファイル移動、管理情報、ロールバック、ジョブ、起動・停止はプラグインおよび実Wingsを使用しました。Wingsのネットワーク保護設定は維持しました。

### 配布物・リリースごとの再現手順

| ケース | 選択リリースと手順 | 観測結果・ログ |
| --- | --- | --- |
| Modrinth Lithium | `gvQqBUqZ`、`sMWkk4VU` → `53cwYYb1`。1.19.3/Fabricで検索し、旧版から更新。InstalledでUninstall → Confirm、再導入 | 629,249 → 638,431 bytes。Chromiumから削除したJARの実ディレクトリ消失を確認。再導入後のWingsのJARと管理情報は `53cwYYb1`。`modrinth-transactions.log` / `modrinth-update.log` / `browser-uninstall-final.log` / `modrinth-reinstall.log` |
| Modrinth Server Optimization | `YkG1DyHE` / `vtGmZsbn`。Wizardの行を選び、前後往復、Fabric Eggと変数を確認して作成。`php artisan mod-manager:process-jobs`、手動起動、`stop`。既存サーバーからも同リリースをキューに投入 | 新規作成 `installed=11, skipped=0, warnings=[]`、1.19.3/Fabric 0.14.12/Java 17。既存サーバーは標準Wings取得失敗と、取得経路置換での完了を個別に確認。`browser-wizard.log` / `modpack-worker.log` / `modpack-host-boot-complete.log` / `existing-modpack-native.log` / `existing-modpack-retry.log` |
| CurseForge Jade / ATM10 | ModのCore検索、ATM10 `925200` の親 `9620701` と子 `9620727` を照会 | いずれも403。キー未提供。公開ファイルと実ZIPの範囲は先行監査。`live.log` |
| Spigot Vault / LuckPerms | Vault `34315`、最新 `344916`（1.7.3）、旧 `271004`（1.7.2）。LuckPerms `28140` / `648014`（5.5.71）。SpiGetメタデータ、CDN、`plugin.yml`、実Installedの版選択を照合 | Vault最新 272,259 bytes、JAR内部 `1.7.3-b131`。LuckPermsのCDNも200、1,501,521 bytes。Vault旧版のSpiGetダウンロードAPIは302、SpigotMCの版指定URLは403。旧版クリック後に手動アップロード案内と `?version=271004` を表示。既存の `Vault-1.7.3.jar` を維持。`downloads.log` / `spigot-catalog.log` / `spigot-latest.log` / `browser-spigot-old-ui-after.log` |
| Hangar ViaVersion | プロジェクト `31`、`33522` → `33541`。Paper 1.20.4で対応スナップショットを取得、同名JARを更新 | `ViaVersion-5.12.3-SNAPSHOT.jar` が6,519,506 → 6,519,553 bytes、更新先SHA-256 `5d0ee19b454ba1484a6f877e4028211ac397bc45b45b1d599aabb7a0703395fc`。`hangar-snapshot-old.log` / `hangar-snapshot-update.log` |
| GitHub Releases ViaVersion | `ViaVersion/ViaVersion`。`5.12.1`（公開ID `406802912`）と `5.12.2`（`425626612`）をリポジトリ指定で取得。旧タグに変更してから最新版へ更新 | 最新のWings上のJARは6,508,813 bytes、SHA-256 `24e4876fefe73e08a4c87cd6c457ee0916e62dd69656631caf41e26f909d504b`。旧JARを削除して管理情報を更新。`github-downgrade.log` / `github-update.log` |
| FTB Vanilla+ | `18` / `12169`（1.1.1、MC 1.7.10）。検索後、実Panelに取り込んだ `ftb-server` と変数を照合 | `FTB_MODPACK_ID=18`、`FTB_MODPACK_VERSION_ID=12169`。専用Eggは先行監査でインストーラー失敗時にも完了表示できるため要注意。`pack-providers.log` |
| Technic Tekkit 2 | `tekkit-2` / `v1.2.6`。PlatformとSolderの版を照合後、実Panelに取り込んだ専用Eggを選ぶ | SolderのMC 1.12.2、Egg `tekkit-2`、`MODPACK_VERSION=v1.2.6`。`pack-providers.log` |
| ATLauncher SevTech: Ages | `SevTechAges` / `3.2.3`。公開APIと対象のリリースを照会 | MC 1.12.2。公開バージョン応答からローダー・サーバーアーカイブを確定できない理由を表示。`pack-providers.log` |

起動・停止ログの抜粋です。Dockerの終了コードも `0` でした。

```text
Loading Minecraft 1.19.3 with Fabric Loader 0.14.12
[09:08:34] [Server thread/INFO]: Done (40.511s)! For help, type "help"
stop
[09:08:35] [Server thread/INFO]: Stopping server
[09:08:35] [Server thread/INFO]: Saving worlds
[09:08:38] [Server thread/INFO]: ThreadedAnvilChunkStorage: All dimensions are saved
```

### Spigotと共通機能

- 無料のLuckPermsを実カタログで確認しました。実検索結果に現れた明確な有料のItemsAdder `73355`、外部配布のEssentialsX `9089` とViaVersion `19254` は除外されました。価格欠損・非JAR・配布エラーの分岐はモックで再現しました。[Spigotの回帰テスト](../tests/Unit/Sources/SpigotSourceTest.php)
- Spigotでは実APIでページ送りと更新済みキャッシュからの行の表示を確認し、Chromiumの版選択を実行しました。可視行のIDがキャッシュ更新でページ外に移る条件は、`SpigotCatalogRecordTest` で更新前後のスナップショットを決定的に再現しました。Modrinth・CurseForge・Hangar・GitHub Releasesのソースキャッシュテストと共通SWRテストも実行しました。共有の429、タイムアウト、再検証失敗、再試行はモックで確認しました。外部APIには429を誘発する負荷をかけていません。[キャッシュ競合のテスト](../tests/Unit/Filament/SpigotCatalogRecordTest.php) · [SWRのテスト](../tests/Unit/Support/SourceCacheTest.php)
- モックでリースによる重複防止、権限の継承と拒否、既存ファイルとの衝突、同名更新、メタデータ保存後の削除、途中失敗時のロールバック、Modpackの保護パスを検証しました。実環境で確認したのは所有者による操作、取得失敗時の未導入、既存JAR保持、Wings上の削除、Modpackジョブの完了と失敗です。実Panelの `file.read` / `file.read-content` だけを持つサブユーザーは、導入・更新・削除・スキャンの権限がなく、Chromiumではカタログと版選択を閲覧できました。Installedタブ、導入・削除アクションは表示されませんでした（`browser-viewer-final.log`）。すべての配布元での個別のロールバックは未検証です。[権限](../tests/Unit/Support/ProjectOperationAuthorizerTest.php) · [重複防止](../tests/Unit/Services/InstalledProjectMutationServiceTest.php) · [ファイル保護](../tests/Unit/Services/InstalledArchiveTransactionTest.php) · [Modpackのファイル保護](../tests/Unit/Services/ModpackInstallerTest.php)

## 自動テスト

PHPUnitは611件・3,681アサーション成功、PintとPanelの標準PHPStanも成功しました。別途プラグインの `src` を明示したPHPStanでは、基点と変更後の両方で207件を報告しました。変更した2ファイルに新しい指摘はありません。

## 修正とマージ前の残件

- `74462e4`。空のFabricサーバーへの初回導入で、Wingsの「ディレクトリがまだ存在しない」404を既存ファイル検査が致命的と扱っていました。既知のディレクトリ404に限って空と解釈します。別の404、403、ルートの404は引き続き失敗として扱います。[回帰テスト](../tests/Unit/Support/WingsRemoteFilesystemTest.php)
- `0c076ae`。実PanelのInstalledで、公式Spigot Simple APIの403により、タイトル以外のデータが欠落しました。単件と一括で403の場合だけ、同一IDの公開SpiGetメタデータを所定の時間枠内で補います。修正後、実Vaultの説明、アイコンURL、DL数3,655,890を取得し、ChromiumのInstalledに説明とDL数を表示しました。公式404と認証外の配布制限は従来通り扱います。旧版のダウンロード経路自体は変更していません。[修正](../src/Sources/SpigotSource.php) · [回帰テスト](../tests/Unit/Sources/SpigotSourceTest.php)
- リリース判断前に残る必須の実環境確認は、認証付きCurseForge CoreでのMod取得と、Wizardでの親→子Additional Files→Wings導入、外部公開URLへ正規に到達できるWingsでの標準取得、公式イメージと未変更Eggでの新規インストールです。FTBは成功判定と起動ファイルを照合し、公式Eggが失敗を成功として扱う条件を確認する必要があります。旧版Spigotの403は手動経路まで実証済みです。ATLauncherの導入は未実装です。いずれも実環境成功件数には含めていません。
