> **Handoff Reference Artifact (WIP — Temporary Planning Archive)**  
> This document preserves verified research / design findings from the prior multi-agent session so subsequent sessions on `fix/modpack-wizard-spigot` do not need to repeat the same investigation.  
> See [`docs/handoff/HANDOFF.md`](HANDOFF.md) for the master handoff summary and next-session workflow. Remove or consolidate `docs/handoff/` once Milestones M2–M5 are completed and merged into permanent `docs/*.md`.

# Handoff Report — `survey_explorer_ui_and_core` (Hard Handoff)

- **Agent**: `survey_explorer_ui_and_core` (`teamwork_preview_explorer`)
- **Timestamp**: `2026-11-24T23:33:00Z`
- **Target Repo**: `<repo-root>` (`fix/modpack-wizard-spigot` @ `4e5f839a4d8ede672565b430995e5ee5655167dd`)
- **Scope**: Read-only survey of UI Issue A (Modrinth tile collapse), UI Issue B (Modpack catalog initial view mode), Non-Modpack & Shared Internal Infrastructure Audit, and Credits/License Conventions.

---

## Executive Summary

1. **UI Issue A (Modrinth Tile Collapse)** has **two distinct root causes**, both proven live against Filament 5.11.4 templates/CSS in headless Google Chrome:
   - **Primary (Modrinth-only, triggers on every Modrinth tile in every catalog)**: Filament's `contentGrid` wraps the entire tile card in `<a href="<project_url>" class="fi-ta-record-content">` (`recordUrl`), while `CatalogTileColumns` puts a state-based `TextColumn::make('author')->url($authorUrl, true)` inside it, emitting an **illegal nested `<a href="https://modrinth.com/user/...">`**. Chrome's HTML5 Adoption Agency Algorithm forcibly splits the outer `<a>`, ejects the bottom `Split` out of `.fi-ta-record-content`, and injects a phantom `width: 100%` `<a class="fi-ta-record-content">` as the first flex child of `fi-ta-split` — crushing `date_modified` to `17px × 120px` (one character per line in Japanese).
   - **Secondary (ALL providers & catalogs in tile view)**: Even without the author link, placing 3 columns (`author`, `downloads`, `date_modified`) in one `Split` inside `contentGrid` (which inherits `white-space: normal`, unlike `<table>`'s `whitespace-nowrap`, and lacks `min-width: 0` on `.fi-ta-col.fi-growable`) causes long unbreakable usernames to crush `date_modified` to `16px × 120px` (1 char/line again!) and causes even short usernames (`CaffeineMC`, `robotkoer`) on 310px 3-col cards to wrap `downloads` and `date_modified` onto 2 lines. Both causes are fixed cleanly with **zero custom CSS** by abolishing author links everywhere (preserving `recordUrl`) and using Filament-native `Stack::make([author, Split::make([downloads, date_modified->alignEnd()])])->space(1)`.
2. **UI Issue B (Modpack Catalog Initial View Mode)**: On a clean session/cookie, **all** catalogs (`Mod`, `Plugin`, `Datapack`, `ResourcePack`, `Modpack` Create Wizard, `Modpack` Existing-Server Modal) default to `'list'`. Modpack appears to open in tile view because **all catalogs across both Admin and Server panels share one global session key (`pelican-mod-manager.catalog-view`) and 1-year cookie (`mmr_catalog_view`)**, compounded by parent/modal Livewire drift (`ModManagerPage` never re-syncs in `hydrate()` and forces list view on the `Installed` tab). Additionally, we uncovered a **critical Filament `HasColumnManager` bug** affecting both `ModpackCatalogTable` and `ModManagerPage`: mounting in tile view leaves `$this->tableColumns = []`, so toggling back to list view hides **every toggleable column** until page reload.
3. **Internal Shared Infrastructure Audit**: Identified **21 concrete defects/bottlenecks** (2 Critical, 8 High, 7 Medium, 4 Low) — headlined by a **Critical silent token-wipe bug in `ModManagerPlugin::getSettingsFormData()`** (`modrinth_token` and `hangar_api_key` omitted, causing Save to wipe both to `''`), a **Critical silent HTTP-error hashing bug in `InstalledProjectService::openDaemonFileStream()`** (missing `$response->throw()`), missing `OPERATION_MODPACK` checks in `InstalledOperationManager::operationStateOrActive()`, `SourceCache` single-flight outage-marker amplification & CLI `forgetFailureMarker()` no-op, missing HTTP retries/`User-Agent`/file-size propagation across sources, synchronous un-throttled `Cache::remember()` filter endpoints, redundant Wings round-trips during installs/bulk-updates, and `ShiftCoreNavigationRows` static state leakage.

---

## 1. Observation

### 1.1 UI Issue A — Modrinth Tile Display Collapse & Cross-Provider Tile Audit

#### Recent UI Commits Inspected
- `c379eb2`: Added custom `modpack-catalog.blade.php` + `modpack-catalog.css`.
- `7e04bb7`: Rebuilt Modpack catalog using Filament native `Table` (`ModpackCatalogTable`) and added `recordUrl` external links.
- `6b94075`: Introduced `src/Support/CatalogTileColumns.php` (`Stack` / `Split` + `contentGrid`) and server-side `catalogView` (`SESSION_KEY` + `COOKIE_KEY`), removing old client-side `localStorage` toggle from `resources/assets/mod-manager-runtime.js`. Initial `CatalogTileColumns` had `author` inside top `Stack([title, author])` next to `icon`, and bottom `Split([downloads, date_modified->alignEnd()])`.
- `4e5f839` (current `HEAD`): Moved `author` into bottom `Split([author, downloads->grow(false), date_modified->grow(false)])` in `src/Support/CatalogTileColumns.php:108-134`, kept `->url($authorUrl, true)` on line 115, added `->toggleable()` to all 3 bottom columns, and added right-aligned `recordActionsAlignment(Alignment::End->value)`.

#### Exact Source Locations of Modrinth Author Links (ALL occurrences in repo)
1. `src/Support/CatalogTileColumns.php:63,71,109-116`:
   ```php
   TextColumn::make('author')
       ->label(trans('pelican-mod-manager::strings.table.columns.author'))
       ->icon('tabler-user')
       ->color('gray')
       ->size(TextSize::Small)
       ->lineClamp(1)
       ->url($authorUrl, true)
       ->toggleable(),
   ```
2. `src/Filament/Server/Pages/ModManagerPage.php:3189-3191` (tile view callback):
   ```php
   authorUrl: fn (array $record, $state): ?string => (($record['source'] ?? null) === ProjectSourceKey::Modrinth->value && is_string($state) && $state !== '')
       ? "https://modrinth.com/user/$state"
       : null,
   ```
3. `src/Filament/Server/Pages/ModManagerPage.php:3251-3255` (list view `author` column):
   ```php
   TextColumn::make('author')
       ->label(trans('pelican-mod-manager::strings.table.columns.author'))
       ->url(fn (array $record, $state) => (($record['source'] ?? null) === ProjectSourceKey::Modrinth->value && $state) ? "https://modrinth.com/user/$state" : null, true)
       ->extraCellAttributes(['data-mmr-swr-cell' => 'author'])
       ->toggleable(),
   ```
4. `src/Filament/Components/ModpackCatalogTable.php:266` (tile view callback), `529-532` (list view `author` column), and `807-814` (`private function authorUrl()`):
   ```php
   private function authorUrl(array $record, mixed $state): ?string
   {
       return ($record['provider'] ?? null) === ModpackProvider::Modrinth->value
           && is_string($state)
           && preg_match('/^[A-Za-z0-9._-]+$/D', $state) === 1
           ? "https://modrinth.com/user/{$state}"
           : null;
   }
   ```
5. `resources/assets/table-swr-cache.js:522-525`: Comment referencing Modrinth author URL (`// Record URLs and the Modrinth author URL wrap otherwise identical text in <a> elements.`).
6. **Project external links (MUST BE PRESERVED)**:
   - `src/Filament/Server/Pages/ModManagerPage.php:3276-3282`: `->recordUrl(fn (array $record) => ... $this->getExternalProjectUrl($record), true)`
   - `src/Filament/Components/ModpackCatalogTable.php:269`: `->recordUrl(fn (array $record): ?string => is_string($record['url'] ?? null) && $record['url'] !== '' ? $record['url'] : null, true)`

#### Exact Filament 5.11.4 Template & CSS Mechanics Verified from Composer Package (`filament/tables` `e172dbb`)
1. **Outer card `<a>` in `contentGrid` mode** (`filament/tables/resources/views/index.blade.php:1397-1411`):
   ```blade
   @if ($recordUrl)
       <a
           {{ \Filament\Support\generate_href_html($recordUrl, $openRecordUrlInNewTab, hasNestedClickEventHandler: true) }}
           {{ $getExtraRecordLinkAttributeBag($record)->class(['fi-ta-record-content']) }}
       >
           @foreach ($columnsLayout as $columnsLayoutComponent)
               {{ $columnsLayoutComponent->record($record)->recordKey($recordKey)->rowLoop($loop)->renderInLayout() }}
           @endforeach
       </a>
   ```
   Notice `index.blade.php` in `contentGrid` mode wraps the **entire** card layout in `<a>` unconditionally — unlike table/list mode (`index.blade.php:2575-2585`), which checks `(! $columnHasStateBasedUrls)` and renders `<div>` for the cell wrapper instead of `<a>`.
2. **Inner `<a>` emitted by `TextColumn`** (`filament/tables/src/Columns/Concerns/CanOpenUrl.php:40-44` & `filament/tables/src/Columns/TextColumn.php:340-356`):
   - Because `$authorUrl` has a `$state` parameter, `hasStateBasedUrls()` is `true`.
   - `Column::renderInLayout()` (`Column.php:158`) gets `null` from `getUrl()` (0 args) so `$wrapperTag = 'div'`.
   - `TextColumn::toEmbeddedHtml()` (`TextColumn.php:340-356`) calls `$this->getUrl($stateItem, $relatedRecord)` and wraps the author text in `<a href="https://modrinth.com/user/..." target="_blank">...</a>` — **ONLY for Modrinth** (all other providers return `null`).
3. **Filament `content.css`, `split.css`, `stack.css`, `text.css`, `table.css`, and compiled `theme.css`**:
   - `content.css:223-260`: `.fi-ta-record-content { @apply block w-full; }` with `ps-4`/`pe-4` (`316,326`), `.fi-ta-record-content .fi-ta-col { @apply flex justify-start text-start; }`, `.fi-ta-record-content .fi-growable { @apply w-full flex-1; }` — **NO `min-width: 0`**, **NO `shrink-0`**, **NO `whitespace-nowrap`**.
   - `table.css:211`: `.fi-ta-records { @apply divide-y divide-gray-200 whitespace-nowrap ...; }` — `whitespace-nowrap` exists ONLY on `<table>`, NOT on `contentGrid`!
   - `split.css:1-6`: `.fi-ta-split { @apply flex; } .fi-ta-split.default\:fi-ta-split { @apply items-center gap-3; }`.
   - `filament/panels/dist/theme.css`: Verified via `unzip -p` that Filament's precompiled Tailwind v4 theme compiles component classes only and does **NOT** expose raw Tailwind utility selectors `.whitespace-nowrap`, `.break-all`, `.min-w-0`, `.shrink-0`.
   - `resources/assets/mod-manager.css` (lines 1–50): Contains **no** rules targeting `.fi-ta-content-grid`, `.fi-ta-split`, `.fi-ta-stack`, or tile metadata columns.

#### Live Headless Chrome (`/usr/bin/google-chrome --headless --dump-dom`) Reproduction Measurements

1. **DOM Mutation Proof (Nested `<a>` in Modrinth tile)**:
   Chrome's HTML5 parser (WHATWG Adoption Agency Algorithm) transforms the single outer `<a class="fi-ta-record-content">` into **6 fragmented `<a class="fi-ta-record-content">` elements**, ejects the bottom `<div class="fi-growable">` out of `.fi-ta-record-content`, injects an empty `<a class="fi-ta-record-content"></a>` (`display: block; width: 100%; padding-inline: 1rem`) as the **1st flex child of `fi-ta-split`** (`splitChildren: 4` instead of `3`), and wraps the author's `<svg class="fi-icon">` in its own `<a class="fi-ta-record-content">`.
2. **Exact Bounding-Box Measurements in Chrome (`cardW = 310px` typical 3-col `xl` tile, line-height 24px)**:

| Test Scenario | `splitChildren` | `author` (`x, w, h`) | `downloads` (`x, w, h`) | `date_modified` (`x, w, h`) | Outcome |
|---|---:|---|---|---|---|
| **1. Current Modrinth (nested `<a>`, short author `jellysquid3`, `2か月前`)** | **4** (phantom `A` first!) | `x=110, w=65, h=24` | `x=188, w=70, h=48` (2 lines!) | **`x=270, w=17, h=120`** (**5 lines = 1 char/line!**) | **CATASTROPHIC COLLAPSE** |
| **2. Clean (no nested `<a>`, short `jellysquid3`, `2か月前`)** | 3 | `x=17, w=100, h=24` | `x=129, w=86, h=24` | `x=227, w=66, h=24` | OK (short text only) |
| **3. Clean (no nested `<a>`, long unbreakable `SuperLongModpackCreatorName_2026`, `2か月前`)** | 3 | `x=17, w=248, h=24` (refuses to shrink!) | `x=277, w=70, h=48` (2 lines + overflow!) | **`x=359, w=16, h=120`** (**5 lines = 1 char/line + overflows right edge!**) | **COLLAPSE (ALL providers!)** |
| **4. Clean (no nested `<a>`, short `CaffeineMC`, `123,456,789`, `11 months ago`)** | 3 | `x=17, w=74, h=24` | `x=103, w=83, h=48` (2 lines!) | `x=198, w=95, h=48` (2 lines!) | **WRAPS (ALL providers!)** |
| **5. Clean (no nested `<a>`, short `robotkoer`, `123,456,789`, `vor 11 Monaten`)** | 3 | `x=17, w=59, h=24` | `x=88, w=88, h=48` (2 lines!) | `x=188, w=105, h=48` (2 lines!) | **WRAPS (ALL providers!)** |
| **6. Clean top `Split` (long unbreakable `title`, `cardW=310px`)** | 2 | title: `x=55, r=331 > 310, w=276` | — | — | **Overflows right edge** (`min-width: auto`) |
| **7. Clean `author = null` (FTB/Technic/ATLauncher)** | 3 | `x=17, w=100, h=0` | `x=129, w=86, h=24` | `x=227, w=66, h=24` | Empty `h=0` spacer pushes stats right |
| **8. PROPOSED FIX: Pure Filament `Stack([author, Split([downloads, date->alignEnd()])])->space(1)` at `cardW=280px`, 55-char unbreakable author, `123,456,789`, `vor 11 Monaten`** | 2 in bottom Split | **`x=17, r=263, w=246, h=24`** | **`x=17, r=139, w=122, h=24`** | **`x=151, r=263, w=112, h=24`** | **ZERO WRAPPING, ZERO OVERFLOW, ZERO CUSTOM CSS** |

#### Provider & Catalog Metadata Coverage Matrix (All 8 Providers × All 6 Catalogs)

| Provider | Catalogs | `author` | `downloads` | `date_modified` | `recordUrl` | Author URL today |
|---|---|---|---|---|---|---|
| **Modrinth** (`ModrinthSource.php:663-668`, `ModpackCatalog.php:109-123`) | Mod, Plugin, Datapack, ResourcePack, Modpack (Create + Server) | string | int | ISO-8601 | `https://modrinth.com/{type}/{slug}` | **YES (`modrinth.com/user/{author}`) — BREAKS TILE DOM** |
| **CurseForge** (`CurseForgeSource.php:1943-1948`, `ModpackCatalog.php:109-123`) | Mod, Plugin, Datapack, ResourcePack, Modpack (Create + Server) | string\|null | int | ISO-8601 | `https://www.curseforge.com/minecraft/.../{slug}` | No (`null`) — still hit by Cause #2 on long author / narrow 3-col tiles |
| **Hangar** (`HangarSource.php:1312-1317`) | Plugin | string\|null (`owner`) | int | ISO-8601 | `https://hangar.papermc.io/{owner}/{slug}` | No (`null`) — hit by Cause #2 |
| **Spigot** (`SpigotSource.php:1796-1801,1831-1836`) | Plugin | string\|null | int\|null | ISO-8601\|null | `https://www.spigotmc.org/resources/{id}/` | No (`null`) — hit by Cause #2 |
| **GitHub Releases** (`GitHubReleasesSource.php:696-702`) | Installed tab only (`supportsSearch() = false`) | string\|null | int | ISO-8601\|null | `https://github.com/{owner}/{repo}` | No (Installed tab forces list view) |
| **FTB** (`FtbModpackSource.php:204-217`, `ModpackCatalog.php:205-215`) | Modpack (Create + Server) | string\|null | int\|null (`plays`) | ISO-8601\|null | `https://www.feed-the-beast.com/modpacks/{id}-{slug}` | No (`null`) — hit by Cause #2 |
| **Technic** (`TechnicModpackSource.php:52-66`) | Modpack (Create only) | `null` | `null` | `null` | `https://www.technicpack.net/modpack/{slug}` | No (`null`) |
| **ATLauncher** (`AtlauncherModpackSource.php:146-163`) | Modpack (Create only) | `null` | `null` | `null` | `https://atlauncher.com/pack/{safeName}` | No (`null`) |

*(Note: Confirmed via `src/Enums/ProjectType.php:9-15` that there is NO Shader catalog — supported `ProjectType` cases are `Mod`, `Plugin`, `Datapack`, `ResourcePack`, plus Modpacks via `ModpackCatalogTable`.)*

---

### 1.2 UI Issue B — Modpack Catalog Initial View Mode & Preference Persistence

#### Complete Catalog View-Mode Initialization Matrix

| Catalog | Livewire Component | Property Default | `mount()` Init | Storage Keys Written on Toggle | Default (No Pref) | Forced List Condition |
|---|---|---|---|---|---|---|
| **Mod** | `ModManagerPage` (`:206,618`) | `'list'` | `CatalogTileColumns::resolveInitialView()` | Session `pelican-mod-manager.catalog-view` + Cookie `mmr_catalog_view` (525600 min, `/`) | `'list'` | `activeTab === 'installed'` (`:3727`) |
| **Plugin** | `ModManagerPage` (`:206,618`) | `'list'` | Same | Same | `'list'` | `activeTab === 'installed'` |
| **Datapack** | `MinecraftDatapackPage extends ModManagerPage` | `'list'` | Inherited | Inherited | `'list'` | `activeTab === 'installed'` |
| **Resource Pack** | `MinecraftResourcePackPage extends ModManagerPage` | `'list'` | Inherited | Inherited | `'list'` | `activeTab === 'installed'` |
| **Modpack (Admin Create Wizard)** | `ModpackCatalogTable` (`mode='create'`, `:87,132`) | `'list'` | `CatalogTileColumns::resolveInitialView()` | **Same global session & cookie keys** (`:191-192`) | `'list'` | None (no Installed tab) |
| **Modpack (Server Modal)** | `ModpackCatalogTable` (`mode='server'`, `:87,132`) | `'list'` | `CatalogTileColumns::resolveInitialView()` | **Same global session & cookie keys** (`:191-192`) | `'list'` | None (no Installed tab) |

#### Exact Discrepancies & Bugs Found in View-Mode Handling
1. **Shared Global Session + 1-Year Cookie Across All Catalogs & Panels (`src/Support/CatalogTileColumns.php:28-52`, `ModManagerPage.php:636-637`, `ModpackCatalogTable.php:191-192`)**:
   - No `localStorage` or URL query parameter is used anymore (old `localStorage` key `pelican-mod-manager.catalog-view` was removed in `6b94075`).
   - Instead, toggling **any** catalog (e.g., Mod/Plugin/Datapack/ResourcePack while testing tile view!) persists `'panel'` globally to `session('pelican-mod-manager.catalog-view')` and a **365-day root-path cookie** `mmr_catalog_view=panel`.
   - When opening `CreateServer` (`ModpackCreationStep` -> `ModpackCatalogTable`) or the `install_modpack` modal on `ModManagerPage`, `ModpackCatalogTable::mount()` reads `'panel'` from the shared cookie/session and **immediately renders in tile view**.
   - Worse: If the user is on `ModManagerPage`'s `Installed` tab (forced list mode via `isCatalogTileView()` at `ModManagerPage.php:3727`) and clicks **"Choose Modpack"**, the background page is in list view while the Modpack modal pops up in tile view!
2. **Parent/Modal Livewire State Desync (`ModManagerPage.php:618,644-648`, `ModpackCatalogTable.php:185-195`)**:
   - `ModManagerPage` reads `CatalogTileColumns::resolveInitialView()` only in `mount()`, never in `hydrate()`.
   - Toggling `ModpackCatalogTable` inside the `install_modpack` modal updates session + cookie, leaving the parent `ModManagerPage::$catalogView` stale in the opposite mode after closing the modal. Subsequent opens of `ModpackCatalogTable` (Create Wizard or re-opened modal) open in the new mode (`panel`) while the parent `ModManagerPage` stays in `list`!
3. **CRITICAL Filament `HasColumnManager` Bug When Toggling Tile (`panel`) → List (`list`) (`ModManagerPage.php:630-642,1098-1101`, `ModpackCatalogTable.php:185-195`, Filament `HasColumnManager.php:35-44,136-139`)**:
   - When a component mounts directly in tile view (`$catalogView === 'panel'`), `CatalogTileColumns::columns()` returns `[Stack::make([...])]`, so `$this->getTable()->hasColumnsLayout()` is `true`.
   - Filament's `HasColumnManager::initTableColumnManager()` (`HasColumnManager.php:36-38`) immediately returns early: `if ($this->getTable()->hasColumnsLayout()) { return; }`, leaving `$this->tableColumns = []`.
   - When the user clicks `toggleCatalogView()` (or switches from a tile-view catalog tab to the `Installed` tab via `updatedActiveTab()` at `ModManagerPage.php:1098-1101`), both methods rebuild `$this->table = $this->table($this->makeTable());` **without resetting `$this->cachedDefaultTableColumnState` or calling `$this->initTableColumnManager()`**!
   - During render, `Table::getVisibleColumns()` (`HasColumns.php:129-135`) filters by `! $column->isToggledHidden()`, which calls `isTableColumnToggledHidden($name)` -> `! ($this->getTableColumnsToggleStateByName()[$name] ?? false)`. Because `$this->tableColumns` is empty `[]`, **every `->toggleable()` column disappears** (`author`, `downloads`, `date_modified`, `source` on `ModManagerPage`; `minecraft_versions`, `loaders`, `author`, `downloads`, `date_modified` on `ModpackCatalogTable`) until full page reload!
4. **Minor Structural Inconsistencies & Dead Code**:
   - `ModpackCatalogTable.php:199,298-300` applies `contentGrid` eagerly (`if ($isPanelView) { $table = $table->contentGrid(...); }`), whereas `ModManagerPage.php:3274` uses a closure `->contentGrid(fn (): ?array => ...)`.
   - `ModpackCatalogTable::toggleCatalogView()` (`:193`) omits `if (isset($this->table))` guard that `ModManagerPage::toggleCatalogView()` (`:638`) has; both duplicate session+cookie persistence instead of calling a shared method on `CatalogTileColumns`.
   - `CatalogTileColumns::normalizeView()` (`src/Support/CatalogTileColumns.php:54-57`) is **dead code** (0 callers).
   - `resources/assets/table-swr-cache.js:305-316,361-379` omits `catalogView` from `buildKey()` and `describeViewState()`.

---

### 1.3 Prioritized Inventory of Non-Modpack & Shared Internal Codebase Issues (§4)

| # | Severity | Area | Exact File:Line | Concrete Defect / Bottleneck | Recommended Fix |
|---|---|---|---|---|---|
| **1** | **CRITICAL** | Plugin Settings / Data Loss | `src/ModManagerPlugin.php:189-204` vs `:251-262,367-368` | `getSettingsFormData()` omits `modrinth_token` and `hangar_api_key`, though both exist in `getSettingsForm()` and `saveSettings()`. Opening Plugin Settings renders both blank; saving ANY other setting **silently overwrites `MOD_MANAGER_MODRINTH_TOKEN` and `MOD_MANAGER_HANGAR_API_KEY` with `''` in `.env`**. | Add `'modrinth_token' => config('pelican-mod-manager.modrinth_token')` and `'hangar_api_key' => config('pelican-mod-manager.hangar_api_key')` to `getSettingsFormData()`, plus add unit test verifying round-trip preservation of all 13 keys. |
| **2** | **CRITICAL** | Installed Scan Integrity | `src/Services/InstalledProjectService.php:1411,1969-1978` | `openDaemonFileStream()` calls `$fileRepository->...->get(...)` and returns `$response->toPsrResponse()->getBody()` **without calling `$response->throw()`**! On the sequential scan path (e.g., 1 new file where `canUseParallelWingsDownload()` is `false`), a Wings 404/403/500 JSON error body is silently hashed as if it were the JAR, persisted as "unresolved" for 24h (`UNRESOLVED_RECHECK_SECONDS`), and never reported as a hash failure. | Call `$response->throw()` before returning `$response->toPsrResponse()->getBody()` (matching `WingsRemoteFilesystem::readStream():194`). Add unit test for HTTP 500/404 in sequential scan path. |
| **3** | **HIGH** | Queue / Operation Concurrency | `src/Services/InstalledOperationManager.php:606-634` (`operationStateOrActive`), `src/Jobs/ScanInstalledProjects.php` | `operationStateOrActive()` only checks `OPERATION_SCAN` and `OPERATION_BULK_UPDATE` (`:623-626`), **completely ignoring `OPERATION_MODPACK`**. Opening the Installed tab during a running Modpack install passes `operationStateOrActive()`, queues a `ScanInstalledProjects` job, overwrites scan state to `queued`, and forces `ProcessBackgroundJobsCommand` to claim & `release()` that scan job repeatedly (decrementing `attempts` back to 0 every time via `DatabaseBackgroundJobQueue::release():156`). Similarly `dispatchBulkUpdate()` returns `state: null` instead of the blocking modpack state. | Check all active operations `[OPERATION_SCAN, OPERATION_BULK_UPDATE, OPERATION_MODPACK]` in `operationStateOrActive()`. Also add a max-release guard / deadline on `DatabaseBackgroundJobQueue::release()` so stuck release loops cannot spin indefinitely. |
| **4** | **HIGH** | Cache / Stampede & Outage Amplification | `src/Support/SourceCache.php:416-450,587-590,756-768` | Three bugs in `SourceCache`: (a) On cold single-flight `LockTimeoutException` (`:431-441`), `swr()` catches `RuntimeException("... is already being fetched")`, **writes a 30s failure marker (`markFailure`)** poisoning the cache while request #1 is still completing, and dispatches a duplicate `RevalidateSourceCache` job (`:84`); (b) `forgetFailureMarker()` (`:756-768`) only deletes the cache key if the *same* PHP request previously read it into `$memos['failures']` — making it a **complete no-op** inside CLI background jobs (`RevalidateSourceCache`, `WarmProjectMetadata::primeMany()`); (c) `usesSingleFlight()` (`:587-590`) excludes `'versions'`, `'latest'`, `'projects'`, leaving them open to stampedes. | Throw a dedicated `SingleFlightTimeoutException` that returns empty/fallback **without** writing a failure marker or dispatching a second job; unconditionally `$this->cache->forget($failureKey)` in `storeEntry()`/`primeMany()` when persisting fresh data (or use Redis `DEL` pipeline); consider extending single-flight to `'versions'`/`'latest'`. |
| **5** | **HIGH** | HTTP Reliability & API Compliance | `src/Support/UpstreamHttp.php:18-26`, `ModrinthSource.php:1566-1574`, `CurseForgeSource.php:2097-2104`, `HangarSource.php:1550-1604`, `GitHubReleasesSource.php:1171-1197`, `FtbModpackSource.php:68-71`, `WingsRemoteFilesystem.php` | (a) `UpstreamHttp::json()` sets **no `User-Agent`** (Modrinth explicitly mandates an app `User-Agent`; GitHub requires one; only `SpigotSource` sets one); (b) `HangarSource`, `GitHubReleasesSource`, and `FtbModpackSource` pool bypass `UpstreamHttp::json()` (no `Accept-Encoding: gzip, deflate`); (c) **Zero retry/backoff** (`429 Retry-After`, `502`/`503`/`504`, transient connection errors) across all 8 sources and `WingsRemoteFilesystem`; (d) `CurseForgeSource:2103`, `GitHubReleasesSource:1187`, `HangarSource:1583` hardcode `connectTimeout(min(1.0, $timeout))` (1s even on 10s background jobs, causing false timeouts from high-latency regions); (e) `HangarSource::authHeaders()` has no 401 JWT refresh/retry and fails public browsing if an invalid API key is configured. | Centralize `User-Agent: pelican-mod-manager/<ver> (+https://github.com/kazaminosuke/pelican-mod-manager)` and `Accept-Encoding: gzip, deflate` in `UpstreamHttp`, route all sources through it, add bounded idempotent retry (respecting `Retry-After`, skipping 4xx except 429/408, bounded within remaining deadline) for both upstream HTTP and idempotent Wings calls, raise background `connectTimeout` to `min(3.0, $timeout)`, and refresh Hangar JWT once on 401. |
| **6** | **HIGH** | Archive Integrity Verification | `src/Sources/CurseForgeSource.php:2081-2088`, `HangarSource.php:1370-1378`, `GitHubReleasesSource.php:727-735`, `SpigotSource.php:1906-1915`, `src/Services/InstalledArchiveTransaction.php:62,226,364-375` | Only `ModrinthSource` passes `'size'` in normalized version `files[]`. CurseForge (`fileLength`), Hangar (`fileInfo.sizeBytes`), GitHub (`asset.size`), and Spigot (`file.size`) all omit `'size'`, so `InstalledArchiveTransaction::assertCompletedPull()` (`:226`) silently skips size checks on all 4 sources. Moreover, `pullForeground()` never verifies file hashes (`sha512`/`sha256`/`murmur2`) yet writes the upstream's unverified hashes into `.pelican-mod-manager.json` (`:364-375`). | Populate `'size'` in normalized `files[]` for CurseForge (`fileLength`), Hangar (`fileInfo.sizeBytes`), GitHub (`asset.size`), and Spigot (when known). Optionally verify hashes on pulled files or distinguish unverified upstream hashes from computed hashes. |
| **7** | **HIGH** | Scheduler Starvation & Idle DB Churn | `src/Providers/ModManagerServiceProvider.php:153-166`, `src/Console/Commands/ProcessBackgroundJobsCommand.php:24-26,45-94`, `src/Jobs/BulkUpdateInstalledProjects.php:83-95` | (a) `->everyTwoSeconds()->when(fn () => $queue->hasPending())` keeps `schedule:run` resident for 60s and fires **30 DB queries/min** even on completely idle panels; (b) `->withoutOverlapping(10)` serializes ALL background work globally into 1 worker — **one slow Modpack install starves every other server's priority-1 scans, metadata fills, and revalidations** for up to 10 minutes (and if a Modpack install exceeds 10 min, the overlap mutex expires while the job is still running); (c) In `BulkUpdateInstalledProjects`, heartbeat only runs *after* each mod finishes (`:87-95`), so a single >5min pull (`FOREGROUND_PULL_TIMEOUT_SECONDS=900` > `STALE_RESERVATION_MINUTES=5`) loses its DB reservation mid-download! | Separate fast interactive/cache jobs from long-running Modpack/Bulk jobs (or allow bounded worker concurrency via `SKIP LOCKED` / separate mutex for long jobs), gate `hasPending()` with a cheap cache flag set on `push()` (avoiding 30 idle DB queries/min), and align reservation TTL / heartbeat intervals with pull timeouts. |
| **8** | **HIGH** | Blocking Render-Path API Calls (Uncached/Raw `Cache::remember`) | `src/Sources/ModrinthSource.php:292,324,369,398,633`, `CurseForgeSource.php:1650,1687`, `HangarSource.php:278`, `AtlauncherModpackSource.php:32-37`, `TechnicModpackSource.php:34-39,79-84`, `FtbModpackSource.php:41-46` | (a) All 7 filter-option endpoints (`Modrinth` `/tag/game_version`, `/tag/loader`, `/tag/category`, `/tag/license`; `CurseForge` `/categories`, `/minecraft/version`; `Hangar` `/platforms/{p}/versions`) use synchronous `Cache::remember(..., now()->addDay(), ...)` with **10s blocking HTTP timeout**, no SWR stale fallback, and no failure backoff — an upstream blip blocks EVERY catalog page load for up to 40s (4 × 10s on Modrinth)! (b) `AtlauncherModpackSource::search()` downloads the entire `/v1/packs/simple` catalog with **zero caching** on every search; `TechnicModpackSource::search()`/`describe()` and `FtbModpackSource::search()` also have zero search caching. | Route filter-option metadata through `SourceCache` SWR (or short negative-cache backoff + stale retention) with short inline budget; cache ATLauncher `/v1/packs/simple` (e.g., 1h TTL) and FTB/Technic search/pack metadata. |
| **9** | **HIGH** | Redundant Wings Round-Trips & Bandwidth Waste | `src/Services/InstalledArchiveTransaction.php:77,92-94,214-235`, `src/Support/WingsRemoteFilesystem.php:168-197`, `src/Services/InstalledProjectUpdateService.php:49-77`, `src/Support/InstalledMetadataWriteSession.php:31-42` | Per single install/update: (1) `findListedFile` (full `listDirectory`), (2) `pullForeground` (returns Wings file `Stat`!), (3) `assertCompletedPull` calls `findListedFile` (**2nd full `listDirectory`** even when `$stat` already returned `size`/`modified`!), (4) `readPrefix` opens a streaming `GET /files/contents` without `Range` header to read 4 bytes and aborts the connection. During a 50-mod bulk update: **100 full directory listings + 50 aborted file streams + 50 full `.pelican-mod-manager.json` PUTs + 50 hydration cache bumps**. | Reuse `pullForeground()`'s returned `$stat` when valid (falling back to `findListedFile()` only when `$stat` lacks `size`/`modified`, e.g., `putStream`); send `Range: bytes=0-3` with `Accept-Encoding: identity` in `readPrefix()` (handling 206/200 cleanly); batch or checkpoint metadata writes in `InstalledProjectUpdateService` while preserving crash safety (flush every N items + always in `finally`). |
| **10** | **HIGH** | Concurrency / Race Condition | `src/Services/ResourcePackService.php:70-139` | `installOrUpdate()` and `uninstall()` perform un-locked `getContent('server.properties')` → `putContent('server.properties')` → `putContent/deleteFiles(metadata)` with **no cache lock or operation lease**. Concurrent clicks race and corrupt `server.properties` or metadata; also crashes with raw `FileNotFoundException` if `server.properties` does not yet exist on a fresh server. | Wrap `installOrUpdate()` and `uninstall()` in a per-server cache lock (matching `InstalledMetadataRepository::withinLock()`) and handle missing `server.properties` explicitly (either create or raise a clear localized error). |
| **11** | **MEDIUM** | Worker State Leak | `src/Support/BackgroundJobRuntime.php:27-45`, `src/Services/InstalledOperationManager.php:52` | `BackgroundJobRuntime::forgetRequestState()` clears `SourceCache`, `InstalledProjectService`, and `ServerModManagerSettings`, but **forgets `InstalledOperationManager::$progressBuffer`**! If a job exits abnormally or loses ownership (`scan_ownership_lost`) after writing buffered progress, stale state remains in the singleton across subsequent jobs in the same `ProcessBackgroundJobsCommand` process. | Add `InstalledOperationManager::clearRuntimeCaches()` clearing `$this->progressBuffer = []` and invoke it in `BackgroundJobRuntime::forgetRequestState()`. |
| **12** | **MEDIUM** | Disk / Memory / Timeout Safety in Parallel Hash Scan | `src/Services/InstalledProjectService.php:1453-1528,1703-1709,1840-1844` | (a) `computeScanHashesParallel()` downloads 4 JARs concurrently with default 15s Wings timeout (no timeout override for large JARs), **no max file size cap** before writing to `sys_get_temp_dir()`, and strict barrier chunking (`array_chunk(4)` + `settle()->wait()` waits for slowest file in each batch); (b) `extractBukkitPluginDescriptor()` (`:1703-1709`) and `computeDaemonFileHashesAndDescriptor()` (`:1840-1844`) unlink `tempnam()` and open `$tmp.'.jar'` (unnecessary TOCTOU race — `ZipArchive::open()` does not need a `.jar` extension). | Enforce reasonable timeout & file-size guard using Wings directory listing's `size` before downloading; use `ZipArchive::open()` directly on the `tempnam()` file without unlink+rename; consider sliding-window concurrency (`Pool`/`EachPromise`). |
| **13** | **MEDIUM** | Redis Memory Bloat | `src/Sources/ModrinthSource.php:1316-1335` | `requestProject()` (single `/v2/project/{id}`) caches the **raw untrimmed Modrinth JSON** — including multi-KB Markdown `body`, `gallery` arrays, and hundreds of `versions` IDs — whereas `requestProjects()` (`:1295-1305`) trims to 9 display fields. Inflates Redis cache and Livewire payloads. | Normalize `requestProject()` to only the fields actually consumed (plus `team` if needed for author resolution). |
| **14** | **MEDIUM** | Rate-Limit Waste & N+1 DB Queries in Warm Jobs | `src/Jobs/WarmCatalogSearch.php:61-127`, `src/Console/Commands/WarmCatalogCacheCommand.php:106-114,251` | (a) `WarmCatalogSearch::handle()` calls `$throttle->tryAcquire()` **before** verifying server existence, project type enablement, source availability, compatibility drift, or fresh cache — burning rate-limit tokens on no-ops; (b) `WarmCatalogCacheCommand` eager-loads servers/eggs/variables, then invokes `BackgroundJob::execute(WARM_SEARCH)` which re-queries `Server::query()->find()` without eager loads (N+1 for up to 200 warm targets); (c) `minecraftVersionsFor()` (`:251`) hardcodes 4 env vars, ignoring custom `EggProfile::$minecraftVersionVariables`. | Move `$throttle->tryAcquire()` immediately before `$source->warmSearch()` (after all validation & fresh-cache check); eager-load `egg.variables` in `WarmCatalogSearch` (or pass preloaded `Server` directly from command); include all profile variable names from `EggProfileRegistry::all()` in `minecraftVersionsFor()`. |
| **15** | **MEDIUM** | Architecture Inconsistency / Bypassed Abstraction | `src/Filament/Server/Pages/ModManagerPage.php:2343-2348` | `performUninstall()` bypasses `WingsRemoteFilesystem` & `DaemonFileRepository` to call `Http::daemon($server->node)->post("/api/servers/{$server->uuid}/files/delete", ...)` directly, causing inconsistent retry/error handling and an extra lazy load of `$server->node`. | Route deletion through `app(WingsRemoteFilesystem::class)->delete($fileRepository, $server, $folder, $safeFilename)`. |
| **16** | **MEDIUM** | Static Asset Request Overhead | `src/Support/ModManagerAssets.php:42-64`, `src/Http/Controllers/ModManagerAssetController.php:20-56` | `ModManagerAssets::get()` computes `hash_file('sha256')` on every call with **no static memo** (5× disk reads + SHA-256 on every page render); `ModManagerAssetController` re-reads the file, re-hashes SHA-256 a **2nd time**, and runs **`gzencode(..., 9)`** *before* checking `$response->isNotModified($request)` (wasting CPU on every 304). | Memoize `ModManagerAssets::get()` per process (keyed by asset + `filemtime`); check `If-None-Match` (`isNotModified`) *before* `file_get_contents()` and `gzencode()`, and use compression level 6. |
| **17** | **MEDIUM** | Diagnostics Bugs & Slowness | `src/Services/ModManagerDiagnostics.php:30-36,74-87` | (a) Fetches `https://api.spiget.org/v2/resources/2` **twice** (`:32` and `:74`); (b) runs 7 upstream HTTP checks sequentially (up to 56s); (c) sends `UpstreamHttp::json()` (`Accept-Encoding: gzip, deflate`, `Content-Type: application/json`) on a binary `Range: bytes=0-3` ZIP probe to `cdn.spiget.org` (`:83-87`), where gzip breaks byte-range header probing; (d) omits Hangar and GitHub Releases checks. | Reuse resource 2 payload, run checks via `Http::pool()`, send `'Accept-Encoding' => 'identity'` for the Range probe (matching `CurseForgeManifestReader:112`), and add Hangar/GitHub checks. |
| **18** | **LOW** | Octane / Long-Lived Process Hazard | `src/Http/Middleware/ShiftCoreNavigationRows.php:36-58,159-184` | Mutates static `$page::navigationSort()` on core Filament classes and guards via `static::$applied = true`. Although `README.md:36-37` notes Octane isn't supported yet, this permanently mutates global class statics across requests/tests if reset isn't called. | Record original sorts before shifting (or reset on request termination) so multi-tenant state cannot bleed if runtime model evolves. |
| **19** | **LOW** | Dead Code & Unused Dependencies | `src/Support/CatalogTileColumns.php:54`, `src/Services/ModpackCatalog.php:290,388`, `src/Services/InstalledOperationManager.php:60,64`, `src/Filament/Components/ModpackCatalogTable.php:807-814` (after UI fix) | Dead methods/params verified with 0 callers: `CatalogTileColumns::normalizeView()`, `ModpackCatalog::searchOptions()`, `ModpackCatalog::versionOptions()`, unused `ConfigRepository $config` (`unset($config);`) in `InstalledOperationManager::__construct()`, and `ModpackCatalogTable::authorUrl()` (once author links are removed). | Remove dead methods/unused constructor param (checking any tests referencing them; note `ModManagerPage.php` is also a 5,843-line god-class worth incremental extraction of self-contained collaborators without touching UI behavior). |
| **20** | **LOW** | Silent Exception Swallowing | `src/Support/DatabaseBackgroundJobQueue.php:48,65,113,126,142,160,173` | Every DB error in `push()`, `hasPending()`, `claim()`, `ack()`, `retry()`, `release()`, `heartbeat()` is caught and silently ignored with zero logging (`report($exception)`). Operational failures (e.g., missing migration, lock wait timeout, disk full) become completely invisible. | Log/report unexpected DB exceptions (throttled or via `report()`). |
| **21** | **LOW** | Client SWR Cache Key & Maintainability | `resources/assets/table-swr-cache.js:305-316,361-379` & direct PHPStan (`316` pre-existing level-6 errors in `src/`) | `table-swr-cache.js` omits `catalogView` from `buildKey()`/`describeViewState()` (harmless during tile view since `getRows()` sees 0 `tr`s, but loses view-transition diagnostics and can collide if list↔tile caching evolves). Also, as noted in `worker_repo_sync`, CI PHPStan only analyzes `pelican/app`, while plugin `src/` has 316 pre-existing level-6 diagnostics — modified files should be PHPStan-clean. | Add `catalogView` to `table-swr-cache.js` `buildKey()`/`describeViewState()` and update comment at line 522; run targeted PHPStan on every modified plugin file. |

---

### 1.4 Existing Credits & License Format Summary (§6 Compliance)

#### Inspected Files
- **`LICENSE`**: GNU General Public License v3.0 (GPL-3.0, 35,149 B).
- **`plugin.json`** (lines 1–20):
  - `id`: `"pelican-mod-manager"`, `version`: `"0.2.0-pre.3"`, `author`: `"kazaminosuke"`, `namespace`: `"Kazaminosuke\\ModManager"`, `panels`: `["server", "admin"]`, `api_version`: `1`, `composer_packages`: `null`.
- **`README.md` (lines 172–187)**:
  ```markdown
  ## Credits & acknowledgements

  - Current maintainer: **kazaminosuke**
  - Original project / contributors: **Boy132**, **H1ghSyst3m**, and **Yonn**
  - UI implementation research/reference:
    [JoanFo1456/resources](https://github.com/JoanFo1456/resources) (GPL-3.0). Its Catalog UI and
    Filament structure were reviewed as a reference; no code was copied from that repository.

  ## Fork lineage & license

  This repository is a fork of
  [H1ghSyst3m/plugins](https://github.com/H1ghSyst3m/plugins/tree/featcomplete-mod-plugin-management),
  which forks [pelican-dev/plugins](https://github.com/pelican-dev/plugins).

  Licensed under the GNU General Public License v3.0 (GPL-3.0) - see [`LICENSE`](LICENSE).
  ```
- **`README.ja.md` (lines 163–180)**:
  ```markdown
  ## クレジット・謝辞

  - 現在のmaintainer: **kazaminosuke**
  - 元プロジェクト / contributors: **Boy132**、**H1ghSyst3m**、**Yonn**
  - UI実装の調査・参考:
    [JoanFo1456/resources](https://github.com/JoanFo1456/resources) (GPL-3.0)。Catalog UIと
    Filament構造の調査・参考に使用しましたが、このrepositoryからコードは流用していません。
  ```
- **`CHANGELOG.md` (lines 1–11)**: Bilingual `## [Unreleased]` with `### 日本語` and `### English` subsections.
- **Note on `README.md:85-98` / `README.ja.md:83-96` Settings table**: Also currently missing `MOD_MANAGER_MODRINTH_TOKEN` and `MOD_MANAGER_HANGAR_API_KEY` (can be updated alongside docs if desired).

#### Exact Style Rule for §6 Credit Entry (`cosmii02/pelican-modpackmanager`, MIT License)
Match the existing `JoanFo1456/resources` bullet format identically in **both** `README.md` (`## Credits & acknowledgements`) and `README.ja.md` (`## クレジット・謝辞`), stating:
- Project name + clickable GitHub URL (`[cosmii02/pelican-modpackmanager](https://github.com/cosmii02/pelican-modpackmanager)`)
- License + copyright notice per MIT (`MIT License`, `Copyright (c) 2026 cosmii02`)
- Exact scope of what was referenced/adopted (and if any code/logic was adapted vs design/workflow reference only, state that accurately; if substantial MIT code is adapted, include the MIT license text/notice as required).

---

## 2. Logic Chain

1. **UI Issue A (Primary)**: From §1.1, Filament 5.11.4's `index.blade.php:1397-1411` wraps `contentGrid` cards in `<a href="$recordUrl" class="fi-ta-record-content">`, while `CatalogTileColumns.php:115` attaches a state-based `$authorUrl` (`ModManagerPage.php:3189`, `ModpackCatalogTable.php:266,807-814`) returning `https://modrinth.com/user/{author}` exclusively for Modrinth. `TextColumn::toEmbeddedHtml()` (`TextColumn.php:345-353`) therefore nests `<a>` inside `<a>` on every Modrinth tile. Live headless Chrome DOM dumping proved Chrome's HTML5 Adoption Agency Algorithm breaks the outer `<a>` into 6 fragments, ejects the bottom `Split` from `.fi-ta-record-content`, and injects a `width: 100%` `<a class="fi-ta-record-content">` as child #1 of `fi-ta-split`, compressing `date_modified` to `17px × 120px` (1 char/line). List view escaped this solely because `index.blade.php:2575-2585` checks `(! $columnHasStateBasedUrls)` — which instead made non-Modrinth author cells non-clickable `<div>`s.
2. **UI Issue A (Secondary)**: From §1.1 Chrome measurements #3–#5, even after removing the nested `<a>`, `contentGrid` inherits `white-space: normal` (unlike `<table>`'s `whitespace-nowrap` at `table.css:211`), and Filament's `.fi-ta-col.fi-growable` sets `flex: 1 1 0%; width: 100%` without `min-width: 0`, while `downloads`/`date_modified` have `flex-shrink: 1`. Consequently, a long unbreakable author name refuses to shrink below `min-content` and crushes `date_modified` to `16px × 120px` (1 char/line!) on **any** provider, and even short authors (`CaffeineMC`, `robotkoer`) wrap `downloads`/`date_modified` onto 2 lines on 310px 3-col tiles. Because Filament's compiled `theme.css` does not expose arbitrary Tailwind utility classes (`min-w-0`, `whitespace-nowrap`, `break-all`), the cleanest strictly Filament-native solution (zero custom CSS, verified in Chrome #8 at 280px with 55-char author and German dates) is `Stack::make([author, Split::make([downloads, date_modified->alignEnd()])])->space(1)` inside the outer `Stack`.
3. **UI Issue B**: From §1.2, every catalog (`ModManagerPage` + subclasses + `ModpackCatalogTable`) initializes `$catalogView = CatalogTileColumns::resolveInitialView()`, which returns `'list'` on a clean session/cookie. Thus Modpack does not have a different clean default — rather, sharing ONE global session key + 1-year cookie across all catalogs and panels causes any prior tile-view toggle on any catalog (or modal toggle leaving parent `ModManagerPage` desynced in list mode, or `Installed` tab forcing list mode behind the Modpack modal) to open Modpack directly in tile view. Tracing Filament's `HasColumnManager.php:35-44,136-139` further revealed that mounting directly in tile view leaves `$this->tableColumns = []`, causing `toggleCatalogView()` (and `updatedActiveTab()` to `Installed`) to hide all toggleable columns when switching back to list view because `$this->initTableColumnManager()` is never called after rebuilding `$this->table`.
4. **Shared Infrastructure**: From §1.3, code inspection across `ModManagerPlugin`, `InstalledProjectService`, `InstalledOperationManager`, `SourceCache`, `UpstreamHttp`, all 8 `Sources/*`, `WingsRemoteFilesystem`, `InstalledArchiveTransaction`, `ResourcePackService`, `ProcessBackgroundJobsCommand`, `BackgroundJobRuntime`, `WarmCatalogSearch`, `ModManagerAssets`/`ModManagerAssetController`, and `ModManagerDiagnostics` established 21 concrete defects with exact `file:line` citations, prioritized by data-loss/corruption/concurrency/performance impact.

---

## 3. Caveats

1. **Modpack-Specific Engine Deep Dive Delegated to Peer Explorers**: Per the 3-way Step 0 Survey split, deep comparison of Modpack installation/provisioning/manifest/overrides/rollback internals against `cosmii02/pelican-modpackmanager (commit ddc2e364, MIT)` (`ModpackDeployment`, `ModpackInstaller`, `ModpackProvisioningPlanner`, `ModpackArchive`, `ModpackUpdateWorkflow`, `ModpackInventory`, `EggCompatibilityEngine`) is owned by the peer Modpack/Comparison explorers and intentionally not duplicated here (only shared interfaces/callsites like `ModpackCatalog`, `ModpackCatalogTable`, `ModpackCreationStep`, `InstalledOperationManager`, and `WingsRemoteFilesystem` were audited).
2. **UI Issue B Policy Choice (Global vs Per-Catalog Preference)**: Both options are valid under `ORIGINAL_REQUEST.md` §2 ("実際に初期表示や設定の扱いが異なる場合は、他のカタログと統一してください"):
   - **Recommended unification**: Keep all catalogs defaulting to `'list'`; centralize persistence + normalization in `CatalogTileColumns`; fix the `HasColumnManager` empty-`tableColumns` bug on tile→list and catalog→installed switches; sync `ModManagerPage` view state cleanly (or scope Modpack modal/wizard view preference separately from Mod/Plugin/ResourcePack/Datapack so toggling one catalog never unexpectedly flips Modpack Create Wizard into tile mode, OR keep shared preference AND sync live between parent/modal via Livewire event + `hydrate()`). The Planner/Orchestrator should lock this design decision explicitly in the plan before implementation!
3. **UI Issue A — Top `Split` Long Unbreakable Title**: In Chrome test #6, an unbreakable 52-char single-word `title` inside top `Split([ImageColumn, TextColumn('title')->lineClamp(1)])` also slightly overflows `310px` (`r=331`) because Filament's `.fi-ta-col.fi-growable` lacks `min-width: 0`. If we want zero CSS rules in `mod-manager.css`, that's a Filament upstream limitation on single-word titles >~35 chars; alternatively, a single 1-line standard flexbox rule `.mmr-table-scroll-ctn .fi-ta-split>.fi-growable{min-width:0;}` fixes unbreakable titles too without changing any colors/spacing/components — Planner should decide whether to stay 100% zero-CSS (pure `Stack`+`Split`) or include that 1-line flex safety rule.
4. **Live Pelican Panel Runtime Not Booted**: Chrome measurements used exact extracted Filament 5.11.4 HTML markup and compiled CSS rules from Composer cache (`filament/tables` `e172dbb`, `filament/filament` `715eeda`) rather than a running Pelican Panel HTTP server. All DOM parsing and flex geometry behaviors are standard browser specifications and directly reproducible.

---

## 4. Conclusion & Recommended Action Plan

### 4.1 Concrete Fix Strategy — UI Issue A (Modrinth Tile Display Collapse)
1. **Abolish Modrinth author page links everywhere** (preserve `->recordUrl(..., true)` project external links):
   - `src/Support/CatalogTileColumns.php`: Remove `$authorUrl` parameter (`:63,71`) and `->url($authorUrl, true)` (`:115`). Remove dead `normalizeView()` (`:54-57`) or wire it into `resolveInitialView()`/`toggleCatalogView()`.
   - `src/Filament/Server/Pages/ModManagerPage.php`: Remove `authorUrl:` arg (`:3189-3191`) and list-view `->url(...)` (`:3253`).
   - `src/Filament/Components/ModpackCatalogTable.php`: Remove `authorUrl:` arg (`:266`), list-view `->url(...)` (`:531`), and dead `authorUrl()` method (`:807-814`).
   - `resources/assets/table-swr-cache.js:522`: Update comment (keep `'a, .fi-ta-col'` for `recordUrl`).
2. **Fix Cause #2 (all-provider tile metadata alignment) strictly Filament-natively**:
   - In `src/Support/CatalogTileColumns.php:108-134`, replace the 3-item `Split` with:
     ```php
     Stack::make([
         TextColumn::make('author')
             ->label(trans('pelican-mod-manager::strings.table.columns.author'))
             ->icon('tabler-user')
             ->color('gray')
             ->size(TextSize::Small)
             ->lineClamp(1)
             ->toggleable(),
         Split::make([
             TextColumn::make('downloads')
                 ->label(trans('pelican-mod-manager::strings.table.columns.downloads'))
                 ->numeric()
                 ->icon('tabler-download')
                 ->color('gray')
                 ->size(TextSize::Small)
                 ->toggleable(),
             TextColumn::make('date_modified')
                 ->label(trans('pelican-mod-manager::strings.table.columns.date_modified'))
                 ->icon('tabler-calendar')
                 ->color('gray')
                 ->size(TextSize::Small)
                 ->grow(false)
                 ->alignEnd()
                 ->formatStateUsing($formatDate)
                 ->tooltip($dateTooltip)
                 ->toggleable(),
         ]),
     ])->space(1),
     ```
   - Zero custom CSS, keeps author below description with `tabler-user` icon, keeps all 3 `->toggleable()`, keeps `Stack`/`Split`/`contentGrid` Filament-native, eliminates wrapping/overflow on narrow 3-col tiles across EN/JA/DE and long unbreakable authors, and keeps existing `ModManagerBrowserAssetsTest:159-164` assertions green!
3. **Add regression tests**: Assert rendered Modrinth tile HTML in `ModpackCatalogTableTest` and `ModManagerPage` contains **zero** `modrinth.com/user/` links, **zero** nested `<a>` tags inside `<a ... class="fi-ta-record-content">`, and **retains** `href="https://modrinth.com/..."` project `recordUrl` links across all providers.

### 4.2 Concrete Fix Strategy — UI Issue B (Modpack Catalog Initial View Mode)
1. **Unify preference resolution & toggle helper in `CatalogTileColumns`**:
   - Add a shared helper (e.g., `CatalogTileColumns::storeView(string $view): string` using `normalizeView()`) called by both `ModManagerPage::toggleCatalogView()` and `ModpackCatalogTable::toggleCatalogView()`.
   - Make `ModpackCatalogTable::table()` use `->contentGrid(fn (): ?array => $this->catalogView === CatalogTileColumns::VIEW_PANEL ? CatalogTileColumns::contentGrid() : null)` and guard `if (isset($this->table))` in `toggleCatalogView()`, matching `ModManagerPage` identically.
   - Resolve the parent/modal desync: either dispatch a Livewire event / sync `$this->catalogView` in `hydrate()` when `session()` changed, OR scope preference per catalog context (note: if keeping shared global preference across all catalogs per existing design, ensure `ModManagerPage` stays synced when modal toggles, AND ensure clean default is explicitly tested as `'list'` across both `MODE_CREATE` and `MODE_SERVER`!).
2. **Fix the `HasColumnManager` empty-`tableColumns` bug on Tile→List & Tile→Installed transitions**:
   - After `$this->table = $this->table($this->makeTable());` in `ModManagerPage::toggleCatalogView()` (`:639`), `ModManagerPage::updatedActiveTab()` (`:1099`), and `ModpackCatalogTable::toggleCatalogView()` (`:193`), reset column manager cache and re-initialize when switching to list view:
     ```php
     if (!$this->getTable()->hasColumnsLayout()) {
         $this->cachedDefaultTableColumnState = null;
         $this->initTableColumnManager();
     }
     ```
3. **Add regression tests**:
   - Clean session/cookie initial mount of `ModpackCatalogTable` (`MODE_CREATE` & `MODE_SERVER`) and `ModManagerPage` asserts `catalogView === 'list'` and `fi-ta-table` (no `fi-ta-content-grid`).
   - Saved `'panel'` preference mount -> `toggleCatalogView()` back to `'list'` asserts all default-visible toggleable columns (`downloads`, `minecraft_versions`, `loaders` on Modpack; `author`, `downloads`, `date_modified` on ModManagerPage) remain visible in rendered HTML!
   - Update `resources/assets/table-swr-cache.js` (`buildKey` & `describeViewState`) to include `catalogView`.

### 4.3 Suggested Milestone Decomposition & File Ownership Boundaries (§8 Commit Separation)

To respect `ORIGINAL_REQUEST.md` §8 ("UI修正と内部実装の改善はコミットを分ける。大きな内部変更についても、変更目的ごとに適切にコミットを分ける。"), decompose implementation into cleanly isolated milestones/commits with non-overlapping file ownership:

| Milestone / Commit | Primary Scope | Owned Files |
|---|---|---|
| **M1 — UI Fixes (Issues A & B)** | Abolish Modrinth author links (keep `recordUrl`), Filament-native tile metadata `Stack+Split`, unify `catalogView` helpers/sync, fix `HasColumnManager` panel→list column-loss bug, add `catalogView` to SWR key + UI regression tests. | `src/Support/CatalogTileColumns.php`, `src/Filament/Components/ModpackCatalogTable.php`, `src/Filament/Server/Pages/ModManagerPage.php` *(UI table/view methods only)*, `resources/assets/table-swr-cache.js`, `tests/Unit/Filament/ModpackCatalogTableTest.php`, `tests/Unit/Filament/ModManagerBrowserAssetsTest.php`, new tile/view regression test |
| **M2 — Core Settings, Scan Safety & Operation Concurrency Fixes** | Fix `ModManagerPlugin::getSettingsFormData()` token wipe (#1), `InstalledProjectService::openDaemonFileStream()` missing `throw()` (#2), `InstalledOperationManager::operationStateOrActive()` missing `OPERATION_MODPACK` (#3), `BackgroundJobRuntime` progressBuffer reset (#11), `ResourcePackService` locking (#10), `performUninstall()` via `WingsRemoteFilesystem` (#15), parallel scan size/timeout/tempnam safety (#12). | `src/ModManagerPlugin.php`, `src/Services/InstalledProjectService.php`, `src/Services/InstalledOperationManager.php`, `src/Support/BackgroundJobRuntime.php`, `src/Services/ResourcePackService.php`, `src/Filament/Server/Pages/ModManagerPage.php` *(`performUninstall` only)*, `src/Support/DatabaseBackgroundJobQueue.php`, corresponding unit tests |
| **M3 — Shared HTTP, Caching, Wings I/O & Scheduler Efficiency** | `UpstreamHttp` `User-Agent`/gzip/retries + source HTTP unification (#5), `SourceCache` single-flight & `forgetFailureMarker()` fix (#4), source version `size` propagation & `InstalledArchiveTransaction` Wings round-trip/Range reduction (#6, #9), filter-option SWR & ATLauncher/FTB/Technic caching (#8), `ModrinthSource::requestProject()` trimming (#13), `WarmCatalogSearch`/`WarmCatalogCacheCommand` throttle/N+1 fixes (#14), `ModManagerAssets`/`ModManagerAssetController` ETag-before-gzip (#16), `ModManagerDiagnostics` dedup/pool (#17), dead code cleanup (#19), scheduler idle-query / worker-starvation improvement (#7). | `src/Support/UpstreamHttp.php`, `src/Support/SourceCache.php`, `src/Support/WingsRemoteFilesystem.php`, `src/Services/InstalledArchiveTransaction.php`, `src/Services/InstalledProjectUpdateService.php`, `src/Support/InstalledMetadataWriteSession.php`, `src/Sources/*`, `src/Jobs/WarmCatalogSearch.php`, `src/Console/Commands/WarmCatalogCacheCommand.php`, `src/Support/ModManagerAssets.php`, `src/Http/Controllers/ModManagerAssetController.php`, `src/Services/ModManagerDiagnostics.php`, `src/Providers/ModManagerServiceProvider.php`, corresponding unit tests |
| **M4 — Modpack Engine Improvements (from Peer Comparison Surveys)** | Modpack provisioning, archive/server-pack handling, compatibility, rollback/resume improvements identified by peer Modpack explorers. | `src/Services/Modpack*`, `src/Support/Modpacks/*`, `src/Support/Compatibility/*`, `src/Jobs/InstallModpack.php`, `src/Filament/Admin/ModpackCreationStep.php`, `src/Filament/Admin/QueueCreatedServerModpack.php`, modpack tests |
| **M5 — Documentation, Credits (§6) & Changelog** | Add `cosmii02/pelican-modpackmanager` (MIT) credits in `README.md` & `README.ja.md`, document settings/changes in `README*`, `CHANGELOG.md`, and audit docs. | `README.md`, `README.ja.md`, `CHANGELOG.md`, `docs/*` |

*(Note: M1 and M2 touch different sections of `ModManagerPage.php`, so run M1 and M2 sequentially or assign `ModManagerPage.php` cleanly to avoid merge conflicts.)*

---

## 5. Verification Method

An independent verifier can reproduce and validate every observation in this report without modifying any repository files:

1. **Verify exact repository cleanliness & HEAD**:
   ```bash
   git -C <repo-root> status --porcelain=v1   # expect only: ?? .agents/
   git -C <repo-root> rev-parse HEAD          # expect: 4e5f839a4d8ede672565b430995e5ee5655167dd
   ```
2. **Verify all UI Issue A & B code references**:
   ```bash
   grep -rn 'modrinth\.com/user\|authorUrl\|resolveInitialView\|mmr_catalog_view' \
     src \
     resources
   ```
3. **Reproduce Modrinth nested-`<a>` DOM shattering in headless Chrome**:
   Run `google-chrome --headless --no-sandbox --disable-gpu --dump-dom` on the Filament `contentGrid` card HTML with `<a class="fi-ta-record-content">...<a href="https://modrinth.com/user/jellysquid3">...</a>...</a>` and observe `splitChildren === 4` with a phantom `<a class="fi-ta-record-content"></a>` injected as the first flex child of `fi-ta-split` and `date_modified` compressed to `17px × 120px`.
4. **Verify Critical Core Bugs (#1, #2, #3, #4, #6) directly in source**:
   - Inspect `src/ModManagerPlugin.php:189-204` vs `251-262,367-368` (`modrinth_token` & `hangar_api_key` absent from `getSettingsFormData()`).
   - Inspect `src/Services/InstalledProjectService.php:1969-1978` (no `$response->throw()`).
   - Inspect `src/Services/InstalledOperationManager.php:606-634` (`OPERATION_MODPACK` absent from `operationStateOrActive()`).
   - Inspect `src/Support/SourceCache.php:431-441,756-768` (`LockTimeoutException` triggers `markFailure()`; `forgetFailureMarker()` gated on request-local `$memos['failures']`).
   - Inspect `src/Sources/CurseForgeSource.php:2081-2088`, `src/Sources/HangarSource.php:1370-1378`, `src/Sources/GitHubReleasesSource.php:727-735` (`'size'` absent from normalized `files[]`).
5. **Post-implementation CI verification commands** (in a scratch `pelican-dev/panel` checkout, excluding `.agents` on rsync):
   - `php .github/scripts/validatePluginJson.php`
   - `vendor/bin/phpunit --configuration plugins/pelican-mod-manager/phpunit.xml`
   - `vendor/bin/phpstan analyse --memory-limit=-1 --error-format=github` (CI scope) + targeted `vendor/bin/phpstan analyse <modified files> --autoload-file=plugins/pelican-mod-manager/tests/bootstrap.php`
   - `vendor/bin/pint --test --config=pint.json`
6. **Invalidation conditions**: Any commit modifying `CatalogTileColumns`, `ModManagerPage`, `ModpackCatalogTable`, `ModManagerPlugin`, or the audited `src/` infrastructure files, or an upstream Filament release altering `index.blade.php` `contentGrid` anchor rendering or `HasColumnManager::initTableColumnManager()`, would supersede the corresponding findings.
