> **Handoff Reference Artifact (WIP — Temporary Planning Archive)**  
> This document preserves verified research / design findings from the prior multi-agent session so subsequent sessions on `fix/modpack-wizard-spigot` do not need to repeat the same investigation.  
> See [`docs/handoff/HANDOFF.md`](HANDOFF.md) for the master handoff summary and next-session workflow. Remove or consolidate `docs/handoff/` once Milestones M2–M5 are completed and merged into permanent `docs/*.md`.

# Handoff Report — `survey_explorer_modpack_comparison` (Hard Handoff)

- **Agent**: `survey_explorer_modpack_comparison` (`teamwork_preview_explorer`)
- **Timestamp**: `2026-11-24T23:30:00Z`
- **Target Repo**: `<repo-root>` (`fix/modpack-wizard-spigot` @ `4e5f839a4d8ede672565b430995e5ee5655167dd`)
- **Reference Repo**: `cosmii02/pelican-modpackmanager (commit ddc2e364, MIT)` (`main` @ `ddc2e364427c703e565b59f9533465e6b1090543`, v1.7.3, MIT License)
- **Mode**: Read-only deep comparative source-code audit (zero source files modified)

> **Executive Summary**: `pelican-mod-manager` is decisively stronger in security, cryptographic integrity, transactional rollback/crash recovery, CurseForge restricted-file manual upload, new-server creation, and testability (729 tests vs. 0), whereas `cosmii02/pelican-modpackmanager` is decisively stronger in real-world large-pack throughput (Wings-side extraction & Panel-bundled ZIP parts), existing-server Egg/loader/version switching, transient Wings retry resilience, and cooperative cancellation. **Neither repository cleanly installs and boots every mainstream modpack today**: `pelican-mod-manager`'s strictly serial `pullForeground → readStream → move` pipeline and 3× live-file hash read-backs do not scale to large Server Packs or 17,000-file FTB packs (and break on pre-Nov-2026 Wings lacking `Content-Length` support for FTB gzipped JSONs), and it blocks existing-server modpack updates whenever a pack bumps its loader build; meanwhile `cosmii02` omits Modrinth `server-overrides/`, verifies almost zero checksums, runs untrusted pack shell scripts, wipes server files before downloading, mutates shared Panel Eggs, and breaks on non-database Laravel queues.

---

## 1. Observation

### 1.1 Baselines, Live External Verification & Independent Re-Evaluation of Existing Docs

Every relevant file in `cosmii02/pelican-modpackmanager (commit ddc2e364, MIT)` (all 29 repository files, including all 3,845 lines of `ModpackInstallService.php`, 1,743 lines of `ModpackBrowserPage.php`, `RetryingDaemonFileRepository.php`, `InstallModpackJob.php`, `UpdateCheckService.php`, all 4 provider services, migrations, config, `NOTES.md`, and git history) and all modpack/Wings/job/compatibility/source paths in `<repo-root>` were read directly.

In addition, live upstream sources were queried read-only to independently adjudicate claims in both repos and in `pelican-mod-manager/docs/`:

1. **Upstream `pelican-dev/wings` (`router/downloader/downloader.go`)**:
   - Commit `b32b813a` (`2026-11-19`, *"fix(downloader): allow remote downloads without a Content-Length (#216)"*) added `trnspt.DisableCompression = true` and support for `res.ContentLength == -1`. Every earlier Wings release (including `v1.0.0-rc.2` tested in `docs/provider-regression-matrix.md:29`) fails remote `pull()` when an origin omits `Content-Length` (chunked or gzip-compressed responses).
   - Wings `downloader.go` enforces SSRF protection (`isBlocked()` checking `wnet.IsInternal(ip)` at DNS + `ControlContext` dial time) and validates every redirect target (max 10 redirects).
2. **Live FTB API (`api.feed-the-beast.com/v1/modpacks/public`) & CDN (`files.feed-the-beast.com`)**:
   - `FTB Evolution 2` (pack `135`, v`1.11.0`): targets `minecraft: 26.1.2`, `neoforge: 26.1.2.129`, `java: 25.0.2+10-LTS`; **17,747 total files** (34 `clientonly`, **17,713 server files**: `datapacks: 12,849`, `config: 1,748`, `shaderpacks: 1,649`, `kubejs: 940`, `mods: 404`, `configureddefaults: 119`, `resourcepacks: 3`, root: `1`).
   - `FTB StoneBlock 3` (pack `100`, v`1.11.7`): 1,242 files, includes a **0-byte** managed config (`config/ironfurnaces.toml`, `size: 0`, `sha1: da39a3ee...`) and CurseForge-backed jars carrying both `edge.forgecdn.net` `url` and `files.feed-the-beast.com` `mirrors[]` plus `hashes.{sha1,sha256,sha512,murmur,cfMurmur}`.
   - Live `GET` with `Accept-Encoding: gzip` against a >5 KB `.json` file on `files.feed-the-beast.com` returned `HTTP/2 200` + `content-encoding: gzip` and **NO `Content-Length` header** — directly confirming `cosmii02`'s observation that pre-`b32b813a` Wings fails remote pulls on FTB text/JSON files!
3. **Live ATLauncher CDN (`download.nodecdn.net/containers/atl/packs/SevTechAges/versions/3.2.3/Configs.json`)**:
   - Confirmed `minecraft: 1.12.2`, `loader: forge 14.23.5.2860`, `configs: {filesize: 20926719, sha1: ...}`, 260 entries (`types: {mods: 259, extract: 1}`, `downloads: {server: 259, direct: 1}`). Confirms `cosmii02` silently drops the mandatory `type: extract` entry.
4. **Live Official Pelican Eggs (`pelican-eggs/minecraft`)**:
   - **Fabric (`java/fabric/egg-fabric.yaml`)**: startup command is STILL `java -Xms128M -Xmx{{SERVER_MEMORY}}M -jar {{SERVER_JARFILE}}` (fails with `-Xmx0M` on unlimited-memory servers where `SERVER_MEMORY=0`!). Variables: `LOADER_VERSION` (loader), `FABRIC_VERSION` (installer), `MC_VERSION`, `SERVER_JARFILE`.
   - **Forge (`java/forge/egg-forge-minecraft.yaml`)**: startup uses `-XX:MaxRAMPercentage=95.0` and switches between `-jar {{SERVER_JARFILE}}` and `@unix_args.txt`. Install script checks `^1\.(17|18|19|20|21|22|23)` for `unix_args.txt` (does not yet match MC `26.x`!) and for explicit `FORGE_VERSION` runs `mv forge-${FORGE_VERSION}*.jar $SERVER_JARFILE`.
   - **NeoForge (`java/neoforge/egg-neo-forge.json`)**: startup uses `-XX:MaxRAMPercentage=95.0 ... @unix_args.txt`; `NEOFORGE_VERSION` rule is `regex:/^[0-9\.\-]+$/` (rejects `-beta` suffixes!) and switches to `net/neoforged/forge` only when prefixed `1.20.1-`.
   - **Quilt (`java/quilt/egg-quilt.yaml`)**: still has ONLY `MC_VERSION` and `SERVER_JARFILE` — no `QUILT_LOADER_VERSION` / `QUILT_VERSION` variable upstream.
   - **Fabric Meta API (`meta.fabricmc.net/v2/versions/intermediary`)**: confirmed `net.fabricmc:intermediary:26.x` still exists for MC 26.x.
5. **Independent Re-Evaluation of `pelican-mod-manager/docs/`**:
   - `docs/modpack-compatibility-audit.md:45`: Its prior evaluation of `cosmii02/pelican-modpackmanager` correctly flagged HTML scraping, arbitrary `direct` URLs, executing ZIP scripts, mutable-branch Egg imports, Quilt Egg DB mutation, and pre-download deletion, **but missed**: (a) `cosmii02`'s Panel-side ZIP bundling (`installFilesViaBundles`) which solves FTB's 17,000-file scaling cliff, (b) `cosmii02`'s Wings-native mode-`000` permission repair (`repairExtractedPermissions`), (c) `cosmii02`'s safe key-level `server.properties` merge & `level.dat` world preservation, (d) `cosmii02`'s `-Xmx0M` unlimited-RAM Fabric startup fix, (e) `cosmii02`'s CurseForge-hosted FTB installer rerouting (`rerouteFtbInstallerPack`), (f) `cosmii02`'s `Modrinth` missing `server-overrides/` bug, `mcFromNeoforge('26.x')` bug, `min(3, ...)` concurrency clamp bug, and `DB::table('jobs')` queue-driver lock-in.
   - `docs/modpack-updates.md:14` is **stale**: claims FTB limits are 8 MiB and 2,000 files, whereas `FtbReleaseManifest::MAX_BYTES` (`src/Support/Modpacks/FtbReleaseManifest.php:11`) is now `24 MiB` and `ModpackManifest::MAX_REMOTE_FILES` (`src/Support/Modpacks/ModpackManifest.php:16`) is `20,000`.
   - `docs/modpack-updates.md:35-53, 61-74`: Performance benchmarks used synthetic 16–32 KiB JARs over in-memory/loopback stubs and therefore masked the real-world latency/bandwidth collapse of serial `pullForeground()` + full `readStream()` read-backs over remote Wings networks and real 2–20 MiB mods / 600 MiB Server Packs.

---

### 1.2 Mandatory 17-Point Comparative Audit Matrix

#### Point 1: Provider-by-Provider Modpack Fetch & Install Mechanisms
| Provider | `pelican-mod-manager` (Target) | `cosmii02/pelican-modpackmanager` (Reference) | Boot Reliability & Critical Evaluation |
|---|---|---|---|
| **Modrinth** | `ModrinthSource.php:143-286`, `ModpackCatalog.php:455-535`, `ModpackArchive.php:194-259`, `InstallModpack.php:179-214`, `ModpackDeployment.php:530-578`. Panel downloads `.mrpack` to `/tmp` (`timeout(180)`), parses `modrinth.index.json`, resolves `server-overrides/` + `overrides/`, then stages each remote file **serially** via Wings `pullForeground()` + reads every file back via `readStream()` to hash + uploads overrides file-by-file via `putStream()` + reads them back via `readStream()`. | `ModrinthService.php:85-334`, `ModpackInstallService.php:382-389, 926-1007, 2903-2954`. Wings `pull()` downloads `.mrpack` (`foreground => false`, polls file size), Wings `decompressFile()` extracts into `.modpack-extract`, moves to root, parses `modrinth.index.json`, pulls remote files via Wings `pull()` (moving window up to 3 concurrent, polls `GET /files/pull`), merges `/overrides` (misses `server-overrides/`). | **Target wins correctness** (`server-overrides/`, SHA-1/SHA-512 verification, HTTPS validation); **Ref wins speed/bandwidth** on `.mrpack` fetch & concurrent Wings pull. Target's serial `pullForeground + readStream` is painfully slow on 400+ mod packs. |
| **CurseForge** | `CurseForgeSource.php:175-580, 681-776`, `CurseForgeManifestReader.php:13-142`, `ModpackInstaller.php:62-161, 244-320`. Prefers dedicated server pack (`serverPackFileId` / `parentProjectFileId` + `isServerPack`). Reads `variables.txt` / `manifest.json` remotely via HTTP Range (`CurseForgeManifestReader`). On install, Panel downloads entire ZIP (even 600 MB server packs!) to `/tmp` (`timeout(180)`), hashes every entry, uploads every file via `putStream()`, reads EVERY file back via `readStream()`, renames one-by-one. Client ZIPs batch-resolve via `POST /mods/files` (100/chunk) + manual upload pause for restricted files; dumps ALL deps into `mods/`. | `CurseForgeService.php:82-866`, `ModpackInstallService.php:411-450, 473-509, 2832-2901`. Prefers server pack (`isServerPack`, `serverPackFileId`, `alternateFileId`, `/files` scan, HTML scraping of `/additional-files`). Server pack downloaded directly by Wings `pull()` and extracted via Wings `decompressFile()`. Client pack batch-resolves via `POST /mods/files` (50/chunk) + `POST /mods` (100/chunk for `classId` routing), reconstructs `edge.forgecdn.net` on null `downloadUrl` (fails on `allowModDistribution=false`!), pulls up to 3 concurrent via Wings. Reroutes CurseForge-hosted FTB `install.sh` packs to FTB API. | **Target wins integrity & restricted-mod handling** (manual upload flow, Range metadata peek, no HTML scraping, no `edge.forgecdn.net` TOS violation); **Ref wins server-pack deployment speed & classId routing** (Wings direct pull+decompress avoids 1.8 GB Panel round-trip, and `alternateFileId` + FTB-on-CF rerouting handle real packs Target rejects). |
| **FTB** | `FtbModpackSource.php:20-338`, `FtbReleaseManifest.php:9-285`, `ModpackInstaller.php:112-115, 176-200`. Uses `api.feed-the-beast.com/v1/modpacks/public`. Requires search query `>= 4` chars (no empty browse!), max 6 results. Downloads JSON manifest to `/tmp`, validates `targets`, `sha256`/`sha512`/`sha1`, `size`, `url` + `mirrors[]`, skips `clientonly` and non-server dirs (`shaderpacks/`, etc.). Fails if `url`+`mirrors` empty (ignores `curseforge` ref). Deploys EVERY file serially via `pullForeground + readStream + move`! | `FtbService.php:19-317`, `ModpackInstallService.php:452-466, 2961-3056, 3448-3776`. Uses `api.feed-the-beast.com/v1/modpacks/public`. Empty query browses `/popular/installs/{limit}` + supports local MC/loader filters. Resolves empty `url` via `curseforge {project, file}` batch. Zero-byte files written via `putContent('')`. Small files (`<= 1 MiB`) and `dist.modpacks.ch`/gzipped text fetched on Panel via `Http::pool(24)`, verified by SHA-1, packed into `<= 64 MiB` ZIP parts, uploaded via NodeJWT `/upload/file`, decompressed on Wings! Large files pulled by Wings (concurrency 3). Ignores `mirrors[]`, doesn't skip `shaderpacks/`. | **Ref's ZIP-bundling transport is transformative** (makes 17,000-file FTB Evolution 2 installable in ~1 min vs. Target's 2.6+ hours & pre-`b32b813a` Wings failure on gzipped JSONs!). **Target wins manifest validation** (`mirrors[]`, SHA-256/512, skipping `shaderpacks/`, `fingerprint` pinning, path safety). Target's `>= 4` char / 6-hit FTB search UX is overly restrictive. |
| **Technic** | `TechnicModpackSource.php:16-179`, `TechnicEggBinding.php:12-77`. **Supported** (search + describe + official Solder MC lookup + binds 10 known official Pelican Technic Eggs via `serverPackUrl` prefix/suffix matching, sets `MODPACK_VERSION`, `ProvisioningMode::EggInstall` on new server creation). | **Not implemented.** | **Preserve in Target.** Clean, conservative Technic support using official pack-specific Eggs without guessing. |
| **ATLauncher** | `AtlauncherModpackSource.php:14-165`. **Browse/version-list only** (`/v1/packs/simple`, `/v1/pack/{safeName}/`); explicitly marks `archiveAllowed: false` (`UNSUPPORTED`) because official Pelican has no ATLauncher Egg and `Configs.json` has `extract`/`browser` complexities (`docs/modpack-compatibility-audit.md:55`). | `ATLauncherService.php:21-272`, `ModpackInstallService.php:395-409, 3063-3127`. Fetches `/v1/packs/full/all` (cached 15m, Chrome UA for Cloudflare) + CDN `Configs.json` + `Configs.zip`. Pulls `type === 'mods'` (`server !== false`, `!library`, `download !== 'browser'`) to `mods/`, then pulls & extracts `Configs.zip`. | **Ref's ATLauncher install is broken on real packs**: silently skips `type=extract` (breaking SevTech: Ages!), silently skips `download=browser` (missing required mods!), installs ALL `optional=true` mods indiscriminately (causing mod conflicts!), and allows arbitrary `direct` URLs without hash checks. Target is right not to ship a half-baked ATLauncher installer unless `extract`, `optional`, `browser` failure, and SHA-1/MD5 verification are properly handled. |

#### Point 2: Dedicated Server Packs vs. Standard/Client Modpacks
- **Target (`pelican-mod-manager`)**:
  - Detection (`CurseForgeSource.php:317-356`, `ModpackFileSelector.php:62-96`): Checks `serverPackFileId`, then first page (`pageSize=50`) of `/mods/{id}/files` for `parentProjectFileId === releaseId && isServerPack === true`. Does **not** check `alternateFileId`, does **not** paginate past 50 files, does **not** scrape HTML.
  - Layout normalization (`ModpackArchive.php:59-118`): Accepts 0 or **1** wrapper directory (`explode('/', $path, 3)`); **fails** on 2- or 3-level nested wrappers. Whitelists only `ModpackPaths::MANAGED_ROOT_DIRECTORIES` + `local/kubejs/` (drops pack scripts, launchers, and unlisted dirs like top-level `paxi/` or `global_data_packs/`).
  - ServerPackCreator & runtime metadata (`ModpackArchive.php:121-156, 280-307`, `ModpackCatalog.php:552-582`): Uses HTTP Range requests (`CurseForgeManifestReader`) to inspect `variables.txt` and `manifest.json` (allowing 1 wrapper), falling back to parent client ZIP `manifest.json`. Does **not** read `*-installer.jar` filenames or `startserver.sh`/`run.sh` metadata, and fails completely on CurseForge-published FTB `install.sh` server packs.
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - Detection (`CurseForgeService.php:531-568, 783-866`, `ModpackInstallService.php:423-446`): Checks `isServerPack`, `serverPackFileId`, `alternateFileId`, page 1 `/files` (`parentProjectFileId`/`alternateFileId`), and falls back to scraping `https://www.curseforge.com/minecraft/modpacks/{slug}/files/{id}/additional-files` matching any file whose name contains `"server"`.
  - Layout normalization (`ModpackInstallService.php:979-1098`): Extracts to `/.modpack-extract`, descends up to **3** lone wrapper folders (`locateExtractedContentRoot()`, stopping if folder is in `SERVER_ROOT_FOLDERS`), moves everything to `/` (overwriting colliding files), then runs `repairExtractedPermissions()`.
  - Special shapes: Recognizes ServerPackCreator (`start.sh` + `variables.txt`), MDK (`run.sh` / `startserver.sh`), installer-based packs (`neoforge-*-installer.jar` / `forge-*-*-installer.jar`), and FTB-on-CurseForge (`rerouteFtbInstallerPack()`, lines 473-509).
- **Assessment**: Target should **adopt** `alternateFileId` API lookup (strictly verifying `modId` + `isServerPack`), up-to-3-level wrapper normalization, read-only metadata extraction from `*-installer.jar` / launcher scripts when `variables.txt` & `manifest.json` are absent, and FTB-on-CurseForge installer detection — while **rejecting** `cosmii02`'s fragile HTML scraping, substring `"server"` filename guessing, and blind root extraction of arbitrary scripts.

#### Point 3: Minecraft, Mod Loader, and Java Version Detection & Resolution
- **Target (`pelican-mod-manager`)**:
  - **MC & Loader**: Strict non-guessing extraction from `.mrpack` `dependencies`, CF `manifest.json` (`primary` modLoader first), ServerPackCreator literal `variables.txt`, FTB `targets[]`, Technic official Solder API (ignoring Platform API's inaccurate top-level MC field — e.g. Tekkit Legends `1.4.7` vs `1.7.10`, `TechnicModpackSource.php:157-177`). `CurseForgeSource::curseForgeFileTargets()` (`414-440`) returns `null` when multiple loaders or MC versions appear in `gameVersions` rather than guessing.
  - **Java**: `JavaRuntimeImage.php:11-151` models **both minimum AND maximum Java bounds**:
    - Min: `>=26 → 25`, `1.20.5+/1.21+ → 21`, `1.18–1.20.4 → 17`, `1.17 → 16`, `<=1.16 → 8`, combined via `max(minecraftJava, minimumJava)` (e.g., FTB `runtime/java`).
    - Max: `<=1.12` (Forge/unknown) `→ 8`, `<=1.16` (Forge) `→ 16` (`isTooNew()` prevents crashing legacy Forge on Java 17/21!).
    - Never invents Docker images not offered by the Egg (`fromEggImages()`).
    - **Minor gap**: Does not read ServerPackCreator `RECOMMENDED_JAVA_VERSION` from `variables.txt` (only `MINECRAFT_VERSION`, `MODLOADER`, `MODLOADER_VERSION` at `ModpackArchive.php:285`). Also `MinecraftVersionResolver::resolve()` (`src/Support/MinecraftVersionResolver.php:68-70`) falls back to `config('pelican-mod-manager.latest_minecraft_version')` when `MC_VERSION=latest`, whereas `InstallModpack.php:258-265` correctly rejects non-pinned `latest`.
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - **MC & Loader**: Reads `modrinth.index.json`, `manifest.json` (BUG: blindly takes `modLoaders[0]` ignoring `primary: true`, `ModpackInstallService.php:2638`), `variables.txt`, `startserver.sh`/`run.sh`, `*-installer.jar`, FTB `targets[]`, ATLauncher `Configs.json`. Carries `preferred_loader` from user's filter into `cfLoaderMeta()` (lines 2163-2190) to disambiguate dual-tagged 1.20.1 Forge/NeoForge packs. Queries NeoForge Maven metadata (`latestNeoForgeForMc()`, lines 2059-2092) when NeoForge version is unknown (including `26.x`).
  - **BUG in `cosmii02`**: `mcFromNeoforge()` (`ModpackInstallService.php:2571-2577`) prepends `'1.'` unconditionally, turning NeoForge `26.1.2.129` into **`1.26.1`** instead of `26.1.2`!
  - **Java**: `javaForMc()` (`ModpackInstallService.php:2811-2828`) maps `>=26 → 25`, `1.20.5+/1.21+ → 21`, `1.17–1.20.4 → 17`, `<=1.16 → 8`. Reads `RECOMMENDED_JAVA_VERSION` from `variables.txt` and `java.min` from ATLauncher/FTB. Has **no maximum Java bound** (`isTooNew` missing), and `pickJavaImageForVersion()` (`2583-2599`) invents `ghcr.io/pelican-eggs/yolks:java_{N}` even if the Egg forbids it (note: `NOTES.md:270` still says `parkervcp` while code uses `pelican-eggs`).
- **Assessment**: **Target's Java min/max model and non-guessing MC/loader resolution are clearly superior**; Target can safely add `RECOMMENDED_JAVA_VERSION` (clamped by `maximumJava`) from `variables.txt` and optional user-selected loader preference for dual-tagged 1.20.1 CF files.

#### Point 4: Egg Selection & Compatibility Checking
- **Target (`pelican-mod-manager`)**:
  - `EggCompatibilityEngine.php:16-530` + `InstalledEggCatalog.php:15-89` + `resources/egg-profiles.json` (1,532 lines covering official Pelican & Pterodactyl eggs).
  - Multi-tier classification (`uuid` → `update_url` → `name+signature` → `name` → `signature` → manual DB profile → tags) with `confidence` (`high`/`medium`/`none`) and `contradicts` detection (a renamed Egg with contradictory variable signature is rejected).
  - Requires `canPinArchiveRuntime()` (`EggCompatibilityEngine.php:254-265`) on new server creation — e.g., blocks Quilt packs with pinned `quilt-loader` on the official Quilt Egg because that Egg lacks `QUILT_VERSION`.
  - Ambiguous matches require explicit operator choice; never auto-imports or mutates Eggs.
  - **Limitation**: On existing servers (`CONTEXT_EXISTING`), `ModpackInstallCoordinator.php:60-64` rejects `EggChange` and `Variables` dispositions outright — **existing servers cannot switch Eggs or even change loader variables**. (`ServerEggMigration.php` exists in `src/Services/` as 182 lines of **unused dead code**!)
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - `ModpackInstallService.php:1863-1932, 2665-2809`: Hardcodes 4 canonical UUIDs + `update_url` paths (`forge`, `neoforge`, `fabric`, `quilt`). If not installed, **downloads the Egg YAML/JSON from mutable GitHub `refs/heads/main`** and imports it via `EggImporterService::fromContent()`. For Quilt, **silently rewrites the shared official Quilt Egg's `script_install` and inserts `QUILT_LOADER_VERSION` into `egg_variables`** (`prepareOfficialQuiltEgg()`). Also silently mutates `$egg->tags` (`1890-1911`) to add `required_egg_tags`.
  - Uses `EggChangerService::handle($server, $egg, keepOldVariables: false)` to switch Eggs cleanly on existing servers.
- **Assessment**: **Preserve Target's `EggCompatibilityEngine` and strict anti-mutation policy**; **reject** `cosmii02`'s mutable-branch GitHub auto-import and shared-Egg mutation; **implement controlled admin/authorized existing-server Egg & variable migration** in Target (using `EggChangerService(..., keepOldVariables: false)` with an already-installed compatible Egg, deleting or properly rewriting dead `ServerEggMigration.php`).

#### Point 5: Loader Installation & Startup Configuration
- **Target (`pelican-mod-manager`)**:
  - Variable pinning (`ModpackLoader.php:30-59`, `ModpackProvisioningPlanner.php:53-64`):
    - Forge: `FORGE_VERSION` = `<mc>-<build>` (or `<mc>-<build>-<mc>` for `1.7.10`/`1.8.9`).
    - NeoForge: `NEOFORGE_VERSION` = `1.20.1-<build>` for `1.20.1`, `<build>` otherwise (fixes NeoForge 1.20.1 coordinate!).
    - Fabric: `LOADER_VERSION` = `<build>` (leaving `FABRIC_VERSION` installer at Egg default `latest`).
    - Quilt: `QUILT_VERSION` (fails cleanly on official Quilt Egg since official Egg lacks it).
  - Post-install verification (`ModpackRuntime.php:18-191`): Deeply verifies the actual runtime created by the Egg! Reads `unix_args.txt` (verifies group `minecraftforge/forge` or `neoforged/(neoforge|forge)`, build match, `--fml.mcVersion`, `@unix_args.txt` in startup, and valid ZIP magic on the referenced JAR), OR opens `SERVER_JARFILE` JAR and inspects `META-INF/MANIFEST.MF` `Main-Class`, `fabric-loader`/`intermediary`/`minecraft-server.jar` (including shaded `<=0.12.5` Fabric), `quilt-loader`, and legacy Forge `ServerLaunchArgs`/`Implementation-Version`/`version.json`.
  - **Gaps in Target**:
    1. Does **not** handle unlimited-memory servers (`$server->memory === 0`) when the official Fabric Egg startup uses `-Xmx{{SERVER_MEMORY}}M` (produces `-Xmx0M` and crashes Java on boot!).
    2. Does **not** handle legacy Forge `<1.17` when official Forge Egg `mv $FORGE_JAR $SERVER_JARFILE` fails to rename `forge-<mc>-*-universal.jar` to `server.jar` (though official egg usually succeeds when clean).
    3. Does **not** trigger `ReinstallServerService` on existing servers when loader/MC version changes.
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - `applyLoaderVariables()` (`1939-2052`): Same Forge `<mc>-<build>` + `1.7.10`/`1.8.9` `<mc>-<build>-<mc>` logic, Fabric `FABRIC_VERSION=latest` + `LOADER_VERSION=<ver>`, Quilt `QUILT_LOADER_VERSION`. **BUG**: Does **not** prefix `1.20.1-` for NeoForge 1.20.1 when manifest gives bare `47.1.95`! Resets stale `FORGE_VERSION`/`NEOFORGE_VERSION` to Egg default when new pack's loader version is unknown (`2029-2043` — great defensive touch!).
  - Fixes `-Xmx{{SERVER_MEMORY}}M` → `-XX:MaxRAMPercentage=92.5` when `$server->memory === 0` (`1806-1809`)!
  - Forces `skip_scripts = false` before `ReinstallServerService`, polls `$server->status` for up to 15 min (`1834-1851`), renames leftover `forge-<mc>-*.jar` to `server.jar` on `<1.17` (`2478-2492`).
  - **Weakness**: `verifyEggRuntime()` (`2457-2499`) only checks that `/server.jar` or `/unix_args.txt` exists — does **not** verify that `unix_args.txt` or `server.jar` matches the requested MC/loader build or isn't corrupt!
- **Assessment**: **Preserve Target's deep `ModpackRuntime` verification and NeoForge 1.20.1 coordinate fix**; **adopt** `cosmii02`'s `-Xmx0M` unlimited-RAM Fabric startup fix, resetting stale loader-version variables when unknown, and (for existing-server runtime migration) `EggChangerService(..., keepOldVariables: false)` + `skip_scripts = false` + `ReinstallServerService` wait loop followed by `ModpackRuntime::assertInstalled()`.

#### Point 6: `manifest.json` / `modrinth.index.json` / Dependencies / `overrides` / `server-overrides`
- **Target (`pelican-mod-manager`)**:
  - **Modrinth (`ModpackArchive.php:194-259, 421-477`)**: Validates `formatVersion === 1`, `game === 'minecraft'`, explicit `files` list; filters `env.server === 'unsupported'`; applies **`server-overrides/` BEFORE `overrides/`** (exact `.mrpack` spec precedence!); ignores `client-overrides/`.
    - **Gaps**: (1) `firstHttpsUrl()` drops `downloads[1..n]` mirror URLs instead of passing them as `fallbackUrls`. (2) `MAX_OVERRIDE_BYTES = 2_000_000` (2 MiB) and `MAX_OVERRIDE_TOTAL_BYTES = 32_000_000` (32 MiB) silently skip larger files in `overrides/` (`override-too-large:...`) and throw on `server-overrides/` — a modpack bundling a 5 MiB custom mod/resource/KubeJS asset in `overrides/` loses it silently! (Because overrides are currently held in PHP RAM as strings in `ModpackOverrideFile::$contents`, instead of streaming from `ZipArchive` like `serverFiles()` does!)
  - **CurseForge (`ModpackArchive.php:309-363`, `ModpackInstaller.php:276-295`)**: Validates `manifestType === 'minecraftModpack'`, reads `manifest['overrides']` directory name, respects `required: false`.
    - **MAJOR BUG**: `ModpackInstaller.php:278` hardcodes `'mods/' . $dependency->filename` for **every** CurseForge manifest dependency! Resource packs (`classId=12`), shaders (`classId=6552`), and datapacks (`classId=6945` or `classId=12` + `categoryId=5193`) are dumped into `mods/`!
  - **FTB (`FtbReleaseManifest.php:21-120`)**: Validates IDs, `private: false`, `status: success`, reads `url` + `mirrors[]`, deduplicates identical paths merging mirrors. **Gap**: Completely ignores `$file['curseforge']` `{project, file}` references when `url`/`mirrors` are absent.
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - **Modrinth (`ModpackInstallService.php:2903-2954, 3149-3161`)**: Parses `modrinth.index.json`, skips `env.server === 'unsupported'`, pulls `downloads[0]` only (drops mirrors). **MAJOR BUG**: `mergeOverrides()` ONLY moves `/overrides` and **completely ignores `/server-overrides`**! (Any `.mrpack` relying on `server-overrides/` boots broken or misconfigured!) Also leaves `/client-overrides` extracted on disk in the server root!
  - **CurseForge (`ModpackInstallService.php:2832-2901, 3136-3144`)**: Ignores `manifest['overrides']` custom directory name (hardcodes `/overrides`), ignores `required: false` (treats all optional files as required), BUT **does** call `getModClasses()` (`POST /mods`) to route `classId` `12 → resourcepacks/`, `6552 → shaderpacks/`, `6945 → datapacks/`, `default → mods/`.
  - **FTB**: Resolves missing `url` via CurseForge `{project, file}` batch, handles 0-byte files directly, ignores `mirrors[]`.
- **Assessment**: Target should **fix** (1) CurseForge `classId` routing (skipping client-only `6552` shaders, routing `6945`/datapacks properly, routing `12` resourcepacks out of `mods/`), (2) streaming overrides from `ZipArchive` instead of buffering in RAM so >2 MB overrides aren't silently dropped, (3) Modrinth `downloads[1..n]` mirror fallbacks, and (4) FTB `curseforge: {project, file}` fallback resolution + 0-byte file handling.

#### Point 7: Client-Only Mod Exclusion
- **Target (`pelican-mod-manager`)**:
  - **Modrinth**: Excludes `env.server === 'unsupported'` and `client-overrides/` (`ModpackArchive.php:213-218, 246`). In `ModpackCatalog::describeModrinth()` (`466-488`), blocks `server_side=unsupported` projects on existing servers, warns + requires confirmation on new servers. Does not hide `server_side=unsupported` in search hits.
  - **FTB**: Excludes `clientonly: true` (`FtbReleaseManifest.php:46-48`) PLUS `SKIPPABLE_NON_SERVER_DIRS` (`shaderpacks`, `animation`, `simple-rpc`, `screenshots`, `packmenu`, `fancymenu_data`, `patchouli_books`, non-kubejs `local/`, lines `17-19, 144-173`).
  - **CurseForge client packs**: **NO client-only mod exclusion** (relies solely on preferring server packs when available; when building from a client ZIP, installs client-only mods AND shaders/resourcepacks into `mods/`).
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - **Modrinth**: Filters `server_side:required|optional|unknown` in search (`ModrinthService.php:98`) and skips `env.server === 'unsupported'` in index (`ModpackInstallService.php:2918`). **BUG**: Leaves `client-overrides/` sitting in server root after extraction!
  - **FTB**: Excludes `clientonly: true` only (`FtbService.php:187`); downloads 1,649 useless `shaderpacks/` files in FTB Evolution 2.
  - **ATLauncher**: Excludes `server === false` (`ATLauncherService.php:179`), but installs all `optional: true` mods.
  - **CurseForge client packs**: **NO client-only mod exclusion** (routes shaders/resourcepacks to `shaderpacks/`/`resourcepacks/`, still downloads them unnecessarily, and still puts client-only JARs like Sodium/Iris/Oculus into `mods/`, crashing dedicated servers on boot!).
- **Assessment (Shared Weakness)**: Neither repo uses a fragile name-based client-mod blocklist (good — name blocklists cause false positives/negatives). **However, BOTH repos crash dedicated servers when assembling a CurseForge client ZIP containing client-only mods.** Target can dramatically improve CurseForge client-pack boot reliability *without* guessing by using **authoritative signals only**:
  1. Skip `classId === 6552` (Shaders — 100% client-only).
  2. When inspecting/staging a CurseForge client-pack JAR, check authoritative loader metadata inside the JAR (zero false positives): `fabric.mod.json` `"environment": "client"`, `quilt.mod.json` `"minecraft": {"environment": "client"}`, and Forge/NeoForge `META-INF/(neo)?mods.toml` `clientSideOnly = true` — skip and report in warnings!
  3. Optionally filter `server_side:required|optional|unknown` (or surface a clear badge) in Modrinth modpack search for existing servers.

#### Point 8: CurseForge External Distribution Restriction Handling
- **Target (`pelican-mod-manager`)**:
  - **Best-in-class implementation** (`CurseForgeSource.php:448-579`, `CurseForgeFile.php:5-51`, `CurseForgeManualFiles.php:15-128`, `ModpackInstaller.php:244-320`, `InstallModpack.php:368-395`, `InstalledOperationManager.php:817-906`, `curseforge-manual.blade.php`):
    - Distinguishes `allowModDistribution === false` (`distribution`) from `401`/`403`/`404`/`429`/timeout.
    - Preflights ALL dependencies BEFORE touching live server files.
    - Respects `required: false` (skips unavailable optional mods with warning instead of blocking!).
    - Pauses up to 7 days (`STATUS_WAITING_FILES`), pins `archive_sha256` and `manual_token`, shows exact project/file IDs, official CurseForge file URL, expected SHA-1/MD5, byte size, and staging path `.mod-manager-curseforge/{pid}-{fid}.jar`.
    - On resume, verifies uploaded file's size, ALL published SHA-1/MD5 hashes, valid JAR ZIP structure (`ZipArchive::CHECKCONS`, `.class` or mod descriptor, no symlinks, no path traversal, <=128 MiB/file, <=512 MiB/batch).
  - **Minor gaps**: (a) `CurseForgeFile::canUpload()` (`CurseForgeFile.php:24`) requires `.jar` suffix, so restricted non-JAR assets (e.g. `.zip` datapacks) cannot use manual upload; (b) `CurseForgeSource::dependencyFiles()` (`450`) uses a fixed 45s deadline across all chunks — for a 600-mod pack with many un-batched fallbacks, 45s can be tight; (c) does not call `/mods/{pid}/files/{fid}/download-url` fallback for `dependencyFiles()` when `downloadUrl` is omitted in bulk response (unlike `modpackZipDownload()` at line 754, which DOES call it!).
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - **No manual upload, no preflight, no pause/resume.**
  - `CurseForgeService.php:596-599, 659-682`: When `downloadUrl` is null, silently reconstructs `https://edge.forgecdn.net/files/{p1}/{p2}/{rawurlencode(fileName)}`! This bypasses CurseForge's `allowModDistribution=false` opt-out (TOS violation), fails on newer CDN-restricted files or returns 403/404 midway through Wings pulling (leaving a half-installed broken server!), and aborts even on optional (`required: false`) files!
- **Assessment**: **Strictly preserve Target's manual upload workflow and reject `cosmii02`'s `edge.forgecdn.net` reconstruction.** Add `/download-url` fallback in `dependencyFiles()` ONLY when `allowModDistribution !== false` and `downloadUrl` is missing, and allow `.zip` manual uploads for verified datapacks/resourcepacks.

#### Point 9: Modpack-Specific Installers, Launch Scripts & JVM Arguments
- **Target (`pelican-mod-manager`)**:
  - Never copies or executes pack-shipped `start.sh`, `run.sh`, `startserver.sh`, `variables.txt`, `user_jvm_args.txt`, or `*-installer.jar`. Uses the Pelican Egg's official startup command and JVM sizing exclusively. Reads `variables.txt` strictly as literal key-value metadata (`ModpackArchive.php:280-307`).
  - **Limitation**: If a server pack includes tuned pack-specific GC/JVM flags in `user_jvm_args.txt` (e.g., G1GC/ZGC flags, `--add-opens` required by certain older mods on Java 17/21, or `log4j` flags) or ships a custom non-standard bootstrapper, those flags are not surfaced to the admin (not even as an informational note).
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - `configureSelfContainedPack()` (`1623-1758`) & `configurePostReinstallLauncher()` (`2285-2316`):
    - **ServerPackCreator**: Sets server startup to `bash start.sh`, patches `variables.txt` (`WAIT_FOR_USER_INPUT=false`, `RESTART=false`, `JAVA_ARGS="-Xms128M -XX:MaxRAMPercentage=92.5"`), writes `user_jvm_args.txt`.
    - **MDK / ATM `run.sh` / `startserver.sh`**: Sets startup to `bash run.sh nogui` or `bash startserver.sh [nogui]`, overwrites `user_jvm_args.txt` with `-Xms128M -XX:MaxRAMPercentage=92.5` (ironically wiping any pack-authored `--add-opens` / GC args in `user_jvm_args.txt`!).
    - **Contradiction / Bug**: `NOTES.md:239` explicitly states *"We deliberately do not drive `startserver.sh` directly: it has its own 10s auto-restart loop and pack-specific env vars that fight Pelican"*, YET `ModpackInstallService.php:1636, 1689, 2287, 2308` actively sets startup to `bash startserver.sh`!!
    - **Security risk**: Blindly executing arbitrary `bash start.sh` / `bash startserver.sh` from a third-party ZIP lets a pack run arbitrary commands, spawn auto-restart loops, break Pelican graceful `stop` signals, or hang waiting on interactive prompts.
- **Assessment**: **Reject executing pack shell scripts** (Pelican Eggs handle startup cleanly and predictably). **Preserve Target's Egg-driven startup**, fix `-Xmx{{SERVER_MEMORY}}M` → `-XX:MaxRAMPercentage=95.0` when `$server->memory === 0`, and optionally extract *read-only* metadata from `startserver.sh`/`run.sh`/`*-installer.jar` (loader + exact build) so ATM10-style standalone packs still resolve their exact build for the official Egg!

#### Point 10: New Server Creation vs. Existing Server Installation & Updates
- **Target (`pelican-mod-manager`)**:
  - **New Server**: Full integration into Admin `CreateServer` wizard (`ModpackCreationStep.php` + `QueueCreatedServerModpack.php`), setting Egg, startup, Java image, pinned variables, `skip_scripts=0`, `start_on_completion=0`, and queueing `InstallModpack` (deferring while Wings installs the Egg).
  - **Existing Server**: Preview → Stop Server → Mandatory Full Backup (with `# Full modpack update backup` comment defeating `.pelicanignore`/`.pteroignore`!) → Approve → Transactional Diff Apply (`ModpackUpdateWorkflow.php` + `ModpackDeployment.php`), tracking exact file inventory in `mod_manager_modpacks` DB table + `.mod-manager-modpack.json`.
  - **Major Gaps on Existing Servers**:
    1. **Cannot switch Eggs or update MC/loader variables** (`ModpackInstallCoordinator.php:60-64, 86-89`) — blocks both cross-pack switches AND same-pack updates that bump loader builds!
    2. **No clean-install option** when switching from a legacy/untracked server to a new pack (user must manually delete every conflicting JAR in `mods/`).
    3. **No scheduled modpack update check / notification** (unlike individual mods).
    4. **No "Link existing pack"** recovery if DB inventory is missing.
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - **New Server**: **None.**
  - **Existing Server**: Full browse + install + cross-loader Egg switch + reinstall + optional backup + optional clean-wipe + optional world delete + optional old-backup delete + `linkInstalled()` + optional `.modpack-manager.json` file metadata + scheduled `modpack-manager:check-updates` (`UpdateCheckService.php`) notifying owners once per compatible version!
  - **Major Gaps**: Does NOT check if the server is stopped before wiping/installing! Does NOT show a diff preview of conflicts/user-modified configs before applying! Does NOT protect against `.pelicanignore` omitting files from the backup!
- **Assessment**: **Preserve Target's new-server wizard, stop-server guard, `.pelicanignore`-safe full backup, and preview/diff/inventory model.** **Adopt/adapt** controlled existing-server Egg/loader-variable update + reinstall (gated on appropriate authorization, e.g., admin / `settings.reinstall` + `startup.update`), optional clean-install mode for pack switches (keeping worlds + safe settings), and scheduled modpack update checking (`UpdateCheckService` pattern).

#### Point 11: File Integrity Verification
- **Target (`pelican-mod-manager`)**:
  - `ModpackDeployment::inspect()` (`580-639`): Verifies **SHA-256, SHA-1, SHA-512, MD5** (all published algorithms), exact byte size, ZIP magic bytes (`ArchiveSignature::isZip()`), and `ZipArchive::CHECKCONS` when hashes are absent!
  - `ModpackInstaller::deploy()` (`96-108, 136-143, 184`): Verifies ZIP entry decompressed byte length, computes SHA-256 of every entry, validates FTB `manifest_fingerprint`.
  - `InstallModpack::handle()` (`217-234`): Pins `archive_sha256` across preview → apply and across CurseForge pause → resume.
  - `CurseForgeManualFiles::verifiedSnapshot()` (`48-126`): Verifies exact size, ALL published SHA-1/MD5 hashes, and internal JAR structure.
  - `CurseForgeFingerprint.php`: Streaming Murmur2 (seed 1) for catalog identification.
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - **Almost completely absent!**
  - `fetchBundleChunk()` (`3562`): Verifies **SHA-1 ONLY** for FTB files bundled on Panel (`<= 1 MiB`).
  - **ZERO hash verification** for: (a) Modrinth `.mrpack` download or ANY Modrinth mod downloads (even though `modrinth.index.json` provides SHA-1 AND SHA-512 for every file!), (b) CurseForge server pack or client-pack mod downloads, (c) ATLauncher mods or `Configs.zip` (even though `Configs.json` provides `configs.sha1`!), (d) FTB files `> 1 MiB` pulled by Wings, or (e) FTB Panel fallback batch (`downloadFilesViaPanelFallbackBatch()` line 3741 only checks `$body !== ''`!).
  - `verifyCompletedRemoteFiles()` (`3415-3438`) only checks `$entries[$name] > 0` — does NOT even check against `$file['size']`!
- **Assessment**: **Strictly preserve Target's cryptographic integrity guarantees!** The key architectural fix is **where** hashing happens: for Panel-downloaded/bundled files (and Server Pack ZIP entries), hash **once on the Panel during download/streaming** rather than uploading to Wings and downloading every byte back from Wings!

#### Point 12: Existing File Conflict, Overwrite, Clean-Install & User-Data Preservation
- **Target (`pelican-mod-manager`)**:
  - `ModpackDelta.php:17-53`, `ModpackDeployment.php:43-124, 389-498`:
    - Tracks SHA-256 ownership per managed file in `mod_manager_modpacks`.
    - Unmodified pack configs update cleanly; **user-modified configs are preserved** (`modified`/`conflict`, never silently clobbered!).
    - Individually catalog-installed mods (`mods/.pelican-mod-manager.json` with `managed_by === 'catalog'`) are protected from pack deletion/overwrite and flagged as conflicts if colliding by path or project ID (`94-114`).
    - Retires removed executable files (`mods/`, `scripts/`, `groovy/`, `bansoukou/`, `datapacks/`, `global_packs/`, `openloader/`, `kubejs/server_scripts|startup_scripts|data/`) ONLY when current hash matches owned hash.
    - Protects `world*`, `server.properties` `level-name` (strict Java-properties parsing), any directory containing `level.dat`/`level.dat_old`/`session.lock`/`uid.dat`, `eula.txt`, `ops.json`, `whitelist.json`, `banned-*.json`, `usercache.json`, root `*.jar`, `.mod-manager-*`.
  - **Gaps**: No clean-install option for switching to a different modpack; no safe `server.properties` operational merge when a new pack ships worldgen defaults in `server.properties` (because `server.properties` is always 100% protected/ignored).
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - Defaults `deleteExisting = true` when switching to a different pack, `false` when updating the same pack (`ModpackBrowserPage.php:608-611`).
  - Clean install (`cleanExistingServerRoot()`, `867-924`): Scans `/` for directories containing `level.dat` (plus `level-name` + `_nether`/`_the_end`), deletes everything else in `/`.
  - **Smart `server.properties` merge (`restoreSafeServerProperties()`, `1138-1222`, `config/modpack-manager.php:29-60`)**: Saves old `server.properties`, lets the new pack write its own worldgen settings (`level-type`, `generator-settings`, `level-seed`, datapacks), and merges back ONLY an allowlist of 30 operational settings (`server-port`, `motd`, `max-players`, `online-mode`, `white-list`, `difficulty`, `gamemode`, `view-distance`, `rcon.*`, etc.) plus `level-name` if keeping the world!
  - Non-clean updates (`removePreviousPackMods()`, `804-848`): Tracks only filenames in `/mods` (`pack_mods` JSON column). **Weaknesses**: (1) Does not track `config/`, `kubejs/`, `datapacks/`, `scripts/` — stale KubeJS scripts or datapacks from old pack versions linger and break the new pack! (2) Overwrites user-modified configs unconditionally (`moveExtractedIntoRoot()` deletes colliding files!). (3) Never verifies hashes, so a user-modified file with the same name is silently deleted/overwritten. (4) Deletes old `libraries/`, `versions/`, `server.jar`, `unix_args.txt`, launchers, and previous `pack_mods` **BEFORE** downloading the new pack!
- **Assessment**: **Preserve Target's cryptographic ownership ledger, user-config preservation, individual-mod awareness, and post-staging commit order.** **Adopt** `cosmii02`'s safe operational `server.properties` allowlist merge (for clean pack switches) and explicit clean-switch mode (executed ONLY after staging/verification, while preserving `level.dat` worlds + player/admin JSONs + `eula.txt`).

#### Point 13: Error Handling, Rollback, Crash Recovery & Retries
- **Target (`pelican-mod-manager`)**:
  - **Rollback & Crash Recovery (Exceptional)**:
    - Stages all new/replacement files under `.mod-manager-next-{token}-{n}` first; rechecks live files before touching anything (`ModpackDeployment.php:163-175`).
    - Writes durable crash journal to `mod_manager_modpacks` (`begin()`, `182`) with expected original SHA-256 of every touched path + backup UUID (and locks the Pelican backup against accidental deletion during apply, `InstallModpack.php:296-315`!).
    - Moves replaced/deleted files to `.mod-manager-prev-{token}-{n}` and verifies backup move digest (`200-202`).
    - On ANY error, executes verified reverse rollback (`rollback()`, `641-677`). If rollback cannot be verified (e.g., process killed mid-apply), keeps recovery lock active until user restores the Pelican backup and `confirmRestored()` (`263-282`) proves every touched path matches its original SHA-256!
  - **CRITICAL GAP — ZERO RETRIES**:
    - `WingsRemoteFilesystem.php` has **ZERO** retry logic! Confirmed via `grep`: neither `WingsRemoteFilesystem`, `ModpackDeployment`, `InstallModpack`, nor `CurseForgeSource::dependencyFiles()` retries transient network/DNS/TCP/TLS/5xx errors!
    - On a large install with hundreds/thousands of Wings HTTP calls, a single transient DNS blip (`cURL error 6`) or TCP reset (`cURL error 7`) fails the entire job and triggers full rollback!
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - **Retries (Strong)**:
    - `RetryingDaemonFileRepository.php:15-32`: Subclasses `DaemonFileRepository`, overriding `getHttpClient()` with `->retry([500, 2000, 5000], when: fn (Throwable $e) => self::failedBeforeSending($e))` where `failedBeforeSending()` matches `/cURL error (6|7|35):|cURL error 28: (Resolving|Connection) timed out/i`. Because these errors occur **before bytes are sent**, retrying is safe even for non-idempotent `deleteFiles`/`renameFiles`!
    - `fetchBundleChunk()` (`3542-3576`) & `downloadFilesViaPanelFallbackBatch()` (`3679-3765`): 3 attempts with backoff on HTTP/network/checksum errors.
    - `pullFilesThrottled()` (`3284-3294`): Gracefully backs off when Wings returns its simultaneous remote-download limit error instead of crashing.
  - **CRITICAL GAP — ZERO ROLLBACK**:
    - Deletes stale runtime & previous files (`stepDeleteFiles`, line 96) BEFORE `resolvePlan()` (line 99) and `stepDownload()` (line 103).
    - No transactional rollback on error — relies solely on the user manually restoring the Pelican backup (if enabled).
    - No crash-recovery lock to prevent a second install from running over a half-applied state.
- **Assessment**: **Preserve Target's transactional rollback & crash recovery journal.** **Immediately adopt `RetryingDaemonFileRepository`'s pre-send cURL retry pattern** inside `WingsRemoteFilesystem` (plus idempotent retries for read-only `listDirectory`/`readStream` and upstream CDN downloads with exponential backoff + jitter + `Retry-After` respect).

#### Point 14: Cancellation, Pause & Resume
- **Target (`pelican-mod-manager`)**:
  - **Pause & Resume (Superior)**: Full 7-day pause/resume for CurseForge restricted files (`STATUS_WAITING_FILES` + `manual_token` + `archive_sha256` pinning) and 24-hour preview review pause (`STATUS_WAITING_REVIEW` + `review_token` + `digest` verification).
  - **Job Fencing & Queue Independence (Superior)**: Own `mod_manager_background_jobs` table drained by `schedule:run` (`ProcessBackgroundJobsCommand.php`), so zero `queue:work` restart traps and works identically across Redis/database/sync Laravel queue configs! Uses `attempts` epoch fencing (`claimEpoch`) + 30s DB `heartbeat()` + `InstalledOperationLease::refresh(..., epoch: $claimEpoch)`.
  - **CRITICAL GAPS**:
    1. **No cooperative cancellation of an in-flight `InstallModpack` job** (only paused CurseForge plans or pending preview reviews can be cancelled).
    2. **No per-file progress reporting** (`InstalledOperationManager::progress()` is never called in `ModpackDeployment`).
    3. **Stale reservation race**: `DatabaseBackgroundJobQueue::STALE_RESERVATION_MINUTES = 5` (`15`), while `WingsRemoteFilesystem::FOREGROUND_PULL_TIMEOUT_SECONDS = 900` (`27`) has **no heartbeat callback** while blocked inside `pullForeground()`. A single >5 min transfer allows another scheduler tick to reclaim the row!
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - **Cooperative Cancellation (Clean Pattern)**: `InstallCancelledException` + `ensureInstallNotDismissed($record)` checked before/after every step, inside backup/reinstall/download loops, AND every 0.5s inside Guzzle's `progress` callback (`ModpackInstallService.php:3693-3699`). Truthful `'cancelling'` status locks UI until worker exits (`ModpackBrowserPage.php:1209-1223`).
  - **Severe Architectural Flaws**:
    1. `ModpackBrowserPage::queuedModpackJobs()` (`855-885`) reads `DB::table('jobs')->get()` and calls `@unserialize()` — **completely broken on Redis, SQS, Horizon, or sync drivers**!
    2. Requires `queue:work --tries=1 --timeout=3660` (`NOTES.md:109`) + worker restarts after plugin updates; hacks around Laravel's 90s `retry_after` with `public int $tries = 0` + `$this->release(60)` (`InstallModpackJob.php:26, 58`).
    3. No pause/resume; `cancelInstallView()` omits `authorizeManage()` (`ModpackBrowserPage.php:1162`).
- **Assessment**: **Preserve Target's scheduler-drained DB queue, epoch fencing, lease refresh, and CurseForge pause/resume.** **Adopt** cooperative cancellation (`ModpackCancelledException` + DB/cache cancel flag checked in `$guard`, download progress callbacks, and batch loops, triggering clean rollback!) AND add per-file progress updates (`progress($done, $total)` coalesced by `InstalledOperationManager`). Also close the 5-min stale-reservation gap by switching away from 15-min opaque blocking pulls (or heartbeating during bounded chunks/async polls).

#### Point 15: API Request Count, Network Bandwidth, Disk I/O & Memory Consumption
Let's compare concrete numbers on real-world packs (e.g. **600 MB CurseForge Server Pack with 2,219 files** like ATM10, **400-mod Modrinth pack**, and **17,747-file FTB Evolution 2**):

| Metric | `pelican-mod-manager` (Target) | `cosmii02/pelican-modpackmanager` (Reference) |
|---|---|---|
| **Catalog / Metadata API calls** | **Strong**: SWR cache (`SourceCache`) + HTTP Range `CurseForgeManifestReader` (reads only EOCD + manifest from 600 MB ZIP = ~100 KB instead of 600 MB!) + `POST /mods/files` (100/chunk) + `POST /mods` (only for missing URLs) + FTB `Http::pool(6)` cached 60s. | **Weak on browse**: No SWR cache; `FtbService::fetchPacks()` fires up to **160 uncached concurrent HTTP requests** per filtered search (`FtbService.php:51, 98`); `CurseForgeService::search()` loops `loaders × pages` sequentially; `ModpackBrowserPage::mount()` calls live `computeUpdateState()` API synchronously on every page load! **Good**: ATLauncher caches `/packs/full/all` 15m & embeds versions; `CurseForgeService::getModClasses()` batches 100/chunk. |
| **Server Pack Transfer (ATM10 600 MB / 2,219 files)** | **Catastrophic**: Panel downloads 600 MB ZIP to `/tmp` (180s timeout!) → hashes 600 MB in PHP → **2,219 serial `putStream()` uploads** (600 MB Panel→Wings) → **2,219 serial `readStream()` read-backs** (600 MB Wings→Panel!) → **2,219 serial `move()` renames** = **~6,660 serial HTTP calls & 1.8 GB total transfer**! On update, re-downloads 600 MB ZIP twice (exceeds `ModpackReviewArchive::MAX_FILE_BYTES = 64 MiB`) + **3× full live-file read-back** from Wings! | **Fast**: 1 Wings `pull()` (600 MB Internet→Wings directly, 0 Panel traffic!) → 1 Wings `decompressFile()` → ~12 batched `renameFiles(200)` calls → ~10 `search('*?*')` permission checks = **~30 HTTP calls & ~0 MB Panel bandwidth**! |
| **FTB Large Pack (FTB Evolution 2: 16,064–17,713 files)** | **Unusable**: **16,064 serial `pullForeground()`** + **16,064 serial `readStream()`** + **16,064 serial `move()`** = **~48,200 serial HTTP calls** (hours!) AND fails on pre-`b32b813a` Wings due to gzipped JSON lacking `Content-Length`! | **Fast (~1–2 min)**: Panel fetches ≤1 MiB files in parallel (`Http::pool(24)`), packs into ≤64 MiB ZIP parts, uploads ~2–5 parts via signed NodeJWT `/upload/file` + `decompressFile()` (~10 Wings calls total!), pulls large JARs directly on Wings (concurrency 3). **Flaw**: calls `createDirectory()` sequentially per unique subdir before bundling; writes full `debug_log` JSON to DB on every log line (`O(N^2)` DB I/O). |
| **Modrinth / CF Client Pack (400 mods)** | **Slow**: 400 serial `pullForeground()` + 400 serial `readStream()` + 400 serial `move()` = **1,200+ serial HTTP calls** + full Wings→Panel read-back of all 400 JARs (~800 MB–1.5 GB!). | **Moderate**: Wings pulls 3 concurrently (polls `GET /files/pull` every 0.5s), verifies via grouped `getDirectory()` (~100–300 calls total, 0 Wings→Panel JAR read-back), BUT zero hash verification! |
| **Memory & Disk I/O** | Streaming `putStream()`/`readStream()` keeps RAM low (~25–40 MB) EXCEPT `ModpackArchive::overrides()` buffers up to **32 MiB** of override strings in RAM (`ModpackOverrideFile::$contents`). Downloads full pack ZIP into `/tmp` (risking small `tmpfs` exhaustion). | `fetchBundleChunk()` buffers 24 concurrent file bodies (up to 24 MiB) in RAM + `ZipArchive::addFromString()` buffers entries until `close()` (up to ~64 MiB RAM + 64 MiB local disk). `stepSaveConfig()` stores up to 5 MiB files in Laravel `Cache`. |

#### Point 16: Large Modpack Scalability (300–600 Mods / 1,500–17,000+ Files)
- **Target (`pelican-mod-manager`)**:
  - **Breaks down on large packs** due to 5 compounding bottlenecks:
    1. **Serial 1-by-1 staging & renaming** (48,200 sequential calls on FTB Evolution 2; 6,660 on ATM10; 1,200+ on 400-mod packs).
    2. **Redundant full-file read-back from Wings (`inspect()`)**: Even after Panel computes SHA-256 while streaming an override/server-pack entry, it downloads the entire file back from Wings to hash it again!
    3. **3× full live-file read-back on existing-server updates** (`plan()` in preview + `plan()` in apply + `recheck()` before swap — never skips unchanged files via Wings `size + modified_at` fast-path!).
    4. **180-second ZIP download timeout** (`InstallModpack.php:179`) too short for 600 MB–1.5 GB server packs on slow links; `ModpackReviewArchive` 64 MiB cap forces re-downloading large server packs twice; `/tmp` storage risks filling `tmpfs`.
    5. **5-minute `STALE_RESERVATION_MINUTES` vs. 10-minute `withoutOverlapping(10)` vs. 20-minute `LEASE_TTL`**: Long single jobs can overlap scheduler ticks or suffer stale reservation reclaim if blocked inside a >5 min `pullForeground()`.
- **Reference (`cosmii02/pelican-modpackmanager`)**:
  - Scales vastly better on large packs via: 60-minute job timeout, Wings-direct server pack download/extract, Panel parallel `Http::pool(24)` + ≤64 MiB ZIP bundling (`installFilesViaBundles`) bounded by `$server->node->upload_size * 0.9`, batched `deleteFiles(200)` / `renameFiles(200)` / `chmodFiles(200)`, and Wings `GET /files/pull` active-download polling (0.5s).
  - **Scalability bugs in `cosmii02`**:
    - `ModpackInstallService.php:3209` clamps `$limit = min(3, $this->remoteDownloadConcurrency())`, silently breaking the admin's concurrency setting!
    - `assembleFtb()` runs thousands of serial `createDirectory()` calls before bundling!
    - `ModpackInstall::appendLog()` causes quadratic DB write amplification on large debug logs.
    - Requires manual operator tuning of `queue:work --timeout=3660` (standard Pelican default queue timeout kills large installs!).

#### Point 17: Security, Stability & Maintainability
| Dimension | `pelican-mod-manager` (Target) | `cosmii02/pelican-modpackmanager` (Reference) |
|---|---|---|
| **Zip-slip / Path traversal** | **Strong**: `ModpackPaths::safeRelative()`, rejects `..`, `/`, `\`, control chars, `:`, null bytes, case collisions, file/dir collisions; restricts writes to `MANAGED_ROOT_DIRECTORIES` + `local/kubejs/`. | **Weak**: No `..` or root-escape validation on Modrinth `path`, FTB `path`/`name`, ATLauncher `file`, or Panel-built ZIP entries; relies wholly on Wings jail; allows overwriting root configs/scripts. |
| **Symlink attacks** | **Strong**: Rejects Unix symlink entries (`0120000`) in server ZIPs & manual JARs (`ModpackArchive.php:75-79`, `CurseForgeManualFiles.php:111-114`) and rejects live symlinks on Wings (`ModpackDeployment.php:474-489`). | **Weak**: Does not inspect ZIPs for symlinks before Wings `decompressFile()`; only skips symlinks during `repairExtractedPermissions()`. |
| **SSRF & TLS** | **Mostly strong, 1 gap**: `ModpackPaths::isSafeHttpsDownloadUrl()` blocks non-HTTPS, userinfo, `localhost`/`.local`/`.internal`, private IP literals; Wings `downloader.go` enforces dial-time IP check on pulls. **Gap**: Panel-side `InstallModpack.php:179` follows HTTPS redirects without DNS/IP or redirect-host validation, and `isSafeHttpsDownloadUrl()` doesn't resolve DNS. | **Weak**: `isPublicHttpUrl()` (DNS + redirect check) is ONLY used for `syncServerIcon()`! Panel-side `fetchBundleChunk()` and `downloadFilesViaPanelFallbackBatch()` follow arbitrary redirects (incl. HTTP downgrade) with NO SSRF check! ATLauncher allows arbitrary `http://` `direct` URLs! `uploadToWings()` (`3629`) **disables TLS verification** outside `production`! |
| **Archive bombs** | **Strong**: Strict byte & entry limits (`8 MiB`/`24 MiB` manifests, `20,000` files, `8 GiB` server pack unpacked, `1 GiB` per file, `100,000` JAR entries, `CHECKCONS`). | **Weak**: Zero pre-extract entry/size checks before Wings `decompressFile()`. |
| **Arbitrary script / Egg supply chain** | **Strong**: Never runs pack scripts; never imports Eggs from mutable GitHub branches; never mutates Panel Eggs. | **Unsafe**: Executes pack-shipped `start.sh`/`run.sh`/`startserver.sh`; auto-imports Eggs from mutable `refs/heads/main` without SHA/hash pinning; mutates shared official Quilt Egg `script_install` & `tags` in DB! |
| **Authorization** | **Strong**: Role abilities (`minecraftModManager:*`), `isRootAdmin()` (not `isAdmin()`), per-server policy, explicit `BackupCreate`/`BackupRestore`/`ControlStart` checks. | **Mostly good, 1 gap**: Uses `SettingsReinstall` + backup perms, but `cancelInstallView()` (`ModpackBrowserPage.php:1162`) and `showLastInstallLog()` omit `authorizeManage()`. |
| **Code quality & Tests** | **Strong**: Clean modular classes, **729 PHPUnit tests (4,515 assertions)**, Pint + CI. Minor debt: `ServerEggMigration.php` dead code (182 lines), `ModManagerPage.php` large (5,843 lines), stale `docs/modpack-updates.md` limits, 316 pre-existing level-6 PHPStan diagnostics on `src/`. | **Poor**: **3,845-line god class** `ModpackInstallService.php`, 1,743-line `ModpackBrowserPage.php`, 1,513-line custom-CSS Blade view, **0 automated tests**, dead methods (`parseRunSh`, `resolveDownloadUrl`), doc/code contradictions (`startserver.sh`, `parkervcp`), broken `min(3, ...)` concurrency clamp, `DB::table('jobs')` driver lock-in. |

---

## 2. Logic Chain

1. **Why real-world installs fail or stall in `pelican-mod-manager` despite passing 729 unit tests**:
   - From Observations §1.1 (live FTB Evolution 2: 17,747 files, gzipped `.json` without `Content-Length`, 0-byte `ironfurnaces.toml`; live Wings commit `b32b813a`) and §1.2 Points #1, #15, #16: `ModpackDeployment.php` executes `pullForeground() → readStream() → move()` **one file at a time, serially**, and re-reads EVERY live tracked file 3 times on updates. Mock/loopback tests with 40–1,000 tiny 32 KiB JARs masked network RTT and bandwidth amplification, which explodes to **48,200 serial HTTP requests (2.6+ hours)** on FTB Evolution 2 and **6,660 calls / 1.8 GB transfer** on ATM10, while crashing on pre-`b32b813a` Wings for FTB JSONs and 0-byte files.
   - From §1.2 Points #4, #5, #10: `ModpackInstallCoordinator.php:60-89` blocks any existing-server modpack install/update whose Egg, MC version, or pinned loader build differs from the server's current state. Because real modpack updates routinely bump Forge/NeoForge/Fabric builds, users cannot update modpacks in-place without manual admin startup edits + reinstall.
   - From §1.2 Points #6, #7, #8: `ModpackInstaller.php:278` hardcodes `'mods/' . $dependency->filename` for all CurseForge client-pack dependencies (putting shaders/resourcepacks/datapacks into `mods/`), neither repo strips client-only JARs from CurseForge client builds (crashing dedicated servers), and `ModpackArchive::overrides()` buffers overrides in RAM and silently skips files >2 MiB.
   - From §1.2 Point #13 & #14: Zero Wings pre-send retries mean a single transient DNS/TCP hiccup kills a multi-thousand-file deployment; absence of cooperative cancellation leaves stuck jobs un-cancellable; 5-minute stale reservation vs. 15-minute blocking `pullForeground()` creates a duplicate-claim race.
2. **Why `cosmii02/pelican-modpackmanager` cannot be copied wholesale despite faster raw installs**:
   - From §1.2 Points #6, #8, #9, #11, #13, #14, #17: `cosmii02` achieves speed by sacrificing fundamentals that `pelican-mod-manager` already solved — it drops Modrinth `server-overrides/`, verifies almost zero checksums, bypasses CurseForge `allowModDistribution=false` via `edge.forgecdn.net` with no manual upload flow, executes untrusted pack shell scripts (`start.sh`/`startserver.sh`), auto-imports Eggs from mutable `main` branches and mutates shared Quilt Eggs in DB, deletes server files *before* downloading with zero rollback, disables TLS verification outside production, skips SSRF/path-traversal checks on download URLs/paths, locks into Laravel's `database` queue table via `@unserialize()`, and ships zero tests in a 3,845-line monolith.
3. **Why combining `pelican-mod-manager`'s verified transactional/security foundation with adapted transport, resilience, and runtime-migration ideas from `cosmii02` produces a strictly superior result**:
   - Transport efficiency and cryptographic integrity are **NOT mutually exclusive**! By staging validated files into **Panel-built bounded ZIP bundles** (with explicit clean Unix modes `0644`/`0755`, hashed *once* on Panel during download/ZIP-streaming, uploaded via signed NodeJWT `/upload/file` with strict TLS verification, and extracted into a staging dir via Wings `decompressFile()`), plus **batched `renameFiles()`/`deleteFiles()`** (up to 200/call), plus **mtime+size fast-path** on live-file ownership verification (only streaming back files whose Wings `size`/`modified_at` changed, or hashing once during apply!), `pelican-mod-manager` cuts Wings HTTP requests by **100×–1,000×** and eliminates Wings→Panel read-backs **without sacrificing a single SHA-256 check, rollback guarantee, or security boundary**!

---

## 3. Synthesis Classifications

### 3.1 Adopt / Adapt from `cosmii02/pelican-modpackmanager` (MIT License — Attribution Required per §6)

> **License & Credit Compliance (`ORIGINAL_REQUEST.md` §6)**: `cosmii02/pelican-modpackmanager` (`https://github.com/cosmii02/pelican-modpackmanager`, commit `ddc2e364427c703e565b59f9533465e6b1090543`) is licensed under the **MIT License** (`Copyright (c) 2026 cosmii02`), which is one-way compatible with `pelican-mod-manager`'s **GPL-3.0**. For every design, workflow, or adapted technique below, add a credit entry to `README.md` (`## Credits & acknowledgements`), `README.ja.md` (`## クレジット・謝辞`), and `docs/modpack-compatibility-audit.md` citing project name, URL, MIT license, and the exact designs referenced (matching the existing `JoanFo1456/resources` credit style). If any code is adapted directly, include MIT copyright/permission notice per MIT terms.

1. **Pre-send Wings HTTP retry (`RetryingDaemonFileRepository` pattern)**: Retry Wings calls on pre-send cURL transport errors (`cURL error 6/7/35` and `cURL error 28: Resolving/Connection timed out`) with `[500, 2000, 5000]` ms delays — safe even for non-idempotent renames/deletes; extend with retries on idempotent read operations (`listDirectory`, `readStream`) and upstream CDN downloads (`429`/`5xx`/connection errors).
2. **Bounded Panel ZIP bundling + Wings `decompressFile()` for bulk small files / verified payloads (`installFilesViaBundles` + `uploadToWings` pattern — HARDENED)**:
   - Fetch small remote files (e.g., FTB configs/datapacks, Modrinth/CF mods ≤ threshold, and pre-verified Server Pack entries/overrides) concurrently on Panel (`Http::pool` bounded concurrency with SSRF/redirect check + SHA-1/256/512 verification in-stream!), pack into bounded ZIP parts (`<= min(64 MiB, node.upload_size * 0.9)`) with explicit `0644`/`0755` Unix permissions, upload via signed `NodeJWTService` `/upload/file` (**always enforcing TLS verification**, unlike `cosmii02`), extract into a temporary staging directory `.mod-manager-stage-{token}/` via Wings `decompressFile()`, then batch-rename into live paths!
   - Eliminates both the 48,200-call FTB bottleneck AND pre-`b32b813a` Wings `Content-Length` failures on `files.feed-the-beast.com`/`dist.modpacks.ch` AND Wings→Panel read-backs!
3. **Batched Wings `renameFiles()` / `deleteFiles()` (up to 200 entries/call)** (`moveExtractedIntoRoot` batching pattern) instead of 1 HTTP call per file.
4. **Concurrent bounded Wings `pull()` + `GET /api/servers/{uuid}/files/pull` status polling** for large files (`> threshold`, e.g., huge mods when Wings pull is preferred) — without `cosmii02`'s `min(3, ...)` clamp bug, and respecting Wings' simultaneous-download limit backoff (`isRemoteDownloadLimitError`).
5. **Cooperative in-flight cancellation (`InstallCancelledException` pattern)**: Add a cancellation exception + cooperative cancel signal checked in `$guard`, Guzzle `progress` callbacks (every 0.5–1s), and batch loops, triggering clean staging cleanup / transactional rollback and truthful `'cancelling'` state.
6. **Existing-server Egg/loader/MC switching & reinstall (`EggChangerService(..., keepOldVariables: false)` + `skip_scripts = false` + `ReinstallServerService` wait loop)** — **ONLY using Eggs already installed on the Panel** (never auto-importing from GitHub!), gated on explicit user/admin confirmation + server stopped + backup, resetting stale loader-version variables when unknown, and finishing with Target's deep `ModpackRuntime::assertInstalled()`.
7. **Unlimited-memory Fabric `-Xmx0M` startup protection**: Detect `$server->memory === 0` with `-Xmx{{SERVER_MEMORY}}M` in Egg default startup and replace with `-XX:MaxRAMPercentage=95.0` (matching official Forge/NeoForge/Quilt Eggs!).
8. **CurseForge `classId` routing (`getModClasses`)**: Batch-resolve `POST /mods` for CurseForge client-pack dependencies to route datapacks (`classId=6945` or `12`+`categoryId=5193`) → `datapacks/`, resourcepacks (`12`) → `resourcepacks/`, and skip client-only shaders (`6552`), instead of dumping all into `mods/`.
9. **Multi-level wrapper directory unwrapping (up to 3 levels)** (`locateExtractedContentRoot` concept) + **read-only metadata extraction** from `*-installer.jar` filenames & `startserver.sh`/`run.sh` (`parseLauncherSh` / `detectInstallerJarMeta` read-only — NEVER executing them!) when a CurseForge Server Pack has neither `variables.txt` nor `manifest.json` (and fixing `mcFromNeoforge()` for MC `26.x`!).
10. **CurseForge `alternateFileId` server-pack linkage** (API-only, verifying `modId` & `isServerPack`, no HTML scraping) and **CurseForge-hosted FTB installer pack detection** (`rerouteFtbInstallerPack` concept: detect `FTB-Server-Installer` + `pack_id`/`version_id` in `install.sh` and reroute to verified `FtbModpackSource` or fall back cleanly).
11. **Zero-byte remote file direct write (`putContent('', ...)` / bundle inclusion)** so 0-byte FTB files (like `config/ironfurnaces.toml` in StoneBlock 3) don't fail Wings `pull()`.
12. **Safe key-level `server.properties` operational merge + `level.dat` world preservation** (for optional clean-install pack switching) + **FTB empty-query popular browse** (`/popular/installs/{limit}`) + **optional scheduled modpack update check** (`UpdateCheckService` concept, keeping Target's Filament-native UI untouched except minimal status/notification if desired).
13. **Mode-`000` permission safety**: When Panel builds staging ZIPs, explicitly stamp `0644`/`0755` Unix modes in ZIP external attributes; if ever extracting an upstream ZIP on Wings directly, run Wings-native `chmodFiles()` repair (`repairExtractedPermissions` pattern).

### 3.2 Preserve in `pelican-mod-manager` (Superior — Must NOT Regress)

1. **Full cryptographic integrity verification**: SHA-256 + SHA-1 + SHA-512 + MD5 + exact byte size + ZIP/JAR structure checks (`ModpackDeployment::inspect`, `ModpackInstaller`, `CurseForgeManualFiles`, `FtbReleaseManifest`, `CurseForgeFingerprint`).
2. **Transactional staging, verified reverse rollback, and durable crash recovery journal**: `mod_manager_modpacks` DB table (`ModpackInventory`), `ModpackDeployment` temp-staging + `originals` journal + backup locking + `confirmRestored()` cryptographic recovery verification.
3. **Cryptographic file ownership & user-data preservation**: `ModpackDelta` preserving user-modified configs, individual catalog-installed mods (`managed_by === 'catalog'`), protected world/operator files (`ModpackPaths::isManaged`), and retiring stale executable pack files (`mods/`, `kubejs/server_scripts/`, `scripts/`, `datapacks/`, etc.).
4. **CurseForge restricted-file manual upload workflow**: 7-day pause/resume, `archive_sha256` pinning, `.mod-manager-curseforge/{pid}-{fid}.jar` hash+JAR verification, and `required: false` optional dependency handling.
5. **Modrinth `server-overrides/` precedence over `overrides/`** and exclusion of `client-overrides/` (`ModpackArchive::fromModrinth`).
6. **HTTP Range-based CurseForge ZIP metadata peeking** (`CurseForgeManifestReader`) avoiding 600 MB downloads during catalog browsing/planning.
7. **Admin New Server Creation Wizard integration** (`ModpackCreationStep`, `QueueCreatedServerModpack`).
8. **Hub-safe, queue-driver-independent background job execution**: `mod_manager_background_jobs` table drained by `schedule:run` (`ProcessBackgroundJobsCommand`, `PluginBackgroundRunner`, `DatabaseBackgroundJobQueue`) with epoch fencing & leases (no `queue:work` restart trap, no `DB::table('jobs')` lock-in).
9. **Deep post-reinstall runtime verification (`ModpackRuntime::assertInstalled`)** and **Java min + max bounds (`JavaRuntimeImage`)** plus **NeoForge 1.20.1 coordinate handling (`ModpackLoader`)**.
10. **Strict Egg compatibility engine (`EggCompatibilityEngine` + `resources/egg-profiles.json`)** with confidence/contradiction checks and zero Egg DB mutations.
11. **Technic provider support** with official Solder MC resolution and explicit Egg bindings (`TechnicModpackSource`, `TechnicEggBinding`).
12. **FTB `mirrors[]` fallback URLs** and **`SKIPPABLE_NON_SERVER_DIRS`** (`shaderpacks/`, etc.).
13. **Server-stopped enforcement (`assertStopped`)** and **`.pelicanignore`-immune full backups** (`# Full modpack update backup` in `ModpackUpdateWorkflow`).
14. **Granular authorization (`ProjectOperationAuthorizer`, `isRootAdmin()`, per-server overrides)**, **strict security validation** (zip-slip, symlinks, archive bombs, no untrusted script execution), **Filament-native UI (§5)**, and **729-test PHPUnit suite**.

### 3.3 Fix Shared Weaknesses (Both Repos Have Gaps)

1. **CurseForge client-pack client-only mod exclusion**: Neither repo excludes client-only JARs when assembling a CurseForge client ZIP (`manifest.json`), causing dedicated server boot crashes on client-only mods (e.g., Sodium/Oculus/Iris/Embeddium/Zoomify). **Fix**: Skip `classId === 6552` shaders AND inspect authoritative JAR-internal metadata (`fabric.mod.json` `environment === 'client'`, `quilt.mod.json` `minecraft.environment === 'client'`, `META-INF/(neo)?mods.toml` `clientSideOnly = true`) during Panel staging (zero false-positive name guessing!), logging every skipped client-only mod clearly.
2. **Modrinth `downloads[1..n]` mirror fallbacks ignored**: Both repos only use `downloads[0]` from `modrinth.index.json`. **Fix**: Pass `array_slice($httpsUrls, 1)` into `ModpackRemoteFile::$fallbackUrls`.
3. **CurseForge server-pack discovery pagination**: Both repos only scan the first 50 files of `/mods/{id}/files` when looking for child server packs (`parentProjectFileId`). **Fix**: Check `serverPackFileId`, then `alternateFileId` (via `getFile` verifying `modId` + `isServerPack`), and paginate `/mods/{id}/files` (bounded, e.g. up to 3 pages or filtered by `gameVersion`) when selecting an older release.
4. **Panel-side SSRF hardening on downloads/redirects**: Neither repo resolves DNS + blocks private/reserved IPs on every redirect hop for all Panel-side HTTP file downloads (`InstallModpack.php:179` follows redirects with only `'protocols' => ['https']`; `cosmii02` skips SSRF checks everywhere except icon sync). **Fix**: Add a shared `SafeHttpDownload` helper verifying DNS A/AAAA resolution + public IP + HTTPS on initial URL and every `on_redirect` hop (matching Wings' `downloader.go` rigor).
5. **Missing real-world server-pack directories**: `pelican-mod-manager`'s `MANAGED_ROOT_DIRECTORIES` omits `global_data_packs/`, top-level `paxi/`, `flan/`, and `local/ftb*/` used by popular modpacks; `cosmii02`'s `SERVER_ROOT_FOLDERS` omits `configureddefaults`, `global_packs`, `openloader`, `paxi`, `groovy`, `bansoukou`. **Fix**: Audit and expand `ModpackPaths::MANAGED_ROOT_DIRECTORIES` safely (still blocking worlds, root binaries/scripts, and protected files).
6. **FTB `curseforge: {project, file}` fallback & `>1`-page catalog browse**: `pelican-mod-manager` ignores FTB `curseforge` refs and requires 4+ char search (max 6 results); `cosmii02` ignores FTB `mirrors[]` and downloads `shaderpacks/`. **Fix**: Combine both — support FTB `url` → `mirrors[]` → `curseforge {project, file}` (via `CurseForgeSource` + manual upload fallback if restricted!), skip `shaderpacks/`, and allow browsing FTB popular packs with `<4` char / empty queries.
7. **CurseForge `dependencyFiles()` missing `/download-url` fallback**: `pelican-mod-manager`'s `CurseForgeSource::dependencyFiles()` doesn't try `/download-url` when `downloadUrl` is omitted on an allowed mod (`allowModDistribution === true`), whereas `modpackZipDownload()` does. Add targeted fallback when `allowModDistribution === true`.
8. **Per-file live progress visibility & cancellation**: `pelican-mod-manager` lacks in-flight cancel and `X / Y` progress; `cosmii02` lacks pause/resume and hammers DB JSON on every log line. **Fix**: Add coalesced `InstalledOperationManager::progress($done, $total)` + cooperative cancellation flag in `pelican-mod-manager`.

### 3.4 Explicitly Rejected Designs from `cosmii02/pelican-modpackmanager`

1. **Executing pack-shipped shell scripts (`bash start.sh`, `bash run.sh nogui`, `bash startserver.sh`)** (`ModpackInstallService.php:1654-1757, 2285-2316`): Arbitrary code execution risk inside server containers, breaks Pelican's managed stop/restart lifecycle (and `startserver.sh` even runs auto-restart loops contradicting `NOTES.md:239`), and overwrites pack-authored JVM args in `user_jvm_args.txt`.
2. **Auto-downloading & importing Eggs from mutable GitHub `refs/heads/main`** (`2734-2809`): Supply-chain attack vector (upstream branch drift/compromise injects arbitrary install scripts into the Panel!) and bypasses admin Egg governance.
3. **Mutating shared Panel Eggs in DB (`prepareOfficialQuiltEgg` rewriting `script_install` & adding `EggVariable`s; mutating `$egg->tags`)** (`1890-1911, 2665-2726`): Silent global side effect affecting all other servers sharing that Egg during a single server's install.
4. **Reconstructing `https://edge.forgecdn.net/files/...` URLs when `downloadUrl` is null** (`CurseForgeService.php:596-599, 676-682`): Violates CurseForge `allowModDistribution=false` opt-out / API terms and fails unpredictably instead of offering verified manual upload.
5. **HTML scraping CurseForge `/additional-files` pages & substring `"server"` filename guessing** (`CurseForgeService.php:799-866`): Brittle against Cloudflare/markup changes and risks picking a non-server or wrong-version archive.
6. **Deleting server files & loader runtimes BEFORE downloading/validating the new pack, with zero rollback** (`ModpackInstallService.php:96-105, 730-797`): Leaves the server destroyed if the network, API, or cancellation aborts the install.
7. **Skipping checksum verification on Modrinth / CurseForge / ATLauncher / >1 MiB FTB downloads**: Risks silent corruption, truncated JARs, or HTML error pages placed into `mods/`.
8. **Blindly moving extracted archives into `/` without path-traversal, symlink, or archive-bomb checks** (and ignoring Modrinth `server-overrides/` while leaving `client-overrides/` on disk).
9. **Disabling TLS verification in `uploadToWings()` outside `production`** (`ModpackInstallService.php:3629`).
10. **Reading `DB::table('jobs')` and calling `@unserialize()` + `ShouldQueue` `$tries = 0` hack** (`ModpackBrowserPage.php:855-885`, `InstallModpackJob.php:26`): Breaks on Redis/SQS/Horizon/sync queue drivers and requires manual `queue:work` restarts + custom 3660s worker timeouts.
11. **Half-baked ATLauncher installer** (`ATLauncherService.php:176-206`): Silently drops `type=extract` bundles, silently skips `download=browser` required mods, and installs all conflicting `optional=true` mods.
12. **Custom Command-Deck CSS / UI (`modpack-browser-page.blade.php`)**: Strictly prohibited by `ORIGINAL_REQUEST.md` §5; preserve `pelican-mod-manager`'s Filament-native UI.
13. **Monolithic 3,845-line service & zero tests**: Maintain `pelican-mod-manager`'s modular service/support architecture and PHPUnit regression suite.

### 3.5 Concrete Architectural & Implementation Improvement Proposals (Prioritized)

All proposals strictly obey `ORIGINAL_REQUEST.md` §5 (keep Filament-native UI; minimal UI changes only where essential for internal improvements) and §8 (zero Pelican Panel / Wings core edits, zero extra daemons).

#### Priority P0 — Boot Reliability & Large-Pack Scalability (Highest Impact)
1. **Redesign `ModpackDeployment` Staging & Transfer Pipeline (100×–1,000× Fewer Wings Calls, Zero Redundant Read-Backs)**:
   - **Panel-staged bounded ZIP bundles (`ModpackBundleUploader`)**:
     - For Server Pack ZIP entries, Modrinth/CurseForge overrides (streamed directly from `ZipArchive` instead of buffering 32 MiB in PHP RAM — removing the 2 MiB silent-skip bug!), and small/FTB/gzip-prone remote files (e.g., all FTB files `<= 4 MiB` and any `files.feed-the-beast.com`/`dist.modpacks.ch` text/JSON/zero-byte files, plus Modrinth/CF mods):
     - Download remote files concurrently on Panel via bounded `Http::pool` (e.g., concurrency 8–16, streaming to temp files for large files so PHP RAM stays <32 MiB!) with SSRF/redirect validation, exponential retry (3 attempts), and **single-pass SHA-256/SHA-1/SHA-512/MD5 + size + ZIP/client-only verification right on Panel**!
     - Pack verified staged files into bounded ZIP parts (`<= min(64 MiB, node.upload_size * 0.9)`) with explicit Unix permissions (`0644` files / `0755` dirs) and relative paths under `.mod-manager-stage-{token}/...`.
     - Upload each ZIP part to Wings via signed `NodeJWTService` `/upload/file` (using Wings' configured TLS/HTTP client — NEVER disabling TLS verify!), extract via `decompressFile('/', $partName)`, and delete the part archive.
     - **Eliminate `inspect()` read-back** for files whose SHA-256 was already verified on Panel before bundling (verify Wings file existence + exact byte size via batched `listDirectory()` — zero full-file read-back over HTTP!).
     - For very large individual files exceeding the node upload limit, fall back to bounded concurrent Wings `pull()` (polling `GET /api/servers/{uuid}/files/pull` with heartbeat every 30s and Wings-limit backoff) + single-pass `inspect()`.
   - **Batch `renameFiles()` & `deleteFiles()`** in `WingsRemoteFilesystem` (chunks of 100–200) for both apply (`prev` backup moves + `stage → live` moves) and rollback/cleanup.
   - **Eliminate 3× full live-file read-backs on updates**: Store `(size, mtime)` alongside `sha256` in `mod_manager_modpacks` inventory (just like `InstalledProjectService`'s incremental hash scan already does!). During `plan()` and `recheck()`, only call `readStream()` to re-hash files whose Wings `(size, modified_at)` changed since last commit (still verifying full SHA-256 for any file that WILL be replaced or deleted, or whose `(size, mtime)` differs).
   - **Fix ZIP download storage & timeout**: Store temporary downloads under `storage_path('app/private/mod-manager-tmp/')` (avoiding tiny `/tmp` `tmpfs`!) with a configurable streaming stall timeout (e.g., 60s idle read timeout instead of 180s total wall-clock cap so 1 GB packs succeed on 20 Mbps connections while stalled connections fail fast!).
2. **Add Pre-Send Retry Resilience to `WingsRemoteFilesystem` & Upstream Downloads**:
   - Wrap Wings HTTP client calls in `WingsRemoteFilesystem` with pre-send retry (`cURL 6/7/35` & `28 Resolving/Connection timed out`, delays `[500, 2000, 5000]` ms) — adapted from `cosmii02`'s `RetryingDaemonFileRepository` — plus idempotent retries for `listDirectory()`/`readStream()` on transient 502/503/504/connection drops.
3. **CurseForge Client-Pack Correctness (`classId` Routing + Authoritative Client-Only Exclusion)**:
   - In `CurseForgeSource::dependencyFiles()`, batch-fetch `POST /mods` for ALL unique `projectId`s (not only missing-URL ones!) to read `classId` and `categories`.
   - Route `classId === 6945` (or `12` with `categoryId === 5193`) → `datapacks/`, `classId === 12` → `resourcepacks/`, **skip `classId === 6552` (Shaders)**, and `default` → `mods/`.
   - During Panel-side staging of CurseForge client-pack mod JARs, inspect authoritative JAR metadata (`fabric.mod.json` `environment === 'client'`, `quilt.mod.json` `minecraft.environment === 'client'`, `META-INF/(neo)?mods.toml` `clientSideOnly = true`) and skip client-only JARs with explicit warnings.
   - Also allow `.zip` manual uploads for restricted CurseForge datapacks/resourcepacks and add `/download-url` fallback when `allowModDistribution === true`.
4. **Fix Unlimited-Memory Fabric Startup (`-Xmx0M`) & Stale Reservation Race**:
   - When configuring startup on new or migrated servers with `$server->memory === 0`, replace `-Xmx{{SERVER_MEMORY}}M` with `-XX:MaxRAMPercentage=95.0` (matching official Forge/NeoForge/Quilt Eggs).
   - Add heartbeat renewal inside all long transfer loops (bundling, pull polling, ZIP downloads) so `DatabaseBackgroundJobQueue` never reclaims an active 10+ min modpack job, and raise `withoutOverlapping()` / `STALE_RESERVATION_MINUTES` alignment appropriately.

#### Priority P1 — Existing-Server Usability, Loader Updates & Pack Compatibility
5. **Enable Controlled Existing-Server Loader/Egg Migration & Reinstall**:
   - Replace/refactor dead `ServerEggMigration.php` (fixing its `keepOldVariables: true` bug to `keepOldVariables: false`!) and wire it into `ModpackInstallCoordinator` + `ModpackUpdateWorkflow` for **already-installed** Panel Eggs when:
     - Same Egg needs updated `MC_VERSION` / `FORGE_VERSION` / `NEOFORGE_VERSION` / `LOADER_VERSION` + Java image + reinstall (solving same-pack updates that bump loader builds!), OR
     - Switching to a different already-installed compatible Egg (gated on appropriate authorization, explicit confirmation, server stopped, full backup created first!).
   - Reset stale `FORGE_VERSION` / `NEOFORGE_VERSION` to Egg default when target pack has unknown build; force `skip_scripts = false`; wait for reinstall completion (with heartbeats + cancel check); verify via `ModpackRuntime::assertInstalled()` before committing files!
6. **Add Optional Clean-Install Pack-Switch Mode + Safe `server.properties` Merge**:
   - Allow operators switching to a different modpack (or adopting an untracked server) to opt into a clean managed-files wipe *after* full backup and staging verification, preserving worlds (detected via `server.properties` `level-name` + `level.dat`), player/admin JSONs, `eula.txt`, and optionally merging only safe operational `server.properties` keys.
7. **Cooperative In-Flight Cancellation & Coalesced Per-File Progress**:
   - Add a `ModpackCancelledException` + cache/DB cancel flag checked in `$guard`, Guzzle `progress`, and batch staging loops, wiring clean rollback + minimal cancel action on active modpack state (respecting §5 UI constraints).
   - Call `InstalledOperationManager::progress($done, $total)` during staging/apply so users see real progress on 500–17,000 file packs.
8. **Expand Server-Pack & Provider Compatibility**:
   - CurseForge: Add `alternateFileId` lookup (API-only, verifying `modId` + `isServerPack`), bounded pagination for child server packs, up-to-3-level wrapper unwrapping, read-only metadata fallback from `*-installer.jar` / `startserver.sh` / `run.sh` (handling MC `26.x` NeoForge properly!), `RECOMMENDED_JAVA_VERSION` (clamped by `maximumJava`), and FTB-on-CurseForge `install.sh` rerouting to `FtbModpackSource`.
   - FTB: Support `$file['curseforge']` `{project, file}` fallback resolution when `url`+`mirrors` are absent, handle 0-byte files directly, allow empty/short search queries via `/popular/installs/{limit}` (raising 6-hit cap to `PAGE_SIZE = 20`), and expand `ModpackPaths::MANAGED_ROOT_DIRECTORIES` carefully (`global_data_packs`, `paxi`, `flan`, `local/ftb*`).
   - Modrinth: Preserve `downloads[1..n]` as `fallbackUrls`.

#### Priority P2 — Hardening, Maintainability & Polish
9. **Panel-Side SSRF Guard on All HTTP Downloads**: Add DNS-resolving + `on_redirect` public-IP enforcement to `InstallModpack` archive downloads and Panel bundle fetchers.
10. **Clean Up Dead Code & Stale Docs**: Either wire the fixed `ServerEggMigration` into existing-server migration or remove it completely; update stale `docs/modpack-updates.md` FTB limits (`8 MiB / 2,000` → `24 MiB / 20,000`); add MIT credit attribution for `cosmii02/pelican-modpackmanager` in `README.md`, `README.ja.md`, and `docs/modpack-compatibility-audit.md`.
11. **Optional (Post-Core)**: Evaluate proper ATLauncher installer support *only* if `type=extract`, `optional` exclusion, `browser` manual-step/abort, and `Configs.zip` SHA-1 verification are fully implemented; evaluate scheduled modpack update notifications (`UpdateCheckService` pattern).

---

## 4. Caveats

1. **Authenticated CurseForge Core API Not Live-Tested**: This environment has no `MOD_MANAGER_CURSEFORGE_API_KEY`; CurseForge API behaviors (`serverPackFileId`, `alternateFileId`, `allowModDistribution`, `POST /mods/files`) were verified via source code of both repos, official CurseForge Core API schema semantics, and prior audit records — not via live authenticated HTTP calls.
2. **Read-Only Audit (No Live Wings Execution)**: Per read-only explorer constraints, no live Panel+Wings container deployment or benchmark was executed in this step; Wings `downloader.go` behavior and commit `b32b813a` were verified directly against `pelican-dev/wings` source via `gh api`, and FTB/ATLauncher/Fabric API responses were verified live via read-only `curl`.
3. **Pelican Panel `NodeJWTService` / `EggChangerService` Signatures**: Verified against `cosmii02`'s usage (`NodeJWTService::setScopes(NodeJwtScope::FileUpload)`, `EggChangerService::handle($server, $egg, keepOldVariables: false)`) and `pelican-mod-manager`'s `ServerEggMigration.php`; downstream implementers MUST verify exact method signatures against the pinned `pelican-dev/panel` harness during PHPStan + PHPUnit runs.
4. **UI Audit Scope Boundary**: Per the 3-way Step 0 Survey split, UI defects (Modrinth tile wrapping, Modpack catalog initial view) and non-modpack core internal audits are handled by peer survey explorers; this report touches UI only where directly tied to modpack workflows and §5 constraints.

---

## 5. Conclusion

`pelican-mod-manager` already possesses a far safer, cleaner, better-tested modpack architecture than `cosmii02/pelican-modpackmanager` — its transactional staging/rollback, crash-recovery journal, cryptographic integrity checks, CurseForge manual upload flow, Modrinth `server-overrides/`, Java min/max validation, deep `ModpackRuntime` verification, and queue-independent scheduler runner must all be **preserved**.

However, for real-world modpacks to **reliably install and boot cleanly at scale**, implementation should immediately address four high-impact gaps surfaced by this audit:
1. **Replace serial per-file Wings `pullForeground`/`putStream` + full `readStream` read-backs** with Panel-verified bounded ZIP bundling + Wings `decompressFile()` + batched `renameFiles()`/`deleteFiles()` + `(size, mtime)` incremental live-file checks (eliminating 100×–1,000× HTTP overhead, pre-`b32b813a` Wings `Content-Length` failures on FTB, and 3× live read-backs).
2. **Add pre-send Wings retries (`RetryingDaemonFileRepository` pattern)** and **cooperative in-flight cancellation + progress reporting**.
3. **Fix CurseForge client-pack `classId` routing, authoritative JAR-metadata client-only mod exclusion, `-Xmx0M` Fabric startup, FTB 0-byte/CurseForge-ref handling, and multi-level/installer-metadata server-pack detection**.
4. **Enable controlled existing-server Egg/loader-variable updates + reinstall** (using already-installed Panel Eggs only, fixing dead `ServerEggMigration`) so users can actually update modpacks across loader-build bumps without manual admin intervention.

All adopted designs from `cosmii02/pelican-modpackmanager` (MIT) must be properly credited in `README.md`, `README.ja.md`, and `docs/modpack-compatibility-audit.md`, while strictly rejecting its unsafe patterns (pack script execution, mutable-branch Egg import, Quilt Egg DB mutation, `edge.forgecdn.net` reconstruction, HTML scraping, pre-download wiping, TLS bypass, and custom UI).

---

## 6. Verification Method

An independent verifier can reproduce every observation and source citation in this report with the following checks:

1. **Verify exact repository SHAs**:
   ```bash
   git -C <repo-root> rev-parse HEAD
   # expect: 4e5f839a4d8ede672565b430995e5ee5655167dd
   git -C cosmii02/pelican-modpackmanager (commit ddc2e364, MIT) rev-parse HEAD
   # expect: ddc2e364427c703e565b59f9533465e6b1090543
   ```
2. **Inspect key target code flaws directly**:
   - Serial `pullForeground`/`putStream` + full `readStream` read-back + 3× `plan()`/`recheck()` + serial `move()`: `src/Services/ModpackDeployment.php:134-208, 322-335, 530-639`
   - Zero Wings retries: `grep -rn 'retry' src/Support/WingsRemoteFilesystem.php src/Services/ModpackDeployment.php src/Jobs/InstallModpack.php` (expect empty)
   - CurseForge deps hardcoded to `mods/`: `src/Services/ModpackInstaller.php:278`
   - In-RAM 2 MiB/32 MiB override limit: `src/Support/Modpacks/ModpackArchive.php:17-21, 455-473`
   - Existing-server loader-version/Egg block + dead `ServerEggMigration`: `src/Services/ModpackInstallCoordinator.php:60-89` and `grep -rn 'ServerEggMigration' src/ tests/`
   - FTB `>=4` char / 6-result search & ignored `curseforge` refs: `src/Sources/FtbModpackSource.php:37, 49` and `src/Support/Modpacks/FtbReleaseManifest.php:258-271`
3. **Inspect key reference strengths & bugs directly**:
   - Pre-send Wings retry: `cosmii02/pelican-modpackmanager:src/Repositories/RetryingDaemonFileRepository.php:15-32`
   - Panel ZIP bundling & TLS verify bug: `cosmii02/pelican-modpackmanager:src/Services/ModpackInstallService.php:3448-3643` (TLS bug at `:3629`)
   - `min(3, ...)` concurrency clamp bug: `ModpackInstallService.php:3209`
   - `mcFromNeoforge('26.x')` bug: `ModpackInstallService.php:2571-2577`
   - Missing Modrinth `server-overrides/`: `ModpackInstallService.php:3149-3161`
   - `startserver.sh` contradiction vs `NOTES.md:239`: `ModpackInstallService.php:1636, 1689, 2287, 2308`
   - Mutable Egg import & Quilt DB mutation: `ModpackInstallService.php:2665-2809`
   - `DB::table('jobs')` lock-in & missing `authorizeManage()` on cancel: `src/Filament/Server/Pages/ModpackBrowserPage.php:855-885, 1162-1224`
4. **Verify live external facts**:
   - Wings `b32b813a`: `gh api repos/pelican-dev/wings/commits?path=router/downloader/downloader.go\&per_page=5`
   - Official Eggs: `gh api repos/pelican-eggs/minecraft/contents/java/{fabric/egg-fabric.yaml,forge/egg-forge-minecraft.yaml,neoforge/egg-neo-forge.json,quilt/egg-quilt.yaml}`
   - Live FTB Evolution 2 (17,747 files, MC 26.1.2) & StoneBlock 3 (0-byte file, gzipped JSON without `Content-Length`): `curl -sSL https://api.feed-the-beast.com/v1/modpacks/public/modpack/135` and `.../modpack/100/100638`.
5. **Invalidation conditions**: Upstream updates to `pelican-eggs/minecraft` (e.g., adding `QUILT_LOADER_VERSION` or fixing Fabric `-Xmx0M` / Forge `26.x` regex), new commits to either repo, or downstream changes to `pelican-mod-manager`'s modpack architecture supersede the corresponding observations.
