# Stage 8 — egg 自動認識（設計書 v4）

作成日: 2026-08-07
改訂 v2: 調査範囲拡大（54 egg）・datapack 既定値・GUI フォールバック
改訂 v3: 手動フォームを管理者限定化・uuid 有無の対応関係を明記・
         変数シグネチャ照合を実測検証のうえ正式な照合段へ格上げ
改訂 v4: 手動フォームの権限を固定ではなくトグルで切替可能に（既定 OFF）・
         Pelican の権限解決を実コードで調査し判定方法を決定・
         既存のセキュリティギャップ（権限チェック皆無）を発見し併記
         ※ v3 で確定した内容（Pterodactyl 系 49 egg の全件自動解決、
           誤同定の回避策、衝突シグネチャの扱い）はすべて維持
※ 従来 Stage 8 だった「plugin id 変更 + リポジトリ名変更」は **Stage 9 へ繰り下げ**。

---

## 0. v1 からの訂正（据え置き・再掲）

| # | v1 の記述 | 実際 |
|---|---|---|
| 1 | 「Pelican にプラグイン用マイグレーションの実行保証が無い」 | **誤り。** `PluginService::installPlugin()` が `runPluginMigrations()` を呼び、`plugins/<id>/database/migrations/` を自動実行する（アンインストール時は `reset()`）。専用テーブルは正式サポート |
| 2 | 「`database/Seeders/eggs/minecraft/` を調べる」 | **現在の Pelican には存在しない。** コミット `bed9dbeb`「Add Eggs to Installer (#2004)」(2025-12-29) で削除され、Web インストーラーの egg 選択ステップに置換。同ディレクトリは **Pterodactyl 側には現存** |
| 3 | 「公式リポジトリに NeoForge egg が無い」 | **誤り。** インデックス経由で存在する（`e23e092f-…`）。Velocity も同様 |

---

## 1. uuid 有無の対応関係（ご確認事項 2 への回答）

> **ご理解のとおりです。** 「54/54 が uuid を持つ」と「uuid 無し・`update_url` null」は、
> **別々の母集団についての結果**です。以下に対応関係を明記します。

| # | 母集団 | 取得元 | 件数 | uuid | `update_url` | `tags` |
|---|---|---|---|---|---|---|
| **A** | **Pelican 公式**（インストーラーが提示する egg インデックスの Minecraft nest） | `config('panel.cdn.egg_index_url')` → `pelican-eggs.github.io/content/pelican.json` → 各 egg は `pelican-eggs/minecraft` 上の `.yaml`/`.json` | **54** | **54/54 あり（100%）** | あり | **54/54 に `minecraft`**（プロキシは `+proxy`） |
| **B** | **Pterodactyl 系** | `parkervcp/eggs` の `game_eggs/minecraft/**` (47) + `pterodactyl/panel` の `database/Seeders/eggs/minecraft/` (2 追加) | **49** | **0/49（全滅）** | **全件 null** | **全件 `[]`（`minecraft` タグすら無い）** |

補足:

- 母集団 A のうち **`meta.version` が `PTDL_v2` のままの egg も uuid を持ちます**。
  これは `pelican-eggs/minecraft` 側が移行時に uuid を付与したためで、
  「PTDL_v2 だから uuid が無い」わけではありません。
  **uuid の有無を分けているのは egg フォーマットではなく“どのリポジトリ由来か”** です。
- 母集団 B の egg を Pelican にインポートすると、
  `EggImporterService::fromParsed()` の
  `$uuid = $parsed['uuid'] ?? Uuid::uuid4()->toString();` により
  **インストールごとに異なるランダム uuid** が振られます。
  → **母集団 B は uuid でも `update_url` でも永続的に同定不可能**です。
- したがって v2 で「`minecraft` タグ必須」としたヒューリスティック閾値は、
  **母集団 B を 49/49 すべて弾いてしまいます**（B は tags が空のため）。

この対応関係を踏まえ、3 章の照合設計を組んでいます。

---

## 2. 母集団 A の調査結果（v2 から据え置き）

Minecraft nest 54 egg を全件ダウンロードして精査:

| 項目 | 結果 |
|---|---|
| uuid を持つ | **54/54** |
| `minecraft` タグを持つ | **54/54**（Bedrock 系も持つ点に注意） |
| ローダータグ（paper/fabric 等）を持つ | **0/54** |
| `mod_manager` / `plugin_manager` / `datapack_manager` | **0/54** |
| nest 全体に存在する feature | `pid_limit`(43) / `eula`(42) / `java_version`(42) のみ |
| `proxy` タグ | 9（プロキシ判別に使える） |

→ 公式 egg は素の状態でこのプラグインが 100% 動作しません（v1 の結論を 54 egg 規模で追認）。

**uuid 重複が 1 件実在**: `FTB Server` と `FTB-modpacks.ch Server` が共に
`e60a9de8-a0b1-4d97-b4e2-6568f048668d`。uuid を一意と仮定してはいけません。

---

## 3. 変数シグネチャ照合の実測検証（ご依頼 3）

母集団 A（54 egg）から作った参照シグネチャに対し、**母集団 B の 49 egg を実際に照合**して
精度を測定しました。

### 3-1. 戦略別の実測結果

| 戦略 | 自動解決 | 誤同定 | 評価 |
|---|---|---|---|
| **A. 変数シグネチャ単独** | 24/49（49%） | **2 件** | ❌ **採用不可** |
| **B. 名前単独** | 36/49（73%） | 0 件 | △ 曖昧多数（Purpur/Waterdog/Tekkit 系） |
| **C. シグネチャ ∧ 名前** | 27/49（55%） | 0 件 | ○ 安全だが取りこぼす |
| **D. 名前エイリアス + シグネチャの段階照合（推奨）** | **49/49（100%）** | **0 件** | ✅ **採用** |

### 3-2. 戦略 A（シグネチャ単独）を採用してはいけない理由

実測で **2 件の誤同定**が出ました。とくに 1 件目は実害があります:

```
Pterodactyl「Folia」 → Pelican「Paper」と誤一致
   両者とも変数が [BUILD_NUMBER, DL_PATH, MINECRAFT_VERSION, SERVER_JARFILE] で完全一致
   → ローダーを paper と誤判定し、Modrinth の検索結果が folia 用にならない

Pterodactyl「Vanilla Bedrock」 → Pelican「Vanilla Bedrock ARM64」と誤一致
   （両者とも最終的に status=none なので実害はないが、照合としては誤り）
```

**ご依頼にあった「シグネチャ照合を uuid/update_url に次ぐ優先順位で採用」は、
このままでは危険**です。シグネチャは**単独の同定キーにはできません**。

### 3-3. 両エコシステムを統合したときのシグネチャ衝突

母集団 A + B を統合して集計:

```
総シグネチャ数              37
  >1 プロファイルに衝突      7   ← 単独キーにできない
  一意（単独キーに使える）   30   (81%)
```

衝突 7 件の内訳:

| 衝突するシグネチャ | 衝突するプロファイル | 実害 |
|---|---|---|
| `BUILD_NUMBER,DL_PATH,MINECRAFT_VERSION,SERVER_JARFILE` | **paper / folia** | **あり（ローダー誤判定）** |
| `SERVER_JARFILE,VANILLA_VERSION` | **vanilla / vanillacord** | **あり（datapack 対象 ↔ 対象外）** |
| `SERVER_JARFILE,WATERDOG_VERSION` | **waterdog / waterdog PE** | **あり（Java ↔ Bedrock）** |
| `BUILD_NUMBER,MINECRAFT_VERSION,SERVER_JARFILE` | purpur / purpur-geyser-floodgate | なし（結論が同一） |
| `BUILD_NUMBER,DL_LINK,MINECRAFT_VERSION,SERVER_JARFILE` | travertine / waterfall | なし（結論が同一） |
| `BEDROCK_VERSION,CHEATS,…` | vanilla bedrock / ARM64 | なし（両方 status=none） |
| `FTB_MODPACK_ID,…` | FTB Server / FTB-modpacks.ch | なし（両方 manual_required） |

### 3-4. 採用する戦略 D の仕様

各プロファイルに **`name_aliases`（正規化名の配列）** と
**`variable_signatures`（エコシステムごとに複数）** を持たせ、段階照合します。

```
D-1  name_aliases 一致 ∧ signature 一致  → exact（最も確実）      実測 45/49
D-2  name_aliases 一致が一意             → high                   実測  4/49
D-3  signature 一致 ∧ 非衝突シグネチャ    → medium（改名 egg 救済） 実測  0/49 ※下記
D-4  いずれも不可                        → GUI フォールバックへ
```

- **D-3 は母集団 B では発火しません**（B の名前はすべてエイリアスに載るため）。
  これは **管理者が egg をリネームした場合**の救済段です。
  例: 「My Paper Server」に改名 → 名前照合は外れる → シグネチャ照合へ →
  ただし Paper のシグネチャは folia と衝突するので**拒否して手動へ**（安全）。
  一方 Spigot（`DL_PATH,DL_VERSION,SERVER_JARFILE`、非衝突）に改名した場合は
  **medium で自動解決できます**。
- **衝突シグネチャは単独では絶対に採用しない**のが安全性の要です。

### 3-5. 実測 100% の但し書き（重要）

戦略 D の 49/49 は、**`name_aliases` を実際の Pterodactyl egg 名を見て作成した結果**です
（uuid リストと同じく**キュレーションされた成果物**）。したがって:

- ✅ **今回調査した母集団 A・B の全 103 egg は自動解決できます**
- ⚠ **未知 egg・管理者が改名した egg・自作 egg には保証がありません**
  → だから GUI フォールバック（5 章）は**依然として必要**です
- ✅ ただし D の設計は**誤同定を 0 に保ったまま**この数字を出しており、
  「取りこぼしは手動へ、誤答はしない」という安全側の性質を持ちます

### 3-6. 結論（ご依頼 3 への回答）

> **シグネチャ照合は「格上げする」が、「単独キーにはしない」**という形で採用します。
> 具体的には、uuid 一致・update_url 一致に次ぐ **第 4 段（D-1）／第 6 段（D-3）**として
> 正式な照合手段に組み込みます。
> これにより **Pterodactyl 系 49 egg は GUI フォールバック不要**になり、
> フォールバックは真に未知の egg だけが対象になります。

---

## 4. 収録リスト（母集団 A の 54 egg 分類・v2 から据え置き）

| 分類 | 件数 | `status` | 主な内容 |
|---|---|---|---|
| Mod サーバー | 4 | `resolved` | Fabric / Forge / NeoForge / Quilt |
| Plugin サーバー | 7 | `resolved` | Paper / Purpur / Folia / Spigot / Purpur-Geyser / Glowstone / Sponge |
| ハイブリッド | 4 | 要判断 | Mohist / Magma / Ketting / SpongeForge |
| Vanilla | 1 | `resolved`（datapack のみ） | Vanilla Minecraft |
| プロキシ | 5 | `resolved`（`is_proxy`） | Bungeecord / Waterfall / Velocity / Travertine / Waterdog |
| **除外** | **17** | `none` | **Bedrock 8** + 非 Java 3 + Limbo 2 + ユーティリティプロキシ 4 |
| モドパック | 16 | `manual_required` | Technic 10 / FTB 4 / CurseForge・Modrinth Generic 2 |
| **合計** | **54** | | |

主要 egg の uuid・ローダー・MC バージョン変数の一覧は
**v2 の 3 章の表**をそのまま使用してください（変更なし）。とくに:

- Paper `5da37ef6-…`（旧 `150956be-…`）/ Forge `ed072427-…`（旧 `d6018085-…`）は**旧 uuid も収録**
- Spigot は **`DL_VERSION`**、Vanilla は **`VANILLA_VERSION`**（現状の既定値誤用バグの原因）
- Bungeecord / Velocity は MC バージョン変数を持たない → リストを空にする
- **Bedrock 8 件を `status: none` に必ず登録**（5 章の安全性に直結）

---

## 5. ご依頼 2: `supports_datapacks` の既定値自動化（v2 から据え置き）

優先順位:

```
1. 明示的な datapack_manager_disabled feature  → false（最優先・新設）
2. 明示的な datapack_manager feature           → true
3. プロファイル DB
     Java サーバー系（mod/plugin/hybrid/vanilla/modpack） → true
     プロキシ系（is_proxy）                               → false
     status=none                                          → false
4. どれにも当たらない                            → false
```

⚠ **既存ユーザーの挙動が変わります**（`datapack_manager` 未設定の Paper 等に
Datapack ページが新たに出る）。`egg_autodetect_enabled=false` で
完全に従来挙動へ戻せることを実装条件とします。

---

## 6. ご依頼 1: GUI フォールバックの権限（v4 で「トグルで切替可能」に変更）

### 6-1. 権限方針（確定・v4）

> **既定は管理者限定。ただしプラグイン設定画面のトグルで、
> 一般ユーザーへの編集開放を管理者が切り替えられるようにする。**

新設する設定（`ModManagerPlugin::getSettingsForm()` に `Toggle` を追加）:

| 設定 | `.env` キー | 既定 |
|---|---|---|
| 一般ユーザーにも egg プロファイルの編集を許可 | `MOD_MANAGER_ALLOW_USER_EGG_PROFILE_EDIT` | **`false`（OFF＝管理者限定）** |

トグルの状態による挙動:

| | 管理者 | 一般ユーザー（サーバーを管理できる） | 一般ユーザー（権限なし） |
|---|---|---|---|
| **OFF（既定）** | 管理画面で編集可。サーバー側ページには設定画面へのリンク | 読み取り専用の案内のみ（入力欄なし） | 読み取り専用の案内のみ |
| **ON** | 同上 | **サーバー側ページに入力欄・保存ボタンが出る** | 読み取り専用の案内のみ |

**トグルが ON でも「サーバーを管理できる権限が無いユーザー」には出しません。**
ON が意味するのは「管理者以外にも開放する」であって「全員に開放する」ではない、
という線引きです（判定方法は 6-2）。

### 6-2. 「このサーバーを管理できるユーザー」の判定（実コードで調査）

Pelican の権限解決は `User::can($ability, $server)` → `checkPermission()` →
`hasPermission()` の順で、実装は次のとおりでした（`app/Models/User.php`）:

```php
protected function hasPermission(Server $server, string $permission = ''): bool
{
    if ($this->canned('update', $server) || $server->owner_id === $this->id) {
        return true;                       // ① パネル管理者  ② サーバー所有者
    }
    ...
    $subuser = $server->subusers->where('user_id', $this->id)->first();
    if (!$subuser || empty($permission)) { return false; }
    return in_array($permission, $subuser->permissions);   // ③ 該当権限を持つサブユーザー
}
```

つまり `user()?->can(SubuserPermission::X, $server)` は
**管理者・サーバー所有者・当該権限を持つサブユーザー**の 3 者に true を返します。
これがまさに「このサーバーを管理できるユーザー」の判定に使えます。

候補の比較:

| 候補 | 通る人 | 評価 |
|---|---|---|
| `user()->isAdmin()` | 管理者のみ | トグル OFF 時の判定に使う |
| `$server->owner_id === user()->id` | 所有者のみ | サブユーザーを排除してしまい狭すぎる |
| `can(SubuserPermission::FileUpdate, $server)` | 管理者/所有者/`file.update` | ファイル操作の権限であって「egg 設定」ではない |
| **`can(SubuserPermission::StartupUpdate, $server)`** | 管理者/所有者/`startup.update` | **推奨** |

**`StartupUpdate`（`startup.update`）を推奨**する理由:

- egg プロファイル（ローダー・対応種別・MC バージョン）は概念的に**起動設定＝egg 設定**であり、
  Pelican の「Startup」タブで扱う領域とまったく同じカテゴリ
- 現に `MinecraftVersionResolver` が読んでいる `MINECRAFT_VERSION` / `MC_VERSION` は
  **`startup.update` 権限で編集できるサーバー変数そのもの**。
  その変数を変更してよい人が、ローダーを宣言してよい人と一致するのは自然
- `settings.*` 系（rename/reinstall）は用途が違い、`file.*` 系は粒度がずれる

→ 実装は `config('...allow_user_egg_profile_edit')` が true のとき
`user()?->can(SubuserPermission::StartupUpdate, $server)`、
false のとき `user()?->isAdmin()` を編集可否の判定に使います。
（この選択自体は Codex 依頼文の「決め打ちせず確認すべき点」にも残し、
実装時に再検証させます）

### 6-3. ⚠ 前提として判明した既存のセキュリティギャップ

調査中に判明した重要な事実です。**現状このプラグインには権限チェックが 1 つもありません。**

```
$ grep -rn "SubuserPermission|->can(|authorize" src/
（ヒットなし）
```

一方 Pelican 本体の `ListFiles` は、ファイル操作のアクション 1 つ 1 つに
`->authorize(fn () => user()?->can(SubuserPermission::FileUpdate, $server))` を付けています。

つまり現在は、**サーバーを閲覧できるサブユーザーなら、ファイル権限を一切持っていなくても
mod のインストール・削除・一括更新ができます**（実体はサーバー上のファイル書き込み）。

これは Stage 8 の範囲を超える既存の問題ですが、本件と直接関係します:

- 「一般ユーザーに egg プロファイル編集を開放する」ことのリスクは、
  **既に mod の導入・削除ができてしまう現状と比べれば小さい**
- 逆に言えば、**プロファイル編集だけ厳密に権限を掛けても全体としては片手落ち**

**推奨**: Stage 8 では 6-2 の権限判定を正しく入れたうえで、
**インストール／アンインストール／一括更新の各アクションにも
`SubuserPermission::FileUpdate` / `FileDelete` のチェックを追加する**ことを
併せて提案します（Codex 依頼文の「決め打ちせず確認すべき点」に含めます）。
既存ユーザーの挙動が変わるため、範囲を広げるかどうかはご判断ください。

### 6-4. 入力 UI の置き場所

**管理者向け（常に利用可能）**:
既存のプラグイン設定画面（`ModManagerPlugin::getSettingsForm()`）を拡張します。
`PluginResource` 側で `user()?->can('update', $plugin)` により既に管理者ゲート済みのため、
権限機構を新設せずに済みます。既存の「Clear cache」と同じ `Actions::make([...])` パターン。

| 項目 | 入力 |
|---|---|
| 対象 egg | Select（未解決の egg を優先表示。解決済み egg も選べて上書き可） |
| 対応種別 | mod / plugin / datapack のみ / 無効 |
| ローダー | `MinecraftLoader` の 13 値（種別が datapack のみ／無効なら不要） |
| MC バージョン | テキスト（空ならサーバー変数から自動解決を継続） |
| データパック対応 | on/off |

**一般ユーザー向け（トグル ON かつ 6-2 の権限を持つ場合のみ）**:
サーバー側ページ（`MANUAL_REQUIRED` 時）に、上記と同じ項目のうち
**対象 egg 以外**を入力するフォームを出します（対象 egg は現在のサーバーの egg に固定）。
保存先は管理画面と同一のテーブル（egg 単位）です。

どちらも推測値を初期値として入れ、**1 クリック保存**で済むようにします。

⚠ egg 単位の設定なので、**一般ユーザーの保存は同じ egg を使う他サーバーにも波及します**。
トグル ON 時はフォームにその旨の注意書きを必ず表示してください。

### 6-3. 永続化: プラグイン専用テーブル（egg 単位）

`plugins/pelican-mod-manager/database/migrations/` にマイグレーションを置くと
`PluginService::installPlugin()` が自動実行します（0 章の訂正 1）。

```
テーブル: mod_manager_egg_profiles
  id
  egg_id             FK eggs.id, unique, cascadeOnDelete
  egg_uuid           監査用
  project_type       'mod'|'plugin'|'datapack'|'none'
  loader             nullable
  minecraft_version  nullable（null ならサーバー変数から解決）
  supports_datapacks bool
  timestamps
```

**サーバー単位ではなく egg 単位**にする理由（v2 から据え置き）:
対応種別とローダーは本質的に egg の属性であり、同一 egg のサーバーを 10 台持つ運用者に
10 回同じ入力をさせないため。MC バージョンのみ真にサーバー単位だが、
それは既にサーバー変数から解決済み。

### 6-4. 解決段階の最終形（v3）

```
 1. 明示的な egg features / tags                      （現行動作・最優先）
 2. プロファイル DB: uuid 一致                          exact
 3. プロファイル DB: update_url 一致                    exact
 4. プロファイル DB: name_alias ∧ signature 一致        exact   ← ご依頼 3 で格上げ
 5. プロファイル DB: name_alias 一意一致                high
 6. プロファイル DB: signature 一致（非衝突のみ）        medium  ← 改名 egg 救済
 7. ヒューリスティック（種別のみ。ローダーは決めない）    low
 8. 手動プロファイル（6-3 のテーブル）
 9. MANUAL_REQUIRED（管理者に設定を促す。6-1 の権限で出し分け）
10. null（ページ非表示）
```

**9 と 10 の区別が設計の肝**（v2 から据え置き）。
`status: none` の egg（Bedrock 8 件等）と `minecraft` タグも変数一致も無い egg は
**必ず 10 に落とし、フォームを出さない**こと。

---

## 7. Codex 依頼文（Stage 8）

```
リポジトリ: kazaminosuke/pelican-mod-manager (main) ※ Stage 7（コミット 6fbb5ff）完了後

【前提（調査済み・再確認不要。推測で上書きしないこと）】

(1) 母集団 A = Pelican 公式インストーラーの egg インデックス
    config('panel.cdn.egg_index_url') 既定:
    https://raw.githubusercontent.com/pelican-eggs/pelican-eggs.github.io/refs/heads/main/content/pelican.json
    Minecraft nest の 54 egg を全件精査済み:
      - 54/54 が uuid を持つ / 54/54 が 'minecraft' タグを持つ（Bedrock 系も持つ）
      - ローダータグを持つ egg 0 件
      - mod_manager / plugin_manager / datapack_manager を持つ egg 0 件
      - uuid 重複が実在: FTB Server と FTB-modpacks.ch Server が共に
        e60a9de8-a0b1-4d97-b4e2-6568f048668d（uuid を一意と仮定しないこと）

(2) 母集団 B = Pterodactyl 系（parkervcp/eggs + pterodactyl/panel seeder）49 egg
      - 0/49 が uuid を持つ（インポート時にランダム uuid4 が振られる）
      - 全件 update_url が null
      - 全件 tags が空（'minecraft' タグすら無い）
      - 一方 name と変数名セットは母集団 A とほぼ同一
    → 母集団 B は uuid でも update_url でも永続同定できません。

(3) 変数シグネチャ照合は実測検証済みです。母集団 A を参照に B の 49 egg を照合:
      シグネチャ単独     : 24/49(49%) だが誤同定 2 件 → 単独キーにしてはいけない
        （実害例: Ptero「Folia」が Pelican「Paper」と誤一致。変数が完全一致するため）
      名前単独           : 36/49(73%)、曖昧多数
      名前エイリアス + シグネチャの段階照合 : 49/49(100%)、誤同定 0 件 ← これを採用
    両エコシステム統合時のシグネチャ衝突は 37 中 7 件。
    衝突するシグネチャは単独キーにしないこと。実害のある衝突:
      paper / folia、vanilla / vanillacord、waterdog / waterdogPE

(4) Pelican はプラグイン専用マイグレーションを正式サポート:
    PluginService::installPlugin() が runPluginMigrations() を呼び
    plugins/<id>/database/migrations/ を $migrator->run() する
    （uninstall 時は reset()）。専用テーブルを作って構いません。

(5) egg への書き戻しは禁止（EggImporterService::fillFromParsed() が
    tags/features を上書きするため egg 更新で消える）。

【タスク】

A. resources/egg-profiles.json（新規）
   スキーマ:
     { "version": 1, "profiles": [ {
         "id": "paper",
         "match": {
           "uuid": ["5da37ef6-...", "150956be-..."],
           "update_url_contains": ["/java/paper/egg-paper."],
           "name_aliases": ["paper"],
           "variable_signatures": [
             ["BUILD_NUMBER","DL_PATH","MINECRAFT_VERSION","SERVER_JARFILE"]
           ]
         },
         "status": "resolved",          // resolved | manual_required | none
         "loader": "paper",
         "project_type": "plugin",
         "is_proxy": false,
         "minecraft_version_variables": ["MINECRAFT_VERSION"]
       } ] }

   収録は添付設計書 4 章 + v2 の 3 章の表どおり（推測で書かないこと）:
     Mod 4 / Plugin 7 / ハイブリッド 4 / Vanilla 1 / プロキシ 5 /
     除外 17（Bedrock 8 件を必ず含む, status=none） / モドパック 16（manual_required）
   name_aliases には Pterodactyl 側の別名も入れること。実測で必要だったもの:
     forge      : "forgeminecraft", "forge", "forgeenhanced"
     sponge     : "sponge", "spongevanilla"
     mohist     : "mohistmc", "mohist"
     vanilla    : "vanillaminecraft", "vanilla"
   variable_signatures はエコシステムごとに複数持てること
   （例: Ptero 版 Mohist は [BUILD_VERSION,MC_VERSION,SERVER_JARFILE] で
    Pelican 版と異なる）。

   ★ 起動時（またはテスト時）に「>1 プロファイルに現れるシグネチャ」を検出し、
     それらを“非discriminating”としてマークすること。実測で 37 中 7 件が該当。

B. Support\EggProfile / EggProfileRegistry / EggProfileResolver（新規）
   照合順序は厳密に:
     1. 明示的な egg features / tags（現行動作。既存挙動を 1 ミリも変えない）
     2. uuid 一致                                  exact
     3. update_url 一致                            exact
     4. name_alias ∧ signature 一致                exact
     5. name_alias 一意一致                        high
     6. signature 一致（★非衝突シグネチャのみ）     medium
     7. ヒューリスティック（種別のみ／ローダーは決めない）low
     8. 手動プロファイル（D のテーブル）
     9. MANUAL_REQUIRED
    10. null

   - **衝突シグネチャは 6 で絶対に採用しないこと**（採用すると Folia が Paper に化ける）
   - uuid 重複を許容し、複数当たったら update_url → name_alias の順で決着
   - ProjectType::fromServer() は 1 描画で 30 回以上呼ばれるため
     リクエスト内メモ化を必須とする。上流 API / Wings アクセスは行わないこと

C. supports_datapacks の既定値自動化
     1. datapack_manager_disabled feature（新設） → false（最優先）
     2. datapack_manager feature                  → true
     3. プロファイル DB（Java 系 true / is_proxy false / status=none false）
     4. それ以外                                   → false
   ※ 既存挙動が変わります（datapack_manager 未設定の Paper 等に Datapack ページが出る）。
     egg_autodetect_enabled=false で必ず従来挙動へ戻せるようにしてください。

D. database/migrations/（新規）
   mod_manager_egg_profiles:
     id / egg_id(FK eggs.id, unique, cascadeOnDelete) / egg_uuid /
     project_type / loader(nullable) / minecraft_version(nullable) /
     supports_datapacks(bool) / timestamps
   **egg 単位**にすること（サーバー単位にしないこと。理由は設計書 6-3）。

E. 手動プロファイル設定 UI ＋ 権限トグル

   E-1. 権限トグル（新設）
     getSettingsForm() に Toggle を追加:
       ラベル: 「一般ユーザーにも egg プロファイルの編集を許可する」
       .env キー: MOD_MANAGER_ALLOW_USER_EGG_PROFILE_EDIT
       config キー: 'allow_user_egg_profile_edit'
       既定値: false（＝管理者限定）
     Pelican 本体の Settings 画面と同じ Toggle パターンに合わせること:
       Toggle::make(...)->formatStateUsing(fn ($state): bool => (bool) $state)
     saveSettings() で writeToEnvironment に含めること。

   E-2. 編集可否の判定
     $canEdit =
       config('pelican-mod-manager.allow_user_egg_profile_edit')
         ? user()?->can(SubuserPermission::StartupUpdate, $server)   // 管理者/所有者/startup.update
         : user()?->isAdmin();
     ★ SubuserPermission::StartupUpdate を選んだ理由:
       egg プロファイル（ローダー/対応種別/MC バージョン）は概念的に起動設定であり、
       MinecraftVersionResolver が読む MINECRAFT_VERSION / MC_VERSION 自体が
       startup.update 権限で編集できるサーバー変数のため。
       ただしこの選択は下記「決め打ちせず確認すべき点」で再検証すること。
     ※ 参考: User::hasPermission() は
       canned('update',$server) || owner_id 一致 を先に true にするため、
       can(SubuserPermission::X, $server) は
       「管理者・サーバー所有者・当該権限を持つサブユーザー」に true を返す。

   E-3. 管理者向け UI（トグルの状態によらず常に利用可能）
     ModManagerPlugin::getSettingsForm() を拡張し、既存の「Clear cache」と同じ
     Actions::make([...]) パターンで新規アクションを足すこと。
     この画面は PluginResource 側で user()?->can('update', $plugin) により
     既に管理者ゲート済みなので、権限機構を新設しないこと。
     入力項目: 対象 egg(Select) / 対応種別 / ローダー / MC バージョン / データパック対応。

   E-4. サーバー側ページ（ModManagerPage / MinecraftDatapackPage）の MANUAL_REQUIRED 時
     $canEdit == true  → 入力欄＋保存ボタンを表示（対象 egg は現在のサーバーの egg に固定）
     $canEdit == false → 読み取り専用の案内のみ。入力欄・保存ボタンは一切出さない
                         管理者なら管理画面設定へのリンクを添える
     ★ egg 単位の設定なので、一般ユーザーの保存は同じ egg を使う他サーバーにも波及する。
       トグル ON 時のフォームにはその旨の注意書きを必ず表示すること。

   E-5. status=none の egg や 'minecraft' タグも変数一致も無い egg では
     **この表示自体を出さないこと**（Bedrock サーバーに設定を促すのは明確なバグ）

F. 既存 5 メソッドへの配線（呼び出し側は変更しないこと）
   ProjectType::fromServer() / ProjectType::supportsDatapacks() /
   MinecraftLoader::fromServer() / MinecraftVersionResolver::resolve()
   ※ ProjectSourceRegistry::availableFor() は今回変更しないこと

G. 既存バグの修正
   上記 3 メソッドは $server->egg->features / ->tags を直接読んでいるが、
   Egg には inherit_features アクセサ（config_from による親 egg 継承）があり、
   親で feature を定義した子 egg が現状すべて検出漏れする。inherit_features を使うこと。

H. config
     'egg_autodetect_enabled'        => env('MOD_MANAGER_EGG_AUTODETECT', true)
     'egg_profiles_extra_path'       => env('MOD_MANAGER_EGG_PROFILES_PATH')
     'allow_user_egg_profile_edit'   => env('MOD_MANAGER_ALLOW_USER_EGG_PROFILE_EDIT', false)

I. 診断表示
   ModManagerPage の「サーバー情報」セクション（2088 行目付近）に、
   ローダー/種別が何で決まったか（明示設定 / uuid / update_url / name+signature /
   name / signature / 自動推定 / 手動設定）を表示すること。

【検証】
1. Pint / PHPStan / PHPUnit green
2. ユニットテスト（実 DB に依存しない既存方針を踏襲）:
   - 明示 features/tags があるとき、プロファイル DB を引かずに従来と同一結果
   - Paper の新 uuid / 旧 uuid 双方で projectType=plugin, loader=paper
   - uuid 不明 + update_url 一致で引けること
   - **uuid も update_url も無い Pterodactyl 版 Paper が name+signature で引けること**
   - **Pterodactyl 版 Folia が Paper に化けないこと**
     （シグネチャは Paper と同一だが name_alias が異なるため folia に解決されること）
   - **衝突シグネチャ（paper/folia 等）が signature 単独では採用されないこと**
   - 非衝突シグネチャ（Spigot 等）が改名 egg でも medium で解決されること
   - Spigot が DL_VERSION、Vanilla が VANILLA_VERSION から MC バージョンを解決
   - Bungeecord は MC 変数リストが空で config 既定値に落ち supports_datapacks=false
   - **Bedrock egg が status=none になり、手動設定の案内も出ないこと**
   - モドパック egg が MANUAL_REQUIRED になること
   - **権限トグル OFF（既定）で、一般ユーザーに入力欄が出ず、
     管理者にのみリンクが出ること**
   - **権限トグル ON で、startup.update を持つサブユーザーに入力欄が出ること**
   - **権限トグル ON でも、startup.update を持たないサブユーザーには
     入力欄が出ないこと**（ON＝全員開放ではない）
   - サーバー所有者はトグル ON のとき（権限指定なしでも）入力欄が出ること
   - 手動プロファイル保存後、以後は自動解決と同等に動作すること
   - datapack 優先順位 3 パターン（disabled / 明示有効 / プロファイル自動）
   - ヒューリスティック一致時にローダーが null のままであること
   - egg_autodetect_enabled=false で Stage 7 以前と同一挙動
   - 1 リクエスト内でプロファイル解決が 1 回のみ（メモ化）
3. egg-profiles.json の全 uuid が実 egg と一致（設計書 4 章と突合）
4. マイグレーションが plugins/<id>/database/migrations/ に置かれ、
   ロールバックも通ること

【決め打ちせず確認すべき点】
- ハイブリッド egg（Mohist / Magma / Ketting / SpongeForge）の扱い。
  ProjectType は単一値。mod / plugin どちらに倒すか、MANUAL_REQUIRED にするか、
  ProjectType を複数返せるようにするか判断し報告してください
  （影響範囲を考えると MANUAL_REQUIRED が落とし所かもしれません）
- Vanilla Minecraft を datapack のみとし、ModManagerPage を出さず
  MinecraftDatapackPage だけ出す挙動で問題ないか
- Glowstone の loader を bukkit、Sponge を sponge としたとき、
  Modrinth の該当 facet で妥当な検索結果が返るか
- モドパック系 16 件を manual_required にすると管理者に設定を促し続けます。
  「対象外として記憶する」導線が要るか検討してください
- name_aliases は今回の調査で観測した名前に基づくキュレーションです。
  未知 egg・改名 egg には効きません。エイリアスを運用者が
  egg_profiles_extra_path で追加できる形で十分か判断してください
- ★ 権限トグル ON 時の判定に SubuserPermission::StartupUpdate を選びましたが、
  この選択が妥当か再検証してください。候補は FileUpdate / SettingsRename /
  所有者限定など。egg プロファイルが「起動設定」カテゴリだという解釈が
  適切かどうかを含めて判断し、報告してください
- ★ egg 単位の設定を一般ユーザーが保存すると、同じ egg を使う他サーバー
  （他人のサーバーを含む）にも波及します。これが許容できるか、
  それともトグル ON 時だけはサーバー単位の上書きテーブルに保存すべきか
  判断してください（重要。設計書 6-4 の注意書きだけで足りるかの判断）
- ★ 既存のセキュリティギャップ（設計書 6-3）: 現在このプラグインには
  権限チェックが 1 つもなく、サーバーを閲覧できるサブユーザーなら
  ファイル権限を持たなくても mod のインストール・削除・一括更新ができます。
  Pelican 本体の ListFiles は各操作に SubuserPermission を課しています。
  Stage 8 の範囲に「インストール/アンインストール/一括更新への
  FileUpdate / FileDelete チェック追加」を含めるべきか判断し、報告してください。
  （既存挙動が変わるため、範囲を広げる場合は完了報告で明示すること）

【実施しないこと】
- egg（Egg モデル）への書き戻しは絶対に行わないこと
- ProjectSourceRegistry::availableFor() の変更は行わないこと
- 権限トグルの既定値を true にしないこと（既定は必ず管理者限定）
- トグル ON を「全ユーザーに開放」と解釈しないこと
  （サーバーを管理できる権限を持つユーザーに限ること）
- commit / push は行わないこと（指示があるまで）
- plugin id / リポジトリ名に関する変更は Stage 9 です
```

---

## 8. 完了条件（S8 → S9）

- [ ] Pint / PHPStan / PHPUnit green
- [ ] `egg-profiles.json` の全 uuid が実 egg と一致（母集団 A の 54 egg 分類済み）
- [ ] **明示 `features`/`tags` 設定済みの既存環境で、datapack 以外の挙動が変わらない**
- [ ] 素の公式 Paper / Fabric / NeoForge egg が egg 無編集で認識される
- [ ] **Pterodactyl 版 49 egg が name+signature で自動解決される（GUI 不要）**
- [ ] **Pterodactyl 版 Folia が Paper に化けない**
- [ ] 衝突シグネチャが signature 単独照合で採用されない
- [ ] Spigot / Vanilla の MC バージョンが egg 変数から解決される
- [ ] **Bedrock egg で mod-manager も設定案内も表示されない**
- [ ] **権限トグルの既定が OFF で、一般ユーザーに入力欄が一切出ない**
- [ ] **トグル ON で `startup.update` を持つユーザーのみ編集でき、
      持たないユーザーには出ない**（ON＝全員開放ではない）
- [ ] 手動プロファイルが専用テーブルに永続化され `cache:clear` で消えない
- [ ] `egg_autodetect_enabled=false` で Stage 7 以前と完全に同一挙動
- [ ] 1 リクエスト内でプロファイル解決が 1 回のみ

## 9. ロードマップ

| Stage | 内容 | リスク | 状態 |
|---|---|---|---|
| S1〜S7 | リング0〜README 改修 | — | ✅ 完了 |
| **S8** | **egg 自動認識** | **中〜高**（datapack 既定値変更で既存挙動が変わる） | 設計完了（v3）・未実装 |
| S9 | plugin id 変更 + リポジトリ名変更（旧 S8） | 高 | 未着手 |

Stage 8 完了時に README（英日）の Roadmap を「実装済み」へ更新し、
**datapack ページが既定で有効化される挙動変更**を明記すること。

---

## 10. 実装完了レビュー（2026-08-08 追記）

Stage 1〜8 の全体レビュー（`pelican-mod-manager-design.md`・本設計書との突合）を行った結果、
「決め打ちせず確認すべき点」のうち以下2点について実装後の決着を記録する。

### 10-1. 衝突シグネチャの実測値の訂正

3-3 節・7 章 Codex 依頼文に記載した「総シグネチャ数 37、衝突 7 件」は母集団 A・B（103 egg）の
み集計した際の数値。`resources/egg-profiles.json` に収録した全プロファイル（母集団 A・B に
加え、モドパック系 16 件の内訳整理を含む）で実測すると **総シグネチャ数 41、衝突 8 件**が正しい。
追加の1件はモドパック egg 同士（`MODPACK_VERSION` のみを共有する複数プロファイル）の衝突で、
全員がすでに `manual_required` のため誤同定リスクはなく、3-4 節の戦略Dの安全性（衝突シグネチャ
を単独採用しない）には影響しない。数値のみの訂正であり、設計・実装の変更は不要。

### 10-2. 既存のセキュリティギャップ（6-3 節）— Stage 8 の範囲外として持ち越し

> **2026-08-10追記（別改修で解消済み）:** 以下はStage 8完了時点の記録です。その後の独立した
> 権限制御改修で、追加・更新(一括更新を含む)・削除はそれぞれRoot Admin、Rolesの
> `Minecraft Mod Manager: Create/Update/Delete`、または明示的にONにした一般ユーザー用トグルと
> 対応するファイル権限で認可されるようになりました。現行仕様は
> [`docs/architecture.md`](docs/architecture.md)の「Project write authorization」を参照してください。

6-3 節・7 章「決め打ちせず確認すべき点」で判断を求めていた「mod のインストール／アンイン
ストール／一括更新に `SubuserPermission::FileUpdate` / `FileDelete` のチェックを追加すべきか」
について、**Stage 8 では対応しない**ことを確定する。

- 理由: egg プロファイルの権限トグル（6-2 節）は Stage 8 のスコープに含めて実装済みだが、
  インストール／アンインストール／一括更新側の権限チェック追加は、既存ユーザーの挙動を変える
  独立した変更であり、Stage 8（egg 自動認識）のスコープには含めない。
- **既知の未対応事項（Known issue）として記録する**: 現状このプラグインには
  `src/Filament/Server/Pages/ModManagerPage.php` のインストール／アンインストール／一括更新の
  各アクションに権限チェックが一つもなく、サーバーを閲覧できるサブユーザーであれば、
  ファイル権限（`SubuserPermission::FileUpdate` / `FileDelete`）を一切持っていなくても
  mod の導入・削除・一括更新ができる（Pelican 本体の `ListFiles` は各操作にこれらの
  権限チェックを課している）。
- 対応は Stage 9（最終リネーム）とは別の、独立した改修として別途行う。着手するまでは
  `docs/architecture.md` の「Known issues」節にも同じ内容を記録し、見落とされないようにする。
