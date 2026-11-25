> **Handoff Reference Artifact (WIP — Temporary Planning Archive)**  
> This document preserves verified research / design findings from the prior multi-agent session so subsequent sessions on `fix/modpack-wizard-spigot` do not need to repeat the same investigation.  
> See [`docs/handoff/HANDOFF.md`](HANDOFF.md) for the master handoff summary and next-session workflow. Remove or consolidate `docs/handoff/` once Milestones M2–M5 are completed and merged into permanent `docs/*.md`.

# Test Infrastructure & 4-Tier E2E Verification Specification (`TEST_INFRA.md`)

- **Project**: `kazaminosuke/pelican-mod-manager` (`fix/modpack-wizard-spigot`, PR #2)
- **Owner Track**: `sub_orch_e2e_track` (Independent Requirement-Driven Opaque-Box E2E Track)
- **Original Request**: `docs/handoff/HANDOFF.md (original request scope)`
- **Project Blueprint**: `docs/handoff/project-plan-m2-m5.md`
- **Isolated E2E Suite Root**: `tests/E2E/` (standalone via `tests/E2E/phpunit-e2e.xml` during M1–M5; integrated into root `phpunit.xml` at M6 for GitHub Actions CI)

---

## 1. Test Execution Architecture & Commands

### 1.1 Isolation & Bootstrap Contract
Because `pelican-mod-manager` is a standalone Pelican Panel plugin with no local `composer.json`/`vendor/`, tests run inside a temporary scratch `pelican-dev/panel` checkout (reusing warm `~/.cache/composer`):

- **`tests/E2E/bootstrap.php`**: Loads `dirname(__DIR__).'/bootstrap.php'` (Panel `vendor/autoload.php` + `Kazaminosuke\ModManager\` → `src/`) and registers PSR-4 `Kazaminosuke\ModManager\Tests\E2E\` → `tests/E2E/`. Works identically in single-process and `#[RunTestsInSeparateProcesses]` Livewire child processes without modifying `tests/bootstrap.php` or `phpunit.xml`.
- **`tests/E2E/phpunit-e2e.xml`**: PHPUnit 11.5 schema (compatible with Panel's PHPUnit 12.5 on PHP 8.4/8.5), defining 5 testsuites:
  - `E2E SelfTest` (`Support/*Test.php`, `Fixtures/*Test.php`) — MUST pass 100% on baseline `4e5f839` and every subsequent milestone.
  - `E2E Tier1` (`Tier1/*Test.php`) — Isolated Feature Coverage (#1–#22).
  - `E2E Tier2` (`Tier2/*Test.php`) — Boundary, Security & Corner Cases.
  - `E2E Tier3` (`Tier3/*Test.php`) — Cross-Feature Combinations.
  - `E2E Tier4` (`Tier4/*Test.php`) — Real-World Workloads & Quantitative Efficiency Bounds.

### 1.2 Canonical Local Execution Commands (Temp Scratch Pelican Harness)
```bash
SCRATCH=$(mktemp -d)
trap 'rm -rf "$SCRATCH"' EXIT
git clone --depth=1 https://github.com/pelican-dev/panel "$SCRATCH/pelican"
(cd "$SCRATCH/pelican" && composer install --no-interaction --prefer-dist --no-progress --no-scripts && cp .env.example .env)
mkdir -p "$SCRATCH/pelican/plugins/pelican-mod-manager"
rsync -a  "$SCRATCH/pelican/plugins/pelican-mod-manager/" \
  --exclude=.git --exclude=.github --exclude=.idea --exclude=.agents

# 1. PHP syntax & Laravel Pint check on E2E suite
find tests/E2E -name '*.php' -print0 | xargs -0 -n1 php -l
(cd <repo-root> && "$SCRATCH/pelican/vendor/bin/pint" --test --config=pint.json tests/E2E)

# 2. PHPUnit discovery & execution
(cd "$SCRATCH/pelican" && vendor/bin/phpunit --configuration plugins/pelican-mod-manager/tests/E2E/phpunit-e2e.xml --list-tests)
(cd "$SCRATCH/pelican" && vendor/bin/phpunit --configuration plugins/pelican-mod-manager/tests/E2E/phpunit-e2e.xml --testsuite="E2E SelfTest")
(cd "$SCRATCH/pelican" && vendor/bin/phpunit --configuration plugins/pelican-mod-manager/tests/E2E/phpunit-e2e.xml)
```

### 1.3 M6 Integration Path into Root `phpunit.xml`
At Final Milestone (M6), integrating `tests/E2E/` into GitHub Actions CI requires only 2 lines:
1. Add `$loader->addPsr4('Kazaminosuke\\ModManager\\Tests\\E2E\\', __DIR__.'/E2E/');` to `tests/bootstrap.php`.
2. Add `<testsuite name="Minecraft Mod Manager E2E"><directory>tests/E2E</directory></testsuite>` to `phpunit.xml`.

---

## 2. Harness & High-Fidelity Test Doubles (`tests/E2E/Support/`)

### 2.1 `StrictWingsFake.php` (HTTP-Transport-Layer Pelican Wings Emulator)
Implemented as a **real temporary-disk-backed Wings HTTP server handler** attached via `Http::fake()` (intercepting `https://wings.e2e.test:8080/api/servers/*` and returning `null` for non-Wings hosts so upstream provider `Http::fake()` stubs compose cleanly). Intercepting at the HTTP layer ensures **100% real production `WingsRemoteFilesystem`, `DaemonFileRepository`, `DaemonServerRepository`, `InstalledProjectService`, `ResourcePackService`, and `ModManagerPage`** code runs unmodified across M1–M6 with zero inheritance breakage.

Enforced real Pelican Wings (`pelican-dev/wings@27f67c8`) and Panel (`pelican-dev/panel@f880625`) semantics:
- **Node Auth & Identity**: Validates `Authorization: Bearer <token>`; emits `User-Agent: Pelican Wings/v1.0.0 (id:<token_id>)` on EVERY response (2xx/4xx/5xx) so Pelican's `DaemonRepository::enforceValidNodeToken()` accepts error responses authentically.
- **`PUT /files/rename`**: Batch array `{root, files: [{from, to}, ...]}`; checks destination via `lstat()` and returns **HTTP 400 `os.ErrExist`** if destination already exists (regular file, directory, OR dangling symlink); silently ignores missing sources; returns `204`.
- **`POST /files/write`**: Rejects `Transfer-Encoding: chunked`, negative/`-1`/non-numeric `Content-Length`, or unknown-size streams lacking explicit `Content-Length` with **HTTP 400 `Missing Content-Length`**; allows known 0-byte bodies (handling Guzzle `PrepareBodyMiddleware`'s empty-body quirk); rejects writing over a directory with `400`; overwrites regular files with `0644`.
- **`POST /files/decompress`**: Performs **real `ZipArchive` extraction** into server temp disk; pre-validates EVERY entry before extraction, rejecting zip-slip (`../`), absolute paths, backslash traversal (`..\`), NUL bytes, >4096-byte names, and symlinks escaping root with `400`; overwrites existing files; applies safe Unix modes (`0644`/`0755`) from ZIP external attributes and records extracted modes; leaves source archive on disk; returns `204`.
- **`POST /files/pull`**: Supports `setDisableRemoteDownload(true)` (returns **HTTP 400**); enforces max **3 concurrent pulls** per server (returns **HTTP 400** when exceeded); rejects non-HTTP(S) and private/loopback/link-local IPs (`400`); supports `foreground: true` (`200` with Wings `Stat`) and async (`202`).
- **`GET /files/contents` & `GET /files/list-directory`**: Authentic 404 JSON error payloads; supports configurable HTTP `200` (real Wings default) or `206 Partial Content` on `Range` headers while recording `Range` and `Accept-Encoding`; authentic Wings `Stat` fields including `modified` RFC3339 timestamps for M4 `(size, modified_at)` incremental ledger fast-path verification.
- **Deterministic Fault Injection**: `injectConnectionError($endpoint, $curlMessage, $times, $pathContains)` throws `GuzzleHttp\Exception\ConnectException` (marshaled by Laravel into `Illuminate\Http\Client\ConnectionException`) for exact pre-send `cURL error 6/7/35/28` and post-send `cURL error 28: Operation timed out`; `injectHttpError(...)` for 400/404/500/502/503/504; `beforeEndpoint(...)` for mid-transaction rollback triggers.
- **Accurate Metrics & Efficiency Counters**: Per-endpoint call counters (`listDirectory`, `readStream`, `readPrefix`, `putStream`, `pull`, `decompress`, `rename`, `delete`, `createDirectory`, `serverDetails`, `power`, `sync`, `reinstall`, `total`, `renameItems`, `deleteItems`) AND exact byte counters (`bytesPanelToWings`, `bytesWingsToPanel`) with `resetMetrics()` and assertion helpers.

### 2.2 `E2ETestCase.php`
- Extends `Illuminate\Foundation\Testing\TestCase`; calls `Model::preventLazyLoading(false)` so real Pelican Panel services (`EggChangerService`, `ReinstallServerService`) run cleanly.
- Creates isolated temporary `storage_path()` (auto-cleaned in `tearDown()`), sets 32-byte `app.key` (required for `Node`'s encrypted `daemon_token`), configures SQLite `:memory:`, creates all 20 required Pelican core & Spatie RBAC tables (`users`, `nodes`, `eggs`, `egg_variables`, `allocations`, `servers`, `server_variables`, `subusers`, `mounts`, `mount_server`, `egg_mount`, `backups`, `activity_logs`, `activity_log_subjects`, `webhook_configurations`, `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`), and runs the 5 schema plugin migrations from `database/migrations/` (intentionally omitting the cache-only `signal_queue_restart` migration in isolated `:memory:` tests).
- Boots `StrictWingsFake` first in `Http::fake()` + `Http::preventStrayRequests()`.
- Provides unified `registerRemoteFile(string $url, string $bytes)` registering files in BOTH `StrictWingsFake` (Wings pull) AND `Http::fake()` (Panel download) using authentic public CDN hostnames (`cdn.modrinth.com`, `mediafilez.forgecdn.net`, `files.feed-the-beast.com`) so tests remain 100% opaque-box across `4e5f839` and M4 DNS SSRF checks.
- Provides `useAdminPanel()` / `useServerPanel(Server $server)` and model factories (`createNode`, `createUser`, `createEgg`, `createServer`, `createFullBackup`).

### 2.3 `Html5DomAssert.php`
- Uses PHP 8.4's native WHATWG lexbor HTML5 parser (`Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR)`) to verify:
  - Zero `modrinth.com/user/` author links (including protocol-relative `//modrinth.com/user/`) in rendered markup.
  - Zero HTML5 Adoption Agency anchor fragmentation and zero direct nested `<a>` (per-card `.fi-ta-record` strict 1 `a.fi-ta-record-content` and 1 non-action `<a>`).
  - Pure Filament-native tile metadata layout (`Stack` with dedicated non-split author row + nested strictly 2-item `Split([downloads, date_modified->alignEnd()])`) rather than a 3-item horizontal `Split`.

### 2.4 `CreateServerWizardHarness.php`
- Mockery-free Filament `CreateServer` harness driving real `ModpackCreationStep` + `RecordCreated` → `QueueCreatedServerModpack` + `BackgroundJob::execute(BackgroundJob::MODPACK, ...)` end-to-end (including unlimited-memory `memory=0` Fabric `-Xmx0M` startup protection).

---

## 3. Authentic Fixtures (`tests/E2E/Fixtures/`)

1. **`OfficialPelicanEggFixtures.php`**: Verbatim pinned definitions, UUIDs, startup commands, Java yolks (`8/11/16/17/21/25`), and JSON validation rules from `pelican-eggs/minecraft@c6f2262`:
   - Forge (`MC_VERSION` `max:9`, `FORGE_VERSION` `regex:/^[0-9\.\-]+$/`)
   - NeoForge (`MC_VERSION` `max:9`, `NEOFORGE_VERSION` `regex:/^[0-9\.\-]+$/` — rejects `-beta`)
   - Fabric (`-Xms128M -Xmx{{SERVER_MEMORY}}M -jar {{SERVER_JARFILE}}`)
   - Quilt (NO `QUILT_VERSION` variable)
   - Technic (`tekkit-2` `max:10`, `tekkit-legends` `max:20`)
   - Paper & Vanilla (negative modpack compatibility tests)
2. **`ModpackArchiveFixtures.php`**: Auto-cleaned real `ZipArchive` & JSON payload builders:
   - `.mrpack` (omitted `env`, `downloads[]` mirrors, `overrides/`/`server-overrides/`/`client-overrides/`, >2 MiB overrides, >8 MiB `CM_STORE`-padded Range-probable archives + RFC 9110 `rangeHttpResponder()` with request/byte metrics & 302 redirects).
   - CurseForge Server Pack (0–3 nested wrapper directories, `variables.txt` with `RECOMMENDED_JAVA_VERSION`, `*-installer.jar`, `startserver.sh`/`run.sh`, CF-hosted FTB installer pack).
   - CurseForge Client Pack + Core API payloads (`classId` `6` mod, `12` resourcepack, `12+5193`/`6945` datapack, `6552` shader; `allowModDistribution=false`; `/download-url` fallback; `alternateFileId`; pagination).
   - Authoritative Client-Only & Universal JARs (`fabric.mod.json`, `quilt.mod.json`, `META-INF/mods.toml`, `META-INF/neoforge.mods.toml`) + hostile archives (zip-slip, Unix symlink, mode-`000`, HTML-as-JAR).
   - FTB Manifests (MC `26.1.2`/Java `25`, `structurize/`, `global_data_packs/`, `paxi/`, `flan/`, `local/ftb*`, 0-byte configs, `mirrors[]`, `curseforge` fallback, `optional: true`, `clientonly: true`, plus scalable 500–1,500 file generator).
3. **`LoaderRuntimeFixtures.php`**: Authentic Wings file maps & folded-header `META-INF/MANIFEST.MF` JARs for Fabric `1.21.1` (obfuscated with `intermediary`), Fabric `26.1.2` (unobfuscated, NO `intermediary`), custom `fabric-server-launcher.properties` (`serverJar=custom-mc.jar`), legacy shaded Fabric, Forge (`1.20.1`/`1.16.5`/`1.12.2`), NeoForge (`1.20.1`/`1.21.1`/`26.1.2`), and Quilt.

---

## 4. Opaque-Box Pre-Implementation Failure Discipline

- Tests MUST NEVER use `markTestSkipped()`, tautological `assertTrue(true)`, or weakened mocks to mask unimplemented M1–M5 features.
- For existing classes/methods (`4e5f839`), invoke public entrypoints directly (never coupling to private helpers or changing helper signatures like `CatalogTileColumns::columns()` or `new InstalledOperationManager()`).
- For new M1–M4 classes/methods specified in `PROJECT.md § Interface Contracts` (`CatalogTileColumns::storeView`, `UpstreamHttp::userAgent`, `WingsRemoteFilesystem::decompressFile`/`renameBatch`/`deleteBatch`, `JarClientSideInspector`, `SafeHttpDownload`, `ModpackBundleUploader`, `ModpackCancelledException`, `ServerPropertiesMerger`, evolved `ServerEggMigration::apply`), guard with `self::assertTrue(class_exists(...))` / `method_exists(...)` / `ReflectionMethod` BEFORE invocation so `phpunit --list-tests` and pre-implementation PHPUnit runs emit clean PHPUnit assertion failures (NEVER PHP fatal/parse/TypeError errors), and automatically execute full behavioral assertions once M1–M5 land.

---

## 5. Complete Feature-to-Tier Traceability Matrix (Features #1–#22)

Every single feature in `PROJECT.md § Feature Inventory` is mapped across Tiers 1–4 with zero gaps:

| # | Feature | Milestone | Tier 1 (Feature Coverage) | Tier 2 (Boundary & Corner Cases) | Tier 3 (Cross-Feature Combinations) | Tier 4 (Real-World Scenarios & Bounds) |
|---|---|---|---|---|---|---|
| **1** | Abolish Modrinth author links; preserve project `recordUrl` | M1 | `Tier1/UiCatalogLayoutAndViewE2ETest` | `Tier2/UiTileOverflowAndPersistenceBoundaryE2ETest` | `Tier3/UiModalSyncAndColumnManagerWorkflowE2ETest` | `Tier4/CobblemonMc26FabricLifecycleAndUiFlowE2ETest` |
| **2** | Pure Filament `Stack+Split` tile metadata layout; zero ad-hoc CSS | M1 | `Tier1/UiCatalogLayoutAndViewE2ETest` | `Tier2/UiTileOverflowAndPersistenceBoundaryE2ETest` (55-char unbreakable author, `ja`/`de`/`en`, `author=null`) | `Tier3/UiModalSyncAndColumnManagerWorkflowE2ETest` | `Tier4/CobblemonMc26FabricLifecycleAndUiFlowE2ETest` |
| **3** | Unify initial `'list'` view, persistence, modal sync, SWR key | M1 | `Tier1/UiCatalogLayoutAndViewE2ETest` | `Tier2/UiTileOverflowAndPersistenceBoundaryE2ETest` | `Tier3/UiModalSyncAndColumnManagerWorkflowE2ETest` | `Tier4/CobblemonMc26FabricLifecycleAndUiFlowE2ETest` |
| **4** | Fix Filament `HasColumnManager` column loss on Tile→List & Catalog→Installed | M1 | `Tier1/UiCatalogLayoutAndViewE2ETest` | `Tier2/UiTileOverflowAndPersistenceBoundaryE2ETest` | `Tier3/UiModalSyncAndColumnManagerWorkflowE2ETest` | `Tier4/CobblemonMc26FabricLifecycleAndUiFlowE2ETest` |
| **5** | Settings token-wipe fix (`modrinth_token`, `hangar_api_key`) | M2 | `Tier1/CoreSettingsScanAndOpsE2ETest` | `Tier2/CoreFailuresLocksAndTransportBoundaryE2ETest` | `Tier3/CoreOpsQueueHttpWingsWorkflowE2ETest` | `Tier4/ExistingServerSwitchRollbackAndOpsComplianceE2ETest` |
| **6** | Sequential & parallel scan `$response->throw()` integrity fix | M2 | `Tier1/CoreSettingsScanAndOpsE2ETest` | `Tier2/CoreFailuresLocksAndTransportBoundaryE2ETest` | `Tier3/CoreOpsQueueHttpWingsWorkflowE2ETest` | `Tier4/ExistingServerSwitchRollbackAndOpsComplianceE2ETest` |
| **7** | `OPERATION_MODPACK` concurrency, `$progressBuffer` reset, queue release guard | M2 | `Tier1/CoreSettingsScanAndOpsE2ETest` | `Tier2/CoreFailuresLocksAndTransportBoundaryE2ETest` | `Tier3/CoreOpsQueueHttpWingsWorkflowE2ETest` | `Tier4/ExistingServerSwitchRollbackAndOpsComplianceE2ETest` |
| **8** | `ResourcePackService` lock & missing `server.properties`; uninstall via Wings; scan hardening | M2 | `Tier1/CoreSettingsScanAndOpsE2ETest` | `Tier2/CoreFailuresLocksAndTransportBoundaryE2ETest` | `Tier3/CoreOpsQueueHttpWingsWorkflowE2ETest` | `Tier4/ExistingServerSwitchRollbackAndOpsComplianceE2ETest` |
| **9** | `UpstreamHttp` UA, gzip, bounded retries (`Retry-After`), Hangar 401 refresh | M2 | `Tier1/CoreHttpWingsCacheAndSchedE2ETest` | `Tier2/CoreFailuresLocksAndTransportBoundaryE2ETest` | `Tier3/CoreOpsQueueHttpWingsWorkflowE2ETest` | `Tier4/ExistingServerSwitchRollbackAndOpsComplianceE2ETest` |
| **10** | `WingsRemoteFilesystem` pre-send cURL 6/7/35/28 retry, `Content-Length`, `Range` prefix, `decompressFile`, batch rename/delete | M2 | `Tier1/CoreHttpWingsCacheAndSchedE2ETest` | `Tier2/CoreFailuresLocksAndTransportBoundaryE2ETest` | `Tier3/CoreOpsQueueHttpWingsWorkflowE2ETest`, `Tier3/ModpackBundledCancelAndEggMigrationWorkflowE2ETest` | `Tier4/LargeScaleFtbBundledDeploymentE2ETest`, `Tier4/Atm10ServerPackAndIncrementalUpdateE2ETest` |
| **11** | `SourceCache` `LockTimeoutException`, CLI `forgetFailureMarker()`, filter SWR, modpack caching, trimmed Modrinth project | M2 | `Tier1/CoreHttpWingsCacheAndSchedE2ETest` | `Tier2/CoreFailuresLocksAndTransportBoundaryE2ETest` | `Tier3/CoreOpsQueueHttpWingsWorkflowE2ETest` | `Tier4/ExistingServerSwitchRollbackAndOpsComplianceE2ETest` |
| **12** | Source `'size'` propagation, `$stat` reuse, warm throttle order, asset 304, diagnostics dedupe, scheduler fixes | M2 | `Tier1/CoreHttpWingsCacheAndSchedE2ETest` | `Tier2/CoreFailuresLocksAndTransportBoundaryE2ETest` | `Tier3/CoreOpsQueueHttpWingsWorkflowE2ETest` | `Tier4/ExistingServerSwitchRollbackAndOpsComplianceE2ETest` |
| **13** | Fabric MC `26.1+` unobfuscated runtime & dynamic `serverJar=` | M3 | `Tier1/ModpackRuntimeAndProbingE2ETest` | `Tier2/ModpackMetadataEggRulesAndSsrfBoundaryE2ETest` | `Tier3/ModpackRangeMirrorClientSideAndRuntimeWorkflowE2ETest`, `Tier3/ModpackBundledCancelAndEggMigrationWorkflowE2ETest` | `Tier4/CobblemonMc26FabricLifecycleAndUiFlowE2ETest` |
| **14** | Range `.mrpack` (>8 MiB) probing & allowlisted HTTPS redirects (`edge`→`mediafilez`) | M3 | `Tier1/ModpackRuntimeAndProbingE2ETest` | `Tier2/ModpackMetadataEggRulesAndSsrfBoundaryE2ETest` | `Tier3/ModpackRangeMirrorClientSideAndRuntimeWorkflowE2ETest` | `Tier4/CobblemonMc26FabricLifecycleAndUiFlowE2ETest` |
| **15** | Modrinth `downloads[1..n]` mirror fallback & streaming >2 MiB overrides | M3 | `Tier1/ModpackRuntimeAndProbingE2ETest` | `Tier2/ModpackMetadataEggRulesAndSsrfBoundaryE2ETest` | `Tier3/ModpackRangeMirrorClientSideAndRuntimeWorkflowE2ETest` | `Tier4/CobblemonMc26FabricLifecycleAndUiFlowE2ETest` |
| **16** | CurseForge `classId` routing, `JarClientSideInspector`, `/download-url`, chunk retry, `.zip` manual upload, `alternateFileId`, pagination | M3 | `Tier1/ModpackProviderCompatE2ETest` | `Tier2/ModpackMetadataEggRulesAndSsrfBoundaryE2ETest` | `Tier3/ModpackRangeMirrorClientSideAndRuntimeWorkflowE2ETest` | `Tier4/ExistingServerSwitchRollbackAndOpsComplianceE2ETest` |
| **17** | FTB `structurize`/expanded dirs, `$file['curseforge']`, 0-byte files, `optional`, `/popular/installs/{limit}` | M3 | `Tier1/ModpackProviderCompatE2ETest` | `Tier2/ModpackMetadataEggRulesAndSsrfBoundaryE2ETest` | `Tier3/ModpackRangeMirrorClientSideAndRuntimeWorkflowE2ETest` | `Tier4/LargeScaleFtbBundledDeploymentE2ETest` |
| **18** | Server pack 3-level unwrap, `RECOMMENDED_JAVA_VERSION`, read-only installer/script metadata, FTB-on-CF, Egg rules, `-Xmx0M` fix | M3 | `Tier1/ModpackProviderCompatE2ETest` | `Tier2/ModpackMetadataEggRulesAndSsrfBoundaryE2ETest` | `Tier3/ModpackRangeMirrorClientSideAndRuntimeWorkflowE2ETest`, `Tier3/ModpackBundledCancelAndEggMigrationWorkflowE2ETest` | `Tier4/Atm10ServerPackAndIncrementalUpdateE2ETest`, `Tier4/CobblemonMc26FabricLifecycleAndUiFlowE2ETest` |
| **19** | `SafeHttpDownload` SSRF + `ModpackBundleUploader` Panel-verified bounded ZIP bundles + incremental `(size, modified_at)` ledger | M4 | `Tier1/ModpackBundledDeployAndMigrationE2ETest` | `Tier2/ModpackDeployRollbackCancelAndMigrationBoundaryE2ETest` | `Tier3/ModpackBundledCancelAndEggMigrationWorkflowE2ETest` | `Tier4/LargeScaleFtbBundledDeploymentE2ETest`, `Tier4/Atm10ServerPackAndIncrementalUpdateE2ETest` |
| **20** | Cooperative cancellation (`ModpackCancelledException`), coalesced progress & heartbeat safety | M4 | `Tier1/ModpackBundledDeployAndMigrationE2ETest` | `Tier2/ModpackDeployRollbackCancelAndMigrationBoundaryE2ETest` | `Tier3/ModpackBundledCancelAndEggMigrationWorkflowE2ETest` | `Tier4/LargeScaleFtbBundledDeploymentE2ETest`, `Tier4/CobblemonMc26FabricLifecycleAndUiFlowE2ETest` |
| **21** | Controlled existing-server `ServerEggMigration` + `ServerPropertiesMerger` + optional clean switch (`level.dat`) | M4 | `Tier1/ModpackBundledDeployAndMigrationE2ETest` | `Tier2/ModpackDeployRollbackCancelAndMigrationBoundaryE2ETest` | `Tier3/ModpackBundledCancelAndEggMigrationWorkflowE2ETest` | `Tier4/ExistingServerSwitchRollbackAndOpsComplianceE2ETest` |
| **22** | License compliance, `cosmii02` MIT credits (§6) & docs refresh | M5 | `Tier1/DocsCreditsComplianceE2ETest` | `Tier1/DocsCreditsComplianceE2ETest` (stale-ref negative guards) | — *(verified in Tier 4)* | `Tier4/ExistingServerSwitchRollbackAndOpsComplianceE2ETest` |

---

## 6. Tier 4 Quantitative Efficiency Bounds

1. **`LargeScaleFtbBundledDeploymentE2ETest` (`N = 600` small files incl. `structurize/` & 0-byte configs, `disable_remote_download: true`)**:
   - `totalWingsHttpCalls < 60` (vs ~1,800–3,000 in 3N serial baseline)
   - `pull === 0`, `putStream <= 10`, `decompress >= 1 && <= 10`, `rename <= 15` (batched up to 200/call)
   - `bytesWingsToPanel === 0` during staging (zero read-back of Panel-verified files)
   - Zero leftover `.mod-manager-stage-*` or bundle `.zip` archives on Wings; 100% SHA-256 content match across all 600 files.
2. **`Atm10ServerPackAndIncrementalUpdateE2ETest` (`N = 300` files, 2-level wrapped Server Pack cold deploy + 5-file v2 incremental update)**:
   - Cold deploy: `totalWingsHttpCalls < 45`, `bytesWingsToPanel <= 4096` (tiny metadata `mods/.pelican-mod-manager.json` / `server.properties` only; strictly ZERO `readStream` of `.mod-manager-*` or any of the 300 pack files!)
   - v2 incremental update (5 changed, 3 retired, 2 user-edited configs + 1 catalog mod preserved, 292 unchanged): `readStream <= 20` (proving `(size, modified_at)` fast-path avoids 3×300 full read-backs!), `totalWingsHttpCalls < 40`.
3. **`CobblemonMc26FabricLifecycleAndUiFlowE2ETest` (9.5 MiB `.mrpack` + Fabric `26.1.2` + `memory=0` + UI flow)**:
   - Range probe transfers `< 2 MiB` (vs 9.5 MiB full download)
   - 2.5 MiB `server-overrides/` delivered intact with exact SHA-256 and zero `override-too-large` skip
   - Startup command uses `-XX:MaxRAMPercentage=95.0` (never `-Xmx0M`)
   - Cross-catalog UI flow confirms `'list'` default, zero nested `<a>`, intact `recordUrl`, and preserved toggleable columns on Tile→List.
4. **`ExistingServerSwitchRollbackAndOpsComplianceE2ETest`**:
   - Existing-server cross-loader pack switch preserves `level.dat` worlds, user-edited configs, individual catalog mods, and operational `server.properties` allowlist; mid-apply failure executes verified rollback (or recovery lock until `confirmRestored()`).
   - Panel ops (settings token retention, scan error integrity, active modpack concurrency fencing, diagnostics, docs/credits compliance) all verified end-to-end.

---

# Iteration 2 E2E Remediation — Final Handoff

## Executive Summary

All findings from the Iteration 1 adversarial audit and all three Iteration 2 explorer blueprints (`iter2_exp_harness_gaps`, `iter2_exp_core_ui_docs_fixes`, `iter2_exp_modpack_tiers_fixes`) have been fully implemented across `tests/E2E/**` and verified inside a clean temporary `pelican-dev/panel` harness.

- **All 4 Auditor Hard-Rule Violations eliminated**:
  1. **Violation 1 (Tautology)**: Removed `DB::table('servers')->update(['startup' => ...])` in `CobblemonMc26FabricLifecycleAndUiFlowE2ETest` and replaced with real `CreateServerWizardHarness` (Mockery-free `CreateServer` subclass) + `ModpackCreationStep` + `RecordCreated` + `QueueCreatedServerModpack` + `BackgroundJob::execute(BackgroundJob::MODPACK, ...)`. Also migrated `ModpackBundledDeployAndMigrationE2ETest` from Forge -> Fabric (`memory=0`) so `-Xmx{{SERVER_MEMORY}}M -> -XX:MaxRAMPercentage=95.0` rewrite is genuinely tested.
  2. **Violation 2 (Undefined constant + swallowed `\Error`)**: Fixed `StrictWingsFake::DEFAULT_TOKEN_ID` across `ModpackDeployRollbackCancelAndMigrationBoundaryE2ETest` and `ExistingServerSwitchRollbackAndOpsComplianceE2ETest` using `$wings->userAgentHeader()`, replaced fragile call-count triggers with payload-aware live-activation rename detection, and added `assertNotInstanceOf(\Error::class, ...)` + `RequestException|ModpackException` assertions.
  3. **Violation 3 (Source-text regex for runtime behavior)**: Replaced `ModManagerPage.php` source regex in `CoreSettingsScanAndOpsE2ETest` with `#[RunInSeparateProcess]` behavioral Livewire test clicking `callTableAction('uninstall', $recordKey)` under injected pre-send cURL error 7.
  4. **Violation 4 (Stale/broken APIs, `app()`, private cache keys)**: Fixed `PluginBackgroundRunner::$spawned`, `dispatchBulkUpdate($server, ProjectType::Mod)` 2-arg call, `MOD_MANAGER_LATEST_MINECRAFT_VERSION`, `$plan->selected?->id`, zero remaining global `app()` calls in `tests/E2E/`, opaque-box Hangar 2-token 401 swap, and opaque-box `SourceCache::swr()` / `peek()` / `revalidateAsync()` verification.
- **All 6 StrictWingsFake / Html5DomAssert / Fixtures gaps closed (GAP-1 through GAP-6)** with 4 new self-tests + expanded `Html5DomAssert` & Paper Egg fixture self-tests (13/13 SelfTests passing, 274 assertions).
- **All 11 missing coverage gaps (D1–D11) and all 16 Modpack fixes** implemented cleanly.
- **Static & Style Checks**: `php -l` 29/29 clean, `pint --test` 29/29 PASS, PHPStan **Level 6** (`Support` + `Fixtures`) **0 errors**, PHPStan **Level 2** (all 29 files in `tests/E2E/`) **0 errors**.
- **Runtime Verification**: 81/81 E2E tests discovered; **13/13 E2E SelfTest PASS**; **9/9 live M1 UI E2E PASS (665 assertions)**; **Full E2E Suite: 29 PASS / 52 expected pre-M2..M5 assertion failures / 0 Errors / 0 Skipped / 0 Risky**; **Unchanged baseline `phpunit.xml`: 744/744 PASS (5,064 assertions)**.

---

## Files Created / Modified (Strictly within `tests/E2E/**`)

### Created (1)
- `tests/E2E/Support/CreateServerWizardHarness.php` — Mockery-free `CreateServer` subclass driving real `ModpackCreationStep`, `RecordCreated`, and `QueueCreatedServerModpack` with `memory = 0`.

### Modified (21)
- **Support & Fixtures (7)**:
  - `tests/E2E/Support/StrictWingsFake.php` — GAP-1 (`resolveContainedPath(..., checkFinalSymlink: true)` default, `checkFinalSymlink: false` on rename/delete), GAP-2 (`zipHasRawNulEntryName()`, `FL_ENC_RAW`, trailing `/..` & `\..`, `verifyZipStreamReadable()` with warning capture + exact size + streaming `crc32b`, `hasNonDirectoryAncestor()`), GAP-3 (`rawurldecode($queryRaw)` in `$contextText`), GAP-4 (pull directory-conflict 400 + full Wings `internal/network/address.go` IPv4/IPv6/NAT64/IPv4-mapped/non-canonical IPv4/zone-ID/trailing-dot SSRF parity), GAP-6 (`unregisterPullFixture()`), PHPStan L6 PHPDoc.
  - `tests/E2E/Support/Html5DomAssert.php` — GAP-5 (`trim()` + protocol-relative author link regex, per-`.fi-ta-record` strict 1-anchor & 0 non-action `<a>` check, `>= 2` metadata rows with dedicated author row + strictly 2 `.fi-ta-split` items).
  - `tests/E2E/Support/E2ETestCase.php` — Synced `unregisterRemoteFile()` with `$this->wings->unregisterPullFixture()`, replaced `app()`/`view()` in `registerPluginRenderHooks()`, corrected table/migration count docblock.
  - `tests/E2E/Support/StrictWingsFakeSelfTest.php` — Replaced all `app()` calls; added 4 new self-tests (GAP-1, GAP-2, GAP-3, GAP-4/GAP-6) and expanded GAP-5 `Html5DomAssert` negative + Filament 5 `.fi-ta-actions` tests.
  - `tests/E2E/Fixtures/OfficialPelicanEggFixtures.php` — GAP-6 verbatim `egg-paper.yaml` (`tags: ['minecraft']`, `USER_AGENT` default `''` & `rules: ['required']`, `DL_PATH`/`PROJECT`/`USER_AGENT` `user_viewable: false, user_editable: false`), DB visibility persistence, removed dead `$nullable` branch.
  - `tests/E2E/Fixtures/ModpackArchiveFixtures.php` — PHPStan L6 `@param` / `@param-out` on `rangeHttpResponder()`.
  - `tests/E2E/Fixtures/FixturesSelfTest.php` — Added Paper Egg verbatim rule & visibility persistence assertions.
- **UI, Core & Docs E2E (8)**:
  - `tests/E2E/Tier1/UiCatalogLayoutAndViewE2ETest.php` — Narrowed Livewire `instance()` types for PHPStan L2.
  - `tests/E2E/Tier2/UiTileOverflowAndPersistenceBoundaryE2ETest.php` — Replaced `app()->setLocale()` with `$this->app->setLocale()` and narrowed `instance()` types.
  - `tests/E2E/Tier3/UiModalSyncAndColumnManagerWorkflowE2ETest.php` — Relayed child modal `$modal->effects['dispatches']` to parent with `$refresh` fallback; narrowed `instance()` types.
  - `tests/E2E/Tier1/CoreSettingsScanAndOpsE2ETest.php` — All 13 settings keys + `MOD_MANAGER_LATEST_MINECRAFT_VERSION`; fixed `dispatchBulkUpdate()`, `$runner->spawned`, split dedicated non-singleton `progressBuffer` reset test, missing `server.properties` graceful handling, behavioral `#[RunInSeparateProcess]` Livewire `uninstall` retry test replacing source regex.
  - `tests/E2E/Tier1/CoreHttpWingsCacheAndSchedE2ETest.php` — `ReflectionMethod` calls for pre-M2 methods, opaque-box Hangar 2-token 401 swap, UA/gzip verification across all 4 sources including ATLauncher, case-insensitive `readPrefix` headers, opaque-box CLI `SourceCache` failure-marker clearing + `FtbModpackSource::search()` caching, Spigot route order + diagnostics wildcards + `WarmRequestThrottle` config + scheduler overlap check, plus 2 new tests (12-mod bulk update checkpoint + heartbeat/TTL; `mod-manager:warm-catalog` custom `EggProfile` MC version + idle `hasPending()` DB-churn guard).
  - `tests/E2E/Tier2/CoreFailuresLocksAndTransportBoundaryE2ETest.php` — New 2 GiB stat-size parallel scan skip test (D1); spy `ArrayStore` lock verification + contention test on `ResourcePackService` (D2) with bindable `Cache::extend()` closure; `CURL_ERR_28_RESOLVE` + mutating `move()`/`delete()` pre-send retries; opaque-box `SourceCache::peek()` + `$runner->spawned`; new filter-option outage backoff test across Modrinth/CurseForge/Hangar (D3).
  - `tests/E2E/Tier3/CoreOpsQueueHttpWingsWorkflowE2ETest.php` — Opaque-box Hangar 2-token 401 swap, opaque-box HTTP-500 -> CLI `SourceCache::revalidate()` -> `revalidateAsync()` recovery, replaced `app()`.
  - `tests/E2E/Tier1/DocsCreditsComplianceE2ETest.php` — Bidirectional MIT regex + MIT copyright notice check (`Copyright (c) 2026 cosmii02`), stale `PHPUnitは611件` guard in `docs/provider-regression-matrix.md`, multi-subsection `CHANGELOG.md` `[Unreleased]` parser allowing optional `####` headings.
- **Modpack Tiers 1–4 E2E (11)**:
  - `tests/E2E/Tier1/ModpackRuntimeAndProbingE2ETest.php` — Replaced `app()`.
  - `tests/E2E/Tier1/ModpackProviderCompatE2ETest.php` — Expanded `JarClientSideInspector` 4 positive + 6 negative zero-false-positive checks, `classId=6945` datapack routing, GET file stub + `manifest_url` `/download-url` assertion, new `test_ftb_mirrors_failover_and_optional_download_failure_skips_with_warning()`, opaque `RECOMMENDED_JAVA_VERSION` extraction & clamping via `ModpackCatalog::describe()`, DB-backed `InstalledEggCatalog::all()`.
  - `tests/E2E/Tier1/ModpackBundledDeployAndMigrationE2ETest.php` — New `test_install_modpack_emits_progress_renews_queue_heartbeat_and_records_terminal_cancelled_state()` (D9); fixed `createServer()` named args + Forge->Fabric `memory=0` `-Xmx0M` rewrite + `keepOldVariables:false` + unknown-build clearing `NEOFORGE_VERSION`/`FORGE_VERSION` (D10) invoked via `ReflectionMethod`.
  - `tests/E2E/Tier2/ModpackMetadataEggRulesAndSsrfBoundaryE2ETest.php` — Opaque Range redirect blocking via `ModpackCatalog::describe()` incl. Modrinth IMDS; streaming override deploy check; 2-file CurseForge bulk chunk 503 retry + dynamic `staging_path` + non-`/mc-mods/` `page` + same-length `hash_mismatch`; CurseForge-hosted FTB installer pack test; DB-backed Egg rules (`MC_VERSION > 9`, Forge `26.1.2`, Quilt pinned build with DB invariance, `RECOMMENDED_JAVA_VERSION=21` clamping via `ModpackCatalog::describe()`).
  - `tests/E2E/Tier2/ModpackDeployRollbackCancelAndMigrationBoundaryE2ETest.php` — Valid public 302 redirect on `SafeHttpDownload`; new `test_mode_zero_archive_normalizes_bundle_entries_to_0644_and_stages_in_private_storage_not_tmp()`; fixed Violation 2 with `$wings->userAgentHeader()`, payload-aware live-activation rename trigger, `assertNotInstanceOf(\Error::class)` + `RequestException|ModpackException`; added Phase 2 successful clean pack switch.
  - `tests/E2E/Tier3/ModpackRangeMirrorClientSideAndRuntimeWorkflowE2ETest.php` — Dynamic `$pending->files[0]['staging_path']`, FTB `135/100634` + `describe()`, DB-backed `InstalledEggCatalog::all()`, fixed `$plan->selected?->id`, replaced `app()`.
  - `tests/E2E/Tier3/ModpackBundledCancelAndEggMigrationWorkflowE2ETest.php` — Real `InstallModpack` cancel automatic lease release, `ReflectionMethod` 6-arg guard/invoke + `keepOldVariables:false`, new `test_existing_server_migration_flows_through_coordinator_update_workflow_and_install_modpack()` driving `ModpackInstallCoordinator` + `ModpackUpdateWorkflow` + `InstallModpack`.
  - `tests/E2E/Tier4/LargeScaleFtbBundledDeploymentE2ETest.php` — Replaced `app()`.
  - `tests/E2E/Tier4/Atm10ServerPackAndIncrementalUpdateE2ETest.php` — Cold deploy metadata read-back bound (`<= 4096` bytes strictly limited to `mods/.pelican-mod-manager.json` / `server.properties`), replaced `app()`.
  - `tests/E2E/Tier4/CobblemonMc26FabricLifecycleAndUiFlowE2ETest.php` — Replaced tautological DB startup update & manual `$selection` with real `CreateServerWizardHarness` (`memory=0`) + `QueueCreatedServerModpack` + `BackgroundJob::execute(BackgroundJob::MODPACK, ...)`, DB-backed `$plan->selected?->id`, narrowed types.
  - `tests/E2E/Tier4/ExistingServerSwitchRollbackAndOpsComplianceE2ETest.php` — Fixed Violation 2 rollback hook (`$wings->userAgentHeader()`, payload-aware live-activation trigger, `assertNotInstanceOf(\Error::class)`), removed unused `$deployment`, broadened Spigot/GitHub diagnostics check, `ReflectionMethod` 6-arg invoke, replaced `app()`.

> **Note on `docs/handoff/e2e-test-infra.md`**: Not modified because it is outside this agent's Exclusive Write Ownership (`tests/E2E/**` and `.agents/teamwork/sub_orch_e2e_track/iter2_test_writer_e2e/**`). Orchestrator may optionally update §6's ATM10 cold-deploy metadata read-back bullet (`<= 4096 bytes`, restricted to `mods/.pelican-mod-manager.json` / `server.properties`) if keeping `TEST_INFRA.md` synchronized.

---

## Final Post-Edit Verification Record

All commands executed AFTER the final file edit inside an isolated scratch `pelican-dev/panel` harness (`/tmp/pmm-iter2-e2e-verify-v1FPWu`, synced via `rsync -a --delete --exclude=.git --exclude=.github --exclude=.idea --exclude=.agents`), then cleaned up (`rm -rf /tmp/pmm-iter2-e2e-verify-v1FPWu`).

| # | Check & Exact Command | Exit Code | Verbatim Summary Output |
|---|---|---|---|
| 1 | `find "$REPO/tests/E2E" -name '*.php' -print0 \| xargs -0 -n1 php -l >/dev/null` | `0` | `PHP_L_COUNT=29` (0 syntax errors across 29 files) |
| 2 | `"$SCRATCH/pelican/vendor/bin/pint" --test --config="$REPO/pint.json" "$REPO/tests/E2E"` | `0` | `PASS .......................................................... 29 files` |
| 3 | `"$SCRATCH/pelican/vendor/bin/phpstan" analyse "$PLUGIN/tests/E2E/Support" "$PLUGIN/tests/E2E/Fixtures" --configuration="$SCRATCH/pelican/phpstan.neon" --autoload-file="$PLUGIN/tests/E2E/bootstrap.php" --level=6 --memory-limit=-1 --no-progress` | `0` | `[OK] No errors` |
| 4 | `"$SCRATCH/pelican/vendor/bin/phpstan" analyse "$PLUGIN/tests/E2E" --configuration="$SCRATCH/pelican/phpstan.neon" --autoload-file="$PLUGIN/tests/E2E/bootstrap.php" --level=2 --memory-limit=-1 --no-progress` | `0` | `[OK] No errors` |
| 5 | `"$SCRATCH/pelican/vendor/bin/phpunit" --configuration "$PLUGIN/tests/E2E/phpunit-e2e.xml" --list-tests` | `0` | `81` tests discovered across SelfTest + Tiers 1–4 |
| 6 | `"$SCRATCH/pelican/vendor/bin/phpunit" --configuration "$PLUGIN/tests/E2E/phpunit-e2e.xml" --testsuite="E2E SelfTest" --testdox` | `0` | `OK (13 tests, 274 assertions)` |
| 7 | `"$SCRATCH/pelican/vendor/bin/phpunit" --configuration "$PLUGIN/tests/E2E/phpunit-e2e.xml" --filter="UiCatalogLayoutAndViewE2ETest\|UiTileOverflowAndPersistenceBoundaryE2ETest\|UiModalSyncAndColumnManagerWorkflowE2ETest" --testdox` | `0` | `OK (9 tests, 665 assertions)` |
| 8 | `"$SCRATCH/pelican/vendor/bin/phpunit" --configuration "$PLUGIN/tests/E2E/phpunit-e2e.xml"` | `1` *(expected pre-M2..M5)* | `FAILURES! Tests: 81, Assertions: 1141, Failures: 52.` (**29 PASS / 52 expected pre-M2..M5 assertion failures / 0 Errors / 0 Skipped / 0 Risky**) |
| 9 | `"$SCRATCH/pelican/vendor/bin/phpunit" --configuration "$PLUGIN/phpunit.xml"` | `0` | `OK (744 tests, 5064 assertions)` |
| 10 | `rm -rf /tmp/pmm-iter2-e2e-verify-v1FPWu && test ! -e /tmp/pmm-iter2-e2e-verify-v1FPWu && git status --short` | `0` | `SCRATCH_CLEANED=yes`; `?? .agents/`, `?? tests/E2E/` (zero files touched outside ownership) |
