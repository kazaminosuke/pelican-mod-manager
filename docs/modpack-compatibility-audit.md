# Modpack互換性監査

対象は [PR #2](https://github.com/kazaminosuke/pelican-mod-manager/pull/2) の新規サーバー作成と導入です。公開配布物の参照と起動にはCodex Cloudのみを使用しました。起動試験はポートを公開しない隔離コンテナで実行しました。

## 検証範囲

- **モック**はAPI、LivewireのWizard、Panel作成サービスへの引き渡し、永続化ジョブ、失敗とロールバックの回帰試験です。
- **公開情報**は実API応答または配布元の公開リリースです。CurseForge Core APIはキーがないため、公開ページとCDNでの確認とCore APIを使うモック試験を区別しています。
- **実ZIP解析**には中央ディレクトリと指定エントリのみを取得した試験を含みます。全件の読み出しは配置欄で区別しています。
- **配置**で「PHP」と記載した行は、プラグインの `ModpackInstaller` と隔離ディレクトリに書くWingsファイル操作アダプターを使用しました。
- **起動**はMinecraftの `Done`、`stop` による停止、終了を確認した場合に成功としています。全行で実Pelican Panel＋Wingsの統合動作は未検証です。

| 配布元・Modpack | プロジェクト・リリース・Server Pack ID | Minecraft・ローダー・Java | 公開情報・実ZIP解析 | 配置・起動 |
| --- | --- | --- | --- | --- |
| CurseForge・All the Mods 10 8.6 | `925200` / `9620701` / `9620727` | 1.21.1・NeoForge 21.1.266・21想定 | 公開Additional Filesと通常ZIPのmanifest、専用ZIPの目録。2,620項目から2,219ファイルを選別。`local/kubejs/dev.json` を含む | 専用ZIPの全体取得・配置・起動は未検証。モックでNeoForge Egg変数と専用ZIP IDの引き渡しを確認 |
| CurseForge・RLCraft 2.9.3 | `4612979` / `4612990` | 1.12.2・Forge 14.23.5.2860・8実測 | 公開Additional Files、通常ZIPのmanifest、約334 MBの専用ZIP全体とCRC | PHPで3,559件配置。隔離起動と停止成功。Forge Egg相当のインストーラーはMojangに接続できず、起動に必要な同一ビルド・依存をハッシュ照合して別途配置 |
| CurseForge・Better MC Forge BMC4 v63 HF | `9554990` / `9555002` | 1.20.1・Forge 47.4.20・17想定 | 公開Additional Filesと通常ZIPのmanifest。専用ZIPの取得はHTTP 403 | 専用ZIP解析・配置・起動は未検証 |
| CurseForge・Better MC Fabric BMC2 v40 | `7517035` / `7522918` | 1.20.1・Fabric 0.18.4・17最低／パック推奨21 | 公開Additional Files、約601 MBの専用ZIPの目録から1,603項目中1,338ファイルを選別し、`variables.txt` からビルドを読み取り。通常ZIPの取得はHTTP 403 | 専用ZIP全体の配置・起動は未検証 |
| CurseForge・Better MC NeoForge BMC5 v54 | `9359712` | 1.21.1・NeoForge 21.1.234・21想定 | 公開一覧は追加Server Pack 0件。通常ZIPのmanifestを解析 | 実依存Modの一括取得・配置・起動は未検証 |
| Modrinth・Fabric Server Optimization | `YkG1DyHE` / `vtGmZsbn` | 1.19.3・Fabric 0.14.12・17実測 | 実APIと実 `.mrpack` を解析 | PHPで実Modを取得・配置。隔離起動と停止成功 |
| Modrinth・Server Optimization | `2wkV8mHp` / `egRNRK9l` | 1.20.1・Fabric 0.14.22・17実測 | 実APIと実 `.mrpack` を解析 | PHPで18件配置。起動失敗。固定版の依存に矛盾があり、TT20はFabric 0.15.11以上を要求します。ローダーは変更していません |
| Modrinth・Fabulously Optimized 6.5.0 | `1KVo5zza` / `N276l2ON` | 1.21.1・Fabric 0.19.3・21想定 | 公開プロジェクトは `server_side=unsupported`。実ZIP内では50件全て `env.server=required` | 初回監査時はWizardで拒否。現在は警告付きのアーカイブ導入を選択可能。実パックの配置・起動は未検証 |
| FTB・FTB Vanilla+ 1.1.1 | `18` / `12169` | 1.7.10・Forge 10.13.4.1448・8想定 | 公開メタデータと現行FTB Server Eggの固定ID変数を照合 | 実インストーラーを実行。APIへの接続タイムアウト後もEggの `Finished installing` が表示されることを確認。配置・起動は失敗 |
| Technic・Tekkit 2 | `tekkit-2` / `v1.2.6` | 1.12.2・Forge 14.23.5.2860・8実測 | 実APIの専用ZIP URLを公式Eggと照合 | 公式Eggの導入スクリプトで取得・配置。隔離起動と停止成功 |
| Technic・Tekkit Legends | `tekkit-legends` / `1.1.1` | 1.7.10・Forge 10.13.4.1492・8実測 | 実APIの専用ZIP URLと公式Eggの `MODPACK_VERSION` を照合。APIの `minecraft=1.4.7` は実行ログと異なる | 専用ZIP105,513,466バイトのCRC確認、配置、公式Eggの `TekkitLegends.jar` で隔離起動と停止成功 |
| ATLauncher・SevTech: Ages | `SevTechAges` / `3.2.3` | 1.12.2・Forge 14.23.5.2860・8想定 | 実APIと配布メタデータを取得。通常のバージョンAPIとCDNの `Configs.json` の役割を分離して確認 | PRの検索とリリース選択は実装済み・モック検証済み。導入経路は未対応 |

公開データへの参照先です。[ATM10 Additional Files](https://www.curseforge.com/minecraft/modpacks/all-the-mods-10/files/9620701/additional-files) · [RLCraft Additional Files](https://www.curseforge.com/minecraft/modpacks/rlcraft/files/4612979/additional-files) · [BMC4 Additional Files](https://www.curseforge.com/minecraft/modpacks/better-mc-forge-bmc4/files/9554990/additional-files) · [BMC2 Additional Files](https://www.curseforge.com/minecraft/modpacks/better-mc-fabric-bmc2/files/7517035/additional-files) · [BMC5 v54](https://www.curseforge.com/minecraft/modpacks/better-mc-neoforge-bmc5/files/9359712) · [Fabric Server Optimization](https://api.modrinth.com/v2/version/vtGmZsbn) · [Server Optimization](https://api.modrinth.com/v2/version/egRNRK9l) · [Fabulously Optimized](https://api.modrinth.com/v2/project/1KVo5zza) · [FTB](https://api.feed-the-beast.com/v1/modpacks/public/modpack/18/12169) · [Tekkit Legends](https://api.technicpack.net/modpack/tekkit-legends?build=recommended) · [ATLauncher API](https://api.atlauncher.com/v1/pack/SevTechAges/3.2.3) · [ATLauncher Configs.json](https://download.nodecdn.net/containers/atl/packs/SevTechAges/versions/3.2.3/Configs.json)

## 公式Eggと既存データ

- **ForgeとNeoForge**の変数を公式定義と照合しました。Forgeの `FORGE_VERSION` はMavenの完全表記を使い、NeoForgeの `NEOFORGE_VERSION` は `21.1.266` のようなビルド単体です。JavaはEggのイメージ一覧からMinecraftの要件に合うものを選びます。Javaのパック固有推奨値までは自動適用しません。[Forge](https://github.com/pelican-eggs/minecraft/blob/c6f2262eb351054d1be44d3f6a3a605ae163d682/java/forge/egg-forge-minecraft.yaml#L53-L77) · [NeoForge](https://github.com/pelican-eggs/minecraft/blob/c6f2262eb351054d1be44d3f6a3a605ae163d682/java/neoforge/egg-neo-forge.json#L52-L75)
- **Fabric**の固定ビルドは `LOADER_VERSION` です。**Quilt**の現行標準Eggには `QUILT_VERSION` がありません。対応するCurseForge Generic Eggが使用可能なケースでは、そのスクリプトがmanifestから固定ビルドを読みます。標準Quilt Eggを使うアーカイブ方式での正確なビルド固定は未対応です。[Fabric](https://github.com/pelican-eggs/minecraft/blob/c6f2262eb351054d1be44d3f6a3a605ae163d682/java/fabric/egg-fabric.yaml) · [Quilt](https://github.com/pelican-eggs/minecraft/blob/c6f2262eb351054d1be44d3f6a3a605ae163d682/java/quilt/egg-quilt.yaml#L33-L95) · [CurseForge Generic](https://github.com/pelican-eggs/minecraft/blob/c6f2262eb351054d1be44d3f6a3a605ae163d682/java/curseforge/egg-curse-forge-generic.yaml#L480-L512)
- **互換性不明**のCurseForge通常ZIPは、適合するEgg、確認チェック、アーカイブ実行には非同期ジョブを必要とします。ローダー不明の場合はロード可能なMod用Eggを管理者が選びます。ビルド不明の場合は選択Eggが公開する起動変数に入力できます。新規作成では、Modrinthが非対応と表示したパックに警告を出し、同意と適合するローダーEggがあればアーカイブ方式を試せます。選択後に変更されたZIP、アクセス拒否や危険なZIPは導入を中断します。
- **ZIP方式**では専用ZIPのスクリプト、JVM引数、`server.properties`、ワールド等を配置対象から除外しています。ServerPackCreatorの `variables.txt` はMinecraftとローダーのリテラル値の読取りに限ります。RLCraftの `resources/` と `structures/` は保持します。名前だけでクライアント専用と推定しません。Modrinthの `env.server=unsupported` と `client-overrides` を除外します。CurseForgeの通常manifestには依存Modのサーバー対応を完全に判定できる情報がありません。
- **既存サーバー**ではダウンロードを一時名で保持し、アーカイブ方式の配置失敗時は当該ジョブが移動したファイルを巻き戻します。専用ZIPでは既存ファイルをスキップします。既存サーバーを削除してから導入する方式、ワールド変換、Eggスクリプトの内部処理を巻き戻す機能はありません。サーバー作成時のアーカイブをジョブに固定し、自動起動はファイル配置の前に実行されないようにしています。

## OSS実装比較

各リンクは調査時のコミットに固定しています。インストールコード・スクリプトや配布物のコードは転用していません。ライセンス表記は実際の `LICENSE` を確認しました。

| 実装・ライセンス | コードから確認した方式とPR #2への判断 |
| --- | --- |
| **JoanFo1456/resources**（GPL-3.0） | CurseForgeは `serverPackFileId` を優先し、Wingsの非同期取得をサイズ監視してからルート展開し、失敗すると通常manifestへ切り替えます。専用ZIP側の `gameVersions` はローダー名までです。通常ZIPとModrinthの依存には固定ビルドがありますが、ForgeとNeoForgeはインストーラーJARの配置で止まり、Modrinthはサーバー側 `env` の除外と `server-overrides` の優先処理がありません。ジョブは既存Eggを最初のタグ一致に変更し、起動変数を再設定しません。**参考**は選択済みIDをジョブに運ぶ設計。**改善不要**はPR側のWings完了待ち、ファイル選別、Eggの事前照合。**採用対象外**は既存ディレクトリの先行削除、部分展開後のフォールバック、無認証の代替取得、最初のタグでのEgg変更です。[LICENSE](https://github.com/JoanFo1456/resources/blob/4be2d545812b6704f9ca42d607daf034a51f43a1/LICENSE) · [CurseForgeの専用ZIPと展開](https://github.com/JoanFo1456/resources/blob/4be2d545812b6704f9ca42d607daf034a51f43a1/src/Services/ModpackInstallers/CurseForgeModpackInstaller.php#L21-L157) · [通常ZIPとローダー](https://github.com/JoanFo1456/resources/blob/4be2d545812b6704f9ca42d607daf034a51f43a1/src/Services/ModpackInstallers/CurseForgeModpackInstaller.php#L431-L464) · [Modrinth](https://github.com/JoanFo1456/resources/blob/4be2d545812b6704f9ca42d607daf034a51f43a1/src/Services/ModpackInstallers/ModrinthModpackInstaller.php#L45-L151) · [ジョブとEgg](https://github.com/JoanFo1456/resources/blob/4be2d545812b6704f9ca42d607daf034a51f43a1/src/Jobs/InstallModpackJob.php#L25-L111) |
| **cosmii02/pelican-modpackmanager**（MIT） | CurseForgeは `serverPackFileId`、`isServerPack`、`alternateFileId`、`parentProjectFileId` を利用し、ファイル一覧とAdditional FilesのHTMLを追加検索します。ATM10固有のIDを必要としませんが、名前に `server` を含む候補や、APIにURLがないときのCDN URL再構成を認めます。Forgeの完全表記、manifest・ファイル名・ランチャー情報からビルドを読み、同じサーバーのEgg変更・変数更新・再導入を実行します。NeoForgeには条件付きの最新ビルド推定があります。ATLauncherは固定 `Configs.json` と `Configs.zip` からファイルを組み立てますが、調べたコードでは `browser` をスキップし、SevTechに実在するルート展開型エントリの処理が不足しています。バックアップ完了待ちと前回管理ファイルの削除、失敗の記録、Wingsのリトライがあります。導入プランを決めるより先に削除段階があり、例外時の自動復元は確認できません。**参考**は配布元が指定した固定ビルド、再試行、起動ファイルの事後確認。**改善不要**はPRでの親子ID照合とインストール前のEgg確認。**採用対象外**はHTMLからの名前による選択、任意 `direct` URLの無条件取得、ZIP内スクリプトの実行、可変ブランチからのEgg自動取得・共有Quilt Eggの変更、既存ファイルの先行削除です。[LICENSE](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/LICENSE) · [CurseForgeの親子関係](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/CurseForgeService.php#L531-L575) · [HTML検索](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/CurseForgeService.php#L799-L868) · [ATLauncherサービス](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/ATLauncherService.php#L159-L216) · [ATLauncher配置](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/ModpackInstallService.php#L3063-L3129) · [Egg・起動変数](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/ModpackInstallService.php#L1863-L2116) · [Quilt変更・Egg取得](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/ModpackInstallService.php#L2665-L2807) · [バックアップ](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/ModpackInstallService.php#L650-L729) · [失敗の処理順](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/ModpackInstallService.php#L63-L150) · [ファイル取得の検証と再試行](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/ModpackInstallService.php#L3190-L3448) |
| **itzg/docker-minecraft-server**（Apache-2.0） | CurseForge・Modrinthは `mc-image-helper` へ固定リリース、除外／追加リスト、ローダー設定を渡します。選択されたCurseForge通常ZIPのmanifestで依存を組み立て、ヘルパーの一覧検索ではサーバーパックを除外しています。Modrinthは共有とサーバー用overrideを展開します。配布制限は不足ファイルのリストとして返し、コマンド失敗ではコンテナ起動を中断します。CurseForgeとModrinth/NeoForgeの設定専用テストを確認しました。**参考**は取得不能ファイルの明示と例外時の起動中断。**改善不要**はPRの親子IDによる専用ZIPの優先です。[LICENSE](https://github.com/itzg/docker-minecraft-server/blob/0c041505810b07f5d54a82e8e403e025f2d185a9/LICENSE) · [CurseForge](https://github.com/itzg/docker-minecraft-server/blob/0c041505810b07f5d54a82e8e403e025f2d185a9/scripts/start-deployAutoCF#L20-L73) · [Modrinth](https://github.com/itzg/docker-minecraft-server/blob/0c041505810b07f5d54a82e8e403e025f2d185a9/scripts/start-deployModrinth#L26-L79) · [ファイル選択](https://github.com/itzg/mc-image-helper/blob/7a0099eb0f976c9ec0519603d0d4b00f0f9266f7/src/main/java/me/itzg/helpers/curseforge/CurseForgeApiClient.java#L192-L215) · [ダウンロード制限](https://github.com/itzg/mc-image-helper/blob/7a0099eb0f976c9ec0519603d0d4b00f0f9266f7/src/main/java/me/itzg/helpers/curseforge/InstallCurseForgeCommand.java#L261-L295) · [NeoForgeテスト](https://github.com/itzg/docker-minecraft-server/blob/0c041505810b07f5d54a82e8e403e025f2d185a9/tests/setuponlytests/modrinth-neoforge/verify.sh) |
| **MaxBal/Modpack-Installer**（MIT） | Pterodactylの既存サーバー用API。`serverPackFileId` があれば専用ZIP、通常ZIPならmanifestから展開対象を作ります。配布不可は変更する前に手動取得リンクとして返します。排他・進捗・キャンセルと新規ファイルの掃除がありますが、既存ファイルの削除やルート展開の巻き戻しは限定的です。リポジトリ内に自動テストは確認できませんでした。**参考**は必要ファイルの事前検証。**採用対象外**はURLリダイレクトの追跡と、既存ファイル削除を組み合わせた展開方式です。[LICENSE](https://github.com/MaxBal/Modpack-Installer/blob/989811e130952e28aa85787835dbae3be9507762/LICENSE) · [専用ZIPとmanifest](https://github.com/MaxBal/Modpack-Installer/blob/989811e130952e28aa85787835dbae3be9507762/app/Services/Modpacks/ModpackProviderService.php#L276-L317) · [ModrinthとZIP処理](https://github.com/MaxBal/Modpack-Installer/blob/989811e130952e28aa85787835dbae3be9507762/app/Services/Modpacks/ModpackProviderService.php#L444-L574) · [導入・変更順](https://github.com/MaxBal/Modpack-Installer/blob/989811e130952e28aa85787835dbae3be9507762/app/Http/Controllers/Api/Client/Servers/ModpackController.php#L114-L249) · [キャンセルと排他](https://github.com/MaxBal/Modpack-Installer/blob/989811e130952e28aa85787835dbae3be9507762/app/Http/Controllers/Api/Client/Servers/ModpackController.php#L611-L666) |
| **nothub/mrpack-install**（MIT） | Modrinth向け。固定依存からローダーを用意し、パック状態にSHA-512とファイルの所有情報を持たせます。更新計画で変更済みのローカルファイルを判別し、失敗時は状態のアーカイブハッシュを無効化します。サーバーoverrideの優先とクライアントファイル除外、ハッシュ、Quiltのランチャー確認をテストしています。**参考**は依存ハッシュの照合とユーザー変更を残す更新計画。PRのWings経由導入はサイズとJAR署名先頭を検証し、依存ハッシュの全件照合は未対応です。[LICENSE](https://github.com/nothub/mrpack-install/blob/90d9ef2d092f94be763cee432839a9dee5fa2610/LICENSE.txt) · [状態と失敗処理](https://github.com/nothub/mrpack-install/blob/90d9ef2d092f94be763cee432839a9dee5fa2610/update/packstate/packstate.go#L475-L550) · [更新計画のテスト](https://github.com/nothub/mrpack-install/blob/90d9ef2d092f94be763cee432839a9dee5fa2610/update/plan_test.go) · [overrideと環境のテスト](https://github.com/nothub/mrpack-install/blob/90d9ef2d092f94be763cee432839a9dee5fa2610/e2e/install_test.go#L204-L287) · [Quilt検証](https://github.com/nothub/mrpack-install/blob/90d9ef2d092f94be763cee432839a9dee5fa2610/server/quilt_test.go) |
| **Patbox/mrpack4server**（MIT） | `.mrpack` からMinecraftとForge・NeoForge・Fabric・Quiltのビルドを確定し、ローダー別のインストーラーを使います。既存ハッシュと上書き除外を照合し、ユーザー変更を保護します。`env.server` 未指定を必須として扱い、許可ドメインを使います。リポジトリ内に自動テストは確認できませんでした。**参考**は正規の固定ビルドと検証付きのランチャー生成。Pelican側ではEggによるランタイム管理を維持します。[LICENSE](https://github.com/Patbox/mrpack4server/blob/eb631a8014ca2a1d78cec36cf30e1a7adcf2c677/LICENSE) · [override・ローカル保護](https://github.com/Patbox/mrpack4server/blob/eb631a8014ca2a1d78cec36cf30e1a7adcf2c677/src/main/java/eu/pb4/mrpackserver/installer/MrPackInstaller.java#L51-L127) · [ローダー取得](https://github.com/Patbox/mrpack4server/blob/eb631a8014ca2a1d78cec36cf30e1a7adcf2c677/src/main/java/eu/pb4/mrpackserver/installer/MrPackInstaller.java#L163-L228) · [依存と環境](https://github.com/Patbox/mrpack4server/blob/eb631a8014ca2a1d78cec36cf30e1a7adcf2c677/src/main/java/eu/pb4/mrpackserver/installer/MrPackInstaller.java#L247-L302) |
| **CurseForgeCommunity/cf-mc-server**（MIT） | 認証付きAPIで指定プロジェクト／ファイルの通常ZIPを取得し、manifestの `primary` Forge・Fabricを解決して、CLIでサーバーを準備します。Additional Filesによる切り替え、NeoForge・Quiltの分岐、PelicanのEgg統合と自動テストは確認できませんでした。**参考**はmanifestの固定IDを使う方式。**改善不要**はPRの専用ZIPの親子検証。**採用対象外**はCLIの展開方式をそのまま管理パネルに組み込むことです。[LICENSE](https://github.com/CurseForgeCommunity/cf-mc-server/blob/8c259b09c3f011694bd7664136e080fc97007ae3/LICENSE) · [認証・ZIPとmanifest](https://github.com/CurseForgeCommunity/cf-mc-server/blob/8c259b09c3f011694bd7664136e080fc97007ae3/cf-mc-server/Program.cs#L135-L211) · [ローダー](https://github.com/CurseForgeCommunity/cf-mc-server/blob/8c259b09c3f011694bd7664136e080fc97007ae3/cf-mc-server/Program.cs#L219-L262) |
| **astrooom/Minecraft-Serverpack-Installer**（ライセンス未確認） | プロバイダごとのURL処理でCurseForgeの `serverPackFileId` と通常版を扱い、通常ZIPのmanifestや `.mrpack` も読みます。展開後は候補スクリプトや実行形式を `shell=True` で起動する分岐があります。追跡による復元・Pelican統合・自動テストは確認できませんでした。**参考**は専用ZIPに複数の起動構造があること。**採用対象外**はURLの推定、一般展開、配布アーカイブ内のスクリプト実行です。リポジトリ内にライセンス許諾を確認できなかったため、コードの利用は対象外です。[CurseForgeファイル選択](https://github.com/astrooom/Minecraft-Serverpack-Installer/blob/8646e4d8035ab0b99470cd1ffc618fdaf663cbd6/get_modpack_info.py#L37-L162) · [manifest](https://github.com/astrooom/Minecraft-Serverpack-Installer/blob/8646e4d8035ab0b99470cd1ffc618fdaf663cbd6/get_forge_or_fabric_version.py) · [ZIP展開](https://github.com/astrooom/Minecraft-Serverpack-Installer/blob/8646e4d8035ab0b99470cd1ffc618fdaf663cbd6/unzip_modpack.py#L5-L16) · [実行処理](https://github.com/astrooom/Minecraft-Serverpack-Installer/blob/8646e4d8035ab0b99470cd1ffc618fdaf663cbd6/run.py#L387-L474) |

### PR #2 の比較追補

[引き継ぎ調査](handoff/comparative-audit-cosmii02.md)を起点に、比較先の v1.7.3（`ddc2e364`）と PR #2 のソースで主要な差分を照合しました。

- **互換性**　CurseForge の専用 ZIP 検索に `alternateFileId` を追加しました。同一プロジェクトの `isServerPack` が真で、親 ID が選択したリリースと矛盾しない ZIP に限ります。通常 ZIP へのフォールバックと公式ダウンロード URL の確認は既存の処理を使います。代替 ID の参照は cosmii02 の設計を参考にしました。[比較先の CurseForge 検索](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/CurseForgeService.php#L525-L568)
- **処理効率・軽量性**　FTB の 0 バイトファイルを直接配置する cosmii02 の設計を参考に、PR #2 では既存のストリーム書き込みで一時配置し、配布元のハッシュとサイズを照合します。比較先の小ファイルを ZIP にまとめる方式は Wings への要求数を減らせます。PR #2 に導入するには Panel の一時容量・ノードのアップロード上限・展開後の検証・ロールバックをまとめて設計する必要があります。[比較先の FTB 配置](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/ModpackInstallService.php#L2961-L3049) · [ZIP 分割転送](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/ModpackInstallService.php#L3441-L3530)
- **安定性**　比較先の Wings リトライは送信前と判断した接続エラーに絞っています。PR #2 で導入する場合は、再送でファイル変更が二重適用されないことを操作ごとに確認する必要があります。比較先の Modrinth は共通 `overrides/` を取り込み、PR #2 は `server-overrides/` を優先します。[比較先の Wings リトライ](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Repositories/RetryingDaemonFileRepository.php#L12-L32) · [比較先の overrides 処理](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/ModpackInstallService.php#L3146-L3160)

上記の代替 ID 検索と空ファイルの直接配置は、cosmii02/pelican-modpackmanager（MIT、Copyright (c) 2026 cosmii02）の設計を参考に、このプラグインの既存処理を使って実装しました。[参照元のライセンス](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/LICENSE)

## Mod Manager による新規サーバーの導入

Wizard で Minecraft、ローダービルド、Java イメージを固定します。Egg は実行時設定に使い、起動コマンドは Mod Manager が生成します。Wings がサーバーを作成する前に導入計画を記録する Pelican の `beforeDaemon` フックが必要です。Panel v1.0.0-rc.4 にはこのフックがあります。そのフックのない Panel ではアーカイブ方式を選べません。通常作成と Technic など明示的に Egg へ委任する方式は標準の作成処理に渡します。[Wizard](../src/Filament/Admin/ModpackCreationStep.php) · [Panel の作成フック](https://github.com/pelican-dev/panel/blob/v1.0.0-rc.4/app/Services/Servers/ServerCreationService.php#L47-L57)

Modpack 専用サーバーには `skip_scripts=true` を保存します。認証済みの Wings が導入対象のサーバー設定を取得したときだけ、一時インストールコンテナの実行を許可します。インストール API はそのサーバーに対して Mod Manager のスクリプトを返します。ローダーの結果は Mod Manager が記録し、パックの検証・配置まで成功した時点でファイル台帳と Panel の完了状態をまとめて保存します。運用時は導入が終わるまでプラグインを有効に保ちます。追加の常駐プロセスは不要です。[Wings のインストール処理](https://github.com/pelican-dev/wings/blob/81152690d2da31b8004f257e0df725e288510a0b/server/install.go#L54-L162) · [状態管理](../src/Services/ModpackProvisioning.php)

| ローダー | インストーラー | 主な確認対象 |
| --- | --- | --- |
| Fabric | 公式 Fabric Installer 1.1.2、SHA-256 固定 | 起動 JAR、Loader、Intermediary、Minecraft 本体 |
| Forge | 固定ビルドの Forge Maven 配布物 | 旧版は起動 JAR、1.17 以降は `unix_args.txt` と参照 JAR |
| NeoForge | 固定ビルドの NeoForge Maven 配布物 | `unix_args.txt` と参照 JAR。1.20.1 は `net/neoforged/forge` 系 |
| Quilt | 公式 Quilt Installer 0.15.1、SHA-256 固定 | 起動 JAR と固定ビルド。公式 Egg にロード用の版変数がなくても導入可能 |

Mod Manager は認証済みの完了通知を確認してから既存ジョブで Modrinth・CurseForge・FTB のパックを配置します。失敗時はサーバーを失敗状態にし、旧方式で作成時に予約されたジョブは更新後の作り直しを案内します。既存サーバーの差分更新は対応する検証済みランタイムを使います。異なるローダー・ビルドへ既存サーバーを切り替えるには、ランタイムの交換もバックアップと復旧の対象にする必要があります。[固定版の選択](../src/Services/ModpackLoaderInstaller.php) · [配置と検証](../src/Jobs/InstallModpack.php) · [既存サーバーの選択条件](../src/Services/ModpackInstallCoordinator.php)

比較先の pelican-modpackmanager は、一般のパックではファイルを配置した後に Egg の再インストールを呼び出し、終了を待ってローダーを確認します。同梱ランチャー付きの一部の CurseForge 専用パックでは初回起動時の導入を許容し、固定 Quilt 版への対応では共有 Egg を更新します。[比較先の配置と再インストール](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/ModpackInstallService.php#L1554-L1858) · [比較先の Quilt 設定](https://github.com/cosmii02/pelican-modpackmanager/blob/ddc2e364427c703e565b59f9533465e6b1090543/src/Services/ModpackInstallService.php#L2665-L2730)

### 実機ログ

Panel のディレクトリで、`42` を対象サーバーの数値 ID に置き換えて実行します。キューのペイロードと認証情報を表示せず、サーバー状態・予約状態・ジョブの結果コードを確認できます。

```sh
php artisan schedule:list | grep -F 'mod-manager:process-jobs'
php artisan tinker --execute='
    $server = App\Models\Server::findOrFail(42);
    dump($server->only(["uuid", "status", "skip_scripts", "installed_at"]));
    dump(Illuminate\Support\Facades\DB::table("mod_manager_background_jobs")
        ->where("type", "modpack_install")->where("payload->server_id", $server->id)
        ->get(["id", "available_at", "reserved_at", "attempts"]));
    $state = app(Kazaminosuke\ModManager\Services\InstalledOperationManager::class)
        ->state($server, Kazaminosuke\ModManager\Enums\ProjectType::Mod, "modpack_install");
    dump($state?->status, $state?->error);
'
```

Wings ノードでは `<UUID>` を上で取得した UUID に置き換えます。標準ログディレクトリを変更している場合は Wings の `system.log_directory` に合わせます。Panel の管理画面でも対象サーバーのインストールログを表示できます。ログを共有する場合は認証情報を伏せてください。

```sh
server_uuid='<UUID>'
sudo tail -n 150 "/var/log/pelican/install/${server_uuid}.log"
sudo journalctl -u wings -n 300 --no-pager | grep -F "$server_uuid"
```

## ATLauncherの導入経路

公式ランチャーの実装とSevTech: Ages `3.2.3` の実データを照合しました。CDNの `Configs.json` にはForge `14.23.5.2860`、サーバー対象の配布エントリ260件（`server` 259件・`direct` 1件）、`Configs.zip` のサイズ・SHA-1があります。実サーバーには `type=extract`・`extractTo=root` の専用設定アーカイブも必要です。実装する場合は、配布型ごとの明示的な許可ポリシー、制限とハッシュ検証を伴うステージング、既存世界・設定との衝突制御、必須の手動取得がある場合の停止が揃って初めて導入済みと扱えます。現行PRは検索・版選択を提供し、導入は無効にしています。[公式のメタデータURL](https://github.com/ATLauncher/ATLauncher/blob/906066e8cf7c5a4770ec67545ee4303a1c266015/src/main/java/com/atlauncher/data/Pack.java#L294-L305) · [公式のブラウザ取得分岐](https://github.com/ATLauncher/ATLauncher/blob/906066e8cf7c5a4770ec67545ee4303a1c266015/src/main/java/com/atlauncher/workers/InstanceInstaller.java#L3176-L3267) · [公式の設定ZIP検証](https://github.com/ATLauncher/ATLauncher/blob/906066e8cf7c5a4770ec67545ee4303a1c266015/src/main/java/com/atlauncher/workers/InstanceInstaller.java#L3541-L3578)

## 仕上げの追加検証

### Modrinth・Technic・CurseForge

- ModrinthのFabulously Optimized `N276l2ON` を実APIと実 `.mrpack` で再確認しました。プロジェクトの明示的な非対応表示は警告として表示し、新規作成時に管理者の同意と対応ローダーEggが揃った場合に導入を試せます。既存サーバーへ適用する場合は、以前と同じく非対応として扱います。Generic Eggへの委譲は使わず、`env.server=unsupported` と `client-overrides` を除外するアーカイブ処理を使い、自動起動を無効化します。対応Eggが選べない場合や、アーカイブの安全性・依存ファイルの取得条件を満たさない場合は既存の検証で停止します。実パックの配置と起動は未検証です。[Modrinthのプロジェクト](https://api.modrinth.com/v2/project/1KVo5zza) · [選択したリリース](https://api.modrinth.com/v2/version/N276l2ON)
- Tekkit Legends `1.1.1` のPlatform APIは `minecraft=1.4.7`、同一ビルドの公式Solderと隔離起動ログは `1.7.10` でした。Tekkit 2 `v1.2.6` はSolderと起動ログの両方で `1.12.2` でした。実装は既知Eggと専用ZIPの版を照合し、公式Solderのリリース情報だけをMinecraft版に採用します。照合不可・通信失敗時はMinecraft版を未確定のまま扱い、Eggと `MODPACK_VERSION` は専用ZIPに設定します。[LegendsのPlatform API](https://api.technicpack.net/modpack/tekkit-legends?build=recommended) · [LegendsのSolder](https://solder.technicpack.net/api/modpack/tekkit-legends/1.1.1) · [Tekkit 2のSolder](https://solder.technicpack.net/api/modpack/tekkit-2/1.2.6)
- CurseForge Core APIの実エンドポイントに、ATM10 `925200` の親 `9620701`、子 `9620727`、子の `/download-url`、プロジェクトのファイル一覧をリクエストしました。4件ともHTTP 403でした。この隔離環境には `MOD_MANAGER_CURSEFORGE_API_KEY` がありません。以前の公開Additional FilesとCDN実ZIPの検証、およびCore APIを模した回帰テストは引き続き有効です。認証付き応答に含まれる親子IDとダウンロードURLの受け渡しは未検証です。[公式Core API Fileスキーマ](https://docs.curseforge.com/rest-api/#tocS_File) · [ATM10の公開Additional Files](https://www.curseforge.com/minecraft/modpacks/all-the-mods-10/files/9620701/additional-files)

### 依存Modと固定ローダーの矛盾

Server Optimization `egRNRK9l` の `.mrpack` はFabric `0.14.22` を指定します。実際のJARから `fabric.mod.json` を読み取ると、c2meは `>=0.14.25`、Fabric APIは `>=0.15.6`、ServerCoreは `>=0.15.2`、TT20は `>=0.15.11` を要求しています。Modrinthのリリース応答と `.mrpack` のファイル一覧で確認できるのは配布ファイルと側の指定までです。単純な `>=` の矛盾はJARを静的に読んで検出できますが、配布元ごとに異なる必須依存、入れ子JAR、複合範囲、Forge/NeoForgeのMaven範囲、Quiltの依存構造まで判定するには、ローダー別の検証が必要です。[Modrinthリリース](https://api.modrinth.com/v2/version/egRNRK9l) · [Fabric Mod JSON仕様](https://wiki.fabricmc.net/documentation:fabric_mod_json_spec)

管理用のWings `/files/contents` はファイル全体を返します。署名付きダウンロードではHTTP Rangeが利用できるため、導入時の警告を実装する場合は、検証済みの配置先JARについて、範囲取得とサイズ制限を使った読み取りが候補です。各Modを丸ごと再取得する検証は追加していません。今回の矛盾は静的に再確認しましたが、製品の自動警告は未実装です。導入フローがパック指定のローダーを上げることはありません。[Wingsの管理用ファイル取得](https://github.com/pelican-dev/wings/blob/cbce59e131e91331800bbaf47dca698bae7e18b5/router/router_server_files.go#L31-L95) · [署名付きダウンロードのRange処理](https://github.com/pelican-dev/wings/blob/cbce59e131e91331800bbaf47dca698bae7e18b5/router/router_download.go#L117-L126)

### Chromium・Panel＋Wingsの再現手順

1. 使い捨ての環境でPanelへPRのプラグインをローカルインストールし、空のDBとユーザーを用意します。公式Fabric Eggをインポートし、ローカルのWingsノードとループバックのテスト用アロケーションを設定します。Wingsとゲーム用ポートを外部へ公開しません。PanelがWingsの応答を待ちながらWingsからのコールバックを受けられるよう、HTTPワーカーを複数起動します。`artisan serve` の場合は `PHP_CLI_SERVER_WORKERS=4` と `--no-reload` を使えます。rootless DockerでWingsをコンテナに入れる場合、Wingsが生成する `passwd`・`group`・`machine-id` とサーバーボリュームのパスをWingsとDockerデーモンで共有します。
2. Panelにログインしてサーバー作成を開きます。ModpackをONにすると結果が表に出ること、カタログの前後ページ、Technicへ切り替えた際のフィルター表示、Modrinthに戻ってMinecraft `1.19.3`・Fabric・`Fabric Server Optimization` の検索が使えることを確認します。
3. `vtGmZsbn` を選び、Informationへ進んで戻ります。検索語、フィルター、行とリリースが残ることを確認します。再度進み、名前とテスト用アロケーションを選びます。Egg設定でFabric、`MC_VERSION=1.19.3`、`LOADER_VERSION=0.14.12`、Java 17、インストールスクリプト実行あり、自動起動なしを確認します。
4. 作成後にWings側のインストールログを確認し、Panelで `php artisan mod-manager:process-jobs` を実行するかSchedulerを稼働させます。Wingsのインストール成功を待ってから、パックの結果、配布由来のModが配置されたこと、実際のリリースID、自動起動されていないことを確認します。隔離サーバーを手動で起動し、`Done` を確認したら `stop` で終了します。
5. 認証付きCurseForgeの補足試験では、隔離Panelの `MOD_MANAGER_CURSEFORGE_API_KEY` を設定し、ATM10で親 `9620701` を選択します。APIが返した同一プロジェクトの子 `9620727` と公式ダウンロードURLを照合します。APIがURLを提供しない場合は制限として扱います。選択したファイルのIDと固定ビルドを導入結果まで確認します。[ATM10 Additional Files](https://www.curseforge.com/minecraft/modpacks/all-the-mods-10/files/9620701/additional-files)

隔離環境ではPanel `1c3e7b33`、Wings `v1.0.0-rc.2`、公式Fabric Eggを使用しました。Chromiumで手順2・3を実行し、`false` がFilamentの必須ラジオ選択で未入力になる不具合を再現しました。修正後は実ブラウザで作成を完了し、Wingsへの作成要求と、`vtGmZsbn`、`MC_VERSION=1.19.3`、`LOADER_VERSION=0.14.12`、Java 17を持つ永続化ジョブを確認しました。

公式 `ghcr.io/pelican-eggs/yolks:java_17` と `installers:java_11` のDocker pullは展開時のディスク不足で失敗しました。Wingsは `No such image` を返し、Panelは `installing`、導入ジョブは延期状態でした。実Wings経由のMod配置、起動、正常停止と、保留期限到達後の通知は今回未検証です。先の表にある単体隔離起動では、同じModrinthリリースがJava 17で `Done` と正常停止に到達しています。
