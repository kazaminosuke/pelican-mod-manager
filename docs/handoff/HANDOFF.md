# 作業引き継ぎドキュメント（PR #2 / `fix/modpack-wizard-spigot`）

> **2026-11-25 方針更新**　以下は引き継ぎ時点の記録です。比較調査は [Modpack互換性監査の追補](../modpack-compatibility-audit.md#pr-2-の比較追補) に反映しました。M2〜M6 は参考資料として保管します。`tests/E2E/` は削除済みで、検証には既存の PHPUnit・PHPStan・Pint・CI を使います。

- **対象リポジトリ**: `https://github.com/kazaminosuke/pelican-mod-manager`
- **対象ブランチ / PR**: `fix/modpack-wizard-spigot`（PR #2、ベース `origin/main` = `a3352733`、本作業起点 = `4e5f839a`）
- **比較監査対象**: `https://github.com/cosmii02/pelican-modpackmanager`（v1.7.3、`ddc2e364`、MIT License `Copyright (c) 2026 cosmii02`）
- **引き継ぎ日**: 2026-11-25
- **目的**: Teamwork による並列調査・反復監査モードを停止し、完了済みの UI 修正（M1）、分離された E2E テスト基盤（WIP）、および再利用可能な詳細調査・設計資料を確実に保存して、次回の**通常エージェントセッション**で重複調査なしに M2〜M6 を効率よく実装・完了できるようにすること。

> **注記（引き継ぎ資料の扱い）**: `docs/handoff/` 配下は次セッション向けの一時引き継ぎ・設計アーカイブです。M5（ドキュメント刷新・クレジット追記）〜M6（最終検証）完了後に不要ファイルを削除または正規 `docs/*.md` へ統合してください。

---

## 資料インデックス（次セッションで再調査を省略するための再利用資料）

| ファイル | 内容 | 再利用タイミング |
|---|---|---|
| [`HANDOFF.md`](HANDOFF.md)（本ファイル） | 全体状況・コミット整理・優先順位・検証状況・次セッション手順 | **最初に読む** |
| [`project-plan-m2-m5.md`](project-plan-m2-m5.md) | 24項目フィーチャー棚卸し、M1〜M6 マイルストーン、Interface Contracts #1〜#6、不採用設計一覧、M2 厳密スコープ | M2〜M6 全体設計の基準 |
| [`m2-implementation-blueprints.md`](m2-implementation-blueprints.md) | **M2 の 2/3（15ファイル超）の行レベル実装設計書＋既存テスト破壊予測＋LSP/DI罠**＋残り 1/3 チェックリスト | **M2 着手時に必読（再探索不要）** |
| [`comparative-audit-cosmii02.md`](comparative-audit-cosmii02.md) | `cosmii02/pelican-modpackmanager` との 17項目詳細比較、公式 API/Wings/Egg 実測、採用/維持/共通課題/不採用、P0〜P2 改善方針 | M3・M4・M5（クレジット/監査更新） |
| [`core-infrastructure-audit.md`](core-infrastructure-audit.md) | UI 問題 A/B の Chrome 実測記録、非Modpack 共通基盤の 21件不具合一覧（`file:line` 付き）、§6 クレジット書式 | M2・M5 |
| [`external-specs-and-docs-reeval.md`](external-specs-and-docs-reeval.md) | 公式 Pelican Eggs (`c6f2262`) / Panel (`f880625`) / Wings (`27f67c8`)、5配布元 API 仕様、14件のエッジケース、既存 `docs/*.md` の陳腐化箇所一覧 | M3・M4・M5 |
| [`e2e-test-infra.md`](e2e-test-infra.md) | 4-Tier E2E テスト設計（`StrictWingsFake` / `Html5DomAssert` / 公式 Egg・Modpack フィクスチャ）、Feature #1〜#22 トレーサビリティ、Tier 4 定量性能基準、Iteration 2 改修記録 | M2〜M6 の自動検証 |

---

## 1. 完了済みの実装とコミット

### Commit 1: `875e30f` — `Fix Modrinth tile layout collapse and unify catalog view handling`（完了・検証済み）

UI 不具合 2 件（依頼 §2）を Filament 標準レイアウトのみで解消し、専用コミットとして分離済みです（`resources/assets/mod-manager.css` への場当たり的 CSS 追加なし）。

#### 根本原因と修正内容

1. **Modrinth タイル表示崩れ（2つの独立した原因を特定・解消）**
   - **主因（Modrinth 固有）**: Filament 5.11.4 の `contentGrid` はカード全体を `<a href="$recordUrl" class="fi-ta-record-content">` で囲む一方、`CatalogTileColumns` が `author` カラムに状態依存の `->url($authorUrl, true)`（`https://modrinth.com/user/...`）を付与していたため、HTML5 違反の入れ子 `<a>` が生成されていた。ブラウザの HTML5 Adoption Agency Algorithm が外側 `<a>` を 6 断片に破壊し、`fi-ta-split` 先頭に `width: 100%` の空 `<a>` を挿入することで、`date_modified` が幅 17px（日本語 1 文字ずつ折り返し）に圧迫されていた。
     - **修正**: `CatalogTileColumns`・`ModManagerPage`・`ModpackCatalogTable` から作者ページリンク機能および未使用の `authorUrl()` を廃止し、全配布元で作者名を通常テキスト表示に統一。プロジェクト自体の外部リンク（`->recordUrl(..., true)`）は完全維持。
   - **副因（全配布元・全カタログ共通）**: タイル下部に 3 項目（`author` / `downloads` / `date_modified`）を 1 つの水平 `Split` で並べていたため、長大な作者名や `ja`/`en`/`de` の日付表記で 1 文字折り返しや 2 行折り返しが発生していた（`contentGrid` は `<table>` と異なり `whitespace-nowrap` を持たず、`.fi-ta-col.fi-growable` に `min-width: 0` がない）。
     - **修正**: Filament 標準のネスト構成 `Stack::make([author->lineClamp(1)->toggleable(), Split::make([downloads->toggleable(), date_modified->grow(false)->alignEnd()->toggleable()])])->space(1)` に変更（カスタム CSS ゼロ）。3 項目に `->toggleable()` を維持することで、フィルタのない配布元（FTB/Technic/ATLauncher）でもツールバーの表示切替フックが表示される Filament 5.11.4 の制約を順守。
   - **境界修正**: `date_modified` に不正日付文字列が渡された際に孤立カレンダー SVG が出力される問題を `->state(...)` による `?string` 返却と raw 値 `->tooltip(...)` で解消。

2. **Modpack カタログ初期表示・表示設定統一・Filament `HasColumnManager` カラム消失不具合**
   - **初期表示の調査結果**: クリーンなセッション／Cookie 環境では全カタログ（Mod/Plugin/Datapack/ResourcePack/Modpack Create Wizard/Modpack Server Modal）とも初期値は `'list'`。Modpack がタイル表示で開く現象は、全カタログ共通のセッションキー（`pelican-mod-manager.catalog-view`）＋1 年 Cookie（`mmr_catalog_view`）の共有、およびモーダル切替時の親 `ModManagerPage` との Livewire 状態乖離（`Installed` タブの強制リスト表示との不一致）によるものだった。
   - **修正**:
     - `CatalogTileColumns` に `normalizeView()`・`resolveInitialView()`・`storeView()`・`COOKIE_MINUTES`・`VIEW_CHANGED_EVENT` を集約し、`ModpackCatalogTable::table()->contentGrid()` もクロージャ形式に統一。
     - `ModpackCatalogTable::toggleCatalogView()` から `VIEW_CHANGED_EVENT` を送出し、`ModManagerPage` 側で `#[On(CatalogTileColumns::VIEW_CHANGED_EVENT)] syncCatalogView(mixed $view = null)` を受けて親とモーダルの表示状態を同期（`null`/配列等の不正ペイロード耐性あり、`Installed` タブの強制リスト表示を維持）。
     - **Filament 5 `HasColumnManager` 欠陥（Bug B1/B2）を修正**: タイル表示で初回マウントした後にリスト表示へ戻すと `$this->tableColumns` が空のまま全 `toggleable()` カラムが消失する問題（Bug B1）と、タイル表示中に `applyTableColumnManager()`/`resetTableColumnManager()` が走ってセッションのカラム表示設定を `[]` で汚染する問題（Bug B2）を、`hasColumnsLayout()` ガードと `syncTableColumnManagerAfterViewChange()` で両コンポーネントにて修正。
     - `resources/assets/table-swr-cache.js` のキャッシュキーおよび遷移記述に `effectiveCatalogView(wire)` を組み込み、廃止済み Modrinth 作者 URL コメントを削除。

#### 変更ファイル（M1 コミット `875e30f` のみ、7ファイル）
- `src/Support/CatalogTileColumns.php`
- `src/Filament/Components/ModpackCatalogTable.php`
- `src/Filament/Server/Pages/ModManagerPage.php`
- `resources/assets/table-swr-cache.js`
- `tests/Unit/Filament/ModpackCatalogTableTest.php`
- `tests/Unit/Filament/ModManagerBrowserAssetsTest.php`
- `tests/Unit/Filament/CatalogViewUnificationTest.php`（新規・1,334行）

---

## 2. 未完成の実装と作業途中の変更

完成済みの M1 プロダクションコード（Commit 1 `875e30f`）とは**明確に分離した別コミット**として以下を保存しています。

| 区分 | コミット／保存先 | 状態 | 完成コードとの分離方針 |
|---|---|---|---|
| **E2E テスト基盤（WIP）** | Commit 2（`tests/E2E/**`、31ファイル） | Iteration 2 実装＋作成者側フル検証完了（81テスト：29 PASS / 52 期待FAIL / 0 Errors）。独立レビューウェーブはクォータ節約のため停止。 | `tests/E2E/phpunit-e2e.xml` として独立配置。ルート `phpunit.xml`（`tests/Unit` のみ実行）には未接続のため CI（Unit / PHPStan / Pint）に影響しない。`tests/E2E/README.md` に WIP 明記。 |
| **引き継ぎ・設計・監査資料** | Commit 3（`docs/handoff/**`、7ファイル） | 全 3 系統の詳細調査、M1〜M6 計画、M2 実装設計書（2/3 完了＋1/3 チェックリスト）を移植可能な形で保存。 | 本番ドキュメント（`docs/*.md` / `README*.md`）には未混入。M5 完了時に統合・整理する。 |
| **M2（コア基盤・HTTP/Wings/キャッシュ・スケジューラ）** | **未実装**（`src/` 変更ゼロ） | 探索 2/3 完了（[`m2-implementation-blueprints.md`](m2-implementation-blueprints.md) に全文保存済み）。残り 1/3（`io_warm_assets_diag`）は同文書 Part 3 にチェックリスト化済み。 | コード変更なし。次セッションで即実装可能。 |
| **M3（Modpack 互換性・メタデータ・クライアント専用除外）** | **未実装**（`src/` 変更ゼロ） | 設計・エッジケース・E2E テスト仕様まで確定済み。 | コード変更なし。 |
| **M4（大規模パック一括展開・中断/再開・既存サーバー移行）** | **未実装**（`src/` 変更ゼロ） | 設計・Interface Contracts・Tier 4 性能基準・E2E テスト仕様まで確定済み。 | コード変更なし。 |
| **M5（ライセンス・クレジット §6・ドキュメント刷新）** | **未実装**（`README*.md` / `docs/*.md` / `CHANGELOG.md` 変更ゼロ） | 更新対象・記載書式・陳腐化箇所一覧を確定済み。 | コード変更なし。 |
| **M6（最終総合検証・E2E 統合・CI確認）** | **未着手** | M2〜M5 完了後に実施。 | — |

> ローカル VM 上の `.agents/teamwork/`（70ファイル超のエフェメラル実行ログ等）は未追跡のままコミット対象外としています。価値ある調査・設計成果はすべて `docs/handoff/` に抽出済みです。

---

## 3. 比較調査で得られた知見と改善方針

`cosmii02/pelican-modpackmanager`（v1.7.3、`ddc2e364`、MIT）の全ソース（`ModpackInstallService.php` 3,845行等）および公式 `pelican-eggs/minecraft@c6f2262`・`pelican-dev/panel@f880625`・`pelican-dev/wings@27f67c8`・実配布物（FTB Evolution 2 17,747ファイル / FTB StoneBlock 3 / FTB Inferno / ATLauncher SevTechAges / Cobblemon 100.8MB `.mrpack` / Fabric MC 26.1.2）を実地調査した結果の要点です（詳細は [`comparative-audit-cosmii02.md`](comparative-audit-cosmii02.md) および [`external-specs-and-docs-reeval.md`](external-specs-and-docs-reeval.md)）。

### 3.1 総合評価

- **`pelican-mod-manager`（現行）が圧倒的に優れている領域（必ず維持）**:
  - 暗号学的整合性検証（SHA-256 / SHA-1 / SHA-512 / MD5 / サイズ / `ZipArchive::CHECKCONS`）
  - ステージング → 検証済み逆ロールバック → クラッシュリカバリ台帳（`mod_manager_modpacks`）＋ Pelican バックアップロック＋ `confirmRestored()`
  - ユーザー編集済み config や個別導入 Mod の保護、`.pelicanignore` 回避のフルバックアップ、サーバー停止確認
  - CurseForge 外部配布制限（`allowModDistribution: false`）に対する 7 日間一時停止＋ハッシュ検証済み手動アップロード再開フロー
  - Modrinth `server-overrides/` の優先適用および `client-overrides/` 除外
  - 新規サーバー作成 Wizard 統合、キュー駆動非依存のバックグラウンドジョブ基盤（`mod_manager_background_jobs`）、`EggCompatibilityEngine`（Egg 改変なし）、Java 下限＋**上限**判定、NeoForge 1.20.1 座標解決、Technic 公式 Solder 対応、744件のユニットテスト
- **`cosmii02/pelican-modpackmanager` が優れている領域（安全化して取り入れる）**:
  - **大規模パックのスループット**: Panel 並列ダウンロード＋上限付き ZIP バンドル（`<= 64 MiB`）＋ Wings `/files/decompress`＋バッチ `renameFiles`/`deleteFiles`（最大 200件/回）。現行の直列 3N 方式（FTB Evolution 2 で約 48,200 回の直列 HTTP リクエスト＋全件 Wings→Panel 読み戻し＋旧 Wings の gzip `Content-Length` 欠落エラー）を 100〜1,000 倍改善できる。
    - *重要設計変更*: `cosmii02` は `NodeJWTService` の `/upload/file`（`api.upload_limit` デフォルト 100 MiB 制限あり＆非本番 TLS 検証無効バグあり）を使うが、`pelican-dev/wings@27f67c8` のソース検証により、**認証済み Wings `/files/write`（明示的 `Content-Length` 付き `putStream`）** を使えば TLS 厳格維持・デッドライン解除済み・`api.upload_limit` 上限なし（ディスククォータのみ）でさらに安全かつ確実に転送できることが判明した。
  - **送信前 cURL リトライ**: `RetryingDaemonFileRepository` パターン（`cURL error 6/7/35` および `cURL error 28: Resolving/Connection timed out` のみ `[500, 2000, 5000]ms` で再試行 — 非冪等な `rename`/`delete` でも安全）。
  - **既存サーバーの Egg / ローダーバージョン切替＋再インストール**: `EggChangerService(..., keepOldVariables: false)` ＋ `skip_scripts = false` ＋ `ReinstallServerService` 完了待ち（ただし **Panel に導入済みの既存 Egg のみ** に限定）。
  - **無制限メモリ（`memory=0`）Fabric 起動保護**: `-Xmx{{SERVER_MEMORY}}M` → `-XX:MaxRAMPercentage=95.0` 置換（`-Xmx0M` クラッシュ防止）。
  - **処理中の協調的キャンセル**、**CurseForge `classId` ルーティング**、**最大 3 階層のサーバーパックラッパー展開**、**`*-installer.jar`/`startserver.sh` からの読み取り専用メタデータ抽出**、**FTB 0 バイトファイル＆`curseforge` フォールバック＆人気パック一覧（`/popular/installs/{limit}`）**、**クリーン切替時の安全な `server.properties` ホワイトリストマージ＋`level.dat` ワールド保護**。
- **両プロジェクトに共通する課題（独自に解決する）**:
  - **Fabric MC 26.1+ 非難読化対応**: `ModpackRuntime::assertInstalled()` が存在しない `intermediary` JAR を必須視し、MC 26.x Fabric が 100% 失敗する致命的バグ。
  - **Modrinth 大容量 `.mrpack`（> 8 MiB、例: Cobblemon 100.8 MB）**: `inspectModrinthArchive()` が 8 MiB 全文 DL で打ち切る問題を HTTP Range 読み取り（`RemoteZipReader`）で解決。あわせて `CurseForgeManifestReader` が `edge.forgecdn.net` の 302→`mediafilez.forgecdn.net` リダイレクトを拒否しているバグも許可リスト付き HTTPS リダイレクト＋SSRF 検証で解決。
  - **Modrinth `downloads[1..n]` ミラー保持**および **`overrides/`・`server-overrides/` の 2 MiB / 32 MiB メモリ上限撤廃（ZIP ストリーミング化）**。
  - **CurseForge クライアントパックのクライアント専用 Mod 除外**: 名前推測ではなく、`classId=6552`（Shader）スキップ＋JAR 内公式メタデータ（`fabric.mod.json` / `quilt.mod.json` / `META-INF/(neo)?mods.toml`）による誤検知ゼロの確実な除外（`JarClientSideInspector`）。
  - **Panel 側 HTTP ダウンロードの DNS＋リダイレクト全ホップ SSRF 防止**（`SafeHttpDownload`）。
  - **FTB 実パック互換性**: `structurize/`（FTB Inferno で 240 ファイル）・`global_data_packs/`・`paxi/`・`flan/`・`local/ftb*` 対応、`optional: true` 尊重。
  - **既存サーバー更新時の 3 回フル読み戻し解消**: `mod_manager_modpacks` 台帳に `(size, modified_at)` を記録し、変更のないファイルの `readStream()` を省略する増分高速パス。
- **明示的に不採用とする `cosmii02` の設計（採用禁止）**:
  1. パック同梱シェルスクリプト（`bash start.sh` / `run.sh` / `startserver.sh`）の実行（任意コマンド実行・再起動ループ・Pelican 停止シグナル阻害）
  2. 可変ブランチ（GitHub `refs/heads/main`）からの Egg 自動インポート（サプライチェーンリスク）
  3. 共有 Panel Egg の DB 直接改変（`prepareOfficialQuiltEgg` による `script_install` / `egg_variables` / `tags` 書き換え）
  4. `allowModDistribution: false` に対する `edge.forgecdn.net` URL 再構築（CurseForge API 利用規約違反＋実測 `403 Forbidden`）
  5. CurseForge `/additional-files` の HTML スクレイピングおよび `"server"` 部分一致推測
  6. ダウンロード・検証前の既存サーバーファイル／ランタイム破壊削除（ロールバックなし）
  7. ハッシュ検証の省略
  8. 本番環境以外での Wings TLS 検証無効化
  9. `DB::table('jobs')` 直接参照＋`@unserialize()`＋`$tries = 0` ハック（キュー駆動依存）
  10. 不完全な ATLauncher 自動導入（`type=extract` 欠落、`download=browser` 欠落、`optional=true` 全導入）— ATLauncher は現行の閲覧専用を維持
  11. カスタム CSS / Command-Deck UI の導入（依頼 §5 で厳禁）

### 3.2 ライセンス・クレジット方針（§6 / M5 で反映）

- 本プラグインは **GPL-3.0**、`cosmii02/pelican-modpackmanager` は **MIT License (`Copyright (c) 2026 cosmii02`)** であり、GPL-3.0 への一方向互換性あり。
- M5 で `README.md`（`## Credits & acknowledgements`）、`README.ja.md`（`## クレジット・謝辞`）、`docs/modpack-compatibility-audit.md` に、既存の `JoanFo1456/resources` 記載と同じ書式でプロジェクト名・GitHub URL・MIT ライセンス・著作権表示（`Copyright (c) 2026 cosmii02`）・参考／応用した設計内容を明記する。

---

## 4. E2E テスト基盤の現状と残存課題

詳細は [`e2e-test-infra.md`](e2e-test-infra.md) および [`tests/E2E/README.md`](../../tests/E2E/README.md) を参照してください。

### 現状（Commit 2 として `tests/E2E/**` に保存済み）
- **構成（計 31 ファイル、81 PHPUnit テスト）**:
  - `tests/E2E/bootstrap.php`, `tests/E2E/phpunit-e2e.xml`, `tests/E2E/README.md`
  - `tests/E2E/Support/`（`StrictWingsFake`, `E2ETestCase`, `Html5DomAssert`, `CreateServerWizardHarness`, `StrictWingsFakeSelfTest`）
  - `tests/E2E/Fixtures/`（`OfficialPelicanEggFixtures`, `ModpackArchiveFixtures`, `LoaderRuntimeFixtures`, `FixturesSelfTest`）
  - `tests/E2E/Tier1/`（7ファイル：機能別カバレッジ #1〜#22）
  - `tests/E2E/Tier2/`（4ファイル：境界・SSRF・敵対的アーカイブ・ロールバック・UI 境界）
  - `tests/E2E/Tier3/`（4ファイル：複合ワークフロー）
  - `tests/E2E/Tier4/`（4ファイル：600ファイル FTB・300ファイル ATM10 増分更新・9.5 MiB Cobblemon MC 26.1.2・既存サーバー切替＆ロールバックの定量性能検証）
- **特徴**:
  - `StrictWingsFake` は `Http::fake()` レイヤーで実 Wings (`27f67c8`) の仕様を忠実に再現（`/rename` の `400 os.ErrExist`、`/write` の `Content-Length` 必須、`/decompress` の実 `ZipArchive` 展開＋zip-slip/symlink/NUL 検査、`/pull` の `disable_remote_download`・同時3件制限・SSRF 遮断、cURL 障害注入、HTTP 回数・転送バイト数メトリクス）。
  - Iteration 1 監査で検出された 4 件の重大問題（トートロジー、未定義定数、ソース正規表現チェック、`app()` 依存）および 6 件のハーネス不備は Iteration 2 で全件解消済み。
- **最新の検証結果（Iteration 2 作成者実行）**:
  - `php -l` 29/29 PASS、Pint 29/29 PASS（リポジトリ全体 281/281 PASS）、PHPStan L6（Support+Fixtures）0 errors、PHPStan L2（全 `tests/E2E`）0 errors
  - `E2E SelfTest`: **13/13 PASS**（274 assertions）
  - M1 UI ライブ E2E（3ファイル）: **9/9 PASS**（665 assertions）
  - 全 E2E スイート: **29 PASS / 52 期待 FAIL（未実装 M2〜M5 機能） / 0 Errors / 0 Skipped / 0 Risky**
- **残存課題**:
  1. Iteration 2 に対する独立コードレビュー／監査ウェーブはクォータ節約のため未完了（次セッションで M2〜M5 の各機能を実装しつつ、対応する E2E テストが意図どおり FAIL → PASS へ遷移することを確認しながら必要に応じてテストの細部を調整すれば十分で、独立した大規模並列レビューをやり直す必要はない）。
  2. M6 完了時に `tests/bootstrap.php` へ PSR-4 1 行と `phpunit.xml` へ E2E testsuite 1 ブロックを追加し、GitHub Actions CI で自動実行されるようにする。

---

## 5. M2〜M5 の計画・優先順位・実装上の注意点

詳細な仕様・Interface Contracts #2〜#6 は [`project-plan-m2-m5.md`](project-plan-m2-m5.md)、M2 の行レベル設計は [`m2-implementation-blueprints.md`](m2-implementation-blueprints.md) をそのまま参照してください。

### 優先順位とマイルストーン構成（依頼 §8 のコミット分割方針に準拠）

| 順 | マイルストーン | 対象 Feature | 最重要ポイント | コミット単位 |
|---:|---|---|---|---|
| **1** | **M2: コア信頼性・共通 HTTP/キャッシュ・Wings 転送・スケジューラ** | #5〜#12 | **Critical 2件**（設定保存時の `modrinth_token`/`hangar_api_key` 消失修正、逐次スキャン `openDaemonFileStream()` の `$response->throw()` 欠落修正）＋ M4 の土台となる `WingsRemoteFilesystem`（送信前 cURL リトライ、`putStream` `Content-Length`、`decompressFile`、`renameBatch`/`deleteBatch`、`Range` 付き `readPrefix`）＋ `UpstreamHttp` ＋ `SourceCache` ＋ スケジューラ競合/空回り対策 | 論理コミット 1〜2 本（設定/スキャン/操作・HTTP/Wings/キャッシュ） |
| **2** | **M3: Modpack 互換性・メタデータ取得・クライアント専用除外・ランタイム検証** | #13〜#18 | **Fabric MC 26.1+ 非難読化対応**＋動的 `serverJar=`、**`RemoteZipReader` による `.mrpack` Range 読み取り＆許可リダイレクト**、Modrinth ミラー保持＆ZIP ストリーミング `overrides`、**CurseForge `classId` ルーティング＆`JarClientSideInspector`**、FTB `structurize`・0 バイト・`curseforge` フォールバック・人気一覧、3階層ラッパー展開・読み取り専用メタデータ・Egg ルール検証・Fabric `-Xmx0M` 保護 | 論理コミット 1 本 |
| **3** | **M4: 大規模パック一括展開・中断/再開・既存サーバー移行** | #19〜#21 | **`SafeHttpDownload`（DNS+リダイレクト SSRF 防止）**＋**`ModpackBundleUploader`（Panel 並列 DL・1パス暗号検証・`<=64 MiB` ZIP パート・認証済み `/files/write`＋`/files/decompress`＋バッチ rename/delete＋`(size, modified_at)` 増分台帳）**、**協調的キャンセル（`ModpackCancelledException`）＆進捗＆ハートビート更新**、**死コード `ServerEggMigration` の修正・接続（導入済み Egg のみ、`keepOldVariables: false`、`skip_scripts = false`）＋ `ServerPropertiesMerger` ＋ 任意クリーン切替** | 論理コミット 1〜2 本 |
| **4** | **M5: ライセンス・クレジット §6・ドキュメント刷新** | #22 | `README.md` / `README.ja.md` / `docs/modpack-compatibility-audit.md` へ `cosmii02/pelican-modpackmanager`（MIT, `Copyright (c) 2026 cosmii02`）クレジット追記、既存 `docs/*.md` 5ファイルの陳腐化修正、`README*.md` 環境変数表更新、`CHANGELOG.md` `[Unreleased]` 日英更新、`docs/handoff/` の整理 | 論理コミット 1 本 |
| **5** | **M6: 最終検証・E2E 統合・CI 確認** | #24 | `tests/E2E` 全 81 テスト PASS、ルート `phpunit.xml` 統合、PHPUnit / PHPStan / Pint / `validatePluginJson.php`、実配布物・性能比較検証、PR #2 通常 push & GitHub Actions 全ジョブ PASS 確認 | 論理コミット 1 本（必要時） |

### 実装上の重要な注意点（事前調査で判明済みの罠）

#### M2 の罠（[`m2-implementation-blueprints.md`](m2-implementation-blueprints.md) で確定済み）
1. **PHP 8.4 LSP 破壊（テストダブル 3 箇所）**: `WingsRemoteFilesystem::putStream()` に `StreamInterface|string $stream, ?int $contentLength = null` を追加する際、`InstalledArchiveTransactionTest`（`RecordingWingsRemoteFilesystem`）、`ModpackDeploymentTest`（`DeploymentWings`）、`ModpackInstallerTest`（`MemoryModpackWings`）の 3 サブクラスのシグネチャを同時更新しないと PHP Fatal Error になる。
2. **`pullForeground()` への引数追加は避ける**: 上記 3 テストサブクラスが 5 引数固定でオーバーライドしているため、進捗／ハートビートコールバックは `WingsRemoteFilesystem::withProgress(?Closure $onProgress, Closure $operation)` のスコープ付きラッパーで渡す。
3. **`InstalledOperationManager::__construct()` の `$config` 引数**: 18 テストファイルに 40 箇所以上の位置引数呼び出しがあるため削除せず `?ConfigRepository $config = null` の任意引数として残す。
4. **`InstalledOperationManager::operationStateOrActive()` 拡張時の既存テスト更新**: `OPERATION_MODPACK` を追加すると cache `get()` が 2 回→3 回になるため、`InstalledOperationManagerTest.php`（L56, L69, L102, L353）の `->twice()` を `->times(3)` に更新する。
5. **`SourceCacheTest.php` / `ModpackCreationWizardTest.php` の既存アサーション更新**: 旧バグを直接検証している `SourceCacheTest.php:167,348` および HTTP 503 リトライで送信回数が 1→3 回に増える `ModpackCreationWizardTest.php:448` を合わせて更新する。
6. **Spigot `'size'` の罠**: Spiget の `KB`/`MB` 表記は丸め誤差があるため、バイト換算して `'size'` に入れてはならない（`InstalledArchiveTransaction` の厳密バイト一致で 99.9% 失敗する）。`B`/`byte`/`bytes` の厳密整数または `probeDownloadArchive()` の `Content-Range`/`Content-Length` 取得時のみ設定する。
7. **`UpstreamHttp::json()` の後方互換**: `float|array $timeout = 10.0` で受け、`->retry($maxRetries + 1, ..., throw: false)` を徹底する（`throw: false` がないと `->throw()` を呼ばない診断・Spigot 呼び出し元が壊れる）。
8. **`WingsRemoteFilesystem` コンストラクタの DI**: `?Closure` ではなく `?callable $sleeper = null` にしないと Laravel コンテナが `Closure` の解決を試みて低速パスに入る。
9. **プラグインスキャン二重 DL 防止**: `InstalledProjectService::computeScanHashesBatch()` でハッシュ失敗／サイズ超過スキップしたファイルは `$preextractedDescriptors[$filename] = null` を明示設定し、後続の `identifyRemainingFromArchiveMetadata()` で再ダウンロードさせない。
10. **UI 変更厳禁（§5）**: `ModManagerPage.php` の M2 編集は `performUninstall()`（L2388 の直叩き `Http::daemon()` → `WingsRemoteFilesystem::delete()` 置換と `use` 整理）のみに限定する。

#### M3・M4 の罠
1. **Wings `/files/rename` は移動先既存時に `400 os.ErrExist` を返す**: 既存ユニットテストの `DeploymentWings` は上書きを許してしまい実機と乖離している（`StrictWingsFake` は正しく `400` を返す）。上書き対象は必ず先にバックアップ／削除してからリネームする。
2. **Wings `/files/write` は `Content-Length` 必須（`-1` や chunked は `400`）**: パート ZIP やストリームの書き込み時は必ず実バイト長を渡す。
3. **既存サーバー移行時の `skip_scripts`**: Pelican の `EggChangerService::handle()` は `$server->skip_scripts` をリセットしないため、`ReinstallServerService` 実行前に必ず `$server->skip_scripts = false` を保存する。また `keepOldVariables: false` を使い、旧ローダー変数（`FORGE_VERSION` 等）の残留を防ぐ。
4. **Modpack 更新時の UI 制約（§5）**: M4 でキャンセル操作やクリーン切替／Egg 移行の導線が必要な場合も、既存 Filament 標準 Action／通知の最小限フックのみに留め、カスタムデザインや画面刷新は行わない。

---

## 6. 実施済みの検証と未検証の項目

| 対象 | 実装状況 | 検証状況 | 詳細・根拠 |
|---|---|---|---|
| **ベースライン確認（`4e5f839`）** | — | **検証済み** | PHP 8.4 + 隔離 Pelican Panel で PHPUnit 729 tests / 4,515 assertions PASS、CI PHPStan 0 errors、Pint 251 files PASS、`validatePluginJson.php` PASS。 |
| **M1: UI 不具合修正（`875e30f`）** | **実装済み** | **検証済み** | PHPUnit **744 tests / 5,064 assertions** PASS、CI PHPStan `781/781` (0 errors)＋M1 対象 6 PHP ファイル新規エラー 0、Pint PASS、`validatePluginJson.php` PASS、`node --check table-swr-cache.js` PASS、Headless Chrome 実測（46 レンダー / 277 カード / 3 ロケール / 7 配布元＋SWR JS 21/21 PASS）、Livewire ライフサイクル境界プローブ（12 tests / 2,168 assertions PASS）、M1 UI ライブ E2E（9/9 PASS / 665 assertions）。 |
| **E2E テスト基盤（`tests/E2E/**`）** | **実装済み（WIP）** | **部分検証済み** | `php -l` 29/29 PASS、Pint 29/29 PASS（全リポジトリ 281/281 PASS）、PHPStan L6（Support+Fixtures）0 errors、PHPStan L2（全 `tests/E2E`）0 errors、`E2E SelfTest` 13/13 PASS、M1 UI E2E 9/9 PASS、全 81 テスト中 29 PASS / 52 期待 FAIL / 0 Errors。**未実施**: Iteration 2 の独立レビュー／監査ウェーブ（クォータ効率化のため省略）。 |
| **M2: コア基盤・HTTP/Wings/キャッシュ・スケジューラ** | **未実装** | **未検証** | 設計・2/3 行レベル実装ブループリント作成済み。 |
| **M3: Modpack 互換性・メタデータ・クライアント専用除外** | **未実装** | **未検証** | 設計・17項目比較・公式仕様確認済み。 |
| **M4: 大規模パック一括展開・中断/再開・既存サーバー移行** | **未実装** | **未検証** | 設計・Interface Contracts・Tier 4 性能基準定義済み。 |
| **M5: ライセンス・クレジット §6・ドキュメント刷新** | **未実装** | **未検証** | 記載方針・対象箇所一覧確定済み。 |
| **M6: 全体統合・性能前後比較・実配布物確認・PR #2 CI** | **未着手** | **未検証** | ※本引き継ぎ push 時点の PR #2 CI（M1 + 隔離 E2E + 引き継ぎ docs）は push 後に確認可能。認証付き CurseForge Core API 実通信および実 Docker イメージ（`yolks:java_*`）による実機 Wings 起動は環境制約上、次セッションでもモック＋読み取り専用実配布物検証の範囲を明示して評価すること。 |

---

## 7. 次回の通常エージェントセッション向け進め方

次のセッションでは **Teamwork や多段並列レビューを起動せず、単一エージェントで順次・効率的に** 進めることを推奨します。

1. **コンテキスト読み込み（数分で完了・再調査不要）**:
   - 本ファイル（`docs/handoff/HANDOFF.md`）と [`docs/handoff/project-plan-m2-m5.md`](project-plan-m2-m5.md) を読む。
   - M2 着手時は [`docs/handoff/m2-implementation-blueprints.md`](m2-implementation-blueprints.md) をそのまま実装ガイドとして使い、未探索の Part 3（6 クラス）だけ対象ソースを直接読んで実装する。
2. **検証ハーネスは 1 回だけ作って再利用する**:
   - 一時ディレクトリに `pelican-dev/panel` ハーネスを 1 つ作成してセッション中は再利用し（毎回 `git clone` / `composer install` し直さない）、各マイルストーン完了時に `rsync` → PHPUnit（`phpunit.xml`＋該当 `tests/E2E` フィルタ）→ 変更ファイル PHPStan → Pint のみ実行する。
3. **マイルストーンごとの完了・コミットリズム**:
   - **M2 実装** → ユニットテスト + E2E `Core*` PASS + PHPStan/Pint → コミット
   - **M3 実装** → ユニットテスト + E2E `ModpackRuntime*`/`ModpackProviderCompat*`/`ModpackMetadata*`/`ModpackRange*` PASS → コミット
   - **M4 実装** → ユニットテスト + E2E `ModpackBundled*`/`ModpackDeploy*`/Tier 4 全 4 本 PASS + 変更前後の性能比較記録 → コミット
   - **M5 実装** → クレジット・`docs/*.md`・`README*.md`・`CHANGELOG.md` 更新 + E2E `DocsCreditsComplianceE2ETest` PASS + `docs/handoff/` の整理・削除 → コミット
   - **M6 最終確認** → `tests/E2E` を `phpunit.xml` へ統合（または必要に応じて構成調整）→ 全テスト・PHPStan・Pint・`validatePluginJson.php` PASS → `fix/modpack-wizard-spigot` へ通常 push → PR #2 の GitHub Actions CI グリーン確認。
4. **厳守制約の再確認**:
   - `main` へのマージ・`force push`・Release 作成・本番環境変更は禁止。
   - Pelican Panel / Wings 本体の改造禁止、追加常駐プロセス禁止。
   - Filament 標準 UI を維持し、不要な UI 変更・カスタムデザイン導入を行わない。
