# Pelican Modpack Manager — Developer Notes

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
timeout (1800s), since building a pack downloads many mods:
```bash
php artisan queue:work --tries=1 --timeout=1860
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
without it those files are skipped). Loader / Minecraft / **Java** version come
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
  `(neoforge|forge|fabric|quilt)-*installer.jar` files on every install for the same reason. (The bundled NeoForge egg's install script only `rm -rf`s the old
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
2. **Find a matching egg** via `findLoaderEgg()`, which *scores* every installed egg
   and picks the best (not the first match — so a generic Forge egg listed before the
   NeoForge egg can't win a neoforge lookup). A defining variable scores highest:
   `NEOFORGE_VERSION` → NeoForge; `FORGE_VERSION` (and **not** NeoForge by var/name,
   and not `SPONGE_TYPE`) → Forge; `FABRIC_VERSION`/`LOADER_VERSION` → Fabric. The egg
   **name** is a fallback signal (name matching `neo[\s_-]*forge` → NeoForge, etc.) for
   eggs that name their version variable unconventionally. A Forge egg never scores for
   neoforge and vice-versa, so a neoforge pack can never fall back onto the Forge egg.
   The loader-version variable is then written to whichever of
   `NEOFORGE_VERSION`/`FORGE_VERSION`/`BUILD_VERSION` (etc.) the egg actually defines.
   If no matching egg is installed, `importBundledLoaderEgg()` imports a bundled egg
   from `resources/eggs/<loader>.json` (currently `neoforge.json`, `forge.json` and
   `fabric.json`, the official pelican-eggs eggs in PTDL_v2 form) via
   `EggImporterService::fromContent()`, then re-scores. This is why a panel that ships
   none of these eggs still gets the right one automatically on the first install — e.g.
   a Forge pack landing on a panel whose only modded egg is the NeoForge one auto-imported
   by a previous neoforge install would otherwise be stuck on neoforge. Best-effort — a
   failed import just logs and leaves the egg unchanged. For **fabric**,
   `applyLoaderVariables()` sets `MC_VERSION` to the pack's MC and forces BOTH the loader
   (`LOADER_VERSION`) and installer (`FABRIC_VERSION`) versions to `latest`. Fabric's loader
   is MC-agnostic/backward-compatible, so `latest` is always valid — and it avoids the trap
   where the bundled egg lists `FABRIC_VERSION` (the *installer* version) before
   `LOADER_VERSION`: pinning the pack's loader build there made the egg fetch a non-existent
   `fabric-installer-<loaderbuild>.jar` (404). Writing `latest` to every matching var also
   scrubs any stale/poisoned value a previous install left. (Quilt still has no bundled egg,
   so a Quilt pack on a panel with no Quilt egg can't auto-switch.)
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

Both paths are **best-effort**: anything unresolved (no launcher and no manifest, no
matching egg, Quilt) just logs a warning and leaves the server unchanged. The whole
step is wrapped so it can never fail the install.

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

