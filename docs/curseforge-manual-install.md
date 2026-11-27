# CurseForgeの配布制限付きModを導入する

対象は、通常のCurseForge Modpack ZIPの `manifest.json` が指定する依存Modと、CurseForgeカタログから選んだ単体Modです。

## Modpack

1. Wizardまたは既存サーバーのMod Managerで、導入するModpackとリリースを選択します。Wizardからは作成後の通知でMod Managerの導入状況を開けます。
2. 必須Modが足りない場合は、Mod Managerに対象リリースと不足一覧が表示されます。行には取得できたMod名、プロジェクトID、ファイルID、配布ファイル名、必須・任意、サイズ、ハッシュ、取得できない理由が表示されます。配布元がプロジェクトのURLを返した場合は、指定のファイルページを開けます。
3. 該当JARを公式CurseForgeからダウンロードします。Wizardで作成したサーバーは、Mod Managerの不足一覧で対象の **JARをアップロード** を押します。既存サーバーでは、Pelicanの **Files** でサーバーのルートに `.mod-manager-curseforge` を作成し、表示された `プロジェクトID-ファイルID.jar` の名前でアップロードします。
4. Mod Managerのタブに戻り、**ファイルを検証して再開**を押します。ジョブがアップロード済みのJARを検証します。必須分がすべて揃うと同じModpackを導入し、任意で取得できないModはスキップ件数に含めます。

待機中は同じリリースとアーカイブのSHA-256を保持します。保留は共有キャッシュに最長7日間保存されます。導入の取り消しや正常完了後も、アップロード済みのJARはFilesに残ります。ジョブの後半で失敗した場合は同じリリースを再試行できます。アップロード済みのJARは再利用し、導入前に再検証します。正常に導入できる依存Modのダウンロードは必須ファイルの事前検証後に始まります。

メタデータの403、404、429や通信失敗は個別に表示されます。ハッシュが得られない必須ファイルは、ファイル名やサイズだけで同一性を判断できないため保留します。自動取得URLがあるファイルでWingsが転送中に失敗した場合は、元のファイルに戻してジョブを失敗として扱います。

## 単体Mod

1. CurseForgeカタログの **バージョン選択** で目的のファイルを選び、**導入**を押します。配布制限がある場合は、そのプロジェクトID・ファイルIDに紐づく通知が表示されます。
2. 公式サイトからJARをダウンロードし、通知に表示された名前で `.mod-manager-curseforge` にアップロードします。
3. **バージョン選択**の同じファイルで再度 **導入**を押します。検証を通過したJARは通常の導入・更新と同じトランザクションで配置され、導入済み情報が保存されます。

単体・Modpackともに、Filesでのアップロード権限と、Mod Managerで該当操作を実行する権限が必要です。手動ファイルはCore APIが公開するSHA-1・MD5のうち利用できるものをすべて照合し、提供されたサイズ、JAR構造とパスを検査します。照合済みのローカル一時コピーをWingsにストリーミングし、JARやアーカイブ内のスクリプトは実行しません。検証上限は単体128 MiB、Modpack全体で512 MiBです。

## 検証と参照実装

- RLCraft `285109/4612979` の公開manifestでIce and Fire `264231/2693547` とJEI `238222/3043174` を確認しました。該当する公開配布ページはHTTP 200でした。キーのないCore APIへの照会はHTTP 403でした。認証付きCore API応答と、これらの実ファイルの配布制限の有無は未検証です。隔離試験のAPIはこれらのIDに合成データを返しています。[Core API仕様](https://docs.curseforge.com/rest-api/#tocS_File) · [RLCraftの選択ファイル](https://www.curseforge.com/minecraft/modpacks/rlcraft/files/4612979) · [Ice and Fire](https://www.curseforge.com/minecraft/mc-mods/ice-and-fire-dragons/files/2693547) · [JEI](https://www.curseforge.com/minecraft/mc-mods/jei/files/3043174)
- `cf-mc-server`（MIT）はmanifestの固定IDを個別照会し、URLがないファイルをまとめて公式ファイルページへ案内します。続行時の確認は `mods` 内のファイルの存在です。今回のPelican実装では、ロード対象外の場所で供給を待ち、ハッシュ付きのJAR検証を導入条件にしています。[実装](https://github.com/CurseForgeCommunity/cf-mc-server/blob/8c259b09c3f011694bd7664136e080fc97007ae3/cf-mc-server/Dependencies.cs#L72-L125) · [ライセンス](https://github.com/CurseForgeCommunity/cf-mc-server/blob/8c259b09c3f011694bd7664136e080fc97007ae3/LICENSE)
- `itzg/mc-image-helper`（MIT）は手動取得が必要なファイルを書き出し、必要ファイルが足りない場合は処理を停止します。今回のPelican実装では、不足一覧の提示と停止に加え、同じリリースで再開するための状態を保持します。両実装は設計の参照に使用しました。[不足一覧の処理](https://github.com/itzg/mc-image-helper/blob/7a0099eb0f976c9ec0519603d0d4b00f0f9266f7/src/main/java/me/itzg/helpers/curseforge/InstallCurseForgeCommand.java#L261-L295) · [ライセンス](https://github.com/itzg/mc-image-helper/blob/7a0099eb0f976c9ec0519603d0d4b00f0f9266f7/LICENSE.txt)

### 隔離Panel＋Wingsでの操作結果

| 試験 | 実行結果 |
| --- | --- |
| 通常ZIPの依存Mod2件が配布制限 | 本物のPanel/Wings v1.0.0-rc.2で待機画面に公式リンクと専用フォルダーを表示。ChromiumからFilesにアップロードし、再開ボタンでバックグラウンドジョブを投入 |
| 同サイズで内容が異なるJAR | `2693547` が `hash_mismatch` となり、検証済みの `3043174` は不足一覧から外れた。配置は保留 |
| 正しいJARで再開 | リリース `4612979` のジョブが `completed`、JAR 2件を配置。既存の設定とワールドを保持。管理情報に同一のリリースIDを記録し、Chromiumで完了通知を確認 |
| 単体Mod | `238222/3043174` を選択。公式ファイルリンクとFilesへの案内を表示。Chromiumでアップロードし、バージョン選択から導入。Wings上のJARと導入済み情報のファイルIDを照合 |

CurseForge側は公開IDを使ったAPI・アーカイブのモックです。テスト用の通常ZIPには合成JARと隔離済みのFabric環境を使いました。Minecraftの起動や、RLCraft本体の動作はこの検証の対象外です。ジョブ投入とFilesのHTTP成功、完了通知、配置ファイルのバイト照合を別々に確認しました。

回帰テストは、全件取得可能、単件・複数件の必須不足、任意の不足、不正サイズ・ハッシュ・JAR、重複配置の防止、途中失敗時の復旧、別アーカイブによる再開の拒否、単体ModとAPIエラーの区別をカバーします。[Modpack処理](../tests/Unit/Services/ModpackInstallerTest.php) · [再開状態](../tests/Unit/Services/ModpackDispatchTest.php) · [単体Mod](../tests/Unit/Services/InstalledArchiveTransactionTest.php) · [APIエラー](../tests/Unit/Sources/CurseForgeSourceResourcePackDistributionTest.php)
