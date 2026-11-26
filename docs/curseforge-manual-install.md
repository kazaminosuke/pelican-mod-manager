# CurseForgeの配布制限付き単体Modを導入する

1. CurseForgeカタログの **バージョン選択** で目的のファイルを選び、**導入**を押します。配布制限がある場合は、そのプロジェクトID・ファイルIDに紐づく通知が表示されます。
2. 公式サイトからJARをダウンロードし、通知に表示された名前で `.mod-manager-curseforge` にアップロードします。
3. **バージョン選択**の同じファイルで再度 **導入**を押します。検証を通過したJARは通常の導入・更新と同じトランザクションで配置され、導入済み情報が保存されます。

Filesでのアップロード権限と、Mod Managerで該当操作を実行する権限が必要です。Core APIが公開するSHA-1・MD5のうち利用できるものをすべて照合し、提供されたサイズ、JAR構造とパスを検査します。検証上限は128 MiBです。検証した一時コピーをWingsにストリーミングし、JARやアーカイブ内のスクリプトは実行しません。アップロードしたファイルはFilesに残るため、導入後に削除できます。

APIの認証・アクセス・通信エラーと配布制限は別の理由として表示されます。手動導入には、選択したファイルのハッシュが必要です。

## 検証

分離元のPR #2では、合成データを返すCurseForge APIと隔離したPanel・Wingsで以下を確認しました。認証付きのCurseForge Core APIは未検証です。 [PR #2の検証記録](https://github.com/kazaminosuke/pelican-mod-manager/blob/58b73c3900ea4babeb509f8d9e7c4f6360929b61/docs/curseforge-manual-install.md)

| 試験 | 実行結果 |
| --- | --- |
| 単体Mod | `238222/3043174` を選択。公式ファイルリンクとFilesへの案内を表示。Chromiumでアップロードし、バージョン選択から導入。Wings上のJARと導入済み情報のファイルIDを照合 |

[単体Modのトランザクション](../tests/Unit/Services/InstalledArchiveTransactionTest.php) · [APIエラーの区別](../tests/Unit/Sources/CurseForgeSourceResourcePackDistributionTest.php)
