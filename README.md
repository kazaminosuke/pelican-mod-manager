# Minecraft Mod Manager

*[日本語](README.ja.md)*

A [Pelican Panel](https://pelican.dev) plugin that lets you search, install, update, and manage Minecraft mods, plugins, and datapacks from **Modrinth, CurseForge, Hangar, and GitHub Releases** directly in the server panel.

![Catalog tab](docs/images/catalog.png)
![Installed tab](docs/images/installed.png)

## Supported sources

| Source | API key | Search | Hash matching | Project types |
|---|---|---|---|---|
| [Modrinth](https://modrinth.com) | Not required | ✅ | ✅ (`sha512`) | Mod, Plugin, Datapack |
| [CurseForge](https://www.curseforge.com/minecraft) | **Required** | ✅ | ✅ (`murmur2`) | Mod, Plugin, Datapack |
| [Hangar](https://hangar.papermc.io) | Not required | ✅ | ✅ (`sha256`) | Plugin |
| [GitHub Releases](https://github.com) | Optional, recommended | ❌ (tracks one `owner/repo` at a time) | ❌ | Mod, Plugin |

Modrinth is always available. CurseForge is available by default on Mod, Plugin, and Datapack pages once its API key is configured; add `curseforge_disabled` to an egg's features to disable it for that egg. Hangar and GitHub Releases are opt-in per egg - see [Egg configuration](#egg-configuration).
GitHub Releases works without a token, but its unauthenticated rate limit (60 requests/hour) is scarce enough that configuring one is recommended for direct repository tracking. GitHub Releases has no catalog cache-warming path.

## Requirements

- Pelican Panel (`main`, Filament 5.6+)
- PHP 8.3 - 8.5
- **An asynchronous queue worker.** Installed-file scans, bulk updates, and cache warming all run as queued jobs so Livewire requests stay responsive. Configure a real driver (for example `QUEUE_CONNECTION=database`) and keep a worker running:

  ```sh
  php artisan queue:work
  ```

  The `sync` and `null` drivers are intentionally rejected for scans/bulk updates - the plugin shows a queue-configuration warning instead of blocking the browser request on them.

## Installation

**Option 1: Direct URL** - paste this into the Pelican Panel plugin installer:

```txt
https://github.com/kazaminosuke/pelican-minecraft-modrinth/releases/latest/download/pelican-minecraft-modrinth.zip
```

**Option 2: Upload ZIP** - download the latest ZIP from the [Releases](https://github.com/kazaminosuke/pelican-minecraft-modrinth/releases) page and upload it in the plugin installer.

## Egg configuration

Add one of these **features** to the egg so the plugin knows what to manage:

- `mod_manager` - manages `mods/`
- `plugin_manager` - manages `plugins/`
- `datapack_manager` - manages `world/datapacks/` (can be combined with either of the above)

Also add the `minecraft` **tag**, plus a loader tag so version/loader-specific filtering works: `paper`, `purpur`, `folia`, `spigot`, `bukkit`, `fabric`, `quilt`, `forge`, `neoforge`, `sponge`, `velocity`, `waterfall`, or `bungeecord`.

To enable GitHub Releases tracking, add its feature flag:

```json
{ "features": ["mod_manager", "github_releases"], "tags": ["minecraft", "fabric"] }
```

`github_releases` enables the **Track GitHub Repository** action: GitHub Releases has no browseable catalog, so enter an `owner/repo` there to track its latest release. Once a CurseForge API key is configured, every catalog type enables CurseForge by default. Hangar is enabled by default on Plugin catalogs. Add `curseforge_disabled` or `hangar_disabled` to an egg's features or tags to hide that source; these opt-outs take precedence over the defaults.

**Automatic egg detection** (see [How it works](#how-it-works)) means most official Minecraft eggs don't need any of the above set manually - explicit `features`/`tags` still always win when present.
One consequence: datapack management now **defaults to on** for any recognized Java server egg (mod/plugin/hybrid/vanilla/modpack), even without a `datapack_manager` feature. Add `datapack_manager_disabled` to an egg's features to opt back out, or set `MOD_MANAGER_EGG_AUTODETECT=false` to fully restore the pre-autodetect behaviour where `datapack_manager` must be explicit.

## Settings

The plugin settings screen (panel admin → Plugins) has these fields, each backed by a global `.env`
key unless noted otherwise:

| Field | `.env` key |
|---|---|
| Latest Minecraft version | `LATEST_MINECRAFT_VERSION` |
| Mod navigation sort order | `MINECRAFT_MODRINTH_MOD_NAV_SORT` |
| Plugin navigation sort order | `MINECRAFT_MODRINTH_PLUGIN_NAV_SORT` |
| Datapack navigation sort order | `MINECRAFT_MODRINTH_DATAPACK_NAV_SORT` |
| CurseForge API key | `CURSEFORGE_API_KEY` |
| GitHub token | `GITHUB_TOKEN` |
| Allow non-admins to edit egg profiles | `MOD_MANAGER_ALLOW_USER_EGG_PROFILE_EDIT` (default off) |
| Allow server users to install projects | `MOD_MANAGER_ALLOW_USER_PROJECT_INSTALL` (default off) |
| Allow server users to update projects, including bulk update | `MOD_MANAGER_ALLOW_USER_PROJECT_UPDATE` (default off) |
| Allow server users to remove projects | `MOD_MANAGER_ALLOW_USER_PROJECT_DELETE` (default off) |

"Latest Minecraft version" is the fallback used when a server has no `MINECRAFT_VERSION`/`MC_VERSION`
startup variable of its own. "Allow non-admins to edit egg profiles" only extends editing to users who
can already manage the server in question (owners, admins, or subusers with the `startup.update`
permission) - it never opens the form to every user; see
[`docs/architecture.md`](docs/architecture.md) for the full permission logic. **Note:** this
permission logic currently has no automated test coverage - it has been verified by manual testing
only; re-verify by hand after touching it.

Project writes are separately protected. Root Admins are always allowed; an administrator can also
grant a Role the **Minecraft Mod Manager: Create**, **Update**, or **Delete** permission for one
operation at a time. The three toggles above are off by default. Enabling one additionally permits
ordinary server users who have the matching native file permission: `FileCreate` for installation,
both `FileCreate` and `FileDelete` for an update (including bulk update), or `FileDelete` for
removal. The UI and its Livewire actions enforce the same decision; see
[`docs/architecture.md`](docs/architecture.md).

The same screen also has an **Egg profiles** action for manually setting a project type, loader, MC
version, and datapack support on eggs that automatic detection couldn't resolve.

The same screen has a **Clear cache** action, which behaves differently by scope:

- **All servers** - clears every server's tracked-file metadata and the shared caches, but does
  **not** force an immediate re-scan; each server re-scans lazily the next time an applicable
  Mod/Plugin/Datapack manager page is opened (on either a catalog or Installed tab).
- **A single server** - clears that server's metadata and immediately queues a forced re-scan
  (needs a working queue - see [Requirements](#requirements)).

## How it works

- **Local metadata index** (`.pelican-mod-manager.json` on each server) tracks which installed
  file maps to which upstream project.
- **Incremental hash scanning** re-hashes a file only when its size/modified-time signature has
  changed, instead of every file on every scan.
- **Background jobs and status badges** handle scans and bulk updates without blocking the UI:
  scans show progress and a brief completion outcome only while the Installed tab is open, while
  bulk-update progress remains inline.
- **A stale-while-revalidate cache** sits in front of every upstream API call, with a freshness
  policy per data type, plus **scheduled warm jobs** that pre-populate it for the loader/Minecraft
  version/project-type combinations actually in use, throttled separately from user traffic.
- **Conditional deferred loading** skips the extra loading round trip entirely when a view's data
  is already cached.
- **Automatic egg detection** recognizes the panel's official Minecraft eggs (and their
  Pterodactyl-ecosystem equivalents) without manual egg editing - explicit `features`/`tags` always
  win over it, and an egg it can't fully place shows a short setup prompt instead of nothing. Set
  `MOD_MANAGER_EGG_AUTODETECT=false` to disable it.

See [`docs/architecture.md`](docs/architecture.md) for the full design behind each of these,
including the detection order and how to configure an egg manually.

## Troubleshooting

- **"An asynchronous queue worker is required" warning** - see [Requirements](#requirements).
- **A row shows "Not tracked"** - a file exists in the mod/plugin/datapack folder that isn't (yet)
  recorded in the metadata index. Use the Rescan action.
- **The CurseForge tab is not shown** - configure a CurseForge API key in [Settings](#settings), and ensure
  the egg does not include the `curseforge_disabled` feature.
- **Catalog data looks stale** - use the settings screen's Clear cache action; see above for the
  all-servers/single-server difference.

## Repository

<https://github.com/kazaminosuke/pelican-minecraft-modrinth>

## Fork lineage & license

This repository is a fork of
[H1ghSyst3m/plugins](https://github.com/H1ghSyst3m/plugins/tree/featcomplete-mod-plugin-management),
which forks [pelican-dev/plugins](https://github.com/pelican-dev/plugins).

Licensed under the GNU General Public License v3.0 (GPL-3.0) - see [`LICENSE`](LICENSE).

## For developers

- [`docs/architecture.md`](docs/architecture.md) - cache layers, metadata format, and how to add a
  new source.
- [Issues](https://github.com/kazaminosuke/pelican-minecraft-modrinth/issues) /
  [Pull requests](https://github.com/kazaminosuke/pelican-minecraft-modrinth/pulls)
