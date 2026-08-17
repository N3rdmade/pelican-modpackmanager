# Pelican Modpack Manager — Developer Notes

## 1.6.9

### Automatic loader and egg handling

- Added end-to-end automatic support for **Forge, NeoForge, Fabric and Quilt** modpacks.
- The plugin now finds the canonical Pelican loader egg by its official UUID/update URL and, when it is missing, downloads and imports the current Pelican-native egg directly from `pelican-eggs/minecraft`.
- Removed the bundled plugin-maintained Forge/Fabric/NeoForge egg copies and the old fuzzy/custom egg fallback, so installs no longer create duplicate or branded loader eggs.
- Added Quilt to automatic egg switching. Packs that pin a Quilt Loader version keep the official Pelican Quilt egg while its install script is prepared to honor the requested loader version.
- Egg changes now use Pelican's `EggChangerService`, replacing stale variables from the previous loader instead of accumulating Forge/Fabric/NeoForge values on one server.
- When the server is already on the correct egg, loader/Minecraft variables are still refreshed for the selected pack/version.
- Loader reinstall scripts are forced back on when `skip_scripts` was previously enabled.
- Before reinstall, the server startup command and Docker image are reset to the selected official egg's defaults, then the correct Java image is selected for the Minecraft version.
- Unlimited-memory servers no longer produce `-Xmx0M`; affected egg startup commands are changed to container-aware `MaxRAMPercentage` memory sizing.
- The installer now waits for Pelican/Wings to finish the loader reinstall, times out clearly after 15 minutes, and verifies the expected runtime files before marking the install ready.
- Runtime verification covers Fabric/Quilt `server.jar`, Quilt launcher properties, legacy Forge `server.jar`, and modern Forge/NeoForge `unix_args.txt`.
- Added exact Forge vs NeoForge version handling, including Forge's required `<minecraft>-<build>` format and NeoForge's build-only variable format.
- CurseForge's selected loader is carried into install planning so ambiguous 1.20.1 packs tagged for multiple loaders prefer the loader the user actually selected.
- Fabric now keeps the official installer version on `latest` while allowing the pack's declared Fabric Loader version to be applied to `LOADER_VERSION`.

### Server-pack launcher handling

- Expanded launcher detection beyond `start.sh`/`run.sh` to understand `startserver.sh`, Forge/NeoForge `unix_args.txt` paths, Fabric launch jars, Quilt launch jars and version variables embedded in shell launchers.
- ServerPackCreator packs continue to use their own launcher, with `WAIT_FOR_USER_INPUT=false`, `RESTART=false` and container-aware JVM arguments applied automatically.
- Forge/NeoForge server packs that already contain a valid runtime use their bundled launcher without an unnecessary reinstall.
- Installer-based packs such as All The Mods can be detected from their launcher/installer metadata, routed through the correct official egg, then switched back to a verified pack launcher after the runtime is installed.
- Stale launchers and loader metadata are cleared before a new pack is extracted, including shell/batch/PowerShell launchers, `server.jar`, Fabric/Quilt launch jars, `unix_args.txt`, manifests and installer jars.
- Stale loader runtimes such as `libraries`, `versions`, `.fabric`, `.fabric-installer`, `.quilt` and `.quilt-installer` are also removed before a new cross-pack install.

### Safer pack switching and server settings

- Installing a **different** modpack now defaults to a clean server-file wipe; reinstalling/updating the **same** pack keeps the wipe option off by default.
- The clean-install path removes the previous pack's root contents instead of only a short hard-coded directory list, while explicitly preserving detected Minecraft worlds unless the separate world-delete option is enabled.
- Existing world folders are detected by `level.dat`, preventing unrelated world data from being removed during a normal clean pack switch.
- `Delete existing world` is now safe when there is no `server.properties`, no `level-name`, or no existing world; it reports that there is nothing to delete and continues instead of failing the install.
- `server.properties` is no longer restored wholesale. The installer saves it separately and restores only operational server preferences such as networking, MOTD, player limits, whitelist, difficulty, gameplay and view/simulation settings.
- Pack-owned world-generation settings are intentionally left to the new pack: seeds, `level-type`, generator settings, datapack selection and similar worldgen values are not copied from the previous modpack.
- When the existing world is deliberately kept, only its `level-name` is carried forward so the server still opens the intended world without importing old generation settings.
- Preserved player/admin files remain protected (`eula.txt`, ops, whitelist, bans and user cache).
- Install progress was reordered and renamed to match what actually happens: **Save Server Settings → Create Backup → Delete Old Files → Download Modpack → Extract Files → Assemble Modpack → Restore Server Settings → Finalize Installation → Configure Startup / Loader**.
- The install job timeout was increased from 30 to 60 minutes for large packs, backups, runtime installs and verification.

### Extracted-file permission repair

- Added a Wings-native post-extraction permission repair for archives that contain mode-`000` files/directories.
- Only completely unusable permission entries are changed: files become `0644`, directories become `0755`, while all valid/executable modes are left untouched.
- Permission fixes use Pelican's daemon `chmodFiles()` API, so the feature works when Panel and Wings are on different machines and does not depend on local `/var/lib/pelican` paths.
- This fixes packs that previously extracted successfully but crashed at startup with `AccessDeniedException` when mods attempted to read/write configuration files.

### Provider/install reliability

- Required CurseForge files now fail the install with the actual missing-file error instead of being silently skipped.
- Required server-supported Modrinth entries with no path/download URL now fail visibly instead of producing an incomplete pack.
- Required FTB files that cannot be resolved now fail visibly instead of being skipped.
- ATLauncher now rejects required server files that are missing a filename or download URL.
- Modrinth search now filters out client-only projects by requiring a usable server-side support state.
- Modrinth version selection is strict when a loader/version filter is active; it no longer silently falls back to releases for a different loader or Minecraft version.
- CurseForge version selection is likewise strict and no longer falls back to an unfiltered release list when the requested filters return nothing.

### Modpack browser

- **All Sources** now searches CurseForge, Modrinth, FTB and ATLauncher together instead of limiting the combined view to only CurseForge/Modrinth.
- Added real pagination with **Load 20 more** instead of stopping permanently at the first 20 cards.
- Added multi-select Forge/NeoForge/Fabric/Quilt loader filters and fixed multi-loader paging/merging so selected loaders do not lose results between pages.
- Added Minecraft-version, provider-supported category and sort filters (`Popular`, `Recently updated`, `Name A-Z`).
- Active filters are displayed as removable chips and can be cleared individually.
- Providers that cannot support a selected filter are skipped instead of returning misleading results.
- A provider can fail or be rate-limited without breaking the entire All Sources page; results from the remaining providers stay visible with a compact warning and **Retry** action for the failed source.
- Modpack cards now have **Description**, **Gallery** and **Open** actions in addition to Install.
- Description/gallery data is fetched when needed rather than bloating the initial card list.
- Gallery screenshots open in a large native in-page dialog, never a new tab/window. The preview can be closed by clicking the backdrop, pressing Escape or using the red circular close button.
- Added a final install confirmation screen showing the selected pack/provider/version/loader plus backup, wipe-files, delete-world and backup-deletion choices before the queued job starts.
- A different-pack install defaults to **Wipe existing server files** on, while same-pack updates/reinstalls leave it off.
- After a successful install, the plugin best-effort copies the modpack artwork into Pelican's native server icon storage when the provider supplies a valid PNG/JPEG/WebP image. Artwork failure never fails the modpack installation.

### Mods / Plugins browser

- Brought the Mods/Plugins browser up to the same card experience with **Description**, **Gallery**, **Open** and **Load 20 more**.
- Added removable active-filter chips and partial provider-failure/retry handling to Mods/Plugins searches.
- Added multi-select mod-loader filters for Forge, NeoForge, Fabric and Quilt, plus multi-select Modrinth plugin-platform filters where supported.
- The server's detected Minecraft version remains the single selector at the top of the page; the duplicate Minecraft-version control inside Filters was removed.
- Added cross-provider multi-select mod category pills for **Performance, Cosmetics, Creatures, Technology, Magic, World Gen, Storage, Utility / QoL, Food and Equipment**.
- Category selections are OR-ed with each other while still combining with Minecraft version, loader(s) and search text.
- CurseForge category IDs are resolved from its live category list and cached instead of hard-coding the IDs used by the Mods page.
- Modrinth category pills map to the corresponding Modrinth category slugs.
- CurseForge individual-mod searches now support multiple selected loaders and categories while deduplicating/merging the result pages.
- Individual Modrinth links now point to the correct `/mod/` or `/plugin/` project page instead of the modpack URL path.
- Modrinth plugin results now recognize Bukkit, Spigot, Paper, Purpur, Folia and Sponge platform tags.

## Installation

1. Copy this folder to `/var/www/pelican/plugins/modpack-manager/`
2. Run: `php artisan p:plugin:install modpack-manager`
   (or click **Install** in Admin Panel → Plugins — both run migrations automatically)
3. Configure API keys: Admin Panel → Plugins → Modpack Manager Settings

## Requirements

| Requirement | Version |
|-------------|---------|
| Pelican Panel | ^1.0 |
| PHP | ^8.2 |
| Queue worker | Required (installation runs as a background job) |

Make sure a queue worker is running. The worker timeout must be ≥ the job
timeout (3600s), since building a pack downloads many mods:
```bash
php artisan queue:work --tries=1 --timeout=3660
```

## API Keys

| Key | Where to get it | Required? |
|-----|-----------------|-----------|
| CurseForge | https://console.curseforge.com → API Keys | Yes (for CurseForge) |
| Modrinth PAT | https://modrinth.com/settings/pat | No (public search works without it) |

Keys are stored in the panel's `.env` file as:
```
MODPACK_MANAGER_CURSEFORGE_API_KEY=...
MODPACK_MANAGER_MODRINTH_TOKEN=...   (optional)
```

## Wings / Daemon Integration Notes

The installation service (`ModpackInstallService`) uses Pelican's
`App\Repositories\Daemon\DaemonFileRepository`. Wings does all the file work —
the panel never downloads the modpack itself.

Methods used (Pelican 1.x signatures):
- `setServer(Server $server)`
- `getContent(string $path, ?int $notLargerThan = null): string`
- `putContent(string $path, string $content)`
- `getDirectory(string $path): array` — entries have `name`, `size`, `file`, …
- `compressFiles(?string $root, array $files, ?string $name, ?string $extension): array`
- `decompressFile(?string $root, string $file)`
- `deleteFiles(string $root, array $files)`
- `renameFiles(string $root, array $files)` — `$files = [['from' => …, 'to' => …]]`
- `pull(string $url, ?string $directory, array $params)` — `$params` keys:
  `filename`, `use_header`, `foreground`. Wings fetches the URL directly onto
  the server.

### Download flow
`pull()` is called with `foreground => false`, then the service polls
`getDirectory('/')` until the archive size stops growing (the panel→Wings call
would otherwise time out on large packs). The archive is always saved as
`modpack-download.zip` so Wings' `decompressFile()` recognises it.

## Install strategy (server pack vs build)

**CurseForge:**
1. When a file is selected, the installer fetches its metadata. If the file
   `isServerPack`, or it links to one via `serverPackFileId`, that **official
   server pack** is downloaded and extracted directly. This is the happy path
   for most large packs (ATM, BMC, FTB, …).
2. If no server pack exists, the installer **builds one** from the client pack:
   it reads `manifest.json`, batch-resolves every mod's download URL
   (`POST /mods/files`, with a forgecdn fallback for null URLs), and has Wings
   `pull()` each file, then merges `overrides/`. Files are **routed by project
   type** — the installer batch-fetches each project's `classId` (`POST /mods`)
   and sends resource packs → `resourcepacks/`, shaders → `shaderpacks/`, data
   packs → `datapacks/`, and everything else (mods + unknown) → `mods/`
   (`folderForCurseClass()`). If the type lookup fails it falls back to `/mods`.

**Modrinth:** a `.mrpack` only contains `modrinth.index.json` + `overrides/`,
so the installer always parses the index and downloads each file whose
`env.server` is not `unsupported` to its declared `path`, then merges overrides.

**FTB (modpacks.ch):** keyless. There is no archive — the version endpoint
(`/public/modpack/{pack}/{version}`) returns a file list. The installer skips
the download/extract steps and pulls each non-`clientonly` file to its declared
`path`. ~80% of files carry a direct FTB-hosted `url`; the rest only have a
CurseForge `{project,file}` reference, which is resolved in one batch via
`CurseForgeService` (so a complete FTB install needs the CurseForge API key —
if a required reference cannot be resolved, the install fails clearly instead of silently skipping it). Loader / Minecraft / **Java** version come
straight from the version's `targets` array.

**ATLauncher:** keyless (the API needs a browser User-Agent to clear Cloudflare).
The install manifest is the CDN `Configs.json`; every mod jar is re-hosted on
ATLauncher's CDN, so even CurseForge-sourced mods download **without** the CF
key. The installer pulls each `server`-side, non-`library`, `download != browser`
mod to `mods/`, then downloads + extracts the pack's `Configs.zip` bundle.
Loader (type + version), Minecraft and Java (`java.min`) come from the manifest.

### Startup / loader auto-configuration (`stepConfigureLoader`)

After the files are in place, the installer makes the server actually launchable.
There are **two paths**, because modpacks ship in two very different shapes.

#### A) Self-contained server packs (`configureSelfContainedPack`)

**This path runs only for CurseForge `server_pack` mode.** Every other mode (modrinth,
curseforge_build, ftb, atlauncher) is assembled by us and ships no launcher, so it skips
straight to path B.

Stale launcher files are the recurring trap here — a `start.sh`/`variables.txt`/`run.sh`
left by a *previous* install gets mistaken for the current pack's launcher (e.g. a Modrinth
Fabric pack, or an **ATM10/NeoForge** install, reading a stale **ATM9/Forge**
`variables.txt` and keeping the Forge egg). The defense:

1. `stepDeleteFiles` **always** deletes `start.sh`/`run.sh`/`variables.txt`/`user_jvm_args.txt`,
   the loader-metadata files `manifest.json`/`modrinth.index.json`, and stale `*-installer.jar`s
   first — even when "Delete existing files" is off — since a new pack ships its own. This runs
   BEFORE the pack is downloaded, so by the time `configureSelfContainedPack` / `detectLoader`
   run, any such file on disk was extracted from THIS pack.

   The `manifest.json`/`modrinth.index.json` clear is what fixes **installing over a leftover
   pack without "Delete existing files"**: `detectLoader()` reads `/manifest.json`, so a stale
   one from a previous install reports the WRONG loader. Real example — Forge 1.20.1
   DeceasedCraft installed over a leftover NeoForge 1.21.1 manifest was detected as
   "neoforge 21.1.221" and stayed on the NeoForge egg, even though its own
   `forge-1.20.1-47.4.0-installer.jar` (via `detectInstallerJarMeta()`) had already set the
   plan loader to `forge`.

Because of that ordering, the on-disk launcher is **authoritative** for the loader. It is
NOT cross-checked against the CurseForge `gameVersions` tag (`plan['loader']`) any more: that
tag is the weaker signal, and for **Minecraft 1.20.1** — the one version where Forge and
NeoForge coexist — packs are routinely tagged "NeoForge" (or both) even when they're Forge.
The old cross-check discarded a Forge pack's real launcher on that mismatch and fell to path
B, which (having no `manifest.json` for a server pack) followed the mistagged CF loader and
installed a **Forge pack on the NeoForge egg**. Now `configureSelfContainedPack` just logs the
discrepancy and trusts the launcher's loader.

There are **three** server-pack shapes, tried in order:

- **A1. ServerPackCreator** (`start.sh` + `variables.txt`) — bundled launcher, used as-is.
- **A2. MDK `run.sh`** — bundled launcher, used as-is.
- **A3. Installer-based** (`detectInstallerJarMeta()`): no run-ready launcher, just a
  `neoforge-<ver>-installer.jar` / `forge-<mc>-<ver>-installer.jar` (+ a `startserver.sh`
  that runs it on first boot). AllTheMods packs (ATM10 ships `neoforge-21.1.228-installer.jar`
  + `startserver.sh`) are this shape. We read the **exact** loader version from the jar
  filename and route to path B so the matching egg installs precisely that version on top of
  the pack's mods. The detector is passed the plan's loader and **prefers the installer jar
  matching it**, so a stale `forge-…-installer.jar` from a previous ATM9 install can't be
  picked over ATM10's neoforge one. `stepDeleteFiles` also clears stale
  `(neoforge|forge|fabric|quilt)-*installer.jar` files on every install for the same reason. (The NeoForge egg's install script only `rm -rf`s the old
  `libraries/net/neoforged/<artifact>` + installer — it never touches `mods/`/`config/`, so
  the pack survives the reinstall.) We deliberately do **not** drive `startserver.sh`
  directly: it has its own 10s auto-restart loop and pack-specific env vars that fight
  Pelican, whereas the egg integrates cleanly.

Most official CurseForge "server pack" downloads are produced by
**ServerPackCreator** (Griefed) and ship their own launcher — `start.sh` +
`variables.txt` (and a `run.sh`, multiple loader installers, `user_jvm_args.txt`,
etc.). These install **and** launch the loader themselves via the ServerStarterJar.
For these we must NOT switch eggs or reinstall (that would fight/clobber the pack);
instead we point the server's startup command at the bundled launcher:

- **ServerPackCreator** (`start.sh` + `variables.txt`): startup → `bash start.sh`.
  `variables.txt` is the authoritative source of `MINECRAFT_VERSION`, `MODLOADER`,
  `MODLOADER_VERSION`, `RECOMMENDED_JAVA_VERSION`. We patch it for Pelican:
  `WAIT_FOR_USER_INPUT=false` (else it hangs the non-interactive console),
  `RESTART=false` (Pelican manages restarts), and
  `JAVA_ARGS="-Xms128M -XX:MaxRAMPercentage=92.5"` so the JVM sizes its heap from
  the container's assigned RAM.
- **Forge/NeoForge MDK pack** (`run.sh`, no `variables.txt`): startup →
  `bash run.sh nogui`; loader/version parsed from the `@libraries/net/.../unix_args.txt`
  path; `user_jvm_args.txt` rewritten to the same `MaxRAMPercentage` args.

Both self-contained shapes also **switch the server to the matching loader egg** (so the
panel shows the right egg + Java image list) without reinstalling. The loader/MC come from
the launcher script (`variables.txt` MODLOADER / the `unix_args.txt` path); if that yields
nothing they fall back to the loader/MC carried on the plan (parsed from the CurseForge
file's `gameVersions`, e.g. `["1.20.1","NeoForge"]` via `cfLoaderMeta()`). This is what
fixed NeoForge official server packs landing on the Forge egg — a downloaded server pack
has no `manifest.json`, so the plan-carried loader is the authoritative source.

The docker image is set to `java_<N>` (preferring one the current egg already allows,
else `ghcr.io/parkervcp/yolks:java_<N>`) from `RECOMMENDED_JAVA_VERSION` or
`javaForMc()`. **No egg switch, no reinstall** — startup command + image are written
straight to the server model. The user just presses Start.

#### B) Assembled packs — loader egg + reinstall (`configureLoaderEggAndReinstall`)

When WE built the server from a CurseForge *client* manifest or a Modrinth `.mrpack`
(only `mods/` + overrides, no launcher), there's nothing to run yet, so:

1. **Detect** loader + versions from `manifest.json`
   (`minecraft.modLoaders[0].id` like `forge-47.2.0`) or `modrinth.index.json`
   `dependencies`.
2. **Use the canonical Pelican loader egg** from `pelican-eggs/minecraft`.
   The plugin identifies an already-installed official egg by its Pelican UUID/update URL;
   if it is absent, it downloads the current upstream Fabric/Forge/NeoForge/Quilt export
   and imports that exact Pelican-native egg. If the official egg cannot be obtained, the
   install fails clearly instead of falling back to a plugin-maintained/custom loader egg.
   Quilt keeps the official egg identity and gains only an optional
   `QUILT_LOADER_VERSION` variable/install-script branch so a pack can pin its declared
   Quilt Loader version without creating a duplicate Quilt egg.

3. **Switch egg + set vars** via Pelican's canonical `EggChangerService::handle(
   $server, $egg, keepOldVariables: false)`. This is important: it switches `egg_id`
   (and the default image/startup) **and deletes the old server variables** before
   recreating the new egg's set. The earlier `StartupModificationService` path only
   *upserted* variables — it never removed the previous egg's, so installing
   Fabric→Forge→NeoForge on one server piled `FABRIC_VERSION`/`FORGE_VERSION`/
   `NEOFORGE_VERSION` all onto it and (under non-admin validation) often didn't switch
   the egg at all. After the switch, `applyLoaderVariables()` overrides `MC_VERSION`
   (or `MINECRAFT_VERSION`) and the loader-version var (`NEOFORGE_VERSION`/
   `FORGE_VERSION`/`BUILD_VERSION` / `LOADER_VERSION`…) directly on the new
   `ServerVariable` rows. Java image chosen by `javaForMc()` (1.20.5+/1.21 → 21,
   1.17–1.20.4 → 17, ≤1.16 → 8).

   **Forge vs NeoForge version format** — the two eggs disagree on what their version
   variable expects, and getting it wrong silently breaks the install:
   - **NeoForge** wants just the build number (`21.1.221`) in `NEOFORGE_VERSION` — the
     Maven path is `.../neoforged/neoforge/21.1.221/`.
   - **Forge** wants the *full* `<mc>-<build>` string (`1.20.1-47.4.0`) in `FORGE_VERSION`
     — the egg downloads `.../minecraftforge/forge/${FORGE_VERSION}/forge-${FORGE_VERSION}-installer.jar`.

     We carry the Forge **build** number (`47.4.0`, from the installer-jar filename / manifest
     `modLoaders[0].id` / modrinth deps) and the MC version separately, so
     `applyLoaderVariables()` stitches them into `<mc>-<build>` for `forge` only. Without it the
     installer URL 404s, Forge never installs, and the server dies on start with
     `Unable to access jarfile server.jar` (the egg's startup falls back to `-jar server.jar`
     when no `unix_args.txt` was produced).
4. **Reinstall** via `ReinstallServerService::handle($server)` so the egg installs
   the loader server.

Loader configuration is now a required install step. If the pack's loader cannot be determined,
the official Pelican egg cannot be obtained, the reinstall fails, or the expected runtime is not
created, the install fails with a clear startup/loader error instead of reporting success for an
unlaunchable server.

Very large packs (hundreds of mods) can take several minutes; the download
wait loop polls Wings and stops early if progress stalls.

## Scheduled update checks

A scheduled task checks every server's installed modpack against its provider
for a newer version and notifies the **server owner** (panel bell) the first
time a new version appears.

- **Command:** `php artisan modpack-manager:check-updates`
  (`--no-notify` records the result without notifying — handy for testing).
- **Schedule:** registered via `callAfterResolving(Schedule::class, …)` in
  `ModpackManagerServiceProvider`, driven by the panel's existing
  `php artisan schedule:run` cron entry. Frequency comes from
  `config('modpack-manager.update_check_frequency')` (`hourly`,
  `every_six_hours`, `twice_daily`, `daily` (default), `weekly`). Disable
  entirely with `MODPACK_MANAGER_UPDATE_CHECKS=false`.
- **Logic (`UpdateCheckService`):** picks the newest `installed` record per
  server, resolves the latest version label using the *same* CurseForge/Modrinth
  calls as the in-panel banner, and compares it to the stored `modpack_version`.
  Results persist on `modpack_installs` (`latest_version`, `update_available`,
  `update_checked_at`). It notifies **once per version** — `update_notified_version`
  records the version last announced so repeated runs don't re-spam the owner.
- A provider error returns `null` (no false "update available").

## Egg-tag visibility filter

The Modpacks page only shows on servers whose **egg** carries one of the
configured tags (matched case-insensitively against `Egg::$tags`). Default is
`minecraft`. Admins edit the list in **Plugin settings → Server visibility**
(a `TagsInput`); it persists to `MODPACK_MANAGER_EGG_TAGS` (comma-separated) and
is parsed into `config('modpack-manager.required_egg_tags')`. An **empty** list
disables the filter (page shows on every server). Enforced in `canAccess()`
(`eggHasAllowedTag()`), so it hides the page *and* its nav entry. Note: on a
`config:cache`d panel the new value takes effect after the config cache is
refreshed (same as the API-key settings).

## File-backed installed-pack metadata (`store_metadata`)

By default the "currently installed" pack is tracked **only** in the
`modpack_installs` table, so the banner + update tracking are lost if those
records disappear (e.g. a plugin re-install dropped the table on older versions —
the reason the manual *Link existing* button exists).

When the admin enables **Plugin settings → Installed-pack tracking** (the
`store_metadata` toggle, persisted to `MODPACK_MANAGER_STORE_METADATA=true|false`,
parsed into `config('modpack-manager.store_metadata')`, **off by default**):

- **Write** — after a successful install, `stepFinalize()` calls
  `writeInstalledMetadata()` which puts `ModpackInstall::toMetadata()` (provider,
  modpack id, name, version, icon, `installed_at`) as pretty JSON to
  `ModpackInstall::METADATA_FILE` = `/.modpack-manager.json` in the server root.
  `linkInstalled()` mirrors the same write so a manual link also persists to disk.
  Best-effort: a daemon/write failure logs but never fails the install.
- **Read** — `ModpackBrowserPage::mount()`, only when no usable DB record was
  found and the toggle is on, calls `loadInstalledFromMetadata()` which reads the
  file via `DaemonFileRepository`, decodes it, and builds a **transient (unsaved)**
  `ModpackInstall::fromMetadata()` to drive the banner + `computeUpdateState()`.
- The dotfile lives in the server root and isn't in any cleanup list, so it
  survives reinstalls and is simply overwritten by the next install.

## Permission gate

The Modpacks page is gated on a Pelican subuser permission so it isn't exposed
to every server user. Installing a modpack replaces files, switches the egg and
reinstalls the server, so the page requires **`settings.reinstall`**
(`SubuserPermission::SettingsReinstall`) — the server owner and admins always pass.

- **`canAccess()`** hides the page *and its nav entry* from users without the
  permission (`parent::canAccess() && user()?->can(self::MANAGE_PERMISSION, tenant)`).
- **Server-side**, `startInstall()` and `openModal()` call `authorizeManage()`
  (`abort_unless(..., 403)`) so the action can't be hit directly even if the UI
  is bypassed. `$canManage` is also exposed for the view.
- The required permission is the single constant `MANAGE_PERMISSION` at the top
  of `ModpackBrowserPage` — change that one line to loosen/tighten the gate.

## Modpack browser details and pagination

The modpack browser can load additional result pages instead of stopping after the first
20 cards. The combined view searches CurseForge, Modrinth, FTB and ATLauncher, while
skipping a provider when it cannot reliably support an active filter.

Cards expose the provider project page plus description and gallery dialogs. Full details
are fetched only when a dialog is opened. Browser filters include Minecraft version,
multi-select Forge/NeoForge/Fabric/Quilt loaders, provider-supported categories, and
sorting of the currently loaded results.
