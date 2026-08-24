# pelican-mod-manager 改修設計書

対象リポジトリ: `kazaminosuke/pelican-minecraft-modrinth` (branch: main)
作成日: 2026-08-06
目的: Codex への段階的実装依頼のマスタードキュメント

---

## 確定方針

| 項目 | 決定 |
|---|---|
| 新名称 | `pelican-mod-manager` / "Minecraft Mod Manager" |
| plugin id 変更 | 一連の改修の**最後**に実施（方式B: 橋渡しリリース） |
| リポジトリ名変更 | plugin id 変更と同じタイミング |
| 着手順 | リング0リネーム → パフォーマンス改善 → README → 最終リネーム |

---

## 環境の前提（実コードで確認済み）

| 項目 | 値 | 確認元 |
|---|---|---|
| Pelican Panel | main（`filament/filament: ^5.6`） | `composer.json` |
| Filament | **v5.7.5** | `composer.lock` |
| SPA モード | **有効**（console ページを除く） | `app/Providers/Filament/PanelProvider.php:32` |
| SPA プリフェッチ | **無効**（`spa()` の第2引数省略 = false） | 同上 + `Filament\Panel\Concerns\HasSpaMode` |
| ServiceProvider 自動登録 | `src/Providers/*.php` を走査し `{namespace}\Providers\{Name}` として登録 | `app/Models/Plugin.php:382-391` |
| プラグインクラス解決 | `\{plugin.json.namespace}\{plugin.json.class}` | `app/Models/Plugin.php:213`, `PluginService::loadPanelPlugins()` |
| id とフォルダ名 | **一致必須**（不一致で `PluginIdMismatchException`） | `app/Models/Plugin.php:136` |
| 更新時の展開先 | **ZIP ファイル名**から決定。`cleanDownload` は `plugins/<zip名>` を削除 | `PluginService::downloadPluginFromFile()` |

---

## 未検証事項3点の扱い

### 検証A: `wire:navigate` の使用有無 → ✅ **本設計書作成時点で確認完了**

**結果:**
- Pelican は `->spa(fn () => !request()->routeIs('filament.server.pages.console'))` を呼んでいる（`PanelProvider.php:32`）。第2引数 `$hasPrefetching` は**省略 = false**。
- Filament の `generate_href_html()`（`packages/support/src/helpers.php:148-155`）は、
  - `hasSpaPrefetching()` が true → `wire:navigate.hover`
  - false → **素の `wire:navigate`**
- → **サイドバーの mod-manager リンクは `wire:navigate` だが、hover プリフェッチは効いていない。**

**設計への反映:**
- 前回案の「P2(b) hover prefetch に期待する」は**却下**。プラグイン側から `spa(true, true)` を有効化するとパネル全ページに影響するため実施しない。
- 代わりに **P2(a) サーバサイドウォームジョブを主軸**に据える（Stage 5）。
- 残る小確認: 素の `wire:navigate` が mousedown 時にプリフェッチするかは Livewire の実装依存。**Stage 5 の冒頭で 5 分だけ確認**（DevTools Network で nav リンクを mousedown した際にリクエストが飛ぶか）。飛ばなくても設計は成立するため、ブロッカーではない。

### 検証B: `deferLoading()` の受け入れ引数 → ✅ **確認完了**

**結果:**（`filament/tables` v5.7.5）
```php
// Filament\Tables\Table\Concerns\CanDeferLoading
public function deferLoading(bool | Closure $condition = true): static
public function isLoadingDeferred(): bool { return (bool) $this->evaluate($this->isLoadingDeferred); }

// Filament\Tables\Concerns\CanDeferLoading
public bool $isTableLoaded = false;
public function loadTable(): void { $this->isTableLoaded = true; }
public function isTableLoaded(): bool {
    if (! $this->getTable()->isLoadingDeferred()) { return true; }
    return $this->isTableLoaded;
}
```

- **Closure を受け付ける**。`evaluate()` されるので DI 注入も可能。
- `isLoadingDeferred()` が false を返すと `isTableLoaded()` は**常に true** → `$this->isTableLoaded = false` の代入は自動的に無効化される。条件付き defer が素直に書ける。
- `$isTableLoaded` は **public プロパティ**（Livewire でシリアライズ対象）。

**設計への反映:** Stage 6（P3）は実装可能。ただし SWR Blade との相互作用に未知があるため、Stage 6 内で統合検証を行う。

### 検証C: `raw.githubusercontent.com` のリポジトリリネーム時の挙動 → ⏳ **Stage 8-A で実測する**

実リネームなしには確定できないため、**Stage 8 の 2 週間前に専用の検証手順**を実施する（Stage 8-A に手順を記載）。

ただし、**検証結果に依存しない恒久的な回避策**を Stage 8-B の橋渡しリリースに含める:

> `update_url` を、**リネームされることのない専用リポジトリ**（例: `kazaminosuke/pelican-plugin-updates`）に分離する。

これにより、以後どのリポジトリをリネームしても更新チェックが切れなくなる。検証Cは「既存インストールの救済がどこまで必要か」を判断するために行う。

---

## 全体ロードマップ

| Stage | 内容 | 旧ラベル | リスク | 依存 |
|---|---|---|---|---|
| **S1** | リング0 リネーム（内部命名整理のみ） | リング0 | 低 | — |
| **S2** | 無駄の除去 | P0 | 低 | S1 |
| **S3** | SWR キャッシュ層の一元化 | P1 | 中 | S2 |
| **S4** | Installed タブの progressive enrichment 化 | P4 | 中〜高 | S3 |
| **S5** | プリロード（ウォームジョブ + スロットル） | P2 | 中 | S3 |
| **S6** | 条件付き defer（2往復目の削減） | P3 | 中 | S3, S5 |
| **S7** | README 改修 | README | 低 | S2〜S6 |
| **S8** | plugin id 変更 + リポジトリ名変更 | 最終リネーム | 高 | 全て |

**実行順序の注意:** 旧ラベルの P2/P3/P4 と実行順が入れ替わっている。P4（Installed タブ）は効果が大きく他への依存が少ないため P2/P3 より前に置いた。以降は **S1〜S8 の番号で統一**する。

```
S1 リング0 ──> S2 P0 ──> S3 P1(SWR) ──┬──> S4 P4(Installed)  ──┐
                                       ├──> S5 P2(preload) ────┼──> S7 README ──> S8 最終リネーム
                                       └──> S6 P3(defer) ──────┘
                                              (S5 完了後)
```

---

# Stage 1 — リング0 リネーム

## 目的
plugin id・ディレクトリ構造・インストールパス・ユーザーデータに一切触れず、**内部識別子のみ**を新名称に揃える。ユーザー移行はゼロ。

## 絶対に変更してはならないもの（S8 まで据え置き）

**ルール: 文字列リテラル `pelican-minecraft-modrinth` は 1 箇所も変更しない。**

具体的には:
- `plugin.json` の `id`
- `config/pelican-minecraft-modrinth.php` のファイル名と `config('pelican-minecraft-modrinth.*')` 全て
- `trans('pelican-minecraft-modrinth::strings.*')` 全て（約140箇所）
- `view('pelican-minecraft-modrinth::*')`、`plugin_path('pelican-minecraft-modrinth', ...)`
- session キー `'pelican-minecraft-modrinth.catalog-sort.'`
- `.github/workflows/lint.yml` のパス、`update.json`

## 変更してはならないもの（Modrinth 固有で正当なもの）

| 対象 | 理由 |
|---|---|
| `Sources/ModrinthSource.php`、クラス名 `ModrinthSource` | Modrinth ソースの実装そのもの |
| `ProjectSourceKey::Modrinth`（enum ケースと値 `'modrinth'`） | **メタデータファイルに永続化されている値**。変更するとインストール済み情報が壊れる |
| キャッシュキー文字列 `modrinth_search:`、`modrinth_versions:`、`modrinth_latest:` 等 | S3 でプロファイル化する際にまとめて扱う。S1 では触らない |
| `https://modrinth.com/...` / `https://api.modrinth.com/v2` | 外部 URL |

## 変更対象

### 1-A. namespace とプラグインクラス

| 現在 | 変更後 |
|---|---|
| `Boy132\MinecraftModrinth` | `Kazaminosuke\ModManager` |
| `src/MinecraftModrinthPlugin.php` / `MinecraftModrinthPlugin` | `src/ModManagerPlugin.php` / `ModManagerPlugin` |

連動:
- `plugin.json` の `"namespace"` と `"class"`
- `tests/bootstrap.php` の `$loader->addPsr4('Boy132\\MinecraftModrinth\\', ...)`

### 1-B. クラス・メソッドのリネーム

| 現在 | 変更後 | 備考 |
|---|---|---|
| `Enums/ModrinthProjectType.php` / `ModrinthProjectType` | `Enums/ProjectType.php` / `ProjectType` | enum の**値** (`mod`/`plugin`/`datapack`) は不変 |
| `ModrinthProjectType::getModrinthLoader()` | `ProjectType::getLoaderSlug()` | |
| `Services/MinecraftModrinthService.php` / `MinecraftModrinthService` | `Services/InstalledProjectService.php` / `InstalledProjectService` | |
| `Facades/MinecraftModrinth.php` / `MinecraftModrinth` | `Facades/ModManager.php` / `ModManager` | facade accessor も追随 |
| `Filament/Server/Pages/MinecraftModrinthProjectPage.php` / `MinecraftModrinthProjectPage` | `Filament/Server/Pages/ModManagerPage.php` / `ModManagerPage` | **slug `'mod-manager'` は不変** |

### 1-C. 未使用の委譲メソッド削除

`MinecraftModrinthService`（→ `InstalledProjectService`）の以下は `ModrinthSource` への薄い委譲で、テスト以外から呼ばれていない。削除する:

- `getModrinthProjects()`
- `getModrinthVersions()`
- `getInstalledModsFromModrinth()`
- `lookupVersionsByHashes()`

**Codex に確認させること:** 削除前に全リポジトリ grep で本当に未使用か検証すること。使用箇所があれば削除せず報告する。
併せて `Facades/ModManager.php` の `@method` docblock からも該当行を削除する。

### 1-D. 翻訳キーと表示文字列

| ファイル | 現在 | 変更後 |
|---|---|---|
| `lang/{de,en,ja}/strings.php` | `'plugin_name' => 'Modrinth'` | `'plugin_name' => 'Minecraft Mod Manager'`（de/ja は各言語で適切に） |
| 同上 | `'badges' => ['not_on_modrinth' => 'Not tracked']` | `'badges' => ['untracked' => ...]`（**値は既に "Not tracked" で正しい**） |
| `src/Filament/Server/Pages/ModManagerPage.php` | `trans('...strings.badges.not_on_modrinth')` | `...strings.badges.untracked` |
| `plugin.json` | `"name": "Minecraft Modrinth"` | `"name": "Minecraft Mod Manager"` |
| `plugin.json` | `"description": "Easily download, update, and manage minecraft mods & plugins from modrinth"` | マルチソースを反映した文言（例: `"Download, update, and manage Minecraft mods, plugins, and datapacks from Modrinth, CurseForge, Hangar, and GitHub Releases"`） |

**注意:** `plugin.json` の `id` / `update_url` / `panels` は変更しない。`author` は据え置き。

### 1-E. 例外メッセージ・コメント

以下のような Modrinth 前提の内部文言を実態に合わせる（機能に影響しない）:
- `throw new Exception('Server does not support Modrinth mods or plugins')` → `'Server does not support managed mods or plugins'`（3箇所程度）
- `MinecraftModrinthService::resolveMetadataFolder()` の `"Server {$server->id} does not support Modrinth mods or plugins"`

## Codex 依頼文（Stage 1）

```
リポジトリ: kazaminosuke/pelican-minecraft-modrinth (main)

【タスク】内部命名のみをリネームする「リング0」を実施してください。
plugin id・ディレクトリ構造・インストールパス・ユーザーデータには一切触れません。

【絶対に変更しないもの】
1. 文字列リテラル "pelican-minecraft-modrinth" は 1 箇所も変更しないでください。
   （plugin.json の id、config ファイル名と config() 呼び出し、
     trans('pelican-minecraft-modrinth::...') 約140箇所、view()/plugin_path()、
     session キー、.github/workflows/lint.yml のパス、update.json すべて含む）
2. ProjectSourceKey の enum ケース Modrinth と値 'modrinth'
   （インストール済みメタデータに永続化されているため）
3. ModrinthSource クラスとファイル名（Modrinth ソースの実装そのもの）
4. MinecraftModrinthProjectPage の slug 'mod-manager'
5. .pelican-mod-manager.json の現行ファイル名
6. キャッシュキー文字列（modrinth_search:, modrinth_versions:, modrinth_latest: 等）
7. https://modrinth.com / https://api.modrinth.com/v2 の URL
8. ProjectType enum の値 'mod' / 'plugin' / 'datapack'

【変更内容】
A. namespace: Boy132\MinecraftModrinth → Kazaminosuke\ModManager
   連動: plugin.json の "namespace"、tests/bootstrap.php の addPsr4 プレフィックス

B. クラス／ファイルのリネーム（中の参照もすべて追随）
   - src/MinecraftModrinthPlugin.php / MinecraftModrinthPlugin
       → src/ModManagerPlugin.php / ModManagerPlugin
       （plugin.json の "class" も追随）
   - src/Enums/ModrinthProjectType.php / ModrinthProjectType
       → src/Enums/ProjectType.php / ProjectType
   - ProjectType::getModrinthLoader() → ProjectType::getLoaderSlug()
   - src/Services/MinecraftModrinthService.php / MinecraftModrinthService
       → src/Services/InstalledProjectService.php / InstalledProjectService
   - src/Facades/MinecraftModrinth.php / MinecraftModrinth
       → src/Facades/ModManager.php / ModManager（getFacadeAccessor も追随）
   - src/Filament/Server/Pages/MinecraftModrinthProjectPage.php / 同クラス
       → ModManagerPage.php / ModManagerPage

C. 未使用の委譲メソッド削除（InstalledProjectService から）
   getModrinthProjects / getModrinthVersions / getInstalledModsFromModrinth /
   lookupVersionsByHashes
   ※ 削除前に必ずリポジトリ全体を grep し、テスト以外から使用されていないことを
      確認してください。使用箇所があれば削除せず、その旨を報告してください。
   ※ Facades/ModManager.php の @method docblock からも該当行を削除。

D. 翻訳キーと表示文字列
   - lang/{de,en,ja}/strings.php の 'plugin_name' => 'Modrinth'
       → 'Minecraft Mod Manager'（de/ja は各言語で自然な表記に）
   - 翻訳キー badges.not_on_modrinth → badges.untracked（値は変更不要）
     と、それを参照している ModManagerPage 内の trans() 呼び出し
   - plugin.json の "name" → "Minecraft Mod Manager"
   - plugin.json の "description" → マルチソース対応を反映した文言に
     （Modrinth / CurseForge / Hangar / GitHub Releases に言及）
   ※ plugin.json の id / update_url / panels / author は変更しない

E. 内部例外メッセージ・コメントの Modrinth 前提表現を実態に合わせる
   例: 'Server does not support Modrinth mods or plugins'
       → 'Server does not support managed mods or plugins'

【検証】
1. vendor/bin/pint --test が通ること
2. PHPStan が通ること（.github/workflows/lint.yml と同じ手順:
   pelican-dev/panel を clone → composer install →
   plugins/pelican-minecraft-modrinth/ に配置 → phpstan analyse）
   ※ 配置先ディレクトリ名は pelican-minecraft-modrinth のままです
3. vendor/bin/phpunit --configuration plugins/pelican-minecraft-modrinth/phpunit.xml
   が全て green であること
4. 以下の grep で "pelican-minecraft-modrinth" のヒット数が変更前と完全に一致すること
   grep -ro "pelican-minecraft-modrinth" --include="*.php" --include="*.json" \
     --include="*.yml" --include="*.xml" . | wc -l
5. 残存する "Modrinth" / "modrinth" が、上記【絶対に変更しないもの】の
   許可リストのいずれかに該当することを一覧で報告すること

【決め打ちせず確認すべき点】
- 新 namespace は Kazaminosuke\ModManager を想定していますが、
  他候補があれば提案してください（PSR-4 として妥当であること）。
- ProjectType へのリネームで Pelican Panel 本体の既存クラスと衝突しないか
  確認してください（App\ 名前空間との衝突はないはずですが要確認）。
- lang/de と lang/ja の 'plugin_name' の適切な訳語を提案してください。
- 未使用と判断した委譲メソッドについて、削除の可否を報告してください。

【実施しないこと】
- commit / push は行わないでください。変更内容と検証結果を報告してください。
- ServiceProvider の追加やパフォーマンス改善は次段階です。今回は行いません。
```

## 完了条件（S1 → S2 へ進む条件）

- [ ] Pint / PHPStan / PHPUnit がすべて green
- [ ] `pelican-minecraft-modrinth` のヒット数が変更前と一致（＝ id 系に一切触れていない）
- [ ] 残存する `Modrinth` が許可リストに完全に収まっていることをレビュー済み
- [ ] 実パネルに配置して mod-manager ページが開き、カタログ／Installed 両タブが従来通り動作すること（手動確認）
- [ ] 旧 URL redirectを登録せず、現行slugだけが登録されること（手動確認）

---

# Stage 2 — 無駄の除去（旧 P0）

## 目的
挙動を変えずに、初回ロードから **Wings 1往復**と各種オーバーヘッドを除去する。

## 変更内容

### 2-A. 計測インストルメンテーションのフラグ化

`chore: add temporary timing diagnostics for mod-manager initial load` 以降に積み上がった一時計測コードが全て残存している。

対象:
- `ModManagerPage`: `boot()`、`dehydrate()`、`getModManagerTimingElapsedMs()`、
  `$modManagerTimingStartedAt` / `$modManagerTimingRequestId` /
  `$modManagerTimingVersionLookups` / `$modManagerTimingVersionLookupDurationMs`、
  `Log::info('Mod manager timing', ...)` 全7箇所
- `InstalledProjectService`: `logModManagerTiming()` と呼び出し6箇所、`$hashScanWingsGetCount`
- `ModrinthSource`: `logger()->info('Mod manager timing', ...)` 2箇所、
  `getModManagerTimingElapsedMs()`、および **`$responseBytes = strlen($response->body())`**
  （レスポンス全体を文字列化しているため、`config('app.debug')` が false でも実行される）

**方針:** 削除ではなく、`config('pelican-minecraft-modrinth.debug_timing', false)` によるオプトイン化。
- フラグ false のときは **配列構築すら行わない**（早期 return）。
- 出力先は `Log::info` の乱発をやめ、リクエスト終端で `Server-Timing` ヘッダ 1 本にまとめる案を提示させる（実装可否は Codex に判断させる）。

### 2-B. `resolveInstalledFilesCount()` の Wings 呼び出し廃止

`ModManagerPage::loadTable()` が**アクティブタブに関係なく** Wings `getDirectory()` を発行している。
さらに Installed タブでは直後に `records()` が `$this->installedFilesCount = $totalCount` で上書きするため、**両方の経路で完全に冗長**。

**変更:**
- `resolveInstalledFilesCount()` と、`loadTable()` 内のその呼び出しを削除
- `installedFilesCount` は `InstalledScanResult::fromCache(...)->diskFileCount` から取得
- キャッシュが無い場合は `null`（`…` 表示）のまま

**要判断（Codex に確認させる）:** カタログタブでスキャン結果が未生成のとき、バッジは `…` のままになる。
1. 案1: `…` のまま。ユーザーが Installed タブを開けば埋まる（**推奨**）
2. 案2: カタログタブからもスキャンジョブを dispatch する（キュー未設定時に警告が全タブに出る副作用あり）

### 2-C. ServiceProvider の追加

現在プラグインに ServiceProvider が 1 つも無く、DI バインディングがゼロ。結果として:
- `getSourceLabel()` は `source` カラムの `formatStateUsing` から**行ごとに**呼ばれ、都度 `ProjectSourceRegistry` + Source 4個を新規構築（1描画あたり最大20回）
- `app(VersionLookupCoordinator::class)` / `app(ProjectSourceRegistry::class)` も毎回 new

**変更:** `src/Providers/ModManagerServiceProvider.php` を新規作成し、以下を `singleton` バインド:
- `ProjectSourceRegistry`
- `ModrinthSource` / `CurseForgeSource` / `HangarSource` / `GitHubReleasesSource`
- `VersionLookupCoordinator`
- `InstalledProjectService`
- `InstalledOperationManager`

**重要:** Pelican は `src/Providers/*.php` を走査して `{namespace}\Providers\{Name}` を自動登録する
（`app/Models/Plugin.php:382-391`）。**`plugin.json` への追記は不要。**

### 2-D. `MinecraftVersionResolver::resolve()` のメモ化

毎回 `server_variables` に DB クエリを投げ、`content()` / `search()` / `getVersions()` /
`lookupLatestVersions()` から呼ばれるため 1リクエストで複数回発火する。
static 配列で `$server->id` をキーにメモ化する。

**注意:** キューワーカーは長時間プロセスなので、static キャッシュがジョブ間で残る。
ジョブ境界でクリアする仕組み（`Queue::looping()` フック等）を併せて設計させること。

### 2-E. Livewire ペイロードの削減

`public array $unknownFiles` は未一致ファイル名を全件保持する public プロパティで、
**毎リクエストの Livewire スナップショットに往復でシリアライズ**される。

**変更:** protected 化して描画時に再構築する、または件数のみ public で保持する。
`performInstallOrUpdate()` / uninstall アクションが `$this->unknownFiles` を書き換えている箇所の整合に注意。

## Codex 依頼文（Stage 2）

```
リポジトリ: kazaminosuke/pelican-minecraft-modrinth (main) ※ Stage 1 完了後

【タスク】挙動を変えずに、mod-manager 画面の初回ロードから無駄な処理を除去してください。

A. 計測インストルメンテーションのフラグ化
   config/pelican-minecraft-modrinth.php に 'debug_timing' => env('MOD_MANAGER_DEBUG_TIMING', false)
   を追加し、以下をすべてこのフラグでガードしてください。
   フラグが false のときは、ログ用配列の構築すら行わないこと（早期 return）。
   - ModManagerPage: boot() / dehydrate() / getModManagerTimingElapsedMs() /
     modManagerTiming* プロパティ / Log::info('Mod manager timing', ...) 全7箇所
   - InstalledProjectService: logModManagerTiming() と呼び出し6箇所
   - ModrinthSource: logger()->info('Mod manager timing', ...) 2箇所 と
     getModManagerTimingElapsedMs()
   特に ModrinthSource::search() の $responseBytes = strlen($response->body()) は
   config('app.debug') が false でも常に実行されているので、必ずフラグ内に移してください。

   その上で、Log::info を8回撒く代わりに Server-Timing ヘッダ1本に集約する案が
   実装可能か検討し、可能なら実装、困難なら理由を報告してください。

B. resolveInstalledFilesCount() の Wings 呼び出し廃止
   ModManagerPage::loadTable() は現在アクティブタブに関係なく Wings の getDirectory() を
   発行していますが、Installed タブでは直後に records() が installedFilesCount を
   上書きするため完全に冗長です。
   - resolveInstalledFilesCount() と loadTable() 内の呼び出しを削除
   - installedFilesCount は InstalledScanResult::fromCache(...)->diskFileCount から取得
   - キャッシュが無ければ null のまま（画面には … が出る）

C. ServiceProvider の追加
   src/Providers/ModManagerServiceProvider.php を新規作成し、以下を singleton バインド:
   ProjectSourceRegistry / ModrinthSource / CurseForgeSource / HangarSource /
   GitHubReleasesSource / VersionLookupCoordinator / InstalledProjectService /
   InstalledOperationManager
   ※ Pelican は src/Providers/*.php を自動走査して登録するため plugin.json への追記は不要です
     （app/Models/Plugin.php の getProviders() を参照）。
   ※ 特に ModManagerPage::getSourceLabel() は source カラムの formatStateUsing から
     行ごとに呼ばれており、現状は1描画で最大20回 Registry を再構築しています。

D. MinecraftVersionResolver::resolve() のリクエスト内メモ化
   server->id をキーにした static 配列でメモ化してください。
   キューワーカーは長時間プロセスのため、ジョブ間で static が残らないよう
   クリア手段（Queue::looping() フック等）も併せて設計・実装してください。

E. Livewire ペイロードの削減
   public array $unknownFiles は未一致ファイル名を全件保持し、毎リクエスト往復で
   シリアライズされています。protected 化して描画時に再構築するか、件数のみ
   public で保持する形に変更してください。
   performInstallOrUpdate() と uninstall アクションが $this->unknownFiles を
   書き換えている箇所の整合に注意してください。

【検証】
1. Pint / PHPStan / PHPUnit がすべて green
2. 実パネルに配置し、以下を手動確認:
   - カタログタブ初回表示で Wings への getDirectory リクエストが発生しないこと
     （パネルのログまたは Wings 側のアクセスログで確認）
   - Installed タブの件数バッジが従来通り正しい値を表示すること
   - MOD_MANAGER_DEBUG_TIMING=true で従来通りの計測ログが出ること
   - 同 false（既定）でログが一切出ないこと
3. B の変更前後で、カタログタブ初回表示の所要時間を計測し比較して報告すること

【決め打ちせず確認すべき点】
- B の副作用として、一度も Installed タブを開いていないサーバではカタログタブの
  件数バッジが「…」のままになります。以下どちらが妥当か判断し、提案してください。
    案1: … のまま（ユーザーが Installed タブを開けば埋まる）※こちらを推奨
    案2: カタログタブからもスキャンジョブを dispatch する
         （キュー未設定時に警告が全タブに出る副作用あり）
  判断が割れる場合は案1で実装し、案2の実装コストを添えて報告してください。
- D のジョブ間 static クリアについて、Pelican / Laravel の慣用手段を調べて
  最も素直な方法を選んでください。
- A の Server-Timing 集約は「できれば」です。困難なら Log::info のままフラグ化だけで
  構いません。

【実施しないこと】
- commit / push は行わないでください。
- キャッシュ戦略の変更（SWR 化）は Stage 3 です。今回は行いません。
```

## 完了条件（S2 → S3）

- [ ] Pint / PHPStan / PHPUnit green
- [ ] カタログタブ初回表示で Wings リクエストが 0 件（Datapack ページを除く。Datapack は `server.properties` 読み取りが残る）
- [ ] `MOD_MANAGER_DEBUG_TIMING=false` でタイミングログが一切出ない
- [ ] 初回表示時間の改善が計測値で示されている（ベースライン記録）
- [ ] Installed タブの件数バッジが従来と同じ値

---

# Stage 3 — SWR キャッシュ層の一元化（旧 P1）

## 目的
上流 API 呼び出しを**描画パスからブロッキング要素として排除**する。
併せて、現在の「期限切れ = 消滅 → 失敗時は空表示」という欠陥を解消する。

## 現状の欠陥

`ModrinthSource::search()` の失敗パス:
```
30分経過 → キャッシュ消滅 → 次の1人が実APIを叩く（timeout 2s）
        → Modrinth が遅い/落ちている → catch → hits: [] を返す
        → ユーザーには「空のテーブル」が表示される（古いデータがあるのに捨てている）
```
`cache()->remember(..., 30分)` が 24 箇所に散在（Modrinth 6 / CurseForge 6 / Hangar 6 / GitHub 6）。

## 設計

### 3-A. `SourceCache` ヘルパー

Modrinth theseus の `CacheBehaviour`（`packages/app-lib/src/state/cache.rs:808-819`）を移植する。

```php
SourceCache::swr(SourceFetchSpec $spec, CacheProfile $profile): mixed
```

エントリ構造:
```php
['v' => SCHEMA_VERSION, 'data' => mixed, 'fresh_until' => int(unix ts)]
```
Laravel の TTL は `staleTtl` に設定する（＝ fresh 期限を過ぎても stale コピーが残る）。

動作:
| 状態 | 挙動 |
|---|---|
| fresh ヒット | 即返す |
| stale ヒット | **即 stale を返す** + `RevalidateSourceCache` ジョブを dispatch |
| ミス | 最大 `inlineBudget`（既定 1.5秒）だけ同期取得。超過/失敗なら空を返し非同期取得へ |
| 取得失敗 & stale あり | **stale を返す**（theseus の `StaleWhileRevalidateSkipOffline` 相当。これが既定） |
| 取得失敗 & stale なし | 空を返す + **短命の失敗マーカー**（30秒程度）を書いて連打を防ぐ |

**重要な設計制約:** ジョブに Closure は渡せない。よって `SourceFetchSpec`（シリアライズ可能な値オブジェクト）を導入する:

```php
final class SourceFetchSpec {
    public function __construct(
        public readonly string $sourceKey,   // ProjectSourceKey の値
        public readonly string $operation,   // 'search' | 'versions' | 'projects' | 'latest' 等
        public readonly array  $arguments,   // スカラーのみ
    ) {}
    public function cacheKey(): string;
}
```
`RevalidateSourceCache` ジョブは Registry から Source を再解決して再実行する。
**`ShouldBeUnique` をキャッシュキーで実装**すれば、同一 stale キーに殺到しても再検証ジョブは 1 本に集約される。

### 3-B. キャッシュプロファイル（theseus の実測値ベース）

theseus `CacheValueType::expiry()`（`cache.rs:97-109`）が採用している値を根拠とする。

| データ種別 | theseus | 本プラグイン（fresh / stale） | 根拠 |
|---|---|---|---|
| ハッシュ → version 一致 | 30日 | **30日 / 無期限** | 内容アドレス指定＝不変 |
| version のファイル情報 | 30日 | **30日 / 無期限** | 同上 |
| 検索結果 | 10分 | **10分 / 24時間** | |
| プロジェクトメタデータ（title/icon/author） | 30分 | **24時間 / 7日** | 変化が稀 |
| installed の最新版ルックアップ | 30分 | **30分 / 24時間** | |
| ユーザー名 / チーム名 | 30分 | **7日 / 30日** | ほぼ不変 |

**現状の最大の無駄:** ハッシュ照合結果が 10 分の `installed_scan` キャッシュに閉じ込められており、
**不変なデータを 10 分で捨てている**（Hangar だけが `HASH_MATCH_CACHE_DAYS` で日単位を使っている）。

### 3-C. 同期キュー時のフォールバック

`InstalledOperationManager::supportsAsyncDispatch()` が false（`sync` / `null` ドライバ）の場合、
再検証ジョブを dispatch しても同期実行されて意味がない。

**方針:** その場合は「fresh 期限で同期再取得」する従来動作に落とす。
ただし**失敗時に stale を返す挙動は維持する**（ここが現状より確実に良くなる部分）。

### 3-D. 移行方法

24 箇所の `cache()->remember` を一気に置換せず、**ソース単位で段階的に**行う。
順序: `ModrinthSource` → `CurseForgeSource` → `HangarSource` → `GitHubReleasesSource`。
各ソース完了ごとに検証する。

キャッシュキーはスキーマ変更のため**接頭辞のバージョンを上げる**（例 `modrinth_search:v2:` → `:v3:`）。
旧キーは TTL で自然消滅するので移行処理は不要。

## Codex 依頼文（Stage 3）

```
リポジトリ: kazaminosuke/pelican-minecraft-modrinth (main) ※ Stage 2 完了後

【背景】
現在キャッシュは各呼び出し箇所の cache()->remember(..., 30分) が24箇所に散在しており、
「期限切れ = 消滅」のため、期限切れ後の最初の1人が実APIのレイテンシを全額負担します。
さらに ModrinthSource::search() は失敗時に hits: [] を返すため、古いデータがあるのに
ユーザーには空のテーブルが表示されます。

参考実装として Modrinth の theseus (modrinth/code) の
packages/app-lib/src/state/cache.rs を読んでください。CacheBehaviour enum (808-819行)、
expiry() (97-109行)、get_many() の stale-while-revalidate 実装 (1085-1110行) が
そのまま設計の下敷きになります。

【タスク】stale-while-revalidate キャッシュ層を導入し、上流API呼び出しを
描画パスからブロッキング要素として排除してください。

A. SourceCache ヘルパーを新規作成
   SourceCache::swr(SourceFetchSpec $spec, CacheProfile $profile): mixed

   エントリ構造: ['v' => SCHEMA_VERSION, 'data' => mixed, 'fresh_until' => unix_ts]
   Laravel の TTL は staleTtl に設定（fresh 期限を過ぎても stale が残るように）

   動作:
   - fresh ヒット → 即返す
   - stale ヒット → 即 stale を返し、RevalidateSourceCache ジョブを dispatch
   - ミス → 最大 inlineBudget（既定1.5秒）だけ同期取得。超過/失敗なら空を返し非同期取得へ
   - 取得失敗 かつ stale あり → stale を返す（これが既定の挙動）
   - 取得失敗 かつ stale なし → 空を返し、30秒程度の短命な失敗マーカーを書いて連打を防ぐ
     ※ エラーレスポンスの本文そのものはキャッシュしないこと
       （GDLauncher の cache_middleware.rs のコメント参照:
         429 の本文をキャッシュすると回復期間を超えてエラーが固定され Retry-After も失われる）

B. SourceFetchSpec 値オブジェクトを新規作成
   ジョブに Closure は渡せないため、シリアライズ可能な取得仕様を導入します。
     sourceKey (ProjectSourceKey の値) / operation ('search'|'versions'|'projects'|'latest' 等)
     / arguments (スカラーのみ) / cacheKey() メソッド
   RevalidateSourceCache ジョブは ProjectSourceRegistry から Source を再解決して再実行します。
   ジョブには ShouldBeUnique をキャッシュキーで実装し、同一 stale キーへの殺到時に
   再検証ジョブが1本に集約されるようにしてください。

C. キャッシュプロファイルを定義
   種別ごとの fresh/stale ペアを 1 箇所（enum または定数クラス）に集約:
     ハッシュ→version 一致        : fresh 30日  / stale 無期限
     version のファイル情報       : fresh 30日  / stale 無期限
     検索結果                     : fresh 10分  / stale 24時間
     プロジェクトメタデータ       : fresh 24時間 / stale 7日
     installed の最新版ルックアップ: fresh 30分  / stale 24時間
     ユーザー名 / チーム名        : fresh 7日   / stale 30日

D. 同期キュー時のフォールバック
   InstalledOperationManager::supportsAsyncDispatch() が false のときは
   再検証ジョブが同期実行されて意味がないため、「fresh 期限で同期再取得」する
   従来動作に落としてください。ただし「失敗時に stale を返す」挙動は維持してください。

E. 既存 cache()->remember の置換
   一気にやらず、ソース単位で段階的に進めてください。
   順序: ModrinthSource → CurseForgeSource → HangarSource → GitHubReleasesSource
   各ソース完了ごとに検証してください。
   キャッシュキーはスキーマ変更のため接頭辞のバージョンを上げてください
   （例: modrinth_search:v2: → modrinth_search:v3:）。旧キーは TTL で消えるので
   移行処理は不要です。

【検証】
1. Pint / PHPStan / PHPUnit green
2. 新規ユニットテストを追加:
   - fresh ヒットで fetch が呼ばれないこと
   - stale ヒットで即座に stale が返り、再検証ジョブが dispatch されること
   - 同一キーへの複数回 swr で再検証ジョブが1本に集約されること（ShouldBeUnique）
   - fetch が例外を投げても stale があれば stale が返ること
   - fetch が例外を投げて stale が無ければ空 + 失敗マーカーが書かれること
   - 同期キュー時のフォールバック動作
3. 統合検証（実パネル）:
   - 上流APIを到達不能にした状態（hosts で api.modrinth.com をブロック等）で
     カタログタブを開き、「空のテーブル」ではなく直前のデータが表示されること
   - かつ 1 秒以内に描画されること（2秒ハングしないこと）
4. Stage 2 で記録したベースラインと初回表示時間を比較して報告

【決め打ちせず確認すべき点】
- inlineBudget の既定値 1.5秒 が妥当か、実測して提案してください。
  現在 ModrinthSource は timeout(2)/connectTimeout(1)、他ソースは timeout(10)/connectTimeout(5)
  とバラバラです。SWR 導入後は「ミス時のインライン取得」だけが同期パスなので、
  ここを統一する提案も併せてください。
- 失敗マーカーの TTL 30秒 が妥当か検討してください。
- CacheProfile の表現方法（enum / 定数クラス / 設定ファイル）を提案してください。
  運用者が調整したくなる値かどうかで判断してください。
- 既存の Hangar の HASH_MATCH_CACHE_DAYS と CacheVersion::hangarHash() の
  generation 方式を、新しい SourceCache とどう統合するか提案してください。
- InstalledScanResult の 10分キャッシュ（installed_scan:v2）に閉じ込められている
  ハッシュ照合結果を、C のプロファイル（30日）に載せ替えられるか検討してください。
  ここが最大の効果が見込める箇所ですが、スキャンのライフサイクルと絡むため
  Stage 4 に回すべきなら、その旨を報告してください。

【実施しないこと】
- commit / push は行わないでください。
- Installed タブの構造変更（Stage 4）、ウォームジョブ（Stage 5）は今回行いません。
```

## 完了条件（S3 → S4）

- [ ] Pint / PHPStan / PHPUnit green（新規テスト含む）
- [ ] 上流 API 到達不能状態でカタログタブが **stale データを 1 秒以内に**表示する
- [ ] 上流 API 到達不能状態で「空のテーブル」が出ない
- [ ] 4 ソースすべて `SourceCache` 経由に移行済み（`cache()->remember` の直接使用が残っていない）
- [ ] `inlineBudget` とタイムアウト値の統一方針が決定・実装済み

---

# Stage 4 — Installed タブの progressive enrichment 化（旧 P4）

## 目的
Installed タブの描画パスに乗っている **Modrinth bulk POST 2 本**を除去する。

## 参考にする設計

**PrismLauncher の分離モデル:**
- `.index/` に packwiz 形式のメタデータをインストール時に書き出す
- `ResourceFolderLoadTask` は**メタデータを先に読み、メタデータの無い jar だけを解析対象にする**
- `EnsureMetadataTask` が**別立ての明示的操作**としてハッシュ計算 → 照合を行う

**GDLauncher の progressive enrichment:**
- 表示中のインスタンスを `priority` に設定して先にキャッシュ
- 完了時に invalidation key をフロントへ push して UI を更新

## 設計

### 4-A. メタデータ文書を「正」とする

ゲームサーバ上の `.pelican-mod-manager.json` は既に
`project_title` / `project_slug` / `author` / `version_number` / `hashes` / `file_signature`
を保持している。**`icon_url` / `downloads` / `date_modified` 以外の全列を描画できる。**

**変更:** `records()` はメタデータ文書だけから行を組み立てる。
`ProjectSourceRegistry::hydrateInstalled()` と `VersionLookupCoordinator` 呼び出しを
描画パスから外す。

### 4-B. エンリッチを背景処理に降格

- 表示用データ（icon/downloads/date_modified）: `SourceCache` のキャッシュ済み分のみ即時反映。
  未取得分はプレースホルダで描画し、`RevalidateSourceCache` に載せる
- 最新版ルックアップ（更新バッジ）: 同様に背景化
- 着地したら Livewire を refresh して UI を更新（既存の `pollInstalledOperation` の仕組みを流用できるか検討）

**影響:** `update` / `installed` アクションの `visible()` が同期 Coordinator 呼び出しに依存している
（`ModManagerPage` の該当2箇所）。これを「キャッシュ済みなら判定、未取得なら `installed` を表示」に変更。

### 4-C. `fetchProjectsMap()` のチャンク単位無効化問題の解消

`ProjectSourceRegistry::fetchProjectsMap()` は project id を **100件チャンク単位で 1 キャッシュキー**に
まとめている。1 件インストールすると `CacheVersion::hydration()` の generation が上がり、
**全チャンクが無効化**される（500 mod のサーバなら 5 チャンク = 500 件を再取得）。

theseus は逆に**エンティティ 1 件 = キャッシュ 1 行**とし、`get_many` が SQL 1 発でヒットを集め、
**ミス分だけを 800 件チャンクでバッチ取得**する（`cache.rs:1116-1163`）。

**変更:** project 単位のキャッシュエントリに変更。インストール 1 件の無効化がエントリ 1 件で済む。
バッチ取得は「ミス分をまとめて 1 リクエスト」の形を維持する。

### 4-D. メタデータ読み取りキャッシュの延長

現在 5 分（`installed_metadata_display:v2:`）。
`CacheVersion::hydration()` の generation が書き込みごとに無効化するため、
TTL はファイルマネージャ経由の外部編集への保険にすぎない。**1 時間に延長**し、手動更新手段を用意する。

## Codex 依頼文（Stage 4）

```
リポジトリ: kazaminosuke/pelican-minecraft-modrinth (main) ※ Stage 3 完了後

【背景】
Installed タブの描画パスには現在、Modrinth の bulk POST が2本乗っています:
  1. VersionLookupCoordinator 経由の POST /version_files/update（最新版ルックアップ）
  2. ProjectSourceRegistry::hydrateInstalled() 経由の getProjectsByIds（表示用データ）
これらを描画パスから外し、progressive enrichment に変更してください。

参考実装:
- PrismLauncher の launcher/minecraft/mod/tasks/ResourceFolderLoadTask.cpp
  → ローカルのメタデータを先に読み、メタデータの無い jar だけを解析対象にする分離
- PrismLauncher の launcher/modplatform/EnsureMetadataTask.cpp
  → ハッシュ照合を別立ての明示的操作として分離
- GDLauncher-Carbon の crates/carbon_app/src/managers/metadata/cache/mod.rs
  → 表示中のエンティティを優先キャッシュし、完了時に UI へ invalidation を push

【タスク】
A. メタデータ文書を描画の「正」とする
   ゲームサーバ上の .pelican-mod-manager.json は既に project_title / project_slug /
   author / version_number / hashes / file_signature を持っており、
   icon_url / downloads / date_modified 以外の全列を描画できます。
   ModManagerPage::table() の records() を、Installed タブではメタデータ文書だけから
   行を組み立てるよう変更してください。
   hydrateInstalled() と VersionLookupCoordinator 呼び出しを描画パスから外します。

B. エンリッチを背景処理に降格
   - 表示用データ（icon_url / downloads / date_modified）は SourceCache の
     キャッシュ済み分のみ即時反映。未取得分はプレースホルダで描画し、
     Stage 3 の RevalidateSourceCache に載せてください。
   - 最新版ルックアップ（更新バッジ）も同様に背景化してください。
   - 着地後の UI 更新方法を設計してください。既存の pollInstalledOperation +
     wire:poll の仕組みを流用できるか検討し、流用できるならそうしてください。
   - update / installed アクションの visible() が同期 Coordinator 呼び出しに
     依存している2箇所を「キャッシュ済みなら判定、未取得なら installed を表示」
     に変更してください。

C. fetchProjectsMap() のチャンク単位無効化を解消
   現在 ProjectSourceRegistry::fetchProjectsMap() は project id を100件チャンク単位で
   1キャッシュキーにまとめているため、1件インストールすると generation が上がって
   全チャンクが無効化されます（500 mod なら500件再取得）。
   theseus の cache.rs:1116-1163 のように「エンティティ1件 = キャッシュ1行」に変更し、
   ヒット分を集めてミス分だけをまとめて1リクエストで取得する形にしてください。

D. メタデータ読み取りキャッシュの延長
   installed_metadata_display:v2: の TTL を 5分 → 1時間 に延長してください。
   CacheVersion::hydration() の generation が書き込みごとに無効化するため安全ですが、
   ファイルマネージャ経由の外部編集に備えて手動更新手段を用意してください
   （既存の scan_mods ヘッダーアクションで足りるか検討）。

【検証】
1. Pint / PHPStan / PHPUnit green
2. 新規／既存テストの更新:
   - メタデータ文書だけから行が組み立てられること（上流API呼び出しなし）
   - project 単位キャッシュで、1件の generation 変更が他エントリに影響しないこと
3. 統合検証（実パネル、mod 50件以上のサーバで）:
   - Installed タブ初回表示で上流APIへの同期リクエストが 0 件であること
   - 初回表示が 1 秒以内であること
   - icon / downloads / 更新バッジが後追いで正しく埋まること
   - 上流API到達不能状態でも行が正しく表示されること（列が一部欠けるのは可）
4. Stage 3 のベースラインとの比較を報告

【決め打ちせず確認すべき点】
- B の「着地後の UI 更新」方法を提案してください。
  候補: 既存の wire:poll 流用 / Livewire の dispatch イベント / 何もせず次回描画に任せる。
  ポーリングを増やすと常時トラフィックが増えるため、トレードオフを添えてください。
- 未取得の icon_url のプレースホルダをどう出すか提案してください。
  現在 SWR_EMPTY_ICON_DATA_URI という透明1pxのフォールバックがあり、
  SWR Blade がこの正確な値のみを許可しています。整合に注意してください。
- 更新バッジが未取得のとき「installed（更新なし）」を表示すると、実際には更新が
  あるのに無いように見える瞬間が生じます。これが許容できるか、あるいは
  「確認中」の第3の状態を出すべきか提案してください。
- D の TTL 1時間が妥当か、また手動更新手段として既存の scan_mods アクションで
  十分か判断してください。
- Stage 3 で保留した場合、InstalledScanResult のハッシュ照合結果を
  30日プロファイルに載せ替える作業をここで実施してください。

【実施しないこと】
- commit / push は行わないでください。
- ウォームジョブ（Stage 5）、条件付き defer（Stage 6）は今回行いません。
```

## 完了条件（S4 → S5）

- [ ] Pint / PHPStan / PHPUnit green
- [ ] Installed タブ初回表示で上流 API への**同期**リクエストが 0 件
- [ ] mod 50 件以上のサーバで Installed タブ初回表示が 1 秒以内
- [ ] icon / downloads / 更新バッジが後追いで正しく埋まる
- [ ] 1 件のインストールで全プロジェクトキャッシュが無効化されないこと（ログまたはテストで確認）

---

# Stage 5 — プリロード（旧 P2）

## 目的
コールドな初回訪問（キャッシュミス）を解消する。

## 前提（検証A の結果を反映）

**hover プリフェッチは効いていない**（Pelican が `spa()` の第2引数を省略しているため）。
プラグイン側から `spa(true, true)` を有効化するのはパネル全ページに影響するため**実施しない**。
→ **サーバサイドのウォームジョブが主軸**。

## 設計

### 5-A. カタログ 1 ページ目のウォームジョブ

カタログ 1 ページ目のキャッシュキーは
`(source, project_type, loader, mc_version, sort, page=1, search なし, filter なし)` の純関数で、
**server_id を含まない**。したがって**同じ loader + MC バージョンの全サーバで共有**される。

**トリガー:**
1. mod-manager ページの RT1 終端で、利用可能な全ソースの page 1（+ アクティブソースの page 2）を
   warm するジョブを dispatch（`ShouldBeUnique` で重複排除）
2. 加えて、実際に使われている `(loader, mc_version, project_type)` の組み合わせを
   egg/server_variables から 1 クエリで抽出し、スケジュール実行

**注意:** 1 は「今回の訪問」には間に合わない（次回以降と、他タブ／他ページに効く）。
2 が実質的にコールド訪問を潰す本命。

### 5-B. レート制限スロットル

GDLauncher の `ModrinthCacheThrottle`（`cache/mod.rs:56-110`）を移植する。

> Modrinth の公開レート制限は 300 req/min（sliding window、`X-RateLimit-Reset` でリセット）。
> GDLauncher は背景キャッシュループを **210 req/min（70%）に自己制限**し、
> **ユーザー起因のリクエストはスロットルを迂回**させて対話レイテンシを守っている。

**実装:** ウォームジョブ経由のリクエストのみスロットル対象。
ユーザー操作起因（検索・インストール・タブ切替）は無制限。
CurseForge / Hangar / GitHub にもそれぞれのレート制限を調べて適用する。

### 5-C. `wire:navigate` の挙動確認（5分）

Stage 5 冒頭で確認する。素の `wire:navigate` が mousedown 時にプリフェッチするかは
Livewire の実装依存。**飛ばなくても設計は成立するためブロッカーではない。**
飛ぶなら RT1 が実質無料になるので、その前提で S6 の判断材料にする。

## Codex 依頼文（Stage 5）

```
リポジトリ: kazaminosuke/pelican-minecraft-modrinth (main) ※ Stage 3 完了後

【前提（調査済み・再確認不要）】
Pelican Panel は app/Providers/Filament/PanelProvider.php:32 で
  ->spa(fn () => !request()->routeIs('filament.server.pages.console'))
を呼んでおり、第2引数 $hasPrefetching が省略されているため false です。
Filament の packages/support/src/helpers.php:148-155 の実装により、リンクには
wire:navigate.hover ではなく素の wire:navigate が出力されます。
つまり hover プリフェッチは効いていません。
プラグイン側から spa(true, true) を有効化するのはパネル全ページに影響するため
実施しないでください。

【タスク】
A. カタログ1ページ目のウォームジョブ
   カタログ1ページ目のキャッシュキーは
   (source, project_type, loader, mc_version, sort, page=1, 検索なし, フィルタなし)
   の純関数で server_id を含まないため、同じ loader + MC バージョンの全サーバで
   共有されます。これを利用して以下を実装してください。

   1. mod-manager ページのレンダリング終端で、利用可能な全ソースの page 1
      （+ アクティブソースの page 2）を warm するジョブを dispatch。
      ShouldBeUnique で重複排除すること。
   2. 実際に使われている (loader, mc_version, project_type) の組み合わせを
      egg / server_variables から1クエリで抽出し、スケジュール実行で warm する
      仕組み。実行間隔は検索結果の fresh TTL（10分）を踏まえて提案してください。

   ※ 1 は今回の訪問には間に合いません（次回以降と他タブに効く）。
      コールド訪問を実際に潰すのは 2 です。両方実装してください。

B. レート制限スロットル
   GDLauncher-Carbon の crates/carbon_app/src/managers/metadata/cache/mod.rs の
   ModrinthCacheThrottle（56-110行）を参考に、ウォームジョブ経由のリクエストのみを
   スロットルする仕組みを実装してください。
   - Modrinth の公開制限は 300 req/min。GDLauncher は背景処理を 210 req/min（70%）に
     自己制限し、ユーザー起因リクエストはスロットルを迂回させています。同じ方針で。
   - ユーザー操作起因（検索・インストール・タブ切替）は無制限のままにしてください。
   - CurseForge / Hangar / GitHub Releases それぞれのレート制限を調べ、
     同様の自己制限値を提案・実装してください。

C. wire:navigate の挙動確認（先に5分で実施）
   実パネルの DevTools Network で、サイドバーの mod-manager リンクを mousedown した際に
   プリフェッチリクエストが飛ぶかを確認し、結果を報告してください。
   飛ばなくても A/B の設計は成立するのでブロッカーではありません。
   結果は Stage 6 の判断材料として使います。

【検証】
1. Pint / PHPStan / PHPUnit green
2. 新規テスト:
   - ウォームジョブのキャッシュキーが server_id を含まないこと
   - ShouldBeUnique で同一キーのジョブが重複しないこと
   - スロットルが上限を超えないこと（時間を偽装したユニットテスト）
   - ユーザー起因リクエストがスロットルを迂回すること
3. 統合検証:
   - キャッシュを全消去 → スケジュールウォームを1回実行 → mod-manager を開く、で
     カタログタブが 1 秒以内に表示されること
   - ウォーム実行中に別ユーザーが検索しても、検索が遅延しないこと

【決め打ちせず確認すべき点】
- A-2 のスケジュール実行間隔を提案してください。検索結果の fresh TTL が 10分 なので
  それより短くしても無意味ですが、長すぎるとコールドが残ります。
- A-2 の対象組み合わせが多い環境（多数の egg / MC バージョン）で
  ウォームが過剰にならないよう、上限や優先順位付けを提案してください。
  GDLauncher は「表示中のインスタンスを priority に設定」という優先度制御を
  していますが、本プラグインで相当するものが妥当か判断してください。
- スケジュール登録の方法を提案してください。Pelican プラグインが Laravel の
  スケジューラに登録する慣用手段を調べてください
  （ServiceProvider の boot() で Schedule::job() が使えるか等）。
  使えない場合は代替案（キューの遅延ディスパッチによる自己再帰等）を提案してください。
- スロットルの状態保持先（キャッシュ / DB / プロセス内）を提案してください。
  複数ワーカーがある環境で全体の上限を守れる方法が必要です。
- 運用者がウォームを無効化できる設定を用意すべきか判断してください。

【実施しないこと】
- commit / push は行わないでください。
- パネル本体の spa() 設定は変更しないでください。
- 条件付き defer（Stage 6）は今回行いません。
```

## 完了条件（S5 → S6）

- [ ] Pint / PHPStan / PHPUnit green
- [ ] キャッシュ全消去 → ウォーム 1 回 → mod-manager を開く、でカタログタブが 1 秒以内
- [ ] ウォーム実行中にユーザー操作のレイテンシが悪化しない（スロットル迂回の確認）
- [ ] `wire:navigate` の mousedown プリフェッチ有無が報告済み
- [ ] スケジュール登録方法とウォーム対象上限が決定・実装済み

---

# Stage 6 — 条件付き defer（旧 P3）

## 目的
`deferLoading()` が固定コストとして払っている **Livewire 1 往復**を、キャッシュが温かい場合に省く。

## 前提（検証B の結果）

Filament v5.7.5 で確認済み:
```php
// Filament\Tables\Table\Concerns\CanDeferLoading
public function deferLoading(bool | Closure $condition = true): static   // ← Closure 可
public function isLoadingDeferred(): bool { return (bool) $this->evaluate($this->isLoadingDeferred); }

// Filament\Tables\Concerns\CanDeferLoading
public bool $isTableLoaded = false;
public function isTableLoaded(): bool {
    if (! $this->getTable()->isLoadingDeferred()) { return true; }  // ← defer でなければ常に true
    return $this->isTableLoaded;
}
```

**重要な帰結:** `isLoadingDeferred()` が false のとき `isTableLoaded()` は常に true を返すため、
既存コードの `$this->isTableLoaded = false;`（`updatedActiveTab()` / `updatedPaginators()` /
`updatedTableSearch()` / `updatedTableFilters()` / `updatedCatalogSort()` の5箇所）は**自動的に無効化される**。
これは狙い通りの挙動（キャッシュが温かいタブへの切替で余計な往復が発生しない）。

## 設計

```php
->deferLoading(fn (): bool => ! $this->hasWarmRecordsCache())
```

`hasWarmRecordsCache()` は、現在の `(activeTab, page, search, filters, sort)` に対応する
`SourceCache` エントリが **fresh でも stale でも**存在するかを、**上流アクセスなしで**判定する。

- Installed タブ: Stage 4 で描画がメタデータ文書のみに依存するようになったので、
  メタデータキャッシュの有無で判定
- カタログタブ: `SourceFetchSpec::cacheKey()` の存在チェック

**【2026-08-08 追記・実装後の訂正】** 上記の Installed タブの判定方針は、実際には
「決め打ちせず確認すべき点」（下記）の検討の結果、**採用されなかった**。実装
（`ModManagerPage::hasWarmRecordsCache()`）は Installed タブでは常に `false`（＝常時 defer のまま）
を返す。理由: Installed タブの同期描画は、スキャン結果キャッシュが無い場合に同期 Wings スキャンを
発生させうるが、`hasWarmRecordsCache()` が見られるメタデータキャッシュはこのスキャン結果キャッシュ
とは寿命が異なり代弁できないため、「温かい」と誤判定した場合に安全なフォールバックが保証できない。
詳細は `docs/architecture.md` の「Conditional deferred table loading」節を参照。

## リスク

**SWR Blade（`table-swr-cache.blade.php`, 1458行）との相互作用が未知。**
このスクリプトは「defer → ローディング → morph で本物が入る」という前提で
morph フック（`morph` / `morph.updating` / `morph.removing` / `morphed`）を組んでいる。
同期描画では最初から本物が入るため**動作は単純化するはず**だが、実測が必要。

## Codex 依頼文（Stage 6）

```
リポジトリ: kazaminosuke/pelican-minecraft-modrinth (main) ※ Stage 3, 5 完了後

【前提（調査済み・再確認不要）】
Filament v5.7.5 の実装を確認済みです:
  Filament\Tables\Table\Concerns\CanDeferLoading::deferLoading(bool|Closure $condition = true)
    → Closure を受け付け、isLoadingDeferred() で evaluate() されます
  Filament\Tables\Concerns\CanDeferLoading::isTableLoaded()
    → getTable()->isLoadingDeferred() が false なら常に true を返します
  $isTableLoaded は public プロパティです

したがって deferLoading にクロージャを渡して条件付きにすると、
既存の $this->isTableLoaded = false; の代入（updatedActiveTab / updatedPaginators /
updatedTableSearch / updatedTableFilters / updatedCatalogSort の5箇所）は
自動的に無効化されます。これは狙い通りの挙動です。

【タスク】
ModManagerPage::table() の ->deferLoading() を条件付きに変更してください。

  ->deferLoading(fn (): bool => ! $this->hasWarmRecordsCache())

hasWarmRecordsCache() は、現在の (activeTab, page, search, filters, sort) に対応する
キャッシュエントリが fresh でも stale でも存在するかを、上流アクセスを一切せずに
判定するメソッドです。
- カタログタブ: SourceFetchSpec::cacheKey() の存在チェック
- Installed タブ: Stage 4 で描画がメタデータ文書のみに依存するようになっているので、
  メタデータキャッシュの有無で判定

【最重要リスク: SWR Blade との相互作用】
resources/views/components/table-swr-cache.blade.php（1458行）は
「defer → ローディング → morph で本物が入る」前提で morph フック
（morph / morph.updating / morph.removing / morphed）を組んでいます。
同期描画では最初から本物が入るため動作は単純化するはずですが、必ず実測してください。

確認すべき挙動:
- 温かいキャッシュで同期描画したとき、SWR のプレビュー投影が二重に走らないこと
- 行アクションボタンの状態が正しいこと（過去に "stale row action flashes" の
  修正コミットが複数あるため、退行に特に注意）
- ページネータの位置が動かないこと（高さ固定レイアウトとの整合）
- タブ切替時（温かい → 温かい、温かい → 冷たい、冷たい → 温かい）の4パターンすべて

【検証】
1. Pint / PHPStan / PHPUnit green
2. 統合検証（実パネル）— 以下をすべて手動確認:
   - 温かいキャッシュ: mod-manager を開いて Livewire の往復が1回だけであること
     （DevTools Network で確認）
   - 冷たいキャッシュ: 従来通り2往復し、シェルが即座に描画されること
   - タブ切替 4パターン（温→温 / 温→冷 / 冷→温 / 冷→冷）
   - ページ送り、検索、フィルタ、ソート変更のそれぞれで表示崩れがないこと
   - 行アクション（versions / install_latest / update / installed / uninstall）の
     表示状態が正しいこと
   - ページネータが動かないこと
3. 温かいキャッシュでの初回表示時間を計測し、Stage 5 のベースラインと比較して報告

【決め打ちせず確認すべき点】
- SWR Blade に手を入れる必要が出た場合、変更を最小限に留めてください。
  大幅な改修が必要と判断した場合は実装せず、必要な変更内容とリスクを報告してください。
  （この Blade は多数の不具合修正の積み重ねであり、安易な書き換えは退行リスクが高い）
- Stage 5 の C で wire:navigate の mousedown プリフェッチが「飛ぶ」と判明していた場合、
  条件付き defer との組み合わせで RT1 も実質無料になります。その前提で
  改善幅がどう変わるか報告してください。
- 温かい判定が誤って true になった場合（キャッシュが判定直後に失効等）の
  フォールバック挙動を確認してください。records() が同期で上流を叩いて
  1.5秒待つことになるなら、それが許容できるか判断してください。
- Installed タブでも条件付き defer を適用すべきか判断してください。
  Wings 依存があるため、常に defer のままが安全かもしれません。

【実施しないこと】
- commit / push は行わないでください。
- パフォーマンス以外の変更（README 等）は今回行いません。
```

## 完了条件（S6 → S7）

- [ ] Pint / PHPStan / PHPUnit green
- [ ] 温かいキャッシュで Livewire 往復が 1 回
- [ ] タブ切替 4 パターンすべてで表示崩れなし
- [ ] 行アクションの状態に退行なし
- [ ] ページネータの位置が動かない
- [ ] **総合目標の達成確認: コールド／ウォーム両方で初回表示 1 秒以内（p95）**

---

# Stage 7 — README 改修

## 目的
実態（マルチソース、SWR、高さ安定化）を反映し、**パネル管理画面内でも読まれる運用者向けドキュメント**として再構成する。

## 前提

`Plugin::getReadme()` が `plugin_path($id, 'README.md')` を読み、**パネル管理画面内でレンダリングされる**
（5分キャッシュ）。GitHub のランディングページではなく運用者向けドキュメントとして構成する。

## 現状の最大の欠落

> **`ProjectSourceRegistry::availableFor()` は egg の `features` から
> `curseforge` / `hangar` / `github_releases` を読んでソースを有効化するが、
> README にその記述が一切ない。ユーザーはマルチソース機能の有効化方法を発見できない。**

## 構成案

1. **タイトル + 一行説明** — Pelican Panel 向けマルチソース Minecraft mod/plugin/datapack マネージャ
2. **スクリーンショット** — カタログ / Installed の2枚
3. **対応ソース表** — ソース / APIキー要否 / 検索 / ハッシュ照合 / 対応プロジェクト種別
4. **要件** — Pelican バージョン、PHP、**非同期キューワーカー必須**（運用ブロッカーなので上位に）
5. **インストール** — URL / ZIP（現行踏襲）
6. **Egg 設定** — `mod_manager` / `plugin_manager` / `datapack_manager`、`minecraft` タグ、
   ローダータグ、**＋ ソース有効化用 feature フラグ**（`curseforge` / `hangar` / `github_releases`、
   実例つき）
7. **設定** — プラグイン設定画面の4項目、対応する `.env` キー、
   "Clear cache" の全サーバ / 単一サーバ挙動の違い
8. **How it works** — 積み重なった改修を可読化する節:
   - ローカルメタデータインデックス（`.pelican-mod-manager.json`）と旧ファイル名からの移行
   - size + mtime シグネチャ再利用によるインクリメンタルハッシュスキャン
   - 背景ジョブ（scan / bulk update）とステータスバッジ
   - stale-while-revalidate キャッシュ（Stage 3 の成果）
   - sessionStorage による SWR テーブルプレビュー（何がキャッシュされ、何がされないか）
   - 高さ固定レイアウト（ページネータが動かない理由）
   - ウォームジョブとレート制限（Stage 5 の成果）
9. **トラブルシューティング** — キュー未設定警告 / "Not tracked" 行の意味 /
   ソースタブの "!" バッジ / データが古いときのキャッシュクリア
10. **Fork 系譜 + ライセンス** — 現行内容を下部へ
11. **開発者向けリンク**

**分割:** パネル内表示を考慮し README は **150行以内**に抑え、内部設計の詳細
（8番の深堀り、キャッシュキー一覧、ソース追加手順）は `docs/architecture.md` に分離する。

## Codex 依頼文（Stage 7）

```
リポジトリ: kazaminosuke/pelican-minecraft-modrinth (main) ※ Stage 6 完了後

【背景】
README.md は Pelican Panel の管理画面内でもレンダリングされます
（app/Models/Plugin.php の getReadme() が plugin_path($id, 'README.md') を読む）。
GitHub のランディングページではなく、運用者向けドキュメントとして構成してください。

現在の README は65行で Modrinth 専用の記述のままです。最大の欠落は、
ProjectSourceRegistry::availableFor() が egg の features から
'curseforge' / 'hangar' / 'github_releases' を読んでソースを有効化しているにもかかわらず、
README にその記述が一切ないことです。ユーザーはマルチソース機能の有効化方法を
発見できません。

【タスク】README.md を以下の構成に書き換え、詳細を docs/architecture.md に分離してください。

README.md（150行以内を目標）:
 1. タイトル + 一行説明（マルチソース対応を明示）
 2. スクリーンショット（プレースホルダとして画像パスを用意。画像自体は後で追加）
 3. 対応ソース表（ソース / APIキー要否 / 検索対応 / ハッシュ照合対応 / 対応プロジェクト種別）
    ※ 実際のコード（各 Source クラスの isConfigured / supportsSearch /
       supportsHashLookup / supportsProjectType）から正確に起こしてください
 4. 要件（Pelican バージョン、PHP、非同期キューワーカー必須）
    ※ キューワーカーは運用ブロッカーなので上位に移動してください
 5. インストール（URL / ZIP）— 現行内容を踏襲
 6. Egg 設定
    - mod_manager / plugin_manager / datapack_manager の feature
    - minecraft タグとローダータグ
    - ★ ソース有効化用 feature フラグ（curseforge / hangar / github_releases）を
      実例つきで必ず記載してください
 7. 設定（プラグイン設定画面の4項目、対応する .env キー、
    Clear cache の全サーバ／単一サーバ挙動の違い）
 8. How it works（各1〜3行の簡潔な説明。詳細は docs/architecture.md へリンク）
 9. トラブルシューティング
10. Fork 系譜 + ライセンス（現行内容を下部へ移動）
11. 開発者向けリンク

docs/architecture.md（新規）:
 - メタデータインデックス（.pelican-mod-manager.json）の構造と旧ファイル名からの移行
 - size + mtime シグネチャ再利用によるインクリメンタルハッシュスキャン
 - 背景ジョブ（scan / bulk update）のライフサイクルとステータスバッジ
 - stale-while-revalidate キャッシュ層（Stage 3 の成果）とキャッシュプロファイル一覧
 - ウォームジョブとレート制限（Stage 5 の成果）
 - sessionStorage による SWR テーブルプレビュー（何がキャッシュされ何がされないか）
 - 高さ固定レイアウト（ページネータが動かない理由）
 - キャッシュキー一覧
 - 新しいソースを追加する手順

【検証】
1. README.md が150行以内であること
2. 記載内容がすべて実コードと一致していること（特に対応ソース表と feature フラグ名）
3. 実パネルの管理画面でプラグインの README が正しくレンダリングされること
   （Markdown の記法がパネルのレンダラで崩れないか確認）
4. リンク切れがないこと

【決め打ちせず確認すべき点】
- 対応ソース表の内容は必ず実コードから起こし、推測で書かないでください。
  不明な項目があれば報告してください。
- Pelican の README レンダラがサポートする Markdown 記法を確認してください。
  テーブル、コードブロック、画像が使えるか。使えない記法があれば代替を提案してください。
- スクリーンショットの配置場所（docs/images/ 等）を提案してください。
- 日本語版 README（README.ja.md）を用意すべきか判断・提案してください。
  lang/ に ja があるので需要はありそうですが、メンテコストとのトレードオフです。
- 名称について: この時点ではまだ plugin id は pelican-minecraft-modrinth のままですが、
  README の表記は "Minecraft Mod Manager" に統一してください。
  インストール URL 等、id に依存する記述は現行のままにしてください（Stage 8 で更新）。

【実施しないこと】
- commit / push は行わないでください。
- plugin id / リポジトリ名に依存する記述の変更は Stage 8 です。
```

## 完了条件（S7 → S8）

- [ ] README 150 行以内、`docs/architecture.md` 分離済み
- [ ] 対応ソース表が実コードと一致
- [ ] feature フラグ（`curseforge` / `hangar` / `github_releases`）が実例つきで記載
- [ ] パネル管理画面で README が崩れずレンダリングされる

---

# Stage 8 — plugin id 変更 + リポジトリ名変更（最終）

## 8-A. 検証C の実施（Stage 8 本体の 2週間前）

**目的:** `raw.githubusercontent.com` がリポジトリリネーム時にリダイレクトするか確定する。

**手順:**
1. 検証用の公開リポジトリを 1 つ作成（例: `kazaminosuke/rename-test`）
2. `update.json` を配置し、`https://raw.githubusercontent.com/kazaminosuke/rename-test/refs/heads/main/update.json`
   が 200 で返ることを確認
3. リリースを 1 つ作成し、アセット URL も控える
4. リポジトリを `kazaminosuke/rename-test-2` にリネーム
5. **旧 raw URL** と**旧リリースアセット URL** に `curl -I` を打ち、
   200 / 301 / 404 のいずれかを記録
6. 検証用リポジトリを削除

**結果に応じた分岐:**

| 結果 | 対応 |
|---|---|
| 旧 raw URL が 200/301 | 通常の橋渡しで問題なし。ただし恒久対策（8-B の update 専用リポジトリ）は実施する |
| 旧 raw URL が 404 | **8-B の橋渡しリリースを既存ユーザーに十分に浸透させてから**リネームする。浸透期間（数週間〜）を設ける |

**注意:** どちらの結果でも 8-B の「update 専用リポジトリへの分離」は実施する。
これにより以後どのリポジトリをリネームしても更新チェックが切れなくなる。

## 8-B. 橋渡しリリース（**旧 id** のまま）

**目的:** 既存インストールに「移行してください」を届け、`update_url` をリネーム耐性のある場所へ移す。

内容:
1. `update_url` を専用リポジトリ（例: `kazaminosuke/pelican-plugin-updates`）の
   `update.json` に変更する
2. README とプラグイン設定画面に移行告知を出す
3. バージョンを上げてリリース

**この時点では plugin id もディレクトリ名も変えない。**
既存ユーザーが通常の更新フローでこのリリースを受け取れることが重要。

**浸透期間を置く**（8-A の結果が 404 の場合は特に長めに）。

## 8-C. 本体リネーム

**Pelican の制約（実コードで確認済み）:**
- `plugin.json.id` は**フォルダ名と一致必須**（不一致で `PluginIdMismatchException`)
- `plugin_path($plugin->id, ...)` が config / lang 名前空間 / view 名前空間 / migrations / README を決定
- `downloadPluginFromUrl()` は **ZIP のファイル名**から展開先フォルダを決定し、
  `cleanDownload` は `plugins/<zip名>` を削除する
- → **id 変更は「更新」ではなく「別プラグインの新規インストール」になる。**
  旧ディレクトリは残り有効なままなので、**ページが二重登録される。**

**変更対象:**
| 対象 | 変更 |
|---|---|
| ディレクトリ名 | `plugins/pelican-minecraft-modrinth` → `plugins/pelican-mod-manager` |
| `plugin.json` の `id` | `pelican-mod-manager` |
| ZIP ファイル名 | `pelican-mod-manager.zip` |
| `update.json` の `download_url` | 新リポジトリ・新ファイル名 |
| `config/pelican-minecraft-modrinth.php` | `config/pelican-mod-manager.php` |
| `config('pelican-minecraft-modrinth.*')` | 4キーすべて |
| `trans('pelican-minecraft-modrinth::strings.*')` | 約140箇所 |
| `view('pelican-minecraft-modrinth::*')` | 2箇所 |
| `plugin_path('pelican-minecraft-modrinth', ...)` | 2箇所 |
| session キー `'pelican-minecraft-modrinth.catalog-sort.'` | 新 id へ |
| `.github/workflows/lint.yml` のパス | 5箇所 |
| `phpunit.xml` 起動パス（CI 内） | 追随 |

**引き継がれるもの / 失われるもの:**
| 項目 | 扱い |
|---|---|
| サーバ上のメタデータ（`.pelican-mod-manager.json`） | **そのまま引き継がれる**（id 非依存。過去のファイル名リネームが効いている） |
| `.env` の値 | グローバルキーなので**引き継がれる** |
| キャッシュ | 接頭辞バージョンを上げて破棄（TTL 付きなので移行不要） |
| プラグイン設定画面の設定 | `.env` に書かれているので引き継がれる |

**`.env` キーの名前空間化（この機会に実施）:**
現在 `LATEST_MINECRAFT_VERSION` / `CURSEFORGE_API_KEY` / `GITHUB_TOKEN` は**名前空間が無く、
他プラグインと衝突しうる**。新 id では名前空間付きキーを導入し、**1リリースだけ旧キーへの
フォールバック読み**を残す:
```php
env('MOD_MANAGER_CURSEFORGE_API_KEY', env('CURSEFORGE_API_KEY'))
```

## Codex 依頼文（Stage 8-C）

```
リポジトリ: kazaminosuke/pelican-mod-manager（リネーム後）※ Stage 8-A, 8-B 完了後

【Pelican の制約（調査済み・再確認不要）】
- app/Models/Plugin.php:136: plugin.json の id はフォルダ名と一致必須
  （不一致で PluginIdMismatchException）
- plugin_path($plugin->id, ...) が config / lang 名前空間 / view 名前空間 /
  migrations / README のすべてを決定する
- PluginService::downloadPluginFromFile(): 展開先フォルダは ZIP のファイル名から決まり、
  cleanDownload は plugins/<zip名> を削除する
- したがって id 変更は「更新」ではなく「別プラグインの新規インストール」になり、
  旧ディレクトリは残って有効なままなのでページが二重登録される
  → これは Stage 8-B の橋渡しリリースと告知で対処済みの前提です

【タスク】plugin id を pelican-minecraft-modrinth → pelican-mod-manager に変更してください。

A. 一括置換（文字列リテラル pelican-minecraft-modrinth → pelican-mod-manager）
   - plugin.json の "id"
   - config/pelican-minecraft-modrinth.php → config/pelican-mod-manager.php（ファイル名）
   - config('pelican-minecraft-modrinth.*') 全4キー
   - trans('pelican-minecraft-modrinth::strings.*') 全箇所（約140）
   - view('pelican-minecraft-modrinth::*') 2箇所
   - plugin_path('pelican-minecraft-modrinth', ...) 2箇所
   - session キー 'pelican-minecraft-modrinth.catalog-sort.'
   - .github/workflows/lint.yml のパス5箇所
   - update.json の download_url（新リポジトリ・新ファイル名 pelican-mod-manager.zip）
   ※ plugin.json の update_url は Stage 8-B で専用リポジトリに移してあるので変更しません

B. .env キーの名前空間化
   ナビゲーション順は、現行の専用キー
   MINECRAFT_MODRINTH_MOD_NAV_SORT / MINECRAFT_MODRINTH_PLUGIN_NAV_SORT /
   MINECRAFT_MODRINTH_DATAPACK_NAV_SORT のみを使用してください。
   旧sharedキーへのフォールバックはpre-releaseのため追加しません。

C. キャッシュキーの接頭辞バージョンを一斉に上げる
   （id が変わってもキャッシュストアは共有されるため、混線を避ける）

D. ディレクトリ名の変更手順を README または RELEASING.md に文書化
   （リポジトリのディレクトリ構造自体は変わりませんが、
     ZIP のトップレベルディレクトリ名とリリース手順に影響します）

【検証】
1. Pint / PHPStan / PHPUnit green（CI のパスも新 id に更新済みであること）
2. grep で "pelican-minecraft-modrinth" のヒットが 0 件であること
   （移行告知の文言を除く）
3. クリーンな Pelican に新 ZIP をインストールし、以下を確認:
   - plugins/pelican-mod-manager に展開されること
   - PluginIdMismatchException が出ないこと
   - mod-manager ページが開き、カタログ / Installed 両タブが動作すること
   - 翻訳が正しく表示されること（de / en / ja すべて）
   - 旧URL向けの互換redirectは提供せず、現行slugだけが登録されること
4. 旧 id が入った既存環境で、新 id を追加インストールし:
   - サーバ上の .pelican-mod-manager.json が引き継がれ、Installed タブに
     従来通りの内容が表示されること
   - ナビゲーション順は専用の3つの.envキーだけで設定できること
   - 旧プラグインを uninstall しても新プラグインが正常動作すること

【決め打ちせず確認すべき点】
- 4 の「旧 id と新 id が同時に有効な状態」で、ページの二重登録が
  実際にどう見えるかを確認し、報告してください。
  ナビゲーションに2項目出る / スラッグが衝突する 等の具体的な症状を記録し、
  移行手順書に反映してください。
- 旧 .env キーのフォールバックは実装しない（pre-releaseのため）。
- 旧 id 側の最終リリースを出すべきか判断してください。
  （新 id への移行を促す告知のみを含むバージョン）
- ProjectSourceKey::Modrinth の値 'modrinth' は絶対に変更しないでください
  （メタデータに永続化されています）。念のため確認してください。
- 旧slugのredirect pageは作成しないでください（pre-releaseのため）。

【実施しないこと】
- commit / push は指示があるまで行わないでください。
- リポジトリ名の変更とリリース作成は人間が行います。
```

## 完了条件（S8 完了 = プロジェクト完了）

- [ ] 8-A の検証結果が記録され、対応方針が決定済み
- [ ] 8-B の橋渡しリリースが配布され、浸透期間を経過
- [ ] `pelican-minecraft-modrinth` の grep ヒットが 0（フォールバック読みと告知文言を除く）
- [ ] クリーン環境で新 ZIP がインストールでき、全機能が動作
- [ ] 既存環境からの移行でサーバ上メタデータと `.env` 設定が引き継がれる
- [ ] 移行手順書が公開されている

---

# 付録: 全体の受け入れ基準（パフォーマンス）

Stage 6 完了時点で以下を満たすこと。

| 条件 | 基準 |
|---|---|
| カタログタブ、コールドキャッシュ、上流API正常 | 初回描画 **p95 ≤ 1秒** |
| カタログタブ、コールドキャッシュ、**上流API到達不能** | 初回描画 **p95 ≤ 1秒**（stale または即座の空表示。2秒ハングは不可） |
| カタログタブ、ウォームキャッシュ | Livewire 往復 **1回**、初回描画 **p95 ≤ 1秒** |
| Installed タブ、メタデータ既存・スキャン済み、mod 50件以上 | 初回描画 **p95 ≤ 1秒**、上流APIへの同期リクエスト **0件** |

**計測方法:** Stage 2 で導入する `MOD_MANAGER_DEBUG_TIMING` を有効にし、
各 Stage の完了時に同一条件で計測してベースラインを更新していく。

---

# 付録: 参考プロジェクトの該当箇所（Codex に読ませる用）

| プロジェクト | ファイル | 何を参照するか | 使う Stage |
|---|---|---|---|
| modrinth/code | `packages/app-lib/src/state/cache.rs:808-819` | `CacheBehaviour` enum の4値と既定値 | S3 |
| modrinth/code | `packages/app-lib/src/state/cache.rs:97-109` | 種別別 TTL の実測値 | S3 |
| modrinth/code | `packages/app-lib/src/state/cache.rs:1085-1110` | stale を返して背景再検証する実装 | S3 |
| modrinth/code | `packages/app-lib/src/state/cache.rs:1116-1163` | ヒット収集 + ミス分のみバッチ取得 | S4 |
| modrinth/code | `packages/app-lib/src/state/instances/commands/sync_content_files.rs` | size+mtime によるハッシュ再利用 | S4 |
| modrinth/code | `apps/app-frontend/src/App.vue:725-755` | アプリシェル準備完了時のプリフェッチ | S5 |
| PrismLauncher | `launcher/minecraft/mod/tasks/ResourceFolderLoadTask.cpp` | メタデータ先読み → 未登録 jar のみ解析 | S4 |
| PrismLauncher | `launcher/modplatform/EnsureMetadataTask.cpp` | ハッシュ照合を別立て操作として分離 | S4 |
| GDLauncher-Carbon | `crates/carbon_app/src/cache_middleware.rs` | HTTP層キャッシュ、エラー応答を保存しない理由、サイズ上限 | S3 |
| GDLauncher-Carbon | `crates/carbon_app/src/managers/metadata/cache/mod.rs:56-110` | レート制限スロットル（70%自己制限、ユーザー起因は迂回） | S5 |
| GDLauncher-Carbon | `crates/carbon_app/src/managers/metadata/cache/mod.rs:146-460` | 優先度制御付き背景キャッシュループ | S4, S5 |

クローン先（このセッションで作成済み・再利用可）:
```
<scratchpad>/refs/modrinth-code
<scratchpad>/refs/prismlauncher
<scratchpad>/refs/gdlauncher-carbon
<scratchpad>/refs/pelican-panel   (sparse: app/Services, app/Models, app/Providers 他)
<scratchpad>/refs/filament        (v5.7.5, sparse: packages/tables/src, packages/panels/src, packages/support/src)
```
