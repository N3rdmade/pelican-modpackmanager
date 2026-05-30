# Pelican Modpack Manager — Developer Notes

## Installation

1. Copy this folder to `/var/www/pelican/plugins/modpack-manager/`
2. Run: `php artisan p:plugin:install modpack-manager`
3. Run migrations: `php artisan migrate`
4. Configure API keys: Admin Panel → Plugins → Modpack Manager Settings

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

### Startup / loader auto-configuration (`stepConfigureLoader`)

After the files are in place, the installer makes the server actually launchable.
There are **two paths**, because modpacks ship in two very different shapes.

#### A) Self-contained server packs (`configureSelfContainedPack`)

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
2. **Find a matching egg** by its *variable names*: `NEOFORGE_VERSION` → NeoForge;
   `FORGE_VERSION` (and not NeoForge/`SPONGE_TYPE`) → Forge; `FABRIC_VERSION`/
   `LOADER_VERSION` → Fabric.
3. **Switch egg + set vars** via `StartupModificationService` at
   `User::USER_LEVEL_ADMIN`. Egg variable defaults are seeded first, then
   `MC_VERSION` (or `MINECRAFT_VERSION`) and the loader-version var overridden;
   Java image chosen by `javaForMc()` (1.20.5+/1.21 → 21, 1.17–1.20.4 → 17, ≤1.16 → 8).
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

## Future Work

- [x] Detect file project type so client-pack resourcepacks/shaders go to the right folder (not `mods/`) TO BE TESTED/but even then this will be done later when I do proper non server-pack modpack testing
- [x] Subuser permission gate (currently accessible to all server users) TO BE TESTED
- [x] Scheduled update checks with notifications TO BE TESTED
