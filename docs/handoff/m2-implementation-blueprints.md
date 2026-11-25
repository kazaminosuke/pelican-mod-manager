> **Handoff Reference Artifact (WIP — Temporary Planning Archive)**  
> This document preserves verified research / design findings from the prior multi-agent session so subsequent sessions on `fix/modpack-wizard-spigot` do not need to repeat the same investigation.  
> See [`docs/handoff/HANDOFF.md`](HANDOFF.md) for the master handoff summary and next-session workflow. Remove or consolidate `docs/handoff/` once Milestones M2–M5 are completed and merged into permanent `docs/*.md`.

# Milestone M2 Detailed Implementation Blueprints (2/3 Pre-Audited + 1/3 Checklist)

Parts 1 and 2 below are complete file-by-file, line-by-line blueprints already audited against commit `875e30f4` (M1), Laravel 13, and Pelican Panel. Use them directly to avoid re-exploring these 15+ files.

---

# Part 1: Core Operations, Settings, Scan, ResourcePack, Queue & Scheduler (Features #5, #6, #7, #8, #12-sched)

# Handoff Report — `m2_iter1_explorer_core_ops_queue`

- **Agent**: `m2_iter1_explorer_core_ops_queue` (Read-Only Explorer, Milestone M2 Iteration 1)
- **Target Repo**: `<repo-root>` (`fix/modpack-wizard-spigot` @ Commit 1 `875e30f4`)
- **Scope**: Features #5, #6, #7, #8, and #12 (scheduler / queue / bulk-update heartbeat portions) — exact `file:line` targets, caller/test matrices, edge cases, PHPStan notes, and actionable implementation + unit test specifications.

---

## Executive Summary of Critical Findings & Gotchas

1. **Feature #5 (`ModManagerPlugin.php`)**: Confirmed **13** settings form fields in `getSettingsForm()` and **13** env writes in `saveSettings()`, vs **11** keys in `getSettingsFormData()` — `modrinth_token` and `hangar_api_key` are the exact 2 missing keys. No other settings keys are omitted or inconsistently cast.
2. **Features #6 & #8 (`InstalledProjectService.php`)**:
   - `openDaemonFileStream()` (`:1969-1978`) lacks both `$response->throw()` and a timeout override (default Panel Guzzle timeout is 15s). Adding `$response->throw()` propagates `RequestException` cleanly to all 4 callers, preventing 24h unresolved-cache poisoning.
   - **Subtle edge case found**: If a Plugin JAR fails hashing in `computeScanHashesBatch()` OR is skipped due to the max file-size guard, `$preextractedDescriptors[$filename]` is unset, causing `identifyRemainingFromArchiveMetadata()` (`:1604-1611`) to call `extractBukkitPluginDescriptor()` and **re-download the failed/oversized JAR from Wings a second time**. Setting `$preextractedDescriptors[$filename] = null` on hash failure / size-skip eliminates this.
   - Found **2** `tempnam()` + unlink + `.jar` TOCTOU races (`extractBukkitPluginDescriptor():1703-1709` and `computeDaemonFileHashesAndDescriptor():1840-1844`); `computeScanHashesParallel():1462` already uses `$tmp` directly, and `BukkitPluginDescriptor::fromJar()` (`:28-37`) uses `ZipArchive::open()` directly without needing a `.jar` extension.
   - `computeScanHashesParallel():1493` calls `PromiseUtils::settle($promises)->wait()` outside `try/finally` — must wrap to prevent leaked temp files on settlement exception.
3. **Feature #7 (`InstalledOperationManager`, `BackgroundJobRuntime`, `DatabaseBackgroundJobQueue`)**:
   - **CRITICAL CALLER FINDING (`$config` param)**: `new InstalledOperationManager($cache, $config, ...)` has **40+ positional call sites across 18 test files**. Removing `$config` WILL break them. **Keep `?ConfigRepository $config = null` as an optional nullable parameter** (zero breakage, clean DI).
   - **CRITICAL TEST BREAKAGE PREDICTION**: Extending `operationStateOrActive()` to check all 3 operations `[OPERATION_SCAN, OPERATION_BULK_UPDATE, OPERATION_MODPACK]` increases cache `get()` calls from 2 to 3. Exactly **4** existing Mockery assertions in `tests/Unit/Services/InstalledOperationManagerTest.php` (`L56, L69, L102, L353`) hardcode `->twice()` and MUST be updated to `->times(3)`.
   - `DatabaseBackgroundJobQueue::release()` can enforce a bounded release cap (`MAX_RELEASES = 100`) with **ZERO DB migration** by tracking `'_release_count'` inside the existing JSON `payload` column (stripped on `claim()`), deleting & reporting stuck rows once exceeded (simply stopping `attempts` decrement would NOT stop `ProcessBackgroundJobsCommand`, which only checks `MAX_ATTEMPTS` in `catch`!).
4. **Feature #8 (`ResourcePackService`, `ModManagerPage::performUninstall()`)**:
   - **CRITICAL TEST CONSTRAINT**: `ResourcePackServiceTest` instantiates `new ResourcePackService()` with 0 args and **no booted Laravel container/Cache facade**. `ResourcePackService::withinLock()` MUST accept optional `?CacheRepository $cache = null` (or resolve `LockProvider` only when `'cache'` is bound) so standalone tests work AND tests injecting `ArrayStore` can verify locking deterministically with `lockWaitSeconds: 0`.
   - `ModManagerPage.php:50` (`use Illuminate\Support\Facades\Http;`) is used **ONLY** at line 2388 (`performUninstall()`). Replacing L2388-2393 with `app(WingsRemoteFilesystem::class)->delete(...)` requires swapping that import to `WingsRemoteFilesystem` so Pint passes.
5. **Feature #12 (Scheduler, Lanes, Bulk Update Heartbeat)**:
   - **CRITICAL INTERFACE/SUBCLASS FINDINGS**:
     1. `src/Contracts/BackgroundJobQueue.php` is **not** in M2 owned files (and is implemented by `RecordingBackgroundJobQueue` in unit tests). Adding an optional default parameter `string $lane = self::LANE_ALL` on `DatabaseBackgroundJobQueue::claim()` and `hasPending()` is 100% Liskov-compatible with untouched `BackgroundJobQueue`.
     2. `WingsRemoteFilesystem::pullForeground()` is overridden with a strict 5-parameter signature by **3 test subclasses** (`RecordingWingsRemoteFilesystem`, `MemoryModpackWings`, `DeploymentWings`). Adding a 6th param directly to `pullForeground()` would cause PHP fatal signature errors. Instead, add a scoped `WingsRemoteFilesystem::withProgress(?Closure $onProgress, Closure $operation): mixed` wrapper (zero signature breakage!).
     3. `DatabaseBackgroundJobQueueTest.php:119,127` explicitly asserts 4 min = alive and 6 min = stale; `STALE_RESERVATION_MINUTES = 5` MUST stay `5`.
     4. `DatabaseBackgroundJobQueueTest.php:177-202` manually updates DB rows directly without `push()`; keeping `$cache = null` when constructed with 0 args (while `ModManagerServiceProvider` injects `CacheRepository` in production) keeps standalone tests deterministic and isolated.
     5. In `InstalledProjectUpdateService::updateAll()`, any ownership-loss abort raised during `installVersion()` / `pullForeground()` MUST be rethrown out of `catch (Throwable)` (never counted as `$failed++`!) and MUST skip metadata flush in `finally`.

---

## 1. Feature #5 — Settings Token-Wipe Fix (`src/ModManagerPlugin.php`)

### 1.1 Exact Audit of All 13 Settings Keys

Verified across `src/ModManagerPlugin.php` (`getSettingsFormData():189-204`, `getSettingsForm():206-356`, `saveSettings():358-380`) and `config/pelican-mod-manager.php` (`:3-89`):

| # | Form Key | `getSettingsForm()` | `getSettingsFormData()` | `config/pelican-mod-manager.php` | Default | `saveSettings()` Env Key | Save Expression |
|---|---|---|---|---|---|---|---|
| 1 | `latest_minecraft_version` | L209-212 (`required`) | L192 | L4 `latest_minecraft_version` | `'26.1.2'` | L361 `MOD_MANAGER_LATEST_MINECRAFT_VERSION` | `$data['latest_minecraft_version']` |
| 2 | `mod_nav_sort` | L213-220 (`integer`, `required`) | L193 | L6 `navigation_sort.mod` | `11` | L362 `MOD_MANAGER_MOD_NAV_SORT` | `$data['mod_nav_sort']` |
| 3 | `plugin_nav_sort` | L221-228 (`integer`, `required`) | L194 | L7 `navigation_sort.plugin` | `11` | L363 `MOD_MANAGER_PLUGIN_NAV_SORT` | `$data['plugin_nav_sort']` |
| 4 | `datapack_nav_sort` | L229-236 (`integer`, `required`) | L195 | L8 `navigation_sort.datapack` | `12` | L364 `MOD_MANAGER_DATAPACK_NAV_SORT` | `$data['datapack_nav_sort']` |
| 5 | `resourcepack_nav_sort` | L237-244 (`integer`, `required`) | L196 | L9 `navigation_sort.resourcepack` | `13` | L365 `MOD_MANAGER_RESOURCEPACK_NAV_SORT` | `$data['resourcepack_nav_sort']` |
| 6 | `curseforge_api_key` | L245-250 (`password`, `revealable`) | L197 | L11 `curseforge_api_key` | `null` | L366 `MOD_MANAGER_CURSEFORGE_API_KEY` | `$data['curseforge_api_key'] ?? ''` |
| 7 | `modrinth_token` | L251-256 (`password`, `revealable`) | **MISSING** | L12 `modrinth_token` | `null` | L367 `MOD_MANAGER_MODRINTH_TOKEN` | `$data['modrinth_token'] ?? ''` |
| 8 | `hangar_api_key` | L257-262 (`password`, `revealable`) | **MISSING** | L13 `hangar_api_key` | `null` | L368 `MOD_MANAGER_HANGAR_API_KEY` | `$data['hangar_api_key'] ?? ''` |
| 9 | `github_token` | L263-268 (`password`, `revealable`) | L198 | L14 `github_token` | `null` | L369 `MOD_MANAGER_GITHUB_TOKEN` | `$data['github_token'] ?? ''` |
| 10 | `allow_user_egg_profile_edit` | L273-276 (`Toggle`) | L199 `(bool)` | L80 `allow_user_egg_profile_edit` | `false` | L370 `MOD_MANAGER_ALLOW_USER_EGG_PROFILE_EDIT` | `... ? 'true' : 'false'` |
| 11 | `allow_user_project_install` | L277-280 (`Toggle`) | L200 `(bool)` | L86 `allow_user_project_install` | `false` | L371 `MOD_MANAGER_ALLOW_USER_PROJECT_INSTALL` | `... ? 'true' : 'false'` |
| 12 | `allow_user_project_update` | L281-284 (`Toggle`) | L201 `(bool)` | L87 `allow_user_project_update` | `false` | L372 `MOD_MANAGER_ALLOW_USER_PROJECT_UPDATE` | `... ? 'true' : 'false'` |
| 13 | `allow_user_project_delete` | L285-288 (`Toggle`) | L202 `(bool)` | L88 `allow_user_project_delete` | `false` | L373 `MOD_MANAGER_ALLOW_USER_PROJECT_DELETE` | `... ? 'true' : 'false'` |

Env-only config keys intentionally NOT in the form: `debug_timing` (L18), `warm_catalog_enabled` (L24), `warm_max_targets` (L29), `warm_rate_limit.*` (L47-55), `egg_autodetect_enabled` (L63), `egg_profiles_extra_path` (L69).

### 1.2 Implementation Spec (`src/ModManagerPlugin.php:189-204`)
Add the 2 missing keys in exact field order inside `getSettingsFormData()`:
```php
            'curseforge_api_key' => config('pelican-mod-manager.curseforge_api_key'),
            'modrinth_token' => config('pelican-mod-manager.modrinth_token'),
            'hangar_api_key' => config('pelican-mod-manager.hangar_api_key'),
            'github_token' => config('pelican-mod-manager.github_token'),
```

### 1.3 Existing Tests & New Unit Test Spec
- Existing tests touching `ModManagerPlugin`: `tests/Unit/ModManagerPluginEggProfileTest.php` (egg profile methods only) and `tests/Unit/Filament/ModManagerBrowserAssetsTest.php` (source string checks on render hooks). None test settings round-trip.
- **New test file**: `tests/Unit/ModManagerPluginSettingsTest.php`
  - Boot a minimal container with `'config'`, `'translator'`, and `'session'` (exact pattern from `ModManagerPluginEggProfileTest.php:44-53` so Filament `Notification::make()->send()` works).
  - Subclass `ModManagerPlugin` (non-final) overriding `writeToEnvironment(...)` (verify exact visibility/signature from `App\Traits\EnvironmentWriterTrait` in the Pelican Panel scratch checkout!) to capture `$this->writtenEnv`.
  - **Test 1 — `test_settings_form_data_matches_every_form_field_key`**: Inspect `getSettingsForm()`, extract `->getName()` from every `Filament\Forms\Components\Field` component (excluding `Actions` schema blocks), and assert the sorted/exact list of 13 field names equals `array_keys($plugin->getSettingsFormData())`. Prevents any future field from being omitted again.
  - **Test 2 — `test_settings_round_trip_preserves_all_thirteen_keys_including_tokens`**: Populate `config('pelican-mod-manager.*')` with non-default values for all 13 keys (including non-empty `curseforge_api_key`, `modrinth_token`, `hangar_api_key`, `github_token`, custom nav sorts, custom MC version, mixed booleans). Call `$data = $plugin->getSettingsFormData()`, assert all 13 values, call `$plugin->saveSettings($data)`, and assert all 13 `MOD_MANAGER_*` env values match verbatim (booleans serialized as `'true'`/`'false'`).
  - **Test 3 — `test_saving_unrelated_setting_does_not_wipe_existing_tokens`**: Take `$data = $plugin->getSettingsFormData()`, change only `'latest_minecraft_version' => '1.21.5'`, call `$plugin->saveSettings($data)`, and assert `MOD_MANAGER_MODRINTH_TOKEN`, `MOD_MANAGER_HANGAR_API_KEY`, `MOD_MANAGER_CURSEFORGE_API_KEY`, and `MOD_MANAGER_GITHUB_TOKEN` remain intact.

---

## 2. Features #6 & #8 — Sequential Scan Integrity & Parallel Scan Hardening (`src/Services/InstalledProjectService.php`)

### 2.1 Caller Matrix of `openDaemonFileStream()` (`src/Services/InstalledProjectService.php:1969-1978`)

| Caller | File:Line | Error Handling | Effect of Adding `$response->throw()` |
|---|---|---|---|
| `extractBukkitPluginDescriptor()` | `InstalledProjectService.php:1712` | `try { ... } catch (Throwable $e) { report($e); return null; }` (L1711-1738) | Stops writing HTTP 4xx/5xx error JSON to temp file; returns `null` cleanly |
| `computeDaemonFileHashes()` | `InstalledProjectService.php:1782` | Called inside `computeScanHashesBatch()` `try/catch` (L1375-1402) | Throws `RequestException`, caught -> `report($e)`, `$hashFailures[] = $filename` |
| `computeDaemonFileCryptoHashes()` | `InstalledProjectService.php:1806` | Same `computeScanHashesBatch()` `try/catch` (L1375-1402) | Same — adds to `$hashFailures[]`, no error JSON hashed |
| `computeDaemonFileHashesAndDescriptor()` | `InstalledProjectService.php:1869, 1873` | Same `computeScanHashesBatch()` `try/catch` (L1375-1402) + local `finally` unlink (L1905-1912) | Cleans up temp file, throws `RequestException` -> `$hashFailures[] = $filename` |

**Verified propagation of `$hashFailures`**:
- `resolveUnmatchedScanFiles()` (`:1010, 1056-1059`): `isTransientScanIdentificationFailure()` returns `true` for `$filename in $hashFailures`.
  - Known installed entry: preserved (L1013-1018).
  - Existing unresolved entry: preserved without corrupting hashes (L1022-1026).
  - New unknown file: written to `unresolved_files` **without `hashes` and without `last_checked_at`** (L1039), so it is NOT cached as checked for 24h (`UNRESOLVED_RECHECK_SECONDS`).
- `performScan()` (`:915-916`): returns `InstalledScanResult::failed('hash_computation_partial_failure', ...)`.
- `rememberScanResult()` (`:207-210`): stores as `asStale()` so next page load revalidates in background.

### 2.2 All `tempnam()` Sites in `src/Services/InstalledProjectService.php`
1. `computeScanHashesParallel()` (`:1462`): `$tmp = tempnam(sys_get_temp_dir(), 'pmm-scan-');` — already passes `$tmp` directly to `BukkitPluginDescriptor::fromJar($tmp, $filename)` (`:1517`). **No TOCTOU rename**, BUT `PromiseUtils::settle($promises)->wait()` (`:1493`) is outside `try/finally`, so any exception during `settle()->wait()` leaks all `$tempPaths`.
2. `extractBukkitPluginDescriptor()` (`:1703-1738`): **TOCTOU BUG** — creates `$tmp`, constructs `$jar = $tmp.'.jar'`, calls `@unlink($tmp)`, opens `$jar`.
   - Fix: Keep `$tmp` directly (`fopen($tmp, 'wb')`, `BukkitPluginDescriptor::fromJar($tmp, $filename)`, `@unlink($tmp)` in `finally`).
3. `computeDaemonFileHashesAndDescriptor()` (`:1840-1912`): **TOCTOU BUG** — creates `$tmp`, constructs `$jarPath = $tmp.'.jar'`, calls `@unlink($tmp)`, opens `$jarPath`.
   - Fix: Keep `$tmp` directly; if `fopen($tmp, 'wb')` fails, immediately `@unlink($tmp)` and set `$jarPath = null`; pass `$jarPath` directly to `BukkitPluginDescriptor::fromJar($jarPath, $filename)`; `@unlink($jarPath)` in `finally`.

Verified `BukkitPluginDescriptor::fromJar()` (`src/Support/BukkitPluginDescriptor.php:28-37`): opens via `$zip->open($path)` (checks ZIP headers, extension-agnostic).

### 2.3 Parallel & Sequential Scan Hardening Spec (`src/Services/InstalledProjectService.php`)
1. **Constants**:
   - `public const WINGS_FILE_DOWNLOAD_TIMEOUT_SECONDS = 60;` (overrides Panel's 15s default for large JARs while staying well under `HASH_SCAN_LOCK_SECONDS = 180` and `STALE_RESERVATION_MINUTES = 300s`).
   - `public const MAX_SCAN_FILE_BYTES = 128 * 1024 * 1024;` (128 MiB — matches `CurseForgeManualFiles::MAX_FILE_BYTES`; covers large real-world mods like Cobblemon ~110 MiB while capping 4-way parallel `/tmp` usage and rejecting giant accidental archives/backups).
2. **`openDaemonFileStream()` (`:1969-1978`)**:
   ```php
   $response = $fileRepository
       ->setServer($server)
       ->getHttpClient()
       ->timeout(self::WINGS_FILE_DOWNLOAD_TIMEOUT_SECONDS)
       ->withOptions(['stream' => true])
       ->get("/api/servers/{$server->uuid}/files/contents", ['file' => $path]);
   $response->throw();

   return $response->toPsrResponse()->getBody();
   ```
3. **File-size propagation & guard**:
   - In `performScan()` (`:467-470`), also store `'size' => is_numeric($item['size'] ?? null) ? (int) $item['size'] : null` in `$diskFiles[$key]` (works even when `modified` is omitted so `file_signature` is `null`).
   - Build `$fileSizesByFilename[$filename] = $diskFile['size']` alongside `$filesNeedingHash` (`:581-586`).
   - Add trailing optional parameter `array $fileSizesByFilename = []` (`@param array<string, int|null> $fileSizesByFilename`) to BOTH `computeScanHashesBatch()` (`:1336`) and `computeScanHashesParallel()` (`:1438`) — 0 existing overrides exist in `src/` or `tests/Unit/`, and trailing default `[]` keeps full BC.
   - **Oversized-file handling (`$size !== null && $size > self::MAX_SCAN_FILE_BYTES`)**:
     - **Skip downloading** (do NOT download to `sys_get_temp_dir()` or stream from Wings).
     - Do **NOT** append to `$hashFailures`! Rationale: adding a permanent 200 MiB file to `$hashFailures` would mark EVERY scan as `hash_computation_partial_failure` forever, show recurring error notifications, cache the scan `asStale()`, and cause infinite background rescan churn. Instead, leaving it unhashed keeps it listed in `$unknownFiles`, allows the scan to complete cleanly (`successful = true`), and skips it in O(1) with 0 HTTP calls on future scans.
     - Set `$preextractedDescriptors[$filename] = null` (when `$extractPluginDescriptors` is true) so `identifyRemainingFromArchiveMetadata()` (`:1604`) does NOT fall back to `extractBukkitPluginDescriptor()` and download the oversized JAR anyway!
     - Call `$onFileHashed()` so progress reaches `total`.
     - Guard in BOTH `computeScanHashesBatch()` (before branching to parallel vs sequential, so a single oversized file is also guarded!) AND defensively inside `computeScanHashesParallel()` (in case called directly by a subclass/test).
     - When `size === null` (minimal listing mocks without `size`), allow download as before (full BC).
   - **Also prevent double-download on hash failure**: In both `computeScanHashesBatch()` sequential `catch` (`:1399`, widen `Exception` to `Throwable`) and `computeScanHashesParallel()` `catch` (`:1519`), when `$extractPluginDescriptors` is true, set `$preextractedDescriptors[$filename] = null;` so `identifyRemainingFromArchiveMetadata()` doesn't re-request a failing Wings file a second time.
4. **Hardening `computeScanHashesParallel()` (`:1453-1528`)**:
   - Add `->timeout(self::WINGS_FILE_DOWNLOAD_TIMEOUT_SECONDS)` before `->withOptions(['sink' => $tmp])` (`:1476`).
   - Wrap `PromiseUtils::settle($promises)->wait()` + settlement processing in `try { ... } finally { foreach ($tempPaths as $leftover) { @unlink($leftover); } }` (removing each `$tempPaths[$filename]` after unlinking in the per-file `finally`) so zero temp files leak if `settle()->wait()` throws.
   - When `$response` is neither `HttpClientResponse` nor `Throwable` (e.g. `null`), throw an `Exception` rather than hashing an empty temp file.

### 2.4 Existing Tests & New Unit Test Spec
- Checked `tests/Unit/Services/ScanPerformanceAndResilienceTest.php`, `IncrementalInstalledScanTest.php`, `InstalledScanRevalidationTest.php`, `InstalledMetadataPrimingAndRetryTest.php`, `SpigotInstalledFlowTest.php`:
  - Subclasses overriding `openDaemonFileStream()` (`ScanPerformanceAndResilienceTest.php:338`, `InstalledMetadataPrimingAndRetryTest.php:171`) and `computeDaemonFileHashes()` (`InstalledScanRevalidationTest.php:331`) are unaffected (signatures unchanged; `canUseParallelWingsDownload()` detects those overrides via reflection).
- **New regression tests** (in `tests/Unit/Services/ScanPerformanceAndResilienceTest.php` or a new `tests/Unit/Services/InstalledProjectServiceScanHardeningTest.php`):
  1. **Sequential scan HTTP 500/404 integrity test**: 1 file (forces sequential path), real `openDaemonFileStream()` via `DaemonFileRepository::getHttpClient()` backed by `Http::fake()` returning HTTP 500 `{"error":"wings error"}` (and separately HTTP 404). Assert:
     - `InstalledScanResult::$successful === false`, `$result->failure === 'hash_computation_partial_failure'`.
     - Metadata `unresolved_files` entry has **NO `hashes`** (error JSON was NOT hashed!) and **NO `last_checked_at`** (not poisoned for 24h!).
     - Previously known installed entry is preserved, not demoted.
     - Verify `timeout` option on the outgoing Wings request equals `InstalledProjectService::WINGS_FILE_DOWNLOAD_TIMEOUT_SECONDS`.
  2. **Parallel scan size guard & timeout test**: 3 files in Wings directory listing — 2 normal `.jar`s + 1 oversized `.jar` (`'size' => InstalledProjectService::MAX_SCAN_FILE_BYTES + 1`), backed by `Http::fake()` on `DaemonFileRepository::getHttpClient()` (using real `computeScanHashesParallel()` — no `openDaemonFileStream` override!). Assert:
     - Wings `/files/contents` is called **ONLY** for the 2 normal JARs (NEVER for the oversized JAR).
     - Outgoing parallel requests have timeout `WINGS_FILE_DOWNLOAD_TIMEOUT_SECONDS`.
     - Scan completes with `$result->successful === true`, normal files matched/hashed, oversized file in `$result->unknownFiles`, and for `ProjectType::Plugin` with Spigot enabled, `extractBukkitPluginDescriptor()` does NOT download the oversized JAR either.
  3. **Parallel scan partial HTTP 500 test**: 2 files in parallel batch where 1 returns 200 valid JAR and 1 returns 500 error JSON. Assert 200 file hashes & extracts descriptor cleanly, 500 file is recorded as transient failure without poisoning hashes, and temp files are unlinked.
  4. **TOCTOU regression test**: Exercise `computeDaemonFileHashesAndDescriptor()` and `extractBukkitPluginDescriptor()` with real ZIP/JAR bytes (via `openDaemonFileStream` returning `Utils::streamFor($jarBytes)`) and assert descriptor is extracted from `plugin.yml` AND no stray `.jar` temp file is created or left behind.

---

## 3. Feature #7 — Operation Concurrency, Worker State Hygiene & Queue Reliability

### 3.1 `src/Services/InstalledOperationManager.php`

#### A. `operationStateOrActive()` (`:606-634`)
Currently checks only `$requestedOperation` and one hardcoded `$otherOperation` (either `OPERATION_BULK_UPDATE` or `OPERATION_SCAN`), completely omitting `OPERATION_MODPACK` (and omitting `OPERATION_BULK_UPDATE` when `$requestedOperation === OPERATION_MODPACK`).

**Implementation spec**:
```php
    /** @var list<string> */
    private const TRACKED_OPERATIONS = [
        self::OPERATION_SCAN,
        self::OPERATION_BULK_UPDATE,
        self::OPERATION_MODPACK,
    ];
```
In `operationStateOrActive()`:
```php
    private function operationStateOrActive(
        int $serverId,
        ProjectType $projectType,
        string $requestedOperation,
    ): ?InstalledOperationState {
        $leaseOperation = null;
        $leaseLoaded = false;
        $current = $this->state($serverId, $projectType, $requestedOperation);

        if ($current?->isActive()) {
            $leaseOperation = $this->leases->currentOperation($serverId, $projectType);
            $leaseLoaded = true;
            $current = $this->stateMatchingLease($current, $leaseOperation);

            if ($current !== null) {
                return $current;
            }
        }

        foreach (self::TRACKED_OPERATIONS as $otherOperation) {
            if ($otherOperation === $requestedOperation) {
                continue;
            }

            $other = $this->state($serverId, $projectType, $otherOperation);

            if ($other?->isActive()) {
                if (!$leaseLoaded) {
                    $leaseOperation = $this->leases->currentOperation($serverId, $projectType);
                    $leaseLoaded = true;
                }

                $other = $this->stateMatchingLease($other, $leaseOperation);

                if ($other?->isActive()) {
                    return $other;
                }
            }
        }

        return $current;
    }
```
- **Edge cases verified**:
  - `STATUS_WAITING_FILES` / `STATUS_WAITING_REVIEW` modpack pause states have `isActive() === false` (`InstalledOperationState.php:261`), so a paused CurseForge modpack does NOT block `dispatchScan()` (`ModpackDispatchTest.php:107-122` stays green!).
  - An active (`queued`/`running`) `OPERATION_MODPACK` with an active `OPERATION_MODPACK` lease now blocks `dispatchScan()`, `dispatchScanIfDue()` (and correctly clears its auto-throttle key because `$dispatch['state']?->operation !== OPERATION_SCAN`), `dispatchBulkUpdate()`, and `dispatchMetadataReset()`, returning the active `OPERATION_MODPACK` state.
  - An active `OPERATION_BULK_UPDATE` now also returns its state from `dispatchModpackInstall()` instead of `null`.
  - `$leaseLoaded` prevents querying the lease cache twice when no lease is currently held (`currentOperation() === null`).
- **Existing tests that MUST be updated**:
  - `tests/Unit/Services/InstalledOperationManagerTest.php` lines **56**, **69**, **102**, and **353**: change `$cache->shouldReceive('get')->twice()->andReturnNull();` → `->times(3)`! Verified via grep across all of `tests/Unit/` that NO other test mocks `CacheRepository::get` with `->twice()` for `InstalledOperationManager`.

#### B. `clearRuntimeCaches()` & `BackgroundJobRuntime` (`src/Support/BackgroundJobRuntime.php:15-46`)
- Add to `InstalledOperationManager`:
  ```php
  public function clearRuntimeCaches(): void
  {
      $this->progressBuffer = [];
  }
  ```
- In `src/Support/BackgroundJobRuntime.php`:
  - Import `Kazaminosuke\ModManager\Services\InstalledOperationManager`.
  - Add `InstalledOperationManager::class` to the services array at L27 and include `|| $resolved instanceof InstalledOperationManager` at L38 so `$resolved->clearRuntimeCaches()` runs between every job in `ProcessBackgroundJobsCommand`.

#### C. Constructor `$config` Parameter (`src/Services/InstalledOperationManager.php:58-67`)
- **Caller audit**: Zero callers in `src/` use `new InstalledOperationManager(...)` (resolved by Laravel DI via `ModManagerServiceProvider`), **HOWEVER 40+ call sites across 18 files in `tests/Unit/`** pass `new InstalledOperationManager($cache, $config, ...)` positionally (including `InstalledOperationManagerTest`, `ModpackDispatchTest`, `ModpackUpdateWorkflowTest`, `ModpackRegressionTest`, `ScanInstalledProjectsTest`, `ResetInstalledMetadataTest`, `SourceCacheTest`, `HangarSourceSearchCacheTest`, `SpigotSourceTest`, `ModpackSearchTest`, etc.).
- **Decision (DO NOT REMOVE `$config`)**: Change parameter 2 from `ConfigRepository $config` to `?ConfigRepository $config = null` (keeping `unset($config);`). This breaks **zero** callers while allowing new callers to omit `$config`.

### 3.2 `src/Support/DatabaseBackgroundJobQueue.php`

Schema verified from `database/migrations/2026_09_21_000004_create_mod_manager_background_jobs_table.php:20-29`:
`id`, `type (varchar 64)`, `payload (json)`, `unique_key (nullable, indexed)`, `available_at (timestamp, indexed)`, `reserved_at (nullable timestamp, indexed)`, `attempts (unsignedSmallInteger default 0)`, `created_at`, `updated_at`.

#### A. Bounded Max-Release Guard in `release()` (NO DB Migration Needed!)
- **Legitimate ceiling check**:
  - `ScanInstalledProjects`: `BUSY_TIMEOUT_SECONDS (600) / BUSY_RETRY_SECONDS (15) = 40` releases max.
  - `InstallModpack`: `deferSeconds (1200) / 30 = 40` releases max.
- **Constants & constructor**:
  - `public const MAX_RELEASES = 100;`
  - `private const RELEASE_COUNT_KEY = '_release_count';`
  - `public function __construct(private readonly ?CacheRepository $cache = null, private readonly int $maxReleases = self::MAX_RELEASES) {}`
- **Mechanism**:
  - Inside `release(int|string $id, int $delaySeconds, ?int $expectedAttempts = null): void`:
    - Run inside `ModManagerBackgroundJob::query()->getConnection()->transaction(...)`:
      - Query `whereKey($id)->where('attempts', '>', 0)` (+ `where('attempts', $expectedAttempts)` if `$expectedAttempts !== null`) with `->lockForUpdate()->first()`.
      - If `!$job instanceof ModManagerBackgroundJob`, return (preserves stale-attempt fencing!).
      - Read `$payload = is_array($job->payload) ? $job->payload : [];`
      - Read `$releases = is_numeric($payload[self::RELEASE_COUNT_KEY] ?? null) ? (int) $payload[self::RELEASE_COUNT_KEY] : 0;`
      - **If `$releases >= $this->maxReleases`**:
        - Delete `$job->delete();` (simply leaving the row without decrementing `attempts` would NOT stop `ProcessBackgroundJobsCommand` because `ProcessBackgroundJobsCommand` only checks `MAX_ATTEMPTS` inside `catch (Throwable)`!).
        - If `is_string($job->unique_key) && $job->unique_key !== ''`, forget `$job->unique_key` from `$this->cache` (if available) so future dispatches aren't stuck waiting on the unique TTL.
        - Call `self::reportException(new \RuntimeException("Background job [{$job->type}] (#{$job->getKey()}) exceeded maximum release count ({$this->maxReleases}) and was discarded."));`
        - Return!
      - **Else**:
        - `$payload[self::RELEASE_COUNT_KEY] = $releases + 1;`
        - `$job->payload = $payload;`
        - `$job->attempts = max(0, ((int) $job->attempts) - 1);`
        - `$job->reserved_at = null;`
        - `$job->available_at = now()->addSeconds(max(0, $delaySeconds));`
        - `$job->save();`
        - Update cache pending hints for `$job->type` (see §5A).
  - Inside `claim()` (`:104-111`): strip `unset($payload[self::RELEASE_COUNT_KEY]);` before returning `$payload` so job handlers and callers see the exact original payload.

#### B. Safe `report()` Logging on Unexpected DB Exceptions
- Add helper on `DatabaseBackgroundJobQueue`:
  ```php
  private static function reportException(Throwable $exception): void
  {
      try {
          if (Container::getInstance()->bound(ExceptionHandler::class)) {
              report($exception);
          }
      } catch (Throwable) {
          // Missing exception handler or log bindings in unit tests must not break fallbacks.
      }
  }
  ```
- Invoke `self::reportException($exception)` in ALL 7 `catch (Throwable $exception)` blocks: `push()` (L48), `hasPending()` (L65), `claim()` (L113), `ack()` (L126), `retry()` (L142), `release()` (L160), `heartbeat()` (L173).
- Safe in standalone PHPUnit tests (where `ExceptionHandler::class` is unbound -> no-op, returns `false`/`null`), reports cleanly in production Pelican Panel AND verifiable in unit tests by binding a spy `ExceptionHandler`!

### 3.3 Unit Test Spec for Feature #7
- **`tests/Unit/Services/InstalledOperationManagerTest.php`**:
  - Update existing mock counts on L56, L69, L102, L353 (`->twice()` → `->times(3)`).
  - Add `test_active_modpack_install_blocks_scan_bulk_update_and_metadata_reset`: dispatch modpack install, assert `dispatchScan()`, `dispatchBulkUpdate()`, and `dispatchMetadataReset()` all return `dispatched: false, reason: 'already_active'` with `state->operation === OPERATION_MODPACK`, and `dispatchScanIfDue()` returns `already_active` WITHOUT holding the auto-scan throttle key (so scan dispatches immediately once modpack completes).
  - Add `test_active_bulk_update_blocks_modpack_install_with_active_state`: verify `dispatchModpackInstall()` returns the active `OPERATION_BULK_UPDATE` state.
  - Add `test_clear_runtime_caches_and_background_job_runtime_discard_buffered_progress`: write buffered progress via `progress()`, overwrite underlying cache directly (simulating another worker completing/changing state), assert `state()` still returns buffered value before `BackgroundJobRuntime::forgetRequestState()`, then bind `InstalledOperationManager` in `Container`, call `BackgroundJobRuntime::forgetRequestState()`, and assert `state()` now reads fresh cache state.
  - Add `test_constructor_allows_omitting_config_repository`: assert `new InstalledOperationManager($cache)` works cleanly.
- **`tests/Unit/Support/DatabaseBackgroundJobQueueTest.php`**:
  - Add `test_release_enforces_maximum_release_count_and_discards_stuck_job`: instantiate `new DatabaseBackgroundJobQueue($cache, maxReleases: 2)`, push job with `_unique_key`, claim + release twice (still claimable with `attempts === 1` and `_release_count` stripped from returned payload), claim 3rd time + release -> row is deleted (`count() === 0`), unique key is forgotten from `$cache`, spy `ExceptionHandler` received the `RuntimeException`, next `claim()` returns `null`.
  - Add `test_unexpected_database_exceptions_are_reported_when_handler_is_bound`: bind a spy `ExceptionHandler`, drop `mod_manager_background_jobs` table, call `push`, `hasPending`, `claim`, `ack`, `retry`, `release`, `heartbeat` -> assert each returns safe fallback (`false`/`null`/void) AND spy captured all 7 exceptions. Verify also with NO `ExceptionHandler` bound -> zero uncaught exceptions.

---

## 4. Feature #8 — ResourcePack Concurrency Lock & `performUninstall()` Abstraction

### 4.1 `src/Services/ResourcePackService.php`

#### A. Per-Server Cache Lock (Matching `InstalledMetadataRepository::withinLock()`)
- Add constants:
  - `public const LOCK_SECONDS = 60;`
  - `public const LOCK_WAIT_SECONDS = 20;`
- Add static key helper:
  ```php
  public static function lockKey(int $serverId): string
  {
      return 'mod_manager_resource_pack:v1:'.$serverId;
  }
  ```
- Add backward-compatible constructor:
  ```php
  public function __construct(
      private readonly ?CacheRepository $cache = null,
      private readonly int $lockWaitSeconds = self::LOCK_WAIT_SECONDS,
  ) {}
  ```
  - `new ResourcePackService()` (existing `ResourcePackServiceTest.php:46,83,100`) works unchanged.
  - Laravel DI (`app(ResourcePackService::class)`) injects `CacheRepository` automatically.
- Add `private function withinLock(Server $server, Closure $callback): mixed`:
  - Resolve `LockProvider`: inspect `$this->cache?->getStore()` first; if `$this->cache === null` and `Container::getInstance()->bound('cache')`, resolve `'cache'` (handling both `CacheRepository` and `CacheManager` via `store()`) and inspect `getStore()`.
  - If `$store instanceof LockProvider`: call `$store->lock(self::lockKey((int) $server->id), self::LOCK_SECONDS)->block($this->lockWaitSeconds, $callback)`. Catch `LockTimeoutException` and throw `new InstalledOperationBusyException('Another resource pack operation is currently in progress.', previous: $exception)` — note `ModManagerPage::notifyProjectOperationFailure()` (`:5748-5755`) ALREADY handles `InstalledOperationBusyException` with the localized warning `pelican-mod-manager::strings.operations.already_active` (zero `lang/` changes needed!).
  - If no `LockProvider` is bound (standalone PHPUnit without container): execute `$callback()` directly.
  - **PHPStan note**: Calling `$store->lock(...)` directly on a `LockProvider`-narrowed variable avoids PHPStan errors on `CacheRepository` (which doesn't declare `lock()` on the interface).
- Wrap `installOrUpdate()` critical section (AFTER `$metadata = $this->metadataFromVersion(...)` pure validation) and `uninstall()` body inside `$this->withinLock($server, ...)`.

#### B. Missing `server.properties` Handling
- **`installOrUpdate()` (`:77-109`)**:
  - Catch `FileNotFoundException` on `$repository->getContent('server.properties')`:
    - Set `$oldProperties = ''; $propertiesExisted = false;`.
    - Compute `$newProperties = ServerProperties::withResourcePack($oldProperties, $metadata['url'], $metadata['sha1']);` (ensure trailing `"\n"` if `!$propertiesExisted && !str_ends_with($newProperties, "\n")`).
    - Write `server.properties`, then write `self::METADATA_FILENAME`.
    - On metadata write failure rollback: if `$propertiesExisted` -> `putContent('server.properties', $oldProperties)`; if `!$propertiesExisted` -> `deleteFiles('/', ['server.properties'])`.
    - Non-`FileNotFoundException` read errors (Wings unreachable/500) MUST NOT be treated as missing — let them propagate so we never clobber an existing `server.properties`!
- **`uninstall()` (`:115-139`)**:
  - Catch `FileNotFoundException` on `$repository->getContent('server.properties')`:
    - Set `$propertiesExisted = false; $oldProperties = null;`.
    - Only write cleared `server.properties` when `$propertiesExisted && $oldProperties !== null`.
    - Proceed to `$repository->deleteFiles('/', [self::METADATA_FILENAME])` so orphaned metadata can still be uninstalled cleanly!
    - On `deleteFiles()` failure rollback: only restore `server.properties` when `$propertiesExisted && $oldProperties !== null`.
- **Bonus bug fix**: Replace bare `report($rollbackException)` at L101 and L132 with a private `reportException(Throwable $exception)` helper checking `app()->bound(ExceptionHandler::class)` (matching L42 and L313), preventing crashes during rollback unit tests without an `ExceptionHandler`.

#### C. Unit Test Spec (`tests/Unit/Services/ResourcePackServiceTest.php`)
1. `test_install_creates_server_properties_when_missing_and_rolls_back_by_deleting_it_on_metadata_failure` (2 tests or separate install-success + rollback-delete tests).
2. `test_uninstall_clears_server_properties_and_deletes_metadata` + `test_uninstall_deletes_metadata_even_when_server_properties_is_missing`.
3. `test_install_and_uninstall_serialize_behind_per_server_cache_lock_and_throw_busy_when_contended`: construct `new ResourcePackService($cache, lockWaitSeconds: 0)` with `ArrayStore`, acquire `ResourcePackService::lockKey(4)` beforehand, assert both `installOrUpdate()` and `uninstall()` throw `InstalledOperationBusyException` without calling `getContent`/`putContent`/`deleteFiles`, then release lock and assert operation succeeds.
4. `test_install_does_not_overwrite_server_properties_on_transport_error`: `getContent('server.properties')` throws `RuntimeException` -> propagates, `putContent` never called.

### 4.2 `src/Filament/Server/Pages/ModManagerPage.php` — `performUninstall()` ONLY (ZERO UI Changes!)
- **Exact lines**:
  - Line 50: remove `use Illuminate\Support\Facades\Http;` (verified ZERO other usages of `Http::` anywhere in `ModManagerPage.php`).
  - After line 102 (`use Kazaminosuke\ModManager\Support\ServerModManagerSettings;`): add `use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;`.
  - Lines 2388–2393: replace:
    ```php
        Http::daemon($server->node)
            ->post("/api/servers/{$server->uuid}/files/delete", [
                'root' => '/',
                'files' => [$folder.'/'.$safeFilename],
            ])
            ->throw();
    ```
    with:
    ```php
        app(WingsRemoteFilesystem::class)->delete($fileRepository, $server, $folder, $safeFilename);
    ```
- **Verification**:
  - `WingsRemoteFilesystem::delete()` calls `$fileRepository->setServer($server)->deleteFiles($folder === '' ? '/' : $folder, [$safeFilename])->throw()` (now with Feature #10's pre-send transport retries and without lazy-loading `$server->node`).
  - Caller at L3708 (`catch (Exception $exception)` at L3721) catches `RequestException` identically.
  - **Unit test**: Add a targeted test (e.g., via reflection invoking `performUninstall` on a test subclass or verifying via a dedicated test in `tests/Unit/Filament/ModManagerPagePayloadTest.php` / new `ModManagerPageUninstallTest.php`) binding a mock/recording `WingsRemoteFilesystem` in `Container`, asserting `delete($fileRepository, $server, 'mods', 'example.jar')` is called and `Http::daemon()` is NEVER invoked!

---

## 5. Feature #12 — Scheduler Idle DB Churn, Worker Starvation & Bulk Update Heartbeat

### 5.1 Lightweight Cache Flag Gating `hasPending()` (Zero Lost Wakeups + Fail-Open Safety)

#### Design (`src/Support/DatabaseBackgroundJobQueue.php`)
Use a race-free **two-key dirty-token + `idle_until`** protocol per lane scope (`all`, `fast`, `heavy`):
- Constants:
  ```php
  public const LANE_ALL = 'all';
  public const LANE_FAST = 'fast';
  public const LANE_HEAVY = 'heavy';

  /** @var list<string> */
  public const HEAVY_TYPES = [
      BackgroundJob::BULK_UPDATE,
      BackgroundJob::MODPACK,
  ];

  private const IDLE_RECHECK_SECONDS = 60;
  private const DIRTY_TTL_SECONDS = 600;
  private const CACHE_PREFIX = 'mod_manager_bg_queue:v1';
  ```
- Cache keys for scope `$lane`:
  - Dirty key: `mod_manager_bg_queue:v1:dirty:{$lane}` (random token string, TTL `600s`)
  - Idle-until key: `mod_manager_bg_queue:v1:idle_until:{$lane}` (Unix int timestamp, TTL `$sleepSeconds`)
- **Behavior**:
  1. **When `$this->cache === null`** (standalone unit tests using `new DatabaseBackgroundJobQueue()`): always query DB directly — guarantees 100% compatibility with existing `DatabaseBackgroundJobQueueTest::test_has_pending_tracks_claimable_jobs()` (which mutates DB directly at L195-200).
  2. **On `push($type, $payload)` (after DB insert succeeds)**:
     - Call `$this->markDirtyForType($type)`: sets fresh random token on `dirty:{laneForType($type)}` and `dirty:all` (TTL `600s`), and forgets `idle_until:{laneForType($type)}` and `idle_until:all`.
  3. **On `retry()` (after DB update)**:
     - Call `$this->markDirtyAllLanes()` (marks `all`, `fast`, `heavy` dirty & forgets their `idle_until` — avoids an extra SELECT to look up job type on rare error retries).
  4. **On `release()` (after saving released row)**:
     - Call `$this->markDirtyForType((string) $job->type)` (we already loaded `$job` inside the transaction!).
  5. **On `hasPending(string $lane = self::LANE_ALL): bool`**:
     - Normalize `$lane = self::normalizeLane($lane)`.
     - Read `$dirtyToken = $this->cacheGet($this->dirtyKey($lane))` and `$idleUntil = $this->cacheGet($this->idleUntilKey($lane))`.
     - **Fast path (0 DB queries)**: if `$dirtyToken === null && is_int($idleUntil) && $idleUntil > time()`, return `false`!
     - **DB query path**:
       - Check claimable existence for `$lane` (`available_at <= now()` AND (`reserved_at IS NULL` OR `reserved_at < now()->subMinutes(STALE_RESERVATION_MINUTES)`) + lane type filter).
       - If `true`: forget `idle_until:{$lane}` and return `true`.
       - If `false`: compute `$sleepSeconds = $this->idleSleepSecondsForLane($lane)`:
         - Query earliest wake-up event for `$lane`:
           - `$nextAvailable = (clone $base)->whereNull('reserved_at')->min('available_at')` -> timestamp `$availTs`
           - `$nextReserved = (clone $base)->whereNotNull('reserved_at')->min('reserved_at')` -> `$staleTs = $reservedTs + STALE_RESERVATION_MINUTES * 60`
           - *(Optimization: run ONE single `->where(...)` existence check or aggregate? Even simpler: `->selectRaw('MIN(CASE WHEN reserved_at IS NULL THEN available_at END) as next_available, MIN(reserved_at) as next_reserved')->first()` is valid standard SQL in SQLite, MySQL, and PostgreSQL — 1 single query! Verify `strtotime()` parsing on both returned values!)*
           - If both are `null` (0 rows in lane): `$sleepSeconds = self::IDLE_RECHECK_SECONDS` (`60`).
           - Else compute `$wakeAt = min(non-null timestamps)` -> `$sleepSeconds = min(self::IDLE_RECHECK_SECONDS, max(0, $wakeAt - time()))`.
         - **Lost-wakeup race check**: Re-read `$currentDirty = $this->cacheGet($this->dirtyKey($lane))`.
           - ONLY if `$currentDirty === $dirtyToken` (no concurrent `push()` landed during our DB query!):
             - If `$dirtyToken !== null`, forget `dirty:{$lane}`.
             - If `$sleepSeconds > 0`, put `idle_until:{$lane} = time() + $sleepSeconds` with TTL `$sleepSeconds`.
         - Return `false`.
- **Safety properties proved**:
  - **Idle panel**: Drops from 30–60 DB queries/min to **1–2 DB queries/min** (97% reduction).
  - **Cache flush / Redis restart**: `idle_until` is missing -> immediately fails open to DB on next tick (never strands rows!).
  - **Concurrent `push()` race**: Dirty token changes during DB read -> `hasPending()` refuses to write `idle_until` -> next tick claims immediately (zero lost wakeups!).
  - **Delayed `release($id, 15)`**: `$wakeAt = now + 15` -> 0 DB queries for 15s, wakes precisely when claimable!
  - **SIGKILLed worker**: `$wakeAt = reserved_at + 300`, clamped to 60s -> reclaims cleanly once stale!
  - **Cache outage (`$this->cache` throws)**: Wrap cache reads/writes in `try/catch (Throwable)` failing open to DB so a Redis hiccup never stops queue draining!

### 5.2 Separate Lanes to Prevent Worker Starvation

1. **`DatabaseBackgroundJobQueue` (`src/Support/DatabaseBackgroundJobQueue.php`)**:
   - `public static function laneForType(string $type): string`: returns `in_array($type, self::HEAVY_TYPES, true) ? self::LANE_HEAVY : self::LANE_FAST`.
   - `public static function normalizeLane(?string $lane): string`: trims/lowercases; accepts `all`, `fast`, `heavy`; empty/`null` defaults to `all`; invalid returns `''` (or dedicated `isValidLane()` check in command).
   - Helper `applyLaneFilter(Builder $query, string $lane)`:
     - `LANE_HEAVY` -> `$query->whereIn('type', self::HEAVY_TYPES)`
     - `LANE_FAST` -> `$query->whereNotIn('type', self::HEAVY_TYPES)` *(ensures unknown/poison job types are still drained and never orphaned!)*
     - `LANE_ALL` -> no filter.
   - `public function hasPending(string $lane = self::LANE_ALL): bool`
   - `public function claim(string $lane = self::LANE_ALL): ?array` — Liskov-compatible with untouched `src/Contracts/BackgroundJobQueue.php`!
2. **`ProcessBackgroundJobsCommand` (`src/Console/Commands/ProcessBackgroundJobsCommand.php`)**:
   - Signature:
     ```php
     protected $signature = 'mod-manager:process-jobs {--lane=all : Job lane to drain (all, fast, or heavy)}';
     ```
   - Read lane safely (works even if invoked directly in tests without `setInput()`):
     ```php
     $rawLane = ($this->input !== null && $this->input->hasOption('lane'))
         ? (string) $this->option('lane')
         : DatabaseBackgroundJobQueue::LANE_ALL;
     $lane = strtolower(trim($rawLane === '' ? DatabaseBackgroundJobQueue::LANE_ALL : $rawLane));
     if (!in_array($lane, [DatabaseBackgroundJobQueue::LANE_ALL, DatabaseBackgroundJobQueue::LANE_FAST, DatabaseBackgroundJobQueue::LANE_HEAVY], true)) {
         $this->error("Invalid background job lane [{$rawLane}]. Expected all, fast, or heavy.");

         return self::INVALID;
     }
     ```
   - Claim call inside loop (L47):
     ```php
     $job = $queue instanceof DatabaseBackgroundJobQueue
         ? $queue->claim($lane)
         : $queue->claim();
     ```
   - 100% backward-compatible with `php artisan mod-manager:process-jobs` and `ProcessBackgroundJobsCommandTest` (`RecordingBackgroundJobQueue`).
3. **`ModManagerServiceProvider` (`src/Providers/ModManagerServiceProvider.php`)**:
   - Line 57: Bind `BackgroundJobQueue::class` singleton via factory closure injecting `CacheRepository` (matching `PluginBackgroundRunner` factory at L59-81):
     ```php
     $this->app->singleton(BackgroundJobQueue::class, function ($app): DatabaseBackgroundJobQueue {
         $cache = null;

         try {
             if ($app->bound(CacheRepository::class)) {
                 $resolved = $app->make(CacheRepository::class);
                 $cache = $resolved instanceof CacheRepository ? $resolved : null;
             }
         } catch (\Throwable) {
             $cache = null;
         }

         return new DatabaseBackgroundJobQueue($cache);
     });
     ```
   - Lines 151-166: Register TWO separate scheduled lane events with distinct `--lane` arguments and distinct mutex names & overlap timeouts:
     ```php
     $schedule->command(ProcessBackgroundJobsCommand::class, ['--lane' => DatabaseBackgroundJobQueue::LANE_FAST])
         ->everyTwoSeconds()
         ->when(fn (): bool => $this->hasPendingBackgroundJobs(DatabaseBackgroundJobQueue::LANE_FAST))
         ->withoutOverlapping(10)
         ->runInBackground()
         ->name('mod-manager:process-jobs:fast');
     $schedule->command(ProcessBackgroundJobsCommand::class, ['--lane' => DatabaseBackgroundJobQueue::LANE_HEAVY])
         ->everyTwoSeconds()
         ->when(fn (): bool => $this->hasPendingBackgroundJobs(DatabaseBackgroundJobQueue::LANE_HEAVY))
         ->withoutOverlapping(30)
         ->runInBackground()
         ->name('mod-manager:process-jobs:heavy');
     ```
     Where `private function hasPendingBackgroundJobs(string $lane): bool` resolves `BackgroundJobQueue::class` and returns `!($queue instanceof DatabaseBackgroundJobQueue) || $queue->hasPending($lane)`.
   - **Check**: Because Laravel's `Schedule\Event::mutexName()` hashes the command string (including `--lane=fast` vs `--lane=heavy`), the two schedules have completely independent overlap mutexes! A 15-minute Modpack install or Bulk Update NEVER starves scans, metadata fills, resets, or revalidations across other servers, AND the heavy lane gets a 30-minute overlap guard instead of expiring after 10 minutes.

### 5.3 Bulk Update Heartbeat & Reservation Alignment

#### Root Cause Verified
- `DatabaseBackgroundJobQueue::STALE_RESERVATION_MINUTES = 5` (300s — MUST stay `5` per `DatabaseBackgroundJobQueueTest.php:119,127`).
- `WingsRemoteFilesystem::FOREGROUND_PULL_TIMEOUT_SECONDS = 900` (15 min).
- Currently `BulkUpdateInstalledProjects::handle()` (`:83-105`) ONLY renews `$this->heartbeat` & `$leases->refresh()` inside `$progress` **after** each item completes. If `lookupInstalled()` + one large `pullForeground()` exceeds 5 minutes, a replacement worker reclaims the DB row mid-transfer!

#### Implementation Spec (Zero Subclass Breakage!)
1. **`WingsRemoteFilesystem` (`src/Support/WingsRemoteFilesystem.php`)**:
   - **DO NOT change `pullForeground()`'s 5-parameter signature** — 3 existing test subclasses (`RecordingWingsRemoteFilesystem`, `MemoryModpackWings`, `DeploymentWings`) override `pullForeground()` with exact 5 args!
   - Add `private ?Closure $progressCallback = null;` and:
     ```php
     /**
      * @template T
      *
      * @param  (Closure(): void)|null  $onProgress
      * @param  Closure(): T  $operation
      * @return T
      */
     public function withProgress(?Closure $onProgress, Closure $operation): mixed
     {
         $previous = $this->progressCallback;
         $this->progressCallback = $onProgress;

         try {
             return $operation();
         } finally {
             $this->progressCallback = $previous;
         }
     }
     ```
   - Inside `pullForeground()` (and `putStream()`): if `$this->progressCallback !== null`, attach `'progress' => static function () use ($callback): void { $callback(); }` via `->withOptions(...)`. libcurl invokes `CURLOPT_XFERINFOFUNCTION` ~every 1s throughout the entire request lifecycle (including waiting on Wings TTFB during remote pull!).
   - *(Note to Worker combining Feature #10 & #12 on `WingsRemoteFilesystem`: make sure `withProgress()` stays active across Feature #10's retry attempts.)*
2. **`InstalledArchiveTransaction::installOrUpdate()` (`src/Services/InstalledArchiveTransaction.php:42-52`)**:
   - Add optional trailing parameter `?Closure $heartbeat = null` (`@param (Closure(): bool)|null $heartbeat`).
   - Helper closure `$assertAlive = static function () use ($heartbeat): void { if ($heartbeat !== null && !$heartbeat()) { throw new RuntimeException('Background operation ownership was lost.'); } };`.
   - Call `$assertAlive()` before starting `pullForeground()`/`putStream()`.
   - Wrap `pullForeground()` (and `putStream()`) inside `$this->wings->withProgress($heartbeat !== null ? $assertAlive : null, fn () => ...)` so long pulls renew DB reservation + cache lease mid-transfer and abort immediately if superseded!
   - Call `$assertAlive()` right BEFORE `move()` activation (L99) so a superseded worker never overwrites a live JAR or commits metadata (temp file gets deleted in `catch`!).
3. **`InstalledProjectUpdateService::updateAll()` (`src/Services/InstalledProjectUpdateService.php:27-87`)**:
   - Add optional trailing parameter `?Closure $heartbeat = null` (`@param (Closure(): bool)|null $heartbeat`).
   - Track `$ownershipLost = false;` and wrap `$heartbeat` into:
     ```php
     $checkHeartbeat = static function () use ($heartbeat, &$ownershipLost): bool {
         if ($heartbeat === null) {
             return true;
         }
         if ($ownershipLost) {
             return false;
         }
         if (!$heartbeat()) {
             $ownershipLost = true;

             return false;
         }

         return true;
     };
     ```
   - Call `if (!$checkHeartbeat()) { throw new RuntimeException('Bulk update ownership was lost.'); }` right after `$this->versions->lookupInstalled(...)` (L45) AND at the start of every `foreach` iteration (L59, before `try`).
   - Pass `$checkHeartbeat` into `$this->installVersion(..., $checkHeartbeat)` -> `$this->archives->installOrUpdate(..., heartbeat: $checkHeartbeat)`.
   - **CRITICAL in `catch (Throwable $exception)` (L69)**:
     ```php
     if ($ownershipLost) {
         throw $exception;
     }
     ```
     Never swallow an ownership-loss abort as `$failed++`!
   - **CRITICAL in `finally` (both per-item L72 AND loop-level checkpoint flush for Feature #12)**:
     - Skip calling `$progress(...)` if `$ownershipLost`.
     - When flushing checkpointed metadata in `InstalledProjectUpdateService` (Feature #12), ONLY flush if `!$ownershipLost` so a fenced worker never writes stale metadata after losing the lease!
4. **`BulkUpdateInstalledProjects::handle()` (`src/Jobs/BulkUpdateInstalledProjects.php:76-126`)**:
   - Create a single `$pulseOwnership` closure returning `bool` (throttled by `LEASE_REFRESH_SECONDS = 30`, with optional `$force = false` for initial/final check; sets `$ownershipError` string when `$this->heartbeat()` returns `false` or `$leases->refresh()` returns `false`).
   - Pass `$pulseOwnership` as the `$heartbeat` argument to `$updates->updateAll($server, $fileRepository, $type, $onProgress, $pulseOwnership)`.
   - Inside `$onProgress`: also call `if (!$pulseOwnership()) { throw new RuntimeException($ownershipError ?? 'The bulk-update operation ownership was lost.'); }` before `$operations->progress(...)`.
   - Before `$operations->complete(...)`: check `if (!$pulseOwnership(force: true))` -> return without completing if ownership was lost! (And in `catch`: `$operations->fail()` is already lease+epoch fenced at `InstalledOperationManager.php:578`, or if `$ownershipError !== null` we can still report/fail safely since `fail()` won't touch a replacement owner's state.)

### 5.4 Unit Test Spec for Feature #12 (Scheduler / Queue / Heartbeat)
1. **`tests/Unit/Support/DatabaseBackgroundJobQueueTest.php`**:
   - `test_has_pending_uses_cache_gate_to_avoid_idle_db_queries_and_wakes_on_push`: inject `ArrayStore` `CacheRepository`, attach PDO/Eloquent query counter (`Capsule::connection()->enableQueryLog()`), assert 1st `hasPending()` runs DB query and returns `false`, next 10 `hasPending()` calls run **0 DB queries**; `push()` immediately wakes `hasPending()` -> returns `true`; flushing cache (`$cache->flush()`) with a pending DB row fails open and returns `true`.
   - `test_has_pending_and_claim_isolate_fast_and_heavy_lanes`: push `MODPACK` + `BULK_UPDATE` + `SCAN` + `REVALIDATE`. Assert `hasPending(LANE_FAST)` / `claim(LANE_FAST)` ONLY see `SCAN` & `REVALIDATE`; `hasPending(LANE_HEAVY)` / `claim(LANE_HEAVY)` ONLY see `BULK_UPDATE` & `MODPACK`; `claim(LANE_ALL)` sees all in priority order; pushing a `MODPACK` does NOT trigger a DB query on an already-idle `LANE_FAST`.
   - `test_has_pending_wakes_for_delayed_release_without_push`: push -> claim -> `release($id, 1)` -> `hasPending()` returns `false` -> wait/advance or expire `idle_until` -> returns `true`.
2. **`tests/Unit/Console/Commands/ProcessBackgroundJobsCommandTest.php`**:
   - `test_lane_option_filters_claimed_jobs_and_defaults_to_all`: test with real SQLite `DatabaseBackgroundJobQueue` — push `MODPACK` and `SCAN`, run with `['--lane' => 'fast']` (assert only `SCAN` claimed/processed while `MODPACK` remains untouched), then run with `['--lane' => 'heavy']` (assert `MODPACK` claimed), and test invalid `--lane=nope` returns `ProcessBackgroundJobsCommand::INVALID`.
3. **`tests/Unit/Providers/ModManagerServiceProviderTest.php`**:
   - Add `test_scheduler_registers_independent_fast_and_heavy_lanes_with_distinct_mutexes`: boot `Schedule` with `ModManagerServiceProvider`, assert two `ProcessBackgroundJobsCommand` events exist (`mod-manager:process-jobs:fast` with `--lane=fast` and `mod-manager:process-jobs:heavy` with `--lane=heavy`), assert their `mutexName()` values are distinct, and assert `heavy`'s `expiresAt` (`30`) >= `fast`'s (`10`).
4. **`tests/Unit/Jobs/BulkUpdateInstalledProjectsTest.php`** (new) & **`tests/Unit/Services/InstalledProjectUpdateServiceTest.php`**:
   - `test_bulk_update_renews_heartbeat_during_foreground_pull_via_wings_progress_and_aborts_on_superseded_reservation`:
     - Verify `WingsRemoteFilesystem::withProgress()` passes Guzzle `'progress'` option on `pullForeground()` (inspect via `Http::fake()` or Guzzle request options) and invokes the progress callback.
     - Verify `BulkUpdateInstalledProjects::handle()` invokes `$heartbeat` before/during `updateAll()` and aborts the loop IMMEDIATELY (not counting remaining mods as `$failed++`, and NOT calling `complete()`) when `$heartbeat` returns `false` mid-update!

---

## 6. PHPStan & Formatting Checklist for Worker

- Run targeted PHPStan on all touched files:
  - `src/ModManagerPlugin.php`
  - `src/Services/InstalledProjectService.php`
  - `src/Services/InstalledOperationManager.php`
  - `src/Support/BackgroundJobRuntime.php`
  - `src/Support/DatabaseBackgroundJobQueue.php`
  - `src/Services/ResourcePackService.php`
  - `src/Filament/Server/Pages/ModManagerPage.php`
  - `src/Console/Commands/ProcessBackgroundJobsCommand.php`
  - `src/Providers/ModManagerServiceProvider.php`
  - `src/Jobs/BulkUpdateInstalledProjects.php`
  - `src/Services/InstalledProjectUpdateService.php`
  - `src/Services/InstalledArchiveTransaction.php`
  - `src/Support/WingsRemoteFilesystem.php`
- Key PHPStan pitfalls already avoided in this spec:
  - Narrow `$store instanceof LockProvider` before calling `->lock(...)` in `ResourcePackService` (avoid calling magic `->lock()` on `CacheRepository` interface).
  - Do NOT add redundant `is_object(Container::getInstance())` / `method_exists($app, 'bound')` checks in `DatabaseBackgroundJobQueue::reportException()` (PHPStan flags them always-true on `Container`).
  - Annotate `@param (Closure(): bool)|null $heartbeat`, `@param array<string, int|null> $fileSizesByFilename`, `@template T` on `withProgress()`/`withinLock()`.
  - Remove unused `use Illuminate\Support\Facades\Http;` in `ModManagerPage.php` so Pint passes cleanly.

---

# Part 2: Shared HTTP (`UpstreamHttp`), 5 Sources, `WingsRemoteFilesystem` & `SourceCache` (Features #9, #10, #11, #12-size)

# Handoff Report — `m2_iter1_explorer_http_wings_cache`

- **Milestone**: M2 — Iteration 1 (Features #9, #10, #11 + Source `'size'` portion of #12)
- **Base Commit Audited**: `875e30f413fad48f1fb5e69216651383869292b5` (Commit 1 / M1 on `fix/modpack-wizard-spigot`)
- **Reference Audited (Read-Only)**: `cosmii02/pelican-modpackmanager:src/Repositories/RetryingDaemonFileRepository.php` (MIT License)
- **Framework/Host Verified Directly**: Laravel 13 `PendingRequest`/`Pool`/`ResponseSequence`/`Sleep`/`Container\Util` + Pelican `DaemonFileRepository`/`DaemonRepository`/`AppServiceProvider`

---

## Executive Summary & Critical Discoveries (Read Before Implementing!)

1. **Backward Compatibility on `UpstreamHttp::json()` Signature**:
   - Existing `src/Support/UpstreamHttp.php:18` is `json(array $headers = [])`, called with an array 1st arg in `src/Services/ModManagerDiagnostics.php:57`, `src/Sources/ModrinthSource.php:1571`, `src/Sources/CurseForgeSource.php:2101`, and `tests/Unit/Support/UpstreamHttpTest.php:40`.
   - `PROJECT.md` Interface Contract #3 specifies `UpstreamHttp::json(float $timeout = 10.0, array $headers = [], ?float $connectTimeout = null, int $maxRetries = 2): PendingRequest`.
   - Accepting `float|array $timeout = 10.0` (treating an array first argument as `$headers` with default `10.0` timeout) satisfies **100% of Contract #3** (identical parameter names, order, defaults, and float calls) while preventing a `TypeError` in `ModManagerDiagnostics.php:57` if another parallel worker owns `ModManagerDiagnostics.php`. All 5 M2 sources and `UpstreamHttpTest` should still be migrated to the canonical Contract #3 call form.
2. **Laravel 13 `PendingRequest::retry(..., throw: false)` Mechanics (Verified in Framework Source)**:
   - In Laravel's `PendingRequest::send()`, `retry($times, $sleep, $when, throw: false)` takes **total attempts** (`$maxRetries + 1`, so `$maxRetries = 2` -> `3` attempts).
   - Passing `throw: false` is **mandatory**: callers without `->throw()` (`ModManagerDiagnostics`, `SpigotSource::request()`, `SpigotSource::probeDownloadArchive()`, direct `UpstreamHttp::json()->get(...)`) still receive the `Response` object to inspect `$response->status()` / `$response->failed()`, while callers with `->throw()` still retry retryable failures and throw `RequestException` when retries exhaust or on non-retryable 4xx/500.
   - `Http::pool()` (`PendingRequest::makePromise()` -> `handlePromiseResponse()`) natively supports `->retry(...)` asynchronously!
3. **3 Existing Unit Tests Will Fail Without Coordinated Test Updates**:
   - **PHP 8.4 LSP Fatal Error in 3 `WingsRemoteFilesystem` Test Subclasses**: Changing `putStream()` from `(..., StreamInterface $contents)` to `(..., StreamInterface|string $stream, ?int $contentLength = null)` will fatal-error PHP on:
     - `tests/Unit/Services/InstalledArchiveTransactionTest.php:519` (`RecordingWingsRemoteFilesystem::putStream`)
     - `tests/Unit/Services/ModpackDeploymentTest.php:454` (`DeploymentWings::putStream`)
     - `tests/Unit/Services/ModpackInstallerTest.php:650` (`MemoryModpackWings::putStream`)
     All 3 test doubles live in `tests/Unit/` (M2-owned) and **MUST** have their `putStream` override signature updated in lockstep.
   - **`tests/Unit/Support/SourceCacheTest.php:167` & `:348`** directly assert the two old `SourceCache` bugs (`$cache->shouldNotReceive('forget')` in `primeMany`, and `assertIsArray($cache->get(...':failure:v1'))` on lock timeout) and **MUST** be updated to assert the fixed behavior.
   - **`tests/Unit/Filament/ModpackCreationWizardTest.php:448`**: In `$scenario === 'http_error'` (FTB returns HTTP `503`), `FtbModpackSource` uses `UpstreamHttp::json()` which will now retry `503` twice (`3` total requests instead of `1`), breaking `Http::assertSentCount(1)` for `http_error`. Update line 448 to `$scenario === 'http_error' ? 3 : 1` (keeping `1` for all other non-interrupted failures).
4. **`ModrinthSource::requestProject()` Trimming Trap Avoided**:
   - Traced every consumer across `src/` and `tests/Unit/`: `src/Services/ModpackCatalog.php:466` reads `$project['id']` and `$project['server_side']`; `src/Sources/ModrinthSource.php:1065` & `src/Services/InstalledProjectService.php:743` read `$project['team']`; `tests/Unit/Services/InstalledMetadataPrimingAndRetryTest.php:403-410` asserts `project_id`, `id`, `date_modified`, `downloads`, `team`, `server_side`.
   - Trimming `requestProject()` to a 13-field allowlist keeps all consumed fields while stripping multi-KB `body`, `gallery`, `versions[]`, etc. (~90–98% payload reduction).
5. **Spigot `'size'` Trap Avoided**:
   - `InstalledArchiveTransaction.php:226` enforces **exact byte equality** (`$size !== $expectedSize`).
   - Spiget's `/resources/{id}` returns rounded human-readable estimates (`'size' => 37.7, 'sizeUnit' => 'KB'`, `'size' => 1.4, 'sizeUnit' => 'MB'`). Converting rounded `KB`/`MB` floats to bytes would **break ~99.9% of Spigot installs**!
   - Populate `'size'` in `SpigotSource` **ONLY** when exact bytes are known: (a) `file.sizeUnit` is `'B'`/`'byte'`/`'bytes'` with an exact positive integer on the current version, OR (b) exact byte total from `Content-Range: bytes 0-3/<total>` (HTTP 206) / `Content-Length` (HTTP 200) returned during `probeDownloadArchive()`.
6. **Filter-Option SWR Mock-Executor Trap Avoided**:
   - `tests/Unit/Sources/HangarSourceSearchCacheTest.php:68-80` instantiates `HangarSource` with a mocked `SourceFetchExecutorInterface` that has zero expectations on `fetch()`, then calls `$source->catalogVersionOptions('paper')`.
   - Adding `SourceCache::swrWithFetcher(SourceFetchSpec $spec, CacheProfile $profile, callable $fetcher, ?float $inlineBudget = null, mixed $fallback = []): mixed` gives all 7 filter-option endpoints unified `SourceCache` fresh/stale TTLs, failure-marker backoff, stale retention on outage, and async background revalidation (via `fetchSourceData()`), **without** breaking mock-executor unit tests!
7. **Laravel Container DI Fast-Path for `WingsRemoteFilesystem` Sleeper**:
   - Verified in `Illuminate\Container\Util::getParameterClassName()`: `?Closure` has `$type->isBuiltin() === false`, which makes `Container::make()` attempt `$this->make('Closure')` and catch `BindingResolutionException`.
   - Typing the constructor parameter as `?callable $sleeper = null` (builtin -> fast path!) and storing `private readonly ?Closure $sleeper;` via `Closure::fromCallable($sleeper)` is both DI-fast and PHPStan level-6 clean.

---

## Section 1: Feature #9 — Shared HTTP Client (`UpstreamHttp`) & 5 Owned Sources (Interface Contract #3)

### 1.1 `src/Support/UpstreamHttp.php`

**Current State** (`src/Support/UpstreamHttp.php:1-28`):
- Only sets `Http::asJson()->withHeaders(['Accept-Encoding' => 'gzip, deflate', ...$headers])->withOptions(['decode_content' => true])`.
- No `User-Agent`, no timeout/connectTimeout defaults, no retry/backoff, no `Retry-After` handling, no pool helper.

**Target Design**:
1. **Constants & Static State**:
   - `private const REPOSITORY_URL = 'https://github.com/kazaminosuke/pelican-mod-manager';`
   - `private const DEFAULT_TIMEOUT_SECONDS = 10.0;`
   - `private const DEFAULT_MAX_CONNECT_TIMEOUT_SECONDS = 3.0;`
   - `private const DEFAULT_MAX_RETRIES = 2;`
   - `private const RETRYABLE_STATUS_CODES = [408, 429, 502, 503, 504];`
   - `private const DEFAULT_BACKOFF_MS = [150, 400, 1000];` (bounded & fast even without `Sleep::fake()`, while still meaningful in production)
   - `private const MAX_RETRY_DELAY_MS = 5000;`
   - `private static ?string $cachedUserAgent = null;`
2. **`public static function userAgent(): string`**:
   - Reads `dirname(__DIR__, 2).'/plugin.json'` once and memoizes in `self::$cachedUserAgent`.
   - Decodes JSON; extracts `$decoded['version']` if `is_string(...)`; sanitizes via `preg_replace('/[^A-Za-z0-9._+\-]/', '', trim($rawVersion)) ?? ''`; falls back to `'0.0.0'` if unreadable/missing/empty.
   - Returns `"pelican-mod-manager/{$version} (+https://github.com/kazaminosuke/pelican-mod-manager)"`.
   - With current `plugin.json:5` (`"version": "0.2.0-pre.3"`): `pelican-mod-manager/0.2.0-pre.3 (+https://github.com/kazaminosuke/pelican-mod-manager)`.
   - Optional `public static function clearCachedUserAgent(): void` (or reset for unit tests if needed).
3. **`public static function json(float|array $timeout = self::DEFAULT_TIMEOUT_SECONDS, array $headers = [], ?float $connectTimeout = null, int $maxRetries = self::DEFAULT_MAX_RETRIES): PendingRequest`**:
   - Backward-compat guard:
     ```php
     if (is_array($timeout)) {
         $headers = $timeout;
         $timeout = self::DEFAULT_TIMEOUT_SECONDS;
     }
     ```
   - Calls `self::configure(Http::asJson(), $timeout, $headers, $connectTimeout, $maxRetries, acceptGzip: true)`.
4. **`public static function request(float $timeout = self::DEFAULT_TIMEOUT_SECONDS, array $headers = [], ?float $connectTimeout = null, int $maxRetries = self::DEFAULT_MAX_RETRIES, bool $acceptGzip = true): PendingRequest`**:
   - For non-JSON requests such as `SpigotSource::probeDownloadArchive()` (which sends `Accept: */*`, `Range: bytes=0-3`, `Accept-Encoding: identity`, and must NOT send `Content-Type: application/json`).
   - Calls `self::configure(Http::createPendingRequest(), $timeout, $headers, $connectTimeout, $maxRetries, acceptGzip: $acceptGzip)` (or `Http::withHeaders(...)` base).
5. **`public static function configurePoolRequest(PendingRequest $request, float $timeout = self::DEFAULT_TIMEOUT_SECONDS, array $headers = [], ?float $connectTimeout = null, int $maxRetries = self::DEFAULT_MAX_RETRIES, bool $asJson = true, bool $acceptGzip = true): PendingRequest`**:
   - Applies the exact same defaults (`asJson()` when `$asJson`, `User-Agent`, `Accept-Encoding`, `decode_content`, `timeout`, `connectTimeout`, `retry`) onto `$pool->as($alias)`.
6. **Core Configuration (`configure(...)`)**:
   - `$timeout = max(0.1, $timeout);`
   - `$resolvedConnectTimeout = $connectTimeout !== null ? max(0.1, min($connectTimeout, $timeout)) : min(self::DEFAULT_MAX_CONNECT_TIMEOUT_SECONDS, $timeout);`
   - Headers:
     ```php
     $defaultHeaders = ['User-Agent' => self::userAgent()];
     if ($acceptGzip) {
         $defaultHeaders['Accept-Encoding'] = 'gzip, deflate';
     }
     // Caller-supplied headers override defaults (e.g., Accept-Encoding: identity, Accept, Authorization)
     $mergedHeaders = [...$defaultHeaders, ...$headers];
     ```
   - Options: `['decode_content' => $acceptGzip && strtolower($mergedHeaders['Accept-Encoding'] ?? '') !== 'identity']` (note: ensures `UpstreamHttpTest:47` still sees `$decodeContent === true` for gzip JSON requests, while `identity` Range probes disable automatic decoding!).
   - Retry configuration (only when `$maxRetries > 0`):
     - Capture `$deadline = microtime(true) + $timeout;`
     - `->retry($maxRetries + 1, static fn (int $attempt, Throwable $exception): int => self::computeRetryDelayMs($attempt, $exception, $deadline), static fn (Throwable $exception): bool => self::shouldRetry($exception, $deadline), throw: false)`
7. **Exact Retry Decision & `Retry-After` Logic**:
   - **`public static function shouldRetry(Throwable $exception, ?float $deadline = null): bool`**:
     - Retryable exception check:
       - `$exception instanceof ConnectionException` -> retryable (`true`).
       - `$exception instanceof RequestException` -> `in_array($exception->response->status(), self::RETRYABLE_STATUS_CODES, true)` (`408, 429, 502, 503, 504`). Explicitly returns `false` for `400, 401, 403, 404, 410, 422, 500`, etc.!
       - All other `Throwable` -> `false`.
     - Deadline check (if `$deadline !== null`):
       - `$remainingMs = (int) floor(($deadline - microtime(true)) * 1000);`
       - If `$remainingMs <= 100`: return `false` (no time left to retry).
     - Return `true`.
   - **`public static function parseRetryAfterMs(Response $response, ?int $now = null): ?int`**:
     - Read `$raw = trim($response->header('Retry-After'));`
     - If `$raw === ''`: return `null`.
     - Delta-seconds: if `preg_match('/^\d+$/', $raw) === 1` -> return `(int) $raw * 1000`.
     - HTTP-date (RFC 7231 / IMF-fixdate): `$timestamp = strtotime($raw);` -> if `$timestamp !== false` -> return `max(0, ($timestamp - ($now ?? time())) * 1000)`.
     - Otherwise return `null` (ignore malformed header and fall back to default backoff).
   - **`public static function computeRetryDelayMs(int $attempt, Throwable $exception, ?float $deadline = null, ?int $now = null): int`**:
     - Base delay:
       - If `$exception instanceof RequestException`: `$retryAfterMs = self::parseRetryAfterMs($exception->response, $now);`
       - If `$retryAfterMs !== null`: `$delayMs = $retryAfterMs;`
       - Else: `$delayMs = self::DEFAULT_BACKOFF_MS[max(0, $attempt - 1)] ?? self::DEFAULT_BACKOFF_MS[array_key_last(self::DEFAULT_BACKOFF_MS)];`
     - Cap by `self::MAX_RETRY_DELAY_MS` AND remaining deadline:
       - `$capMs = self::MAX_RETRY_DELAY_MS;`
       - If `$deadline !== null`:
         `$remainingMs = max(0, (int) floor(($deadline - microtime(true)) * 1000) - 100);`
         `$capMs = min($capMs, $remainingMs);`
       - Return `max(0, min($delayMs, $capMs))`.
     - **Important**: When `Retry-After: 0` is returned, `$retryAfterMs === 0` -> returns `0` ms (immediate retry!). When `Retry-After: 120` is returned on a 5s deadline, capped cleanly to remaining deadline (never sleeps 120s!). When `Sleep::fake()` is active in tests, `Sleep::usleep()` records the exact delay without wall-clock waiting!

---

### 1.2 Caller Audit for All `UpstreamHttp` Usages

| File:Line | Current Call | M2 Action |
|---|---|---|
| `src/Sources/ModrinthSource.php:1571` | `UpstreamHttp::json($headers)->timeout(...)->connectTimeout(...)` | Update to `UpstreamHttp::json($timeoutSeconds, $headers)` |
| `src/Sources/CurseForgeSource.php:2101` | `UpstreamHttp::json(['x-api-key' => ...])->timeout(...)->connectTimeout(min(1.0, ...))` | Update to `UpstreamHttp::json(max(0.1, $timeoutSeconds), ['x-api-key' => $this->apiKey()])` |
| `src/Sources/CurseForgeSource.php:1098-1107` | Raw `$pool->as(...)->asJson()->withHeaders(...)->connectTimeout(min(1.0, ...))` | Update to `UpstreamHttp::configurePoolRequest($pool->as((string) $projectId), $requestTimeout, ['x-api-key' => $this->apiKey()])` |
| `src/Sources/HangarSource.php:728,804,998,1440,1553,1581` | Raw `Http::asJson()` / `$pool->as(...)` | Route ALL 6 through `UpstreamHttp::json()` / `UpstreamHttp::configurePoolRequest()` + add 401 refresh/fallback |
| `src/Sources/GitHubReleasesSource.php:527,777,886,1173` | Raw `Http::asJson()` / `$pool->as(...)` | Route ALL 4 through `UpstreamHttp::json()` / `UpstreamHttp::configurePoolRequest()` preserving GitHub headers |
| `src/Sources/SpigotSource.php:1607,1650,2146,2193` | Raw `Http::withHeaders(...)` / `$pool->as(...)` | Route through `UpstreamHttp` (`request()` for probe with `identity`, `json(..., maxRetries: 0)` for `requestSpigetUpdate`, `json()` for `request()`, `configurePoolRequest()` for `pool()`) |
| `src/Services/ModManagerDiagnostics.php:57,74,83,102` | `UpstreamHttp::json(['x-api-key' => ...])` & `UpstreamHttp::json()` | Safe via `float\|array` compat (and updated by Diagnostics task to canonical signature) |
| `src/Sources/AtlauncherModpackSource.php:32,134` | `UpstreamHttp::json()` (0-arg) | **DO NOT TOUCH (M3 owned)** — automatically gets UA, gzip, retries! |
| `src/Sources/FtbModpackSource.php:41,180,233` | `UpstreamHttp::json()` (0-arg) | **DO NOT TOUCH (M3 owned)** — automatically gets UA, gzip, retries! |
| `src/Sources/TechnicModpackSource.php:34,79,168` | `UpstreamHttp::json()` (0-arg) | **DO NOT TOUCH (M3 owned)** — automatically gets UA, gzip, retries! |
| `tests/Unit/Support/UpstreamHttpTest.php:40` | `UpstreamHttp::json(['x-api-key' => 'test-key'])` | Update & expand thoroughly |

---

### 1.3 Source-by-Source Exact Implementation Specification (5 Owned Sources)

#### A. `src/Sources/ModrinthSource.php`
1. **Shared HTTP (`:1566-1574`)**:
   - Simplify `http(float $timeoutSeconds)` to `UpstreamHttp::json($timeoutSeconds, $headers)`.
   - All 13 upstream requests in `ModrinthSource` already call `$this->http(...)`.
2. **Filter-Option SWR (`:289-423`, `:631-636`, `:104-126`)**:
   - Add `private const OPERATION_TAG = 'tag';` and `private const FILTER_INLINE_BUDGET_SECONDS = 3.0;`.
   - Remove `use Illuminate\Support\Facades\Cache;` (`:8`) — unused after replacing the 4 `Cache::remember()` calls.
   - Replace the 4 `Cache::remember()` blocks in `catalogVersionOptions()` (`:291-299`), `catalogLoaderOptions()` (`:323-331`), `catalogCategoryGroups()` (`:368-376`), and `catalogLicenseOptions()` (`:397-405`) with a private helper:
     ```php
     /** @return array<int, mixed> */
     private function cachedTagMetadata(string $tag): array
     {
         $result = $this->sourceCache->swrWithFetcher(
             $this->spec(self::OPERATION_TAG, ['tag' => $tag]),
             CacheProfile::ProjectMetadata,
             fn (float $timeoutSeconds): array => $this->metadata('/tag/'.$tag, $timeoutSeconds),
             self::FILTER_INLINE_BUDGET_SECONDS,
             [],
         );

         return is_array($result) ? $result : [];
     }
     ```
   - Update `metadata(string $path, float $timeoutSeconds = 10.0): array` (`:631-636`) to pass `$timeoutSeconds` to `$this->http($timeoutSeconds)`.
   - Register `self::OPERATION_TAG` in `fetchSourceData()` (`:104-114`, validating `$tag` against allowlist `['game_version', 'loader', 'category', 'license']`!) and `emptySourceData()` (`:117-126` -> `[]`).
   - Result: 24h fresh, 7d stale retention, 3.0s cold inline budget (instead of 10s), 30s failure backoff, async background revalidation when stale!
3. **`requestProject()` Payload Trimming (`:1316-1335`)**:
   - Replace raw `$project` return with exact consumed-fields allowlist:
     ```php
     $resolvedId = (string) ($project['project_id'] ?? $project['id'] ?? $projectId);

     return [
         'id' => (string) ($project['id'] ?? $resolvedId),
         'project_id' => $resolvedId,
         'slug' => (string) ($project['slug'] ?? ''),
         'title' => (string) ($project['title'] ?? ''),
         'description' => CatalogFields::description($project['description'] ?? ''),
         'icon_url' => is_string($project['icon_url'] ?? null) ? $project['icon_url'] : null,
         'author' => is_string($project['author'] ?? null) && $project['author'] !== '' ? $project['author'] : null,
         'team' => is_string($project['team'] ?? null) && $project['team'] !== '' ? $project['team'] : null,
         'downloads' => (int) ($project['downloads'] ?? 0),
         'date_modified' => $project['date_modified'] ?? $project['updated'] ?? null,
         'project_type' => (string) ($project['project_type'] ?? ''),
         'server_side' => is_string($project['server_side'] ?? null) ? $project['server_side'] : null,
         'client_side' => is_string($project['client_side'] ?? null) ? $project['client_side'] : null,
     ];
     ```
   - **Verified Consumers**:
     - `ModpackCatalog.php:466` (`id`, `server_side`), `:474` (`title`)
     - `ModrinthSource.php:1061,1065` (`author`, `team`)
     - `InstalledProjectService.php:740,743,767,768` (`author`, `team`, `slug`, `title`)
     - `ProjectSourceRegistry.php:118-125,238-247` (`project_id`, `slug`, `title`, `description`, `icon_url`, `author`, `downloads`, `date_modified`, `project_type`)
     - `tests/Unit/Services/InstalledMetadataPrimingAndRetryTest.php:405-410` (`project_id`, `id`, `date_modified`, `downloads`, `team`, `server_side`)
   - **Dropped Bloat**: `body` (multi-KB Markdown), `gallery`, `versions` (hundreds of IDs), `game_versions`, `loaders`, `categories`, `additional_categories`, `license`, `donation_urls`, `moderator_message`, `issues_url`, `source_url`, `wiki_url`, `discord_url`.

#### B. `src/Sources/CurseForgeSource.php` (**STRICT M2 vs M3 BOUNDARY**)
- **STRICTLY DO NOT MODIFY (M3-Owned Modpack Methods)**:
  - Lines `194–579`: `latestModpackDownload`, `listModpackVersions`, `modpackDownloadById`, `resolveModpackRelease`, `modpackReleaseDownload`, `findModpackFile`, `curseForgeFileTargets`, `dependencyFiles`, `dependencyFailureReason`, `dependencyFile`
  - Lines `681–776`: `buildModpackSearchSpec`, `modpackFile`, `modpackZipDownload`
- **M2-Owned Changes ONLY**:
  1. **Shared HTTP (`:2097-2104` & `:1094-1111`)**:
     - `http(float $timeoutSeconds)` (`:2097-2104`): change to `UpstreamHttp::json(max(0.1, $timeoutSeconds), ['x-api-key' => $this->apiKey()])` (raises background `connectTimeout` from `1.0` to `min(3.0, $timeout)`, adds `User-Agent` and bounded retries).
     - `fetchWarmVersions()` `Http::pool()` (`:1094-1111`): replace inline `$pool->as(...)->asJson()->withHeaders(...)->connectTimeout(min(1.0, ...))` with `UpstreamHttp::configurePoolRequest($pool->as((string) $projectId), $requestTimeout, ['x-api-key' => $this->apiKey()])->get(...)`.
  2. **Filter-Option SWR (`:1638-1709`, `:1757-1804`)**:
     - Remove unused `use Illuminate\Support\Facades\Cache;` (`:11`).
     - In `catalogCategoryOptions()` (`:1649-1657`): use `$this->cache()->swrWithFetcher(new SourceFetchSpec($this->getKey()->value, 'filter_categories', ['class_id' => $classId]), CacheProfile::ProjectMetadata, fn (float $t): array => $this->fetchFilterCategories($classId, $t), 3.0, [])`.
     - In `catalogVersionOptions()` (`:1686-1694`): use `$this->cache()->swrWithFetcher(new SourceFetchSpec($this->getKey()->value, 'filter_game_versions', []), CacheProfile::ProjectMetadata, fn (float $t): array => $this->fetchFilterGameVersions($t), 3.0, [])`.
     - Register `'filter_categories'` and `'filter_game_versions'` in `fetchSourceData()` (`:1757-1794`) and `emptySourceData()` (`:1796-1804` -> `[]` via default arm).
  3. **Populate `'size'` in `normalizeVersion()` (`:2081-2088`)**:
     - Extract `$fileSize = is_numeric($file['fileLength'] ?? null) && (int) $file['fileLength'] > 0 ? (int) $file['fileLength'] : null;`
     - Include `...($fileSize !== null ? ['size' => $fileSize] : [])` in `files[0]`.

#### C. `src/Sources/HangarSource.php`
1. **Shared HTTP + 401 JWT Refresh + Graceful Public Fallback (`:1550-1604` + 4 pool sites `:720`, `:798`, `:992`, `:1434`)**:
   - Add `use Illuminate\Contracts\Debug\ExceptionHandler;` and `use Kazaminosuke\ModManager\Support\UpstreamHttp;`.
   - `jwtCacheKey(string $apiKey): string` -> `'pelican-mod-manager:hangar-jwt:'.hash('sha256', $apiKey)`.
   - `jwtFailureCacheKey(string $apiKey): string` -> `'pelican-mod-manager:hangar-jwt-failed:'.hash('sha256', $apiKey)`.
   - `authHeaders(bool $forceRefresh = false): array`:
     - Keep `$forceRefresh = false` optional so existing `OptionalSourceCredentialTest.php:73-74` (`$method->invoke($source)`) stays 100% compatible!
     - If `$apiKey === ''` -> return `[]`.
     - If `$forceRefresh`: `Cache::forget($jwtKey); Cache::forget($failedKey);`.
     - Else: check `Cache::get($jwtKey)` (return `['Authorization' => 'HangarAuth '.$token]`); check `Cache::get($failedKey)` (if truthy, return `[]` immediately — public fallback without hammering `/authenticate`!).
     - Call `POST /authenticate` via `UpstreamHttp::json(5.0, connectTimeout: 3.0, maxRetries: 1)->withOptions(['query' => ['apiKey' => $apiKey]])->post(self::BASE_URL.'/authenticate')`.
     - **SECURITY REQUIREMENT (Preserve `:1589-1590`)**: NEVER retain/log/report the underlying `RequestException` whose URL contains `?apiKey=...`! On failure (exception, non-2xx, or missing string `token`):
       - `Cache::put($failedKey, true, 300);`
       - Guarded report: `if (function_exists('app') && app()->bound(ExceptionHandler::class)) { report(new Exception('Hangar API authentication failed.')); }` (prevents `BindingResolutionException` in standalone container unit tests!).
       - Return `[]` (graceful public fallback so Hangar browsing/scanning keeps working even if an admin pasted a bad/revoked API key!).
     - On success: cache JWT (`max(60, intdiv($expiresInMs, 1000) - 60)`), `Cache::forget($failedKey)`, return `['Authorization' => 'HangarAuth '.$token]`.
   - `getJson(string $path, array $query, float $timeoutSeconds): array` (`:1550-1566`):
     - `$headers = $this->authHeaders();`
     - `$response = UpstreamHttp::json($timeoutSeconds, $headers)->get(self::BASE_URL.$path, $query);`
     - If `$response->status() === 401 && $headers !== []`:
       - `$refreshed = $this->authHeaders(forceRefresh: true);`
       - `$response = UpstreamHttp::json($timeoutSeconds, $refreshed)->get(self::BASE_URL.$path, $query);`
       - If `$response->status() === 401 && $refreshed !== []`:
         - Invalidate cached JWT + mark 300s auth failure backoff (`Cache::forget(...)`, `Cache::put($failedKey, true, 300)`).
         - Final public fallback: `$response = UpstreamHttp::json($timeoutSeconds)->get(self::BASE_URL.$path, $query);`
     - `$response->throw();`
     - Decode JSON and verify `is_array($payload)`.
   - **Shared Pool Helper** (`sendGetPool(array $requestsByAlias, float $timeoutSeconds): array`) used by all 4 `Http::pool()` sites (`:720`, `:798`, `:992`, `:1434`):
     - Each entry is `['url' => string, 'query' => array<string, mixed>]`.
     - Uses `UpstreamHttp::configurePoolRequest($pool->as($alias), $timeoutSeconds, $headers)->get($req['url'], $req['query'])`.
     - If `$headers !== []` and any alias returned a `Response` with `$response->status() === 401`:
       - Refreshes via `$refreshed = $this->authHeaders(forceRefresh: true)` and retries ONLY the 401 aliases once (with final public `[]` fallback if still 401 and `$refreshed !== []`).
     - Eliminates 4 duplicated pool blocks, fixes missing `User-Agent`, missing `Accept-Encoding: gzip, deflate`, missing retries, and hardcoded `connectTimeout(min(1.0, ...))` on lines `:1002` and `:1444`!
2. **Filter-Option SWR (`catalogVersionOptions()`, `:270-306`, `:119-138`)**:
   - Add `private const OPERATION_PLATFORM_VERSIONS = 'platform_versions';`.
   - Replace `Cache::remember(...)` (`:278-286`) with `$this->sourceCache->swrWithFetcher($this->spec(self::OPERATION_PLATFORM_VERSIONS, ['platform' => $platform]), CacheProfile::ProjectMetadata, fn (float $t): array => $this->getJson('/platforms/'.$platform.'/versions', [], $t), 3.0, [])`.
   - Register `self::OPERATION_PLATFORM_VERSIONS` in `fetchSourceData()` (validating `$platform` against `catalogPlatformOptions()`) and `emptySourceData()` (`[]`).
   - Preserves `HangarSourceSearchCacheTest::test_catalog_version_options_use_the_official_platform_metadata_endpoint()` 100%!
3. **Populate `'size'` in `normalizeVersion()` (`:1363-1380`)**:
   - Extract `$sizeBytes = is_numeric($fileInfo['sizeBytes'] ?? null) && (int) $fileInfo['sizeBytes'] > 0 ? (int) $fileInfo['sizeBytes'] : null;`
   - Include `...($sizeBytes !== null ? ['size' => $sizeBytes] : [])` in `files[0]`.

#### D. `src/Sources/GitHubReleasesSource.php`
1. **Shared HTTP across ALL 4 sites (`:521`, `:777`, `:880`, `:1173`)**:
   - Add `use Kazaminosuke\ModManager\Support\UpstreamHttp;`.
   - Centralize GitHub headers helper:
     ```php
     /** @return array<string, string> */
     private function requestHeaders(): array
     {
         $headers = [
             'Accept' => 'application/vnd.github+json',
             'X-GitHub-Api-Version' => '2022-11-28',
         ];

         $token = $this->token();
         if ($token !== '') {
             $headers['Authorization'] = 'Bearer '.$token;
         }

         return $headers;
     }
     ```
   - `getJson()` (`:1171-1197`): `UpstreamHttp::json(max(0.1, $timeoutSeconds), $this->requestHeaders())->throw()->get(self::BASE_URL.$path, $query)->json()` (fixes missing `User-Agent`, missing gzip, missing retries, and hardcoded 1s `connectTimeout`!).
   - `lookupLatestVersionsWithGraphQl()` (`:777-790`): `UpstreamHttp::json($remaining, $this->requestHeaders())->throw()->post(self::BASE_URL.'/graphql', [...])->json()`.
   - `fetchProjectsByIdsWithPool()` (`:521-543`): `UpstreamHttp::configurePoolRequest($pool->as($alias), $remaining, $headers)->get(...)`.
   - `lookupLatestVersionsWithRestPool()` (`:880-900`): `UpstreamHttp::configurePoolRequest($pool->as($alias), $remaining, $headers)->get(...)` (note: line 886 didn't pass token because REST pool is only entered when `$this->token() === ''`, so `$this->requestHeaders()` works identically!).
2. **Populate `'size'` in BOTH REST and GraphQL (`:721-735`, `:997-1004`, `:1056-1062`)**:
   - In `normalizeVersion()` (`:721-735`):
     ```php
     $size = is_numeric($asset['size'] ?? null) && (int) $asset['size'] > 0
         ? (int) $asset['size']
         : null;

     return [
         'primary' => $index === 0,
         'filename' => $asset['name'] ?? '',
         'url' => $asset['browser_download_url'] ?? null,
         'hashes' => $hashes,
         ...($size !== null ? ['size' => $size] : []),
     ];
     ```
   - In `buildGraphQlReleaseQuery()` (`:997-1004`): add `size` to `releaseAssets(first: 100) { nodes { name downloadUrl downloadCount digest size } }`.
   - In `latestNormalizedGraphQlVersion()` (`:1056-1062`): pass `'size' => $asset['size'] ?? null` into `$assets[]`.

#### E. `src/Sources/SpigotSource.php`
1. **Shared HTTP across ALL 4 sites (`:55`, `:1603-1678`, `:2119-2206`)**:
   - Add `use Kazaminosuke\ModManager\Support\UpstreamHttp;`.
   - Remove hardcoded `protected const USER_AGENT = ...;` (`:55`) — verified 0 references to `SpigotSource::USER_AGENT` outside `SpigotSource.php`!
   - `headers()` (`:2119-2125`): `['Accept' => 'application/json']` (since `UpstreamHttp` supplies `User-Agent` and `Accept-Encoding: gzip, deflate`).
   - `request()` (`:2190-2206`):
     - Use `UpstreamHttp::json(max(0.1, $timeoutSeconds), $this->headers())`.
     - **Preserve** `$response = $query === [] ? $pending->get($url) : $pending->get($url, $query);` (so embedded `?_=<ts>` query strings in `spigetResourceUrl()` are not wiped!).
     - Works cleanly with `if ($response->status() === 404) { throw new SourceFetchNotFoundException(...); } $response->throw();` because `UpstreamHttp::json()` sets `throw: false` and never retries 404!
   - `pool()` (`:2134-2160`):
     - Use `UpstreamHttp::configurePoolRequest($pool->as((string) $alias), max(0.1, $timeoutSeconds), $this->headers())`.
     - Preserve `if (!$followRedirects) { $request = $request->withOptions(['allow_redirects' => false]); }`.
   - `probeDownloadArchive()` (`:1603-1639`):
     - Binary Range probe — use `UpstreamHttp::request($timeoutSeconds, ['Accept' => '*/*', 'Range' => 'bytes=0-3', 'Accept-Encoding' => 'identity'], maxRetries: 1, acceptGzip: false)`!
     - Preserve `allow_redirects => false` when `!$allowRedirects`.
   - `requestSpigetUpdate()` (`:1646-1678`):
     - **Non-idempotent mutating POST** — use `UpstreamHttp::json($timeoutSeconds, $this->headers(), maxRetries: 0)`!
2. **Exact-Only `'size'` Population (`:1885-1916`, `:1500-1639`)**:
   - **NEVER convert rounded `KB`/`MB`/`GB` floats from Spiget `file.size` into exact bytes**, because `InstalledArchiveTransaction.php:226` checks strict `$size !== $expectedSize`!
   - Add helper `exactSpigetFileSize(?array $resource, string $versionId): ?int`:
     - Only returns an `int` when:
       1. `$resource['file']` is an array;
       2. `$resource['version']['id']` (if present) matches `$versionId` (because `$resource['file']` describes only the latest version, not historical versions!);
       3. `strtolower(trim((string) ($file['sizeUnit'] ?? '')))` is in `['b', 'byte', 'bytes']`;
       4. `is_numeric($file['size'] ?? null)` and `(float) $file['size'] > 0` and `(float) (int) $file['size'] === (float) $file['size']`.
     - Include `...($size !== null ? ['size' => $size] : [])` in `normalizeSpigetVersion()` (`:1907-1914`).
   - Enhance `probeDownloadArchive()` (`:1603-1639`) to return `array{status: 'ok'|'not_found'|'rate_limited'|'failed', size: ?int}` (or extract exact size helper `exactResponseSize(Response $response): ?int`):
     - On HTTP `206`: parse `Content-Range: bytes \d+-\d+/(\d+)` -> positive int total size!
     - On HTTP `200`: parse `Content-Length: (\d+)` -> positive int total size!
     - In `verifyAndAttachSpigetCdnDownload()` (`:1508-1525`) and `verifyAndAttachHistoricalVersionDownload()` (`:1567-1589`): when `$probe['status'] === 'ok'` and `$probe['size'] !== null`, set `$version['files'][0]['size'] = $probe['size']`!

---

## Section 2: Feature #10 — Wings Transport Resilience & Batch Primitives (`WingsRemoteFilesystem` — Interface Contract #2)

### 2.1 Reference Pattern Audited
`cosmii02/pelican-modpackmanager:src/Repositories/RetryingDaemonFileRepository.php:15-32` (MIT License, `Copyright (c) 2026 cosmii02`):
- `RETRY_DELAYS_MS = [500, 2000, 5000]`.
- `failedBeforeSending(Throwable $e): bool`:
  `$e instanceof ConnectionException && preg_match('/cURL error (6|7|35):|cURL error 28: (Resolving|Connection) timed out/i', $e->getMessage()) === 1`.
- Because Pelican injects `DaemonFileRepository` into our services rather than our subclass, centralizing retry in `WingsRemoteFilesystem::withRetry()` covers **both** `DaemonFileRepository` helper methods (`getDirectory`, `renameFiles`, `deleteFiles`, `decompressFile`, `putContent`) **and** direct `getHttpClient()` calls (`pullForeground`, `readStream`, `readPrefix`, `putStream`).

### 2.2 Exact Public API & Behavior (`src/Support/WingsRemoteFilesystem.php`)

1. **Constants & Constructor**:
   ```php
   public const FOREGROUND_PULL_TIMEOUT_SECONDS = 900;
   public const DECOMPRESS_TIMEOUT_SECONDS = 900;
   public const DEFAULT_BATCH_CHUNK_SIZE = 200;
   public const PRE_SEND_RETRY_DELAYS_MS = [500, 2000, 5000];
   private const TRANSIENT_READ_STATUS_CODES = [502, 503, 504];

   private readonly ?Closure $sleeper;

   /**
    * @param  list<int>  $retryDelaysMs
    * @param  (callable(int): void)|null  $sleeper
    */
   public function __construct(
       private readonly array $retryDelaysMs = self::PRE_SEND_RETRY_DELAYS_MS,
       ?callable $sleeper = null,
   ) {
       $this->sleeper = $sleeper !== null ? Closure::fromCallable($sleeper) : null;
   }
   ```
   - Zero-arg `new WingsRemoteFilesystem()` / Laravel singleton binding (`ModManagerServiceProvider.php:98`) continues to work cleanly with zero exceptions in `Container::resolveDependencies()`.
   - When `$this->sleeper === null`, calls `Sleep::usleep($delayMs * 1000)` — automatically respects `Sleep::fake()` in integration/E2E tests too!

2. **Retry Classification Helpers**:
   - `public static function failedBeforeSending(Throwable $exception): bool`:
     Matches `ConnectionException` with `/cURL error (6|7|35):|cURL error 28: (Resolving|Connection) timed out/i`. Never matches `cURL error 28: Operation timed out`!
   - `public static function isRetryableReadFailure(Throwable $exception): bool`:
     Returns `true` for ANY `ConnectionException` OR `RequestException` with status in `[502, 503, 504]`. Returns `false` for `400, 401, 403, 404, 416, 500`, etc.
   - `private function withRetry(callable $operation, bool $idempotentRead = false): mixed`:
     Loops up to `count($this->retryDelaysMs)` retries (3 retries = 4 total attempts with default `[500, 2000, 5000]`), sleeping `$this->retryDelaysMs[$attempt]` ms before each retry.

3. **Method-by-Method Retry & Behavior Matrix**:

| Method | Signature | Retry Mode | Key Implementation Details |
|---|---|---|---|
| `pullForeground` | Existing (`:32-38`) | Pre-send only | Wrap POST `/api/servers/{uuid}/files/pull` (`timeout(900)`) in `withRetry(..., idempotentRead: false)`. Preserve 202 check & `$response->throw()`. |
| `move` | Existing (`:61-67`) | Pre-send only | Delegate to `$this->renameBatch($fileRepository, $server, $directory, [['from' => $from, 'to' => $to]])`. |
| `renameBatch` *(NEW)* | `renameBatch(DaemonFileRepository $fileRepository, Server $server, string $root, array $files, int $chunkSize = 200): void` | Pre-send only | Validate `$chunkSize >= 1` (`InvalidArgumentException`). Skip if `$files === []`. Normalize `$root === '' ? '/' : $root`. Chunk `array_values($files)` by `$chunkSize` and call `$fileRepository->setServer($server)->renameFiles($normalizedRoot, $chunk)->throw()` inside `withRetry(..., false)`. |
| `delete` | Existing (`:76-81`) | Pre-send only | Delegate to `$this->deleteBatch($fileRepository, $server, $directory, [$filename])`. |
| `deleteBatch` *(NEW)* | `deleteBatch(DaemonFileRepository $fileRepository, Server $server, string $root, array $files, int $chunkSize = 200): void` | Pre-send only | Validate `$chunkSize >= 1` (`InvalidArgumentException`). Filter non-empty strings; skip if empty. Normalize `$root === '' ? '/' : $root`. Chunk by `$chunkSize` and call `$fileRepository->setServer($server)->deleteFiles($normalizedRoot, $chunk)->throw()` inside `withRetry(..., false)`. |
| `deleteQuietly` | Existing (`:85-96`) | Pre-send only (via `delete`) | Keep `try/catch (Exception) { report($e); }`. |
| `deleteManyQuietly` | Existing (`:99-108`) | Pre-send only (via `deleteBatch`) | Chunk by `self::DEFAULT_BATCH_CHUNK_SIZE` (200), wrapping each chunk in `try/catch (Exception) { report($e); }`. |
| `listDirectory` | Existing (`:118-122`) | **Idempotent read** | Wrap `$fileRepository->setServer($server)->getDirectory($normalizedRoot)` in `withRetry(..., idempotentRead: true)`. Preserve 404 non-root `"The requested directory was not found on the server."` -> `[]` (NOT retried because 404 is non-retryable). |
| `findListedFile` | Existing (`:143-148`) | Inherits `listDirectory` | Unchanged. |
| `readStream` | Existing (`:187`) | **Idempotent read** | Wrap GET `/api/servers/{uuid}/files/contents` (`['stream' => true]`) + `$response->throw()` in `withRetry(..., idempotentRead: true)`. |
| `readPrefix` | Existing (`:168-174`) | **Idempotent read** | Send `Range: bytes=0-{$endByte}` (`bytes=0-3` for default `$bytes = 4`), `Accept-Encoding: identity`, options `['stream' => true, 'decode_content' => false]`. Handle HTTP `416` (empty file) -> `''`. Call `$response->throw()`. Handle **both `206` and `200`** by reading loop `while (strlen($prefix) < $length && !$body->eof())` and closing `$body` in `finally` (never buffers full 200 body!). Return `substr($prefix, 0, $length)`. |
| `put` | Existing (`:199-204`) | Pre-send only | Wrap `putContent(ltrim($path, '/'), $contents)` in `withRetry(..., idempotentRead: false)`. Preserve `$response->failed()` check (and note `DaemonFileRepository::putContent()` already throws `FileExistsException` on 400 and `RequestException` on other errors). |
| `putStream` *(UPDATED)* | `putStream(DaemonFileRepository $fileRepository, Server $server, string $path, StreamInterface\|string $stream, ?int $contentLength = null): void` | Pre-send only | Guarantee explicit `Content-Length` header (`>= 0`, NEVER `-1`/chunked!): <br>1. If `$contentLength !== null && $contentLength < 0`: throw `InvalidArgumentException`. <br>2. If `is_string($stream)`: `$length = $contentLength ?? strlen($stream)`. <br>3. Else (`StreamInterface`): if `$contentLength !== null`, use it; else if `$stream->getSize() !== null`, compute remaining bytes (`$initialPos = $stream->isSeekable() ? $stream->tell() : null`, `$length = $initialPos !== null ? max(0, $stream->getSize() - $initialPos) : $stream->getSize()`); else (**non-seekable / `getSize() === null`**) spool `$stream` in 64 KiB chunks into `fopen('php://temp/maxmemory:2097152', 'r+b')`, measure `ftell()`, `rewind()`, wrap in `GuzzleHttp\Psr7\Stream`, close in `finally`! <br>4. Before any pre-send retry (`$attempt > 0`), seek seekable stream back to `$initialPos`. <br>5. Send `->timeout(self::FOREGROUND_PULL_TIMEOUT_SECONDS)->withHeaders(['Content-Length' => (string) $length])->withQueryParameters(['file' => $path])->withBody($uploadBody, 'application/octet-stream')->post("/api/servers/{$server->uuid}/files/write")->throw()`. |
| `decompressFile` *(NEW)* | `decompressFile(DaemonFileRepository $fileRepository, Server $server, string $root, string $file): void` | Pre-send only | Verified against Pelican's `DaemonFileRepository.php:178-190`: `decompressFile(?string $root, string $file): Response` already sets `->timeout(60 * 15)` (900s) and POSTs `['root' => ..., 'file' => $file]` to `/api/servers/{uuid}/files/decompress`. Wrap `$fileRepository->setServer($server)->decompressFile($root === '' ? '/' : $root, $file)->throw()` in `withRetry(..., idempotentRead: false)`. |

### 2.3 Backward Compatibility & Required Test-Double Updates (PHP 8.4 LSP!)
Verified ALL subclasses of `WingsRemoteFilesystem` in `src/` and `tests/Unit/`:
1. `tests/Unit/Services/InstalledArchiveTransactionTest.php:519` (`RecordingWingsRemoteFilesystem::putStream`) -> update signature to `(DaemonFileRepository $fileRepository, Server $server, string $path, StreamInterface|string $contents, ?int $contentLength = null): void`, handling `is_string($contents) ? $contents : $contents->getContents()`.
2. `tests/Unit/Services/ModpackDeploymentTest.php:454` (`DeploymentWings::putStream`) -> same signature update.
3. `tests/Unit/Services/ModpackInstallerTest.php:650` (`MemoryModpackWings::putStream`) -> same signature update.

All existing callers in `src/` (`InstalledArchiveTransaction`, `ModpackDeployment`, `ModpackInstaller`, `ModpackRuntime`, `InstallModpack`) pass positional arguments and remain 100% compatible without changes.

---

## Section 3: Feature #11 — `SourceCache` Fixes & Filter-Option SWR

### 3.1 New Exception: `src/Exceptions/SingleFlightTimeoutException.php`
Match `src/Exceptions/SourceFetchNotFoundException.php`:
```php
<?php

namespace Kazaminosuke\ModManager\Exceptions;

use RuntimeException;

/**
 * Signals that a caller timed out waiting for another request's single-flight
 * fetch lock on a cold cache key.
 *
 * The lock holder is still actively fetching from upstream, so this is NOT an
 * upstream outage: SourceCache must return a fallback without recording a
 * failure marker or dispatching a duplicate RevalidateSourceCache job.
 */
final class SingleFlightTimeoutException extends RuntimeException {}
```

### 3.2 `src/Support/SourceCache.php` Exact Changes
1. **Cold Lock Timeout (`fetchAndStore()`, `:431-442`)**:
   - Change `catch (LockTimeoutException)` (`:431`) to `catch (LockTimeoutException $exception)` and throw `new SingleFlightTimeoutException("Source [{$spec->sourceKey}] operation [{$spec->operation}] is already being fetched.", previous: $exception)` (`:439`).
2. **Handle `SingleFlightTimeoutException` in ALL 5 Callers of `fetchAndStore()`**:
   - **`swr()` (`:63-92`)**: add `catch (SingleFlightTimeoutException)` BEFORE `catch (Throwable)` -> set `$status = $entry !== null ? 'STALE' : 'MISS';` and return `$entry !== null ? $entry['data'] : $this->emptyResult($spec);` — **NO `markFailure()`, NO `dispatchRevalidation()`**!
   - **`swrRequired()` (`:119-131`)**: add `catch (SingleFlightTimeoutException $exception)` BEFORE `catch (Throwable)` -> `throw $exception;` (NO `markFailure()`, NO `dispatchRevalidation()`).
   - **`swrForAction()` (`:159-171`)**: add `catch (SingleFlightTimeoutException $exception)` BEFORE `catch (Throwable)` -> if `$entry !== null`, return `$entry['data']`; else `throw $exception;` (NO `markFailure()`).
   - **`swrRequiredFresh()` (`:195-210`)**: add `catch (SingleFlightTimeoutException $exception)` BEFORE `catch (Throwable)` -> `throw $exception;` (NO `markFailure()`, NO `dispatchRevalidation()`).
   - **`revalidate()` (`:217-232`)**: add `catch (SingleFlightTimeoutException)` BEFORE `catch (Throwable)` -> `return false;` (NO `markFailure()`).
3. **Unconditional Cache Failure-Marker Clearing (`forgetFailureMarker()`, `:751-768`)**:
   - Currently checks `if ($known !== null && $known !== false && ...)` — which is a complete no-op when CLI background jobs (`RevalidateSourceCache`, `WarmProjectMetadata`) run in a fresh process with empty `$memos['failures']`!
   - Change to unconditionally delete in cache AND update process/request memo:
     ```php
     /**
      * Always remove any persisted failure marker when fresh data is written.
      * Background CLI jobs (RevalidateSourceCache, WarmProjectMetadata) run
      * in a separate process whose request memos never observed the earlier
      * failure marker, so skipping unobserved deletes left stale cooldowns
      * active in cache after recovery.
      */
     private function forgetFailureMarker(string $failureKey): void
     {
         $this->cache->forget($failureKey);

         $memos = $this->memos();
         $failures = $memos['failures'];
         $failures[$failureKey] = false;
         $memos['failures'] = $failures;
     }
     ```
   - Both `storeEntry()` (`:568`) and `primeMany()` (`:514`) already call `forgetFailureMarker(...)`, so BOTH single-key refreshes (`revalidate()`) and batched priming (`primeMany()`) now unconditionally clear cross-request failure markers!
4. **Filter-Option SWR Helper (`swrWithFetcher(...)`)**:
   - Add `public function swrWithFetcher(SourceFetchSpec $spec, CacheProfile $profile, callable $fetcher, ?float $inlineBudget = null, mixed $fallback = []): mixed` (detailed in §1.3A above):
     - Fresh hit -> return `$entry['data']`.
     - Active failure marker -> return `$entry !== null ? $entry['data'] : $fallback` (0 upstream calls during 30s backoff!).
     - Stale hit + async dispatch -> dispatch `RevalidateSourceCache`, return `$entry['data']` immediately.
     - Cold miss (or sync stale) -> call `$fetcher($inlineBudget ?? $profile->inlineBudgetSeconds())`, `storeEntry()` on success; on `SourceFetchNotFoundException`, return `$entry !== null ? $entry['data'] : $fallback`; on `Throwable`, `markFailure()`, return stale `$entry['data']` if present, else dispatch async revalidation (if supported) and return `$fallback`.

### 3.3 Existing `SourceCacheTest.php` Assertions to Update
- `tests/Unit/Support/SourceCacheTest.php:153-176` (`test_prime_many_skips_unobserved_failure_marker_deletes`):
  - Rename/update to `test_prime_many_always_clears_failure_markers_even_when_unobserved_in_current_process` and replace `$cache->shouldNotReceive('forget')` with expecting `forget($first->cacheKey().':failure:v1')` and `forget($second->cacheKey().':failure:v1')` once each!
- `tests/Unit/Support/SourceCacheTest.php:320-349` (`test_search_lock_timeout_never_starts_a_second_inline_fetch`):
  - Update line 348 from `self::assertIsArray($cache->get($spec->cacheKey().':failure:v1'));` to `self::assertNull($cache->get($spec->cacheKey().':failure:v1'));` and also test with `'database'` async queue driver asserting `self::assertSame([], $this->backgroundRunner?->spawned ?? [])`.

---

## Section 4: PHPStan Level-6 & Pint Notes

- **Pint (`pint.json`)**: Laravel preset with `concat_space: false`, `new_with_parentheses.anonymous_class: false`, `not_operator_with_successor_space: false`, `single_line_comment_spacing: false`, `phpdoc_align: false`.
- **PHPStan Level-6 Considerations on Modified/New Files**:
  - `UpstreamHttp.php`: Annotate `@param float|array<string, string> $timeout`, `@param array<string, string> $headers`; narrow `is_array($timeout)` before passing `float` to `configure()`; guard `preg_replace` returning `string|null` with `?? ''`; handle `strtotime()` returning `int|false`.
  - `WingsRemoteFilesystem.php`: Annotate `@template T`, `@param callable(): T $operation`, `@return T` on `withRetry()`; `@param list<array{from: string, to: string}> $files` on `renameBatch()`; `@param list<string> $files` on `deleteBatch()`; guard `fopen('php://temp/maxmemory:2097152', 'r+b')` returning `resource|false` and `ftell()` returning `int|false`.
  - `SourceCache.php`: Annotate `@param callable(float): mixed $fetcher` on `swrWithFetcher()`; ensure `$memos` ArrayObject reads/writes keep existing clean style.
  - `ModrinthSource.php` & `CurseForgeSource.php`: Remove now-unused `use Illuminate\Support\Facades\Cache;` imports.
  - `HangarSource.php`: Guard `app()->bound(ExceptionHandler::class)` before `report(...)` in `authHeaders()`.
  - `SingleFlightTimeoutException.php`: Strict `final class SingleFlightTimeoutException extends RuntimeException {}`.

---

## Section 5: Actionable Unit Test Plan

1. **`tests/Unit/Support/UpstreamHttpTest.php`** (use `Sleep::fake()` in setUp/tearDown with `Sleep::fake(false)` cleanup!):
   - `userAgent()` returns `pelican-mod-manager/<plugin.json version> (+https://github.com/kazaminosuke/pelican-mod-manager)`.
   - `json()` sets `User-Agent`, `Accept-Encoding: gzip, deflate`, `decode_content: true`, `timeout`, `connectTimeout = min(3.0, $timeout)`, and supports legacy 1-arg array headers.
   - Retries on `429` (with `Retry-After: 0`, delta-seconds, HTTP-date, and capped by remaining deadline!), `408`, `502`, `503`, `504`, and `ConnectionException`.
   - Does **NOT** retry on `400`, `401`, `403`, `404`, `500` (assert `Http::assertSentCount(1)`).
   - Without `->throw()`, exhausted retries return the final `Response` (status `503`, `$response->failed() === true`) instead of throwing `RequestException`; with `->throw()`, exhausted retries throw `RequestException`.
   - `configurePoolRequest()` attaches UA, gzip, timeout, connectTimeout, and retries inside `Http::pool()`.
2. **`tests/Unit/Support/WingsRemoteFilesystemTest.php`** (inject fast recording `$sleeper` AND verify default `[500, 2000, 5000]` delays!):
   - Pre-send `ConnectionException` (`cURL error 6:`, `7:`, `35:`, `28: Resolving timed out`, `28: Connection timed out`) retries with exact `[500, 2000, 5000]` ms backoff and recovers on write/rename/delete/decompress/pull/read.
   - Post-send `cURL error 28: Operation timed out` is **NOT** retried on mutating ops (`putStream`, `move`, `delete`, `decompressFile`, `pullForeground`), **IS** retried on idempotent reads (`listDirectory`, `readStream`, `readPrefix`).
   - Idempotent reads (`listDirectory`, `readStream`, `readPrefix`) retry HTTP `502`, `503`, `504` and do NOT retry `403`/`404`/`500`.
   - `putStream()` sends explicit `Content-Length` for `string` (including `''` -> `'0'`), seekable `StreamInterface`, explicit `$contentLength`, and non-seekable `StreamInterface` with `getSize() === null` (spooled cleanly, never `-1`); rejects negative `$contentLength`; rewinds seekable stream on pre-send retry.
   - `readPrefix()` sends `Range: bytes=0-3` + `Accept-Encoding: identity`; handles `206`, `200` (returns only first 4 bytes), and `416` (returns `''`).
   - `decompressFile()` calls `DaemonFileRepository::decompressFile()` (900s timeout) and retries pre-send failures.
   - `renameBatch()` and `deleteBatch()` chunk >200 entries (e.g. 450 items -> 3 calls: 200, 200, 50), skip empty arrays (0 calls), and throw `InvalidArgumentException` on `chunkSize < 1`.
3. **`tests/Unit/Support/SourceCacheTest.php`**:
   - Cold single-flight `LockTimeoutException` returns fallback WITHOUT writing `:failure:v1` marker and WITHOUT dispatching duplicate `RevalidateSourceCache` (tested on both `'sync'` and `'database'` queue drivers).
   - Cross-process recovery: Instance #1 fails `swr()` -> writes `:failure:v1` -> Instance #2 (fresh empty memos, simulating CLI worker) calls `revalidate()` or `primeMany()` -> `:failure:v1` is deleted from cache and Instance #3 `revalidateAsync()` is not blocked.
   - `swrWithFetcher()`: fresh hit, cold failure sets backoff preventing repeated upstream hits, stale hit retained on sync failure, stale hit dispatches async revalidation on database queue.
4. **Source Unit Tests (`ModrinthSourceSearchCacheTest`, `CurseForgeSourceSearchCacheTest`, `HangarSourceSearchCacheTest`, `GitHubReleasesSourceSearchCacheTest`, `SpigotSourceTest`, `OptionalSourceCredentialTest`, `InstalledMetadataPrimingAndRetryTest`)**:
   - All 5 sources send `UpstreamHttp::userAgent()` and `Accept-Encoding: gzip, deflate` (except binary Range probes sending `identity`).
   - `ModrinthSource::requestProject()` drops `body`/`gallery`/`versions` while preserving `id`, `project_id`, `slug`, `title`, `description`, `icon_url`, `author`, `team`, `downloads`, `date_modified`, `project_type`, `server_side`, `client_side`.
   - Filter-option SWR backoff & stale retention on `ModrinthSource`, `CurseForgeSource`, and `HangarSource`.
   - `HangarSource` 401 JWT refresh + retry once on expired JWT, AND graceful public fallback + negative cache when `/authenticate` rejects an invalid API key (never leaking `apiKey`).
   - Normalized version `files[0]['size']` populated for:
     - `CurseForgeSource` (`fileLength`)
     - `HangarSource` (`fileInfo.sizeBytes`)
     - `GitHubReleasesSource` (both REST `asset.size` and GraphQL `releaseAssets.nodes.size`)
     - `SpigotSource` (exact `sizeUnit: 'B'` AND probed `206 Content-Range` / `200 Content-Length`; **omitted** for rounded `KB`/`MB` floats!).
   - `SpigotSource::requestSpigetUpdate()` does NOT retry on 503/429 (`maxRetries: 0`).
   - Update LSP signatures in `RecordingWingsRemoteFilesystem`, `DeploymentWings`, `MemoryModpackWings`, and update `ModpackCreationWizardTest.php:448` for `http_error` 503 retry count.

---

# Part 3: Remaining Unexplored M2 Slice (`io_warm_assets_diag`)

The third M2 explorer (`m2_iter1_explorer_io_warm_assets_diag`) had not produced a blueprint before handoff. The remaining M2 items (all part of Feature #12, summarized in [`core-infrastructure-audit.md`](core-infrastructure-audit.md) §1.3 #9, #14, #16, #17, #19) are straightforward and self-contained to inspect directly in the next session before coding M2:

1. `src/Services/InstalledArchiveTransaction.php` — Reuse `pullForeground()`'s returned `$stat` when valid instead of making a redundant 2nd `findListedFile()` (`listDirectory`) call; fall back to `findListedFile()` only when `$stat` lacks `size`/`modified`.
2. `src/Services/InstalledProjectUpdateService.php` & `src/Support/InstalledMetadataWriteSession.php` — Checkpoint/batch metadata writes during bulk updates (flush periodically + always in `finally`, skipping flush on ownership-loss abort as noted in Part 1 §5.3).
3. `src/Jobs/WarmCatalogSearch.php` & `src/Console/Commands/WarmCatalogCacheCommand.php` — Move `$throttle->tryAcquire()` AFTER server/source/compatibility validation and fresh-cache check; eager-load `egg.variables`; include all MC version variable names from `EggProfileRegistry::all()` in `minecraftVersionsFor()`; eliminate N+1 server re-queries.
4. `src/Support/ModManagerAssets.php` & `src/Http/Controllers/ModManagerAssetController.php` — Process-local memo keyed by asset+`filemtime`; check `isNotModified($request)` BEFORE `file_get_contents()` and `gzencode(..., 6)`.
5. `src/Services/ModManagerDiagnostics.php` — Deduplicate Spiget resource `#2` fetch, run checks via `Http::pool()`, send `'Accept-Encoding' => 'identity'` on the `Range: bytes=0-3` ZIP probe, and add Hangar & GitHub Releases checks.
6. Dead code cleanup (`src/Services/ModpackCatalog.php::searchOptions/versionOptions`, etc. — confirm zero callers/tests before removal).
