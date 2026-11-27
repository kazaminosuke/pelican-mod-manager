> **Handoff Reference Artifact (WIP — Temporary Planning Archive)**  
> This document preserves verified research / design findings from the prior multi-agent session so subsequent sessions on `fix/modpack-wizard-spigot` do not need to repeat the same investigation.  
> See [`docs/handoff/HANDOFF.md`](HANDOFF.md) for the master handoff summary and next-session workflow. Remove or consolidate `docs/handoff/` once Milestones M2–M5 are completed and merged into permanent `docs/*.md`.

# Project: pelican-mod-manager — UI Fixes, Modpack Comparative Audit & Internal Improvements

- **Repository**: `https://github.com/kazaminosuke/pelican-mod-manager` (`<repo-root>`)
- **Branch / PR**: `fix/modpack-wizard-spigot` (PR #2, base commit `4e5f839a4d8ede672565b430995e5ee5655167dd`, 78 commits ahead of `origin/main` `a3352733c1266bfef353f88854e35c1ad514f287`)
- **Reference Repository (Read-Only)**: `cosmii02/pelican-modpackmanager (commit ddc2e364, MIT)` (`https://github.com/cosmii02/pelican-modpackmanager` @ `ddc2e364427c703e565b59f9533465e6b1090543`, v1.7.3, MIT License `Copyright (c) 2026 cosmii02`)
- **Original Request**: `docs/handoff/HANDOFF.md (original request scope)`
- **Survey Evidence Artifacts**:
  - `docs/handoff/HANDOFF.md`
  - `docs/handoff/core-infrastructure-audit.md`
  - `docs/handoff/comparative-audit-cosmii02.md`
  - `docs/handoff/external-specs-and-docs-reeval.md`

---

## Architecture

### System Overview & Module Boundaries
`pelican-mod-manager` is a standalone Pelican Panel plugin (GPL-3.0, PHP 8.4/8.5, Filament 5.11.4, Laravel 13, no standalone `composer.json`/`vendor/`, tested inside a `pelican-dev/panel` harness). Its core boundaries are:
1. **UI / Presentation Layer (`src/Filament/`, `src/Support/CatalogTileColumns.php`, `resources/views/components/`, `resources/assets/`)**:
   - Strictly Filament-native UI (`ORIGINAL_REQUEST.md` §5). Zero custom design systems, zero ad-hoc CSS fixes, zero copying of `cosmii02/pelican-modpackmanager`'s UI.
   - `CatalogTileColumns` defines shared list/tile view preference persistence and Filament `Stack`/`Split` tile layouts across `ModManagerPage` (Mod/Plugin/Datapack/ResourcePack) and `ModpackCatalogTable` (Create Wizard & Existing-Server Modal).
2. **Core Operations, Queue & Shared Infrastructure (`src/ModManagerPlugin.php`, `src/Support/{UpstreamHttp,SourceCache,WingsRemoteFilesystem,DatabaseBackgroundJobQueue,BackgroundJobRuntime,ModManagerAssets}.php`, `src/Services/{InstalledProjectService,InstalledOperationManager,InstalledArchiveTransaction,InstalledProjectUpdateService,ResourcePackService,ModManagerDiagnostics}.php`, `src/Sources/*`, `src/Jobs/*`, `src/Console/Commands/*`, `src/Providers/ModManagerServiceProvider.php`)**:
   - Queue-driver-independent background job execution via `mod_manager_background_jobs` drained by `mod-manager:process-jobs` (no `queue:work` restart traps, no `DB::table('jobs')` lock-in).
   - Centralized HTTP client (`UpstreamHttp`) with descriptive `User-Agent`, gzip compression, bounded idempotent retries, and SWR caching (`SourceCache`).
   - Centralized Wings transport (`WingsRemoteFilesystem`) adding safe pre-send cURL transport retries (`cURL 6/7/35` & `28 Resolving/Connection timed out`), idempotent read retries, explicit `Content-Length` on `/files/write`, `/files/decompress`, and batched `/files/rename` + `/files/delete`.
3. **Modpack Metadata, Compatibility & Runtime Verification Layer (`src/Services/{ModpackCatalog,ModpackRuntime,ModpackProvisioningPlanner}.php`, `src/Support/Modpacks/*`, `src/Support/JavaRuntimeImage.php`, `src/Support/Compatibility/EggCompatibilityEngine.php`, `src/Sources/{FtbModpackSource,TechnicModpackSource,AtlauncherModpackSource,CurseForgeSource}.php`)**:
   - Non-guessing metadata extraction (HTTP Range ZIP reading for both CurseForge ZIPs and Modrinth `.mrpack` archives with allowlisted HTTPS redirects + SSRF protection; FTB manifest with `mirrors[]` + `curseforge` fallback + expanded safe server directories; read-only server-pack installer/launcher metadata fallback; `RECOMMENDED_JAVA_VERSION` clamped by `JavaRuntimeImage::maximumJava()`).
   - CurseForge client-pack `classId` destination routing (`mods/` vs `datapacks/` vs `resourcepacks/`, skipping client-only shaders `6552`) + authoritative JAR-internal client-only exclusion (`fabric.mod.json`, `quilt.mod.json`, `META-INF/(neo)?mods.toml` — zero heuristic name guessing).
   - Post-Egg runtime verification (`ModpackRuntime::assertInstalled()`) supporting unobfuscated Minecraft `26.1+` Fabric (no `intermediary` JAR) and dynamic `serverJar=` from `fabric-server-launcher.properties`.
   - Strict Egg rule validation (catching NeoForge `-beta` regex rejection, `MC_VERSION` `max:9`, Quilt Egg missing `QUILT_VERSION`, Forge Egg MC `26.x` `unix_args.txt` gap) + unlimited-memory Fabric `-Xmx0M` → `-XX:MaxRAMPercentage=95.0` startup protection.
4. **Modpack Scalable Deployment, Cancellation & Controlled Existing-Server Migration (`src/Services/{ModpackDeployment,ModpackInstaller,ModpackInstallCoordinator,ModpackUpdateWorkflow,ModpackInventory,ServerEggMigration,ModpackBundleUploader,SafeHttpDownload,ServerPropertiesMerger}.php`, `src/Exceptions/ModpackCancelledException.php`, `src/Jobs/InstallModpack.php`)**:
   - Replaces serial 3N Wings `pullForeground → readStream → move` + 3× full live-file read-back with:
     - Panel-side concurrent bounded downloads (`SafeHttpDownload` with DNS+redirect public-IP SSRF protection, retries, stall timeout, temp storage under `storage_path('app/private/mod-manager-tmp/')`),
     - Single-pass cryptographic verification on Panel (SHA-256/SHA-1/SHA-512/MD5 + size + ZIP/JAR checks + CF client-only JAR filter),
     - Bounded staging ZIP bundling (`<= 64 MiB` parts with explicit `0644`/`0755` Unix modes, including 0-byte files and streamed overrides without 2 MiB RAM truncation!),
     - Authenticated Wings `/files/write` (`putStream` with explicit `Content-Length`, strict Panel TLS, no `upload_limit` ceiling, immune to `disable_remote_download: true` and HTTP proxy limits!) → Wings `/files/decompress` into `.mod-manager-stage-{token}/` → batched `listDirectory()` size verification (eliminating Wings→Panel read-back!) → batched `renameFiles()`/`deleteFiles()` (up to 200/call),
     - Incremental `(size, modified_at)` fast-path on `mod_manager_modpacks` live-file ownership ledger,
     - Full transactional rollback, backup locking, and crash-recovery journal preserved intact.
   - Cooperative in-flight cancellation (`ModpackCancelledException`) + coalesced per-file progress + heartbeat renewal inside all long loops.
   - Controlled existing-server Egg/loader variable migration & reinstall (fixing dead `ServerEggMigration`: `keepOldVariables: false`, `skip_scripts = false`, sane daemon timeout, already-installed Eggs only — NEVER auto-importing or mutating Panel Eggs!) + optional clean pack-switch mode with `level.dat` world preservation and safe operational `server.properties` allowlist merge.

### Explicitly Rejected Designs (Recorded for Transparency & Audit)
From comparative audit against `cosmii02/pelican-modpackmanager` (`ddc2e364`):
1. Executing pack shell scripts (`bash start.sh`, `run.sh`, `startserver.sh`) — arbitrary execution, restart loops, breaks Pelican stop signals.
2. Auto-importing Eggs from mutable GitHub `refs/heads/main` — supply-chain risk & bypasses admin governance.
3. Mutating shared Panel Eggs in DB (`prepareOfficialQuiltEgg`, `$egg->tags` mutation) — silent global side effect across servers.
4. Reconstructing `edge.forgecdn.net` URLs on `allowModDistribution: false` — violates CurseForge 3rd Party API T&C and live-verified `403 Forbidden` on `mediafilez.forgecdn.net`.
5. HTML scraping CurseForge `/additional-files` and substring `"server"` filename guessing.
6. Pre-download deletion of server files/runtimes with zero rollback.
7. Skipping cryptographic hash verification on Modrinth/CurseForge/FTB/ATLauncher downloads.
8. Disabling TLS verification on Wings uploads outside production.
9. Reading `DB::table('jobs')` with `@unserialize()` + `ShouldQueue` `$tries = 0` hack (breaks Redis/SQS/sync drivers).
10. Half-baked ATLauncher installer (drops `type=extract`, drops `download=browser`, installs conflicting `optional=true` mods) — keep ATLauncher browse-only until/unless full spec compliance is built.
11. Custom CSS / Command-Deck UI (`modpack-browser-page.blade.php`) — prohibited by §5.
12. `NodeJWTService` `/upload/file` bypass when Panel's authenticated Wings `/files/write` (`putStream` with explicit `Content-Length`) clears deadlines, enforces Panel TLS, and has no `api.upload_limit` cap.

### License & Credits Compliance (§6)
- `pelican-mod-manager`: GPL-3.0 (`LICENSE`).
- `cosmii02/pelican-modpackmanager`: MIT License (`Copyright (c) 2026 cosmii02`) — one-way compatible with GPL-3.0.
- Mandatory attribution in `README.md`, `README.ja.md`, and `docs/modpack-compatibility-audit.md` matching existing `JoanFo1456/resources` bullet format, citing project name, GitHub URL, MIT license, copyright notice, and exact referenced/adapted designs (pre-send Wings retry pattern, bounded ZIP staging bundle transport concept, batched Wings rename/delete, cooperative cancellation pattern, `-Xmx0M` unlimited-memory Fabric startup protection, CurseForge `classId` routing, multi-level wrapper/launcher metadata concepts, safe `server.properties` operational allowlist merge, and FTB popular browse).

---

## Feature Inventory

Every feature/defect discovered across `ORIGINAL_REQUEST.md` and all 3 Survey reports is assigned to a concrete milestone (ZERO unassigned):

| # | Feature / Issue | Description | Milestone | Source |
|---|---|---|---|---|
| 1 | UI Issue A — Abolish Modrinth author links | Remove Modrinth author page links from `CatalogTileColumns`, `ModManagerPage`, `ModpackCatalogTable` (fixing HTML5 nested-`<a>` DOM collapse); keep project `recordUrl` links | M1 | ORIGINAL_REQUEST §2, survey_ui |
| 2 | UI Issue A — Filament-native tile metadata layout | Replace 3-item bottom `Split` with pure Filament `Stack([author, Split([downloads, date_modified->alignEnd()])])->space(1)` fixing wrapping/overflow across all providers & locales with zero custom CSS | M1 | ORIGINAL_REQUEST §2/§5, survey_ui |
| 3 | UI Issue B — Unify catalog initial view & persistence | Centralize `CatalogTileColumns` view read/write/normalize; unify `ModpackCatalogTable` closure `contentGrid` & guard with `ModManagerPage`; fix parent/modal Livewire view desync; update `table-swr-cache.js` view key & comment | M1 | ORIGINAL_REQUEST §2/§5, survey_ui |
| 4 | UI Issue B — Fix Filament `HasColumnManager` column loss | Reset `cachedDefaultTableColumnState` and re-run `initTableColumnManager()` when switching Tile→List or Catalog→Installed so toggleable columns never disappear | M1 | survey_ui |
| 5 | Critical Settings Token Wipe Fix | Include `modrinth_token` and `hangar_api_key` in `ModManagerPlugin::getSettingsFormData()` so saving settings never wipes them to `''` | M2 | survey_ui (#1) |
| 6 | Critical Sequential Scan Integrity Fix | Call `$response->throw()` in `InstalledProjectService::openDaemonFileStream()` so Wings HTTP error JSON is never hashed as a JAR | M2 | survey_ui (#2) |
| 7 | Operation Concurrency & State Leak Fixes | Include `OPERATION_MODPACK` in `InstalledOperationManager::operationStateOrActive()`; reset `$progressBuffer` in `BackgroundJobRuntime::forgetRequestState()`; add release-loop guard + `report()` logging in `DatabaseBackgroundJobQueue` | M2 | survey_ui (#3, #11, #20) |
| 8 | ResourcePack Lock & Uninstall Abstraction | Add per-server cache lock + missing `server.properties` handling in `ResourcePackService`; route `ModManagerPage::performUninstall()` through `WingsRemoteFilesystem`; harden parallel scan size/timeout/`tempnam` TOCTOU | M2 | survey_ui (#10, #12, #15) |
| 9 | Shared HTTP Client & Retry Compliance | Centralize descriptive `User-Agent`, `Accept-Encoding: gzip, deflate`, bounded idempotent retries (`Retry-After`, 429/408/5xx/conn), background `connectTimeout`, Hangar 401 JWT refresh in `UpstreamHttp` + all sources | M2 | survey_ui (#5), survey_spec |
| 10 | Wings Transport Resilience & Batch Primitives | Add pre-send cURL 6/7/35/28 retry (`RetryingDaemonFileRepository` pattern) + idempotent read retries, explicit `Content-Length` enforcement on `putStream()`, `Range: bytes=0-3` (`identity`) on `readPrefix()`, `decompressFile()`, and batched `renameFiles()`/`deleteFiles()` (up to 200/call) in `WingsRemoteFilesystem` | M2 | survey_modpack, survey_spec, survey_ui (#5, #9) |
| 11 | SourceCache & Filter/Search Caching Efficiency | Fix `SourceCache` `LockTimeoutException` outage poisoning + CLI `forgetFailureMarker()` no-op; move blocking filter-option `Cache::remember()` to SWR/backoff; cache ATLauncher/FTB/Technic lists; trim `ModrinthSource::requestProject()` payload | M2 | survey_ui (#4, #8, #13) |
| 12 | Archive Size Verification, Warm Jobs, Assets, Diagnostics & Scheduler | Populate `'size'` in CurseForge/Hangar/GitHub/Spigot normalized files; reuse `pullForeground()` `$stat` & checkpoint metadata in `InstalledArchiveTransaction`/`InstalledProjectUpdateService`; fix `WarmCatalogSearch`/`WarmCatalogCacheCommand` throttle & N+1; optimize `ModManagerAssets`/`AssetController`/`Diagnostics`; mitigate scheduler idle DB churn, global `withoutOverlapping` starvation & `BulkUpdateInstalledProjects` heartbeat gap; clean dead code | M2 | survey_ui (#6, #7, #9, #14, #16, #17, #19) |
| 13 | Fabric MC 26.1+ Runtime Verification Fix | Update `ModpackRuntime::assertInstalled()` so unobfuscated MC `26.1+` Fabric servers do not require `intermediary` JAR, and read dynamic `serverJar=` from `fabric-server-launcher.properties` | M3 | survey_spec (Edge #1, #12) |
| 14 | Range-Based `.mrpack` Probing & Allowlisted Redirects | Generalize `CurseForgeManifestReader` into shared `RemoteZipReader` supporting allowlisted HTTPS redirects (`edge.forgecdn.net`→`mediafilez.forgecdn.net`, `cdn.modrinth.com`) with SSRF checks; use Range tail probing in `ModpackCatalog::inspectModrinthArchive()` for 66–100+ MB `.mrpack` files | M3 | survey_spec (Edge #2, #4) |
| 15 | Modrinth Mirror Fallbacks & Streaming Overrides | Preserve `downloads[1..n]` as `fallbackUrls` in `ModpackArchive::fromModrinth()`; stream `overrides/` and `server-overrides/` directly from `ZipArchive` instead of buffering 2 MiB / 32 MiB strings in PHP RAM | M3 | survey_modpack (#6), survey_spec (Edge #3) |
| 16 | CurseForge Client-Pack `classId` Routing & Authoritative Client-Only Exclusion | Batch-fetch `POST /mods` in `CurseForgeSource::dependencyFiles()` for `classId`/`categories` (routing datapacks/resourcepacks, skipping shaders `6552`); add `JarClientSideInspector` (`fabric.mod.json`, `quilt.mod.json`, `(neo)?mods.toml` `clientSideOnly=true`) for zero-false-positive client-only JAR exclusion; add `/download-url` fallback for allowed mods, bulk chunk retries, `.zip`/non-`/mc-mods/` manual uploads, `alternateFileId` + bounded pagination, `manifest_url` `$projectId` fix | M3 | survey_modpack (#1, #2, #6, #7, #8), survey_spec (Edge #11) |
| 17 | FTB Real-Pack Compatibility & Popular Browse | Expand `ModpackPaths::MANAGED_ROOT_DIRECTORIES` safely (`structurize`, `global_data_packs`, `paxi`, `flan`, `local/ftb*`); support FTB `$file['curseforge']` `{project, file}` fallback & 0-byte files & `optional` flag; allow `<4` char / empty FTB browse via `/popular/installs/{limit}` (up to 20 results) | M3 | survey_modpack (#1, #6, #16), survey_spec (Edge #5) |
| 18 | Server Pack Deep Normalization, Read-Only Metadata & Egg/Java Validation | Unwrap up to 3 lone wrapper directories; read `RECOMMENDED_JAVA_VERSION` (clamped by `maximumJava`); read-only loader/version extraction from `*-installer.jar` & `startserver.sh`/`run.sh` (handling MC 26.x NeoForge properly, NEVER executing scripts); detect CurseForge-hosted FTB installer packs; validate Egg variable rules (NeoForge `-beta`, `MC_VERSION` `max:9`, Forge 26.x gap); fix unlimited-memory Fabric `-Xmx0M` → `-XX:MaxRAMPercentage=95.0` | M3 | survey_modpack (#2, #3, #5, #9), survey_spec (Edge #8, #13) |
| 19 | Scalable Panel-Verified ZIP Bundling & Incremental Ownership Deployment | Implement `SafeHttpDownload` (DNS+redirect public-IP SSRF guard, stall timeout, non-`/tmp` storage) + `ModpackBundleUploader` (bounded concurrent download, single-pass Panel cryptographic verification + CF client-only JAR skip, bounded ZIP parts with `0644`/`0755` modes + 0-byte files, authenticated Wings `/files/write` + `/files/decompress`, batched `renameFiles()`/`deleteFiles()` + `listDirectory()` size check, zero Wings→Panel read-back) + `(size, modified_at)` incremental inventory ledger fast-path while preserving full transactional rollback & crash recovery | M4 | survey_modpack (#11, #15, #16, #17), survey_spec (Edge #6, #7, #10) |
| 20 | Cooperative In-Flight Cancellation, Progress & Heartbeat Safety | Implement `ModpackCancelledException` + cancel flag checked in `$guard`/download progress/batch/reinstall loops with clean rollback; emit coalesced per-file `InstalledOperationManager::progress($done, $total)`; renew DB job heartbeats + leases inside all long transfer/reinstall loops to close 5-min stale-reservation race | M4 | survey_modpack (#13, #14), survey_spec (Edge #6) |
| 21 | Controlled Existing-Server Egg/Loader Migration & Optional Clean Pack Switch | Fix and wire `ServerEggMigration` (`keepOldVariables: false`, `skip_scripts = false`, >1s timeout, reset stale loader vars, `-Xmx0M` fix, already-installed Eggs only!) into `ModpackInstallCoordinator`/`ModpackUpdateWorkflow`/`InstallModpack` with reinstall wait loop + `ModpackRuntime::assertInstalled()`; add `ServerPropertiesMerger` (safe operational allowlist) + `level.dat` world preservation for explicit clean-install pack switch (post-backup, post-staging); keep minimal Filament-native UI hooks only (§5) | M4 | survey_modpack (#4, #5, #10, #12), survey_spec (Edge #9) |
| 22 | License Compliance, Credits (§6) & Documentation Refresh | Add MIT credit for `cosmii02/pelican-modpackmanager` in `README.md`, `README.ja.md`, `docs/modpack-compatibility-audit.md`; update stale claims in all 5 `docs/*.md`, settings env table in `README*.md`, and `CHANGELOG.md` | M5 | ORIGINAL_REQUEST §6, survey_ui, survey_modpack, survey_spec |
| 23 | Requirement-Driven 4-Tier E2E/Regression Suite (`TEST_READY.md`) | Build high-fidelity `StrictWingsFake` (enforcing real Wings 400 `os.ErrExist`, `Content-Length`, `decompressFile`, `disable_remote_download`, pull limits, fault injection, HTTP call/byte counters) + real official Pelican Egg rule fixtures + real-pack manifest/startup fixtures + 4-tier opaque-box E2E/regression suite + performance comparison assertions | E2E Track | ORIGINAL_REQUEST §7, survey_spec (Pillar 3) |
| 24 | Final Verification, Tier 5 Adversarial Hardening, Live Real-Artifact Check, Logical Commits, Push & PR #2 CI Pass | Pass 100% E2E Tiers 1–4; run Tier 5 white-box Challenger-first adversarial hardening; execute feasible live read-only real-artifact checks & before/after performance benchmarks; pass whole-project Forensic Audit; verify PHPUnit, PHPStan (CI + modified files), Pint, `validatePluginJson.php`; create cleanly separated commits (§8), normal push to `fix/modpack-wizard-spigot`, confirm all GitHub Actions CI checks green on PR #2 | M6 | ORIGINAL_REQUEST §7, §8, §9 |

---

## Milestones

| # | Name | Scope | Dependencies | Owned Files (Exclusive Write Scope) | Status |
|---|---|---|---|---|---|
| **E2E** | E2E / Regression Verification Track | Design `docs/handoff/e2e-test-infra.md`, build `StrictWingsFake` + real Egg/manifest fixtures + 4-tier requirement-driven E2E/regression test suite under `tests/E2E/` (with `tests/E2E/phpunit-e2e.xml`, Pint-formatted, isolated from `tests/Unit` during M1–M4), publish `.agents/teamwork/TEST_READY.md` | none (runs parallel with M1–M4) | `docs/handoff/e2e-test-infra.md`, `.agents/teamwork/TEST_READY.md`, `tests/E2E/**` | IN_PROGRESS (`2ca3c3368f4552abbacb1a977749c3ff`) |
| **M1** | UI Fixes (Issues A & B) | Features #1–#4: Abolish Modrinth author links (keep `recordUrl`), pure Filament `Stack+Split` tile metadata, unify `catalogView` persistence/sync, fix `HasColumnManager` column loss, SWR view key + UI regression tests. **Dedicated Commit 1 (§8).** | none | `src/Support/CatalogTileColumns.php`, `src/Filament/Components/ModpackCatalogTable.php`, `src/Filament/Server/Pages/ModManagerPage.php` *(UI table/view methods only)*, `resources/assets/table-swr-cache.js`, `tests/Unit/Filament/ModpackCatalogTableTest.php`, `tests/Unit/Filament/ModManagerBrowserAssetsTest.php`, `tests/Unit/Filament/CatalogViewUnificationTest.php` | DONE (`c643883c891988210042b3858ce74775` — Commit 1 `875e30f4`, `744 tests / 5064 assertions`, Auditor CLEAN) |
| **M2** | Core Reliability, Shared HTTP/Cache, Wings Transport & Scheduler | Features #5–#12: Settings token-wipe fix, scan `throw()` fix, `OPERATION_MODPACK` concurrency + `progressBuffer` reset, `ResourcePackService` lock, `performUninstall` via Wings, `UpstreamHttp` UA/gzip/retries/Hangar 401, `WingsRemoteFilesystem` pre-send retry/batch rename & delete/`decompressFile`/`Content-Length`/`Range`, `SourceCache` fixes, source `size` propagation & `InstalledArchiveTransaction` optimizations, filter/catalog caching, `WarmCatalog*`, `Assets`/`Diagnostics`, scheduler idle/starvation/heartbeat fixes, dead code cleanup. **Dedicated Commit 2.** | M1 *(sequential on `ModManagerPage.php`)* | `src/ModManagerPlugin.php`, `src/Services/{InstalledProjectService,InstalledOperationManager,InstalledArchiveTransaction,InstalledProjectUpdateService,ResourcePackService,ModManagerDiagnostics}.php`, `src/Support/{BackgroundJobRuntime,DatabaseBackgroundJobQueue,UpstreamHttp,SourceCache,WingsRemoteFilesystem,InstalledMetadataWriteSession,ModManagerAssets}.php`, `src/Sources/{ModrinthSource,CurseForgeSource,HangarSource,GitHubReleasesSource,SpigotSource}.php`, `src/Jobs/{WarmCatalogSearch,BulkUpdateInstalledProjects}.php`, `src/Console/Commands/{WarmCatalogCacheCommand,ProcessBackgroundJobsCommand}.php`, `src/Http/Controllers/ModManagerAssetController.php`, `src/Providers/ModManagerServiceProvider.php`, `src/Filament/Server/Pages/ModManagerPage.php` *(`performUninstall` only)*, corresponding unit tests | IN_PROGRESS (`75de3032a73325bdc742cd0ca68fb5ff`) |
| **M3** | Modpack Compatibility, Metadata Probing, Client-Only Filtering & Runtime Verification | Features #13–#18: Fabric MC 26.1+ runtime fix + dynamic `serverJar=`, `RemoteZipReader` Range probing for `.mrpack` & allowlisted redirects, Modrinth mirrors & streaming overrides (no 2 MiB RAM truncation), CurseForge `classId` routing + `JarClientSideInspector` + `/download-url`/pagination/`alternateFileId`/`.zip` manual uploads, FTB `structurize`/expanded dirs/`curseforge` fallback/0-byte/popular browse, 3-level server-pack unwrap & read-only installer/launcher/`RECOMMENDED_JAVA_VERSION` metadata, FTB-on-CF detection, Egg rule checks & `-Xmx0M` Fabric startup fix. **Dedicated Commit 3.** | M2 | `src/Services/{ModpackRuntime,ModpackCatalog,ModpackProvisioningPlanner}.php`, `src/Support/Modpacks/*`, `src/Support/JavaRuntimeImage.php`, `src/Support/Compatibility/EggCompatibilityEngine.php`, `src/Sources/{FtbModpackSource,TechnicModpackSource,AtlauncherModpackSource,CurseForgeSource}.php`, `src/Filament/Admin/{ModpackCreationStep,QueueCreatedServerModpack}.php`, corresponding unit tests | PLANNED |
| **M4** | Modpack Scalable Bundled Deployment, Cancellation & Existing-Server Migration | Features #19–#21: `SafeHttpDownload` SSRF/stall-timeout/non-`/tmp` staging, `ModpackBundleUploader` Panel-verified bounded ZIP bundling + authenticated Wings `/files/write` + `/files/decompress` + batched rename/delete + `(size, modified_at)` incremental inventory fast-path, `ModpackCancelledException` + coalesced per-file progress + heartbeat safety, fixed & wired `ServerEggMigration` (already-installed Eggs only, `keepOldVariables: false`, `skip_scripts = false`) + `ServerPropertiesMerger` + optional clean pack switch (minimal §5 UI hooks only). **Dedicated Commit 4.** | M3 | `src/Services/{ModpackDeployment,ModpackInstaller,ModpackInstallCoordinator,ModpackUpdateWorkflow,ModpackInventory,ServerEggMigration,ModpackBundleUploader,SafeHttpDownload,ServerPropertiesMerger}.php`, `src/Exceptions/ModpackCancelledException.php`, `src/Jobs/InstallModpack.php`, `src/Filament/Server/Pages/ModManagerPage.php` *(minimal modpack cancel/migration hooks only)*, `resources/views/components/modpack-update.blade.php` *(minimal only if needed)*, `lang/*`, corresponding unit tests | PLANNED |
| **M5** | Credits (§6), Comparative Audit Record & Documentation Refresh | Feature #22: MIT credit for `cosmii02/pelican-modpackmanager` in `README.md`, `README.ja.md`, `docs/modpack-compatibility-audit.md`; update stale claims across all 5 `docs/*.md`, `README*.md`, and `CHANGELOG.md`. **Dedicated Commit 5.** | M4 | `README.md`, `README.ja.md`, `CHANGELOG.md`, `docs/*.md` | PLANNED |
| **M6** | Final Milestone — E2E Pass, Tier 5 Adversarial Hardening, Benchmarks, CI & PR #2 Push | Feature #24: Phase 1 pass 100% E2E Tiers 1–4 (`TEST_READY.md`) + live read-only real-artifact & performance benchmarks; Phase 2 Tier 5 white-box adversarial hardening (inverted Challenger-first cycle); whole-project Forensic Audit; full CI parity (`validatePluginJson.php`, PHPUnit, PHPStan CI + modified files, Pint); clean logical commits, normal push to `fix/modpack-wizard-spigot`, verify all GitHub Actions CI checks pass on PR #2. | M5 + E2E (`TEST_READY.md`) | Whole project (bug fixes + test fixes + git commit/push verification) | PLANNED |

---

## Interface Contracts

### 1. `CatalogTileColumns` ↔ `ModManagerPage` & `ModpackCatalogTable` (M1)
- `CatalogTileColumns::resolveInitialView(): string` — returns `'list'` or `'panel'` via `normalizeView()`, defaulting to `'list'`.
- `CatalogTileColumns::storeView(string $view): string` — normalizes and persists to session (`SESSION_KEY`) + 365-day cookie (`COOKIE_KEY`), returning normalized view.
- `CatalogTileColumns::columns(Closure $formatDate, Closure $dateTooltip, ?Closure $extraTopContent = null, ...)` — **NO `$authorUrl` parameter**; bottom metadata uses pure Filament `Stack([TextColumn('author'), Split([TextColumn('downloads'), TextColumn('date_modified')->grow(false)->alignEnd()])])->space(1)`.
- Both `ModManagerPage` and `ModpackCatalogTable` MUST call `$this->syncTableColumnManagerAfterViewChange()` (resetting `$this->cachedDefaultTableColumnState = null; $this->initTableColumnManager();` when `!$this->getTable()->hasColumnsLayout()`) whenever rebuilding `$this->table` across tile→list or tab transitions.

### 2. `WingsRemoteFilesystem` ↔ `InstalledArchiveTransaction`, `ModpackDeployment`, `ModpackBundleUploader`, `ModManagerPage` (M2 → M4)
- Pre-send transport retry (`cURL error 6|7|35` or `cURL error 28: (Resolving|Connection) timed out`, backoff `[500, 2000, 5000]` ms) applied centrally to all Wings daemon calls; idempotent reads (`listDirectory`, `readStream`, `readPrefix`) additionally retry transient 502/503/504/connection errors.
- `putStream(DaemonFileRepository $repo, Server $server, string $path, StreamInterface|string $stream, ?int $contentLength = null): void` — guarantees explicit `Content-Length` header (never chunked `-1`, which Wings rejects with 400).
- `decompressFile(DaemonFileRepository $repo, Server $server, string $root, string $file): void` — delegates to Wings `/files/decompress` (with 900s timeout).
- `renameBatch(DaemonFileRepository $repo, Server $server, string $root, array $files, int $chunkSize = 200): void` — chunks `[['from' => ..., 'to' => ...], ...]` into Wings `/files/rename`.
- `deleteBatch(DaemonFileRepository $repo, Server $server, string $root, array $files, int $chunkSize = 200): void` — chunks filenames into Wings `/files/delete`.
- `readPrefix(...)` sends `Range: bytes=0-3` and `Accept-Encoding: identity` (handling both `206` and `200`).

### 3. `UpstreamHttp` ↔ All `Sources/*` & `SafeHttpDownload` (M2 → M3/M4)
- `UpstreamHttp::userAgent(): string` — returns `pelican-mod-manager/<version> (+https://github.com/kazaminosuke/pelican-mod-manager)`.
- `UpstreamHttp::json(float $timeout = 10.0, array $headers = [], ?float $connectTimeout = null, int $maxRetries = 2): PendingRequest` — sets `User-Agent`, `Accept-Encoding: gzip, deflate`, bounded retries respecting `Retry-After` on 429/408/502/503/504/connection failures, and sensible `connectTimeout` (`min(3.0, $timeout)` for background calls).

### 4. `ModpackArchive` / `ModpackManifest` / `JarClientSideInspector` ↔ `ModpackInstaller` & `ModpackDeployment` (M3 → M4)
- `ModpackOverrideFile` supports streaming directly from a `ZipArchive` entry (`$archivePath`, `$entryName`, `$size`, optional `$contents` for small/synthetic tests) so overrides >2 MiB are never silently dropped or buffered in PHP RAM.
- `ModpackRemoteFile` carries `targetPath` (routed cleanly by CurseForge `classId`: `mods/`, `datapacks/`, `resourcepacks/`), `url`, `fallbackUrls` (including Modrinth `downloads[1..n]` & FTB `mirrors[]`), `hashes` (`sha256`, `sha512`, `sha1`, `md5`), `size` (allowing `0` for valid 0-byte managed configs!), `required`, and `checkClientSideJar: bool` (true for CurseForge client-pack mod JARs).
- `JarClientSideInspector::isClientOnlyJar(string $localPath): ?string` — inspects `fabric.mod.json`, `quilt.mod.json`, `META-INF/mods.toml`, `META-INF/neoforge.mods.toml`; returns reason string ONLY on explicit authoritative client-only declaration (`environment=client` or `clientSideOnly=true`), else `null`.

### 5. `ModpackBundleUploader` & `SafeHttpDownload` ↔ `ModpackDeployment` & `InstallModpack` (M4)
- `SafeHttpDownload::downloadToFile(string $url, string $destPath, array $options): DownloadedFile` — resolves DNS + blocks private/reserved/loopback/link-local IPs on initial URL AND every `on_redirect` hop (HTTPS only, max redirects, stall timeout, max byte limit, optional progress/cancel callback).
- `ModpackBundleUploader` stages verified files into bounded ZIP parts (`<= 64 MiB`, explicit `0644`/`0755` modes, under `.mod-manager-stage-{token}/`), uploads via `WingsRemoteFilesystem::putStream()` (explicit `Content-Length`), extracts via `decompressFile()`, cleans up the part archive, and verifies presence + byte size via batched `listDirectory()`.
- `ModpackCancelledException` propagates cleanly through `$guard`, download progress callbacks, bundle loops, and reinstall wait loops, triggering staging cleanup / transactional rollback and recording terminal `cancelled` state.

### 6. `ServerEggMigration` & `ServerPropertiesMerger` ↔ `ModpackInstallCoordinator` & `ModpackUpdateWorkflow` (M4)
- `ServerEggMigration::apply(Server $server, Egg $targetEgg, array $variables, ?string $dockerImage, ?string $startup, callable $guard): void`:
  - Uses ONLY already-installed Panel Eggs (never imports/mutates Eggs).
  - Resets `$server->skip_scripts = false` before `ReinstallServerService`.
  - Calls `EggChangerService::handle($server, $targetEgg, keepOldVariables: false)` when switching Eggs; clears stale loader-version variables when target build is unknown; applies `-XX:MaxRAMPercentage=95.0` when `$server->memory === 0` and startup uses `-Xmx{{SERVER_MEMORY}}M`.
  - Waits for reinstall completion with periodic heartbeats & cancellation checks, then runs `ModpackRuntime::assertInstalled()`.
- `ServerPropertiesMerger::merge(string $existingProperties, string $packProperties, bool $preserveWorldName): string` — preserves ONLY the documented allowlist of safe operational settings from existing `server.properties` while accepting new pack worldgen settings.

---

## Code Layout

- **Plugin Root**: `<repo-root>`
- **Source Code**: `src/` (`Kazaminosuke\ModManager\*`, PSR-4)
  - `src/Console/Commands/`
  - `src/Contracts/`, `src/Enums/`, `src/Exceptions/`, `src/Facades/`
  - `src/Filament/{Actions,Admin,Components,Filters,Server}/`
  - `src/Http/{Controllers,Middleware}/`
  - `src/Jobs/`, `src/Models/`, `src/Providers/`, `src/Repositories/`
  - `src/Services/`
  - `src/Sources/`
  - `src/Support/{Compatibility,Modpacks}/`
- **Assets & Views**: `resources/assets/`, `resources/views/components/`, `resources/egg-profiles.json`, `lang/`
- **Docs**: `README.md`, `README.ja.md`, `CHANGELOG.md`, `docs/*.md`
- **Tests**: `tests/Unit/` (executed via `phpunit.xml`); independent E2E/regression track writes to `tests/E2E/` (run via `tests/E2E/phpunit-e2e.xml`, strictly Pint-formatted) during M1–M4 so unimplemented features never break `tests/Unit`, then integrated into `phpunit.xml` at M6 so GitHub Actions CI runs the entire suite automatically!
- **Agent Metadata (STRICTLY UNTRACKED — NEVER COMMIT)**: `.agents/teamwork/` (contains `ORIGINAL_REQUEST.md`, `PROJECT.md`, `TEST_INFRA.md`, `TEST_READY.md`, and per-agent state folders). NEVER place source code, tests, or cloned repositories inside `.agents/teamwork/`.

### Canonical Local Verification Commands (CI Parity)
All workers and verifiers run verification in an isolated scratch `pelican-dev/panel` checkout (using warm `~/.cache/composer`, rsyncing plugin with `--exclude=.git --exclude=.github --exclude=.idea --exclude=.agents`, and deleting scratch dir on completion):
1. `php .github/scripts/validatePluginJson.php`
2. `(cd "$SCRATCH/pelican" && vendor/bin/phpunit --configuration plugins/pelican-mod-manager/phpunit.xml)`
3. `(cd "$SCRATCH/pelican" && vendor/bin/phpstan analyse --memory-limit=-1 --error-format=github)` (CI scope) + targeted `vendor/bin/phpstan analyse <modified_plugin_files> --autoload-file=plugins/pelican-mod-manager/tests/bootstrap.php --memory-limit=-1`
4. `(cd <repo-root> && "$SCRATCH/pelican/vendor/bin/pint" --test --config=pint.json)`

---

# Scope — Milestone M2 (`sub_orch_m2_core`)

- **Milestone**: M2 — Core Reliability, Settings/Scan Safety, Concurrency, Shared HTTP/Cache, Wings Transport Resilience & Scheduler Efficiency (Features #5–#12 in `PROJECT.md`)
- **Base Commit**: `875e30f413fad48f1fb5e69216651383869292b5` (Commit 1 / M1 on `fix/modpack-wizard-spigot`)
- **Original Request**: `docs/handoff/HANDOFF.md (original request scope)`
- **Project Plan**: `docs/handoff/project-plan-m2-m5.md`

## In-Scope Deliverables (Features #5–#12)
1. **#5 Settings Token-Wipe Fix (Critical)**:
   - `src/ModManagerPlugin.php`: Include `modrinth_token` and `hangar_api_key` in `getSettingsFormData()` so saving Plugin Settings never overwrites them with `''` in `.env`. Add round-trip unit test covering all 13 settings keys.
2. **#6 & #8 Sequential Scan Integrity & Parallel Scan Hardening (Critical/Medium)**:
   - `src/Services/InstalledProjectService.php`: Call `$response->throw()` in `openDaemonFileStream()` before returning `$response->toPsrResponse()->getBody()`. Add regression test.
   - Harden `computeScanHashesParallel()`: sane timeout & file-size guard using Wings directory listing's `size` before downloading to temp storage; open `ZipArchive` directly on `tempnam()` files without unlink+`.jar` TOCTOU rename.
3. **#7 Operation Concurrency, Worker State Hygiene & Queue Reliability (High/Medium)**:
   - `src/Services/InstalledOperationManager.php`: Check `[OPERATION_SCAN, OPERATION_BULK_UPDATE, OPERATION_MODPACK]` in `operationStateOrActive()`. Add `clearRuntimeCaches()` clearing `$this->progressBuffer = []`. Remove unused `$config` constructor param ONLY if zero external/test callers break.
   - `src/Support/BackgroundJobRuntime.php`: Invoke `InstalledOperationManager::clearRuntimeCaches()` inside `forgetRequestState()`.
   - `src/Support/DatabaseBackgroundJobQueue.php`: Add bounded max-release guard so blocked release loops cannot spin forever decrementing `attempts` back to 0 indefinitely; report/log unexpected DB exceptions via `report()` instead of silent swallowing.
4. **#8 ResourcePack Concurrency Lock & Uninstall Abstraction (High/Medium)**:
   - `src/Services/ResourcePackService.php`: Wrap `installOrUpdate()` and `uninstall()` in a per-server cache lock (matching `InstalledMetadataRepository::withinLock()`) and handle missing `server.properties` gracefully.
   - `src/Filament/Server/Pages/ModManagerPage.php` *(`performUninstall()` ONLY — ZERO UI changes!)*: Route deletion through `WingsRemoteFilesystem::delete()` instead of `Http::daemon($server->node)->post(...)`.
5. **#9 Shared HTTP Client & Upstream API Compliance (High; Interface Contract #3)**:
   - `src/Support/UpstreamHttp.php`: Add `userAgent(): string` (`pelican-mod-manager/<version> (+https://github.com/kazaminosuke/pelican-mod-manager)`), default `User-Agent` + `Accept-Encoding: gzip, deflate`, bounded idempotent retry (respecting `Retry-After` on 429/408/502/503/504/connection errors within remaining deadline, skipping other 4xx), configurable `connectTimeout` (`min(3.0, $timeout)` for background calls).
   - Route `ModrinthSource`, `CurseForgeSource`, `HangarSource`, `GitHubReleasesSource`, `SpigotSource` through `UpstreamHttp` consistently; add Hangar 401 JWT refresh/retry once + graceful public fallback if an invalid API key is configured.
6. **#10 Wings Transport Resilience & Batch Primitives (High; Interface Contract #2 for M4)**:
   - `src/Support/WingsRemoteFilesystem.php`:
     - Safe pre-send transport retry (`cURL error 6|7|35:` and `cURL error 28: (Resolving|Connection) timed out`, backoff `[500, 2000, 5000]` ms — adapted from `cosmii02`'s `RetryingDaemonFileRepository`, configurable delay/sleeper for fast deterministic unit tests) centrally on Wings daemon calls, PLUS idempotent read retries (`listDirectory`, `readStream`, `readPrefix`) on transient 502/503/504/connection errors.
     - Explicit `Content-Length` enforcement on `putStream()` (never sending chunked `-1`).
     - `Range: bytes=0-3` + `Accept-Encoding: identity` in `readPrefix()` (handling both `206` and `200`).
     - `decompressFile(DaemonFileRepository $repo, Server $server, string $root, string $file): void` (Wings `/files/decompress` with 900s timeout).
     - `renameBatch(DaemonFileRepository $repo, Server $server, string $root, array $files, int $chunkSize = 200): void` and `deleteBatch(DaemonFileRepository $repo, Server $server, string $root, array $files, int $chunkSize = 200): void`.
     - Thorough unit tests in `WingsRemoteFilesystemTest.php`.
7. **#11 SourceCache & Filter-Option SWR / Payload Trimming (High/Medium)**:
   - `src/Support/SourceCache.php` (+ `src/Exceptions/SingleFlightTimeoutException.php`): Dedicated `SingleFlightTimeoutException` on cold single-flight `LockTimeoutException` so `swr()` returns fallback WITHOUT calling `markFailure()` and WITHOUT dispatching duplicate `RevalidateSourceCache`. Fix CLI `forgetFailureMarker()` no-op so fresh writes (`storeEntry()`/`primeMany()`) ALWAYS clear stale failure markers in cache.
   - Move blocking synchronous `Cache::remember()` filter-option endpoints (`ModrinthSource` game_version/loader/category/license, `CurseForgeSource` categories/mc versions, `HangarSource` platform versions) to SWR / stale retention + short failure backoff.
   - Trim `ModrinthSource::requestProject()` cached payload to consumed fields only.
   - *(Note: `FtbModpackSource`, `TechnicModpackSource`, `AtlauncherModpackSource` are strictly owned by M3 and MUST NOT be touched in M2.)*
8. **#12 Archive Size Verification, Wings I/O Reduction, Warm Jobs, Assets, Diagnostics & Scheduler (High/Medium)**:
   - Populate `'size'` in normalized version `files[]` for `CurseForgeSource` (`fileLength`), `HangarSource` (`fileInfo.sizeBytes`), `GitHubReleasesSource` (`asset.size`), and `SpigotSource` (when known).
   - `InstalledArchiveTransaction`: Reuse `pullForeground()`'s returned `$stat` when valid (avoiding redundant 2nd `listDirectory()`).
   - `InstalledProjectUpdateService` / `InstalledMetadataWriteSession`: Checkpoint/batch metadata writes during bulk updates while preserving crash safety (periodic flush + always in `finally`).
   - `WarmCatalogSearch`: Move `$throttle->tryAcquire()` AFTER validation & fresh-cache check; eager-load `egg.variables`.
   - `WarmCatalogCacheCommand`: Include all MC version variable names from `EggProfileRegistry::all()` and eliminate unnecessary N+1 server loading.
   - `ModManagerAssets`: Process-local memo keyed by asset+mtime.
   - `ModManagerAssetController`: Check `isNotModified` BEFORE reading & `gzencode(..., 6)`.
   - `ModManagerDiagnostics`: Dedupe Spiget #2, run checks via `Http::pool()`, `'Accept-Encoding' => 'identity'` on Range probe, add Hangar & GitHub checks.
   - Scheduler idle DB churn & worker starvation (`ModManagerServiceProvider`, `ProcessBackgroundJobsCommand`, `DatabaseBackgroundJobQueue`, `BulkUpdateInstalledProjects`): gate `hasPending()` with lightweight cache flag set on `push()`; separate lane/mutex for long jobs (Modpack/Bulk) vs fast interactive jobs so long jobs never starve scans/revalidations across other servers; align `BulkUpdateInstalledProjects` heartbeat/reservation renewal during long pulls.

## Exclusive Write Ownership
### Owned (May Modify / Create)
- `src/ModManagerPlugin.php`
- `src/Services/{InstalledProjectService,InstalledOperationManager,InstalledArchiveTransaction,InstalledProjectUpdateService,ResourcePackService,ModManagerDiagnostics}.php`
- `src/Support/{BackgroundJobRuntime,DatabaseBackgroundJobQueue,UpstreamHttp,SourceCache,WingsRemoteFilesystem,InstalledMetadataWriteSession,ModManagerAssets}.php`
- `src/Exceptions/SingleFlightTimeoutException.php`
- `src/Sources/{ModrinthSource,CurseForgeSource,HangarSource,GitHubReleasesSource,SpigotSource}.php` *(CurseForge: shared HTTP/size/filter-cache ONLY — leave modpack-specific methods for M3)*
- `src/Jobs/{WarmCatalogSearch,BulkUpdateInstalledProjects}.php`
- `src/Console/Commands/{WarmCatalogCacheCommand,ProcessBackgroundJobsCommand}.php`
- `src/Http/Controllers/ModManagerAssetController.php`
- `src/Providers/ModManagerServiceProvider.php`
- `src/Filament/Server/Pages/ModManagerPage.php` *(`performUninstall()` ONLY — ZERO UI changes!)*
- Corresponding `tests/Unit/**` tests

### Strictly Forbidden (Out of Scope)
- `tests/E2E/**` (concurrent `sub_orch_e2e_track`)
- `src/Support/Modpacks/*`, `src/Services/Modpack*`, `src/Jobs/InstallModpack.php`
- `src/Sources/{FtbModpackSource,TechnicModpackSource,AtlauncherModpackSource}.php`
- `src/Support/CatalogTileColumns.php`, `src/Filament/Components/ModpackCatalogTable.php`
- `resources/**`, `docs/**`, `README*.md`, `CHANGELOG.md`
- Any UI changes (§5), Pelican/Wings core edits (§8), or `git commit`/`git push`.

## Verification Criteria
1. `php .github/scripts/validatePluginJson.php` passes (exit 0).
2. `vendor/bin/phpunit --configuration plugins/pelican-mod-manager/phpunit.xml` passes 100% (all existing + new unit tests).
3. CI `vendor/bin/phpstan analyse --memory-limit=-1 --error-format=github` passes (exit 0) AND targeted PHPStan on all M2 modified/new files introduces zero new errors (ideally 0 errors on M2 files).
4. Pint (`vendor/bin/pint --test --config=pint.json src tests/Unit`) passes cleanly on all M2 owned files (and repo `src/` + `tests/Unit/`).
5. 2 Reviewers APPROVE, 2 Challengers report zero unaddressed defects/gaps, 1 Forensic Auditor returns CLEAN (intermediate milestone audit on M2 scope).
