<?php

namespace Cosmii02\ModpackManager\Services;

use App\Enums\EggFormat;
use App\Models\Backup;
use App\Models\Egg;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Backups\DeleteBackupService;
use App\Services\Backups\InitiateBackupService;
use App\Services\Eggs\EggChangerService;
use App\Services\Eggs\Sharing\EggImporterService;
use App\Services\Servers\ReinstallServerService;
use Cosmii02\ModpackManager\Models\ModpackInstall;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Orchestrates modpack installation on a Pelican server via the Wings daemon.
 *
 * Strategy:
 *   - CurseForge: if the selected file is (or links to) an official SERVER PACK,
 *     download & extract it directly. Otherwise BUILD a server pack from the
 *     client pack — read manifest.json, have Wings download every mod, and
 *     merge the overrides/ folder.
 *   - Modrinth: a .mrpack only contains an index + overrides, so we always
 *     parse modrinth.index.json and download the server-side files.
 *
 * Wings does all file work (download via pull(), extract via decompressFile()).
 */
class ModpackInstallService
{
    private const ARCHIVE_NAME = 'modpack-download.zip';
    private const DEFAULT_REMOTE_DOWNLOAD_CONCURRENCY = 3;

    public function __construct(
        private DaemonFileRepository $fileRepo
    ) {}

    public function install(ModpackInstall $record, array $options = []): void
    {
        $server = $record->server;

        if (!$server) {
            throw new RuntimeException('Server not found for modpack install record #' . $record->id);
        }

        $spec = Cache::pull("modpack-manager:install-spec:{$record->id}");

        if (empty($spec) || empty($spec['provider'])) {
            throw new RuntimeException(
                "No install spec found for install #{$record->id} (cache may have expired). Please re-trigger."
            );
        }

        $this->fileRepo->setServer($server);

        try {
            $this->stepDeleteSelectedBackups($record, $options);
            $this->stepSaveConfig($record);
            $this->stepCreateBackup($record, $options);
            $this->stepDeleteWorlds($record, $options);
            $this->stepDeleteFiles($record, $options);

            $plan = $this->resolvePlan($record, $spec);

            if (!empty($plan['archiveUrl'])) {
                $this->stepDownload($record, $plan['archiveUrl']);
                $this->stepExtract($record);
            } else {
                // FTB/ATLauncher ship a file list, not a single archive.
                $this->skipArchiveSteps($record);
            }

            $this->stepRestoreConfig($record);
            $this->stepAssembleAndMerge($record, $plan, $spec);
            $this->stepFinalize($record);
            $this->stepConfigureLoader($record, $plan);

            $record->update(['status' => 'installed', 'progress' => 100]);
            $record->appendLog('Installation completed successfully.');

        } catch (Throwable $e) {
            Log::error('[ModpackManager] Installation failed', [
                'record' => $record->id,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);

            $record->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            $record->appendLog('FATAL: ' . $e->getMessage());

            throw $e;
        }
    }

    // ─── Plan resolution ────────────────────────────────────────────────────

    /**
     * Decide what to download and how to install it.
     *
     * @return array{mode:string, archiveUrl:?string}
     *   mode: 'server_pack' | 'curseforge_build' | 'modrinth' | 'ftb' | 'atlauncher'.
     *   FTB/ATLauncher carry no archive — they ship a file list + loader metadata
     *   that the assemble step downloads directly.
     */
    private function resolvePlan(ModpackInstall $record, array $spec): array
    {
        if ($spec['provider'] === 'modrinth') {
            $url = $spec['mrpack_url'] ?? null;
            if (empty($url)) {
                throw new RuntimeException('No Modrinth download URL in install spec.');
            }
            $record->appendLog('Modrinth pack — will download server-side files from the index.');
            return ['mode' => 'modrinth', 'archiveUrl' => $url];
        }

        if ($spec['provider'] === 'ftb') {
            $detail = app(FtbService::class)->getVersionFiles((int) $spec['pack_id'], (int) $spec['version_id']);
            $record->appendLog('FTB pack — assembling ' . count($detail['files']) . ' server files from the API.');

            return [
                'mode'       => 'ftb',
                'archiveUrl' => null,
                'files'      => $detail['files'],
                'loader'     => $detail['loader'],
                'mc'         => $detail['mc'],
                'version'    => $detail['loaderVersion'],
                'java'       => $detail['java'],
            ];
        }

        if ($spec['provider'] === 'atlauncher') {
            $manifest = app(ATLauncherService::class)->getInstallManifest((string) $spec['safe_name'], (string) $spec['version']);
            $record->appendLog('ATLauncher pack — assembling ' . count($manifest['files']) . ' server mods from the CDN manifest.');

            return [
                'mode'       => 'atlauncher',
                'archiveUrl' => null,
                'files'      => $manifest['files'],
                'configsUrl' => $manifest['configsUrl'],
                'loader'     => $manifest['loader'],
                'mc'         => $manifest['mc'],
                'version'    => $manifest['loaderVersion'],
                'java'       => $manifest['java'],
            ];
        }

        /** @var CurseForgeService $cf */
        $cf     = app(CurseForgeService::class);
        $modId  = (int) $spec['mod_id'];
        $fileId = (int) $spec['file_id'];

        $file = $cf->getFile($modId, $fileId);

        // The loader/MC are listed on the file metadata (e.g. ["1.20.1","NeoForge"]).
        // A downloaded *server* pack often has no manifest.json, so carry this along as
        // the authoritative loader source for the egg switch.
        ['loader' => $cfLoader, 'mc' => $cfMc] = $this->cfLoaderMeta($file['gameVersions'] ?? []);

        if (!empty($file['isServerPack'])) {
            $record->appendLog('Selected file is already a server pack — installing directly.');
            return ['mode' => 'server_pack', 'archiveUrl' => $cf->getDownloadUrl($modId, $fileId), 'loader' => $cfLoader, 'mc' => $cfMc, 'version' => null];
        }

        if (!empty($file['serverPackFileId'])) {
            $serverPackId = (int) $file['serverPackFileId'];
            $record->appendLog("Official server pack found (file #{$serverPackId}) — using it.");
            return ['mode' => 'server_pack', 'archiveUrl' => $cf->getDownloadUrl($modId, $serverPackId), 'loader' => $cfLoader, 'mc' => $cfMc, 'version' => null];
        }

        if ($serverPack = $cf->findServerPackForFile($modId, $fileId, $file)) {
            ['loader' => $serverLoader, 'mc' => $serverMc] = $this->cfLoaderMeta($serverPack['gameVersions'] ?? []);
            $serverPackId = (int) $serverPack['id'];
            $record->appendLog("Official server pack found as an additional file (#{$serverPackId}) — using it.");

            return [
                'mode'       => 'server_pack',
                'archiveUrl' => $cf->getDownloadUrl($modId, $serverPackId),
                'loader'     => $cfLoader ?: $serverLoader,
                'mc'         => $cfMc ?: $serverMc,
                'version'    => null,
            ];
        }

        $record->appendLog('No official server pack available — building one from the client pack.');
        return ['mode' => 'curseforge_build', 'archiveUrl' => $cf->getDownloadUrl($modId, $fileId), 'loader' => $cfLoader, 'mc' => $cfMc, 'version' => null];
    }

    // ─── Steps ──────────────────────────────────────────────────────────────

    private function stepDeleteSelectedBackups(ModpackInstall $record, array $options): void
    {
        if (!($options['delete_backups'] ?? false)) {
            return;
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $options['backup_ids'] ?? []),
            fn (int $id) => $id > 0
        )));

        if (empty($ids)) {
            $record->appendLog('Backup deletion enabled, but no backups were selected.');
            return;
        }

        $backups = Backup::query()
            ->where('server_id', $record->server_id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($backups->count() !== count($ids)) {
            throw new RuntimeException('One or more selected backups do not belong to this server or no longer exist. No backups were deleted.');
        }

        foreach ($ids as $id) {
            $backup = $backups->get($id);

            if ($backup->is_locked) {
                throw new RuntimeException('Backup "' . $backup->name . '" is locked and cannot be deleted. No backups were deleted.');
            }

            if ($backup->completed_at === null) {
                throw new RuntimeException('Backup "' . $backup->name . '" is still running and cannot be deleted. No backups were deleted.');
            }
        }

        $record->appendLog('Deleting selected backups from this server…');
        $service = app(DeleteBackupService::class);

        foreach ($ids as $id) {
            $backup = $backups->get($id);
            $name = $backup->name;
            $service->handle($backup);
            $record->appendLog('  Deleted backup: ' . $name);
        }
    }

    private function stepDeleteWorlds(ModpackInstall $record, array $options): void
    {
        if (!($options['delete_world'] ?? false)) {
            return;
        }

        try {
            $properties = (string) $this->fileRepo->getContent('/server.properties', 1024 * 1024);
        } catch (Throwable $e) {
            throw new RuntimeException('World deletion was requested, but server.properties could not be read. Nothing was deleted.', 0, $e);
        }

        if (!preg_match('/^\s*level-name\s*=\s*(.+?)\s*$/m', $properties, $matches)) {
            throw new RuntimeException('World deletion was requested, but level-name could not be found in server.properties. Nothing was deleted.');
        }

        $levelName = trim((string) $matches[1]);

        if (
            $levelName === ''
            || $levelName === '.'
            || $levelName === '..'
            || str_contains($levelName, '/')
            || str_contains($levelName, '\\')
            || str_contains($levelName, "\0")
        ) {
            throw new RuntimeException('World deletion was requested, but level-name is not a safe root-level world folder. Nothing was deleted.');
        }

        $candidates = [$levelName, $levelName . '_nether', $levelName . '_the_end'];
        $targets = [];

        foreach ($this->fileRepo->getDirectory('/') as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $isDirectory = (bool) ($entry['directory'] ?? false);

            if ($isDirectory && in_array($name, $candidates, true)) {
                $targets[] = $name;
            }
        }

        if (empty($targets)) {
            $record->appendLog('World deletion enabled, but no active world folders were found.');
            return;
        }

        $record->appendLog('Deleting active world folders: ' . implode(', ', $targets));
        $this->fileRepo->deleteFiles('/', $targets);

        foreach ($targets as $target) {
            $record->appendLog('  Deleted world folder: /' . $target . '/');
        }
    }

    private function stepSaveConfig(ModpackInstall $record): void
    {
        $record->markStepRunning('save_config');
        $record->appendLog('Saving configuration files before install…');

        $saved = [];
        foreach (config('modpack-manager.preserved_files', []) as $filename) {
            try {
                $content = $this->fileRepo->getContent('/' . $filename, 5 * 1024 * 1024);
                $saved[$filename] = (string) $content;
                $record->appendLog("  Saved: {$filename}");
            } catch (Throwable) {
                $record->appendLog("  Skip (not found): {$filename}");
            }
        }

        Cache::put("modpack-manager:config:{$record->id}", $saved, now()->addHours(2));

        $record->update(['progress' => 8]);
        $record->markStepDone('save_config');
        $record->appendLog('Configuration saved.');
    }

    private function stepCreateBackup(ModpackInstall $record, array $options = []): void
    {
        $record->markStepRunning('create_backup');

        if (!($options['create_backup'] ?? true)) {
            $record->appendLog('Skipping backup (disabled for this install).');
            $record->update(['progress' => 16]);
            $record->markStepDone('create_backup');
            return;
        }

        $record->appendLog('Creating a panel backup of the current server…');

        // The user explicitly asked for a backup before we wipe the existing
        // installation. If we can't produce a successful one, abort instead of
        // silently destroying their files — installation continues only when a
        // backup is in hand.
        try {
            $name = 'Before modpack install — ' . $record->modpack_name
                  . ' (' . now()->format('Y-m-d H:i') . ')';

            /** @var InitiateBackupService $service */
            $service = app(InitiateBackupService::class);
            $backup  = $service->handle($record->server, $name);

            $record->appendLog('  Backup started on Wings; waiting for it to finish…');

            // Wait for Wings to report completion before we touch any files,
            // otherwise the backup would capture a half-modified server.
            $deadline  = time() + 900; // 15 minutes
            $completed = false;
            while (time() < $deadline) {
                sleep(5);
                $backup->refresh();
                if ($backup->completed_at !== null) {
                    $completed = true;
                    break;
                }
            }

            if (!$completed) {
                throw new RuntimeException(
                    'Backup did not finish within 15 minutes. Aborting so your current installation is left untouched.'
                );
            }

            if (!$backup->is_successful) {
                throw new RuntimeException(
                    'The backup reported a failure. Aborting so your current installation is left untouched.'
                );
            }

            $size = $backup->bytes ? $this->humanBytes((int) $backup->bytes) : 'unknown size';
            $record->appendLog("  Backup completed successfully ({$size}). It's in the Backups tab.");
        } catch (\App\Exceptions\Service\Backup\TooManyBackupsException) {
            $record->markStepFailed('create_backup');
            throw new RuntimeException(
                'Server backup limit reached — the requested backup could not be created. '
                . 'Free a slot or raise the limit, then try again. Your current installation was left untouched.'
            );
        } catch (RuntimeException $e) {
            // Already a clear, user-facing message from the checks above.
            $record->markStepFailed('create_backup');
            throw $e;
        } catch (Throwable $e) {
            $record->markStepFailed('create_backup');
            throw new RuntimeException(
                'The requested backup could not be created (' . $e->getMessage()
                . '). Aborting so your current installation is left untouched.',
                0,
                $e
            );
        }

        $record->update(['progress' => 16]);
        $record->markStepDone('create_backup');
    }

    private function stepDeleteFiles(ModpackInstall $record, array $options): void
    {
        $record->markStepRunning('delete_files');

        // ALWAYS clear launcher scripts AND loader-metadata files a previous install may have
        // left, even when "Delete existing files" is off. A new pack ships its own, so any
        // pre-existing one is stale. Two failure modes this prevents:
        //   • a stale variables.txt makes a NeoForge pack (ATM10) get read as the previous Forge
        //     pack (ATM9) and keep the wrong egg;
        //   • a stale manifest.json / modrinth.index.json makes detectLoader() report the
        //     PREVIOUS pack's loader — e.g. installing Forge 1.20.1 DeceasedCraft over a leftover
        //     NeoForge 1.21.1 manifest was detected as "neoforge 21.1.221" and stayed on the
        //     NeoForge egg. This runs BEFORE download, so the current pack's own files (extracted
        //     afterwards) are untouched. These are tiny metadata files, never user data.
        foreach (['start.sh', 'run.sh', 'variables.txt', 'user_jvm_args.txt', 'manifest.json', 'modrinth.index.json'] as $file) {
            try {
                $this->fileRepo->deleteFiles('/', [$file]);
                $record->appendLog("  Cleared stale launcher/metadata file: /{$file}");
            } catch (Throwable) {
                // Usually absent; ignore.
            }
        }

        // Also clear stale loader installer jars (forge-/neoforge-/fabric-/quilt- *installer.jar)
        // a previous pack left in the root — otherwise an old ATM9 forge installer can be picked
        // up instead of this pack's (e.g. ATM10's neoforge) installer. Runs before download, so
        // the new pack's own installer (extracted afterwards) is untouched.
        try {
            foreach ($this->fileRepo->getDirectory('/') as $e) {
                $name = (string) ($e['name'] ?? '');
                if (preg_match('/^(neoforge|forge|fabric|quilt)-.*installer\.jar$/i', $name)) {
                    try {
                        $this->fileRepo->deleteFiles('/', [$name]);
                        $record->appendLog("  Cleared stale installer jar: /{$name}");
                    } catch (Throwable) {
                        // ignore
                    }
                }
            }
        } catch (Throwable) {
            // Directory unreadable; ignore.
        }

        if (!($options['delete_existing'] ?? false)) {
            $record->appendLog('Skipping directory deletion ("Delete existing files" was not enabled).');
            $record->update(['progress' => 24]);
            $record->markStepDone('delete_files');
            return;
        }

        $record->appendLog('Removing old mod/pack directories…');
        foreach (['mods', 'config', 'scripts', 'kubejs', 'defaultconfigs', 'patchouli_books', 'resourcepacks', 'shaderpacks', 'datapacks'] as $dir) {
            try {
                $this->fileRepo->deleteFiles('/', [$dir]);
                $record->appendLog("  Deleted: /{$dir}/");
            } catch (Throwable) {
                // Most won't exist; ignore.
            }
        }

        $record->update(['progress' => 24]);
        $record->markStepDone('delete_files');
        $record->appendLog('Old files removed.');
    }

    private function stepDownload(ModpackInstall $record, string $url): void
    {
        $record->markStepRunning('download');
        $record->appendLog('Telling Wings to download the modpack archive…');
        $record->appendLog("  Source: {$url}");

        $this->fileRepo->pull($url, '/', [
            'filename'   => self::ARCHIVE_NAME,
            'foreground' => false,
        ]);

        $deadline = time() + 600;
        $lastSize = -1;
        $stable   = 0;

        while (time() < $deadline) {
            sleep(4);
            $size = $this->remoteFileSize('/', self::ARCHIVE_NAME);

            if ($size === null) {
                $record->appendLog('  …waiting for download to start');
                continue;
            }

            if ($size > 0 && $size === $lastSize) {
                if (++$stable >= 2) {
                    break;
                }
            } else {
                $stable = 0;
                $record->appendLog('  Downloaded ' . $this->humanBytes($size));
                $record->update(['progress' => min(45, 26 + (int) ($size / (8 * 1024 * 1024)))]);
            }

            $lastSize = $size;
        }

        if ($lastSize <= 0) {
            throw new RuntimeException('Download did not complete — archive not found on the server.');
        }

        $record->appendLog('  Download complete: ' . $this->humanBytes($lastSize));
        $record->update(['progress' => 48]);
        $record->markStepDone('download');
    }

    private function stepExtract(ModpackInstall $record): void
    {
        $record->markStepRunning('extract');
        $record->appendLog('Extracting archive on the server…');

        $this->fileRepo->decompressFile('/', self::ARCHIVE_NAME);
        $record->appendLog('  Extraction complete.');

        $record->update(['progress' => 58]);
        $record->markStepDone('extract');
    }

    /**
     * FTB/ATLauncher have no single archive — files are pulled individually in
     * the assemble step — so the download/extract steps are marked done up front.
     */
    private function skipArchiveSteps(ModpackInstall $record): void
    {
        $record->markStepRunning('download');
        $record->appendLog('This provider has no single archive — files are fetched individually during assembly.');
        $record->update(['progress' => 48]);
        $record->markStepDone('download');

        $record->markStepRunning('extract');
        $record->update(['progress' => 58]);
        $record->markStepDone('extract');
    }

    private function stepRestoreConfig(ModpackInstall $record): void
    {
        $record->markStepRunning('restore_config');
        $record->appendLog('Restoring preserved configuration files…');

        $saved = Cache::get("modpack-manager:config:{$record->id}", []);

        foreach ($saved as $filename => $content) {
            try {
                $this->fileRepo->putContent('/' . $filename, $content);
                $record->appendLog("  Restored: {$filename}");
            } catch (Throwable $e) {
                $record->appendLog("  WARNING: could not restore {$filename}: " . $e->getMessage());
            }
        }

        $record->update(['progress' => 62]);
        $record->markStepDone('restore_config');
    }

    /**
     * The "merge_configs" step does the real assembly work:
     *   - server_pack: just move overrides/ into root (usually none).
     *   - curseforge_build: download every mod from manifest.json + overrides.
     *   - modrinth: download every server-side file from modrinth.index.json + overrides.
     */
    private function stepAssembleAndMerge(ModpackInstall $record, array $plan, array $spec): void
    {
        $record->markStepRunning('merge_configs');

        if ($plan['mode'] === 'curseforge_build') {
            $this->assembleCurseForgeBuild($record, app(CurseForgeService::class));
        } elseif ($plan['mode'] === 'modrinth') {
            $this->assembleModrinth($record);
        } elseif ($plan['mode'] === 'ftb') {
            $this->assembleFtb($record, $plan);
        } elseif ($plan['mode'] === 'atlauncher') {
            $this->assembleATLauncher($record, $plan);
        } else {
            $record->appendLog('Server pack installed — no assembly required.');
        }

        // Move an extracted overrides/ folder into the server root (all modes).
        $this->mergeOverrides($record);

        $record->update(['progress' => 94]);
        $record->markStepDone('merge_configs');
    }

    private function stepFinalize(ModpackInstall $record): void
    {
        $record->markStepRunning('finalize');
        $record->appendLog('Finalizing…');

        try {
            $this->fileRepo->deleteFiles('/', [self::ARCHIVE_NAME]);
            $record->appendLog('  Removed download archive.');
        } catch (Throwable) {
            // Non-fatal.
        }

        $this->writeInstalledMetadata($record);

        Cache::forget("modpack-manager:config:{$record->id}");

        $record->update(['progress' => 98]);
        $record->markStepDone('finalize');
        $record->appendLog('Done.');
    }

    /**
     * When `store_metadata` is enabled, persist the installed-pack info into the
     * server's own files (a dotfile in the server root) so the Modpacks page can
     * recover the current pack even if the database record is lost. Best-effort:
     * a write failure never fails the install.
     */
    private function writeInstalledMetadata(ModpackInstall $record): void
    {
        if (!config('modpack-manager.store_metadata', false)) {
            return;
        }

        try {
            $this->fileRepo->putContent(
                ModpackInstall::METADATA_FILE,
                (string) json_encode($record->toMetadata(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            $record->appendLog('  Wrote installed-pack metadata to ' . ModpackInstall::METADATA_FILE . '.');
        } catch (Throwable $e) {
            $record->appendLog('  Could not write metadata file (non-fatal): ' . $e->getMessage());
        }
    }

    /**
     * Best-effort: detect the pack's mod loader, switch the server to a matching
     * Forge/NeoForge/Fabric egg, set its MC + loader-version variables, and trigger
     * a reinstall so the egg installs the loader server on top of the files we placed.
     *
     * Never fatal — any problem just logs a warning and leaves the egg unchanged.
     */
    private function stepConfigureLoader(ModpackInstall $record, array $plan): void
    {
        $record->markStepRunning('configure_loader');
        $record->appendLog('Configuring startup / mod loader…');
        $record->appendLog(sprintf(
            '  [diag] mode=%s, CF-tagged loader=%s, mc=%s, version=%s',
            $plan['mode'] ?? '?',
            $plan['loader'] ?? '—',
            $plan['mc'] ?? '—',
            $plan['version'] ?? '—'
        ));

        try {
            // Self-contained server packs (ServerPackCreator's start.sh, or a Forge/NeoForge
            // MDK run.sh) install AND launch their own loader. For those we just point the
            // server's startup command at the bundled launcher — no egg switch, no reinstall.
            //
            // ONLY a CurseForge official server pack can ship its own launcher. Every other
            // mode (modrinth, curseforge_build, ftb, atlauncher) is assembled by us with no
            // launcher, so we must NOT run this detection — otherwise a stale start.sh/run.sh
            // left behind by a *previous* server-pack install gets mistaken for this pack's
            // launcher and the egg is never switched (e.g. a Fabric pack landing on Forge).
            if ($plan['mode'] === 'server_pack') {
                // 1. Genuine bundled launcher (ServerPackCreator start.sh / MDK run.sh) that
                //    installs AND launches the loader itself — use it as-is, no reinstall.
                if ($this->configureSelfContainedPack($record, $plan)) {
                    $record->markStepDone('configure_loader');
                    return;
                }

                // 2. Installer-based server pack (AllTheMods-style: startserver.sh + a
                //    neoforge/forge installer jar, no run-ready launcher). The exact loader
                //    version is in the installer jar's filename — feed it to the egg path so
                //    the matching egg installs precisely that version on top of the mods.
                $installer = $this->detectInstallerJarMeta($record, $plan['loader'] ?? null);
                if ($installer) {
                    $plan['loader']  = $installer['loader'];
                    $plan['version'] = $installer['version'] ?? ($plan['version'] ?? null);
                    $plan['mc']      = $plan['mc'] ?: ($installer['mc'] ?? null);
                    $record->appendLog("  Installer-based server pack — will install {$installer['loader']} {$installer['version']} via the matching egg.");
                }
            }

            // Otherwise we assembled the files ourselves (only mods/ + overrides, no launcher),
            // so switch to a matching loader egg and reinstall to install the loader server.
            $this->configureLoaderEggAndReinstall($record, $plan);
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: startup/loader configuration skipped (' . $e->getMessage() . ').');
        }

        $record->markStepDone('configure_loader');
    }

    /**
     * Detect a self-contained server pack and configure the server to launch its bundled
     * installer/launcher. It switches the server to the matching loader egg (so the panel shows
     * the right egg and the correct Java images) but does NOT reinstall — reinstalling would
     * clobber the loader/files the pack already ships. The egg's startup is then overridden to
     * the bundled launcher.
     *
     *   - ServerPackCreator (start.sh + variables.txt): launch via `bash start.sh`. The
     *     ServerStarterJar installs/runs the loader from variables.txt on first boot.
     *   - Forge/NeoForge MDK server pack (run.sh referencing @libraries/.../unix_args.txt):
     *     launch via `bash run.sh nogui`.
     *
     * Returns true if it handled the pack (caller should stop); false to fall through to the
     * loader-egg + reinstall path.
     */
    private function configureSelfContainedPack(ModpackInstall $record, array $plan = []): bool
    {
        // Index the server root (case-insensitively).
        $root = [];
        try {
            foreach ($this->fileRepo->getDirectory('/') as $e) {
                $root[strtolower((string) ($e['name'] ?? ''))] = true;
            }
        } catch (Throwable) {
            return false;
        }

        $hasSpc = isset($root['start.sh']) && isset($root['variables.txt']);
        $hasRun = isset($root['run.sh']);

        if (!$hasSpc && !$hasRun) {
            return false;
        }

        $server = $record->server->refresh();

        $loader = $mc = $loaderVer = null;
        $java   = null;
        $startup = null;

        if ($hasSpc) {
            // variables.txt is the authoritative config for a ServerPackCreator pack.
            $vars      = $this->parseVarsTxt((string) $this->fileRepo->getContent('/variables.txt', 1024 * 1024));
            $mc        = $vars['MINECRAFT_VERSION'] ?? null;
            $loader    = isset($vars['MODLOADER']) ? strtolower($vars['MODLOADER']) : null;
            $loaderVer = $vars['MODLOADER_VERSION'] ?? null;
            $java      = isset($vars['RECOMMENDED_JAVA_VERSION']) && is_numeric($vars['RECOMMENDED_JAVA_VERSION'])
                ? (int) $vars['RECOMMENDED_JAVA_VERSION']
                : null;
            $startup   = 'bash start.sh';
        } else {
            // Plain Forge/NeoForge MDK server pack with a run.sh launcher.
            ['loader' => $loader, 'version' => $loaderVer, 'mc' => $mc] =
                $this->parseRunSh((string) $this->fileRepo->getContent('/run.sh', 256 * 1024));
            $startup = 'bash run.sh nogui';
        }

        // The on-disk launcher is authoritative: stepDeleteFiles() always clears any
        // start.sh/run.sh/variables.txt a *previous* install left BEFORE this pack is
        // downloaded, so whatever launcher is here now was extracted from THIS pack's archive
        // and describes its own loader. The CurseForge `gameVersions` tag carried on the plan
        // is the weaker signal — for Minecraft 1.20.1 (the one version where Forge and NeoForge
        // coexist) packs are routinely tagged "NeoForge" (or both) even when they're Forge, and
        // vice-versa. So when the two disagree we TRUST THE LAUNCHER rather than bail to the
        // egg-switch path (which, having no manifest for a server pack, would blindly follow the
        // mistagged CF loader and land a Forge pack on the NeoForge egg — the exact bug).
        $planLoader = $this->normalizeLoader($plan['loader'] ?? null);
        $diskLoader = $this->normalizeLoader($loader);
        if ($planLoader && $diskLoader && $planLoader !== $diskLoader) {
            $record->appendLog("  Note: CurseForge tags this pack “{$planLoader}”, but its own launcher is “{$diskLoader}” — trusting the launcher (the CF loader tag is unreliable for 1.20.1 Forge/NeoForge).");
        }

        if ($hasSpc) {
            $record->appendLog('  ServerPackCreator pack detected — launching via start.sh.');
            $this->applyServerPackCreatorTweaks($record, $this->normalizeLoader($loader) ?? '');
        } else {
            $record->appendLog('  Self-contained server pack detected — launching via run.sh' . ($loader ? " ({$loader})" : '') . '.');
            $this->writeUserJvmArgs($record);
        }

        // Fall back to the loader/MC carried on the plan (CurseForge file metadata) if the
        // launcher script didn't reveal them — guarantees the egg can still be switched.
        $loader    = $loader    ?: ($plan['loader']  ?? null);
        $mc        = $mc        ?: ($plan['mc']       ?? null);
        $loaderVer = $loaderVer ?: ($plan['version']  ?? null);

        // Switch the server to the matching loader egg (correct egg + Java image list in the
        // panel) — but do NOT reinstall, so the bundled loader/files survive.
        $loaderKey = $this->normalizeLoader($loader);
        if ($loaderKey) {
            try {
                $this->switchToLoaderEgg($record, $server, $loaderKey, $mc, $loaderVer);
                $server = $server->refresh();
            } catch (Throwable $e) {
                $record->appendLog('  WARNING: could not switch egg (' . $e->getMessage() . '). Leaving the current egg.');
            }
        }

        // Pick the Java image from the (now matching) egg's allowed images, then write the
        // bundled-launcher startup + image directly, overriding any egg defaults. No reinstall.
        $java  = $java ?: $this->javaForMc($mc);
        $image = $this->pickJavaImageForVersion($server, $java);

        $update = ['startup' => $startup];
        if ($image) {
            $update['image'] = $image;
        }
        $server->update($update);

        $detail = trim(($loaderKey ?: ($loader ?: 'unknown loader')) . ' ' . ($loaderVer ?? '') . ($mc ? " / MC {$mc}" : ''));
        $record->appendLog("  Startup set to “{$startup}”" . ($image ? " (image {$image}, Java {$java})" : '') . " — {$detail}.");
        $record->appendLog('  No reinstall — the pack installs/launches its own loader. Stop → Start once so the new Java image is applied.');

        return true;
    }

    /**
     * Switch the server to a Forge/NeoForge/Fabric egg matching the detected loader, set its
     * MC + loader-version variables, and trigger a reinstall. Used only when WE assembled the
     * files (curseforge_build / modrinth) and there is no bundled launcher script.
     */
    private function configureLoaderEggAndReinstall(ModpackInstall $record, array $plan): void
    {
        ['loader' => $loader, 'mc' => $mc, 'version' => $ver] = $this->detectLoader($record, $plan);

        if (!$loader) {
            $record->appendLog('  Could not determine the mod loader (no manifest/index and no launcher). Egg left unchanged.');
            return;
        }

        $record->appendLog("  Detected loader: {$loader}" . ($ver ? " {$ver}" : '') . ($mc ? " (Minecraft {$mc})" : ''));

        $loaderKey = $this->normalizeLoader($loader);
        if (!$loaderKey || $loaderKey === 'quilt') {
            $record->appendLog('  No matching egg for this loader on the panel — set your loader manually.');
            return;
        }

        $server = $record->server->refresh();
        $egg    = $this->switchToLoaderEgg($record, $server, $loaderKey, $mc, $ver);
        if (!$egg) {
            return;
        }

        $server = $server->refresh();
        // FTB/ATLauncher report an exact Java version; otherwise infer from the MC version.
        $java   = $plan['java'] ?? $this->javaForMc($mc);
        $image  = $this->pickJavaImageForVersion($server, $java);
        if ($image) {
            $server->update(['image' => $image]);
            $record->appendLog("  Set Java image: {$image} (Java {$java}).");
        }

        try {
            app(ReinstallServerService::class)->handle($server->refresh());
            $record->appendLog("  Triggered a server reinstall — the egg is installing the {$loaderKey} server now. Watch the console.");
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: could not auto-trigger reinstall (' . $e->getMessage() . '). Reinstall the server manually to install the loader.');
        }
    }

    /**
     * Switch the server to the egg matching $loader and set its MC + loader-version variables.
     * Does NOT reinstall. Returns the matched egg, or null if no matching egg is installed.
     */
    private function switchToLoaderEgg(ModpackInstall $record, Server $server, string $loader, ?string $mc, ?string $ver): ?Egg
    {
        // [diag] show every installed egg and how it scores for the target loader, so the log
        // makes clear why a given egg was (or wasn't) picked.
        try {
            $scores = Egg::with('variables')->get()
                ->map(fn ($e) => "{$e->name}={$this->loaderEggScore($e, $loader)}")
                ->implode(', ');
            $record->appendLog("  [diag] egg scores for “{$loader}”: {$scores}");
        } catch (Throwable) {
            // diagnostics only — never fail the install over this
        }

        $egg = $this->findLoaderEgg($loader);
        if (!$egg) {
            // No matching egg installed — try to import a bundled one (e.g. many panels
            // ship Forge/Fabric but not NeoForge), then retry the match.
            $egg = $this->importBundledLoaderEgg($record, $loader);
        }
        if (!$egg) {
            $record->appendLog("  No {$loader} egg installed on this panel and none bundled — egg left unchanged. Install a {$loader} egg to enable auto-switching.");
            return null;
        }

        $tags = is_array($egg->tags) ? $egg->tags : [];
        $requiredTags = array_values(array_filter(
            array_map(fn ($tag) => trim((string) $tag), config('modpack-manager.required_egg_tags', ['minecraft'])),
            fn ($tag) => $tag !== ''
        ));
        $normalizedTags = array_map(fn ($tag) => strtolower(trim((string) $tag)), $tags);
        $changedTags = false;

        foreach ($requiredTags as $requiredTag) {
            $normalized = strtolower($requiredTag);
            if (!in_array($normalized, $normalizedTags, true)) {
                $tags[] = $requiredTag;
                $normalizedTags[] = $normalized;
                $changedTags = true;
            }
        }

        if ($changedTags) {
            $egg->tags = array_values(array_unique($tags));
            $egg->save();
            $egg->refresh();
        }

        if ((int) $server->egg_id === (int) $egg->id) {
            $record->appendLog("  Server already on the “{$egg->name}” egg.");
            $this->applyLoaderVariables($record, $server, $egg, $loader, $mc, $ver);
            return $egg;
        }

        // Use Pelican's canonical egg changer: it switches egg_id (+ default image/startup)
        // AND deletes the old server variables before recreating the new egg's set, so we
        // never leave stale Forge/Fabric/NeoForge vars piled on the server. (The old
        // StartupModificationService path only upserted variables — it never removed the
        // previous egg's ones, which is what caused the variable accumulation.)
        app(EggChangerService::class)->handle($server, $egg, keepOldVariables: false);
        $server->refresh();

        $this->applyLoaderVariables($record, $server, $egg, $loader, $mc, $ver);

        $record->appendLog("  Switched egg to “{$egg->name}”.");

        return $egg;
    }

    /**
     * Override the server's MC + loader-version variables on the egg it's currently on.
     * Writes straight to ServerVariable (the rows EggChangerService just created), picking
     * whichever variable name the egg actually defines.
     */
    private function applyLoaderVariables(ModpackInstall $record, Server $server, Egg $egg, string $loader, ?string $mc, ?string $ver): void
    {
        $applied = [];

        // Write $val into the FIRST candidate (by the candidates' own priority order) that
        // the egg actually defines. Iterating candidates — NOT the egg's variable list — is
        // essential: the loader version must land in the RIGHT variable when an egg defines
        // several of them. The bundled Fabric egg is the trap — it lists FABRIC_VERSION (the
        // *installer* version) BEFORE LOADER_VERSION (the loader), so matching by egg order
        // wrote the loader version into FABRIC_VERSION and the install script then fetched
        // fabric-installer/<loader>/… which 404s. Candidate priority fixes that.
        $set = function (array $candidates, ?string $val) use ($server, $egg, &$applied) {
            if ($val === null || $val === '') {
                return;
            }
            foreach ($candidates as $candidate) {
                foreach ($egg->variables as $v) {
                    if (strtoupper((string) $v->env_variable) === $candidate) {
                        ServerVariable::query()->updateOrCreate(
                            ['server_id' => $server->id, 'variable_id' => $v->id],
                            ['variable_value' => $val]
                        );
                        $applied[$v->env_variable] = $val;
                        return; // highest-priority candidate the egg defines wins
                    }
                }
            }
        };

        $set(['MC_VERSION', 'MINECRAFT_VERSION'], $mc);

        if ($loader === 'fabric') {
            // Fabric's loader is Minecraft-version-agnostic and backward-compatible, so
            // pinning the pack's exact loader build buys nothing — and pinning is what caused
            // the 404: the loader build (e.g. 0.19.3) was landing in FABRIC_VERSION, which the
            // egg uses as the *installer* version, so it fetched a non-existent
            // fabric-installer-0.19.3.jar. Force BOTH the loader and installer versions to
            // "latest": the downloads are always valid, and writing every matching var (not
            // just the first) also scrubs any stale/poisoned value a previous install left.
            foreach ($egg->variables as $v) {
                if (in_array(strtoupper((string) $v->env_variable), ['LOADER_VERSION', 'FABRIC_VERSION'], true)) {
                    ServerVariable::query()->updateOrCreate(
                        ['server_id' => $server->id, 'variable_id' => $v->id],
                        ['variable_value' => 'latest']
                    );
                    $applied[$v->env_variable] = 'latest';
                }
            }
        } else {
            // The Forge egg wants the FULL Maven version in FORGE_VERSION — it downloads
            // .../forge/${FORGE_VERSION}/forge-${FORGE_VERSION}-installer.jar, and Forge's artifact
            // is named "<mc>-<build>" (e.g. "1.20.1-47.4.0"). We carry the build number (47.4.0) and
            // the MC version separately, so stitch them together. Without this the installer URL 404s
            // and Forge never installs — the server then falls back to "-jar server.jar" (absent) and
            // dies with a Java "unable to access jarfile" error. NeoForge is the opposite: its egg
            // wants just the build number (21.1.221) in NEOFORGE_VERSION, so leave that untouched.
            $loaderVer = $ver;
            if ($loader === 'forge' && $ver !== null && $ver !== '' && $mc && !str_contains($ver, '-')) {
                $loaderVer = "{$mc}-{$ver}";
            }

            $set(match ($loader) {
                'forge'    => ['FORGE_VERSION', 'BUILD_VERSION'],
                'neoforge' => ['NEOFORGE_VERSION', 'FORGE_VERSION', 'BUILD_VERSION'],
                default    => [],
            }, $loaderVer);
        }

        if ($applied) {
            $record->appendLog('  Set variables: ' . json_encode($applied));
        }
    }

    /**
     * Normalise a loader name (from variables.txt MODLOADER or a manifest id) to one of
     * forge / neoforge / fabric / quilt, or null if unrecognised. LegacyFabric maps to fabric.
     */
    private function normalizeLoader(?string $loader): ?string
    {
        return match (strtolower(trim((string) $loader))) {
            'forge'                  => 'forge',
            'neoforge'               => 'neoforge',
            'fabric', 'legacyfabric' => 'fabric',
            'quilt'                  => 'quilt',
            default                  => null,
        };
    }

    /**
     * Pull the loader + Minecraft version out of a CurseForge file's gameVersions list
     * (a flat mix of MC versions and loader names, e.g. ["1.20.1", "NeoForge", "Client"]).
     * NeoForge is checked before Forge so a pack tagged with both resolves to neoforge.
     *
     * @param array<int, string> $gameVersions
     * @return array{loader:?string, mc:?string}
     */
    private function cfLoaderMeta(array $gameVersions): array
    {
        $loader = $mc = null;
        $found  = [];

        foreach ($gameVersions as $gv) {
            $s = strtolower(trim((string) $gv));
            if ($l = $this->normalizeLoader($s)) {
                $found[$l] = true;
            } elseif (!$mc && preg_match('/^\d+\.\d+(?:\.\d+)?$/', $s)) {
                $mc = $s;
            }
        }

        foreach (['neoforge', 'forge', 'fabric', 'quilt'] as $candidate) {
            if (!empty($found[$candidate])) {
                $loader = $candidate;
                break;
            }
        }

        return ['loader' => $loader, 'mc' => $mc];
    }

    /**
     * Parse a ServerPackCreator variables.txt (KEY=value, # comments) into a map.
     *
     * @return array<string, string>
     */
    private function parseVarsTxt(string $txt): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $txt) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim(trim($v), "\"'");
        }
        return $out;
    }

    /**
     * The JVM args we hand modded servers: let the JVM size its heap from the container's
     * cgroup memory limit (the RAM Pelican assigned), instead of a hardcoded -Xmx.
     */
    private const JVM_MEMORY_ARGS = '-Xms128M -XX:MaxRAMPercentage=92.5';

    /**
     * Make a ServerPackCreator pack play nicely with Pelican: non-interactive, let Pelican
     * manage restarts, and let the JVM use the container's allocated memory.
     */
    private function applyServerPackCreatorTweaks(ModpackInstall $record, string $loader): void
    {
        try {
            $txt = (string) $this->fileRepo->getContent('/variables.txt', 1024 * 1024);
            $txt = preg_replace('/^\s*WAIT_FOR_USER_INPUT\s*=.*$/m', 'WAIT_FOR_USER_INPUT=false', $txt);
            $txt = preg_replace('/^\s*RESTART\s*=.*$/m', 'RESTART=false', $txt);
            // Fabric / Quilt / LegacyFabric launch with JAVA_ARGS from variables.txt.
            $txt = preg_replace('/^\s*JAVA_ARGS\s*=.*$/m', 'JAVA_ARGS="' . self::JVM_MEMORY_ARGS . '"', $txt);

            $this->fileRepo->putContent('/variables.txt', $txt);
            $record->appendLog('  Patched variables.txt (non-interactive, Pelican-managed restarts, container-aware memory).');
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: could not patch variables.txt (' . $e->getMessage() . ').');
        }

        // Forge / NeoForge launch via run scripts that read user_jvm_args.txt.
        if (in_array($loader, ['forge', 'neoforge'], true)) {
            $this->writeUserJvmArgs($record);
        }
    }

    private function writeUserJvmArgs(ModpackInstall $record): void
    {
        try {
            $this->fileRepo->putContent(
                '/user_jvm_args.txt',
                "# Managed by Modpack Manager — lets the JVM use the container's allocated memory.\n" . self::JVM_MEMORY_ARGS . "\n"
            );
            $record->appendLog('  Set user_jvm_args.txt to use the container memory (MaxRAMPercentage=92.5).');
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: could not write user_jvm_args.txt (' . $e->getMessage() . ').');
        }
    }

    /**
     * Work out loader/version/MC from a Forge/NeoForge run.sh that references
     * @libraries/net/<vendor>/.../unix_args.txt.
     *
     * @return array{loader:?string, version:?string, mc:?string}
     */
    private function parseRunSh(string $txt): array
    {
        if (preg_match('#libraries/net/neoforged/neoforge/([^/\s]+)/#', $txt, $m)) {
            return ['loader' => 'neoforge', 'version' => $m[1], 'mc' => $this->mcFromNeoforge($m[1])];
        }
        if (preg_match('#libraries/net/minecraftforge/forge/([0-9.]+)-([0-9.]+)#', $txt, $m)) {
            return ['loader' => 'forge', 'version' => $m[2], 'mc' => $m[1]];
        }
        return ['loader' => null, 'version' => null, 'mc' => null];
    }

    /**
     * Detect an installer-based server pack by the loader installer jar it ships in the root,
     * e.g. AllTheMods packs bundle `neoforge-21.1.228-installer.jar` (+ a startserver.sh that
     * runs it) or `forge-1.20.1-47.4.20-installer.jar`. The exact loader version lives in the
     * filename, which is what we want to hand the matching egg.
     *
     * @return array{loader:string, version:?string, mc:?string}|null
     */
    private function detectInstallerJarMeta(ModpackInstall $record, ?string $preferLoader = null): ?array
    {
        try {
            $entries = $this->fileRepo->getDirectory('/');
        } catch (Throwable) {
            return null;
        }

        $found = [];
        foreach ($entries as $e) {
            $name = (string) ($e['name'] ?? '');

            if (preg_match('/^neoforge-([0-9][0-9.]*)-installer\.jar$/i', $name, $m)) {
                $found['neoforge'] = ['loader' => 'neoforge', 'version' => $m[1], 'mc' => $this->mcFromNeoforge($m[1])];
            } elseif (preg_match('/^forge-([0-9]+\.[0-9]+(?:\.[0-9]+)?)-([0-9][0-9.]*)-installer\.jar$/i', $name, $m)) {
                // forge-<mc>-<forgever>-installer.jar (e.g. forge-1.20.1-47.4.20-installer.jar)
                $found['forge'] = ['loader' => 'forge', 'version' => $m[2], 'mc' => $m[1]];
            }
        }

        if (!$found) {
            return null;
        }

        // Prefer the loader the pack metadata says it is (CurseForge gameVersions, carried on
        // the plan) — so a stale installer jar from a previous pack (an old ATM9 forge installer)
        // can never win over this pack's real (neoforge) one.
        $prefer = $this->normalizeLoader($preferLoader);
        if ($prefer && isset($found[$prefer])) {
            return $found[$prefer];
        }

        return $found['neoforge'] ?? $found['forge'] ?? reset($found);
    }

    /**
     * NeoForge versions track the MC version: 21.1.228 → 1.21.1, 20.4.x → 1.20.4.
     */
    private function mcFromNeoforge(string $ver): ?string
    {
        if (preg_match('/^(\d+)\.(\d+)\./', $ver, $m)) {
            return '1.' . $m[1] . ($m[2] !== '0' ? '.' . $m[2] : '');
        }
        return null;
    }

    /**
     * Pick a Java docker image for a specific major version, preferring one the server's
     * current egg already allows, else falling back to the parkervcp yolks image.
     */
    private function pickJavaImageForVersion(Server $server, int $java): ?string
    {
        try {
            $images = $server->egg?->docker_images ?? [];
            foreach ($images as $label => $img) {
                if (stripos((string) $img, "java_{$java}") !== false
                    || stripos((string) $label, "java {$java}") !== false
                    || stripos((string) $label, "java_{$java}") !== false) {
                    return $img;
                }
            }
        } catch (Throwable) {
            // fall through to the default image
        }

        return "ghcr.io/parkervcp/yolks:java_{$java}";
    }

    /**
     * Work out the mod loader + versions from the files already on the server.
     *
     * @return array{loader:?string, mc:?string, version:?string}
     */
    private function detectLoader(ModpackInstall $record, array $plan): array
    {
        $loader = $mc = $version = null;

        // FTB/ATLauncher loader metadata comes from the provider API (carried in the plan),
        // not from a manifest written to disk.
        if (in_array($plan['mode'], ['ftb', 'atlauncher'], true)) {
            return [
                'loader'  => $plan['loader'] ?? null,
                'mc'      => $plan['mc'] ?? null,
                'version' => $plan['version'] ?? null,
            ];
        }

        try {
            if ($plan['mode'] === 'modrinth') {
                $index = json_decode((string) $this->fileRepo->getContent('/modrinth.index.json', 5 * 1024 * 1024), true);
                $deps  = is_array($index) ? ($index['dependencies'] ?? []) : [];
                $mc    = $deps['minecraft'] ?? null;

                foreach (['neoforge' => 'neoforge', 'forge' => 'forge', 'fabric-loader' => 'fabric', 'quilt-loader' => 'quilt'] as $key => $type) {
                    if (!empty($deps[$key])) {
                        $loader  = $type;
                        $version = (string) $deps[$key];
                        break;
                    }
                }
            } else {
                // curseforge_build always has manifest.json; some server packs do too.
                $manifest = json_decode((string) $this->fileRepo->getContent('/manifest.json', 5 * 1024 * 1024), true);
                if (is_array($manifest)) {
                    $mc = $manifest['minecraft']['version'] ?? null;
                    $id = $manifest['minecraft']['modLoaders'][0]['id'] ?? null;
                    if (is_string($id) && str_contains($id, '-')) {
                        [$type, $version] = explode('-', $id, 2);
                        $type   = strtolower($type);
                        $loader = in_array($type, ['forge', 'neoforge', 'fabric', 'quilt'], true) ? $type : null;
                    }
                }
            }
        } catch (Throwable) {
            // No manifest / unreadable — caller treats null loader as "leave egg alone".
        }

        // Fall back to the loader/MC carried on the plan (CurseForge file metadata).
        // A downloaded server pack usually has no manifest.json, so this is what lets
        // the egg switch for official server packs.
        return [
            'loader'  => $loader  ?? ($plan['loader']  ?? null),
            'mc'      => $mc      ?? ($plan['mc']       ?? null),
            'version' => $version ?? ($plan['version']  ?? null),
        ];
    }

    /**
     * Find an installed egg matching the loader, identified by its variable names
     * (robust across differently-named eggs / panels).
     */
    private function findLoaderEgg(string $loader): ?Egg
    {
        $best      = null;
        $bestScore = 0;

        // Pick the *best*-scoring egg rather than the first match, so a generic Forge
        // egg listed before the NeoForge egg can't win a neoforge lookup.
        foreach (Egg::with('variables')->get() as $egg) {
            $score = $this->loaderEggScore($egg, $loader);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $egg;
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    /**
     * Score how well an egg matches a loader. A defining variable is the strongest
     * signal (3); the egg name is a fallback (2) for eggs that name their version
     * variable unconventionally. 0 means "not this loader" — crucially a Forge egg
     * never scores for neoforge (and vice-versa), so a neoforge pack can never fall
     * back onto the Forge egg.
     */
    private function loaderEggScore(Egg $egg, string $loader): int
    {
        $envs = $egg->variables->pluck('env_variable')->map(fn ($e) => strtoupper((string) $e))->all();
        $has  = fn (string $n) => in_array($n, $envs, true);

        $name      = strtolower((string) $egg->name);
        $isNeoName = (bool) preg_match('/neo[\s_-]*forge/', $name);
        $isNeoVar  = $has('NEOFORGE_VERSION');

        // Match a loader keyword only as a WHOLE WORD, so an unrelated egg whose name merely
        // contains the substring can't masquerade as a loader egg. The classic offender is
        // "Arma Reforger" → "re·forge·r" contains "forge" but is not the Forge loader; a bare
        // str_contains() let it score as a Forge fallback whenever no real Forge egg was
        // installed. \bforge\b matches "Forge Minecraft" / "Minecraft Forge" but not "reforger".
        $nameMentions = fn (string $kw) => (bool) preg_match('/\b' . preg_quote($kw, '/') . '\b/', $name);

        return match ($loader) {
            'neoforge' => $isNeoVar ? 3 : ($isNeoName ? 2 : 0),
            'forge'    => (!$isNeoVar && !$isNeoName && !$has('SPONGE_TYPE'))
                ? ($has('FORGE_VERSION') ? 3 : ($nameMentions('forge') ? 2 : 0))
                : 0,
            'fabric'   => ($has('FABRIC_VERSION') || $has('LOADER_VERSION'))
                ? 3
                : ($nameMentions('fabric') ? 2 : 0),
            default    => 0,
        };
    }

    /**
     * Import a loader egg bundled with the plugin (resources/eggs/<loader>.json) when the
     * panel has no matching egg installed. Returns the imported egg, or null if there's no
     * bundled egg for the loader or the import fails. Best-effort — never throws.
     */
    private function importBundledLoaderEgg(ModpackInstall $record, string $loader): ?Egg
    {
        $path = dirname(__DIR__, 2) . "/resources/eggs/{$loader}.json";
        if (!is_file($path)) {
            return null;
        }

        try {
            $record->appendLog("  No {$loader} egg found — importing the bundled “{$loader}” egg…");

            $content = (string) file_get_contents($path);
            $imported = app(EggImporterService::class)->fromContent($content, EggFormat::JSON);

            $record->appendLog("  Imported egg “{$imported->name}” (id {$imported->id}).");

            // Re-run scoring against all eggs (incl. the freshly imported one) so we pick the
            // best match by the same rules as everything else.
            return $this->findLoaderEgg($loader);
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: could not import the bundled egg (' . $e->getMessage() . ').');
            return null;
        }
    }

    private function javaForMc(?string $mc): int
    {
        if (!$mc || !preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $mc, $m)) {
            return 17;
        }

        $major = (int) $m[1];
        $minor = (int) $m[2];
        $patch = (int) ($m[3] ?? 0);

        if ($major >= 26) return 25;
        if ($major !== 1) return 17;
        if ($minor >= 21) return 21;
        if ($minor === 20) return $patch >= 5 ? 21 : 17;
        if ($minor >= 17) return 17;

        return 8;
    }

    // ─── Assembly ───────────────────────────────────────────────────────────

    private function assembleCurseForgeBuild(ModpackInstall $record, CurseForgeService $cf): void
    {
        $record->appendLog('Reading manifest.json…');
        $manifest = json_decode((string) $this->fileRepo->getContent('/manifest.json', 5 * 1024 * 1024), true);

        if (!is_array($manifest) || empty($manifest['files'])) {
            throw new RuntimeException('Could not read manifest.json from the client pack.');
        }

        $mcVer  = $manifest['minecraft']['version'] ?? 'unknown';
        $loader = $manifest['minecraft']['modLoaders'][0]['id'] ?? 'unknown';
        $count  = count($manifest['files']);
        $record->appendLog("  Minecraft {$mcVer} / loader {$loader} — {$count} mods to download.");

        try { $this->fileRepo->createDirectory('mods', '/'); } catch (Throwable) {}

        $infos = $cf->getFilesByIds(array_map(fn ($f) => (int) $f['fileID'], $manifest['files']));

        // Resolve each project's type so resource packs / shaders / data packs go to
        // their own folder instead of all landing in /mods.
        $classes = [];
        try {
            $classes = $cf->getModClasses(array_map(fn ($f) => (int) ($f['projectID'] ?? 0), $manifest['files']));
        } catch (Throwable $e) {
            $record->appendLog('  NOTE: could not resolve project types (' . $e->getMessage() . ') — placing everything in /mods.');
        }

        $downloads   = [];
        $createdDirs  = ['/mods' => true];
        $folderCounts = [];
        foreach ($manifest['files'] as $f) {
            $fid  = (int) ($f['fileID'] ?? 0);
            $pid  = (int) ($f['projectID'] ?? 0);
            $info = $infos[$fid] ?? null;
            $url  = $info['url'] ?? null;
            $name = $info['name'] ?? null;

            if (empty($url)) {
                try {
                    $url  = $cf->getDownloadUrl($pid, $fid);
                    $name = $name ?: ('mod-' . $fid . '.jar');
                } catch (Throwable $e) {
                    $record->appendLog("  WARN: skipping file #{$fid} (" . $e->getMessage() . ')');
                    continue;
                }
            }

            $name      = $name ?: ('mod-' . $fid . '.jar');
            $folder    = $this->folderForCurseClass($classes[$pid] ?? null);
            $remoteDir = '/' . $folder;

            if (!isset($createdDirs[$remoteDir])) {
                try { $this->fileRepo->createDirectory($folder, '/'); } catch (Throwable) {}
                $createdDirs[$remoteDir] = true;
            }

            $downloads[] = ['url' => $url, 'dir' => $remoteDir, 'name' => $name];
            $folderCounts[$folder] = ($folderCounts[$folder] ?? 0) + 1;
        }

        $breakdown = implode(', ', array_map(fn ($d, $n) => "{$n} → {$d}/", array_keys($folderCounts), $folderCounts));
        $record->appendLog('  Prepared ' . count($downloads) . ' downloads for Wings' . ($breakdown ? " ({$breakdown})" : '') . '…');
        $this->pullFilesThrottled($record, $downloads);

        $note = "Assembled from a CurseForge CLIENT pack by Modpack Manager.\n"
              . "Minecraft version: {$mcVer}\nMod loader: {$loader}\n\n"
              . "Modpack Manager will try to switch this server to a matching loader egg\n"
              . "and reinstall so the loader server is installed automatically. If that\n"
              . "step is skipped (no matching egg), set your loader to the version above.\n";
        try { $this->fileRepo->putContent('/modpack-manager-README.txt', $note); } catch (Throwable) {}
    }

    private function assembleModrinth(ModpackInstall $record): void
    {
        $record->appendLog('Reading modrinth.index.json…');
        $index = json_decode((string) $this->fileRepo->getContent('/modrinth.index.json', 5 * 1024 * 1024), true);

        if (!is_array($index) || empty($index['files'])) {
            throw new RuntimeException('Could not read modrinth.index.json from the .mrpack.');
        }

        $deps = $index['dependencies'] ?? [];
        $record->appendLog('  Dependencies: ' . json_encode($deps));

        $downloads  = [];
        $createdDirs = [];
        foreach ($index['files'] as $f) {
            if (($f['env']['server'] ?? 'required') === 'unsupported') {
                continue;
            }

            $path = $f['path'] ?? null;
            $url  = $f['downloads'][0] ?? null;
            if (empty($path) || empty($url)) {
                continue;
            }

            $dir  = trim(str_replace('\\', '/', dirname($path)), '/');
            $name = basename($path);
            $remoteDir = $dir === '' || $dir === '.' ? '/' : '/' . $dir;

            if ($remoteDir !== '/' && !isset($createdDirs[$remoteDir])) {
                try { $this->fileRepo->createDirectory($dir, '/'); } catch (Throwable) {}
                $createdDirs[$remoteDir] = true;
            }

            $downloads[] = ['url' => $url, 'dir' => $remoteDir, 'name' => $name];
        }

        $record->appendLog('  Prepared ' . count($downloads) . ' file downloads for Wings…');
        $this->pullFilesThrottled($record, $downloads);

        $note = "Installed from a Modrinth .mrpack by Modpack Manager.\n"
              . 'Dependencies: ' . json_encode($deps) . "\n\n"
              . "Modpack Manager will try to switch this server to a matching loader egg\n"
              . "and reinstall so the loader server is installed automatically. If that\n"
              . "step is skipped (no matching egg), set your loader per the dependencies above.\n";
        try { $this->fileRepo->putContent('/modpack-manager-README.txt', $note); } catch (Throwable) {}
    }

    /**
     * FTB: download each server-side file from its FTB-hosted URL. Files that only
     * carry a CurseForge {project,file} reference are resolved in one batch via
     * CurseForgeService (needs the CF key); if that's unavailable they're skipped.
     */
    private function assembleFtb(ModpackInstall $record, array $plan): void
    {
        $files = $plan['files'] ?? [];
        if (empty($files)) {
            throw new RuntimeException('FTB returned no installable files for this version.');
        }

        // Batch-resolve the CurseForge-referenced files (no direct URL).
        $cfFileIds = [];
        foreach ($files as $f) {
            if (empty($f['url']) && !empty($f['cfFile'])) {
                $cfFileIds[] = (int) $f['cfFile'];
            }
        }

        $cfInfos = [];
        if (!empty($cfFileIds)) {
            try {
                $cfInfos = app(CurseForgeService::class)->getFilesByIds($cfFileIds);
            } catch (Throwable $e) {
                $record->appendLog('  NOTE: ' . count($cfFileIds) . ' file(s) are CurseForge-hosted and need the CurseForge API key — they will be skipped (' . $e->getMessage() . ').');
            }
        }

        $downloads  = [];
        $createdDirs = [];
        $skipped    = 0;
        foreach ($files as $f) {
            $name = $f['name'];
            $url  = $f['url'] ?? null;

            if (empty($url) && !empty($f['cfFile'])) {
                $url = $cfInfos[(int) $f['cfFile']]['url'] ?? null;
                if (empty($url) && !empty($f['cfProject'])) {
                    try {
                        $url = app(CurseForgeService::class)->getDownloadUrl((int) $f['cfProject'], (int) $f['cfFile']);
                    } catch (Throwable) {
                        $url = null;
                    }
                }
            }

            if (empty($url)) {
                $skipped++;
                continue;
            }

            $dir       = trim((string) ($f['dir'] ?? ''), '/');
            $remoteDir = $dir === '' ? '/' : '/' . $dir;

            if ($remoteDir !== '/' && !isset($createdDirs[$remoteDir])) {
                try { $this->fileRepo->createDirectory($dir, '/'); } catch (Throwable) {}
                $createdDirs[$remoteDir] = true;
            }

            $downloads[] = ['url' => $url, 'dir' => $remoteDir, 'name' => $name];
        }

        $record->appendLog('  Prepared ' . count($downloads) . ' file downloads for Wings…' . ($skipped ? " ({$skipped} skipped — no resolvable URL)" : ''));
        $this->pullFilesThrottled($record, $downloads);

        $note = "Installed from an FTB (modpacks.ch) pack by Modpack Manager.\n"
              . 'Minecraft: ' . ($plan['mc'] ?? 'unknown') . ' / loader: ' . ($plan['loader'] ?? 'unknown') . ' ' . ($plan['version'] ?? '') . "\n\n"
              . "Modpack Manager will try to switch this server to a matching loader egg and reinstall.\n";
        try { $this->fileRepo->putContent('/modpack-manager-README.txt', $note); } catch (Throwable) {}
    }

    /**
     * ATLauncher: every server mod is re-hosted on ATLauncher's CDN, so pull each
     * one directly (no CurseForge key needed), then download + extract the pack's
     * config zip if it has one.
     */
    private function assembleATLauncher(ModpackInstall $record, array $plan): void
    {
        $files = $plan['files'] ?? [];
        if (empty($files)) {
            throw new RuntimeException('ATLauncher returned no installable server mods for this version.');
        }

        try { $this->fileRepo->createDirectory('mods', '/'); } catch (Throwable) {}

        $downloads = [];
        foreach ($files as $f) {
            $remoteDir = '/' . trim((string) ($f['dir'] ?? 'mods'), '/');
            $downloads[] = ['url' => $f['url'], 'dir' => $remoteDir, 'name' => $f['name']];
        }

        $record->appendLog('  Prepared ' . count($downloads) . ' mod downloads for Wings…');
        $this->pullFilesThrottled($record, $downloads);

        if (!empty($plan['configsUrl'])) {
            $record->appendLog('  Downloading the pack config bundle…');
            $this->fileRepo->pull($plan['configsUrl'], '/', ['filename' => self::ARCHIVE_NAME, 'foreground' => false]);

            $deadline = time() + 300;
            $last = -1;
            $stable = 0;
            while (time() < $deadline) {
                sleep(4);
                $size = $this->remoteFileSize('/', self::ARCHIVE_NAME);
                if ($size === null) {
                    continue;
                }
                if ($size > 0 && $size === $last) {
                    if (++$stable >= 2) {
                        break;
                    }
                } else {
                    $stable = 0;
                }
                $last = $size;
            }

            if ($last > 0) {
                try {
                    $this->fileRepo->decompressFile('/', self::ARCHIVE_NAME);
                    $record->appendLog('  Extracted the config bundle.');
                } catch (Throwable $e) {
                    $record->appendLog('  WARNING: could not extract config bundle (' . $e->getMessage() . ').');
                }
            }
        }

        $note = "Installed from an ATLauncher pack by Modpack Manager.\n"
              . 'Minecraft: ' . ($plan['mc'] ?? 'unknown') . ' / loader: ' . ($plan['loader'] ?? 'unknown') . ' ' . ($plan['version'] ?? '') . "\n\n"
              . "Modpack Manager will try to switch this server to a matching loader egg and reinstall.\n";
        try { $this->fileRepo->putContent('/modpack-manager-README.txt', $note); } catch (Throwable) {}
    }

    /**
     * Map a CurseForge class (project type) ID to the server folder its files belong in.
     * Unknown/mod types fall back to mods/.
     *
     * CurseForge Minecraft class IDs:
     *   6 = Mc Mods · 12 = Resource Packs · 6552 = Shaders · 6945 = Data Packs · 6541 = Customization
     */
    private function folderForCurseClass(?int $classId): string
    {
        return match ($classId) {
            12   => 'resourcepacks',
            6552 => 'shaderpacks',
            6945 => 'datapacks',
            default => 'mods',
        };
    }

    /**
     * Move the contents of an extracted overrides/ folder into the server root.
     */
    private function mergeOverrides(ModpackInstall $record): void
    {
        try {
            $moved = $this->mergeRemoteDirectory('/overrides', '/');
            $this->fileRepo->deleteFiles('/', ['overrides']);

            if ($moved > 0) {
                $record->appendLog('  Merged ' . $moved . ' file(s) from overrides/ into the server root.');
            }
        } catch (Throwable $e) {
            $record->appendLog('  Overrides merge skipped (' . $e->getMessage() . ')');
        }
    }

    private function mergeRemoteDirectory(string $sourceDir, string $targetDir): int
    {
        $entries = $this->fileRepo->getDirectory($sourceDir);
        $moved = 0;

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }

            if ($targetDir === '/' && in_array($name, config('modpack-manager.preserved_files', []), true)) {
                continue;
            }

            $sourcePath = rtrim($sourceDir, '/') . '/' . $name;
            $targetPath = rtrim($targetDir, '/') . '/' . $name;

            if ((bool) ($entry['directory'] ?? false)) {
                $this->ensureRemoteDirectory($targetDir, $name);
                $moved += $this->mergeRemoteDirectory($sourcePath, $targetPath);
                continue;
            }

            $this->replaceRemoteEntry($sourcePath, $targetPath);
            $moved++;
        }

        return $moved;
    }

    private function ensureRemoteDirectory(string $parentDir, string $name): void
    {
        foreach ($this->fileRepo->getDirectory($parentDir) as $entry) {
            if (($entry['name'] ?? null) !== $name) {
                continue;
            }

            if ((bool) ($entry['directory'] ?? false)) {
                return;
            }

            $this->fileRepo->deleteFiles($parentDir, [$name]);
            break;
        }

        $this->fileRepo->createDirectory($name, $parentDir);
    }

    private function replaceRemoteEntry(string $sourcePath, string $targetPath): void
    {
        $targetParent = dirname($targetPath);
        $targetName = basename($targetPath);

        foreach ($this->fileRepo->getDirectory($targetParent) as $entry) {
            if (($entry['name'] ?? null) === $targetName) {
                $this->fileRepo->deleteFiles($targetParent, [$targetName]);
                break;
            }
        }

        $this->fileRepo->renameFiles('/', [[
            'from' => ltrim($sourcePath, '/'),
            'to'   => ltrim($targetPath, '/'),
        ]]);
    }

    // ─── Wings helpers ──────────────────────────────────────────────────────

    /**
     * Wings rejects the fourth simultaneous remote download for a server by
     * default. Submit downloads through a small moving window so large packs do
     * not trip that guard.
     *
     * @param array<int, array{url:string, dir:string, name:string}> $downloads
     */
    private function pullFilesThrottled(ModpackInstall $record, array $downloads): void
    {
        if (empty($downloads)) {
            return;
        }

        $limit = $this->remoteDownloadConcurrency();
        $total = count($downloads);

        $record->appendLog("  Downloading {$total} file(s) via Wings, up to {$limit} at a time…");

        $pending = array_values($downloads);
        $active = [];
        $done = 0;
        $lastLoggedDone = -1;
        $lastProgressAt = time();
        $deadline = time() + max(540, $total * 90);
        $busyLoggedAt = 0;

        while (!empty($pending) || !empty($active)) {
            while (count($active) < $limit && !empty($pending)) {
                $next = $pending[0];

                try {
                    $this->fileRepo->pull($next['url'], $next['dir'], [
                        'filename'   => $next['name'],
                        'foreground' => false,
                    ]);
                    array_shift($pending);
                    $active[] = $next + ['lastSize' => null, 'stable' => 0];
                    $lastProgressAt = time();
                } catch (Throwable $e) {
                    if (!$this->isRemoteDownloadLimitError($e)) {
                        throw $e;
                    }

                    if (time() - $busyLoggedAt >= 30) {
                        $record->appendLog('  Wings remote-download slots are full — waiting before queueing more files.');
                        $busyLoggedAt = time();
                    }

                    break;
                }
            }

            if (empty($active)) {
                if (time() >= $deadline) {
                    throw new RuntimeException("Timed out waiting for Wings download slots ({$done}/{$total} downloaded).");
                }

                sleep(5);
                continue;
            }

            sleep(5);

            $remaining = [];
            foreach ($active as $file) {
                $size = $this->remoteFileSize($file['dir'], $file['name']);

                if ($size !== null && $size > 0) {
                    if ($file['lastSize'] !== null && $size === $file['lastSize']) {
                        $file['stable']++;
                    } else {
                        $file['stable'] = 0;
                        $file['lastSize'] = $size;
                        $lastProgressAt = time();
                    }

                    if ($file['stable'] >= 1) {
                        $done++;
                        $lastProgressAt = time();
                        continue;
                    }
                }

                $remaining[] = $file;
            }
            $active = $remaining;

            if ($done !== $lastLoggedDone) {
                $record->appendLog("  Downloaded {$done}/{$total} files…");
                $record->update(['progress' => min(92, 64 + (int) (28 * $done / max(1, $total)))]);
                $lastLoggedDone = $done;
            }

            if (time() >= $deadline || time() - $lastProgressAt > 300) {
                throw new RuntimeException("Timed out waiting for Wings downloads ({$done}/{$total} downloaded).");
            }
        }
    }

    private function remoteDownloadConcurrency(): int
    {
        return max(
            1,
            (int) config('modpack-manager.remote_download_concurrency', self::DEFAULT_REMOTE_DOWNLOAD_CONCURRENCY)
        );
    }

    private function isRemoteDownloadLimitError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'simultaneous remote file downloads')
            || str_contains($message, 'reached its limit');
    }

    private function remoteFileSize(string $dir, string $name): ?int
    {
        try {
            foreach ($this->fileRepo->getDirectory($dir) as $entry) {
                if (($entry['name'] ?? null) === $name) {
                    return (int) ($entry['size'] ?? 0);
                }
            }
        } catch (Throwable) {
            // ignore
        }

        return null;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
