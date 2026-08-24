# Minecraft Mod Manager

*[English](README.md)*

[Pelican Panel](https://pelican.dev) 用のプラグインです。**Modrinth、CurseForge、Hangar、GitHub Releases** のMod・Plugin・Datapack・Resource Packを、サーバーパネル上で検索・インストール・更新・管理できます。

![カタログタブ](docs/images/catalog.png)
![インストール済みタブ](docs/images/installed.png)

## 対応ソース

| ソース | APIキー | 検索 | ハッシュ照合 | 対応プロジェクト種別 |
|---|---|---|---|---|
| [Modrinth](https://modrinth.com) | 不要 | ✅ | ✅(`sha512`) | Mod, Plugin, Datapack, Resource Pack |
| [CurseForge](https://www.curseforge.com/minecraft) | **必須** | ✅ | ✅(`murmur2`) | Mod, Plugin, Datapack, Resource Pack |
| [Hangar](https://hangar.papermc.io) | 不要 | ✅ | ✅(`sha256`) | Plugin |
| [GitHub Releases](https://github.com) | 任意(推奨) | ❌(`owner/repo`を1件ずつ追跡) | ❌ | Mod, Plugin |

各サーバーの Admin → Server → Edit → Mod Manager に **Mod Managerの利用設定**があります。Modrinth・CurseForge・Hangarは既定でON(CurseForgeはAPIキーも必要)、GitHub Releasesは既定でOFFで、ここでONにします。sourceの切り替えはeggとは独立しており、各providerが対応するプロジェクトtypeの範囲に対して適用されます。
GitHub Releasesはトークンなしでも動作しますが、未認証時のレート制限(60リクエスト/時)は乏しいため、直接リポジトリを追跡する場合はトークンの設定を推奨します。GitHub Releasesにはカタログのキャッシュウォーミング経路はありません。

Resource Packには専用ページがあり、同じサーバー単位の利用設定で有効化します。Modrinthと
CurseForgeに対応しています。インストール時にアーカイブをサーバーの`resourcepacks/`へ
ダウンロードするのではなく、providerのdirect URLとSHA-1を`server.properties`の
`resource-pack`と`resource-pack-sha1`へ設定します。

## 要件

- Pelican Panel(`main`、Filament 5.6以上)
- PHP 8.3〜8.5
- PHP-FPMなど、HTTPリクエストごとに状態が分離されるruntime。Laravel Octaneなどの
  long-lived HTTP workerは現在サポート対象外です。
- **非同期キューワーカー(必須)。** インストール済みファイルのスキャン、一括更新、キャッシュのウォーミングはすべてキュージョブとして実行され、Livewireリクエストの応答性を保ちます。実際のドライバ(例: `QUEUE_CONNECTION=database`)を設定し、ワーカーを起動しておいてください。

  ```sh
  php artisan queue:work
  ```

  スキャン・一括更新については`sync`・`null`ドライバは意図的に拒否されます。この場合ブラウザのリクエストをブロックする代わりに、キュー未設定の警告が表示されます。

## インストール

**方法1: 直接URL** - Pelican Panelのプラグインインストーラーに以下を貼り付けてください。

```txt
https://github.com/kazaminosuke/pelican-mod-manager/releases/latest/download/pelican-mod-manager.zip
```

**方法2: ZIPアップロード** - [Releases](https://github.com/kazaminosuke/pelican-mod-manager/releases)ページから最新のZIPをダウンロードし、プラグインインストーラーからアップロードしてください。

## Egg設定

eggの`features`/`tags`は、引き続き**typeとローダーの判定**に使用されます。typeを明示する場合は、以下のfeatureを追加してください。

- `mod_manager` - `mods/` を管理
- `plugin_manager` - `plugins/` を管理
- `datapack_manager` - `world/datapacks/` を管理(上記いずれかと併用可能)

さらに`minecraft`**タグ**と、バージョン/ローダー別のフィルタリングを機能させるためのローダータグ(`paper`、`purpur`、`folia`、`spigot`、`bukkit`、`fabric`、`quilt`、`forge`、`neoforge`、`sponge`、`velocity`、`waterfall`、`bungeecord`のいずれか)を追加してください。

sourceを有効にするには、サーバーの**Mod Managerの利用設定**を使用します。GitHub ReleasesをONにすると**GitHubリポジトリを追跡**アクションが表示されます。GitHub Releasesには一覧検索できるカタログがないため、そこで`owner/repo`を入力して最新リリースを追跡してください。eggのfeatures/tagsにある`curseforge_disabled`、`hangar_disabled`、`github_releases`はsourceの有効化・無効化には使用されません。

Resource Packの利用可否もサーバー単位のtype設定で管理され、eggのfeature/tagからは判定しません。
eggの自動認識はMod/Pluginのtypeとローダー情報にのみ使用されます。

**eggの自動認識**([内部の仕組み](#内部の仕組み)参照)により、公式のMinecraft eggの大半はtypeやローダーを手動設定する必要がありません(明示的な`features`/`tags`が設定されていればtype/ローダー判定では常にそちらが優先されます)。sourceの利用可否は上記のサーバー設定から決まります。
これに伴う変更点として、認識されたJava系egg(mod/plugin/hybrid/vanilla/modpack)ではdatapack管理が`datapack_manager` featureなしでも**既定で有効**になります。無効化するにはeggのfeatureに`datapack_manager_disabled`を追加するか、`MOD_MANAGER_EGG_AUTODETECT=false`を設定して自動認識導入前の挙動(`datapack_manager`の明示指定が必須)に完全に戻してください。

## 設定

プラグイン設定画面(パネル管理者 → Plugins)には以下の項目があり、特記のない限りそれぞれ
グローバルな`.env`キーに対応しています。

サーバーごとの Admin → Server → Edit → Mod Manager では、各プロジェクトtypeのページと各sourceを
個別に切り替えられます。GitHub Releasesは既定OFF、Modrinth・CurseForge・Hangarは既定ONです。
CurseForgeはグローバルAPIキーが設定されるまで表示されません。

| 項目 | `.env`キー |
|---|---|
| 最新Minecraftバージョン | `MOD_MANAGER_LATEST_MINECRAFT_VERSION` |
| Modのナビゲーション表示順 | `MOD_MANAGER_MOD_NAV_SORT` |
| Pluginのナビゲーション表示順 | `MOD_MANAGER_PLUGIN_NAV_SORT` |
| Datapackのナビゲーション表示順 | `MOD_MANAGER_DATAPACK_NAV_SORT` |
| Resource Packのナビゲーション表示順 | `MOD_MANAGER_RESOURCEPACK_NAV_SORT` |
| CurseForge APIキー | `MOD_MANAGER_CURSEFORGE_API_KEY` |
| GitHubトークン | `MOD_MANAGER_GITHUB_TOKEN` |
| 一般ユーザーにもegg プロファイルの編集を許可 | `MOD_MANAGER_ALLOW_USER_EGG_PROFILE_EDIT`(既定OFF) |
| 一般サーバーユーザーにプロジェクトの追加を許可 | `MOD_MANAGER_ALLOW_USER_PROJECT_INSTALL`(既定OFF) |
| 一般サーバーユーザーにプロジェクトの更新を許可(一括更新を含む) | `MOD_MANAGER_ALLOW_USER_PROJECT_UPDATE`(既定OFF) |
| 一般サーバーユーザーにプロジェクトの削除を許可 | `MOD_MANAGER_ALLOW_USER_PROJECT_DELETE`(既定OFF) |

「最新Minecraftバージョン」は、サーバー自身に`MINECRAFT_VERSION`/`MC_VERSION`起動時変数が設定されていない場合のフォールバック値です。「一般ユーザーにもeggプロファイルの編集を許可」は、そのサーバーを既に管理できるユーザー(所有者・管理者・`startup.update`権限を持つサブユーザー)に編集を広げるだけで、全ユーザーに開放するものではありません。判定ロジックの詳細は[`docs/architecture.md`](docs/architecture.md)(英語)を参照してください。**注意:** この権限判定ロジックには現時点で自動テストが整備されておらず、手動確認のみで検証されています。変更する際は手動での再確認をお願いします。

プロジェクトの追加・更新・削除は別途保護されます。Root Adminは常に許可され、管理者はRolesで
**Minecraft Mod Manager: Create** / **Update** / **Delete** を操作ごとに付与できます。上記3つの
トグルは既定OFFです。ONにした操作では、対応する通常のファイル権限を持つサーバーユーザーにも
許可が広がります(追加は`FileCreate`、更新・一括更新は`FileCreate`と`FileDelete`の両方、削除は
`FileDelete`)。UIだけでなくLivewireアクション側でも同じ判定を行います。詳細は
[`docs/architecture.md`](docs/architecture.md)(英語)を参照してください。

同じ画面には、自動認識で解決できなかったeggに対して対応種別・ローダー・MCバージョン・
datapack対応を手動設定する**Egg profiles**アクションもあります。

同じ画面には**キャッシュをクリア**アクションもあり、対象範囲によって挙動が異なります。

- **全サーバー** - 全サーバーの追跡済みファイルMetadataと共有キャッシュをクリアしますが、即座には
  再スキャンしません。各サーバーは次回、該当するMod/Plugin/Datapack管理ページのInstalledタブを
  開いたときに自動的に再スキャンされます。
- **単一サーバー** - そのサーバーのMetadataをクリアし、即座に強制再スキャンをキューに投入します
  (稼働中のキューが必要です。[要件](#要件)を参照)。

## 内部の仕組み

- **ローカルMetadataインデックス**(各サーバー上の`.pelican-mod-manager.json`)が、インストール済み
  ファイルとアップストリームプロジェクトの対応関係を追跡します。
- **Resource Packの状態**(`.pelican-mod-manager-resource-pack.json`)は、`server.properties`へ
  設定するproviderのバージョン、direct URL、SHA-1を既存のMetadataインデックスとは別に保存します。
- **インクリメンタルなハッシュスキャン**により、サイズ/更新日時のシグネチャに変化があった
  ファイルのみを再ハッシュ化します(毎回全ファイルを再ハッシュ化しません)。
- **バックグラウンドジョブ・ステータスバッジ**により、スキャンと一括更新がUIをブロックせずに
  実行されます。スキャンはInstalledタブを開いている間だけ進行・短時間の完了結果を表示し、
  一括更新の進行状況はページ内に表示します。
- すべてのアップストリームAPI呼び出しの前段に**stale-while-revalidateキャッシュ**があり、
  データ種別ごとの鮮度ポリシーに加え、実際に使われている(ローダー / Minecraftバージョン /
  プロジェクト種別)の組み合わせを事前にキャッシュへ埋める**スケジュール実行のウォームジョブ**
  (ユーザー操作とは別枠でレート制限)を備えています。
- **条件付きの遅延読み込み**により、表示するデータが既にキャッシュ済みの場合は追加の読み込み
  往復自体を省略します。
- **eggの自動認識**により、パネル公式のMinecraft egg(およびPterodactylエコシステム側の
  対応するegg)を、eggを手で編集することなく認識します。明示的な`features`/`tags`はtypeと
  ローダー判定で自動認識より優先され、sourceの表示はサーバーのMod Manager利用設定で決まります。
  自動では判定しきれないeggには簡単な設定案内が表示されます。`MOD_MANAGER_EGG_AUTODETECT=false`
  で自動認識を無効化できます。

各項目の詳細な設計(検出順序や、eggを手動設定する方法を含む)については
[`docs/architecture.md`](docs/architecture.md)(英語)を参照してください。

## トラブルシューティング

- **「非同期キューワーカーが必要です」という警告が出る** - [要件](#要件)を参照してください。
- **行が「未追跡」と表示される** - mod/plugin/datapackフォルダにファイルは存在するものの、
  まだMetadataインデックスに記録されていない状態です。再スキャンアクションを使用してください。
- **CurseForgeタブが表示されない** - [設定](#設定)でCurseForge APIキーを設定し、そのサーバーの
  Admin → Server → Edit → Mod Manager利用設定でCurseForgeをONにしてください。
- **カタログのデータが古いと感じる** - 設定画面のキャッシュをクリアアクションを使用してください。
  全サーバー/単一サーバーでの挙動の違いは上記の通りです。

## リポジトリ

<https://github.com/kazaminosuke/pelican-mod-manager>

## フォークの系譜とライセンス

このリポジトリは
[H1ghSyst3m/plugins](https://github.com/H1ghSyst3m/plugins/tree/featcomplete-mod-plugin-management)
のフォークであり、同リポジトリは[pelican-dev/plugins](https://github.com/pelican-dev/plugins)の
フォークです。

GNU General Public License v3.0(GPL-3.0)の下でライセンスされています。詳細は
[`LICENSE`](LICENSE)を参照してください。

## 開発者向け

- [`docs/architecture.md`](docs/architecture.md)(英語) - キャッシュ層、Metadataフォーマット、
  新しいソースの追加方法。
- [Issues](https://github.com/kazaminosuke/pelican-mod-manager/issues) /
  [Pull requests](https://github.com/kazaminosuke/pelican-mod-manager/pulls)
